<?php

namespace App\Services\Transporte;

use App\Models\Colaborador;
use App\Models\MovimientoTransporte;
use App\Models\Paradero;
use App\Models\Persona;
use App\Models\Ruta;
use App\Models\RutaHorario;
use App\Models\Sede;
use App\Models\User;
use App\Models\Vehiculo;
use App\Services\Firmas\Firmas;
use App\Services\Padrones\AltasPorVerificar;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use App\Services\Personas\AdministradorPersonas;
use App\Services\Rutas\AdministradorRutas;
use App\Services\Vehiculos\AdministradorVehiculos;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Reglas de la Bitácora de transporte (SEGCAT: bitacora_proceso.php).
 *
 * - Todo es POR SEDE: la ruta (y su horario) debe ser de la sede y del
 *   sentido elegidos; el alcance de sede del usuario decide qué ve y qué toca
 *   (con "propios", solo lo que él registró).
 * - Servicio normal (A TIEMPO / RETRASO): un registro con la unidad, el chofer
 *   y el número de pasajeros.
 * - NO LLEGO (USO DE TAXIS): un registro por taxi, cada uno con placas, chofer,
 *   monto > 0, destino (paradero de la sede), al menos un colaborador y
 *   justificación si el monto supera el tope de la ruta.
 * - Unidades, choferes y destinos se registran solos en sus padrones (vehículos,
 *   personas, paraderos) si todavía no existen; el servidor siempre busca
 *   primero (nunca decide el navegador si es nuevo).
 *
 * Todas las consultas corren con la empresa de trabajo fijada en el Tenant.
 */
class BitacoraTransporte
{
    public const MAX_TAXIS = 10;

    public const MAX_PASAJEROS = 30;

    private const PLACAS = '/^[A-Z0-9Ñ]{2,20}$/u';

    private const TELEFONO = '/^\d{10,15}$/';

    public function __construct(
        private readonly Autorizador $autorizador,
        private readonly AdministradorRoles $auditoria,
        private readonly AdministradorRutas $rutas,
        private readonly AdministradorVehiculos $vehiculos,
        private readonly AdministradorPersonas $personas,
        private readonly Firmas $firmas,
    ) {}

    // ------------------------------------------------------------------ Alcance

    /**
     * Sedes en las que el usuario usa el permiso: null = todas; [] = ninguna.
     *
     * @return list<int>|null
     */
    public function sedes(User $actor, string $permiso): ?array
    {
        return $actor->can($permiso) ? $this->autorizador->sedesPermitidas($actor, $permiso) : [];
    }

    public function puedeEnSede(User $actor, string $permiso, int $sedeId): bool
    {
        $sedes = $this->sedes($actor, $permiso);

        return $sedes === null || in_array($sedeId, $sedes, true);
    }

    /**
     * ¿Puede tocar este movimiento? Revisa su sede y, con alcance "propios", que lo haya registrado él.
     */
    public function puede(User $actor, string $permiso, MovimientoTransporte $movimiento): bool
    {
        return $this->autorizador->puede($actor, $permiso, $movimiento);
    }

    /**
     * Limita una consulta a los movimientos que el actor puede ver o tocar con un permiso.
     *
     * @param  Builder<MovimientoTransporte>  $consulta
     * @return Builder<MovimientoTransporte>
     */
    public function limitar(Builder $consulta, User $actor, string $permiso): Builder
    {
        if ($actor->es_superadmin) {
            return $consulta;
        }

        $efectivo = $this->autorizador->permisosEfectivos($actor)[$permiso] ?? null;
        if ($efectivo === null) {
            return $consulta->whereRaw('1 = 0');
        }

        $sedes = $this->autorizador->sedesPermitidas($actor, $permiso);

        return $consulta
            ->when($sedes !== null, fn ($q) => $q->whereIn('movimientos_transporte.sede_id', $sedes))
            ->when($efectivo->alcance === Alcance::Propios, fn ($q) => $q->where('movimientos_transporte.creado_por', $actor->id));
    }

    /**
     * Hoy en la sede (su zona o la de la empresa).
     */
    public function ahoraEn(Sede $sede): CarbonImmutable
    {
        return CarbonImmutable::now($sede->zonaHoraria());
    }

    // ------------------------------------------------------- Opciones del alta

    /**
     * Horarios de las rutas ACTIVAS de esas sedes, con su ruta (para la lista
     * "Ruta" del diálogo), ordenados por la hora que importa en caseta: la de
     * llegada a la sede en las llegadas y la de salida en las salidas.
     *
     * @param  list<int>  $sedes
     * @return Collection<int, RutaHorario>
     */
    public function horariosPara(array $sedes): Collection
    {
        return RutaHorario::query()
            ->whereHas('ruta', fn ($q) => $q->whereIn('sede_id', $sedes)->where('activo', true))
            ->with(['ruta:id,sede_id,sentido,nombre,proveedor_id,costo_maximo_taxi', 'ruta.proveedor:id,nombre'])
            ->get()
            ->sortBy(fn (RutaHorario $h) => $this->horaDeCaseta($h).' '.$h->ruta->nombre)
            ->values();
    }

