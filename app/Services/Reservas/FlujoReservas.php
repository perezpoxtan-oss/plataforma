<?php

namespace App\Services\Reservas;

use App\Mail\AvisoRecepcion;
use App\Models\Auditoria;
use App\Models\Departamento;
use App\Models\Reserva;
use App\Models\ReservaAprobacion;
use App\Models\ReservaEvento;
use App\Models\ReservaOrden;
use App\Models\ReservaServicioItem;
use App\Models\User;
use App\Services\Avisos\AvisosCorreo;
use App\Services\Notificaciones\CentroNotificaciones;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Aprobaciones, confirmación y órdenes de servicio de una reserva
 * (docs/tecnico/reservas.md, diagrama de estados).
 *
 *  - Al crear o modificar: se calculan las partes que deben aprobar (el ÁREA
 *    y CADA DEPARTAMENTO de los servicios que lo requieren), en paralelo. Una
 *    parte ya aprobada cuya huella no cambió conserva su aprobación; las
 *    demás se vuelven a pedir (campana con botón «Aprobar», correo y Mis
 *    pendientes). Sin partes por aprobar (área de confirmación inmediata, sin
 *    servicios ni costo) la reserva queda confirmada al momento.
 *  - Responder: Aprobar; Rechazar (con motivo: la reserva queda rechazada y
 *    las demás aprobaciones ya no aplican) o pedir un cambio (con motivo: la
 *    reserva regresa a quien la hizo para modificarla). Cuando TODAS las
 *    vigentes aprueban, la reserva se confirma.
 *  - Al confirmarse: cada departamento con servicios recibe su ORDEN DE
 *    SERVICIO y la marca «Listo»; quien reservó y el responsable del área ven
 *    el avance. Si después cambia lo de un departamento, su orden vuelve a
 *    pendiente con aviso; si ya no tiene servicios, su orden se cancela.
 */
class FlujoReservas
{
    public const RESPUESTAS = ['aprobar', 'rechazar', 'cambio'];

    public function __construct(
        private readonly ResponsablesReservas $responsables,
        private readonly CentroNotificaciones $centro,
        private readonly AvisosCorreo $correo,
        private readonly TextosReservas $textos,
    ) {}

    // ---------------------------------------------------- Partes que aprueban

    /**
     * Partes que deben aprobar hoy: clave => [tipo, departamento_id, huella].
     *
     * @return array<string, array{tipo: string, departamento_id: ?int, huella: string}>
     */
    public function requeridas(Reserva $reserva): array
    {
        $reserva->loadMissing(['area', 'items.servicio']);
        $items = $reserva->items;
        $partes = [];
        if (! ($reserva->area->confirmacion_inmediata && $items->isEmpty() && ! $reserva->para_cliente)) {
            $partes['area'] = ['tipo' => 'area', 'departamento_id' => null, 'huella' => $this->huellaArea($reserva)];
        }
        $porDepto = $items->filter(fn (ReservaServicioItem $i) => $i->departamento_id !== null && ($i->servicio?->requiere_aprobacion ?? true))
            ->groupBy('departamento_id');
        foreach ($porDepto as $departamento => $renglones) {
            $partes['departamento:'.$departamento] = ['tipo' => 'departamento', 'departamento_id' => (int) $departamento,
                'huella' => $this->huellaDepartamento($reserva, (int) $departamento)];
        }

        return $partes;
    }

    public function huellaArea(Reserva $r): string
    {
        return hash('sha256', json_encode([(int) $r->espacio_id, $r->inicio?->toIso8601String(), $r->fin?->toIso8601String(),
            $r->montaje_id, (int) $r->asistentes, (bool) $r->para_cliente, $r->tarifa]));
    }

