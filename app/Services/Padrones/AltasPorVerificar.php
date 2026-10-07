<?php

namespace App\Services\Padrones;

use App\Models\Persona;
use App\Models\Proveedor;
use App\Models\Sede;
use App\Models\User;
use App\Models\Vehiculo;
use App\Services\Avisos\AvisosCorreo;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Autorizador;
use App\Services\Personas\AdministradorPersonas;
use App\Services\Vehiculos\AdministradorVehiculos;
use App\Support\HoraLocal;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Altas pendientes de verificar (ADR-0006).
 *
 * Cuando la caseta, desde una pantalla de Operación (Bitácora de accesos,
 * Bitácora de transporte, Pases de salida, Lost & Found), necesita un
 * vehículo, una empresa externa o una persona que no está en su padrón:
 *
 *  1. primero se le sugieren los parecidos ("¿Es alguno de estos?"), sin
 *     importar mayúsculas, acentos, espacios o guiones, y con las placas sin
 *     separadores (también los dados de baja, marcados);
 *  2. si no es ninguno, el registro se crea y se usa de inmediato, pero queda
 *     "pendiente de verificar" (salvo que quien lo registra pueda editar ese
 *     padrón: entonces nace verificado);
 *  3. quien puede editar el padrón lo acepta (y completa sus datos), lo
 *     rechaza con un motivo o lo une con el registro correcto: todo lo que se
 *     registró con el provisional pasa al correcto (REFERENCIAS).
 *
 * Un registro rechazado se queda para la historia, pero la operación ya no
 * lo puede usar; uno unido se sustituye solo por el registro correcto.
 *
 * Todo corre con la empresa de trabajo fijada en el Tenant.
 */
class AltasPorVerificar
{
    /**
     * padrón => modelo, permiso base ("<permiso>.ver/crear/editar"), textos y pantalla.
     *
     * @var array<string, array{modelo: class-string<Model>, permiso: string, nombre: string, singular: string, genero: string, ruta: string, ancla: string, icono: string, padron: string}>
     */
    public const PADRONES = [
        'vehiculos' => ['modelo' => Vehiculo::class, 'permiso' => 'vehiculos', 'nombre' => 'Vehículos', 'singular' => 'vehículo', 'genero' => 'm',
            'ruta' => 'vehiculos.index', 'ancla' => 'vehiculo-', 'icono' => 'bi-car-front-fill', 'padron' => 'Padrón vehicular'],
        'proveedores' => ['modelo' => Proveedor::class, 'permiso' => 'proveedores', 'nombre' => 'Empresas externas', 'singular' => 'empresa externa', 'genero' => 'f',
            'ruta' => 'proveedores.index', 'ancla' => 'proveedor-', 'icono' => 'bi-building', 'padron' => 'directorio de Empresas externas'],
        'personas' => ['modelo' => Persona::class, 'permiso' => 'visitantes', 'nombre' => 'Personas', 'singular' => 'persona', 'genero' => 'f',
            'ruta' => 'personas.index', 'ancla' => 'persona-', 'icono' => 'bi-person-vcard', 'padron' => 'Padrón de personas'],
    ];

    /**
     * Pantallas de Operación que pueden dar altas pendientes: origen => permiso
     * operativo que lo permite, nombre y prefijo de sus rutas.
     *
     * @var array<string, array{permiso: string, nombre: string, ruta: string}>
     */
    public const ORIGENES = [
        'accesos' => ['permiso' => 'accesos.crear', 'nombre' => 'Bitácora de accesos', 'ruta' => 'accesos.'],
        'transporte' => ['permiso' => 'transporte.crear', 'nombre' => 'Bitácora de transporte', 'ruta' => 'transporte.'],
        'pases_salida' => ['permiso' => 'pases_salida.crear', 'nombre' => 'Pases de salida', 'ruta' => 'pases-salida.'],
        'lost_found' => ['permiso' => 'lost_found.firmar', 'nombre' => 'Lost & Found', 'ruta' => 'lost_found.'],
    ];

    /** Qué padrón puede crecer desde cada pantalla de Operación. */
    public const ORIGENES_POR_PADRON = [
        'vehiculos' => ['accesos', 'transporte'],
        'proveedores' => ['accesos', 'pases_salida'],
        'personas' => ['accesos', 'transporte', 'lost_found'],
    ];

