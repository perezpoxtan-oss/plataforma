<?php

namespace App\Services\PasesSalida;

use App\Models\Colaborador;
use App\Models\Equipo;
use App\Models\PaseSalida;
use App\Models\Proveedor;
use App\Models\Sede;
use App\Models\User;
use App\Services\Firmas\Firmas;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de los Pases de salida (réplica de pases_salida_proceso.php,
 * pases_salida_firmar.php y pases_salida_rechazar.php de SEGCAT).
 *
 * Alcance: con alcance de empresa se ve todo; con alcance de sede, los
 * pases que SALEN de sus sedes o que VAN a ellas (la sede destino firma la
 * recepción y la salida de regreso); con alcance "propios", además, solo
 * los que él registró. Todo corre con la empresa de trabajo fijada en el
 * Tenant: el filtro de empresa lo pone el modelo.
 *
 * Permisos de las firmas (SEGCAT usaba "editar" para todo):
 *   - Aprobaciones (Jefe de Departamento, Contraloría, Gerencia) y Rechazar: pases_salida.aprobar
 *   - Salida física, Recepción en destino, Salida de regreso y Regreso: pases_salida.firmar
 * Las aprobaciones, la salida física y el regreso se firman con alcance en
 * la sede de ORIGEN; la recepción en destino y la salida de regreso, con
 * alcance en la sede DESTINO.
 */
class AdministradorPasesSalida
{
    public const POR_PAGINA = 20;

    public const MAX_ARTICULOS = 50;

    /** Filtros de la lista (SEGCAT: ?filtro=). */
    public const FILTROS = [
        'todos' => 'Todos',
        'pendientes' => 'Pendientes de Aprobación',
        'aprobados' => 'Aprobados, listos para salir',
        'fuera' => 'Fuera de la propiedad',
        'espera_regreso' => 'Esperando Regreso',
        'vencidos' => 'Vencidos',
    ];

    /** grupo completo => [estado nuevo, columna de fecha, evento de auditoría] */
    private const AVANCE = [
        'aprobacion' => [PaseSalida::APROBADO, 'aprobado_en', 'pases_salida.aprobado'],
        'salida_fisica' => [PaseSalida::SALIO, 'salio_en', 'pases_salida.salida_registrada'],
        'recepcion_destino' => [PaseSalida::EN_DESTINO, 'recibido_destino_en', 'pases_salida.recibido_en_destino'],
        'salida_regreso' => [PaseSalida::EN_TRANSITO_REGRESO, 'salio_regreso_en', 'pases_salida.salida_de_regreso'],
        'regreso' => [PaseSalida::REGRESADO, 'regreso_en', 'pases_salida.regresado'],
    ];

    private const TELEFONO = '/^\+?[0-9][0-9 ()\-]{6,19}$/';

    /** @var array<int, string>|null fecha local "Y-m-d" de cada sede */
    private ?array $hoy = null;