    /** "06:40": la llegada a la sede (llegadas) o la salida de la sede (salidas). */
    public function horaDeCaseta(RutaHorario $h): string
    {
        return $h->ruta->sentido === 'llegada' ? $h->fin() : $h->inicio();
    }

    /**
     * El horario más cercano a "ahora" en la sede, por sentido, entre los que
     * operan hoy: así el guardia casi nunca tiene que buscar la ruta.
     *
     * @param  Collection<int, RutaHorario>  $horarios  de una sola sede
     * @return array{llegada: ?int, salida: ?int, sentido: string}
     */
    public function sugerencia(Collection $horarios, CarbonImmutable $ahora): array
    {
        $minutos = $ahora->hour * 60 + $ahora->minute;
        $mejor = ['llegada' => [null, PHP_INT_MAX], 'salida' => [null, PHP_INT_MAX]];

        foreach ($horarios as $h) {
            if (! $h->aplicaEn($ahora->dayOfWeekIso)) {
                continue;
            }
            [$hh, $mm] = array_map('intval', explode(':', $this->horaDeCaseta($h)));
            $diferencia = abs($hh * 60 + $mm - $minutos);
            $diferencia = min($diferencia, 1440 - $diferencia);
            $sentido = $h->ruta->sentido;
            if ($diferencia < $mejor[$sentido][1]) {
                $mejor[$sentido] = [$h->id, $diferencia];
            }
        }

        return [
            'llegada' => $mejor['llegada'][0],
            'salida' => $mejor['salida'][0],
            'sentido' => $mejor['salida'][1] < $mejor['llegada'][1] ? 'salida' : 'llegada',
        ];
    }

    // -------------------------------------------------------------------- Alta

    /**
     * Registra un movimiento normal o los vales de taxi (uno por taxi).
     *
     * @param  array<string, mixed>  $entrada
     * @param  bool  $conFirmas  el usuario captura firmas en pantalla (transporte.firmar)
     * @param  list<int>|null  $sedesPermitidas  sedes donde puede registrar (null = todas)
     * @return Collection<int, MovimientoTransporte>
     */
    public function registrar(User $actor, array $entrada, bool $conFirmas, ?array $sedesPermitidas): Collection
    {
        $datos = $this->validarAlta($actor, $entrada, $conFirmas, $sedesPermitidas);

        // Las firmas se guardan solo cuando todo lo demás ya es válido
        $guardadas = [];
        try {
            $firmaGuardia = null;
            if ($conFirmas && $this->firmas->viene($datos['firma_guardia'])) {
                $guardadas[] = $firmaGuardia = $this->firmas->guardar($datos['firma_guardia'], 'transporte', 'firma_guardia', 'la firma del guardia');
            }
            foreach ($datos['taxis'] as $i => $taxi) {
                $datos['taxis'][$i]['firma_ruta'] = null;
                if ($conFirmas) {
                    $guardadas[] = $datos['taxis'][$i]['firma_ruta'] = $this->firmas->guardar($taxi['firma'], 'transporte', "taxis.{$i}.firma", 'la firma del taxista del Taxi '.($i + 1));
                }
            }

            $movimientos = DB::transaction(fn () => $datos['estatus'] === 'no_llego'
                ? $this->guardarTaxis($actor, $datos, $firmaGuardia)
                : collect([$this->guardarNormal($actor, $datos, $firmaGuardia)]));
        } catch (Throwable $e) {
            foreach ($guardadas as $ruta) {
                $this->firmas->borrar($ruta);
            }
            throw $e;
        }

        foreach ($movimientos as $m) {
            $this->auditoria->auditar($actor, 'transporte.creado', $m, null, $this->foto($m));
        }

        return $movimientos;
    }

    /**
     * @param  array<string, mixed>  $d
     */
    private function guardarNormal(User $actor, array $d, ?string $firmaGuardia): MovimientoTransporte
    {
        $ruta = $d['horario']->ruta;
        $vehiculo = $d['placas'] !== null
            ? $this->vehiculo($actor, $d['placas'], MovimientoTransporte::TIPOS_UNIDAD[$d['tipo_unidad']], 'transporte_personal', $ruta->proveedor_id, $d, (int) $d['sede']->id)
            : null;
        $chofer = $d['chofer'] !== null ? $this->chofer($actor, $d['chofer'], $ruta->proveedor_id, $d['chofer_telefono'], (int) $d['sede']->id) : null;

        $m = new MovimientoTransporte([
            'sede_id' => $d['sede']->id, 'ruta_id' => $ruta->id, 'ruta_horario_id' => $d['horario']->id,
            'tipo_movimiento' => $d['tipo_movimiento'], 'estatus' => $d['estatus'], 'fecha' => $d['fecha'],
            'vehiculo_id' => $vehiculo?->id, 'chofer_id' => $chofer?->id, 'cantidad_pax' => $d['cantidad_pax'],
            'firma_guardia' => $firmaGuardia, 'observaciones' => $d['observaciones'],
        ]);
        $m->forceFill(['creado_por' => $actor->id, 'actualizado_por' => $actor->id])->save();

        return $m;
    }