    /**
     * Columnas que apuntan a cada padrón [tabla, columna]: al unir un alta
     * pendiente con el registro correcto, aquí se cambia la referencia.
     * Todo módulo nuevo que guarde vehiculo_id, proveedor_id o persona_id se
     * agrega aquí (una prueba lo exige; ver AltasPorVerificarTest).
     *
     * @var array<string, list<array{0: string, 1: string}>>
     */
    public const REFERENCIAS = [
        'vehiculos' => [
            ['accesos', 'vehiculo_id'],
            ['movimientos_transporte', 'vehiculo_id'],
        ],
        'proveedores' => [
            ['accesos', 'proveedor_id'],
            ['pases_salida', 'proveedor_id'],
            ['personas', 'proveedor_id'],
            ['rutas', 'proveedor_id'],
            ['vehiculos', 'proveedor_id'],
        ],
        'personas' => [
            ['accesos', 'persona_id'],
            ['lost_found_entregas', 'persona_id'],
            ['movimientos_transporte', 'chofer_id'],
        ],
    ];

    /** Lo que se puede corregir al aceptar, por padrón. */
    public const CAMPOS_AL_ACEPTAR = [
        'vehiculos' => ['placas', 'propiedad', 'tipo', 'marca', 'modelo', 'color'],
        'proveedores' => ['nombre', 'categoria', 'rfc', 'telefono'],
        'personas' => ['nombre_completo', 'tipo', 'tipo_identificacion', 'folio_identificacion', 'telefono'],
    ];

    /** Terminaciones de razón social que no distinguen a una empresa de otra. */
    private const SOCIEDADES = [
        's a p i de c v', 's a b de c v', 's de r l de c v', 's a de c v', 's c de r l', 's de r l', 's a s', 's a', 's c',
        'sapi de cv', 'sab de cv', 's de rl de cv', 'sa de cv', 'sc de rl', 's de rl', 'sapi', 'sas', 'sa', 'sc',
    ];

    private const SIN_ACENTOS = [
        'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'ñ' => 'n',
        'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u', 'Ñ' => 'n',
    ];

    public function __construct(
        private readonly Autorizador $autorizador,
        private readonly AdministradorRoles $auditoria,
    ) {}

    /**
     * @return array{modelo: class-string<Model>, permiso: string, nombre: string, singular: string, genero: string, ruta: string, ancla: string, icono: string, padron: string}
     */
    public function definicion(string $padron): array
    {
        abort_unless(isset(self::PADRONES[$padron]), 404);

        return self::PADRONES[$padron];
    }

    // ----------------------------------------------------------------- Permisos

    /**
     * Origen (pantalla de Operación) según el nombre de la ruta que se está
     * mostrando: así los diálogos de alta rápida saben desde dónde se abren.
     */
    public function origenDeRuta(?string $ruta): ?string
    {
        foreach (self::ORIGENES as $clave => $origen) {
            if ($ruta !== null && str_starts_with($ruta, $origen['ruta'])) {
                return $clave;
            }
        }

        return null;
    }

    /**
     * El origen pedido, si aplica a ese padrón y el actor tiene su permiso operativo.
     */
    public function origenPermitido(User $actor, string $padron, mixed $origen): ?string
    {
        if (! is_string($origen) || ! in_array($origen, self::ORIGENES_POR_PADRON[$padron] ?? [], true)) {
            return null;
        }

        return $actor->can(self::ORIGENES[$origen]['permiso']) ? $origen : null;
    }

    /**
     * Para los registros rápidos: el origen pedido (si no le corresponde,
     * ninguno) o, si no se indicó, la primera pantalla de Operación de ese
     * padrón cuyo permiso operativo tiene el actor.
     */
    public function origenDeAlta(User $actor, string $padron, mixed $origen): ?string
    {
        if ($origen !== null && $origen !== '') {
            return $this->origenPermitido($actor, $padron, $origen);
        }
        foreach (self::ORIGENES_POR_PADRON[$padron] ?? [] as $clave) {
            if ($actor->can(self::ORIGENES[$clave]['permiso'])) {
                return $clave;
            }
        }

        return null;
    }

    /**
     * ¿Puede registrar en el padrón desde la pantalla actual? Con el permiso
     * de alta del padrón, o con el permiso operativo de la pantalla de origen
     * (entonces el registro nace pendiente de verificar).
     */
    public function puedeAltaRapida(User $actor, string $padron, ?string $origen = null): bool
    {
        $origen ??= $this->origenDeRuta(request()->route()?->getName());

        return $actor->can($this->definicion($padron)['permiso'].'.crear') || $this->origenPermitido($actor, $padron, $origen) !== null;
    }

    /** Verifica quien puede editar el padrón. */
    public function puedeVerificar(User $actor, string $padron): bool
    {
        return $actor->can($this->definicion($padron)['permiso'].'.editar');
    }