    public function huellaDepartamento(Reserva $r, int $departamentoId): string
    {
        $renglones = $r->items->where('departamento_id', $departamentoId)
            ->map(fn ($i) => [$i->servicio_id, $i->nombre, number_format((float) $i->cantidad, 2, '.', ''), (string) $i->notas])
            ->sortBy(fn ($x) => json_encode($x))->values()->all();

        return hash('sha256', json_encode([(int) $r->espacio_id, $r->inicio?->toIso8601String(), $r->fin?->toIso8601String(), $r->montaje_id, $renglones]));
    }

    /**
     * Después de crear o modificar: pide lo que haga falta y deja la reserva
     * en «Por aprobar» o «Confirmada». Devuelve las aprobaciones nuevas.
     *
     * @return Collection<int, ReservaAprobacion>
     */
    public function sincronizar(User $actor, Reserva $reserva): Collection
    {
        $requeridas = $this->requeridas($reserva);
        $nuevas = collect();
        $estabaConfirmada = $reserva->estado === Reserva::CONFIRMADA;

        DB::transaction(function () use ($reserva, $requeridas, &$nuevas) {
            $vigentes = ReservaAprobacion::where('reserva_id', $reserva->id)->where('vigente', true)->get()->keyBy(fn ($a) => $a->clave());
            foreach ($vigentes as $clave => $a) {
                if (! isset($requeridas[$clave])) {
                    $this->retirar($a);
                }
            }
            foreach ($requeridas as $clave => $parte) {
                $actual = $vigentes[$clave] ?? null;
                // Lo que no cambió conserva su aprobación (o sigue esperando sin volver a avisar)
                if ($actual !== null && in_array($actual->estado, [ReservaAprobacion::APROBADA, ReservaAprobacion::PENDIENTE], true) && $actual->huella === $parte['huella']) {
                    continue;
                }
                if ($actual !== null) {
                    $this->retirar($actual);
                }
                $nuevas->push(ReservaAprobacion::create(['reserva_id' => $reserva->id, 'tipo' => $parte['tipo'], 'departamento_id' => $parte['departamento_id'],
                    'huella' => $parte['huella'], 'solicitada_en' => now(), 'vigente' => true, 'estado' => ReservaAprobacion::PENDIENTE]));
            }
            $faltan = ReservaAprobacion::where('reserva_id', $reserva->id)->where('vigente', true)->where('estado', '!=', ReservaAprobacion::APROBADA)->exists();
            $reserva->forceFill(['estado' => $faltan ? Reserva::POR_APROBAR : Reserva::CONFIRMADA])->save();
        });

        foreach ($nuevas as $a) {
            $this->pedir($actor, $reserva, $a);
        }
        if ($reserva->estado === Reserva::CONFIRMADA) {
            $this->confirmada($actor, $reserva, ! $estabaConfirmada);
        } elseif ($estabaConfirmada) {
            $this->evento($reserva, 'por_aprobar', 'El cambio necesita aprobación: la reserva vuelve a «Por aprobar»', null);
        }

        return $nuevas;
    }

    /** La aprobación deja de valer (queda de historial). */
    private function retirar(ReservaAprobacion $a): void
    {
        $a->forceFill(['vigente' => false, 'estado' => $a->pendiente() ? ReservaAprobacion::CANCELADA : $a->estado])->save();
        $this->centro->resolver('reserva_aprobacion', (int) $a->id);
    }

