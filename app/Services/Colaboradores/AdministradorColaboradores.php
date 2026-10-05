<?php

namespace App\Services\Colaboradores;

use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Puesto;
use App\Models\Sede;
use App\Models\User;
use App\Services\Avisos\AvisosCorreo;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de Colaboradores (réplica de colaborador_proceso.php y
 * colaborador_registro_rapido_proceso.php de SEGCAT), compartidas por la
 * pantalla, el registro rápido y la búsqueda.
 *
 * Todas las consultas corren con la empresa de trabajo ya fijada en el
 * Tenant: el filtro de empresa lo pone el modelo, no el programador.
 */
class AdministradorColaboradores
{
    /**
     * Tablas que guardan un colaborador: al unir un alta provisional con el
     * registro correcto, aquí se cambia la referencia. Cada módulo nuevo que
     * guarde colaborador_id (préstamos, accesos, responsivas...) se agrega.
     *
     * @var array<string, string> tabla => columna
     */
    public const REFERENCIAS = [
        'users' => 'colaborador_id',
        'vehiculos' => 'colaborador_id',
        'llaves' => 'colaborador_id',
        'vouchers_reposicion' => 'colaborador_id',
        'prestamos_llaves' => 'colaborador_id',
        'responsivas' => 'colaborador_id',
    ];

    private const SIN_ACENTOS = ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u', 'ü' => 'u', 'Á' => 'a', 'É' => 'e', 'Í' => 'i', 'Ó' => 'o', 'Ú' => 'u', 'Ü' => 'u'];

    public function __construct(
        private readonly Autorizador $autorizador,
        private readonly AdministradorRoles $auditoria,
    ) {}

    // ------------------------------------------------------------------ Alcance

    /**
     * Limita una consulta al alcance que el actor tiene en un permiso: toda la
     * empresa, los colaboradores con presencia (sede física o adicional) en
     * sus sedes, o solo los que él dio de alta.
     *
     * @param  Builder<Colaborador>  $consulta
     * @return Builder<Colaborador>
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
        if ($efectivo->alcance === Alcance::Empresa) {
            return $consulta;
        }
        if ($efectivo->sedes !== null) {
            $consulta->enSedes($efectivo->sedes);
        }
        if ($efectivo->alcance === Alcance::Propios) {
            $consulta->where('colaboradores.creado_por', $actor->id);
        }

        return $consulta;
    }

    /**
     * Ids dentro del alcance de un permiso; null = todos los visibles.
     *
     * @return list<int>|null
     */
    public function idsEnAlcance(User $actor, string $permiso): ?array
    {
        if (! $actor->can($permiso)) {
            return [];
        }
        if ($actor->es_superadmin) {
            return null;
        }

        $efectivo = $this->autorizador->permisosEfectivos($actor)[$permiso];
        if ($efectivo->alcance === Alcance::Empresa || ($efectivo->sedes === null && $efectivo->alcance !== Alcance::Propios)) {
            return null;
        }

        return $this->limitar(Colaborador::query(), $actor, $permiso)->pluck('colaboradores.id')->map(fn ($id) => (int) $id)->all();
    }

    /**
     * @return list<int>|null null = todas las sedes de la empresa
     */
    public function sedesPermitidas(User $actor, string $permiso): ?array
    {
        return $this->autorizador->sedesPermitidas($actor, $permiso);
    }

    /**
     * Catálogos para los formularios (alta, edición, registro rápido).
     *
     * @return array{sedes: Collection<int, Sede>, sedesPermitidas: list<int>|null, departamentos: Collection<int, Departamento>, puestos: Collection<int, Puesto>}
     */
    public function catalogos(User $actor, string $permiso): array
    {
        $permitidas = $this->sedesPermitidas($actor, $permiso);

        return [
            // Todas las de la empresa (la vista decide: alta = activas y permitidas;
            // edición = además la actual, aunque esté desactivada o fuera de su alcance)
            'sedes' => Sede::orderBy('nombre')->get(['id', 'nombre', 'activo']),
            'sedesPermitidas' => $permitidas,
            'departamentos' => Departamento::with('sedes:id')->orderBy('nombre')->get(['id', 'nombre', 'todas_las_sedes', 'activo']),
            'puestos' => Puesto::with('departamentos:id')->orderBy('nombre')->get(['id', 'nombre', 'activo']),
        ];
    }

