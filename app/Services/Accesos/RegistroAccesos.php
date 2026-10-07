<?php

namespace App\Services\Accesos;

use App\Models\Acceso;
use App\Models\AcompananteAcceso;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Gafete;
use App\Models\Persona;
use App\Models\Proveedor;
use App\Models\User;
use App\Models\Vehiculo;
use App\Models\ZonaEstacionamiento;
use App\Services\Padrones\AdministradorProveedores;
use App\Services\Padrones\AltasPorVerificar;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Personas\AdministradorPersonas;
use App\Services\Recepcion\RecepcionEnCaseta;
use App\Services\Vehiculos\AdministradorVehiculos;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * "Registro Inteligente de Ingreso" (réplica de acceso_proceso.php?accion=entrada
 * de SEGCAT): un solo formulario que cambia según el tipo de persona.
 *
 * Reglas de SEGCAT que se conservan:
 *  - la sede debe ser de la empresa y estar al alcance de quien registra;
 *  - colaboradores, huéspedes y emergencias nunca llevan gafete;
 *  - proveedor y contratista nacen PENDIENTES y necesitan host (colaborador);
 *  - con reserva, el pase del huésped siempre es Estancia;
 *  - el conductor del huésped solo se guarda si llegó en vehículo;
 *  - la zona debe ser de la sede y estar activa;
 *  - el vehículo, la persona y la empresa externa caen a sus padrones
 *    (se buscan primero para no duplicar), con la propiedad del vehículo
 *    derivada del tipo: Colaborador/Emergencia/Personal externo → propio
 *    visitante; Huésped → propio huésped o taxi/app si trae conductor;
 *    Proveedor/Contratista → flotilla de empresa;
 *  - un mismo nombre en el Padrón de personas pregunta "¿Es la misma
 *    persona?" antes de crear otra (homónimos reales).
 *
 * Lo que SEGCAT callaba (un gafete ocupado, una zona inválida, un host de
 * otra sede) ahora se avisa con un mensaje dentro del formulario.
 */
class RegistroAccesos
{
    /** Máximo de acompañantes por registro (como SEGCAT). */
    public const MAX_ACOMPANANTES = 15;

    public function __construct(
        private readonly ConsultaAccesos $consulta,
        private readonly AdministradorRoles $auditoria,
        private readonly AdministradorPersonas $personas,
        private readonly AdministradorVehiculos $vehiculos,
        private readonly AdministradorProveedores $proveedores,
    ) {}

