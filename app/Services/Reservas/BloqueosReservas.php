<?php

namespace App\Services\Reservas;

use App\Mail\AvisoRecepcion;
use App\Models\Auditoria;
use App\Models\Reserva;
use App\Models\ReservaArea;
use App\Models\ReservaBloqueo;
use App\Models\User;
use App\Services\Avisos\AvisosCorreo;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Services\Permisos\Autorizador;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Bloqueos de sitios (docs/tecnico/reservas.md, «Bloqueos»): un área o
 * espacio interior no se puede reservar en un rango de fecha y hora por un
 * motivo (mantenimiento, evento de dirección…).
 *
 * Quién bloquea (decisión del dueño, 2026-11-12):
 *  - el responsable general de reservas y su suplente: cualquier sitio de la
 *    empresa (el responsable general es uno por empresa);
 *  - el responsable y el suplente de un área (los suyos, no los heredados del
 *    general): solo esa área;
 *  - quien tenga `reservas.bloquear` en la sede del área (el motor decide).
 *
 * Si ya hay reservas en esas fechas el bloqueo entra igual: esas reservas
 * quedan «Afectada por bloqueo» (reservas.bloqueo_id) y se avisa a quien
 * reservó y al responsable del área (campana y correo según preferencias).
 * Nada se da de baja solo.
 */
class BloqueosReservas
{
    /** Motivos sugeridos en el formulario (se puede escribir cualquiera). */
    public const MOTIVOS = ['Mantenimiento', 'Evento de dirección', 'Limpieza profunda', 'Remodelación', 'Fumigación'];

    public function __construct(
        private readonly Autorizador $autorizador,
        private readonly Disponibilidad $disponibilidad,
        private readonly ResponsablesReservas $responsables,
        private readonly AjustesReservas $ajustes,
        private readonly FlujoReservas $flujo,
        private readonly TextosReservas $textos,
        private readonly CentroNotificaciones $centro,
        private readonly AvisosCorreo $correo,
    ) {}

    // ---------------------------------------------------------------- Quién

    /** ¿Es el responsable general de reservas o su suplente? */
    public function esGeneral(User $actor, int $empresaId): bool
    {
        return in_array((int) $actor->id, $this->responsables->generales($empresaId), true);
    }

    /** ¿Puede bloquear esta área? */
    public function puedeBloquear(User $actor, ReservaArea $area): bool
    {
        if ($this->esGeneral($actor, (int) $area->empresa_id)) {
            return true;
        }
        if (in_array((int) $actor->id, array_map('intval', array_filter([$area->responsable_id, $area->suplente_id])), true)) {
            return true;
        }
        if (! $actor->can('reservas.bloquear')) {
            return false;
        }
        $sedes = $this->autorizador->sedesPermitidas($actor, 'reservas.bloquear');

        return $sedes === null || in_array((int) $area->sede_id, $sedes, true);
    }

    /** ¿Puede bloquear algún sitio? (pestaña «Bloqueos» y botones) */
    public function puedeBloquearAlgo(User $actor, int $empresaId): bool
    {
        return $actor->can('reservas.bloquear') || $this->esGeneral($actor, $empresaId)
            || ReservaArea::withoutGlobalScopes()->where('empresa_id', $empresaId)->where('reservable', true)->where(fn ($q) => $q->where('responsable_id', $actor->id)->orWhere('suplente_id', $actor->id))->exists();
    }

    /**
     * Áreas reservables que puede bloquear (con su espacio y su sede).
     *
     * @return Collection<int, ReservaArea>
     */
    public function areasQuePuede(User $actor): Collection
    {
        return ReservaArea::with(['espacio:id,nombre,activo,nivel,ruta,padre_id', 'sede:id,nombre,zona_horaria,empresa_id'])
            ->where('reservable', true)->whereHas('espacio', fn ($q) => $q->where('activo', true))->get()
            ->filter(fn (ReservaArea $a) => $this->puedeBloquear($actor, $a))
            ->sortBy(fn (ReservaArea $a) => $a->sede?->nombre.' '.$a->rutaTexto())->values();
    }

    // -------------------------------------------------------------- Impacto