    // ----------------------------------------------------------------- Escritura

    /**
     * Alta (pantalla o registro rápido). $rapido: solo los datos esenciales y la sede es obligatoria.
     */
    public function crear(User $actor, int $empresaId, Request $request, bool $rapido = false): Colaborador
    {
        $conDatos = ! $rapido && $actor->can('colaboradores.datos_personales');
        $datos = $this->validar($request, $actor, $empresaId, 'colaboradores.crear', null, $conDatos, $rapido);

        $colaborador = DB::transaction(fn () => Colaborador::create($datos + ['empresa_id' => $empresaId]));

        $this->auditoria->auditar($actor, 'colaboradores.creado', $colaborador, null, $this->foto($colaborador));

        return $colaborador;
    }

    /**
     * Alta provisional desde la caseta: la persona aún no está en el directorio
     * y hay que registrarla para no detener la operación. Queda marcada como
     * provisional, se puede usar de inmediato en su sede y Recursos Humanos la
     * valida (completa su número de empleado) o la une con el registro correcto.
     *
     * Si ya hay alguien activo con el mismo nombre, se detiene y lo propone
     * (para no duplicar), salvo que el guardia confirme que es otra persona.
     */
    public function crearProvisional(User $actor, int $empresaId, Request $request): Colaborador
    {
        $datos = $this->validar($request, $actor, $empresaId, 'colaboradores.provisional', null, false, true, provisional: true);

        if (! $request->boolean('confirmar_nuevo')) {
            $parecidos = $this->parecidos($datos['nombre'], $datos['apellido_paterno'], $datos['apellido_materno']);
            if ($parecidos !== []) {
                throw new ColaboradorParecido($parecidos);
            }
        }

        $colaborador = DB::transaction(function () use ($datos, $empresaId) {
            $nuevo = new Colaborador($datos + ['empresa_id' => $empresaId]);
            $nuevo->forceFill(['provisional' => true])->save();

            return $nuevo;
        });

        $this->auditoria->auditar($actor, 'colaboradores.provisional', $colaborador, null, $this->foto($colaborador));
        app(AvisosCorreo::class)->altaProvisional($colaborador, $actor);

        return $colaborador;
    }

    /**
     * Recursos Humanos valida un alta provisional: confirma o corrige sus datos
     * y le asigna su número de empleado. Desde ese momento es un colaborador normal.
     */
    public function validarProvisional(User $actor, int $empresaId, Colaborador $colaborador, Request $request): Colaborador
    {
        if (! $colaborador->provisional || $colaborador->fusionado_en_id !== null) {
            throw ValidationException::withMessages(['num_empleado' => 'Este colaborador ya no está pendiente de validar.']);
        }

        $antes = $this->foto($colaborador);
        $datos = $this->validar($request, $actor, $empresaId, 'colaboradores.aprobar', $colaborador, false, false);

        DB::transaction(fn () => $colaborador->fill($datos)->forceFill([
            'provisional' => false, 'validado_por' => $actor->id, 'validado_en' => now(), 'activo' => true,
        ])->save());

        $this->auditoria->auditar($actor, 'colaboradores.validado', $colaborador, $antes, $this->foto($colaborador));

        return $colaborador;
    }