    /**
     * @param  array<string, mixed>  $entrada
     */
    public function registrar(User $actor, array $entrada): Acceso
    {
        $d = $this->validar($entrada);
        $tipo = $d['tipo'];
        $errores = [];

        // ---------- Sede (al alcance de quien registra) ----------
        $sedeId = (int) $d['sede_id'];
        if (! $this->consulta->sedesParaElegir($actor, 'accesos.crear')->contains('id', $sedeId)) {
            throw ValidationException::withMessages(['sede_id' => 'Elige una sede activa de la lista: esa sede no existe, está desactivada o no está a tu cargo.']);
        }

        // ---------- Titular ----------
        $colaborador = null;
        $nombre = $this->texto($d['nombre'] ?? null);
        if ($tipo === 'colaborador') {
            $colaborador = $this->colaboradorEnSede($d['colaborador_id'] ?? null, $sedeId);
            if ($colaborador === null) {
                $errores['colaborador_id'] = empty($d['colaborador_id'])
                    ? 'Escanea o busca al colaborador que ingresa.'
                    : 'El colaborador elegido no existe, está dado de baja o no pertenece a esta sede.';
            } else {
                $nombre = $colaborador->nombreCompleto();
            }
        } elseif ($tipo === 'emergencia') {
            $nombre ??= Acceso::NOMBRE_EMERGENCIA;
        } elseif ($nombre === null) {
            $errores['nombre'] = $tipo === 'huesped' ? 'Escribe el nombre del huésped.' : 'El nombre de la persona es obligatorio.';
        }

        // ---------- Host (proveedor / contratista) y a quién visita (personal externo) ----------
        $host = null;
        if (in_array($tipo, Acceso::CON_AUTORIZACION, true)) {
            $host = $this->colaboradorEnSede($d['host_colaborador_id'] ?? null, $sedeId);
            if ($host === null) {
                $errores['host_colaborador_id'] = empty($d['host_colaborador_id'])
                    ? 'Indica quién citó al proveedor/contratista (Host).'
                    : 'El host elegido no existe, está dado de baja o no pertenece a esta sede.';
            }
        }
        $motivo = $tipo === 'visitante' ? ($d['motivo_visita'] ?? 'rh') : null;
        $visita = null;
        if ($motivo === 'colaborador') {
            $visita = $this->colaboradorEnSede($d['visita_colaborador_id'] ?? null, $sedeId);
            if ($visita === null) {
                $errores['visita_colaborador_id'] = empty($d['visita_colaborador_id'])
                    ? 'Indica a quién visita (busca o escanea al colaborador).'
                    : 'El colaborador visitado no existe, está dado de baja o no pertenece a esta sede.';
            }
        }

        // ---------- Departamento (proveedor / contratista) ----------
        $departamentoId = null;
        if (in_array($tipo, Acceso::CON_AUTORIZACION, true) && ! empty($d['departamento_id'])) {
            $departamentoId = Departamento::where('activo', true)->aplicanEn([$sedeId])->whereKey((int) $d['departamento_id'])->value('id');
            if ($departamentoId === null) {
                $errores['departamento_id'] = 'Elige un departamento activo de la lista.';
            }
        }

        // ---------- Vehículo y zona ----------
        $placas = Vehiculo::normalizarPlacas($d['placas'] ?? null);
        $modo = $tipo === 'emergencia' ? null : ($d['modo_arribo'] ?? 'a_pie');
        if ($modo === 'a_pie') {
            $placas = '';
        } elseif ($modo !== null && $placas === '') {
            $errores['placas'] = 'Escribe las placas del vehículo (o elige «A Pie»).';
        }
        if ($placas !== '' && ! preg_match('/^[A-Z0-9Ñ]{2,20}$/u', $placas)) {
            $errores['placas'] = 'Las placas solo llevan letras y números (de 2 a 20).';
        }
        $tipoVehiculo = $d['tipo_vehiculo'] ?? ($modo === 'moto' ? 'motocicleta' : 'sedan');
        if ($placas !== '' && $modo !== null) {
            $modo = $tipoVehiculo === 'motocicleta' ? 'moto' : 'auto';
        }
        if ($tipo === 'emergencia' && $placas !== '') {
            $modo = $tipoVehiculo === 'motocicleta' ? 'moto' : 'auto';
        }
        $zonaId = null;
        if ($placas !== '' && ! empty($d['zona_estacionamiento_id'])) {
            $zonaId = ZonaEstacionamiento::where('activo', true)->where('sede_id', $sedeId)->whereKey((int) $d['zona_estacionamiento_id'])->value('id');
            if ($zonaId === null) {
                $errores['zona_estacionamiento_id'] = 'Elige una zona activa de esta sede (o déjalo «Sin asignar»).';
            }
        }
        $conductor = $tipo === 'huesped' && $placas !== '' ? $this->mayusculas($d['conductor'] ?? null) : null;

        // ---------- Gafetes (titular y acompañantes) ----------
        $gafete = null;
        if (! in_array($tipo, Acceso::SIN_GAFETE, true) && ! empty($d['gafete_id'])) {
            [$gafete, $mensaje] = $this->gafeteLibre((int) $d['gafete_id'], $sedeId);
            if ($mensaje !== null) {
                $errores['gafete_id'] = $mensaje;
            }
        }
        $acompanantes = [];
        $usados = $gafete ? [$gafete->id] : [];
        foreach (array_values($d['acompanantes'] ?? []) as $i => $fila) {
            $n = $i + 1;
            $nombreAc = $this->mayusculas($fila['nombre'] ?? null);
            $idAc = $fila['identificacion'] ?? null;
            $gafeteAc = null;
            if (! empty($fila['gafete_id'])) {
                $gid = (int) $fila['gafete_id'];
                if (in_array($gid, $usados, true)) {
                    $errores["acompanantes.$i.gafete_id"] = "El gafete del acompañante $n ya está elegido para otra persona de este registro.";
                } else {
                    [$gafeteAc, $mensaje] = $this->gafeteLibre($gid, $sedeId);
                    if ($mensaje !== null) {
                        $errores["acompanantes.$i.gafete_id"] = "Acompañante $n: ".$mensaje;
                    } else {
                        $usados[] = $gid;
                    }
                }
            }
            if ($nombreAc === null && $idAc === null && $gafeteAc === null) {
                continue;
            }
            $acompanantes[] = ['nombre' => $nombreAc, 'identificacion' => $idAc, 'gafete' => $gafeteAc];
        }

        // Recepción de candidatos y autorizaciones (ADR-0007): candidato, visita a departamento y fotos
        $recepcion = app(RecepcionEnCaseta::class)->preparar($tipo, $motivo, $entrada, $sedeId, $visita, $errores);
        // Fin Recepción de candidatos

        if ($errores !== []) {
            throw ValidationException::withMessages($errores);
        }

        // ---------- Padrón de personas: "¿Es la misma persona?" ----------
        $personaId = null;
        $personaRepetida = null;
        if (in_array($tipo, Acceso::AL_PADRON, true)) {
            if (! empty($d['persona_id'])) {
                // Altas por verificar: una persona unida a otra se sustituye por la correcta; una rechazada no se usa
                $elegida = Persona::find((int) $d['persona_id']);
                $elegida = $elegida === null ? null : app(AltasPorVerificar::class)->paraOperacion('personas', $elegida, 'nombre');
                $elegida = $elegida?->activo ? $elegida : null;
                if ($elegida === null) {
                    throw ValidationException::withMessages(['nombre' => 'La persona elegida ya no está activa en el Padrón de personas. Escribe su nombre de nuevo.']);
                }
                $personaId = $elegida->id;
                $nombre = $elegida->nombre_completo;
            } elseif (($d['persona_decision'] ?? null) !== 'distinta') {
                $personaRepetida = Persona::where('activo', true)->where('tipo', $tipo)
                    ->whereRaw('LOWER(nombre_completo) = ?', [mb_strtolower((string) $nombre)])->orderBy('id')->first();
                if ($personaRepetida !== null && ($d['persona_decision'] ?? null) !== 'misma') {
                    $empresa = $personaRepetida->empresaQueRepresenta();
                    throw ValidationException::withMessages(['persona_repetida' => 'Ya existe una persona registrada con este nombre: «'.$personaRepetida->nombre_completo.'»'
                        .' ('.(Persona::TIPOS[$personaRepetida->tipo] ?? $personaRepetida->tipo).($empresa ? ' · '.$empresa : '').'). ¿Es la misma?']);
                }
            }
        }

        $estado = in_array($tipo, Acceso::CON_AUTORIZACION, true) || $recepcion['esperar'] ? 'pendiente' : 'en_sitio'; // Recepción: visita que espera al departamento

        $acceso = DB::transaction(function () use ($actor, $d, $tipo, $sedeId, $nombre, $colaborador, $host, $visita, $motivo, $departamentoId,
            $placas, $modo, $tipoVehiculo, $zonaId, $conductor, $gafete, $acompanantes, $personaId, $personaRepetida, $estado) {
            // Gafetes: se revisan otra vez con el registro bloqueado (dos casetas a la vez)
            foreach (array_filter([$gafete, ...array_column($acompanantes, 'gafete')]) as $g) {
                Gafete::whereKey($g->id)->lockForUpdate()->first();
                if (! $g->fresh()->disponibleParaAsignar()) {
                    throw ValidationException::withMessages(['gafete_id' => "El gafete {$g->nomenclatura} se acaba de prestar a otra persona. Elige otro."]);
                }
            }

            // Empresa externa: proveedor/contratista (procedencia) o agencia del huésped
            [$proveedorId, $procedencia] = $this->empresaExterna($actor, $tipo, $d, $sedeId);

            if (in_array($tipo, Acceso::AL_PADRON, true) && $personaId === null) {
                $personaId = $personaRepetida?->id ?? $this->nuevaPersona($actor, $tipo, (string) $nombre, $proveedorId, $procedencia, $sedeId);
            }

            $vehiculoId = null;
            if ($placas !== '') {
                $propiedad = match (true) {
                    $tipo === 'huesped' && $conductor !== null => 'taxi_app',
                    $tipo === 'huesped' => 'propio_huesped',
                    in_array($tipo, Acceso::CON_AUTORIZACION, true) => 'empresa_proveedor',
                    default => 'propio_visitante',
                };
                $vehiculoId = $this->vehiculo($actor, $placas, $tipoVehiculo, $d, $propiedad, $propiedad === 'empresa_proveedor' ? $proveedorId : null, $sedeId)->id;
            }

            $reserva = $tipo === 'huesped' ? (bool) ($d['tiene_reserva'] ?? true) : null;
            $acceso = Acceso::create([
                'sede_id' => $sedeId,
                'tipo' => $tipo,
                'movimiento' => 'entrada',
                'estado' => $estado,
                'nombre' => $this->mayusculas($nombre),
                'colaborador_id' => $colaborador?->id,
                'persona_id' => $personaId,
                'proveedor_id' => $proveedorId,
                'empresa_procedencia' => $procedencia,
                'motivo_visita' => $motivo,
                'visita_colaborador_id' => $visita?->id,
                'persona_visita' => $visita ? mb_strtoupper($visita->nombreCompleto()) : null,
                'host_colaborador_id' => $host?->id,
                'identificacion' => in_array($tipo, Acceso::AL_PADRON, true) ? ($d['identificacion'] ?? 'ine') : null,
                'gafete_id' => $gafete?->id,
                'gafete_texto' => $gafete?->nomenclatura,
                'modo_arribo' => $modo,
                'vehiculo_id' => $vehiculoId,
                'placas' => $placas === '' ? null : $placas,
                'zona_estacionamiento_id' => $zonaId,
                'conductor' => $conductor,
                'num_acompanantes' => max(count($acompanantes), (int) ($d['num_acompanantes'] ?? 0)),
                'tiene_reserva' => $reserva,
                'numero_reserva' => $reserva ? $this->mayusculas($d['numero_reserva'] ?? null) : null,
                // Con reserva, el pase siempre es Estancia (regla de SEGCAT)
                'tipo_pase' => $tipo === 'huesped' ? ($reserva ? 'estancia' : ($d['tipo_pase'] ?? 'daypass')) : null,
                'habitacion' => $tipo === 'huesped' ? $this->mayusculas($d['habitacion'] ?? null) : null,
                'tipo_visita' => in_array($tipo, Acceso::CON_AUTORIZACION, true) ? ($d['tipo_visita'] ?? 'cortesia') : null,
                'departamento_id' => $departamentoId,
                'area_trabajo' => in_array($tipo, Acceso::CON_AUTORIZACION, true) ? $this->mayusculas($d['area_trabajo'] ?? null) : null,
                'actividad' => in_array($tipo, Acceso::CON_AUTORIZACION, true) ? $this->texto($d['actividad'] ?? null) : null,
                'tipo_emergencia' => $tipo === 'emergencia' ? ($d['tipo_emergencia'] ?? null) : null,
                'observaciones' => $tipo === 'emergencia' ? $this->texto($d['observaciones'] ?? null, false) : null,
                'entrada_at' => now(),
            ]);

            foreach ($acompanantes as $ac) {
                AcompananteAcceso::create([
                    'acceso_id' => $acceso->id, 'nombre' => $ac['nombre'], 'identificacion' => $ac['identificacion'],
                    'gafete_id' => $ac['gafete']?->id, 'gafete_texto' => $ac['gafete']?->nomenclatura,
                ]);
            }

            return $acceso;
        });

        $this->auditoria->auditar($actor, 'accesos.creado', $acceso, null, self::foto($acceso));
        app(RecepcionEnCaseta::class)->despues($actor, $acceso, $recepcion); // Recepción de candidatos (ADR-0007)

        return $acceso;
    }