    /** Aviso a quien debe aprobar (campana con botón Aprobar y correo). */
    private function pedir(User $actor, Reserva $reserva, ReservaAprobacion $a): void
    {
        $usuarios = $this->responsables->aQuienAvisar($a, $reserva);
        $a->forceFill(['avisados' => $usuarios->pluck('id')->map(fn ($id) => (int) $id)->values()->all()])->save();
        $this->evento($reserva, 'aprobacion_pedida', 'Se pidió la aprobación de '.$this->textos->parte($a).' a '.($usuarios->pluck('name')->join(', ', ' y ') ?: 'nadie (no hay responsable)'), $actor);
        if ($usuarios->isEmpty()) {
            return;
        }
        $titulo = 'Reserva por aprobar: '.$reserva->titulo;
        $texto = $this->textos->resumen($reserva).($a->tipo === 'departamento' ? ' · Servicios de '.$this->textos->parte($a).': '.$this->textos->serviciosDe($reserva, (int) $a->departamento_id) : '');
        $this->centro->avisar((int) $reserva->empresa_id, $usuarios->pluck('id'), 'reserva_por_aprobar', [
            'titulo' => $titulo, 'texto' => $texto, 'url' => route('reservas.show', $reserva->id),
            'referencia_tipo' => 'reserva_aprobacion', 'referencia_id' => (int) $a->id,
            'acciones' => [
                ['etiqueta' => 'Aprobar', 'url' => route('reservas.aprobaciones.responder', $a->id), 'campos' => ['respuesta' => 'aprobar'], 'estilo' => 'exito'],
                ['etiqueta' => 'Ver y responder', 'abrir' => true],
            ],
        ]);
        $this->correo->recepcion((int) $reserva->empresa_id, 'reserva_aprobacion', $usuarios->pluck('email')->filter()->values()->all(),
            new AvisoRecepcion($titulo, [$texto, 'Aprueba, rechaza o pide un cambio desde la plataforma.'], [['Abrir la reserva', route('reservas.show', $reserva->id)]]));
    }

    // -------------------------------------------------------------- Responder

    public function responder(User $actor, ReservaAprobacion $a, string $respuesta, ?string $comentario): Reserva
    {
        $reserva = Reserva::with(['area.espacio', 'items.servicio', 'solicitante'])->findOrFail($a->reserva_id);
        if (! in_array($respuesta, self::RESPUESTAS, true)) {
            throw ValidationException::withMessages(['respuesta' => 'Elige una respuesta.']);
        }
        if (! $a->vigente || ! $a->pendiente() || ! in_array($reserva->estado, [Reserva::POR_APROBAR, Reserva::CAMBIO_PEDIDO], true)) {
            throw ValidationException::withMessages(['respuesta' => 'Esta aprobación ya se respondió o ya no aplica.']);
        }
        if (! $this->responsables->puedeResponder($actor, $a, $reserva)) {
            abort(403);
        }
        $comentario = trim((string) $comentario);
        if ($respuesta !== 'aprobar' && mb_strlen($comentario) < 3) {
            throw ValidationException::withMessages(['comentario' => $respuesta === 'rechazar' ? 'Escribe el motivo del rechazo.' : 'Escribe qué cambio necesitas.']);
        }
        $estado = ['aprobar' => ReservaAprobacion::APROBADA, 'rechazar' => ReservaAprobacion::RECHAZADA, 'cambio' => ReservaAprobacion::CAMBIO][$respuesta];

        // El primero que responde gana
        $tomada = ReservaAprobacion::whereKey($a->id)->where('estado', ReservaAprobacion::PENDIENTE)->where('vigente', true)->update([
            'estado' => $estado, 'respondida_por' => $actor->id, 'respondida_en' => now(), 'comentario' => $comentario === '' ? null : mb_substr($comentario, 0, 1000),
            'actualizado_por' => $actor->id, 'updated_at' => now(),
        ]);
        if ($tomada === 0) {
            throw ValidationException::withMessages(['respuesta' => 'Alguien más ya respondió esta aprobación.']);
        }
        $a->refresh();
        $this->centro->resolver('reserva_aprobacion', (int) $a->id);
        $parte = $this->textos->parte($a);
        $this->auditar($actor, match ($respuesta) {
            'rechazar' => 'reservas.rechazada', 'cambio' => 'reservas.cambio_pedido', default => 'reservas.aprobada',
        }, $reserva, ['parte' => $parte, 'comentario' => $a->comentario]);

        if ($respuesta === 'rechazar') {
            ReservaAprobacion::where('reserva_id', $reserva->id)->where('vigente', true)->where('estado', ReservaAprobacion::PENDIENTE)->get()
                ->each(fn ($otra) => $this->retirar($otra));
            $reserva->forceFill(['estado' => Reserva::RECHAZADA])->save();
            $this->evento($reserva, 'rechazada', "Rechazada por {$actor->name} ({$parte}): {$a->comentario}", $actor);
            $this->avisarSolicitante($reserva, 'reserva_rechazada', 'Tu reserva fue rechazada: '.$reserva->titulo,
                $this->textos->resumen($reserva).' · '.$parte.': '.$a->comentario, $actor);

            return $reserva;
        }
        if ($respuesta === 'cambio') {
            $reserva->forceFill(['estado' => Reserva::CAMBIO_PEDIDO])->save();
            $this->evento($reserva, 'cambio_pedido', "{$actor->name} ({$parte}) pidió un cambio: {$a->comentario}", $actor);
            $this->avisarSolicitante($reserva, 'reserva_cambio', 'Piden un cambio en tu reserva: '.$reserva->titulo,
                $parte.': '.$a->comentario.' · Modifícala para volver a enviarla.', $actor, 'Modificar la reserva', route('reservas.edit', $reserva->id));

            return $reserva;
        }

        $this->evento($reserva, 'aprobada_parte', "Aprobada por {$actor->name} ({$parte})".($a->comentario ? ': '.$a->comentario : ''), $actor);
        $faltan = ReservaAprobacion::where('reserva_id', $reserva->id)->where('vigente', true)->where('estado', '!=', ReservaAprobacion::APROBADA)->exists();
        if (! $faltan && $reserva->estado === Reserva::POR_APROBAR) {
            $reserva->forceFill(['estado' => Reserva::CONFIRMADA])->save();
            $this->confirmada($actor, $reserva, true);
        }

        return $reserva;
    }