    /**
     * Altas pendientes que el actor puede verificar: las de su alcance en
     * "editar" del padrón. Con alcance de sede, las registradas en sus sedes
     * (o sin sede conocida); los proveedores, además, los que operan en ellas.
     *
     * @param  Builder<Model>  $consulta
     * @return Builder<Model>
     */
    public function limitarVerificacion(Builder $consulta, User $actor, string $padron): Builder
    {
        $permiso = $this->definicion($padron)['permiso'].'.editar';
        $tabla = $consulta->getModel()->getTable();
        if (! $actor->can($permiso)) {
            return $consulta->whereRaw('1 = 0');
        }
        if ($actor->es_superadmin) {
            return $consulta;
        }
        if ($padron === 'proveedores') {
            $consulta->whereIn('proveedores.id', app(AdministradorProveedores::class)->consulta($actor, $permiso)->select('proveedores.id'));
        } elseif ($this->autorizador->soloPropios($actor, $permiso)) {
            $consulta->where($tabla.'.creado_por', $actor->id);
        }
        $sedes = $this->autorizador->sedesPermitidas($actor, $permiso);
        if ($sedes !== null) {
            $consulta->where(fn ($q) => $q->whereNull($tabla.'.sede_alta_id')->orWhereIn($tabla.'.sede_alta_id', $sedes));
        }

        return $consulta;
    }

