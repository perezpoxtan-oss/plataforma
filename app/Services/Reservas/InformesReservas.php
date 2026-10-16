<?php

namespace App\Services\Reservas;

use App\Models\Reserva;
use App\Models\ReservaArea;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * Informes básicos de Reservas de áreas en un periodo (fechas locales de la
 * empresa): ocupación por área (horas confirmadas sobre horas que el área
 * se puede reservar), reservas por tipo de uso e ingresos de eventos para
 * clientes. Solo reservas confirmadas (las canceladas y rechazadas no ocupan).
 */
class InformesReservas
{
    public function __construct(
        private readonly AdministradorReservas $reservas,
        private readonly Disponibilidad $disponibilidad,
    ) {}

    /**
     * @param  list<int>|null  $sedes  null = todas las de su alcance
     * @return array{ocupacion: list<array<string, mixed>>, tipos: list<array<string, mixed>>, ingresos: list<array<string, mixed>>, totales: array<string, mixed>}
     */
    public function calcular(User $actor, CarbonImmutable $desde, CarbonImmutable $hasta, ?int $sede = null): array
    {
        $reservas = $this->reservas->limitar(Reserva::query(), $actor)
            ->with(['area.espacio', 'tipoUso:id,nombre'])
            ->where('estado', Reserva::CONFIRMADA)
            ->where('inicio', '<', $hasta->utc())->where('fin', '>', $desde->utc())
            ->when($sede !== null, fn ($q) => $q->where('sede_id', $sede))
            ->get();

        $areas = ReservaArea::with('espacio:id,nombre')->where('reservable', true)
            ->when($sede !== null, fn ($q) => $q->where('sede_id', $sede))->get();
        $sedesVisibles = $this->reservas->sedes($actor, 'reservas.ver');
        if ($sedesVisibles !== null && ! $actor->es_superadmin) {
            $areas = $areas->filter(fn ($a) => in_array((int) $a->sede_id, $sedesVisibles, true))->values();
        }

        $ocupacion = $areas->map(function (ReservaArea $area) use ($reservas, $desde, $hasta) {
            $suyas = $reservas->where('reserva_area_id', $area->id);
            $horas = round($suyas->sum(fn (Reserva $r) => $r->inicio->diffInMinutes($r->fin, true)) / 60, 1);
            $disponibles = $this->horasDisponibles($area, $desde, $hasta);

            return ['area' => $area->nombre(), 'reservas' => $suyas->count(), 'horas' => $horas, 'disponibles' => $disponibles,
                'porcentaje' => $disponibles > 0 ? min(100, round($horas * 100 / $disponibles, 1)) : null];
        })->sortByDesc('horas')->values()->all();

        $tipos = $reservas->groupBy(fn ($r) => $r->tipoUso?->nombre ?? 'Sin tipo')
            ->map(fn (Collection $g, $tipo) => ['tipo' => $tipo, 'reservas' => $g->count(), 'clientes' => $g->where('para_cliente', true)->count(),
                'internas' => $g->where('para_cliente', false)->count()])
            ->sortByDesc('reservas')->values()->all();

        $clientes = $reservas->where('para_cliente', true);
        $ingresos = $clientes->groupBy(fn ($r) => $r->area->nombre())
            ->map(fn (Collection $g, $area) => ['area' => $area, 'eventos' => $g->count(), 'subtotal' => round($g->sum(fn ($r) => (float) $r->total - (float) $r->iva), 2),
                'iva' => round($g->sum(fn ($r) => (float) $r->iva), 2), 'total' => round($g->sum(fn ($r) => (float) $r->total), 2),
                'cortesias' => $g->where('cortesia', true)->count()])
            ->sortByDesc('total')->values()->all();

        return [
            'ocupacion' => $ocupacion,
            'tipos' => $tipos,
            'ingresos' => $ingresos,
            'totales' => [
                'reservas' => $reservas->count(), 'clientes' => $clientes->count(), 'internas' => $reservas->count() - $clientes->count(),
                'ingresos' => round($clientes->sum(fn ($r) => (float) $r->total), 2),
                'moneda' => (string) ($reservas->first()?->moneda ?? 'MXN'),
            ],
        ];
    }

    /** Horas que el área se puede reservar en el periodo (sin horario: 24 h por día). */
    public function horasDisponibles(ReservaArea $area, CarbonImmutable $desde, CarbonImmutable $hasta): float
    {
        $minutos = 0;
        for ($dia = $desde->startOfDay(); $dia->lessThan($hasta); $dia = $dia->addDay()) {
            if (! is_array($area->horario) || $area->horario === []) {
                $minutos += 1440;

                continue;
            }
            foreach ((array) ($area->horario[(string) $dia->dayOfWeekIso] ?? []) as $v) {
                [$h1, $m1] = array_map('intval', explode(':', $v[0]));
                [$h2, $m2] = array_map('intval', explode(':', $v[1]));
                $minutos += max(0, ($h2 * 60 + $m2) - ($h1 * 60 + $m1));
            }
        }

        return round($minutos / 60, 1);
    }
}
