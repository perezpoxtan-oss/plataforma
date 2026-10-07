<?php

namespace App\Services\Lector;

use App\Models\EtiquetaPlantilla;
use App\Models\ImpresionEtiquetas;
use App\Models\ImpresionEtiquetasItem;
use App\Models\User;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Autorizador;
use App\Support\HoraLocal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Ronda 7 (parte B): historial del gestor de impresión QR. Cada impresión
 * queda registrada (quién, cuándo, plantilla, cuántas y cuáles) y se puede
 * «Reimprimir» completa o solo algunas etiquetas (otra impresión que apunta
 * a la original). Auditoría: etiquetas_qr.impreso / etiquetas_qr.reimpreso.
 *
 * Quién ve cada impresión (según «etiquetas_qr.ver»): con alcance de empresa,
 * todas; con alcance de sede, las de sus sedes y las suyas; con «solo los
 * propios», las suyas. En todos los casos, solo si trae etiquetas de algún
 * tipo que el usuario puede imprimir (y de esas solo ve los títulos). Al ver o reimprimir, cada etiqueta se vuelve a revisar
 * con los permisos de su tipo (EtiquetasMasivas::paraImprimir).
 *
 * Debe correr con la empresa de trabajo ya fijada en el Tenant.
 */
class ImpresionesEtiquetas
{
    public function __construct(
        private readonly Autorizador $autorizador,
        private readonly AdministradorRoles $auditoria,
    ) {}

    /**
     * Registra la impresión de lo que ya pasó por paraImprimir().
     *
     * @param  Collection<int, array<string, mixed>>  $etiquetas
     */
    public function registrar(User $actor, EtiquetaPlantilla $plantilla, Collection $etiquetas, ?ImpresionEtiquetas $original = null): ImpresionEtiquetas
    {
        $sedes = $etiquetas->pluck('sede_id')->unique()->values();
        $impresion = DB::transaction(function () use ($plantilla, $etiquetas, $original, $sedes) {
            $impresion = ImpresionEtiquetas::create([
                'sede_id' => $sedes->count() === 1 ? $sedes->first() : null,
                'plantilla_id' => $plantilla->id,
                'plantilla_nombre' => $plantilla->nombre,
                'cantidad' => $etiquetas->count(),
                'reimpresion_de_id' => $original?->id,
            ]);
            $ahora = now();
            ImpresionEtiquetasItem::insert($etiquetas->values()->map(fn (array $e, int $i) => [
                'empresa_id' => $impresion->empresa_id,
                'impresion_id' => $impresion->id,
                'tipo' => $e['tipo'],
                'registro_id' => (int) explode('-', $e['clave'])[1],
                'titulo' => mb_substr((string) $e['titulo'], 0, 150),
                'sede_id' => $e['sede_id'],
                'orden' => $i,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ])->all());

            return $impresion;
        });

        $this->auditoria->auditar($actor, $original ? 'etiquetas_qr.reimpreso' : 'etiquetas_qr.impreso', $impresion, null, [
            'plantilla' => $plantilla->nombre,
            'cantidad' => $impresion->cantidad,
            'etiquetas' => $etiquetas->pluck('clave')->all(),
        ] + ($original ? ['reimpresion_de' => $original->id] : []));

        return $impresion;
    }

    /**
     * Impresiones que el usuario puede ver.
     *
     * @return Builder<ImpresionEtiquetas>
     */
    public function visibles(User $actor): Builder
    {
        // Solo las que traen etiquetas de algún tipo que el usuario puede imprimir
        $tipos = $this->tiposImprimibles($actor);
        $consulta = ImpresionEtiquetas::query()->whereHas('items', fn ($q) => $q->whereIn('tipo', $tipos));
        if ($actor->es_superadmin || $this->autorizador->alcanceDeEmpresa($actor, 'etiquetas_qr.ver')) {
            return $consulta;
        }
        if ($this->autorizador->soloPropios($actor, 'etiquetas_qr.ver')) {
            return $consulta->where('impresiones_etiquetas.creado_por', $actor->id);
        }
        $sedes = $this->autorizador->sedesPermitidas($actor, 'etiquetas_qr.ver') ?? [];

        return $consulta->where(fn ($q) => $q->where('impresiones_etiquetas.creado_por', $actor->id)->orWhereIn('impresiones_etiquetas.sede_id', $sedes));
    }

