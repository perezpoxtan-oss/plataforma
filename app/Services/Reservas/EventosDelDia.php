<?php

namespace App\Services\Reservas;

use App\Models\Reserva;
use App\Services\Permisos\Autorizador;
use App\Support\HoraLocal;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * C-31 (D-12, X-05): los eventos confirmados de Reservas que ocurren un día
 * (en la hora local), para que la caseta no se entere en la puerta de que
 * llegan cien personas al Salón Maya. Lo usan la tarjeta «Eventos de hoy»
 * de Accesos, la Bitácora general del día y el Informe ejecutivo.
 *
 * Solo lee: área, horario (con montaje), título y número de personas. No
 * muestra los datos del cliente. Si la empresa no tiene Reservas, no hay
 * eventos.
 */
class EventosDelDia
{
    public const MAX = 50;

    public function __construct(
        private readonly Autorizador $autorizador,
        private readonly HoraLocal $hora,
    ) {}

    public function hayReservas(int $empresaId): bool
    {
        return $this->autorizador->moduloActivo($empresaId, 'reservas');
    }

    /** [inicio, fin) del día en hora universal. */
    public function dia(?CarbonImmutable $fecha = null, ?string $zona = null): array
    {
        $zona ??= $this->hora->zona();
        $inicio = CarbonImmutable::createFromFormat('!Y-m-d', ($fecha ?? CarbonImmutable::now($zona))->setTimezone($zona)->format('Y-m-d'), $zona);

        return [$inicio->utc(), $inicio->addDay()->utc()];
    }

    /**
     * Consulta de los eventos confirmados que tocan el día (incluye montaje y
     * desmontaje: el área se ocupa desde bloqueo_desde).
     *
     * @return Builder<Reserva>
     */
    public function consulta(?CarbonImmutable $fecha = null, ?string $zona = null): Builder
    {
        [$desde, $hasta] = $this->dia($fecha, $zona);

        return Reserva::query()->where('reservas.estado', Reserva::CONFIRMADA)
            ->where('reservas.bloqueo_desde', '<', $hasta)->where('reservas.bloqueo_hasta', '>', $desde)
            ->with(['sede:id,nombre', 'espacio:id,nombre'])
            ->orderBy('reservas.inicio');
    }

    /**
     * Eventos de hoy en las sedes indicadas (null = todas las de la empresa).
     *
     * @param  list<int>|null  $sedes
     * @return Collection<int, array<string, mixed>>
     */
    public function deHoy(int $empresaId, ?array $sedes, ?CarbonImmutable $fecha = null): Collection
    {
        if (! $this->hayReservas($empresaId) || $sedes === []) {
            return collect();
        }

        return $this->consulta($fecha)->where('reservas.empresa_id', $empresaId)
            ->when($sedes !== null, fn ($q) => $q->whereIn('reservas.sede_id', $sedes))
            ->limit(self::MAX)->get()->map(fn (Reserva $r) => $this->renglon($r))->values();
    }

    /** @return array<string, mixed> */
    public function renglon(Reserva $r): array
    {
        $montaje = $r->bloqueo_desde !== null && $r->bloqueo_desde->lt($r->inicio) ? $this->hora->formatear($r->bloqueo_desde, 'H:i') : null;

        return [
            'id' => $r->id,
            'folio' => $r->folio,
            'titulo' => $r->titulo ?: 'Evento',
            'area' => $r->espacio?->nombre ?? 'Área',
            'sede' => $r->sede?->nombre,
            'sede_id' => (int) $r->sede_id,
            'inicio' => $this->hora->formatear($r->inicio, 'H:i'),
            'fin' => $this->hora->formatear($r->fin, $r->inicio->copy()->setTimezone($this->hora->zona())->isSameDay($r->fin->copy()->setTimezone($this->hora->zona())) ? 'H:i' : 'd/m H:i'),
            'montaje' => $montaje,
            'asistentes' => (int) $r->asistentes,
            'horario' => $this->hora->formatear($r->inicio, 'H:i').'–'.$this->hora->formatear($r->fin, 'H:i'),
        ];
    }
}
