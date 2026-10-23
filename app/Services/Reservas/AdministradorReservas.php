<?php

namespace App\Services\Reservas;

use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Reserva;
use App\Models\ReservaArea;
use App\Models\ReservaServicio;
use App\Models\ReservaServicioItem;
use App\Models\ReservaTipoUso;
use App\Models\User;
use App\Services\Permisos\Autorizador;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Reservar, modificar, cancelar y ajustar el costo de una reserva, y qué
 * reservas ve cada quien (docs/tecnico/reservas.md).
 *
 * Permisos (motor existente, sin reglas en código):
 *  - reservas.crear: reservar en las sedes de su alcance; modifica y cancela
 *    las suyas antes de que empiecen.
 *  - reservas.ver: consultar el calendario y las reservas de su alcance
 *    (sede, empresa o solo las propias).
 *  - reservas.aprobar: responder cualquier aprobación y ajustar el costo
 *    (descuento, cortesía, importe del área) en su alcance.
 *  - reservas.configurar: áreas reservables, catálogos y ajustes; modificar y
 *    cancelar cualquier reserva de su alcance.
 */
class AdministradorReservas
{
    public function __construct(
        private readonly Autorizador $autorizador,
        private readonly Disponibilidad $disponibilidad,
        private readonly Cotizador $cotizador,
        private readonly AjustesReservas $ajustes,
        private readonly FlujoReservas $flujo,
        private readonly TextosReservas $textos,
    ) {}

    // --------------------------------------------------------------- Alcance

    /**
     * Reservas que el usuario puede consultar con un permiso.
     *
     * @param  Builder<Reserva>  $q
     * @return Builder<Reserva>
     */
    public function limitar(Builder $q, User $actor, string $permiso = 'reservas.ver'): Builder
    {
        if ($actor->es_superadmin) {
            return $q;
        }
        if ($this->autorizador->soloPropios($actor, $permiso)) {
            return $q->where('reservas.solicitante_id', $actor->id);
        }
        $sedes = $this->autorizador->sedesPermitidas($actor, $permiso);

        return $sedes === null ? $q : $q->whereIn('reservas.sede_id', $sedes);
    }

    /**
     * Sedes donde puede usar el permiso (null = todas).
     *
     * @return list<int>|null
     */
    public function sedes(User $actor, string $permiso): ?array
    {
        return $this->autorizador->sedesPermitidas($actor, $permiso);
    }

    public function puedeReservarEn(User $actor, int $sedeId): bool
    {
        if (! $actor->can('reservas.crear')) {
            return false;
        }
        $sedes = $this->sedes($actor, 'reservas.crear');

        return $sedes === null || in_array($sedeId, $sedes, true);
    }

    public function puedeVer(User $actor, Reserva $reserva): bool
    {
        return (int) $reserva->solicitante_id === (int) $actor->id || $actor->can('reservas.ver', $reserva);
    }

    public function puedeModificar(User $actor, Reserva $reserva): bool
    {
        if (! $reserva->activa() || $reserva->yaEmpezo()) {
            return false;
        }

        return ((int) $reserva->solicitante_id === (int) $actor->id && $actor->can('reservas.crear')) || $actor->can('reservas.configurar', $reserva);
    }

    public function puedeCancelar(User $actor, Reserva $reserva): bool
    {
        if (! $reserva->activa() || $reserva->fin->lessThanOrEqualTo(now())) {
            return false;
        }
        if ($actor->can('reservas.configurar', $reserva) || $actor->can('reservas.aprobar', $reserva)) {
            return true;
        }

        return (int) $reserva->solicitante_id === (int) $actor->id && $actor->can('reservas.crear') && ! $reserva->yaEmpezo();
    }

    public function puedeAjustarCosto(User $actor, Reserva $reserva): bool
    {
        return $reserva->para_cliente && $reserva->activa() && $actor->can('reservas.aprobar', $reserva);
    }

    // ------------------------------------------------------- Reservar y modificar