    /** La impresión a su alcance (de otra empresa, otra sede o inexistente: 404). */
    public function buscar(User $actor, int $id): ImpresionEtiquetas
    {
        $impresion = $this->visibles($actor)->whereKey($id)->first();
        abort_if($impresion === null, 404);

        return $impresion;
    }

    /**
     * Historial con filtros: desde / hasta (día de impresión, hora local),
     * quién imprimió, plantilla y búsqueda en los títulos de sus etiquetas.
     *
     * @param  array{desde: ?string, hasta: ?string, usuario: ?int, plantilla: ?int, q: string}  $filtros
     * @return Collection<int, ImpresionEtiquetas>
     */
    public function historial(User $actor, array $filtros, int $limite = 100): Collection
    {
        [$desde, $hasta] = app(EtiquetasMasivas::class)->rangoAlta($filtros['desde'], $filtros['hasta']);
        $texto = mb_strtolower($filtros['q']);

        return $this->visibles($actor)
            ->with(['autor:id,name', 'original:id,created_at',
                // Solo los títulos de los tipos que puede imprimir
                'items' => fn ($q) => $q->whereIn('tipo', $this->tiposImprimibles($actor))->select('id', 'impresion_id', 'tipo', 'registro_id', 'titulo', 'orden')])
            ->when($desde !== null, fn ($q) => $q->where('impresiones_etiquetas.created_at', '>=', $desde))
            ->when($hasta !== null, fn ($q) => $q->where('impresiones_etiquetas.created_at', '<', $hasta))
            ->when($filtros['usuario'] !== null, fn ($q) => $q->where('impresiones_etiquetas.creado_por', $filtros['usuario']))
            ->when($filtros['plantilla'] !== null, fn ($q) => $q->where('impresiones_etiquetas.plantilla_id', $filtros['plantilla']))
            ->when($texto !== '', fn ($q) => $q->whereHas('items', fn ($i) => $i->whereRaw('LOWER(titulo) LIKE ?', ['%'.addcslashes($texto, '%_\\').'%'])))
            ->latest('impresiones_etiquetas.id')
            ->limit($limite)
            ->get();
    }

    /**
     * Quiénes imprimieron (para el filtro), entre lo que el usuario ve.
     *
     * @return Collection<int, string>
     */
    public function autores(User $actor): Collection
    {
        $ids = $this->visibles($actor)->whereNotNull('creado_por')->distinct()->pluck('creado_por');

        return User::whereIn('id', $ids)->orderBy('name')->pluck('name', 'id');
    }

    /** @var array<int, list<string>> */
    private array $imprimibles = [];

    /**
     * Tipos del lector que el usuario puede imprimir (una vez por petición).
     *
     * @return list<string>
     */
    private function tiposImprimibles(User $actor): array
    {
        return $this->imprimibles[$actor->id] ??= array_keys(app(EtiquetasMasivas::class)->tipos($actor));
    }

    /** «3 Llaves · 1 Vehículos» */
    public function resumenTipos(ImpresionEtiquetas $impresion): string
    {
        return $impresion->items->countBy('tipo')
            ->map(fn (int $n, string $tipo) => $n.' '.(EtiquetasMasivas::NOMBRES[$tipo] ?? $tipo))->join(' · ');
    }

    /** Fecha corta de la impresión en la hora local. */
    public function fecha(ImpresionEtiquetas $impresion): string
    {
        return app(HoraLocal::class)->formatear($impresion->created_at);
    }
}