    // --------------------------------------------------------------- Validación

    /**
     * Formato de cada campo; las reglas que dependen del tipo van en registrar().
     *
     * @param  array<string, mixed>  $entrada
     * @return array<string, mixed>
     */
    private function validar(array $entrada): array
    {
        $entrada = array_map(fn ($v) => is_string($v) && trim($v) === '' ? null : $v, $entrada);
        if (isset($entrada['acompanantes']) && is_array($entrada['acompanantes'])) {
            $entrada['acompanantes'] = array_map(fn ($f) => is_array($f) ? array_map(fn ($v) => is_string($v) && trim($v) === '' ? null : $v, $f) : $f, $entrada['acompanantes']);
        }

        return Validator::make($entrada, [
            'sede_id' => ['required', 'integer'],
            'tipo' => ['required', Rule::in(array_keys(Acceso::TIPOS))],
            'nombre' => ['nullable', 'string', 'max:150'],
            'persona_id' => ['nullable', 'integer'],
            'persona_decision' => ['nullable', Rule::in(['misma', 'distinta'])],
            'colaborador_id' => ['nullable', 'integer'],
            'host_colaborador_id' => ['nullable', 'integer'],
            'visita_colaborador_id' => ['nullable', 'integer'],
            'motivo_visita' => ['nullable', Rule::in(array_keys(Acceso::MOTIVOS))],
            'identificacion' => ['nullable', Rule::in(array_keys(Acceso::IDENTIFICACIONES))],
            'empresa_procedencia' => ['nullable', 'string', 'max:150'],
            'proveedor_id' => ['nullable', 'integer'],
            'tipo_visita' => ['nullable', Rule::in(array_keys(Acceso::TIPOS_VISITA))],
            'departamento_id' => ['nullable', 'integer'],
            'area_trabajo' => ['nullable', 'string', 'max:150'],
            'actividad' => ['nullable', 'string', 'max:500'],
            'gafete_id' => ['nullable', 'integer'],
            'modo_arribo' => ['nullable', Rule::in(array_keys(Acceso::MODOS))],
            'placas' => ['nullable', 'string', 'max:25'],
            'tipo_vehiculo' => ['nullable', Rule::in(array_keys(Vehiculo::TIPOS))],
            'marca' => ['nullable', 'string', 'max:50'],
            'modelo' => ['nullable', 'string', 'max:60'],
            'color' => ['nullable', 'string', 'max:30'],
            'zona_estacionamiento_id' => ['nullable', 'integer'],
            'conductor' => ['nullable', 'string', 'max:150'],
            'tiene_reserva' => ['nullable', 'boolean'],
            'numero_reserva' => ['nullable', 'string', 'max:50'],
            'tipo_pase' => ['nullable', Rule::in(array_keys(Acceso::PASES))],
            'habitacion' => ['nullable', 'string', 'max:20'],
            'agencia_id' => ['nullable', 'integer'],
            'tipo_emergencia' => ['nullable', Rule::in(array_keys(Acceso::TIPOS_EMERGENCIA))],
            'observaciones' => ['nullable', 'string', 'max:1000'],
            'num_acompanantes' => ['nullable', 'integer', 'min:0', 'max:'.self::MAX_ACOMPANANTES],
            'acompanantes' => ['nullable', 'array', 'max:'.self::MAX_ACOMPANANTES],
            'acompanantes.*.nombre' => ['nullable', 'string', 'max:150'],
            'acompanantes.*.identificacion' => ['nullable', Rule::in(array_keys(Acceso::IDENTIFICACIONES))],
            'acompanantes.*.gafete_id' => ['nullable', 'integer'],
        ], [
            'sede_id.required' => 'Elige la sede.',
            'tipo.required' => 'Elige el tipo de persona que ingresa.',
            'tipo.in' => 'Elige el tipo de persona de la lista.',
            'nombre.max' => 'El nombre admite máximo 150 caracteres.',
            'tipo_vehiculo.in' => 'Elige el tipo de vehículo de la lista.',
            'placas.max' => 'Revisa las placas: son muy largas.',
            'num_acompanantes.max' => 'Máximo '.self::MAX_ACOMPANANTES.' acompañantes por registro.',
            'num_acompanantes.min' => 'El número de acompañantes no puede ser negativo.',
            'acompanantes.max' => 'Máximo '.self::MAX_ACOMPANANTES.' acompañantes por registro.',
            'actividad.max' => 'Describe la actividad en máximo 500 caracteres.',
            'observaciones.max' => 'Las observaciones admiten máximo 1000 caracteres.',
            '*.in' => 'Elige una opción de la lista.',
            '*.integer' => 'Elige una opción de la lista.',
        ], [
            'empresa_procedencia' => 'empresa / procedencia',
            'area_trabajo' => 'área de trabajo',
            'numero_reserva' => 'número de reserva',
            'habitacion' => 'número de habitación',
        ])->validate();
    }