    /**
     * Un registro por taxi, como en SEGCAT. Los taxis de una misma captura comparten "lote".
     *
     * @param  array<string, mixed>  $d
     * @return Collection<int, MovimientoTransporte>
     */
    private function guardarTaxis(User $actor, array $d, ?string $firmaGuardia): Collection
    {
        $ruta = $d['horario']->ruta;
        $lote = (string) Str::uuid();
        $creados = collect();

        foreach ($d['taxis'] as $t) {
            // Taxis de ocasión: unidades externas sin empresa propietaria (como SEGCAT)
            $vehiculo = $this->vehiculo($actor, $t['placas'], MovimientoTransporte::TIPOS_TAXI[$t['tipo']], 'taxi_app', null, $t, (int) $d['sede']->id);
            $chofer = $this->chofer($actor, $t['chofer'], null, $t['chofer_telefono'], (int) $d['sede']->id);
            $paradero = $this->rutas->paraderoParaRuta($actor, $d['sede'], $t['destino']);

            $m = new MovimientoTransporte([
                'sede_id' => $d['sede']->id, 'ruta_id' => $ruta->id, 'ruta_horario_id' => $d['horario']->id,
                'tipo_movimiento' => $d['tipo_movimiento'], 'estatus' => 'no_llego', 'fecha' => $d['fecha'],
                'vehiculo_id' => $vehiculo->id, 'chofer_id' => $chofer->id, 'cantidad_pax' => count($t['pasajeros']),
                'monto' => $t['monto'], 'justificacion' => $t['justificacion'], 'paradero_id' => $paradero->id,
                'firma_guardia' => $firmaGuardia, 'firma_taxista' => $t['firma_ruta'], 'observaciones' => $d['observaciones'], 'lote' => $lote,
            ]);
            $m->forceFill(['creado_por' => $actor->id, 'actualizado_por' => $actor->id])->save();
            $m->pasajeros()->sync($t['pasajeros']);
            $creados->push($m);
        }

        return $creados;
    }

    /**
     * Unidad del padrón vehicular por placas: si existe se usa (y se completan
     * marca, modelo, número económico o capacidad que le falten); si no, se
     * registra con la categoría indicada.
     *
     * @param  array{0: string, 1: string, 2: ?string}  $tipo
     * @param  array<string, mixed>  $d
     */
    private function vehiculo(User $actor, string $placas, array $tipo, string $propiedad, ?int $proveedorId, array $d, ?int $sedeId = null): Vehiculo
    {
        $existente = $this->vehiculos->conPlacas($placas);
        if ($existente !== null) {
            // Altas por verificar: unido a otro = el correcto; rechazado = no se usa
            $existente = app(AltasPorVerificar::class)->paraOperacion('vehiculos', $existente, 'placas');
            $antes = $this->vehiculos->foto($existente);
            foreach (['marca' => 'marca', 'modelo' => 'modelo', 'numero_economico' => 'economico', 'capacidad' => 'capacidad'] as $columna => $campo) {
                if (($existente->{$columna} === null || $existente->{$columna} === '') && ($d[$campo] ?? null) !== null) {
                    $existente->{$columna} = $d[$campo];
                }
            }
            if ($existente->isDirty()) {
                $existente->save();
                $this->auditoria->auditar($actor, 'vehiculos.actualizado', $existente, $antes, $this->vehiculos->foto($existente));
            }

            return $existente;
        }

        $vehiculo = Vehiculo::create([
            'placas' => $placas, 'tipo' => $tipo[1], 'descripcion_otro' => $tipo[2], 'propiedad' => $propiedad,
            'marca' => $d['marca'] ?? null, 'modelo' => $d['modelo'] ?? null, 'numero_economico' => $d['economico'] ?? null,
            'capacidad' => $d['capacidad'] ?? null, 'proveedor_id' => $proveedorId,
        ]);
        $this->auditoria->auditar($actor, 'vehiculos.creado', $vehiculo, null, $this->vehiculos->foto($vehiculo));
        app(AltasPorVerificar::class)->registrarAlta($actor, 'vehiculos', $vehiculo, 'transporte', $sedeId);

        return $vehiculo;
    }

    /**
     * Chofer en el Padrón de personas (tipo Proveedor), por nombre y empresa
     * transportista (sin empresa para los taxistas). Si ya existía sin
     * teléfono, se le completa.
     */
    private function chofer(User $actor, string $nombre, ?int $proveedorId, ?string $telefono, ?int $sedeId = null): Persona
    {
        $existente = Persona::where('tipo', 'proveedor')
            ->whereRaw('UPPER(nombre_completo) = ?', [$nombre])
            ->when($proveedorId !== null, fn ($q) => $q->where('proveedor_id', $proveedorId), fn ($q) => $q->whereNull('proveedor_id'))
            ->orderByDesc('activo')->first();

        if ($existente !== null) {
            // Altas por verificar: unida a otra = la correcta; rechazada = no se usa
            $existente = app(AltasPorVerificar::class)->paraOperacion('personas', $existente, 'chofer');
            if ($telefono !== null && ($existente->telefono === null || $existente->telefono === '')) {
                $antes = $this->personas->foto($existente);
                $existente->forceFill(['telefono' => $telefono])->save();
                $this->auditoria->auditar($actor, 'visitantes.actualizado', $existente, $antes, $this->personas->foto($existente));
            }

            return $existente;
        }

        $persona = Persona::create([
            'tipo' => 'proveedor', 'categoria' => 'general', 'nombre_completo' => $nombre, 'proveedor_id' => $proveedorId,
            'empresa_procedencia' => $proveedorId === null ? 'TAXI' : null, 'telefono' => $telefono,
            'motivo_visita' => $proveedorId === null ? 'Taxista (Bitácora de transporte).' : 'Chofer de transporte de personal (Bitácora de transporte).',
        ]);
        $this->auditoria->auditar($actor, 'visitantes.creado', $persona, null, $this->personas->foto($persona));
        app(AltasPorVerificar::class)->registrarAlta($actor, 'personas', $persona, 'transporte', $sedeId);

        return $persona;
    }