    // ----------------------------------------------------------- Confirmación

    private function confirmada(User $actor, Reserva $reserva, bool $avisar): void
    {
        if ($reserva->confirmada_en === null || $avisar) {
            $reserva->forceFill(['confirmada_en' => now()])->save();
        }
        $this->sincronizarOrdenes($actor, $reserva);
        if ($avisar) {
            $this->evento($reserva, 'confirmada', 'Reserva confirmada', $actor);
            $this->auditar($actor, 'reservas.confirmada', $reserva, ['estado' => Reserva::CONFIRMADA]);
            $this->avisarSolicitante($reserva, 'reserva_confirmada', 'Tu reserva quedó confirmada: '.$reserva->titulo, $this->textos->resumen($reserva), $actor,
                'Imprimir la hoja', route('reservas.imprimir', $reserva->id));
        }
    }

    /**
     * Una orden por departamento con servicios (pidan o no aprobación).
     */
    public function sincronizarOrdenes(User $actor, Reserva $reserva): void
    {
        $reserva->load('items');
        $deptos = $reserva->items->whereNotNull('departamento_id')->pluck('departamento_id')->map(fn ($d) => (int) $d)->unique()->values();
        $existentes = ReservaOrden::where('reserva_id', $reserva->id)->get()->keyBy('departamento_id');
        foreach ($existentes as $depto => $orden) {
            if (! $deptos->contains((int) $depto) && $orden->estado !== ReservaOrden::CANCELADA) {
                $orden->forceFill(['estado' => ReservaOrden::CANCELADA])->save();
                $this->avisarDepartamento($reserva, $orden, 'Ya no se necesita la orden de servicio', $actor);
            }
        }
        foreach ($deptos as $depto) {
            $huella = $this->huellaDepartamento($reserva, $depto);
            $orden = $existentes[$depto] ?? null;
            if ($orden !== null && $orden->huella === $huella && $orden->estado !== ReservaOrden::CANCELADA) {
                continue;
            }
            $cambio = $orden !== null;
            if ($orden === null) {
                $orden = ReservaOrden::create(['reserva_id' => $reserva->id, 'departamento_id' => $depto, 'huella' => $huella]);
            } else {
                $orden->forceFill(['estado' => ReservaOrden::PENDIENTE, 'huella' => $huella, 'lista_en' => null, 'lista_por' => null])->save();
            }
            $this->avisarDepartamento($reserva, $orden, $cambio ? 'Cambió la orden de servicio' : 'Orden de servicio', $actor);
        }
    }