    // ------------------------------------------------------------------ Ayudas

    /**
     * Colaborador activo (no unido a otro) de esta sede, corporativo o con la sede como adicional.
     */
    private function colaboradorEnSede(mixed $id, int $sedeId): ?Colaborador
    {
        if (empty($id)) {
            return null;
        }

        return Colaborador::where('colaboradores.activo', true)->whereNull('colaboradores.fusionado_en_id')
            ->where(fn ($q) => $q->whereNull('colaboradores.sede_id')->orWhere(fn ($s) => $s->enSedes([$sedeId])))
            ->find((int) $id);
    }

    /**
     * El gafete y, si no se puede prestar, por qué.
     *
     * @return array{0: ?Gafete, 1: ?string}
     */
    private function gafeteLibre(int $id, int $sedeId): array
    {
        $gafete = Gafete::find($id);
        if ($gafete === null || (int) $gafete->sede_id !== $sedeId) {
            return [null, 'El gafete no existe o no es de esta sede.'];
        }
        if (! $gafete->activo) {
            return [null, "El gafete {$gafete->nomenclatura} está dado de baja."];
        }
        if (! $gafete->disponibleParaAsignar()) {
            return [null, "El gafete {$gafete->nomenclatura} ya está en uso (EN SITIO). Pide que lo devuelvan o elige otro."];
        }

        return [$gafete, null];
    }