    /**
     * @param  array<string, mixed>  $datos
     */
    public function crear(User $actor, array $datos): Reserva
    {
        $area = $this->area($datos);
        if (! $this->puedeReservarEn($actor, (int) $area->sede_id)) {
            throw ValidationException::withMessages(['reserva_area_id' => 'No puedes reservar en esa sede.']);
        }

        $reserva = DB::transaction(function () use ($actor, $area, $datos) {
            $this->bloquear($area); // dos personas no ganan el mismo horario (ni en la zona y su espacio interior)
            $valores = $this->valores($actor, $area, $datos, null);
            $reserva = Reserva::create($valores['reserva'] + ['solicitante_id' => $actor->id, 'estado' => Reserva::POR_APROBAR]);
            $reserva->forceFill(['folio' => 'RES-'.str_pad((string) $reserva->id, 6, '0', STR_PAD_LEFT)])->save();
            $this->guardarItems($reserva, $valores['items']);

            return $reserva;
        });

        $reserva->load(['area.espacio', 'area.montajes', 'items.servicio', 'solicitante']);
        $this->flujo->evento($reserva, 'creada', 'Reserva creada por '.$actor->name, $actor);
        $this->flujo->auditar($actor, 'reservas.creada', $reserva, $this->foto($reserva), []);
        $this->flujo->sincronizar($actor, $reserva);

        return $reserva->refresh();
    }

    /**
     * Modificar vuelve a pedir la aprobación de lo que cambió.
     *
     * @param  array<string, mixed>  $datos
     */
    public function modificar(User $actor, Reserva $reserva, array $datos): Reserva
    {
        if (! $this->puedeModificar($actor, $reserva)) {
            throw ValidationException::withMessages(['reserva' => 'Esta reserva ya no se puede modificar (empezó, terminó o se canceló).']);
        }
        $area = $this->area($datos);
        if ((int) $area->sede_id !== (int) $reserva->sede_id && ! $this->puedeReservarEn($actor, (int) $area->sede_id)) {
            throw ValidationException::withMessages(['reserva_area_id' => 'No puedes reservar en esa sede.']);
        }
        $antes = $this->foto($reserva->load('items'));

        DB::transaction(function () use ($actor, $area, $datos, $reserva) {
            $this->bloquear($area);
            $valores = $this->valores($actor, $area, $datos, $reserva);
            $reserva->update($valores['reserva']);
            $reserva->items()->delete();
            $this->guardarItems($reserva, $valores['items']);
        });

        $reserva->load(['area.espacio', 'area.montajes', 'items.servicio', 'solicitante']);
        $despues = $this->foto($reserva);
        $cambios = collect($despues)->filter(fn ($v, $k) => ($antes[$k] ?? null) !== $v)->keys()->map(fn ($k) => self::CAMPOS[$k] ?? $k)->values()->all();
        $this->flujo->evento($reserva, 'modificada', 'Modificada por '.$actor->name.($cambios !== [] ? ': '.implode(', ', $cambios) : ' (sin cambios)'), $actor);
        $this->flujo->auditar($actor, 'reservas.modificada', $reserva, $despues, $antes);
        $this->flujo->sincronizar($actor, $reserva);

        return $reserva->refresh();
    }

    /** Nombre de cada dato para el historial. */
    private const CAMPOS = [
        'area' => 'área', 'inicio' => 'inicio', 'fin' => 'fin', 'titulo' => 'título', 'tipo_uso' => 'tipo de uso', 'montaje' => 'montaje',
        'asistentes' => 'personas', 'para_cliente' => 'para quién', 'cliente' => 'cliente', 'tarifa' => 'tarifa', 'servicios' => 'servicios',
        'notas' => 'notas', 'total' => 'total', 'departamento' => 'departamento que solicita', 'excepcion_aforo' => 'excepción de aforo',
        'dia_completo' => 'día completo', 'montaje_minutos' => 'tiempo de montaje', 'desmontaje_minutos' => 'tiempo de desmontaje', 'indicaciones' => 'indicaciones de montaje',
    ];