    /**
     * Reservas activas que caen en el bloqueo (en el sitio, la zona que lo
     * contiene o sus espacios interiores), contando su montaje y desmontaje.
     *
     * @return Collection<int, Reserva>
     */
    public function afectadas(ReservaArea $area, CarbonImmutable $desde, CarbonImmutable $hasta): Collection
    {
        return $this->disponibilidad->choques($area, $desde, $hasta)
            ->filter(fn (Reserva $r) => $r->fin->greaterThan(now()))->values()
            ->load(['solicitante:id,name,email,activo']);
    }

    /**
     * Valida los datos del formulario y da [área, desde, hasta, motivo] en
     * hora universal (la hora se captura en la hora local de la sede).
     *
     * @param  array<string, mixed>  $datos
     * @return array{0: ReservaArea, 1: CarbonImmutable, 2: CarbonImmutable, 3: string}
     */
    public function leer(User $actor, array $datos, bool $exigirMotivo = true): array
    {
        $area = ReservaArea::with(['espacio', 'sede'])->where('reservable', true)->find((int) ($datos['reserva_area_id'] ?? 0));
        if ($area === null || ! $this->puedeBloquear($actor, $area)) {
            throw ValidationException::withMessages(['reserva_area_id' => 'Elige un sitio que puedas bloquear.']);
        }
        $zona = $this->disponibilidad->zona($area);
        $fechaDesde = (string) ($datos['fecha_desde'] ?? '');
        $fechaHasta = (string) (($datos['fecha_hasta'] ?? '') !== '' ? $datos['fecha_hasta'] : $fechaDesde);
        $todoElDia = filter_var($datos['todo_el_dia'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $horaDesde = $todoElDia ? '00:00' : (string) ($datos['hora_desde'] ?? '');
        $horaHasta = $todoElDia ? '00:00' : (string) ($datos['hora_hasta'] ?? '');
        $formato = fn (string $f, string $h) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $f) === 1 && preg_match('/^\d{2}:\d{2}$/', $h) === 1;
        if (! $formato($fechaDesde, $horaDesde) || ! $formato($fechaHasta, $horaHasta)) {
            throw ValidationException::withMessages(['fecha_desde' => 'Escribe el día y la hora de inicio y de fin del bloqueo.']);
        }
        try {
            $desde = CarbonImmutable::createFromFormat('Y-m-d H:i', $fechaDesde.' '.$horaDesde, $zona);
            $hasta = CarbonImmutable::createFromFormat('Y-m-d H:i', $fechaHasta.' '.$horaHasta, $zona);
        } catch (\Throwable) {
            throw ValidationException::withMessages(['fecha_desde' => 'Revisa los días y las horas del bloqueo.']);
        }
        if ($todoElDia) {
            $hasta = $hasta->addDay(); // «Todo el día»: hasta el final del último día
        }
        if ($hasta->lessThanOrEqualTo($desde)) {
            throw ValidationException::withMessages(['fecha_hasta' => 'El fin del bloqueo debe ser después del inicio.']);
        }
        if ($hasta->lessThanOrEqualTo(now())) {
            throw ValidationException::withMessages(['fecha_hasta' => 'Ese horario ya pasó: elige uno que termine más tarde que ahora.']);
        }
        if ($desde->diffInDays($hasta) > 366) {
            throw ValidationException::withMessages(['fecha_hasta' => 'Un bloqueo dura como máximo un año.']);
        }
        $motivo = trim((string) ($datos['motivo'] ?? ''));
        if ($exigirMotivo && mb_strlen($motivo) < 3) {
            throw ValidationException::withMessages(['motivo' => 'Escribe el motivo del bloqueo (por ejemplo, «Mantenimiento»).']);
        }

        return [$area, $desde->utc(), $hasta->utc(), mb_substr($motivo, 0, 255)];
    }

    // ------------------------------------------------------------- Bloquear

    /**
     * Guarda el bloqueo, marca «Afectada por bloqueo» las reservas que caen en
     * él y avisa a quien reservó y al responsable del área.
     *
     * @param  array<string, mixed>  $datos
     * @return array{0: ReservaBloqueo, 1: Collection<int, Reserva>}
     */
    public function crear(User $actor, array $datos): array
    {
        [$area, $desde, $hasta, $motivo] = $this->leer($actor, $datos);

        [$bloqueo, $afectadas] = DB::transaction(function () use ($area, $desde, $hasta, $motivo) {
            // Mismo candado que al reservar: nadie gana ese horario mientras se bloquea
            ReservaArea::whereIn('espacio_id', $this->disponibilidad->espaciosRelacionados($area))->orderBy('id')->lockForUpdate()->get(['id']);
            $bloqueo = ReservaBloqueo::create([
                'empresa_id' => $area->empresa_id, 'sede_id' => $area->sede_id, 'reserva_area_id' => $area->id, 'espacio_id' => $area->espacio_id,
                'desde' => $desde, 'hasta' => $hasta, 'motivo' => $motivo,
            ]);
            $afectadas = $this->afectadas($area, $desde, $hasta);
            if ($afectadas->isNotEmpty()) {
                Reserva::whereKey($afectadas->modelKeys())->update(['bloqueo_id' => $bloqueo->id]);
            }

            return [$bloqueo, $afectadas];
        });
        $bloqueo->setRelation('area', $area)->setRelation('espacio', $area->espacio);
        $zona = $this->disponibilidad->zona($area);
        $rango = Disponibilidad::rangoTexto($desde, $hasta, $zona);

        Auditoria::create([
            'empresa_id' => $bloqueo->empresa_id, 'user_id' => $actor->id, 'evento' => 'reservas.bloqueo_creado',
            'auditable_type' => ReservaBloqueo::class, 'auditable_id' => $bloqueo->id, 'antes' => [],
            'despues' => ['sitio' => $area->rutaTexto(), 'desde' => $desde->toIso8601String(), 'hasta' => $hasta->toIso8601String(), 'motivo' => $motivo,
                'reservas_afectadas' => $afectadas->map(fn ($r) => $r->folioTexto())->values()->all()],
            'ip' => request()?->ip(),
        ]);

        foreach ($afectadas as $reserva) {
            $reserva->bloqueo_id = $bloqueo->id;
            $this->flujo->evento($reserva, 'bloqueo', "Afectada por bloqueo de «{$bloqueo->sitio()}» {$rango}: {$motivo} ({$actor->name})", $actor);
            $this->avisarSolicitante($actor, $reserva, 'reserva_bloqueada', 'Tu reserva quedó afectada por un bloqueo: '.$reserva->titulo,
                "«{$bloqueo->sitio()}» se bloqueó {$rango} ({$motivo}). Muévela a otro horario u otra área, o dala de baja. ".$this->textos->resumen($reserva));
        }
        $this->avisarResponsables($actor, $area, $afectadas, 'reserva_bloqueada',
            'Bloqueo con reservas afectadas: '.$bloqueo->sitio(),
            "«{$bloqueo->sitio()}» se bloqueó {$rango} ({$motivo}). Reservas afectadas: ".$afectadas->map(fn ($r) => $r->folioTexto().' '.$r->titulo)->join(', ').'. Avisamos a quienes reservaron para que las muevan o las den de baja.');

        return [$bloqueo, $afectadas];
    }

    // ----------------------------------------------------------- Dar de baja

    /**
     * Quita el bloqueo (queda de historial). Las reservas que afectaba dejan
     * de estar «Afectada por bloqueo» si ningún otro bloqueo vigente cae en
     * su horario, y se avisa a quien reservó.
     */
    public function darDeBaja(User $actor, ReservaBloqueo $bloqueo, ?string $motivo = null): int
    {
        $bloqueo->loadMissing(['area.espacio', 'area.sede', 'espacio']);
        if ($bloqueo->area === null || ! $this->puedeBloquear($actor, $bloqueo->area)) {
            abort(403);
        }
        if (! $bloqueo->activo) {
            throw ValidationException::withMessages(['bloqueo' => 'Ese bloqueo ya se había dado de baja.']);
        }
        $motivo = trim((string) $motivo);
        $liberadas = collect();
        DB::transaction(function () use ($actor, $bloqueo, $motivo, &$liberadas) {
            $bloqueo->forceFill(['activo' => false, 'dado_de_baja_en' => now(), 'dado_de_baja_por' => $actor->id,
                'motivo_baja' => $motivo === '' ? null : mb_substr($motivo, 0, 255)])->save();
            foreach (Reserva::with(['area.espacio', 'area.sede', 'solicitante:id,name,email,activo'])->where('bloqueo_id', $bloqueo->id)->get() as $reserva) {
                $otro = $reserva->area === null ? null : $this->disponibilidad->bloqueos($reserva->area, CarbonImmutable::instance($reserva->bloqueo_desde), CarbonImmutable::instance($reserva->bloqueo_hasta))->first();
                $reserva->forceFill(['bloqueo_id' => $otro?->id])->save();
                if ($otro === null) {
                    $liberadas->push($reserva);
                }
            }
        });
        Auditoria::create([
            'empresa_id' => $bloqueo->empresa_id, 'user_id' => $actor->id, 'evento' => 'reservas.bloqueo_baja',
            'auditable_type' => ReservaBloqueo::class, 'auditable_id' => $bloqueo->id, 'antes' => ['activo' => true],
            'despues' => ['activo' => false, 'motivo_baja' => $bloqueo->motivo_baja, 'reservas_liberadas' => $liberadas->map(fn ($r) => $r->folioTexto())->values()->all()],
            'ip' => request()?->ip(),
        ]);
        $rango = Disponibilidad::rangoTexto($bloqueo->desde, $bloqueo->hasta, $this->disponibilidad->zona($bloqueo->area));
        foreach ($liberadas as $reserva) {
            if (! $reserva->activa()) {
                continue;
            }
            $this->flujo->evento($reserva, 'bloqueo_quitado', "Se quitó el bloqueo de «{$bloqueo->sitio()}» {$rango} ({$actor->name}): la reserva ya no está afectada", $actor);
            $this->avisarSolicitante($actor, $reserva, 'reserva_bloqueo_quitado', 'Se quitó el bloqueo que afectaba tu reserva: '.$reserva->titulo,
                "«{$bloqueo->sitio()}» ya no está bloqueado {$rango}. Tu reserva sigue como estaba. ".$this->textos->resumen($reserva));
        }

        return $liberadas->count();
    }

    // ---------------------------------------------------------------- Avisos

    private function avisarSolicitante(User $actor, Reserva $reserva, string $tipo, string $titulo, string $texto): void
    {
        $solicitante = $reserva->solicitante;
        if ($solicitante === null || ! $solicitante->activo || (int) $solicitante->id === (int) $actor->id) {
            return;
        }
        $url = route('reservas.show', $reserva->id);
        $this->centro->avisar((int) $reserva->empresa_id, [(int) $solicitante->id], $tipo, [
            'titulo' => $titulo, 'texto' => $texto, 'url' => $url, 'referencia_tipo' => 'reserva', 'referencia_id' => (int) $reserva->id,
        ]);
        if ($solicitante->email) {
            $this->correo->recepcion((int) $reserva->empresa_id, 'reserva_resultado', [$solicitante->email],
                new AvisoRecepcion($titulo, [$texto], [['Abrir la reserva', $url]]));
        }
    }

    /**
     * Al responsable y al suplente del área (o a los generales si el área no
     * tiene), solo si hay reservas afectadas y sin avisar a quien bloqueó.
     *
     * @param  Collection<int, Reserva>  $afectadas
     */
    private function avisarResponsables(User $actor, ReservaArea $area, Collection $afectadas, string $tipo, string $titulo, string $texto): void
    {
        if ($afectadas->isEmpty()) {
            return;
        }
        $ids = collect($this->responsables->delArea($area))
            ->merge($afectadas->map(fn (Reserva $r) => $r->area ? $this->responsables->delArea($r->area) : [])->flatten())
            ->filter()->map(fn ($id) => (int) $id)->unique()->reject(fn ($id) => $id === (int) $actor->id)->values();
        $usuarios = User::whereIn('id', $ids)->where('activo', true)->get(['id', 'email']);
        if ($usuarios->isEmpty()) {
            return;
        }
        $url = route('reservas.bloqueos');
        $this->centro->avisar((int) $area->empresa_id, $usuarios->pluck('id'), $tipo, ['titulo' => $titulo, 'texto' => $texto, 'url' => $url]);
        $this->correo->recepcion((int) $area->empresa_id, 'reserva_aprobacion', $usuarios->pluck('email')->filter()->values()->all(),
            new AvisoRecepcion($titulo, [$texto], [['Ver los bloqueos', $url]]));
    }
}