    // ------------------------------------------------------- Validación (alta)

    /**
     * @param  array<string, mixed>  $e
     * @param  list<int>|null  $sedesPermitidas
     * @return array<string, mixed>
     */
    private function validarAlta(User $actor, array $e, bool $conFirmas, ?array $sedesPermitidas): array
    {
        $errores = [];
        $texto = fn (mixed $v, int $max = 150) => is_string($v) || is_numeric($v) ? mb_substr(trim((string) preg_replace('/\s+/u', ' ', (string) $v)), 0, $max + 1) : '';

        $sede = is_numeric($e['sede_id'] ?? null) ? Sede::where('activo', true)->find((int) $e['sede_id']) : null;
        if ($sede === null || ($sedesPermitidas !== null && ! in_array($sede->id, $sedesPermitidas, true))) {
            $errores['sede_id'][] = 'Elige la sede donde ocurre el movimiento.';
        }
        $tipo = is_string($e['tipo_movimiento'] ?? null) && isset(MovimientoTransporte::TIPOS[$e['tipo_movimiento']]) ? $e['tipo_movimiento'] : null;
        if ($tipo === null) {
            $errores['tipo_movimiento'][] = 'Elige si es una LLEGADA o una SALIDA.';
        }
        $estatus = is_string($e['estatus'] ?? null) && isset(MovimientoTransporte::ESTATUS[$e['estatus']]) ? $e['estatus'] : null;
        if ($estatus === null) {
            $errores['estatus'][] = 'Elige el estatus del servicio: A tiempo, Con retraso o No llegó (uso de taxis).';
        }

        $horario = null;
        if (! is_numeric($e['ruta_horario_id'] ?? null)) {
            $errores['ruta_horario_id'][] = 'Elige la ruta programada.';
        } elseif ($sede !== null && $tipo !== null) {
            $horario = RutaHorario::with('ruta.proveedor:id,nombre')->find((int) $e['ruta_horario_id']);
            if ($horario === null || ! $horario->ruta->activo || $horario->ruta->sede_id !== $sede->id || $horario->ruta->sentido !== $tipo) {
                $horario = null;
                $errores['ruta_horario_id'][] = 'La ruta elegida no es de esta sede, no es de '.mb_strtolower(MovimientoTransporte::TIPOS[$tipo]).' o está suspendida. Elige otra de la lista.';
            }
        }

        $observaciones = is_string($e['observaciones'] ?? null) ? trim($e['observaciones']) : '';
        if (mb_strlen($observaciones) > 1000) {
            $errores['observaciones'][] = 'Las observaciones son muy largas (máximo 1000 caracteres).';
        }

        $datos = [
            'sede' => $sede, 'tipo_movimiento' => $tipo, 'estatus' => $estatus, 'horario' => $horario,
            'fecha' => $sede ? $this->ahoraEn($sede)->toDateString() : null,
            'observaciones' => $observaciones === '' ? null : $observaciones,
            'firma_guardia' => is_string($e['firma_guardia'] ?? null) ? $e['firma_guardia'] : null,
            'taxis' => [],
        ];

        if ($estatus === 'no_llego') {
            if ($conFirmas && ! $this->firmas->viene($datos['firma_guardia'])) {
                $errores['firma_guardia'][] = 'Falta la firma del guardia: firma en el recuadro antes de guardar.';
            }
            $datos['taxis'] = $this->validarTaxis($actor, $e['taxis'] ?? null, $horario?->ruta, $sede, $conFirmas, $errores, $texto);
        } elseif ($estatus !== null) {
            $datos += $this->validarUnidad($e, $errores, $texto);
        }

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        return $datos;
    }