    public function __construct(
        private readonly Autorizador $autorizador,
        private readonly AdministradorRoles $auditoria,
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

    /**
     * Limita una consulta a los pases que el actor puede ver con un permiso:
     * los que salen de sus sedes o van a ellas.
     *
     * @param  Builder<PaseSalida>  $consulta
     * @return Builder<PaseSalida>
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
            ->when($sedes !== null, fn ($q) => $q->where(fn ($s) => $s->whereIn('pases_salida.sede_id', $sedes)
                ->orWhereIn('pases_salida.sede_destino_id', $sedes)))
            ->when($efectivo->alcance === Alcance::Propios, fn ($q) => $q->where('pases_salida.creado_por', $actor->id));
    }

    /**
     * ¿Puede firmar el grupo que toca? Revisa el permiso del grupo (aprobar o
     * firmar) y que tenga alcance en la sede donde se firma ese grupo.
     */
    public function puedeFirmarGrupo(User $actor, PaseSalida $pase, string $grupo): bool
    {
        $permiso = PaseSalida::permisoDelGrupo($grupo);
        if (! $actor->can($permiso)) {
            return false;
        }
        if ($actor->es_superadmin) {
            return true;
        }

        $efectivo = $this->autorizador->permisosEfectivos($actor)[$permiso] ?? null;
        $sedes = $this->autorizador->sedesPermitidas($actor, $permiso);
        $sede = $pase->sedeDelGrupo($grupo);

        return $efectivo !== null
            && ($sedes === null || ($sede !== null && in_array($sede, $sedes, true)))
            && ($efectivo->alcance !== Alcance::Propios || (int) $pase->creado_por === (int) $actor->id);
    }

    public function puedeRechazar(User $actor, PaseSalida $pase): bool
    {
        return $pase->estado === PaseSalida::PENDIENTE && $this->puedeFirmarGrupo($actor, $pase, 'aprobacion');
    }

    // ------------------------------------------------------------------- Fechas

    /**
     * "Hoy" en cada sede (su zona horaria o la de la empresa).
     *
     * @return array<int, string>
     */
    public function hoyPorSede(): array
    {
        return $this->hoy ??= Sede::withTrashed()->with('empresa:id,zona_horaria')->get(['id', 'empresa_id', 'zona_horaria'])
            ->mapWithKeys(fn (Sede $s) => [(int) $s->id => now($s->zonaHoraria())->format('Y-m-d')])->all();
    }

    public function hoyDe(PaseSalida $pase): string
    {
        return $this->hoyPorSede()[(int) $pase->sede_id] ?? now()->format('Y-m-d');
    }

    public function vencido(PaseSalida $pase): bool
    {
        return $pase->vencido($this->hoyDe($pase));
    }

    // ------------------------------------------------------------------- Lista

    /**
     * Aplica un filtro de la lista. "Vencidos" compara contra la fecha local
     * de la sede de origen de cada pase.
     *
     * @param  Builder<PaseSalida>  $consulta
     * @return Builder<PaseSalida>
     */
    public function filtrar(Builder $consulta, string $filtro): Builder
    {
        $fuera = fn ($q) => $q->whereIn('pases_salida.estado', PaseSalida::FUERA);

        return match ($filtro) {
            'pendientes' => $consulta->where('pases_salida.estado', PaseSalida::PENDIENTE),
            'aprobados' => $consulta->where('pases_salida.estado', PaseSalida::APROBADO),
            'fuera' => $fuera($consulta),
            'espera_regreso' => $fuera($consulta)->where('pases_salida.requiere_regreso', true),
            'vencidos' => $fuera($consulta)->where('pases_salida.requiere_regreso', true)->whereNotNull('pases_salida.fecha_tentativa_regreso')
                ->where(function ($q) {
                    $q->whereRaw('1 = 0');
                    foreach ($this->hoyPorSede() as $sede => $hoy) {
                        $q->orWhere(fn ($s) => $s->where('pases_salida.sede_id', $sede)->where('pases_salida.fecha_tentativa_regreso', '<', $hoy));
                    }
                }),
            default => $consulta,
        };
    }

    /**
     * Búsqueda por folio, solicitante o lo que sale (SEGCAT: folio o solicitante).
     *
     * @param  Builder<PaseSalida>  $consulta
     * @return Builder<PaseSalida>
     */
    public function buscar(Builder $consulta, string $texto): Builder
    {
        $texto = trim((string) preg_replace('/\s+/u', ' ', $texto));
        if ($texto === '') {
            return $consulta;
        }
        $comodin = fn (string $t) => '%'.addcslashes(mb_strtolower($t), '%_\\').'%';

        return $consulta->where(function ($q) use ($texto, $comodin) {
            $q->whereRaw('LOWER(pases_salida.folio) LIKE ?', [$comodin($texto)])
                ->orWhereHas('solicitante', function ($c) use ($texto, $comodin) {
                    $c->whereRaw('LOWER(colaboradores.num_empleado) LIKE ?', [$comodin($texto)])
                        ->orWhere(function ($n) use ($texto, $comodin) {
                            foreach (explode(' ', $texto) as $palabra) {
                                $n->where(fn ($p) => $p->whereRaw('LOWER(colaboradores.nombre) LIKE ?', [$comodin($palabra)])
                                    ->orWhereRaw('LOWER(colaboradores.apellido_paterno) LIKE ?', [$comodin($palabra)])
                                    ->orWhereRaw('LOWER(colaboradores.apellido_materno) LIKE ?', [$comodin($palabra)]));
                            }
                        });
                })
                ->orWhereHas('articulos', fn ($a) => $a->whereRaw('LOWER(pases_salida_articulos.equipo) LIKE ?', [$comodin($texto)])
                    ->orWhereRaw('LOWER(pases_salida_articulos.serie) LIKE ?', [$comodin($texto)]));
        });
    }

    // ---------------------------------------------------------------- Escritura

    /**
     * Alta del pase: queda "Pendiente de Aprobación" con folio PS-000123
     * consecutivo por empresa (SEGCAT: pases_salida_proceso.php?accion=crear).
     *
     * @param  array<string, mixed>  $entrada
     */
    public function crear(User $actor, array $entrada): PaseSalida
    {
        [$datos, $articulos] = $this->validar($actor, $entrada);

        for ($intento = 1; ; $intento++) {
            try {
                $pase = DB::transaction(function () use ($datos, $articulos) {
                    $numero = (int) PaseSalida::query()->lockForUpdate()->max('folio_numero') + 1;
                    $pase = new PaseSalida($datos);
                    $pase->forceFill([
                        'folio_numero' => $numero,
                        'folio' => PaseSalida::folioDe($numero),
                        'requiere_regreso' => PaseSalida::requiereRegreso($datos['motivo']),
                        'estado' => PaseSalida::PENDIENTE,
                    ])->save();
                    foreach ($articulos as $articulo) {
                        $pase->articulos()->create($articulo + ['empresa_id' => $pase->empresa_id]);
                    }

                    return $pase;
                });
                break;
            } catch (UniqueConstraintViolationException $e) {
                // Dos altas al mismo tiempo tomaron el mismo folio: se intenta con el siguiente
                if ($intento >= 3) {
                    throw $e;
                }
            }
        }

        $this->auditoria->auditar($actor, 'pases_salida.creado', $pase, null, $this->foto($pase->load('articulos')));

        return $pase;
    }

    /**
     * Firma de un rol (SEGCAT: pases_salida_firmar.php). Cada rol firma una
     * vez; cuando firman todos los roles del grupo, el pase avanza solo.
     * Devuelve el estado nuevo si avanzó, o null.
     *
     * @param  array<string, mixed>  $entrada  rol, nombre_firma, firma (imagen en base64)
     */
    public function firmar(User $actor, PaseSalida $pase, array $entrada): ?string
    {
        $datos = Validator::make($entrada, [
            'rol' => ['required', 'string', 'max:40'],
            'nombre_firma' => ['required', 'string', 'max:150'],
        ], [
            'rol.required' => 'Elige qué rol firma.',
            'nombre_firma.required' => 'Falta el nombre completo de quien firma.',
            'nombre_firma.max' => 'El nombre de quien firma admite máximo 150 caracteres.',
        ])->validate();

        $rol = $datos['rol'];
        $grupo = PaseSalida::grupoDeRol($rol);
        if ($grupo === null) {
            throw ValidationException::withMessages(['rol' => 'Rol de firma no válido.']);
        }
        if ($pase->grupoAbierto() !== $grupo) {
            throw ValidationException::withMessages(['rol' => 'Este pase ya no está en el punto correcto del circuito para esta firma.']);
        }
        if (! $this->puedeFirmarGrupo($actor, $pase, $grupo)) {
            throw new AuthorizationException('No tienes permiso para firmar esta parte del pase.');
        }
        if ($pase->firmas()->where('rol', $rol)->exists()) {
            throw ValidationException::withMessages(['rol' => 'Este rol ya firmó este pase.']);
        }

        $nombre = mb_strtoupper(trim((string) preg_replace('/\s+/u', ' ', $datos['nombre_firma'])), 'UTF-8');
        $ruta = $this->firmas->guardar($entrada['firma'] ?? null, 'pases-salida', 'firma', 'la firma');

        try {
            [$firma, $avance] = DB::transaction(function () use ($pase, $grupo, $rol, $nombre, $ruta) {
                // Se bloquea el pase: dos firmas simultáneas no pueden saltarse el circuito
                $actual = PaseSalida::query()->lockForUpdate()->findOrFail($pase->id);
                if ($actual->grupoAbierto() !== $grupo) {
                    throw ValidationException::withMessages(['rol' => 'Este pase ya no está en el punto correcto del circuito para esta firma.']);
                }
                if ($actual->firmas()->where('rol', $rol)->exists()) {
                    throw ValidationException::withMessages(['rol' => 'Este rol ya firmó este pase.']);
                }

                $firma = $actual->firmas()->create([
                    'empresa_id' => $actual->empresa_id, 'grupo' => $grupo, 'rol' => $rol, 'nombre_firma' => $nombre, 'firma_ruta' => $ruta,
                ]);

                $roles = array_keys(PaseSalida::GRUPOS[$grupo][1]);
                $firmados = $actual->firmas()->whereIn('rol', $roles)->distinct()->count('rol');
                $avance = null;
                if ($firmados >= count($roles)) {
                    [$estado, $columna, $evento] = self::AVANCE[$grupo];
                    $actual->forceFill(['estado' => $estado, $columna => now()])->save();
                    $avance = [$estado, $evento];
                }

                return [$firma, $avance];
            });
        } catch (\Throwable $e) {
            $this->firmas->borrar($ruta);
            throw $e;
        }

        $pase->refresh();
        $this->auditoria->auditar($actor, 'pases_salida.firmado', $pase, null, [
            'folio' => $pase->folio, 'grupo' => $grupo, 'rol' => $rol, 'firma' => $firma->etiquetaRol(), 'nombre_firma' => $nombre,
        ]);
        if ($avance !== null) {
            $this->auditoria->auditar($actor, $avance[1], $pase, ['estado' => PaseSalida::ESTADOS[$this->estadoAnterior($grupo)][0] ?? null],
                ['folio' => $pase->folio, 'estado' => PaseSalida::ESTADOS[$avance[0]][0]]);
        }

        return $avance[0] ?? null;
    }

    /**
     * Rechazo con motivo, solo mientras está pendiente de aprobación
     * (SEGCAT: pases_salida_rechazar.php).
     *
     * @param  array<string, mixed>  $entrada
     */
    public function rechazar(User $actor, PaseSalida $pase, array $entrada): void
    {
        $datos = Validator::make($entrada, [
            'motivo_rechazo' => ['required', 'string', 'max:1000'],
        ], [
            'motivo_rechazo.required' => 'Escribe el motivo del rechazo.',
            'motivo_rechazo.max' => 'El motivo del rechazo admite máximo 1000 caracteres.',
        ])->validate();

        if (! $this->puedeFirmarGrupo($actor, $pase, 'aprobacion')) {
            throw new AuthorizationException('No tienes permiso para rechazar este pase.');
        }

        DB::transaction(function () use ($pase, $datos, $actor) {
            $actual = PaseSalida::query()->lockForUpdate()->findOrFail($pase->id);
            if ($actual->estado !== PaseSalida::PENDIENTE) {
                throw ValidationException::withMessages(['motivo_rechazo' => 'Este pase ya no está pendiente de aprobación.']);
            }
            $actual->forceFill([
                'estado' => PaseSalida::RECHAZADO,
                'motivo_rechazo' => trim($datos['motivo_rechazo']),
                'rechazado_en' => now(),
                'rechazado_por' => $actor->id,
            ])->save();
        });

        $pase->refresh();
        $this->auditoria->auditar($actor, 'pases_salida.rechazado', $pase, ['estado' => PaseSalida::ESTADOS[PaseSalida::PENDIENTE][0]],
            ['folio' => $pase->folio, 'estado' => PaseSalida::ESTADOS[PaseSalida::RECHAZADO][0], 'motivo_rechazo' => $pase->motivo_rechazo]);
    }

    // --------------------------------------------------------------- Opciones

    /**
     * Sedes de origen donde puede registrar pases (activas y en su alcance).
     *
     * @return Collection<int, Sede>
     */
    public function sedesOrigen(User $actor)
    {
        $permitidas = $this->sedes($actor, 'pases_salida.crear');

        return Sede::where('activo', true)->when($permitidas !== null, fn ($q) => $q->whereIn('id', $permitidas))
            ->orderBy('nombre')->get(['id', 'nombre', 'direccion', 'colonia', 'ciudad', 'telefono']);
    }

    /**
     * Datos del equipo escaneado para llenar un renglón de artículos.
     *
     * @return array{equipo_id: int, equipo: string, marca: ?string, modelo: ?string, serie: string, descripcion: ?string}
     */
    public function articuloDeEquipo(Equipo $equipo): array
    {
        $equipo->loadMissing('tipo:id,nombre');

        return [
            'equipo_id' => $equipo->id,
            'equipo' => $equipo->tipo?->nombre ?? 'Equipo',
            'marca' => $equipo->marca,
            'modelo' => $equipo->modelo,
            'serie' => $equipo->numero_serie,
            'descripcion' => null,
        ];
    }

    // ------------------------------------------------------------- Validación

    /**
     * @param  array<string, mixed>  $entrada
     * @return array{0: array<string, mixed>, 1: list<array<string, mixed>>}
     */
    private function validar(User $actor, array $entrada): array
    {
        // Renglones vacíos (sin "Equipo") se ignoran, como en SEGCAT
        $articulos = collect(is_array($entrada['articulos'] ?? null) ? $entrada['articulos'] : [])
            ->filter(fn ($a) => is_array($a) && trim((string) ($a['equipo'] ?? '')) !== '')
            ->values()->all();
        $entrada['articulos'] = $articulos;
        foreach (['destino_direccion', 'destino_telefono'] as $campo) {
            if (isset($entrada[$campo]) && is_string($entrada[$campo])) {
                $entrada[$campo] = trim($entrada[$campo]);
            }
        }

        $datos = Validator::make($entrada, [
            'sede_id' => ['required', 'integer'],
            'motivo' => ['required', 'string', Rule::in(array_keys(PaseSalida::MOTIVOS))],
            'colaborador_id' => ['required', 'integer'],
            'destino_tipo' => ['required', 'string', Rule::in(array_keys(PaseSalida::DESTINOS))],
            'sede_destino_id' => ['nullable', 'required_if:destino_tipo,sede', 'integer'],
            'proveedor_id' => ['nullable', 'required_if:destino_tipo,proveedor', 'integer'],
            'colaborador_destino_id' => ['nullable', 'required_if:destino_tipo,colaborador', 'integer'],
            'destino_direccion' => ['nullable', 'string', 'max:255'],
            'destino_telefono' => ['nullable', 'string', 'regex:'.self::TELEFONO],
            'fecha_salida_programada' => ['nullable', 'date_format:Y-m-d'],
            'fecha_tentativa_regreso' => ['nullable', 'date_format:Y-m-d',
                ...(is_string($entrada['fecha_salida_programada'] ?? null) && $entrada['fecha_salida_programada'] !== '' ? ['after_or_equal:fecha_salida_programada'] : [])],
            'articulos' => ['required', 'array', 'max:'.self::MAX_ARTICULOS],
            'articulos.*.cantidad' => ['required', 'integer', 'min:1', 'max:9999'],
            'articulos.*.equipo' => ['required', 'string', 'max:150'],
            'articulos.*.marca' => ['nullable', 'string', 'max:100'],
            'articulos.*.modelo' => ['nullable', 'string', 'max:100'],
            'articulos.*.serie' => ['nullable', 'string', 'max:100'],
            'articulos.*.descripcion' => ['nullable', 'string', 'max:500'],
            'articulos.*.equipo_id' => ['nullable', 'integer'],
        ], [
            'sede_id.required' => 'Elige la sede de origen (de donde sale el equipo).',
            'motivo.required' => 'Elige el motivo de salida.',
            'motivo.in' => 'Motivo de salida no válido.',
            'colaborador_id.required' => 'Elige al solicitante: escanea su gafete, busca su nombre o regístralo con «Nuevo Colaborador».',
            'destino_tipo.required' => 'Elige el tipo de destino.',
            'destino_tipo.in' => 'Tipo de destino no válido.',
            'sede_destino_id.required_if' => 'Elige la sede destino.',
            'proveedor_id.required_if' => 'Elige el proveedor destino (o regístralo con «Nuevo Proveedor»).',
            'colaborador_destino_id.required_if' => 'Elige el colaborador que se lleva el equipo.',
            'destino_direccion.max' => 'La dirección de destino admite máximo 255 caracteres.',
            'destino_telefono.regex' => 'Revisa el teléfono: solo números (7 a 20), con "+" si es de otro país.',
            'fecha_salida_programada.date_format' => 'La fecha de salida programada no es válida.',
            'fecha_tentativa_regreso.date_format' => 'La fecha tentativa de regreso no es válida.',
            'fecha_tentativa_regreso.after_or_equal' => 'La fecha tentativa de regreso no puede ser antes de la fecha de salida.',
            'articulos.required' => 'Agrega al menos un artículo que salga en el pase.',
            'articulos.max' => 'Un pase admite máximo '.self::MAX_ARTICULOS.' artículos.',
            'articulos.*.cantidad.required' => 'Escribe la cantidad de cada artículo.',
            'articulos.*.cantidad.integer' => 'La cantidad debe ser un número entero.',
            'articulos.*.cantidad.min' => 'La cantidad mínima es 1.',
            'articulos.*.cantidad.max' => 'Revisa la cantidad: es demasiado alta.',
            'articulos.*.equipo.max' => 'El nombre del equipo admite máximo 150 caracteres.',
            'articulos.*.marca.max' => 'La marca admite máximo 100 caracteres.',
            'articulos.*.modelo.max' => 'El modelo admite máximo 100 caracteres.',
            'articulos.*.serie.max' => 'El número de serie admite máximo 100 caracteres.',
            'articulos.*.descripcion.max' => 'La descripción de un artículo admite máximo 500 caracteres.',
        ])->validate();

        // Sede de origen: activa y dentro de su alcance para crear
        $origen = $this->sedesOrigen($actor)->firstWhere('id', (int) $datos['sede_id']);
        if ($origen === null) {
            throw ValidationException::withMessages(['sede_id' => 'Elige una sede de origen activa en la que puedas registrar pases.']);
        }

        // Solicitante: cualquier colaborador activo de la empresa (también el corporativo, sin sede)
        $solicitante = Colaborador::where('activo', true)->whereNull('fusionado_en_id')->find((int) $datos['colaborador_id']);
        if ($solicitante === null) {
            throw ValidationException::withMessages(['colaborador_id' => 'Solicitante no válido: búscalo o regístralo antes de continuar.']);
        }

        $destino = ['sede_destino_id' => null, 'proveedor_id' => null, 'colaborador_destino_id' => null];
        switch ($datos['destino_tipo']) {
            case 'sede':
                $sedeDestino = (int) $datos['sede_destino_id'];
                if ($sedeDestino === $origen->id) {
                    throw ValidationException::withMessages(['sede_destino_id' => 'La sede de destino no puede ser la misma que la sede de origen: elige una distinta.']);
                }
                if (! Sede::where('activo', true)->whereKey($sedeDestino)->exists()) {
                    throw ValidationException::withMessages(['sede_destino_id' => 'Elige una sede destino activa de la empresa.']);
                }
                $destino['sede_destino_id'] = $sedeDestino;
                break;
            case 'proveedor':
                if (! Proveedor::where('activo', true)->whereKey((int) $datos['proveedor_id'])->exists()) {
                    throw ValidationException::withMessages(['proveedor_id' => 'Elige un proveedor activo de la empresa.']);
                }
                $destino['proveedor_id'] = (int) $datos['proveedor_id'];
                break;
            default:
                if (! Colaborador::where('activo', true)->whereNull('fusionado_en_id')->whereKey((int) $datos['colaborador_destino_id'])->exists()) {
                    throw ValidationException::withMessages(['colaborador_destino_id' => 'Elige el colaborador que se lleva el equipo.']);
                }
                $destino['colaborador_destino_id'] = (int) $datos['colaborador_destino_id'];
        }

        $conRegreso = PaseSalida::requiereRegreso($datos['motivo']);

        // Equipos escaneados: solo se ligan si son de la empresa (si no, se guarda solo el texto)
        $idsEquipo = collect($datos['articulos'])->pluck('equipo_id')->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $equiposValidos = $idsEquipo === [] ? [] : Equipo::whereIn('id', $idsEquipo)->pluck('id')->map(fn ($id) => (int) $id)->all();

        $limpio = fn ($v) => ($t = trim((string) preg_replace('/\s+/u', ' ', (string) $v))) === '' ? null : $t;
        $renglones = array_map(fn (array $a) => [
            'equipo_id' => isset($a['equipo_id']) && in_array((int) $a['equipo_id'], $equiposValidos, true) ? (int) $a['equipo_id'] : null,
            'cantidad' => (int) $a['cantidad'],
            'equipo' => $limpio($a['equipo']),
            'marca' => $limpio($a['marca'] ?? null),
            'modelo' => $limpio($a['modelo'] ?? null),
            'serie' => $limpio($a['serie'] ?? null),
            'descripcion' => isset($a['descripcion']) && trim((string) $a['descripcion']) !== '' ? trim((string) $a['descripcion']) : null,
        ], $datos['articulos']);

        return [[
            'sede_id' => $origen->id,
            'motivo' => $datos['motivo'],
            'colaborador_id' => $solicitante->id,
            'destino_tipo' => $datos['destino_tipo'],
            ...$destino,
            'destino_direccion' => ($datos['destino_direccion'] ?? '') !== '' ? $datos['destino_direccion'] : null,
            'destino_telefono' => ($datos['destino_telefono'] ?? '') !== '' ? $datos['destino_telefono'] : null,
            'fecha_salida_programada' => $datos['fecha_salida_programada'] ?? null,
            'fecha_tentativa_regreso' => $conRegreso ? ($datos['fecha_tentativa_regreso'] ?? null) : null,
        ], $renglones];
    }

    private function estadoAnterior(string $grupo): string
    {
        return match ($grupo) {
            'aprobacion' => PaseSalida::PENDIENTE,
            'salida_fisica' => PaseSalida::APROBADO,
            'recepcion_destino' => PaseSalida::SALIO,
            'salida_regreso' => PaseSalida::EN_DESTINO,
            default => PaseSalida::EN_TRANSITO_REGRESO,
        };
    }

    /**
     * Lo que se guarda en la bitácora de auditoría.
     *
     * @return array<string, mixed>
     */
    public function foto(PaseSalida $p): array
    {
        return [
            'folio' => $p->folio,
            'sede_id' => $p->sede_id,
            'motivo' => $p->motivo,
            'requiere_regreso' => $p->requiere_regreso,
            'colaborador_id' => $p->colaborador_id,
            'destino_tipo' => $p->destino_tipo,
            'sede_destino_id' => $p->sede_destino_id,
            'proveedor_id' => $p->proveedor_id,
            'colaborador_destino_id' => $p->colaborador_destino_id,
            'destino_direccion' => $p->destino_direccion,
            'destino_telefono' => $p->destino_telefono,
            'fecha_salida_programada' => $p->fecha_salida_programada?->format('Y-m-d'),
            'fecha_tentativa_regreso' => $p->fecha_tentativa_regreso?->format('Y-m-d'),
            'estado' => $p->estado,
            'articulos' => $p->articulos->map(fn ($a) => $a->resumen())->values()->all(),
        ];
    }
}
