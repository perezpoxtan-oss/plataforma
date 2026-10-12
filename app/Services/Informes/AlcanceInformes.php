<?php

namespace App\Services\Informes;

use App\Models\Sede;
use App\Models\User;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Alcance de los informes (ADR-0017). Cada cifra respeta DOS permisos: el
 * del informe («tendencias.ver», «dashboard.ver»…) y el «ver» del módulo de
 * donde sale el dato («accesos.ver», «novedades.ver»…). Las sedes que se
 * muestran son la intersección de ambos alcances y de las sedes que la
 * persona eligió en el filtro. Sin el permiso del módulo de origen, la ficha
 * o la sección no se calcula ni se muestra.
 */
class AlcanceInformes
{
    public function __construct(private readonly Autorizador $autorizador) {}

    /**
     * Sedes que valen para un dato: null = todas las de la empresa; [] = ninguna.
     *
     * @param  list<int>|null  $seleccion  sedes elegidas en el filtro (null = sin filtro)
     * @return list<int>|null
     */
    public function sedes(User $actor, string $permisoInforme, string $permisoOrigen, ?array $seleccion = null): ?array
    {
        if (! $actor->can($permisoInforme) || ! $actor->can($permisoOrigen)) {
            return [];
        }

        $resultado = null;
        foreach ([$this->autorizador->sedesPermitidas($actor, $permisoInforme), $this->autorizador->sedesPermitidas($actor, $permisoOrigen), $seleccion] as $lista) {
            if ($lista === null) {
                continue;
            }
            $lista = array_map('intval', $lista);
            $resultado = $resultado === null ? $lista : array_values(array_intersect($resultado, $lista));
        }

        return $resultado === null ? null : array_values(array_unique($resultado));
    }

    /**
     * Limita una consulta del módulo de origen: sedes (intersección) y, si el
     * permiso de origen es «Solo los propios», lo que la persona registró.
     *
     * @template T of \Illuminate\Database\Eloquent\Model
     *
     * @param  Builder<T>  $consulta
     * @param  list<int>|null  $seleccion
     * @param  string|null  $columnaSede  null = el registro no es de una sede (vehículos, proveedores)
     * @return Builder<T>
     */
    public function limitar(Builder $consulta, User $actor, string $permisoInforme, string $permisoOrigen, ?array $seleccion, ?string $columnaSede, ?string $columnaAutor = null): Builder
    {
        $sedes = $this->sedes($actor, $permisoInforme, $permisoOrigen, $seleccion);
        if ($sedes === []) {
            return $consulta->whereRaw('1 = 0');
        }
        if ($sedes !== null && $columnaSede !== null) {
            $consulta->whereIn($columnaSede, $sedes);
        }
        if ($columnaAutor !== null && $this->autorizador->soloPropios($actor, $permisoOrigen)) {
            $consulta->where($columnaAutor, $actor->id);
        }

        return $consulta;
    }

    /**
     * Sedes activas que la persona puede elegir en el filtro del informe.
     *
     * @return Collection<int, Sede>
     */
    public function sedesParaElegir(User $actor, string $permisoInforme): Collection
    {
        $permitidas = $actor->can($permisoInforme) ? $this->autorizador->sedesPermitidas($actor, $permisoInforme) : [];

        return Sede::where('activo', true)
            ->when($permitidas !== null, fn ($q) => $q->whereIn('id', $permitidas))
            ->orderBy('nombre')->get(['id', 'nombre', 'zona_horaria']);
    }

    /**
     * Sedes elegidas en la petición, solo las que puede ver (null = todas las suyas).
     *
     * @param  Collection<int, Sede>  $disponibles
     * @return list<int>|null
     */
    public function seleccion(mixed $valor, Collection $disponibles): ?array
    {
        $ids = collect(is_array($valor) ? $valor : (is_numeric($valor) ? [$valor] : []))
            ->filter(fn ($v) => is_numeric($v))->map(fn ($v) => (int) $v)
            ->intersect($disponibles->pluck('id'))->unique()->values()->all();

        return $ids === [] ? null : $ids;
    }

    /**
     * Huella del alcance para la caché corta: cambia si cambian los permisos o las sedes.
     *
     * @param  list<string>  $permisos
     * @param  list<int>|null  $seleccion
     */
    public function huella(User $actor, string $permisoInforme, array $permisos, ?array $seleccion): string
    {
        $partes = [];
        foreach ($permisos as $permiso) {
            $sedes = $this->sedes($actor, $permisoInforme, $permiso, $seleccion);
            $partes[] = $permiso.'='.($sedes === null ? '*' : implode(',', $sedes))
                .($this->autorizador->soloPropios($actor, $permiso) ? '@'.$actor->id : '');
        }

        return md5(implode('|', $partes));
    }

    /** ¿Alcance de toda la empresa para ese permiso? (texto del encabezado) */
    public function deEmpresa(User $actor, string $permiso): bool
    {
        return $this->autorizador->alcanceDeEmpresa($actor, $permiso)
            && ($this->autorizador->permisosEfectivos($actor)[$permiso] ?? null)?->alcance !== Alcance::Propios;
    }
}