    /**
     * Datos de la unidad del servicio normal (todo opcional, como en SEGCAT).
     *
     * @param  array<string, mixed>  $e
     * @param  array<string, list<string>>  $errores
     * @return array<string, mixed>
     */
    private function validarUnidad(array $e, array &$errores, \Closure $texto): array
    {
        $placas = Vehiculo::normalizarPlacas($texto($e['placas'] ?? null, 30));
        if ($placas !== '' && ! preg_match(self::PLACAS, $placas)) {
            $errores['placas'][] = 'Revisa las placas: solo letras y números, de 2 a 20 (los espacios y guiones se quitan solos).';
        }
        $chofer = mb_strtoupper($texto($e['chofer'] ?? null));
        if (mb_strlen($chofer) > 150) {
            $errores['chofer'][] = 'El nombre del chofer es muy largo (máximo 150 caracteres).';
        } elseif ($chofer !== '' && mb_strlen($chofer) < 3) {
            $errores['chofer'][] = 'Escribe el nombre completo del chofer.';
        }
        $pax = $e['cantidad_pax'] ?? '';
        if ($pax !== '' && $pax !== null && (! is_numeric($pax) || (int) $pax != $pax || (int) $pax < 0 || (int) $pax > 999)) {
            $errores['cantidad_pax'][] = 'Los pasajeros deben ser un número entero de 0 a 999.';
        }
        $tipoUnidad = is_string($e['tipo_unidad'] ?? null) && isset(MovimientoTransporte::TIPOS_UNIDAD[$e['tipo_unidad']]) ? $e['tipo_unidad'] : 'autobus';

        return [
            'placas' => $placas === '' ? null : $placas,
            'chofer' => $chofer === '' ? null : $chofer,
            'cantidad_pax' => is_numeric($pax) ? max(0, (int) $pax) : 0,
            'tipo_unidad' => $tipoUnidad,
        ] + $this->detallesUnidad($e, '', $errores, $texto);
    }

    /**
     * Marca, modelo, número económico, capacidad y teléfono del chofer (opcionales).
     *
     * @param  array<string, mixed>  $e
     * @param  array<string, list<string>>  $errores
     * @return array<string, mixed>
     */
    private function detallesUnidad(array $e, string $prefijo, array &$errores, \Closure $texto, string $etiqueta = ''): array
    {
        $marca = mb_strtoupper($texto($e['marca'] ?? null, 50));
        $modelo = mb_strtoupper($texto($e['modelo'] ?? null, 60));
        $economico = mb_strtoupper($texto($e['economico'] ?? null, 30));
        $capacidad = $e['capacidad'] ?? '';
        $telefono = preg_replace('/[\s\-().+]/', '', $texto($e['chofer_telefono'] ?? null, 30));

        // "Taxi 2: la marca…" o, en la unidad normal, "La marca…"
        $mensaje = fn (string $m) => $etiqueta === '' ? ucfirst($m) : $etiqueta.$m;
        if (mb_strlen($marca) > 50) {
            $errores[$prefijo.'marca'][] = $mensaje('la marca es muy larga (máximo 50 caracteres).');
        }
        if (mb_strlen($modelo) > 60) {
            $errores[$prefijo.'modelo'][] = $mensaje('el modelo es muy largo (máximo 60 caracteres).');
        }
        if (mb_strlen($economico) > 30) {
            $errores[$prefijo.'economico'][] = $mensaje('el número económico es muy largo (máximo 30 caracteres).');
        }
        if ($capacidad !== '' && $capacidad !== null && (! is_numeric($capacidad) || (int) $capacidad != $capacidad || (int) $capacidad < 1 || (int) $capacidad > 99)) {
            $errores[$prefijo.'capacidad'][] = $mensaje('la capacidad debe ser de 1 a 99 personas.');
        }
        if ($telefono !== '' && ! preg_match(self::TELEFONO, $telefono)) {
            $errores[$prefijo.'chofer_telefono'][] = $mensaje('el teléfono del chofer debe tener de 10 a 15 dígitos.');
        }

        return [
            'marca' => $marca === '' ? null : $marca,
            'modelo' => $modelo === '' ? null : $modelo,
            'economico' => $economico === '' ? null : $economico,
            'capacidad' => is_numeric($capacidad) ? (int) $capacidad : null,
            'chofer_telefono' => $telefono === '' ? null : $telefono,
        ];
    }