    /**
     * @return array<string, mixed>
     */
    private function foto(Reserva $r): array
    {
        return [
            'area' => (int) $r->reserva_area_id, 'inicio' => $r->inicio?->toIso8601String(), 'fin' => $r->fin?->toIso8601String(),
            'titulo' => $r->titulo, 'tipo_uso' => $r->tipo_uso_id, 'montaje' => $r->montaje_id, 'asistentes' => (int) $r->asistentes,
            'para_cliente' => (bool) $r->para_cliente, 'cliente' => trim($r->cliente_nombre.' '.$r->cliente_empresa) ?: null, 'tarifa' => $r->tarifa,
            // En orden fijo: el formulario puede mandar los mismos servicios en otro orden
            'servicios' => $r->items->sortBy(fn ($i) => mb_strtolower($i->nombre).'|'.$i->departamento_id)->map(fn ($i) => $i->nombre.' × '.$i->cantidadTexto().($i->notas ? ' ('.$i->notas.')' : ''))->join(', '), 'notas' => $r->notas,
            'total' => number_format((float) $r->total, 2, '.', ''),
            'departamento' => $r->departamento_solicitante_id, 'excepcion_aforo' => $r->excede_aforo ? $r->motivo_excepcion_aforo : null,
            'dia_completo' => (bool) $r->dia_completo, 'montaje_minutos' => $r->montaje_minutos, 'desmontaje_minutos' => $r->desmontaje_minutos,
            'indicaciones' => $r->indicaciones_montaje,
        ];
    }

    /**
     * @param  array<string, mixed>  $datos
     */
    private function area(array $datos): ReservaArea
    {
        $area = ReservaArea::with(['espacio', 'montajes', 'sede'])->where('reservable', true)->find((int) ($datos['reserva_area_id'] ?? 0));
        if ($area === null) {
            throw ValidationException::withMessages(['reserva_area_id' => 'Elige un área que se pueda reservar.']);
        }

        return $area;
    }

