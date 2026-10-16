<?php

namespace App\Services\Reservas;

use App\Models\Espacio;
use App\Models\Reserva;
use App\Models\ReservaArea;
use App\Models\Sede;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * Disponibilidad de un área: sin choques (contando montaje y desmontaje),
 * dentro del horario permitido del día y sin pasar el aforo del montaje.
 *
 * Las horas se capturan en la hora local de la SEDE del área (su zona o la de
 * la empresa) y se guardan en hora universal.
 */
class Disponibilidad
{
    public function __construct(private readonly NivelesReservas $niveles) {}

    /**
     * Espacios que comparten lugar con el área: ella, la zona que la contiene
     * y sus espacios interiores (bloqueo cruzado).
     *
     * @return list<int>
     */
    public function espaciosRelacionados(ReservaArea $area): array
    {
        $espacio = $area->relationLoaded('espacio') && $area->espacio !== null ? $area->espacio : Espacio::find($area->espacio_id);

        return $espacio === null ? [(int) $area->espacio_id] : $this->niveles->espaciosRelacionados($espacio);
    }

    /** Zona horaria de la sede del área. */
    public function zona(ReservaArea $area): string
    {
        $sede = $area->relationLoaded('sede') ? $area->sede : Sede::withTrashed()->find($area->sede_id);

        return $sede?->zonaHoraria() ?? config('app.timezone');
    }

    /**
     * Fecha y horas locales → [inicio, fin] en hora universal.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function aUtc(ReservaArea $area, string $fecha, string $horaInicio, string $horaFin): array
    {
        $zona = $this->zona($area);
        try {
            $inicio = CarbonImmutable::createFromFormat('Y-m-d H:i', $fecha.' '.$horaInicio, $zona);
            $fin = CarbonImmutable::createFromFormat('Y-m-d H:i', $fecha.' '.$horaFin, $zona);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['fecha' => 'Revisa la fecha y las horas.']);
        }
        if ($inicio === false || $fin === false) {
            throw ValidationException::withMessages(['fecha' => 'Revisa la fecha y las horas.']);
        }
        if ($fin->lessThanOrEqualTo($inicio)) {
            throw ValidationException::withMessages(['hora_fin' => 'La hora de fin debe ser después de la hora de inicio.']);
        }

        return [$inicio->utc(), $fin->utc()];
    }

    /**
     * Bloqueo del área: del inicio menos el montaje al fin más el desmontaje.
     * Los minutos son los de la reserva si los trae (null = los del área).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}
     */
    public function bloqueo(ReservaArea $area, CarbonImmutable $inicio, CarbonImmutable $fin, ?int $montaje = null, ?int $desmontaje = null): array
    {
        return [$inicio->subMinutes($montaje ?? (int) $area->montaje_minutos), $fin->addMinutes($desmontaje ?? (int) $area->desmontaje_minutos)];
    }

    /**
     * Ventana de «Día completo» para una fecha local: el horario del área de
     * ese día (si tiene varias ventanas, la más larga) o 00:00–23:59 si el
     * área no tiene horario. null = ese día el área no se reserva.
     *
     * @return array{0: string, 1: string}|null
     */
    public function diaCompleto(ReservaArea $area, string $fecha): ?array
    {
        if (! is_array($area->horario) || $area->horario === []) {
            return ['00:00', '23:59'];
        }
        $dia = CarbonImmutable::createFromFormat('Y-m-d', $fecha, $this->zona($area))->dayOfWeekIso;
        $ventanas = collect((array) ($area->horario[(string) $dia] ?? []))
            ->filter(fn ($v) => is_array($v) && count($v) === 2)
            ->sortByDesc(fn ($v) => self::minutos((string) $v[1]) - self::minutos((string) $v[0]));

        return $ventanas->isEmpty() ? null : array_values($ventanas->first());
    }

    /** «13:30» → 810. */
    private static function minutos(string $hora): int
    {
        [$h, $m] = array_map('intval', explode(':', $hora) + [0, 0]);

        return $h * 60 + $m;
    }

    /**
     * Aforo que aplica (el del montaje elegido o el del área) y si las personas
     * lo pasan: [aforo, excede, texto «del montaje Tipo U» / «del área»].
     *
     * @return array{0: ?int, 1: bool, 2: string}
     */
    public function aforo(ReservaArea $area, ?int $montajeId, int $asistentes): array
    {
        $aforo = $area->aforoPara($montajeId);
        $montaje = $montajeId !== null ? $area->montajes->firstWhere('id', $montajeId) : null;
        $texto = $montaje !== null && $montaje->pivot->aforo !== null ? 'del montaje '.$montaje->nombre : 'del área';

        return [$aforo, $aforo !== null && $asistentes > $aforo, $texto];
    }

    /**
     * Reservas activas que se cruzan con ese bloqueo en el área o en un lugar
     * que comparte espacio con ella: si se reservó la zona completa, sus
     * espacios interiores están ocupados; si se reservó un espacio interior,
     * la zona completa no se puede reservar a esa hora. Cada reserva ya trae
     * su montaje y desmontaje en bloqueo_desde / bloqueo_hasta.
     *
     * @return Collection<int, Reserva>
     */
    public function choques(ReservaArea $area, CarbonImmutable $desde, CarbonImmutable $hasta, ?int $excepto = null): Collection
    {
        return Reserva::ocupan()->with('area.espacio:id,nombre')->whereIn('espacio_id', $this->espaciosRelacionados($area))
            ->where('bloqueo_desde', '<', $hasta)->where('bloqueo_hasta', '>', $desde)
            ->when($excepto !== null, fn ($q) => $q->whereKeyNot($excepto))
            ->orderBy('inicio')->get();
    }