    /**
     * Proveedor/contratista: su empresa; huésped: la agencia vinculada. Se busca
     * por nombre antes de crearla (SEGCAT: upsertEmpresaExt). Una empresa dada de
     * baja (vetada) no puede ingresar como proveedor o contratista.
     *
     * @param  array<string, mixed>  $d
     * @return array{0: ?int, 1: ?string}
     */
    private function empresaExterna(User $actor, string $tipo, array $d, int $sedeId): array
    {
        $esProveedor = in_array($tipo, Acceso::CON_AUTORIZACION, true);
        if (! $esProveedor && $tipo !== 'huesped') {
            return [null, null];
        }
        $idElegido = $esProveedor ? ($d['proveedor_id'] ?? null) : ($d['agencia_id'] ?? null);
        $texto = AdministradorProveedores::normalizarNombre($d['empresa_procedencia'] ?? null);

        $proveedor = $idElegido ? Proveedor::find((int) $idElegido) : null;
        if ($proveedor === null && $texto !== '') {
            $proveedor = $this->proveedores->buscarPorNombre($texto);
        }
        // Altas por verificar: unida a otra = la correcta; rechazada = no se usa
        $proveedor = $proveedor === null ? null : app(AltasPorVerificar::class)->paraOperacion('proveedores', $proveedor, 'empresa_procedencia');
        if ($proveedor !== null && ! $proveedor->activo) {
            if ($esProveedor) {
                throw ValidationException::withMessages(['empresa_procedencia' => "La empresa «{$proveedor->nombre}» está dada de baja (vetada) en Proveedores: no puede ingresar."]);
            }

            return [null, mb_strtoupper($proveedor->nombre)];
        }
        if ($proveedor === null && $texto === '') {
            return [null, null];
        }

        if ($proveedor === null) {
            $categoria = match ($tipo) {
                'contratista' => 'contratista',
                'proveedor' => 'proveedor',
                default => 'agencia_viajes',
            };
            $proveedor = Proveedor::create(['nombre' => $texto, 'categoria' => $categoria, 'todas_las_sedes' => false]);
            $proveedor->sedes()->sync([$sedeId]);
            $this->auditoria->auditar($actor, 'proveedores.creado', $proveedor, null, $this->proveedores->foto($proveedor));
            app(AltasPorVerificar::class)->registrarAlta($actor, 'proveedores', $proveedor, 'accesos', $sedeId);
        }

        return [$proveedor->id, mb_strtoupper($proveedor->nombre)];
    }