    /**
     * Cada taxi: placas, chofer, monto > 0, destino, al menos un colaborador,
     * justificación si supera el tope y, si se firma en pantalla, la firma del taxista.
     *
     * @param  array<string, list<string>>  $errores
     * @return list<array<string, mixed>>
     */
    private function validarTaxis(User $actor, mixed $filas, ?Ruta $ruta, ?Sede $sede, bool $conFirmas, array &$errores, \Closure $texto): array
    {
        $filas = is_array($filas) ? array_values(array_filter($filas, 'is_array')) : [];
        if ($filas === []) {
            $errores['taxis'][] = 'Debe añadir al menos una unidad de taxi para documentar el fallo.';

            return [];
        }
        if (count($filas) > self::MAX_TAXIS) {
            $errores['taxis'][] = 'Se pueden registrar máximo '.self::MAX_TAXIS.' taxis a la vez.';

            return [];
        }

        $tope = $ruta?->costo_maximo_taxi !== null ? (float) $ruta->costo_maximo_taxi : null;
        $vistos = [];
        $taxis = [];
        foreach ($filas as $i => $t) {
            $n = $i + 1;
            $p = "taxis.{$i}.";
            $et = "Taxi {$n}: ";

            $placas = Vehiculo::normalizarPlacas($texto($t['placas'] ?? null, 30));
            if ($placas === '') {
                $errores[$p.'placas'][] = $et.'faltan las placas.';
            } elseif (! preg_match(self::PLACAS, $placas)) {
                $errores[$p.'placas'][] = $et.'revisa las placas: solo letras y números, de 2 a 20.';
            }
            $chofer = mb_strtoupper($texto($t['chofer'] ?? null));
            if ($chofer === '') {
                $errores[$p.'chofer'][] = $et.'falta el nombre del conductor.';
            } elseif (mb_strlen($chofer) > 150 || mb_strlen($chofer) < 3) {
                $errores[$p.'chofer'][] = $et.'escribe el nombre completo del conductor (máximo 150 caracteres).';
            }

            $montoTexto = is_string($t['monto'] ?? null) || is_numeric($t['monto'] ?? null) ? str_replace([',', '$', ' '], '', (string) $t['monto']) : '';
            $monto = is_numeric($montoTexto) ? round((float) $montoTexto, 2) : null;
            if ($monto === null || $monto <= 0) {
                $errores[$p.'monto'][] = $et.'el monto del vale debe ser mayor a cero.';
            } elseif ($monto > 99999.99) {
                $errores[$p.'monto'][] = $et.'revisa el monto del vale: es demasiado alto.';
            }

            $justificacion = is_string($t['justificacion'] ?? null) ? trim($t['justificacion']) : '';
            if ($monto !== null && $tope !== null && $monto > $tope && $justificacion === '') {
                $errores[$p.'justificacion'][] = $et.'el monto ($'.number_format($monto, 2).') supera el tope de la ruta ($'.number_format($tope, 2).'). Escribe la justificación.';
            }
            if (mb_strlen($justificacion) > 1000) {
                $errores[$p.'justificacion'][] = $et.'la justificación es muy larga (máximo 1000 caracteres).';
            }

            $destino = Paradero::normalizarNombre($texto($t['destino'] ?? null));
            if ($destino === '') {
                $errores[$p.'destino'][] = $et.'falta el destino (paradero).';
            } elseif (mb_strlen($destino) > 150) {
                $errores[$p.'destino'][] = $et.'el destino es muy largo (máximo 150 caracteres).';
            }

            $pasajeros = $this->pasajerosValidos($actor, $t['pasajeros'] ?? [], $sede);
            if ($pasajeros === []) {
                $errores[$p.'pasajeros'][] = $et.'agrega al menos un colaborador transportado (escanea su gafete o escribe su número de empleado).';
            } elseif (count($pasajeros) > self::MAX_PASAJEROS) {
                $errores[$p.'pasajeros'][] = $et.'máximo '.self::MAX_PASAJEROS.' pasajeros por taxi.';
            }
            foreach ($pasajeros as $id) {
                if (isset($vistos[$id])) {
                    $nombre = Colaborador::find($id)?->nombreCompleto() ?? 'Un colaborador';
                    $errores[$p.'pasajeros'][] = $et."«{$nombre}» ya va en el Taxi {$vistos[$id]}.";
                }
                $vistos[$id] ??= $n;
            }

            $firma = is_string($t['firma'] ?? null) ? $t['firma'] : null;
            if ($conFirmas && ! $this->firmas->viene($firma)) {
                $errores[$p.'firma'][] = $et.'falta la firma del taxista: que firme en el recuadro.';
            }

            $tipo = is_string($t['tipo'] ?? null) && isset(MovimientoTransporte::TIPOS_TAXI[$t['tipo']]) ? $t['tipo'] : 'sedan';

            $taxis[] = [
                'placas' => $placas, 'chofer' => $chofer, 'monto' => $monto, 'tipo' => $tipo,
                'justificacion' => $justificacion === '' ? null : $justificacion,
                'destino' => $destino, 'pasajeros' => $pasajeros, 'firma' => $firma,
            ] + $this->detallesUnidad($t, $p, $errores, $texto, $et);
        }

        return $taxis;
    }

    /**
     * Colaboradores de la empresa, activos y que el usuario puede ver (los que
     * encuentra el lector universal). Sin duplicados.
     *
     * @return list<int>
     */
    public function pasajerosValidos(User $actor, mixed $ids, ?Sede $sede): array
    {
        $ids = collect(is_array($ids) ? $ids : [])->filter(fn ($v) => is_numeric($v))->map(fn ($v) => (int) $v)->unique()->values();
        if ($ids->isEmpty()) {
            return [];
        }
        $sedes = $actor->can('colaboradores.ver') ? $this->autorizador->sedesPermitidas($actor, 'colaboradores.ver') : ($sede ? [$sede->id] : []);

        $validos = Colaborador::whereIn('colaboradores.id', $ids)->where('colaboradores.activo', true)->whereNull('colaboradores.fusionado_en_id')
            ->when($sedes !== null, fn ($q) => $q->where(fn ($s) => $s->whereNull('colaboradores.sede_id')->orWhere(fn ($x) => $x->enSedes($sedes))))
            ->pluck('colaboradores.id')->map(fn ($id) => (int) $id)->all();

        // En el orden capturado
        return $ids->filter(fn ($id) => in_array($id, $validos, true))->values()->all();
    }

    // ----------------------------------------------------------------- Edición