    private function avisarDepartamento(Reserva $reserva, ReservaOrden $orden, string $encabezado, User $actor): void
    {
        $orden->loadMissing('departamento:id,nombre');
        $usuarios = $this->responsables->delDepartamento((int) $orden->departamento_id, (int) $reserva->sede_id, (int) $reserva->empresa_id);
        $depto = (string) ($orden->departamento?->nombre ?? 'Departamento');
        $this->evento($reserva, 'orden', "{$encabezado} para {$depto}", $actor);
        if ($usuarios->isEmpty()) {
            return;
        }
        $titulo = "{$encabezado} ({$depto}): {$reserva->titulo}";
        $texto = $orden->estado === ReservaOrden::CANCELADA ? $this->textos->resumen($reserva)
            : $this->textos->resumen($reserva).' · '.$this->textos->serviciosDe($reserva, (int) $orden->departamento_id).$this->textos->montajeTexto($reserva);
        $this->centro->avisar((int) $reserva->empresa_id, $usuarios->pluck('id'), 'reserva_orden', [
            'titulo' => $titulo, 'texto' => $texto, 'url' => route('reservas.ordenes'), 'referencia_tipo' => 'reserva_orden', 'referencia_id' => (int) $orden->id,
        ]);
        $this->correo->recepcion((int) $reserva->empresa_id, 'reserva_orden', $usuarios->pluck('email')->filter()->values()->all(),
            new AvisoRecepcion($titulo, [$texto], [['Ver órdenes de servicio', route('reservas.ordenes')]]));
    }

    /** El departamento marca su orden «Listo». */
    public function marcarLista(User $actor, ReservaOrden $orden, ?string $comentario): void
    {
        $reserva = Reserva::with(['area.espacio', 'solicitante'])->findOrFail($orden->reserva_id);
        if ($orden->estado !== ReservaOrden::PENDIENTE || $reserva->estado !== Reserva::CONFIRMADA) {
            throw ValidationException::withMessages(['orden' => 'Esta orden ya no está pendiente.']);
        }
        $orden->forceFill(['estado' => ReservaOrden::LISTA, 'lista_en' => now(), 'lista_por' => $actor->id,
            'comentario' => ($c = trim((string) $comentario)) === '' ? null : mb_substr($c, 0, 500)])->save();
        $this->centro->resolver('reserva_orden', (int) $orden->id);
        $depto = (string) (Departamento::whereKey($orden->departamento_id)->value('nombre') ?? 'Departamento');
        $this->evento($reserva, 'orden_lista', "{$depto}: listo ({$actor->name})", $actor);
        $this->auditar($actor, 'reservas.orden_lista', $reserva, ['departamento' => $depto]);

        $pendientes = ReservaOrden::where('reserva_id', $reserva->id)->where('estado', ReservaOrden::PENDIENTE)->count();
        $titulo = $pendientes === 0 ? 'Montaje listo: '.$reserva->titulo : "{$depto} tiene listo lo suyo: {$reserva->titulo}";
        $texto = $this->textos->resumen($reserva).($pendientes > 0 ? " · Faltan {$pendientes} ".($pendientes === 1 ? 'departamento' : 'departamentos') : ' · Todos los departamentos están listos');
        $ids = collect([$reserva->solicitante_id])->merge($this->responsables->delArea($reserva->area))->filter()->map(fn ($id) => (int) $id)
            ->unique()->reject(fn ($id) => $id === (int) $actor->id)->values();
        if ($ids->isNotEmpty()) {
            $this->centro->avisar((int) $reserva->empresa_id, $ids, 'reserva_orden_lista', [
                'titulo' => $titulo, 'texto' => $texto, 'url' => route('reservas.show', $reserva->id), 'referencia_tipo' => 'reserva', 'referencia_id' => (int) $reserva->id,
            ]);
        }
    }