    /**
     * Valida todo y arma los valores de la reserva y sus renglones.
     *
     * @param  array<string, mixed>  $datos
     * @return array{reserva: array<string, mixed>, items: list<array<string, mixed>>}
     */
    private function valores(User $actor, ReservaArea $area, array $datos, ?Reserva $actual): array
    {
        $titulo = trim((string) ($datos['titulo'] ?? ''));
        if ($titulo === '') {
            throw ValidationException::withMessages(['titulo' => 'Escribe el nombre del evento o el motivo de la reserva.']);
        }
        $asistentes = (int) ($datos['asistentes'] ?? 0);
        if ($asistentes < 1 || $asistentes > 100000) {
            throw ValidationException::withMessages(['asistentes' => 'Indica cuántas personas asistirán.']);
        }
        $montajeId = ($datos['montaje_id'] ?? '') === '' || ! isset($datos['montaje_id']) ? null : (int) $datos['montaje_id'];
        $tipoUso = ($datos['tipo_uso_id'] ?? '') === '' || ! isset($datos['tipo_uso_id']) ? null : (int) $datos['tipo_uso_id'];
        if ($tipoUso !== null && ! ReservaTipoUso::whereKey($tipoUso)->exists()) {
            throw ValidationException::withMessages(['tipo_uso_id' => 'Elige un tipo de uso del catálogo.']);
        }
        $departamento = $this->departamentoSolicitante($actor, $datos, $actual);

        // Horario: por horas o día completo (el horario del área ese día)
        if (! preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) ($datos['fecha'] ?? ''))) {
            throw ValidationException::withMessages(['fecha' => 'Indica la fecha y las horas de inicio y fin.']);
        }
        $diaCompleto = filter_var($datos['dia_completo'] ?? false, FILTER_VALIDATE_BOOLEAN);
        if ($diaCompleto) {
            $ventana = $this->disponibilidad->diaCompleto($area, (string) $datos['fecha']);
            if ($ventana === null) {
                throw ValidationException::withMessages(['fecha' => 'Ese día el área no se reserva: elige otro día.']);
            }
            [$datos['hora_inicio'], $datos['hora_fin']] = $ventana;
        }
        if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) ($datos['hora_inicio'] ?? '')) || ! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) ($datos['hora_fin'] ?? ''))) {
            throw ValidationException::withMessages(['fecha' => 'Indica la fecha y las horas de inicio y fin.']);
        }
        [$inicio, $fin] = $this->disponibilidad->aUtc($area, (string) $datos['fecha'], (string) $datos['hora_inicio'], (string) $datos['hora_fin']);

        // Montaje antes y desmontaje después: el del área salvo que se ajuste para esta reserva
        $montajeMin = $this->minutos($datos, 'montaje_minutos', (int) $area->montaje_minutos, $actual?->montaje_minutos);
        $desmontajeMin = $this->minutos($datos, 'desmontaje_minutos', (int) $area->desmontaje_minutos, $actual?->desmontaje_minutos);
        $indicaciones = array_key_exists('indicaciones_montaje', $datos)
            ? (($t = trim((string) $datos['indicaciones_montaje'])) === '' ? null : mb_substr($t, 0, 255))
            : $actual?->indicaciones_montaje;

        // Aforo: pasarlo es una excepción con motivo (lo ve quien aprueba)
        [$aforo, $excede, $deQue] = $this->disponibilidad->aforo($area, $montajeId, $asistentes);
        $motivoAforo = mb_substr(trim((string) ($datos['motivo_excepcion_aforo'] ?? '')), 0, 255);
        if ($excede && mb_strlen($motivoAforo) < 3) {
            throw ValidationException::withMessages(['motivo_excepcion_aforo' => "Son {$asistentes} personas y el aforo {$deQue} es de {$aforo}: escribe el motivo de la excepción (lo verá quien aprueba)."]);
        }
        [$desde, $hasta] = $this->disponibilidad->validar($area, $inicio, $fin, $montajeId, $asistentes, $actual?->id, true, $excede, $montajeMin, $desmontajeMin);

        $paraCliente = filter_var($datos['para_cliente'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $cliente = ['cliente_nombre' => null, 'cliente_empresa' => null, 'cliente_correo' => null, 'cliente_telefono' => null];
        if ($paraCliente) {
            $cliente = [
                'cliente_nombre' => mb_substr(trim((string) ($datos['cliente_nombre'] ?? '')), 0, 150) ?: null,
                'cliente_empresa' => mb_substr(trim((string) ($datos['cliente_empresa'] ?? '')), 0, 150) ?: null,
                'cliente_correo' => mb_substr(trim((string) ($datos['cliente_correo'] ?? '')), 0, 150) ?: null,
                'cliente_telefono' => mb_substr(trim((string) ($datos['cliente_telefono'] ?? '')), 0, 30) ?: null,
            ];
            if ($cliente['cliente_nombre'] === null) {
                throw ValidationException::withMessages(['cliente_nombre' => 'Escribe el nombre del cliente (persona de contacto).']);
            }
            if ($cliente['cliente_correo'] !== null && filter_var($cliente['cliente_correo'], FILTER_VALIDATE_EMAIL) === false) {
                throw ValidationException::withMessages(['cliente_correo' => 'El correo del cliente no es válido.']);
            }
        }

        $items = $this->items($datos, $paraCliente, $asistentes, Cotizador::horasCobradas($inicio, $fin), $actual);

        $ajustes = $this->ajustes->de((int) $area->empresa_id);
        $tarifa = isset($datos['tarifa']) && array_key_exists($datos['tarifa'], ReservaArea::TARIFAS) ? (string) $datos['tarifa'] : null;
        // Día completo con tarifa de día: se propone esa tarifa si no se eligió otra
        if ($tarifa === null && $diaCompleto && isset($area->tarifas()['dia'])) {
            $tarifa = 'dia';
        }
        // Los ajustes de costo (descuento, cortesía, importe del área) los conserva una modificación
        $sigueCliente = $paraCliente && $actual?->para_cliente;
        $costo = $this->cotizador->calcular($area, $paraCliente, $tarifa, $inicio, $fin, $items, $ajustes,
            $sigueCliente && $actual->importe_area_manual !== null ? (float) $actual->importe_area_manual : null,
            $sigueCliente ? (float) $actual->descuento : 0.0, $sigueCliente && $actual->cortesia);
        foreach ($items as $n => $item) {
            $items[$n]['importe'] = $costo['items'][$n];
        }

        return ['reserva' => [
            'sede_id' => $area->sede_id, 'reserva_area_id' => $area->id, 'espacio_id' => $area->espacio_id,
            'titulo' => mb_substr($titulo, 0, 150), 'tipo_uso_id' => $tipoUso, 'montaje_id' => $montajeId, 'asistentes' => $asistentes,
            'excede_aforo' => $excede, 'motivo_excepcion_aforo' => $excede ? $motivoAforo : null,
            'departamento_solicitante_id' => $departamento,
            'inicio' => $inicio, 'fin' => $fin, 'dia_completo' => $diaCompleto, 'bloqueo_desde' => $desde, 'bloqueo_hasta' => $hasta,
            'montaje_minutos' => $montajeMin, 'desmontaje_minutos' => $desmontajeMin, 'indicaciones_montaje' => $indicaciones,
            'para_cliente' => $paraCliente, 'tarifa' => $costo['tarifa'],
            'importe_area' => $costo['importe_area'], 'importe_servicios' => $costo['importe_servicios'], 'descuento' => $costo['descuento'],
            'iva_porcentaje' => $costo['iva_porcentaje'], 'iva' => $costo['iva'], 'total' => $costo['total'], 'moneda' => $ajustes['moneda'],
            'notas' => ($n = trim((string) ($datos['notas'] ?? ''))) === '' ? null : mb_substr($n, 0, 2000),
        ] + $cliente + ($paraCliente ? [] : ['importe_area_manual' => null, 'cortesia' => false, 'ajuste_motivo' => null]), 'items' => $items];
    }

    /**
     * Departamento que solicita: el que se eligió (de la empresa), o al crear
     * sin indicarlo, el del colaborador del usuario; al modificar sin indicarlo,
     * el que ya tenía.
     *
     * @param  array<string, mixed>  $datos
     */
    private function departamentoSolicitante(User $actor, array $datos, ?Reserva $actual): ?int
    {
        if (! array_key_exists('departamento_solicitante_id', $datos)) {
            return $actual !== null ? $actual->departamento_solicitante_id : $this->departamentoDe($actor);
        }
        $id = $datos['departamento_solicitante_id'];
        if ($id === null || $id === '') {
            return null;
        }
        // Scope de la empresa: un departamento de otra empresa no existe aquí
        if (! is_numeric($id) || ! Departamento::whereKey((int) $id)->exists()) {
            throw ValidationException::withMessages(['departamento_solicitante_id' => 'Elige un departamento de tu empresa.']);
        }

        return (int) $id;
    }

    /** Departamento del colaborador vinculado al usuario (si lo tiene y es de la empresa). */
    public function departamentoDe(User $actor): ?int
    {
        // La sesión puede traer al usuario con pocas columnas: se consulta su colaborador
        $colaborador = User::whereKey($actor->id)->value('colaborador_id');
        $id = $colaborador ? Colaborador::withoutGlobalScopes()->whereKey($colaborador)->value('departamento_id') : null;

        return $id !== null && Departamento::whereKey((int) $id)->exists() ? (int) $id : null;
    }

    /**
     * Minutos de montaje o desmontaje de la reserva: null si son los del área.
     *
     * @param  array<string, mixed>  $datos
     */
    private function minutos(array $datos, string $campo, int $delArea, ?int $actual): ?int
    {
        if (! array_key_exists($campo, $datos)) {
            return $actual;
        }
        $valor = $datos[$campo];
        if ($valor === null || $valor === '') {
            return null;
        }
        if (! is_numeric($valor) || (int) $valor < 0 || (int) $valor > 1440) {
            throw ValidationException::withMessages([$campo => 'Los minutos van de 0 a 1440 (24 horas).']);
        }

        return (int) $valor === $delArea ? null : (int) $valor;
    }

    /**
     * Renglones de servicios: los del catálogo marcados (casilla «usar» o con
     * cantidad) y los «otros servicios» libres por departamento (sin
     * servicio_id). Sin cantidad se sugiere: por persona = las personas; por
     * hora = las horas; por pieza o evento = 1.
     *
     * Un servicio dado de baja en el catálogo ya no se ofrece para reservas
     * nuevas, pero lo que una reserva YA pidió se conserva al modificarla
     * (mismo nombre, departamento y costo con que se pidió): guardar sin
     * cambios no quita nada ni vuelve a pedir aprobaciones.
     *
     * @param  array<string, mixed>  $datos
     * @return list<array<string, mixed>>
     */
    private function items(array $datos, bool $paraCliente, int $asistentes, int $horas, ?Reserva $actual = null): array
    {
        $pedidos = collect((array) ($datos['servicios'] ?? []))
            ->filter(fn ($f) => is_array($f) && (filter_var($f['usar'] ?? false, FILTER_VALIDATE_BOOLEAN) || (float) ($f['cantidad'] ?? 0) > 0));
        $catalogo = ReservaServicio::where('activo', true)->whereIn('id', $pedidos->keys()->map(fn ($k) => (int) $k))->get()->keyBy('id');
        $yaPedidos = $actual === null ? collect() : $actual->items()->whereNotNull('servicio_id')->get()->keyBy('servicio_id');
        $items = [];
        foreach ($pedidos as $id => $fila) {
            $servicio = $catalogo[(int) $id] ?? null;
            if ($servicio === null) {
                $previo = $yaPedidos[(int) $id] ?? null;
                if ($previo === null) {
                    continue;
                }
                // Dado de baja después de pedirlo: se conserva tal como se pidió
                $cantidad = (float) ($fila['cantidad'] ?? 0) > 0 ? min(100000, round((float) $fila['cantidad'], 2)) : (float) $previo->cantidad;
                $items[] = [
                    'servicio_id' => $previo->servicio_id, 'departamento_id' => $previo->departamento_id, 'nombre' => $previo->nombre, 'unidad' => $previo->unidad,
                    'cantidad' => $cantidad, 'costo_unitario' => $paraCliente ? (float) $previo->costo_unitario : 0.0,
                    'notas' => mb_substr(trim((string) ($fila['notas'] ?? '')), 0, 255) ?: null,
                ];

                continue;
            }
            $cantidad = (float) ($fila['cantidad'] ?? 0) > 0 ? min(100000, round((float) $fila['cantidad'], 2))
                : (float) match ($servicio->unidad) {
                    'persona' => $asistentes, 'hora' => $horas, default => 1
                };
            $items[] = [
                'servicio_id' => $servicio->id, 'departamento_id' => $servicio->departamento_id, 'nombre' => $servicio->nombre, 'unidad' => $servicio->unidad,
                'cantidad' => $cantidad, 'costo_unitario' => $paraCliente ? (float) ($servicio->costo ?? 0) : 0.0,
                'notas' => mb_substr(trim((string) ($fila['notas'] ?? '')), 0, 255) ?: null,
            ];
        }

        // «+ Otro servicio» de cada departamento: nombre y nota libres
        $otros = collect((array) ($datos['otros'] ?? []))->filter(fn ($f) => is_array($f) && trim((string) ($f['nombre'] ?? '')) !== '')->take(30);
        $deptos = $otros->pluck('departamento_id')->filter(fn ($d) => is_numeric($d))->map(fn ($d) => (int) $d)->unique()->values();
        $validos = $deptos->isEmpty() ? collect() : Departamento::whereIn('id', $deptos)->pluck('id')->map(fn ($d) => (int) $d);
        foreach ($otros as $fila) {
            $depto = is_numeric($fila['departamento_id'] ?? null) ? (int) $fila['departamento_id'] : null;
            if ($depto !== null && ! $validos->contains($depto)) {
                throw ValidationException::withMessages(['otros' => 'Elige un departamento de tu empresa para cada servicio adicional.']);
            }
            $items[] = [
                'servicio_id' => null, 'departamento_id' => $depto, 'nombre' => mb_substr(trim((string) $fila['nombre']), 0, 80), 'unidad' => 'pieza',
                'cantidad' => (float) ($fila['cantidad'] ?? 0) > 0 ? min(100000, round((float) $fila['cantidad'], 2)) : 1.0, 'costo_unitario' => 0.0,
                'notas' => mb_substr(trim((string) ($fila['notas'] ?? '')), 0, 255) ?: null,
            ];
        }

        return $items;
    }

    /**
     * @param  list<array<string, mixed>>  $items
     */
    private function guardarItems(Reserva $reserva, array $items): void
    {
        foreach ($items as $item) {
            ReservaServicioItem::create($item + ['reserva_id' => $reserva->id]);
        }
    }

    // ------------------------------------------------------------- Cancelar

    public function cancelar(User $actor, Reserva $reserva, string $motivo): void
    {
        if (! $this->puedeCancelar($actor, $reserva)) {
            throw ValidationException::withMessages(['motivo' => 'Esta reserva ya no se puede cancelar.']);
        }
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < 3) {
            throw ValidationException::withMessages(['motivo' => 'Escribe por qué se cancela.']);
        }
        $reserva->forceFill(['estado' => Reserva::CANCELADA, 'cancelada_en' => now(), 'cancelada_por' => $actor->id,
            'motivo_cancelacion' => mb_substr($motivo, 0, 255)])->save();
        $this->flujo->evento($reserva, 'cancelada', "Cancelada por {$actor->name}: {$reserva->motivo_cancelacion}", $actor);
        $this->flujo->auditar($actor, 'reservas.cancelada', $reserva, ['estado' => Reserva::CANCELADA, 'motivo' => $reserva->motivo_cancelacion]);
        $this->flujo->alCancelar($actor, $reserva->load(['area.espacio', 'solicitante']));
    }

    // -------------------------------------------------------- Ajustar costo

    /**
     * Descuento o cortesía (con motivo) e importe del área definido por evento.
     * No vuelve a pedir aprobación: lo hace quien tiene reservas.aprobar.
     *
     * @param  array<string, mixed>  $datos
     */
    public function ajustarCosto(User $actor, Reserva $reserva, array $datos): void
    {
        if (! $this->puedeAjustarCosto($actor, $reserva)) {
            abort(403);
        }
        $cortesia = filter_var($datos['cortesia'] ?? false, FILTER_VALIDATE_BOOLEAN);
        $descuento = ($datos['descuento'] ?? '') === '' ? 0.0 : max(0.0, round((float) $datos['descuento'], 2));
        $manual = ($datos['importe_area_manual'] ?? '') === '' || ! isset($datos['importe_area_manual']) ? null : max(0.0, round((float) $datos['importe_area_manual'], 2));
        $motivo = trim((string) ($datos['ajuste_motivo'] ?? ''));
        if (($cortesia || $descuento > 0 || $manual !== null) && mb_strlen($motivo) < 3) {
            throw ValidationException::withMessages(['ajuste_motivo' => 'Escribe el motivo del ajuste (por ejemplo, «Cliente frecuente»).']);
        }
        $reserva->load(['area', 'items']);
        $antes = $reserva->only(['importe_area', 'importe_area_manual', 'descuento', 'cortesia', 'total']);
        $items = $reserva->items->map(fn ($i) => ['cantidad' => (float) $i->cantidad, 'costo_unitario' => (float) $i->costo_unitario])->all();
        $costo = $this->cotizador->calcular($reserva->area, true, $reserva->tarifa, CarbonImmutable::instance($reserva->inicio), CarbonImmutable::instance($reserva->fin),
            $items, $this->ajustes->de((int) $reserva->empresa_id), $manual, $descuento, $cortesia);
        $reserva->forceFill([
            'importe_area' => $costo['importe_area'], 'importe_area_manual' => $manual, 'descuento' => $costo['descuento'], 'cortesia' => $cortesia,
            'ajuste_motivo' => $motivo === '' ? null : mb_substr($motivo, 0, 255), 'iva_porcentaje' => $costo['iva_porcentaje'], 'iva' => $costo['iva'], 'total' => $costo['total'],
        ])->save();
        $texto = $cortesia ? 'Cortesía' : ($descuento > 0 ? 'Descuento de '.Cotizador::dinero($costo['descuento'], $reserva->moneda) : 'Costo ajustado');
        $this->flujo->evento($reserva, 'costo', "{$texto} por {$actor->name}".($motivo !== '' ? ": {$motivo}" : '').' · Total: '.Cotizador::dinero($costo['total'], $reserva->moneda), $actor);
        $this->flujo->auditar($actor, 'reservas.costo_ajustado', $reserva, $reserva->only(['importe_area', 'importe_area_manual', 'descuento', 'cortesia', 'ajuste_motivo', 'total']), $antes);
    }

    // ------------------------------------------------------------- Apoyo

    /**
     * Bloquea (en orden de id, para no trabarse) las áreas que comparten lugar
     * con ésta: la zona completa y sus espacios interiores.
     */
    private function bloquear(ReservaArea $area): void
    {
        ReservaArea::whereIn('espacio_id', $this->disponibilidad->espaciosRelacionados($area))->orderBy('id')->lockForUpdate()->get(['id']);
    }

    public function textos(): TextosReservas
    {
        return $this->textos;
    }
}