    /**
     * SEGCAT: solo se corrigen pasajeros y observaciones (normal) o monto,
     * destino, justificación, pasajeros y observaciones (taxi). Ruta, unidad y
     * chofer no cambian: si se equivocaron, se anula y se registra de nuevo.
     * Además, en el servicio normal se puede corregir A tiempo / Con retraso.
     *
     * @param  array<string, mixed>  $e
     */
    public function actualizar(User $actor, MovimientoTransporte $m, array $e): MovimientoTransporte
    {
        if ($m->anulado) {
            throw ValidationException::withMessages(['estado' => 'Este registro está anulado — no se puede editar.']);
        }
        if ($m->autorizado()) {
            throw ValidationException::withMessages(['estado' => 'Este vale ya tiene el Vo.Bo. de autorización: ya no se puede editar.']);
        }

        $antes = $this->foto($m);
        $observaciones = is_string($e['observaciones'] ?? null) ? trim($e['observaciones']) : '';
        $errores = [];
        if (mb_strlen($observaciones) > 1000) {
            $errores['observaciones'][] = 'Las observaciones son muy largas (máximo 1000 caracteres).';
        }

        if ($m->esTaxi()) {
            $montoTexto = is_string($e['monto'] ?? null) || is_numeric($e['monto'] ?? null) ? str_replace([',', '$', ' '], '', (string) $e['monto']) : '';
            $monto = is_numeric($montoTexto) ? round((float) $montoTexto, 2) : null;
            if ($monto === null) {
                $errores['monto'][] = 'Falta el monto del vale.';
            } elseif ($monto <= 0) {
                $errores['monto'][] = 'El costo del servicio debe ser mayor a cero.';
            } elseif ($monto > 99999.99) {
                $errores['monto'][] = 'Revisa el monto del vale: es demasiado alto.';
            }
            $justificacion = is_string($e['justificacion'] ?? null) ? trim($e['justificacion']) : '';
            $tope = $m->ruta?->costo_maximo_taxi !== null ? (float) $m->ruta->costo_maximo_taxi : null;
            if ($monto !== null && $tope !== null && $monto > $tope && $justificacion === '') {
                $errores['justificacion'][] = 'El costo ($'.number_format($monto, 2).') supera el tope de la ruta ($'.number_format($tope, 2).'). Debes anotar la justificación.';
            }
            if (mb_strlen($justificacion) > 1000) {
                $errores['justificacion'][] = 'La justificación es muy larga (máximo 1000 caracteres).';
            }
            $destino = Paradero::normalizarNombre(is_string($e['destino'] ?? null) ? $e['destino'] : '');
            if ($destino === '') {
                $errores['destino'][] = 'Falta el destino (paradero).';
            } elseif (mb_strlen($destino) > 150) {
                $errores['destino'][] = 'El destino es muy largo (máximo 150 caracteres).';
            }
            $pasajeros = $this->pasajerosValidos($actor, $e['pasajeros'] ?? [], $m->sede);
            if ($pasajeros === []) {
                $errores['pasajeros'][] = 'Debe quedar al menos un colaborador registrado.';
            } elseif (count($pasajeros) > self::MAX_PASAJEROS) {
                $errores['pasajeros'][] = 'Máximo '.self::MAX_PASAJEROS.' pasajeros por taxi.';
            }
            if ($errores !== []) {
                throw ValidationException::withMessages($errores);
            }

            DB::transaction(function () use ($actor, $m, $monto, $justificacion, $destino, $pasajeros, $observaciones) {
                $paradero = $this->rutas->paraderoParaRuta($actor, $m->sede, $destino);
                $m->fill([
                    'monto' => $monto, 'justificacion' => $justificacion === '' ? null : $justificacion, 'paradero_id' => $paradero->id,
                    'cantidad_pax' => count($pasajeros), 'observaciones' => $observaciones === '' ? null : $observaciones,
                ])->forceFill(['editado_por' => $actor->id, 'editado_en' => now()])->save();
                $m->pasajeros()->sync($pasajeros);
            });
        } else {
            $pax = $e['cantidad_pax'] ?? '';
            if ($pax === '' || $pax === null) {
                $pax = 0;
            }
            if (! is_numeric($pax) || (int) $pax != $pax || (int) $pax < 0 || (int) $pax > 999) {
                $errores['cantidad_pax'][] = 'Los pasajeros deben ser un número entero de 0 a 999.';
            }
            $estatus = $e['estatus'] ?? $m->estatus;
            if (! in_array($estatus, ['a_tiempo', 'retraso'], true)) {
                $errores['estatus'][] = 'En un servicio normal solo se puede corregir entre A TIEMPO y RETRASO. Si la unidad no llegó, anula este registro y registra los taxis.';
            }
            if ($errores !== []) {
                throw ValidationException::withMessages($errores);
            }
            $m->fill(['cantidad_pax' => (int) $pax, 'estatus' => $estatus, 'observaciones' => $observaciones === '' ? null : $observaciones])
                ->forceFill(['editado_por' => $actor->id, 'editado_en' => now()])->save();
        }

        $this->auditoria->auditar($actor, 'transporte.actualizado', $m, $antes, $this->foto($m->refresh()));

        return $m;
    }

    // ------------------------------------------------------- Cambios de estado