    /**
     * Ids de las altas pendientes que el actor puede verificar.
     *
     * @return list<int>
     */
    public function idsVerificables(User $actor, string $padron): array
    {
        $modelo = $this->definicion($padron)['modelo'];

        return $this->limitarVerificacion($modelo::query()->pendientesDeVerificar(), $actor, $padron)
            ->pluck((new $modelo)->getTable().'.id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * Para el aviso de Inicio: cuántas altas pendientes hay en cada padrón que el actor puede editar.
     *
     * @return list<array{padron: string, nombre: string, icono: string, total: int, ruta: string}>
     */
    public function pendientesPorPadron(User $actor): array
    {
        $grupos = [];
        foreach (self::PADRONES as $padron => $d) {
            if (! $this->puedeVerificar($actor, $padron)) {
                continue;
            }
            $total = $this->limitarVerificacion($d['modelo']::query()->pendientesDeVerificar(), $actor, $padron)->count();
            if ($total > 0) {
                $grupos[] = ['padron' => $padron, 'nombre' => $d['nombre'], 'icono' => $d['icono'], 'total' => $total,
                    'ruta' => route($d['ruta'], ['verificacion' => 'pendiente'])];
            }
        }

        return $grupos;
    }

    // ------------------------------------------------------------ Alta desde Operación

    /**
     * Después de crear un registro desde Operación: guarda de dónde vino y, si
     * quien lo registró no puede editar el padrón, lo deja pendiente de
     * verificar, lo audita y avisa por correo. Devuelve true si quedó pendiente.
     */
    public function registrarAlta(User $actor, string $padron, Model $registro, ?string $origen, ?int $sedeId = null): bool
    {
        $d = $this->definicion($padron);
        $sedeId ??= $this->sedeDelActor($actor, $origen);
        $pendiente = ! $this->puedeVerificar($actor, $padron);

        $registro->forceFill(['origen_alta' => $origen, 'sede_alta_id' => $sedeId]
            + ($pendiente ? ['verificacion' => Vehiculo::PENDIENTE] : []))->save();

        if ($pendiente) {
            $this->auditoria->auditar($actor, $d['permiso'].'.provisional', $registro, null, [
                'verificacion' => Vehiculo::PENDIENTE, 'origen' => $origen, 'sede_id' => $sedeId, 'titulo' => $this->titulo($padron, $registro),
            ]);
            app(AvisosCorreo::class)->altaPorVerificar($padron, $registro, $actor);
        }

        return $pendiente;
    }

    /**
     * Lo que la Operación puede usar: el registro, el que lo sustituye si se
     * unió con otro, o un error de captura si se rechazó.
     *
     * @template T of Model
     *
     * @param  T  $registro
     * @return T
     */
    public function paraOperacion(string $padron, Model $registro, string $campo): Model
    {
        for ($i = 0; $i < 5 && $registro->fusionado_en_id !== null; $i++) {
            $destino = $registro->fusionadoEn()->first();
            if ($destino === null) {
                break;
            }
            $registro = $destino;
        }

        if ($registro->estaRechazado()) {
            throw ValidationException::withMessages([$campo => $this->mensajeRechazado($padron, $registro)]);
        }

        return $registro;
    }

    public function mensajeRechazado(string $padron, Model $registro): string
    {
        $d = $this->definicion($padron);
        $rechazado = $d['genero'] === 'f' ? 'rechazada' : 'rechazado';

        return '«'.$this->titulo($padron, $registro)."» fue {$rechazado} al verificar el {$d['padron']}"
            .($registro->motivo_rechazo ? " (motivo: {$registro->motivo_rechazo})" : '')
            .'. Ya no se puede usar: revisa los datos o avisa a tu supervisor.';
    }

    /**
     * Sede donde se registró: la única sede del actor en el permiso operativo.
     */
    private function sedeDelActor(User $actor, ?string $origen): ?int
    {
        $permiso = $origen !== null ? self::ORIGENES[$origen]['permiso'] : null;
        $sedes = $permiso === null ? null : $this->autorizador->sedesPermitidas($actor, $permiso);

        return is_array($sedes) && count($sedes) === 1 ? (int) $sedes[0] : null;
    }

    // ---------------------------------------------------------------- Verificar

    /**
     * Aceptar: el alta era correcta (y, si se mandan, se corrigen sus datos).
     *
     * @param  array<string, mixed>  $datos  campos de CAMPOS_AL_ACEPTAR
     */
    public function aceptar(User $actor, string $padron, Model $registro, array $datos): Model
    {
        $d = $this->definicion($padron);
        $this->exigirPendiente($registro);
        $datos = array_intersect_key($datos, array_flip(self::CAMPOS_AL_ACEPTAR[$padron]));

        DB::transaction(function () use ($actor, $padron, $registro, $datos) {
            if ($datos !== []) {
                $this->completar($actor, $padron, $registro, $datos);
            }
            $registro->forceFill(['verificacion' => Vehiculo::VERIFICADO, 'verificado_por' => $actor->id, 'verificado_en' => now(), 'motivo_rechazo' => null])->save();
        });

        $this->auditoria->auditar($actor, $d['permiso'].'.verificado', $registro, ['verificacion' => Vehiculo::PENDIENTE], ['verificacion' => Vehiculo::VERIFICADO]);

        return $registro;
    }

    /**
     * Rechazar: el alta no procede. Se queda para la historia (lo que ya se
     * registró con ella no cambia), pero la operación ya no la puede usar.
     */
    public function rechazar(User $actor, string $padron, Model $registro, mixed $motivo): Model
    {
        $d = $this->definicion($padron);
        $this->exigirPendiente($registro);
        $motivo = trim((string) preg_replace('/\s+/u', ' ', is_scalar($motivo) ? (string) $motivo : ''));
        if (mb_strlen($motivo) < 5) {
            throw ValidationException::withMessages(['motivo_rechazo' => 'Escribe el motivo del rechazo (mínimo 5 letras): así la caseta sabe qué corregir.']);
        }
        if (mb_strlen($motivo) > 255) {
            throw ValidationException::withMessages(['motivo_rechazo' => 'El motivo admite máximo 255 caracteres.']);
        }

        $registro->forceFill([
            'verificacion' => Vehiculo::RECHAZADO, 'activo' => false, 'motivo_rechazo' => $motivo,
            'verificado_por' => $actor->id, 'verificado_en' => now(),
        ])->save();

        $this->auditoria->auditar($actor, $d['permiso'].'.rechazado', $registro, ['verificacion' => Vehiculo::PENDIENTE, 'activo' => true],
            ['verificacion' => Vehiculo::RECHAZADO, 'activo' => false, 'motivo_rechazo' => $motivo]);

        return $registro;
    }

    /**
     * Unir con el registro correcto: todo lo que se registró con el alta
     * pendiente (accesos, movimientos de transporte, entregas...) pasa al
     * correcto, y el alta queda rechazada apuntando a él.
     */
    public function unir(User $actor, string $padron, Model $provisional, Model $destino): Model
    {
        $d = $this->definicion($padron);
        $this->exigirPendiente($provisional);
        if ($destino->is($provisional) || ! $destino->activo || $destino->verificacion !== Vehiculo::VERIFICADO
            || (int) $destino->empresa_id !== (int) $provisional->empresa_id) {
            throw ValidationException::withMessages(['destino_id' => 'Elige un registro activo y ya verificado del padrón (no puede ser el mismo).']);
        }

        $movidos = DB::transaction(function () use ($padron, $provisional, $destino, $actor) {
            $movidos = [];
            foreach (self::REFERENCIAS[$padron] as [$tabla, $columna]) {
                $movidos["{$tabla}.{$columna}"] = DB::table($tabla)->where($columna, $provisional->id)->update([$columna => $destino->id]);
            }
            // Empresa externa: el correcto también opera en las sedes del provisional
            if ($padron === 'proveedores' && ! $destino->todas_las_sedes) {
                $destino->sedes()->syncWithoutDetaching($provisional->sedes()->pluck('sedes.id')->all());
            }
            $folio = null;
            // Persona: si el correcto no tenía identificación, se queda con la que capturó la caseta
            if ($padron === 'personas' && $provisional->folio_identificacion !== null && $destino->folio_identificacion === null) {
                $folio = [$provisional->tipo_identificacion, $provisional->folio_identificacion];
                $provisional->forceFill(['folio_identificacion' => null, 'tipo_identificacion' => null]);
            }
            $provisional->forceFill([
                'verificacion' => Vehiculo::RECHAZADO, 'activo' => false, 'fusionado_en_id' => $destino->id,
                'motivo_rechazo' => mb_substr('Unido con «'.$this->titulo($padron, $destino).'»', 0, 255),
                'verificado_por' => $actor->id, 'verificado_en' => now(),
            ])->save();
            if ($folio !== null) {
                $destino->forceFill(['tipo_identificacion' => $folio[0], 'folio_identificacion' => $folio[1]])->save();
            }

            return $movidos;
        });

        $this->auditoria->auditar($actor, $d['permiso'].'.fusionado', $provisional,
            ['verificacion' => Vehiculo::PENDIENTE, 'titulo' => $this->titulo($padron, $provisional)],
            ['fusionado_en' => $destino->id, 'titulo' => $this->titulo($padron, $destino), 'referencias' => $movidos]);

        return $destino;
    }

    public function exigirPendiente(Model $registro): void
    {
        if (! $registro->estaPendiente()) {
            throw ValidationException::withMessages(['verificacion' => 'Este registro ya no está pendiente de verificar (alguien más ya lo revisó). Recarga la pantalla.']);
        }
    }

    /**
     * Un registro rechazado no se reactiva ni se edita: se queda como historia.
     */
    public function exigirNoRechazado(Model $registro, string $campo = 'activo'): void
    {
        if ($registro->estaRechazado()) {
            throw ValidationException::withMessages([$campo => 'Este registro fue rechazado al verificar el padrón'
                .($registro->fusionado_en_id ? ' (se unió con otro registro)' : '').': se conserva solo como historia y ya no se puede reactivar ni editar.']);
        }
    }

    /**
     * Corrige los datos del alta con las reglas normales del padrón.
     *
     * @param  array<string, mixed>  $datos
     */
    private function completar(User $actor, string $padron, Model $registro, array $datos): void
    {
        $base = $registro->only(match ($padron) {
            'vehiculos' => ['placas', 'propiedad', 'tipo', 'descripcion_otro', 'marca', 'modelo', 'color', 'capacidad', 'numero_economico', 'proveedor_id', 'colaborador_id'],
            'proveedores' => ['nombre', 'categoria', 'rfc', 'telefono', 'direccion'],
            default => ['tipo', 'categoria', 'nombre_completo', 'proveedor_id', 'empresa_procedencia', 'tipo_identificacion', 'folio_identificacion', 'telefono', 'motivo_visita'],
        });
        $entrada = array_merge($base, $datos);

        match ($padron) {
            'vehiculos' => app(AdministradorVehiculos::class)->actualizar($actor, (int) $registro->empresa_id, $registro, $entrada),
            'proveedores' => app(AdministradorProveedores::class)->actualizar($actor, $registro, Request::create('/', 'PUT', $entrada)),
            default => app(AdministradorPersonas::class)->actualizar($actor, $registro, Request::create('/', 'PUT', $entrada)),
        };
    }

    // --------------------------------------------------------------- Parecidos

    /**
     * "¿Es alguno de estos?": registros del padrón parecidos a lo capturado.
     * Compara sin mayúsculas, acentos, espacios ni guiones (placas sin
     * separadores y con O/0 e I/1 como iguales; razones sociales sin
     * "S.A. de C.V."), sobre un grupo acotado de candidatos de la empresa
     * (y de las sedes indicadas, en proveedores). Incluye los dados de baja
     * y los pendientes, marcados; nunca los rechazados ni los ya unidos.
     *
     * @param  array<string, mixed>  $datos  vehiculos: placas | proveedores: nombre | personas: nombre_completo, folio_identificacion
     * @param  list<int>|null  $sedes  solo proveedores: null = todas
     * @return list<array<string, mixed>>
     */
    public function parecidos(string $padron, array $datos, ?array $sedes = null, ?int $excepto = null, int $maximo = 5): array
    {
        $d = $this->definicion($padron);
        $consulta = $d['modelo']::query()
            ->where((new $d['modelo'])->getTable().'.verificacion', '!=', Vehiculo::RECHAZADO)
            ->whereNull((new $d['modelo'])->getTable().'.fusionado_en_id')
            ->when($excepto !== null, fn ($q) => $q->whereKeyNot($excepto));

        $puntuados = match ($padron) {
            'vehiculos' => $this->parecidosVehiculo($consulta, $this->texto($datos['placas'] ?? null)),
            'proveedores' => $this->parecidosProveedor($consulta->when($sedes !== null, fn ($q) => $q->operanEn($sedes)), $this->texto($datos['nombre'] ?? null)),
            default => $this->parecidosPersona($consulta, $this->texto($datos['nombre_completo'] ?? null), Persona::normalizarFolio($this->texto($datos['folio_identificacion'] ?? null))),
        };

        arsort($puntuados);
        $ids = array_slice(array_keys($puntuados), 0, $maximo);
        if ($ids === []) {
            return [];
        }
        $modelos = $d['modelo']::query()->whereKey($ids)->get()->keyBy('id');

        return array_values(array_filter(array_map(fn ($id) => isset($modelos[$id]) ? $this->resumen($padron, $modelos[$id]) : null, $ids)));
    }

    /**
     * @return array<int, float> id => puntaje
     */
    private function parecidosVehiculo(Builder $consulta, string $placas): array
    {
        $buscadas = $this->clavePlacas($placas);
        if (mb_strlen($buscadas) < 3) {
            return [];
        }
        $normal = Vehiculo::normalizarPlacas($placas);
        $consulta->where(function ($q) use ($normal) {
            $q->where('vehiculos.placas', 'like', $this->escapar(mb_substr($normal, 0, 2)).'%')
                ->orWhere('vehiculos.placas', 'like', '%'.$this->escapar(mb_substr($normal, -3)).'%')
                ->orWhere('vehiculos.placas', 'like', '%'.$this->escapar(mb_substr($normal, 1, 3)).'%');
        });

        $puntuados = [];
        foreach ($consulta->limit(300)->get(['vehiculos.id', 'vehiculos.placas']) as $v) {
            $puntaje = $this->similitud($buscadas, $this->clavePlacas($v->placas), mb_strlen($buscadas) <= 5 ? 1 : 2);
            if ($puntaje !== null) {
                $puntuados[(int) $v->id] = $puntaje;
            }
        }

        return $puntuados;
    }

    /**
     * @return array<int, float>
     */
    private function parecidosProveedor(Builder $consulta, string $nombre): array
    {
        $buscado = $this->claveEmpresa($nombre);
        if (mb_strlen($buscado) < 3) {
            return [];
        }
        $consulta->where(function ($q) use ($buscado) {
            foreach (array_slice(array_filter(explode(' ', $buscado), fn ($p) => mb_strlen($p) >= 3), 0, 3) as $palabra) {
                $q->orWhereRaw('LOWER(proveedores.nombre) LIKE ?', ['%'.$this->escapar(mb_substr($palabra, 0, 3)).'%']);
            }
            if (mb_strlen($buscado) < 6) {
                $q->orWhereRaw('LOWER(proveedores.nombre) LIKE ?', [$this->escapar(mb_substr($buscado, 0, 2)).'%']);
            }
        });

        $puntuados = [];
        foreach ($consulta->limit(300)->get(['proveedores.id', 'proveedores.nombre']) as $p) {
            $puntaje = $this->similitud($buscado, $this->claveEmpresa($p->nombre), 2, true);
            if ($puntaje !== null) {
                $puntuados[(int) $p->id] = $puntaje;
            }
        }

        return $puntuados;
    }

    /**
     * @return array<int, float>
     */
    private function parecidosPersona(Builder $consulta, string $nombre, ?string $folio): array
    {
        $buscado = $this->claveNombre($nombre);
        $palabras = array_values(array_filter(explode(' ', $buscado), fn ($p) => mb_strlen($p) >= 3));
        if (mb_strlen($buscado) < 3 && $folio === null) {
            return [];
        }
        $consulta->where(function ($q) use ($palabras, $folio) {
            foreach (array_slice($palabras, 0, 3) as $palabra) {
                $q->orWhereRaw('LOWER(personas.nombre_completo) LIKE ?', ['%'.$this->escapar(mb_substr($palabra, 0, 3)).'%']);
            }
            if ($folio !== null) {
                $q->orWhere('personas.folio_identificacion', $folio);
            }
        });

        $puntuados = [];
        foreach ($consulta->limit(300)->get(['personas.id', 'personas.nombre_completo', 'personas.folio_identificacion']) as $p) {
            if ($folio !== null && $p->folio_identificacion === $folio) {
                $puntuados[(int) $p->id] = 200.0;

                continue;
            }
            if (mb_strlen($buscado) < 3) {
                continue;
            }
            $puntaje = $this->similitud($buscado, $this->claveNombre($p->nombre_completo), 2, true);
            if ($puntaje !== null) {
                $puntuados[(int) $p->id] = $puntaje;
            }
        }

        return $puntuados;
    }

    /**
     * Puntaje de 0 a 100 si se parecen (null si no): iguales, a pocas letras
     * de distancia, muy parecidos (similar_text) o, con $palabras, con las
     * mismas palabras en otro orden o una contenida en la otra.
     */
    private function similitud(string $a, string $b, int $distancia, bool $palabras = false): ?float
    {
        if ($b === '') {
            return null;
        }
        if ($a === $b) {
            return 100.0;
        }
        if ($palabras) {
            $pa = explode(' ', $a);
            $pb = explode(' ', $b);
            sort($pa);
            sort($pb);
            if ($pa === $pb) {
                return 98.0;
            }
            $menor = count($pa) <= count($pb) ? $pa : $pb;
            $mayor = count($pa) <= count($pb) ? $pb : $pa;
            if (count($menor) >= 2 && array_diff($menor, $mayor) === []) {
                return 90.0;
            }
            $a = implode(' ', $pa);
            $b = implode(' ', $pb);
        }
        if (strlen($a) <= 255 && strlen($b) <= 255) {
            $lev = levenshtein($a, $b);
            if ($lev <= $distancia) {
                return 95.0 - $lev;
            }
        }
        similar_text($a, $b, $porcentaje);
        if ($porcentaje >= 82) {
            return round($porcentaje * 0.9, 1);
        }
        // Una dentro de la otra ("PEPSICO" y "PEPSICO MEXICO")
        $corta = strlen($a) <= strlen($b) ? $a : $b;
        $larga = strlen($a) <= strlen($b) ? $b : $a;
        if ($palabras && strlen($corta) >= 5 && str_contains(' '.$larga.' ', ' '.$corta.' ')) {
            return 80.0;
        }

        return null;
    }

    /** Placas sin separadores, con O/0 e I/1 tratados igual (se confunden al leerlas). */
    private function clavePlacas(?string $placas): string
    {
        return strtr((string) preg_replace('/[^A-Z0-9]/', '', strtr(Vehiculo::normalizarPlacas($placas), ['Ñ' => 'N'])), ['O' => '0', 'Q' => '0', 'I' => '1']);
    }

    /** minúsculas, sin acentos, guiones ni signos, espacios simples. */
    private function claveNombre(?string $texto): string
    {
        $texto = mb_strtolower(strtr((string) $texto, self::SIN_ACENTOS));

        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9]+/', ' ', $texto)));
    }

    /** Como claveNombre, sin "S.A. de C.V." y similares al final. */
    private function claveEmpresa(?string $texto): string
    {
        $clave = $this->claveNombre($texto);
        foreach (self::SOCIEDADES as $sociedad) {
            if (str_ends_with($clave, ' '.$sociedad)) {
                return trim(mb_substr($clave, 0, -mb_strlen($sociedad) - 1));
            }
        }

        return $clave;
    }

    private function escapar(string $texto): string
    {
        return addcslashes($texto, '%_\\');
    }

    private function texto(mixed $valor): string
    {
        return is_scalar($valor) ? trim((string) $valor) : '';
    }

    // ------------------------------------------------------------------ Lectura

    public function titulo(string $padron, Model $registro): string
    {
        return (string) match ($padron) {
            'vehiculos' => $registro->placas,
            'proveedores' => $registro->nombre,
            default => $registro->nombre_completo,
        };
    }

    /**
     * Lo que se devuelve en JSON: el resumen de cada padrón (el mismo que
     * usan sus búsquedas) más el estado de verificación.
     *
     * @return array<string, mixed>
     */
    public function resumen(string $padron, Model $registro): array
    {
        $base = match ($padron) {
            'vehiculos' => app(AdministradorVehiculos::class)->resumen($registro) + $registro->only(['tipo', 'marca', 'modelo', 'color']),
            'proveedores' => app(AdministradorProveedores::class)->resumen($registro),
            default => app(AdministradorPersonas::class)->resumen($registro),
        };
        $detalle = match ($padron) {
            'vehiculos' => implode(' · ', array_filter([$base['descripcion'] ?? null, $base['propiedad_etiqueta'] ?? null])),
            'proveedores' => $base['categoria_etiqueta'] ?? '',
            default => implode(' · ', array_filter([$base['tipo_etiqueta'] ?? null, $base['empresa'] ?? null, $base['folio'] ?? null])),
        };
        $estado = match (true) {
            ! $registro->activo => 'Dado de baja',
            $registro->estaPendiente() => 'Pendiente de verificar',
            default => null,
        };

        return $base + [
            'titulo' => $this->titulo($padron, $registro),
            'detalle' => $detalle,
            'activo' => (bool) $registro->activo,
            'verificacion' => $registro->verificacion,
            'estado_texto' => $estado === 'Dado de baja' && $this->definicion($padron)['genero'] === 'f' ? 'Dada de baja' : $estado,
            'usable' => (bool) $registro->activo && ! $registro->estaRechazado(),
        ];
    }

    /**
     * Datos del diálogo "Verificar alta" de cada ficha.
     *
     * @param  array<int, string>  $nombres  id de usuario => nombre (para no consultar por ficha)
     * @param  array<int, string>  $sedes  id de sede => nombre
     * @return array<string, mixed>
     */
    public function datosDialogo(string $padron, Model $registro, array $nombres, array $sedes): array
    {
        $origen = $registro->origen_alta !== null ? (self::ORIGENES[$registro->origen_alta]['nombre'] ?? null) : null;

        return [
            'id' => (int) $registro->id,
            'titulo' => $this->titulo($padron, $registro),
            'registro' => trim(implode(' · ', array_filter([
                isset($nombres[(int) $registro->creado_por]) ? 'Registró: '.$nombres[(int) $registro->creado_por] : null,
                app(HoraLocal::class)->formatear($registro->created_at),
                $origen ? 'desde '.$origen : null,
                $registro->sede_alta_id && isset($sedes[(int) $registro->sede_alta_id]) ? 'Sede '.$sedes[(int) $registro->sede_alta_id] : null,
            ]))),
            'valores' => $registro->only(self::CAMPOS_AL_ACEPTAR[$padron]),
            'aceptar' => route($padron.'.aceptar', $registro->id),
            'rechazar' => route($padron.'.rechazar', $registro->id),
            'unir' => route($padron.'.unir', $registro->id),
            'parecidos' => route('altas_por_verificar.parecidos', ['padron' => $padron, 'de' => $registro->id]),
        ];
    }

    /**
     * Lo que la lista de un padrón necesita para las altas por verificar:
     * ¿puede verificar?, cuáles puede verificar, cuántas pendientes hay en la
     * lista y los datos del diálogo de cada una (sin consultas por ficha).
     *
     * @param  iterable<Model>  $registros
     * @return array{padron: string, puede: bool, verificables: list<int>, pendientes: int, dialogos: array<int, array<string, mixed>>}
     */
    public function paraLista(string $padron, iterable $registros, ?User $actor): array
    {
        $puede = $actor !== null && $this->puedeVerificar($actor, $padron);
        $verificables = $puede ? $this->idsVerificables($actor, $padron) : [];
        [$nombres, $sedes] = $puede ? $this->catalogosDialogo($registros) : [[], []];
        $pendientes = 0;
        $dialogos = [];
        foreach ($registros as $r) {
            if (! $r->estaPendiente()) {
                continue;
            }
            $pendientes++;
            if (in_array((int) $r->id, $verificables, true)) {
                $dialogos[(int) $r->id] = $this->datosDialogo($padron, $r, $nombres, $sedes);
            }
        }

        return ['padron' => $padron, 'puede' => $puede, 'verificables' => $verificables, 'pendientes' => $pendientes, 'dialogos' => $dialogos];
    }

    /**
     * Nombres de quienes registraron y de las sedes, para pintar varias fichas sin N+1.
     *
     * @param  iterable<Model>  $registros
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    public function catalogosDialogo(iterable $registros): array
    {
        $usuarios = [];
        $sedes = [];
        foreach ($registros as $r) {
            if ($r->estaPendiente()) {
                $usuarios[] = (int) $r->creado_por;
                $sedes[] = (int) $r->sede_alta_id;
            }
        }

        return [
            $usuarios === [] ? [] : User::whereIn('id', array_unique($usuarios))->pluck('name', 'id')->all(),
            $sedes === [] ? [] : Sede::whereIn('id', array_unique($sedes))->pluck('nombre', 'id')->all(),
        ];
    }
}
