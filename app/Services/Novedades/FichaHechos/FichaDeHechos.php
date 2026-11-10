<?php

namespace App\Services\Novedades\FichaHechos;

use App\Models\Espacio;
use App\Models\LostFoundArticulo;
use App\Models\LostFoundReportePerdida;
use App\Models\Novedad;
use App\Models\User;
use App\Models\ValoresVistaDetalle;
use App\Services\Novedades\AdministradorNovedades;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

/**
 * Ficha de Hechos (SEGCAT: ficha_hechos.php): cruza todo lo que pasó en la
 * misma habitación y en su misma zona, de 7 días antes a 30 días después de
 * una fecha, para apoyar una investigación (pérdida, robo). Es apoyo, no la
 * investigación en sí: el agente decide qué es relevante.
 *
 * Fuentes propias: tickets de la bitácora, Valores a la Vista, artículos y
 * reportes de pérdida de Lost & Found. Las de otros módulos (Préstamo de
 * llaves, Bitácora de accesos…) se enchufan con FuenteFichaHechos.
 */
class FichaDeHechos
{
    public const ETIQUETA = 'novedades.ficha_hechos';

    public const DIAS_ANTES = 7;

    public const DIAS_DESPUES = 30;

    /** Máximo de hechos de la zona (contexto más amplio). */
    public const MAX_ZONA = 30;

    public function __construct(private readonly AdministradorNovedades $novedades) {}

    /**
     * @return array{desde: CarbonInterface, hasta: CarbonInterface, zona: ?Espacio, habitacion: list<array<string, mixed>>, cercanos: list<array<string, mixed>>}
     */
    public function armar(User $actor, Espacio $habitacion, string $fecha, ?Novedad $origen = null): array
    {
        $tz = $habitacion->sede?->zonaHoraria() ?? config('app.timezone');
        $centro = Carbon::createFromFormat('Y-m-d', $fecha, $tz);
        $desde = $centro->copy()->subDays(self::DIAS_ANTES)->startOfDay()->utc();
        $hasta = $centro->copy()->addDays(self::DIAS_DESPUES)->endOfDay()->utc();
        $zona = $habitacion->padre;
        $visibles = fn (Builder $q) => $this->novedades->limitar($q, $actor, 'ver');

        $enHabitacion = [];
        $tickets = $visibles(Novedad::query())->where('novedades.area_especifica_id', $habitacion->id)
            ->whereBetween('novedades.created_at', [$desde, $hasta])
            ->when($origen !== null, fn ($q) => $q->whereKeyNot($origen->id))
            ->latest('novedades.created_at')->get();
        foreach ($tickets as $t) {
            $enHabitacion[] = $this->ticket($t, '#0f172a');
        }

        $valores = ValoresVistaDetalle::with('novedad:id,numero,created_at')->where('area_especifica_id', $habitacion->id)
            ->whereHas('novedad', fn ($q) => $visibles($q)->whereBetween('novedades.created_at', [$desde, $hasta]))->get();
        foreach ($valores as $v) {
            $enHabitacion[] = [
                'tipo' => 'Valores a la Vista', 'icono' => 'bi-door-open-fill', 'color' => '#d97706',
                'texto' => 'Caja fuerte: '.(ValoresVistaDetalle::CAJA_FUERTE[$v->caja_fuerte] ?? $v->caja_fuerte).($v->valores_dentro ? ' — '.$v->valores_dentro : ''),
                'fecha' => $v->novedad->created_at, 'enlace' => route('novedades.index', ['abrir' => $v->novedad_id]), 'estatus' => null,
            ];
        }

        $articulos = LostFoundArticulo::where('area_especifica_id', $habitacion->id)->whereBetween('created_at', [$desde, $hasta])
            ->whereHas('novedad', $visibles)->get();
        foreach ($articulos as $a) {
            $enHabitacion[] = [
                'tipo' => 'Lost & Found — Encontrado', 'icono' => 'bi-bag-fill', 'color' => '#16a34a',
                'texto' => $a->folio.' — '.$a->objeto.' ('.$a->etiquetaEstatus().')', 'fecha' => $a->created_at,
                'enlace' => route('novedades.index', ['abrir' => $a->novedad_id]), 'estatus' => null,
                'id_articulo' => $a->id, 'vinculable' => $a->enResguardo(),
            ];
        }

        $reportes = LostFoundReportePerdida::where('area_especifica_id', $habitacion->id)->whereBetween('created_at', [$desde, $hasta])
            ->whereHas('novedad', $visibles)->get();
        foreach ($reportes as $r) {
            $enHabitacion[] = [
                'tipo' => 'Lost & Found — Reporte de Pérdida', 'icono' => 'bi-search', 'color' => '#dc2626',
                'texto' => $r->folio.' — '.$r->objeto.' ('.$r->etiquetaEstatus().')', 'fecha' => $r->created_at,
                'enlace' => route('novedades.index', ['abrir' => $r->novedad_id]), 'estatus' => null,
            ];
        }

        $enZona = [];
        if ($zona !== null) {
            $cercanos = $visibles(Novedad::query())
                ->where(fn ($q) => $q->where('novedades.area_id', $zona->id)
                    ->orWhereIn('novedades.area_especifica_id', Espacio::where('ruta', 'like', $zona->ruta.'%')->select('id')))
                ->where(fn ($q) => $q->whereNull('novedades.area_especifica_id')->orWhere('novedades.area_especifica_id', '!=', $habitacion->id))
                ->whereBetween('novedades.created_at', [$desde, $hasta])
                ->when($origen !== null, fn ($q) => $q->whereKeyNot($origen->id))
                ->latest('novedades.created_at')->limit(self::MAX_ZONA)->get();
            foreach ($cercanos as $t) {
                $enZona[] = $this->ticket($t, '#475569');
            }
        }

        // Fuentes de otros módulos (Préstamo de llaves, Accesos…), si ya existen
        foreach (app()->tagged(self::ETIQUETA) as $fuente) {
            if ($fuente instanceof FuenteFichaHechos && $fuente->disponible($actor)) {
                $extra = $fuente->hechos($actor, $habitacion, $zona, $desde, $hasta);
                array_push($enHabitacion, ...($extra['habitacion'] ?? []));
                array_push($enZona, ...($extra['zona'] ?? []));
            }
        }

        $porFecha = fn (array $a, array $b) => $b['fecha'] <=> $a['fecha'];
        usort($enHabitacion, $porFecha);
        usort($enZona, $porFecha);

        return ['desde' => $desde, 'hasta' => $hasta, 'zona' => $zona, 'habitacion' => $enHabitacion, 'cercanos' => $enZona];
    }

    /** @return array<string, mixed> */
    private function ticket(Novedad $t, string $color): array
    {
        return [
            'tipo' => 'Ticket '.$t->folio().' · '.$t->etiquetaCategoria(), 'icono' => 'bi-file-earmark-text-fill', 'color' => $color,
            'texto' => $t->descripcion, 'fecha' => $t->created_at, 'enlace' => route('novedades.index', ['abrir' => $t->id]),
            'estatus' => $t->etiquetaEstatus(),
        ];
    }
}