    /**
     * Anular, no borrar: es una bitácora; queda quién y cuándo.
     */
    public function anular(User $actor, MovimientoTransporte $m): void
    {
        if ($m->anulado) {
            throw ValidationException::withMessages(['estado' => 'Este registro ya estaba anulado.']);
        }
        $m->forceFill(['anulado' => true, 'anulado_por' => $actor->id, 'anulado_en' => now()])->save();
        $this->auditoria->auditar($actor, 'transporte.anulado', $m, ['anulado' => false], ['anulado' => true]);
    }

    public function reactivar(User $actor, MovimientoTransporte $m): void
    {
        if (! $m->anulado) {
            throw ValidationException::withMessages(['estado' => 'Este registro no está anulado: no hay nada que reactivar.']);
        }
        $antes = ['anulado' => true, 'anulado_por' => $m->anulado_por, 'anulado_en' => $m->anulado_en?->toIso8601String()];
        $m->forceFill(['anulado' => false, 'anulado_por' => null, 'anulado_en' => null])->save();
        $this->auditoria->auditar($actor, 'transporte.reactivado', $m, $antes, ['anulado' => false]);
    }

    /**
     * Vo.Bo. del vale de taxi (SEGCAT: "Requiere Autorización", firma a mano
     * en "Vo.Bo Autorización"). Después ya no se edita.
     */
    public function autorizar(User $actor, MovimientoTransporte $m): void
    {
        if (! $m->esTaxi()) {
            throw ValidationException::withMessages(['estado' => 'Solo los vales de taxi llevan Vo.Bo. de autorización.']);
        }
        if ($m->anulado) {
            throw ValidationException::withMessages(['estado' => 'Este vale está anulado: no se puede autorizar.']);
        }
        if ($m->autorizado()) {
            throw ValidationException::withMessages(['estado' => 'Este vale ya estaba autorizado.']);
        }
        $m->forceFill(['autorizado_por' => $actor->id, 'autorizado_en' => now()])->save();
        $this->auditoria->auditar($actor, 'transporte.autorizado', $m, ['autorizado' => false], ['autorizado' => true, 'monto' => $m->monto]);
    }

    // ---------------------------------------------------------------- Lectura

    /**
     * Totales de una consulta (SEGCAT: resumen de reportes). Gasto, pasajeros y
     * movimientos solo cuentan los vigentes; los anulados se cuentan aparte.
     *
     * @param  Builder<MovimientoTransporte>  $consulta
     * @return array{total: int, a_tiempo: int, retraso: int, taxis: int, gasto: float, pax: int, anulados: int}
     */
    public function resumen(Builder $consulta): array
    {
        $fila = (clone $consulta)->reorder()->toBase()->selectRaw("
            SUM(CASE WHEN movimientos_transporte.anulado = 0 THEN 1 ELSE 0 END) as total,
            SUM(CASE WHEN movimientos_transporte.anulado = 0 AND movimientos_transporte.estatus = 'a_tiempo' THEN 1 ELSE 0 END) as a_tiempo,
            SUM(CASE WHEN movimientos_transporte.anulado = 0 AND movimientos_transporte.estatus = 'retraso' THEN 1 ELSE 0 END) as retraso,
            SUM(CASE WHEN movimientos_transporte.anulado = 0 AND movimientos_transporte.estatus = 'no_llego' THEN 1 ELSE 0 END) as taxis,
            SUM(CASE WHEN movimientos_transporte.anulado = 0 THEN COALESCE(movimientos_transporte.monto, 0) ELSE 0 END) as gasto,
            SUM(CASE WHEN movimientos_transporte.anulado = 0 THEN movimientos_transporte.cantidad_pax ELSE 0 END) as pax,
            SUM(CASE WHEN movimientos_transporte.anulado = 1 THEN 1 ELSE 0 END) as anulados
        ")->first();

        return [
            'total' => (int) ($fila->total ?? 0), 'a_tiempo' => (int) ($fila->a_tiempo ?? 0), 'retraso' => (int) ($fila->retraso ?? 0),
            'taxis' => (int) ($fila->taxis ?? 0), 'gasto' => round((float) ($fila->gasto ?? 0), 2), 'pax' => (int) ($fila->pax ?? 0),
            'anulados' => (int) ($fila->anulados ?? 0),
        ];
    }

    /**
     * Foto para la bitácora de auditoría (sin las rutas de las firmas).
     *
     * @return array<string, mixed>
     */
    public function foto(MovimientoTransporte $m): array
    {
        return $m->only(['sede_id', 'ruta_id', 'ruta_horario_id', 'tipo_movimiento', 'estatus', 'vehiculo_id', 'chofer_id', 'cantidad_pax', 'monto', 'justificacion', 'paradero_id', 'observaciones', 'anulado'])
            + [
                'fecha' => $m->fecha?->toDateString(),
                'pasajeros' => $m->esTaxi() ? $m->pasajeros()->pluck('colaboradores.id')->map(fn ($id) => (int) $id)->sort()->values()->all() : [],
                'con_firma_guardia' => $m->firma_guardia !== null,
                'con_firma_taxista' => $m->firma_taxista !== null,
            ];
    }
}