    /**
     * ¿El horario del área permite de inicio a fin? (mismo día local, dentro de una ventana)
     */
    public function enHorario(ReservaArea $area, CarbonImmutable $inicio, CarbonImmutable $fin): bool
    {
        $horario = $area->horario;
        if (! is_array($horario) || $horario === []) {
            return true;
        }
        $zona = $this->zona($area);
        $i = $inicio->setTimezone($zona);
        $f = $fin->setTimezone($zona);
        if ($i->toDateString() !== $f->toDateString()) {
            return false;
        }
        foreach ((array) ($horario[(string) $i->dayOfWeekIso] ?? []) as $ventana) {
            if (is_array($ventana) && count($ventana) === 2 && $i->format('H:i') >= $ventana[0] && $f->format('H:i') <= $ventana[1]) {
                return true;
            }
        }

        return false;
    }

    /** «Lunes de 08:00 a 22:00» para el mensaje de error. */
    public function textoHorarioDe(ReservaArea $area, CarbonImmutable $fecha): string
    {
        $dia = $fecha->setTimezone($this->zona($area))->dayOfWeekIso;
        $ventanas = (array) (($area->horario ?? [])[(string) $dia] ?? []);
        if ($ventanas === []) {
            return 'El '.mb_strtolower(ReservaArea::DIAS[$dia]).' esta área no se reserva.';
        }

        return 'El '.mb_strtolower(ReservaArea::DIAS[$dia]).' se reserva de '.collect($ventanas)->map(fn ($v) => $v[0].' a '.$v[1])->join(' y de ').'.';
    }

    /**
     * Todas las reglas juntas. Lanza el mensaje que la persona entiende.
     * Pasar el aforo se permite solo como excepción con motivo
     * ($excepcionAforo, ya validada por quien llama).
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable} bloqueo
     */
    public function validar(ReservaArea $area, CarbonImmutable $inicio, CarbonImmutable $fin, ?int $montajeId, int $asistentes, ?int $excepto = null, bool $exigirFuturo = true,
        bool $excepcionAforo = false, ?int $montajeMinutos = null, ?int $desmontajeMinutos = null): array
    {
        if (! $area->reservable || ! $area->espacio?->activo) {
            throw ValidationException::withMessages(['reserva_area_id' => 'Esta área ya no se puede reservar.']);
        }
        if ($area->porInteriores()) {
            throw ValidationException::withMessages(['reserva_area_id' => 'Esta zona se reserva por sus espacios interiores: elige uno de ellos.']);
        }
        if ($exigirFuturo && $inicio->lessThanOrEqualTo(now())) {
            throw ValidationException::withMessages(['hora_inicio' => 'La reserva debe empezar más tarde que ahora.']);
        }
        if (! $this->enHorario($area, $inicio, $fin)) {
            throw ValidationException::withMessages(['hora_inicio' => 'Fuera del horario del área. '.$this->textoHorarioDe($area, $inicio)]);
        }
        if ($montajeId !== null && ! $area->montajes->contains('id', $montajeId)) {
            throw ValidationException::withMessages(['montaje_id' => 'Ese montaje no está disponible en esta área.']);
        }
        [$aforo, $excede, $deQue] = $this->aforo($area, $montajeId, $asistentes);
        if ($excede && ! $excepcionAforo) {
            throw ValidationException::withMessages(['asistentes' => "Son más personas que el aforo {$deQue}: máximo {$aforo}."]);
        }
        [$desde, $hasta] = $this->bloqueo($area, $inicio, $fin, $montajeMinutos, $desmontajeMinutos);
        $choque = $this->choques($area, $desde, $hasta, $excepto)->first();
        if ($choque !== null) {
            $zona = $this->zona($area);
            $texto = $choque->bloqueo_desde->setTimezone($zona)->format('H:i').' a '.$choque->bloqueo_hasta->setTimezone($zona)->format('H:i');
            $extra = (($montajeMinutos ?? (int) $area->montaje_minutos) + ($desmontajeMinutos ?? (int) $area->desmontaje_minutos)) > 0 ? ' (incluye montaje y desmontaje)' : '';
            if ((int) $choque->espacio_id !== (int) $area->espacio_id) {
                $otro = $choque->area?->nombre() ?? 'otro espacio';
                throw ValidationException::withMessages(['hora_inicio' => "«{$otro}» está reservado de {$texto} y comparte lugar con esta área (la zona completa o uno de sus espacios interiores). Elige otro horario u otra área."]);
            }
            throw ValidationException::withMessages(['hora_inicio' => "El área está ocupada de {$texto}{$extra}. Elige otro horario u otra área."]);
        }

        return [$desde, $hasta];
    }

    /**
     * Bloques ocupados de un día local (para el formulario y el calendario).
     *
     * @return list<array{desde: string, hasta: string, inicio: string, fin: string, id: int, otro: string|null}>
     */
    public function ocupadoEl(ReservaArea $area, string $fecha, ?int $excepto = null): array
    {
        $zona = $this->zona($area);
        $dia = CarbonImmutable::createFromFormat('Y-m-d', $fecha, $zona)->startOfDay();

        return $this->choques($area, $dia->utc(), $dia->addDay()->utc(), $excepto)
            ->map(fn (Reserva $r) => [
                'id' => (int) $r->id,
                'desde' => $r->bloqueo_desde->setTimezone($zona)->format('H:i'),
                'hasta' => $r->bloqueo_hasta->setTimezone($zona)->format('H:i'),
                'inicio' => $r->inicio->setTimezone($zona)->format('H:i'),
                'fin' => $r->fin->setTimezone($zona)->format('H:i'),
                // Reserva de la zona completa o de un espacio interior que también ocupa esta área
                'otro' => (int) $r->espacio_id !== (int) $area->espacio_id ? $r->area?->nombre() : null,
            ])->values()->all();
    }
}