    /**
     * El alta provisional era alguien que ya existía: todo lo que se registró
     * con el provisional pasa al colaborador correcto y el provisional queda
     * dado de baja, apuntando al registro que lo sustituye.
     *
     * Cada tabla que guarde un colaborador_id debe agregarse a REFERENCIAS.
     */
    public function fusionar(User $actor, Colaborador $provisional, Colaborador $destino): void
    {
        if (! $provisional->provisional || $provisional->fusionado_en_id !== null) {
            throw ValidationException::withMessages(['destino_id' => 'Este colaborador ya no está pendiente de validar.']);
        }
        if ($destino->id === $provisional->id || $destino->provisional || ! $destino->activo || $destino->empresa_id !== $provisional->empresa_id) {
            throw ValidationException::withMessages(['destino_id' => 'Elige un colaborador activo y ya validado.']);
        }

        $movidos = DB::transaction(function () use ($provisional, $destino) {
            $movidos = [];
            foreach (self::REFERENCIAS as $tabla => $columna) {
                $consulta = DB::table($tabla)->where($columna, $provisional->id);
                // Una cuenta de usuario por colaborador: si el correcto ya tiene una, la del provisional se desvincula
                if ($tabla === 'users' && DB::table('users')->where('colaborador_id', $destino->id)->exists()) {
                    $movidos[$tabla] = (clone $consulta)->update(['colaborador_id' => null]);

                    continue;
                }
                $movidos[$tabla] = $consulta->update([$columna => $destino->id]);
            }
            $provisional->forceFill(['activo' => false, 'fusionado_en_id' => $destino->id])->save();

            return $movidos;
        });

        $this->auditoria->auditar($actor, 'colaboradores.fusionado', $provisional,
            ['provisional' => $provisional->only(['nombre', 'apellido_paterno', 'apellido_materno', 'sede_id'])],
            ['fusionado_en' => $destino->id, 'num_empleado' => $destino->num_empleado, 'referencias' => $movidos]);
    }

    /**
     * Activos con el mismo nombre completo (sin importar mayúsculas ni acentos).
     *
     * @return list<array<string, mixed>>
     */
    public function parecidos(string $nombre, string $paterno, ?string $materno): array
    {
        $clave = fn (?string $t) => mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', strtr((string) $t, self::SIN_ACENTOS))));
        $buscado = $clave($nombre.' '.$paterno);

        return Colaborador::query()->where('activo', true)->whereNull('fusionado_en_id')
            // Primero acota por la inicial del apellido (con o sin acento) y luego compara en PHP sin acentos
            ->where(function ($q) use ($paterno, $clave) {
                $inicial = mb_substr($clave($paterno), 0, 1);
                foreach (array_unique([$inicial, array_search($inicial, ['á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u'], true) ?: $inicial]) as $letra) {
                    $q->orWhereRaw('LOWER(apellido_paterno) LIKE ?', [$letra.'%']);
                }
            })
            ->limit(200)->get()
            ->filter(fn (Colaborador $c) => $clave($c->nombre.' '.$c->apellido_paterno) === $buscado
                && ($materno === null || $c->apellido_materno === null || $clave($c->apellido_materno) === $clave($materno)))
            ->take(5)
            ->map(fn (Colaborador $c) => $this->resumen($c))
            ->values()->all();
    }

    public function actualizar(User $actor, int $empresaId, Colaborador $colaborador, Request $request): Colaborador
    {
        $conDatos = $actor->can('colaboradores.datos_personales');
        $antes = $this->foto($colaborador);
        $datosAntes = $colaborador->only(Colaborador::DATOS_PERSONALES);

        $datos = $this->validar($request, $actor, $empresaId, 'colaboradores.editar', $colaborador, $conDatos, false);

        DB::transaction(function () use ($colaborador, $datos) {
            $colaborador->fill($datos)->save();
            // La sede física no se repite como sede adicional
            if ($colaborador->sede_id !== null) {
                $colaborador->sedesAdicionales()->detach($colaborador->sede_id);
            }
        });

        $despues = $this->foto($colaborador);
        $cambiados = array_keys(array_filter(
            $colaborador->only(Colaborador::DATOS_PERSONALES),
            fn ($valor, $campo) => (string) $this->comparable($valor) !== (string) $this->comparable($datosAntes[$campo] ?? null),
            ARRAY_FILTER_USE_BOTH,
        ));
        if ($cambiados !== []) {
            $despues['datos_personales_modificados'] = $cambiados;
        }

        $this->auditoria->auditar($actor, 'colaboradores.actualizado', $colaborador, $antes, $despues);

        return $colaborador;
    }

