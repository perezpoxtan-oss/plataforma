<?php

namespace App\Services\Novedades;

use App\Models\LostFoundArticulo;
use App\Models\User;
use App\Support\HoraLocal;
use Carbon\Carbon;
use Illuminate\Support\Collection;

/**
 * Buscar Coincidencias (SEGCAT: lf_buscar_coincidencias.php): entre los
 * artículos encontrados que siguen en resguardo, los que podrían ser lo que
 * un huésped dice haber perdido (o lo que se reportó como robado): mismo
 * tipo de valor, objeto/marca/color parecidos y encontrados entre 7 días
 * antes y 30 días después de la fecha aproximada. Ayuda a acotar, no confirma.
 */
class CoincidenciasLostFound
{
    public const LIMITE = 15;

    public function __construct(
        private readonly AdministradorNovedades $novedades,
        private readonly HoraLocal $hora,
    ) {}

    /**
     * @param  array{tipo_valor?: ?string, objeto?: ?string, marca?: ?string, color?: ?string, fecha?: ?string}  $filtros
     * @return Collection<int, array<string, mixed>>
     */
    public function buscar(User $actor, array $filtros): Collection
    {
        // Se guardan en mayúsculas: se compara en mayúsculas (SQLite no pasa a minúsculas las letras acentuadas)
        $como = fn (string $t) => '%'.addcslashes(mb_strtoupper(trim($t)), '%_\\').'%';
        $fecha = isset($filtros['fecha']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filtros['fecha']) ? Carbon::createFromFormat('Y-m-d', $filtros['fecha'], $this->hora->zona()) : null;

        return LostFoundArticulo::query()
            ->where('lost_found_articulos.estatus', LostFoundArticulo::EN_RESGUARDO)
            ->whereHas('novedad', fn ($q) => $this->novedades->limitar($q, $actor, 'ver'))
            ->when(isset(LostFoundArticulo::TIPOS_VALOR[$filtros['tipo_valor'] ?? '']), fn ($q) => $q->where('tipo_valor', $filtros['tipo_valor']))
            ->when(trim((string) ($filtros['objeto'] ?? '')) !== '', fn ($q) => $q->where('objeto', 'like', $como($filtros['objeto'])))
            ->when(trim((string) ($filtros['marca'] ?? '')) !== '', fn ($q) => $q->where('marca', 'like', $como($filtros['marca'])))
            ->when(trim((string) ($filtros['color'] ?? '')) !== '', fn ($q) => $q->where('color', 'like', $como($filtros['color'])))
            ->when($fecha !== null, fn ($q) => $q->whereBetween('created_at', [
                $fecha->copy()->subDays(7)->startOfDay()->utc(), $fecha->copy()->addDays(30)->endOfDay()->utc(),
            ]))
            ->latest()->limit(self::LIMITE)->get()
            ->map(fn (LostFoundArticulo $a) => [
                'id' => $a->id, 'folio' => $a->folio, 'objeto' => $a->objeto, 'marca' => $a->marca, 'color' => $a->color,
                'fecha' => $this->hora->formatear($a->created_at, 'd/m/Y'),
                'url' => route('novedades.index', ['abrir' => $a->novedad_id]),
            ])->values();
    }
}