    // --------------------------------------------------------------- Cancelar

    /** Al cancelar: lo pendiente ya no aplica y los departamentos con orden se enteran. */
    public function alCancelar(User $actor, Reserva $reserva): void
    {
        $pendientes = ReservaAprobacion::where('reserva_id', $reserva->id)->where('vigente', true)->where('estado', ReservaAprobacion::PENDIENTE)->get();
        $avisar = collect();
        foreach ($pendientes as $a) {
            $avisar = $avisar->merge((array) ($a->avisados ?? []));
            $this->retirar($a);
        }
        foreach (ReservaOrden::where('reserva_id', $reserva->id)->where('estado', '!=', ReservaOrden::CANCELADA)->get() as $orden) {
            $orden->forceFill(['estado' => ReservaOrden::CANCELADA])->save();
            $this->centro->resolver('reserva_orden', (int) $orden->id);
            $this->avisarDepartamento($reserva, $orden, 'Se canceló la reserva', $actor);
        }
        $ids = $avisar->map(fn ($id) => (int) $id)->push((int) $reserva->solicitante_id)->filter()->unique()->reject(fn ($id) => $id === (int) $actor->id)->values();
        if ($ids->isNotEmpty()) {
            $this->centro->avisar((int) $reserva->empresa_id, $ids, 'reserva_cancelada', [
                'titulo' => 'Se canceló la reserva: '.$reserva->titulo, 'texto' => $this->textos->resumen($reserva).' · Motivo: '.$reserva->motivo_cancelacion,
                'url' => route('reservas.show', $reserva->id), 'referencia_tipo' => 'reserva', 'referencia_id' => (int) $reserva->id,
            ]);
        }
    }

    // ------------------------------------------------------------------ Apoyo

    private function avisarSolicitante(Reserva $reserva, string $tipo, string $titulo, string $texto, User $actor, ?string $boton = null, ?string $url = null): void
    {
        $solicitante = $reserva->solicitante;
        // Quien hizo el movimiento ya lo ve en su pantalla: no se avisa a sí mismo
        if ($solicitante === null || ! $solicitante->activo || (int) $solicitante->id === (int) $actor->id) {
            return;
        }
        $this->centro->avisar((int) $reserva->empresa_id, [(int) $solicitante->id], $tipo, [
            'titulo' => $titulo, 'texto' => $texto, 'url' => $url ?? route('reservas.show', $reserva->id),
            'referencia_tipo' => 'reserva', 'referencia_id' => (int) $reserva->id,
        ]);
        if ($solicitante->email) {
            $this->correo->recepcion((int) $reserva->empresa_id, 'reserva_resultado', [$solicitante->email],
                new AvisoRecepcion($titulo, [$texto], [[$boton ?? 'Abrir la reserva', $url ?? route('reservas.show', $reserva->id)]]));
        }
    }

    /**
     * @param  array<string, mixed>|null  $detalle
     */
    public function evento(Reserva $reserva, string $evento, string $titulo, ?User $actor, ?array $detalle = null): void
    {
        ReservaEvento::create(['empresa_id' => $reserva->empresa_id, 'reserva_id' => $reserva->id, 'evento' => $evento,
            'titulo' => mb_substr($titulo, 0, 255), 'detalle' => $detalle, 'user_id' => $actor?->id]);
    }

    /**
     * @param  array<string, mixed>  $despues
     */
    public function auditar(?User $actor, string $evento, Reserva $reserva, array $despues, ?array $antes = null): void
    {
        Auditoria::create([
            'empresa_id' => $reserva->empresa_id, 'user_id' => $actor?->id, 'evento' => $evento,
            'auditable_type' => Reserva::class, 'auditable_id' => $reserva->id, 'antes' => $antes ?? ['estado' => $reserva->getOriginal('estado')],
            'despues' => $despues, 'ip' => $actor !== null ? request()?->ip() : null,
        ]);
    }
}