    public function cambiarEstado(User $actor, Colaborador $colaborador, bool $activo): void
    {
        $colaborador->forceFill(['activo' => $activo])->save();
        $this->auditoria->auditar($actor, $activo ? 'colaboradores.reactivado' : 'colaboradores.desactivado', $colaborador, ['activo' => ! $activo], ['activo' => $activo]);
    }

    /**
     * Sedes adicionales (además de la física). Quien tiene alcance de sede
     * solo marca o desmarca sus sedes; las demás se conservan.
     *
     * @param  array<mixed>  $seleccion
     */
    public function actualizarSedes(User $actor, Colaborador $colaborador, array $seleccion): void
    {
        $antes = $colaborador->sedesAdicionales()->pluck('sedes.id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        $permitidas = $this->sedesPermitidas($actor, 'colaboradores.editar');

        $validas = Sede::where('activo', true)->whereIn('id', array_map('intval', $seleccion))->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($permitidas !== null) {
            $validas = array_values(array_intersect($validas, $permitidas));
        }

        // Se conservan las que el actor no puede tocar: fuera de su alcance o de sedes desactivadas
        $activas = Sede::where('activo', true)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $conservar = array_filter($antes, fn ($id) => ! in_array($id, $activas, true) || ($permitidas !== null && ! in_array($id, $permitidas, true)));

        $final = array_values(array_diff(array_unique([...$conservar, ...$validas]), [(int) $colaborador->sede_id]));
        sort($final);

        $colaborador->sedesAdicionales()->sync($final);
        $colaborador->touch();

        $this->auditoria->auditar($actor, 'colaboradores.sedes', $colaborador, ['sedes_adicionales' => $antes], ['sedes_adicionales' => $final]);
    }

    // --------------------------------------------------------------- Validación

    /**
     * @return array<string, mixed> columnas listas para guardar
     */
    private function validar(Request $request, User $actor, int $empresaId, string $permiso, ?Colaborador $actual, bool $conDatos, bool $rapido, bool $provisional = false): array
    {
        $entrada = $this->normalizar($request->all());

        $unico = fn (string $campo) => Rule::unique('colaboradores', $campo)->where('empresa_id', $empresaId)->ignore($actual?->id);

        $reglas = [
            // En un alta provisional el guardia puede no conocerlo: lo asigna Recursos Humanos
            'num_empleado' => [$provisional ? 'nullable' : 'required', 'string', 'max:20', $unico('num_empleado')],
            'nombre' => ['required', 'string', 'max:60'],
            'apellido_paterno' => ['required', 'string', 'max:60'],
            'apellido_materno' => ['nullable', 'string', 'max:60'],
            'telefono' => ['nullable', 'string', 'regex:/^\d{10,15}$/'],
            'sede_id' => [$rapido ? 'required' : 'nullable', 'integer'],
            'departamento_id' => ['nullable', 'integer'],
            'puesto_id' => ['nullable', 'integer'],
        ];

        // Datos personales: solo con permiso; al editar, solo los que llegaron
        // (si el formulario no los cargó, no se borran).
        $personales = [];
        if ($conDatos) {
            $estados = Colaborador::ESTADOS_NACIMIENTO;
            if ($actual?->lugar_nacimiento !== null) {
                $estados[] = $actual->lugar_nacimiento;
            }
            $todas = [
                'curp' => ['nullable', 'string', 'regex:'.Colaborador::CURP, $unico('curp')],
                'rfc' => ['nullable', 'string', 'regex:'.Colaborador::RFC, $unico('rfc')],
                'nss' => ['nullable', 'string', 'regex:'.Colaborador::NSS, $unico('nss')],
                'fecha_nacimiento' => ['nullable', 'date_format:Y-m-d', 'before:today', 'after:1900-01-01'],
                'lugar_nacimiento' => ['nullable', 'string', Rule::in($estados)],
                'nacionalidad' => ['nullable', 'string', 'max:40'],
                'correo_personal' => ['nullable', 'email:rfc', 'max:150'],
                'direccion_completa' => ['nullable', 'string', 'max:500'],
            ];
            foreach ($todas as $campo => $regla) {
                if ($actual === null || array_key_exists($campo, $entrada)) {
                    $reglas[$campo] = $regla;
                    $personales[] = $campo;
                }
            }
        }

        $validados = Validator::make($entrada, $reglas, [
            'num_empleado.unique' => 'Ya existe otro colaborador registrado con ese número de empleado.',
            'curp.unique' => 'Ya existe otro colaborador registrado con ese CURP.',
            'rfc.unique' => 'Ya existe otro colaborador registrado con ese RFC.',
            'nss.unique' => 'Ya existe otro colaborador registrado con ese NSS.',
            'curp.regex' => 'El CURP no tiene el formato correcto (18 caracteres, ej. ABCD123456HDFXYZ01).',
            'rfc.regex' => 'El RFC no tiene el formato correcto (12 o 13 caracteres, ej. ABCD123456XYZ).',
            'nss.regex' => 'El NSS debe tener exactamente 11 dígitos.',
            'telefono.regex' => 'El teléfono debe tener de 10 a 15 dígitos.',
            'lugar_nacimiento.in' => 'Elige el estado de nacimiento de la lista.',
            'fecha_nacimiento.before' => 'La fecha de nacimiento debe ser anterior a hoy.',
            'fecha_nacimiento.after' => 'Revisa la fecha de nacimiento.',
            'sede_id.required' => 'Elige la sede del colaborador.',
        ], [
            'num_empleado' => 'número de empleado',
            'nombre' => 'nombre(s)',
            'apellido_paterno' => 'apellido paterno',
            'apellido_materno' => 'apellido materno',
            'telefono' => 'teléfono',
            'fecha_nacimiento' => 'fecha de nacimiento',
            'lugar_nacimiento' => 'estado de nacimiento',
            'correo_personal' => 'correo personal',
            'direccion_completa' => 'dirección completa',
        ])->validate();

        $sedeId = $this->sedeValida($validados['sede_id'] ?? null, $actor, $permiso, $actual);
        $departamentoId = $this->departamentoValido($validados['departamento_id'] ?? null, $sedeId, $actual);
        $puestoId = $this->puestoValido($validados['puesto_id'] ?? null, $departamentoId, $actual);

        $datos = [
            'num_empleado' => $validados['num_empleado'] ?? null,
            'nombre' => $validados['nombre'],
            'apellido_paterno' => $validados['apellido_paterno'],
            'apellido_materno' => $validados['apellido_materno'] ?? null,
            'telefono' => $validados['telefono'] ?? null,
            'sede_id' => $sedeId,
            'departamento_id' => $departamentoId,
            'puesto_id' => $puestoId,
        ];

        foreach ($personales as $campo) {
            $datos[$campo] = $validados[$campo] ?? null;
        }

        return $datos;
    }

    /**
     * Mayúsculas en CURP/RFC, sin espacios ni guiones en CURP/RFC/NSS,
     * teléfono solo con dígitos, espacios dobles fuera y vacíos como nulos.
     *
     * @param  array<string, mixed>  $entrada
     * @return array<string, mixed>
     */
    private function normalizar(array $entrada): array
    {
        $texto = fn ($v) => is_string($v) ? trim((string) preg_replace('/\s+/u', ' ', $v)) : $v;

        foreach ($entrada as $campo => $valor) {
            $entrada[$campo] = $texto($valor);
        }
        foreach (['curp', 'rfc', 'nss'] as $campo) {
            if (isset($entrada[$campo]) && is_string($entrada[$campo])) {
                $entrada[$campo] = mb_strtoupper(str_replace([' ', '-'], '', $entrada[$campo]));
            }
        }
        if (isset($entrada['num_empleado']) && is_string($entrada['num_empleado'])) {
            $entrada['num_empleado'] = mb_strtoupper($entrada['num_empleado']);
        }
        if (isset($entrada['telefono']) && is_string($entrada['telefono'])) {
            $entrada['telefono'] = preg_replace('/[\s\-().+]/', '', $entrada['telefono']);
        }
        if (isset($entrada['correo_personal']) && is_string($entrada['correo_personal'])) {
            $entrada['correo_personal'] = mb_strtolower($entrada['correo_personal']);
        }

        return array_map(fn ($v) => $v === '' ? null : $v, $entrada);
    }

    private function sedeValida(mixed $sedeId, User $actor, string $permiso, ?Colaborador $actual): ?int
    {
        $sedeId = $sedeId === null ? null : (int) $sedeId;
        $sinCambio = $actual !== null && $sedeId === ($actual->sede_id === null ? null : (int) $actual->sede_id);

        if ($sedeId !== null && ! $sinCambio && ! Sede::where('activo', true)->whereKey($sedeId)->exists()) {
            throw ValidationException::withMessages(['sede_id' => 'La sede no existe en esta empresa o está desactivada.']);
        }

        $permitidas = $this->sedesPermitidas($actor, $permiso);
        if ($permitidas !== null && ! $sinCambio && ($sedeId === null || ! in_array($sedeId, $permitidas, true))) {
            throw ValidationException::withMessages(['sede_id' => 'Elige una de tus sedes: solo puedes registrar colaboradores en ellas.']);
        }

        return $sedeId;
    }

    private function departamentoValido(mixed $id, ?int $sedeId, ?Colaborador $actual): ?int
    {
        if ($id === null) {
            return null;
        }
        $id = (int) $id;
        $departamento = Departamento::find($id);
        if ($departamento === null || (! $departamento->activo && $id !== (int) $actual?->departamento_id)) {
            throw ValidationException::withMessages(['departamento_id' => 'El departamento no existe en esta empresa o está desactivado.']);
        }
        if ($sedeId !== null && ! Departamento::whereKey($id)->aplicanEn([$sedeId])->exists()) {
            throw ValidationException::withMessages(['departamento_id' => "El departamento «{$departamento->nombre}» no aplica en la sede elegida."]);
        }

        return $id;
    }

    private function puestoValido(mixed $id, ?int $departamentoId, ?Colaborador $actual): ?int
    {
        if ($id === null) {
            return null;
        }
        $id = (int) $id;
        $puesto = Puesto::find($id);
        if ($puesto === null || (! $puesto->activo && $id !== (int) $actual?->puesto_id)) {
            throw ValidationException::withMessages(['puesto_id' => 'El puesto no existe en esta empresa o está desactivado.']);
        }

        // Si el puesto está ligado a departamentos, el elegido debe ser uno de ellos
        $ligados = $puesto->departamentos()->pluck('departamentos.id')->map(fn ($d) => (int) $d)->all();
        if ($departamentoId !== null && $ligados !== [] && ! in_array($departamentoId, $ligados, true)) {
            throw ValidationException::withMessages(['puesto_id' => "El puesto «{$puesto->nombre}» no aplica en el departamento elegido."]);
        }

        return $id;
    }

    // ------------------------------------------------------------------ Lectura

    /**
     * Búsqueda para autocompletar (por número de empleado o por nombre).
     *
     * @param  list<int>|null  $sedes  null = todas
     * @return array{resultados: list<array<string, mixed>>, todas_ya_tienen_usuario: bool}
     */
    public function buscar(string $texto, ?array $sedes, bool $sinUsuario, ?int $exceptoUsuario = null): array
    {
        $texto = trim((string) preg_replace('/\s+/u', ' ', $texto));
        if (mb_strlen($texto) < 2) {
            return ['resultados' => [], 'todas_ya_tienen_usuario' => false];
        }

        $comodin = fn (string $t) => '%'.addcslashes(mb_strtolower($t), '%_\\').'%';
        $consulta = Colaborador::query()
            ->where('colaboradores.activo', true)
            ->when($sedes !== null, fn ($q) => $q->enSedes($sedes))
            ->where(function ($q) use ($texto, $comodin) {
                $q->whereRaw('LOWER(colaboradores.num_empleado) LIKE ?', [addcslashes(mb_strtolower($texto), '%_\\').'%']);
                $q->orWhere(function ($nombre) use ($texto, $comodin) {
                    foreach (explode(' ', $texto) as $palabra) {
                        $nombre->where(fn ($p) => $p->whereRaw('LOWER(colaboradores.nombre) LIKE ?', [$comodin($palabra)])
                            ->orWhereRaw('LOWER(colaboradores.apellido_paterno) LIKE ?', [$comodin($palabra)])
                            ->orWhereRaw('LOWER(colaboradores.apellido_materno) LIKE ?', [$comodin($palabra)]));
                    }
                });
            });

        $total = (clone $consulta)->count();

        if ($sinUsuario) {
            $consulta->whereDoesntHave('usuario', fn ($u) => $u->when($exceptoUsuario !== null, fn ($x) => $x->where('users.id', '!=', $exceptoUsuario)));
        }

        $resultados = $consulta->with(['puesto:id,nombre', 'departamento:id,nombre', 'sede:id,nombre'])
            ->orderBy('colaboradores.nombre')->orderBy('colaboradores.apellido_paterno')
            ->limit(15)
            ->get()
            ->map(fn (Colaborador $c) => $this->resumen($c))
            ->all();

        return ['resultados' => $resultados, 'todas_ya_tienen_usuario' => $resultados === [] && $total > 0];
    }

    /**
     * Lo que se devuelve en JSON (sin datos personales).
     *
     * @return array<string, mixed>
     */
    public function resumen(Colaborador $c): array
    {
        $c->loadMissing(['puesto:id,nombre', 'departamento:id,nombre', 'sede:id,nombre']);

        return [
            'id' => $c->id,
            'num_empleado' => $c->num_empleado,
            'nombre_completo' => $c->nombreCompleto(),
            'puesto' => $c->puesto?->nombre,
            'departamento' => $c->departamento?->nombre,
            'sede_id' => $c->sede_id,
            'sede' => $c->sede?->nombre,
            'provisional' => (bool) $c->provisional,
        ];
    }

    /**
     * Foto para la bitácora: los identificadores oficiales y el teléfono
     * van enmascarados; fecha, lugar, correo y dirección no se copian.
     *
     * @return array<string, mixed>
     */
    public function foto(Colaborador $c): array
    {
        return $c->only(['num_empleado', 'nombre', 'apellido_paterno', 'apellido_materno', 'sede_id', 'departamento_id', 'puesto_id', 'activo']) + [
            'telefono' => Colaborador::enmascarar($c->telefono),
            'curp' => Colaborador::enmascarar($c->curp),
            'rfc' => Colaborador::enmascarar($c->rfc),
            'nss' => Colaborador::enmascarar($c->nss),
            'sedes_adicionales' => $c->sedesAdicionales()->pluck('sedes.id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
        ];
    }

    private function comparable(mixed $valor): ?string
    {
        if ($valor instanceof \DateTimeInterface) {
            return $valor->format('Y-m-d');
        }

        return $valor === null ? null : (string) $valor;
    }
}