    private function nuevaPersona(User $actor, string $tipo, string $nombre, ?int $proveedorId, ?string $procedencia, ?int $sedeId = null): int
    {
        $persona = Persona::create([
            'tipo' => $tipo,
            'categoria' => 'general',
            'nombre_completo' => mb_convert_case(mb_strtolower($nombre), MB_CASE_TITLE),
            'proveedor_id' => $tipo === 'visitante' ? null : $proveedorId,
            'empresa_procedencia' => $tipo === 'visitante' || $proveedorId !== null ? null : $procedencia,
        ]);
        $this->auditoria->auditar($actor, 'visitantes.creado', $persona, null, $this->personas->foto($persona));
        app(AltasPorVerificar::class)->registrarAlta($actor, 'personas', $persona, 'accesos', $sedeId);

        return $persona->id;
    }

    /**
     * Busca las placas en el padrón; si existen se usan (y se completan los
     * datos que faltaban, sin tocar los que ya tenía); si no, se registran.
     *
     * @param  array<string, mixed>  $d
     */
    public function vehiculo(User $actor, string $placas, string $tipoVehiculo, array $d, string $propiedad, ?int $proveedorId, ?int $sedeId = null): Vehiculo
    {
        $datos = array_filter([
            'marca' => $this->mayusculas($d['marca'] ?? null),
            'modelo' => $this->mayusculas($d['modelo'] ?? null),
            'color' => $this->mayusculas($d['color'] ?? null),
        ]);

        $existente = $this->vehiculos->conPlacas($placas);
        if ($existente !== null) {
            // Altas por verificar: unido a otro = el correcto; rechazado = no se usa
            $existente = app(AltasPorVerificar::class)->paraOperacion('vehiculos', $existente, 'placas');
            $faltantes = array_filter($datos, fn ($v, $campo) => $existente->{$campo} === null || $existente->{$campo} === '', ARRAY_FILTER_USE_BOTH);
            if ($faltantes !== []) {
                $antes = $this->vehiculos->foto($existente);
                $existente->fill($faltantes)->save();
                $this->auditoria->auditar($actor, 'vehiculos.actualizado', $existente, $antes, $this->vehiculos->foto($existente));
            }

            return $existente;
        }

        $vehiculo = Vehiculo::create($datos + [
            'placas' => $placas,
            'tipo' => $tipoVehiculo,
            'propiedad' => $propiedad,
            'proveedor_id' => $proveedorId,
        ]);
        $this->auditoria->auditar($actor, 'vehiculos.creado', $vehiculo, null, $this->vehiculos->foto($vehiculo));
        app(AltasPorVerificar::class)->registrarAlta($actor, 'vehiculos', $vehiculo, 'accesos', $sedeId);

        return $vehiculo;
    }

    private function texto(mixed $valor, bool $unaLinea = true): ?string
    {
        if (! is_string($valor)) {
            return null;
        }
        $limpio = trim($unaLinea ? (string) preg_replace('/\s+/u', ' ', $valor) : $valor);

        return $limpio === '' ? null : $limpio;
    }

    private function mayusculas(mixed $valor): ?string
    {
        $limpio = $this->texto($valor);

        return $limpio === null ? null : mb_strtoupper($limpio);
    }

    /**
     * Foto para la bitácora de auditoría.
     *
     * @return array<string, mixed>
     */
    public static function foto(Acceso $a): array
    {
        return $a->only([
            'sede_id', 'tipo', 'movimiento', 'estado', 'nombre', 'colaborador_id', 'persona_id', 'proveedor_id', 'host_colaborador_id',
            'visita_colaborador_id', 'gafete_texto', 'placas', 'zona_estacionamiento_id', 'num_acompanantes', 'tipo_pase', 'habitacion',
        ]);
    }
}
