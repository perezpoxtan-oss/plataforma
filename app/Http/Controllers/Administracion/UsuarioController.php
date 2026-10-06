<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\User;
use App\Rules\ContrasenaSegura;
use App\Services\Permisos\Autorizador;
use App\Services\Usuarios\AdministradorUsuarios;
use App\Services\Usuarios\HomonimosUsuarios;
use App\Support\Entrada;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Usuarios del sistema (réplica de modules/usuarios/usuario_lista.php de SEGCAT).
 */
class UsuarioController extends Controller
{
    public function __construct(
        private readonly AdministradorUsuarios $administrador,
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly Autorizador $autorizador,
        private readonly HomonimosUsuarios $homonimos,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('usuarios.ver');

        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);

        // Sin empresa elegida (Super Administrador en plantillas) no hay usuarios que mostrar
        if ($empresaId === null) {
            return view('administracion.usuarios.index', ['sinEmpresa' => true]);
        }

        $usuarios = $this->visibles($actor, $empresaId)
            ->with(['roles' => fn ($q) => $q->select('roles.id', 'roles.nombre', 'roles.nivel_jerarquia'), 'colaborador:id,activo'])
            ->leftJoin('users as uc', 'uc.id', '=', 'users.creado_por')
            ->leftJoin('users as ua', 'ua.id', '=', 'users.actualizado_por')
            ->select('users.*', 'uc.name as creado_por_nombre', 'ua.name as actualizado_por_nombre')
            ->orderByDesc('users.id')
            ->get();

        $sedes = $this->tenant->conEmpresa($empresaId, fn () => Sede::where('activo', true)->orderBy('nombre')->get(['id', 'nombre', 'empresa_id']));
        $nivelPropio = $actor->nivelJerarquia();
        $puedeDesbloquear = $actor->can('usuarios.desbloquear');

        // Cuentas bloqueadas que este usuario puede desbloquear (según el alcance del permiso)
        $desbloqueables = ! $puedeDesbloquear ? [] : $this->administrador
            ->limitarAlcance($this->visibles($actor, $empresaId), $actor, 'usuarios.desbloquear')
            ->where('users.bloqueado_hasta', '>', now())
            ->pluck('users.id')
            ->all();

        $roles = Rol::where('empresa_id', $empresaId)
            ->where('activo', true)
            ->when(! $actor->es_superadmin, fn ($q) => $q->where('nivel_jerarquia', '>', $nivelPropio))
            ->orderBy('nivel_jerarquia')
            ->get(['id', 'nombre', 'nivel_jerarquia']);

        return view('administracion.usuarios.index', [
            'sinEmpresa' => false,
            'usuarios' => $usuarios,
            'sedes' => $sedes->keyBy('id'),
            'roles' => $roles,
            'nivelPropio' => $nivelPropio,
            // Seguridad (AZ-01): sedes a las que puede asignar cuentas (null = todas)
            'sedesAsignables' => $actor->es_superadmin ? null : $this->autorizador->sedesPermitidas($actor, $actor->can('usuarios.crear') ? 'usuarios.crear' : 'usuarios.editar'),
            'desbloqueables' => $desbloqueables,
            'empresaNombre' => Empresa::whereKey($empresaId)->value('nombre_comercial'),
            'puede' => [
                'crear' => $actor->can('usuarios.crear'),
                'editar' => $actor->can('usuarios.editar'),
                'eliminar' => $actor->can('usuarios.eliminar'),
                'desbloquear' => $puedeDesbloquear,
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('usuarios.crear');

        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        $datos = $this->vincularColaborador($this->validar($request, $empresaId), $empresaId);
        [$rol, $sede] = $this->rolYSede($datos, $empresaId);
        $this->exigirConfirmacionHomonimo($request, $empresaId, $datos['name']);

        try {
            $this->administrador->crear($request->user(), $empresaId, $this->campos($datos), $rol, $sede);
        } catch (AuthorizationException $e) {
            return back()->withInput($request->except('password'))->with('error', $e->getMessage());
        }

        return redirect()->route('usuarios.index')->with('ok', 'Usuario creado correctamente.');
    }

    public function update(Request $request, User $usuario): RedirectResponse
    {
        $this->exigirDeLaEmpresa($request, $usuario);
        Gate::authorize('usuarios.editar');
        $empresaId = $this->exigirVisible($request, $usuario);

        $datos = $this->vincularColaborador($this->validar($request, $empresaId, $usuario), $empresaId, $usuario);
        [$rol, $sede] = $this->rolYSede($datos, $empresaId);
        $this->exigirConfirmacionHomonimo($request, $empresaId, $datos['name'], $usuario);

        try {
            $this->administrador->actualizar($request->user(), $usuario, $this->campos($datos) + [
                'activo' => $request->boolean('activo'),
            ], $rol, $sede);
        } catch (AuthorizationException $e) {
            return back()->withInput($request->except('password'))->with('error', $e->getMessage());
        }

        return redirect()->route('usuarios.index')->with('ok', 'Usuario actualizado correctamente.');
    }

    public function estado(Request $request, User $usuario): RedirectResponse
    {
        $this->exigirDeLaEmpresa($request, $usuario);
        Gate::authorize('usuarios.eliminar');
        $this->exigirVisible($request, $usuario);

        $activo = $request->boolean('activo');

        try {
            $this->administrador->cambiarEstado($request->user(), $usuario, $activo);
        } catch (AuthorizationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('usuarios.index')->with($activo ? 'ok' : 'aviso', $activo
            ? 'Usuario reactivado: ya puede iniciar sesión de nuevo.'
            : 'Usuario desactivado: ya no puede iniciar sesión y su sesión abierta se cerró. Puedes reactivarlo con el mismo botón.');
    }

    public function desbloquear(Request $request, User $usuario): RedirectResponse
    {
        $this->exigirDeLaEmpresa($request, $usuario);
        Gate::authorize('usuarios.desbloquear');
        $this->exigirVisible($request, $usuario);

        if (! $usuario->estaBloqueado()) {
            return redirect()->route('usuarios.index')->with('aviso', "«{$usuario->name}» no está bloqueado; ya puede entrar.");
        }

        try {
            $this->administrador->desbloquear($request->user(), $usuario);
        } catch (AuthorizationException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('usuarios.index')->with('ok', "«{$usuario->name}» desbloqueado; ya puede entrar.");
    }

    /**
     * Aviso en vivo del diálogo de alta y edición: usuarios con el mismo
     * nombre (sin importar mayúsculas ni acentos) y colaboradores con ese
     * nombre que aún no tienen cuenta, para sugerir vincularlos.
     * ?nombre=Daniela Canul May&excluir={id del usuario que se edita}
     */
    public function homonimos(Request $request): JsonResponse
    {
        $actor = $request->user();
        $permiso = $actor->can('usuarios.crear') ? 'usuarios.crear' : 'usuarios.editar';
        Gate::authorize($permiso);
        $empresaId = $this->empresa->id($actor);
        $nombre = trim(Entrada::texto($request->query('nombre')));
        $vacio = ['usuarios' => [], 'otros' => 0, 'colaboradores' => [], 'requiere_confirmacion' => false, 'mensaje' => null];
        if ($empresaId === null || mb_strlen($nombre) < 3 || mb_strlen($nombre) > 150) {
            return response()->json($vacio);
        }

        // La cuenta que se edita debe estar a la vista; si no, se ignora
        $excluir = is_numeric($request->query('excluir'))
            ? $this->visibles($actor, $empresaId)->whereKey((int) $request->query('excluir'))->first()
            : null;

        $iguales = $this->homonimos->usuarios($empresaId, $nombre, $excluir?->id);
        $idsVisibles = $iguales->isEmpty() ? [] : $this->visibles($actor, $empresaId)->whereIn('users.id', $iguales->pluck('id'))->pluck('users.id')->all();
        $visibles = $iguales->whereIn('id', $idsVisibles)->values();
        $otros = $iguales->count() - $visibles->count();

        $sedes = $this->autorizador->sedesPermitidas($actor, $permiso);
        $colaboradores = $this->tenant->conEmpresa($empresaId, fn () => $this->homonimos->colaboradoresSinCuenta($nombre, $sedes, $excluir?->id));

        return response()->json([
            'usuarios' => $visibles->map(fn (User $u) => [
                'username' => $u->username,
                'rol' => $u->roles->first()?->nombre,
                'colaborador' => $u->colaborador?->num_empleado,
                'activo' => (bool) $u->activo,
            ])->all(),
            'otros' => $otros,
            'colaboradores' => $colaboradores,
            'requiere_confirmacion' => $iguales->isNotEmpty() && ($excluir === null || HomonimosUsuarios::clave($excluir->name) !== HomonimosUsuarios::clave($nombre)),
            'mensaje' => $iguales->isEmpty() ? null : HomonimosUsuarios::mensaje($visibles, $otros),
        ]);
    }

    /**
     * Un homónimo no impide guardar (hay personas con el mismo nombre), pero
     * hay que confirmarlo con «Sí, es otra persona con el mismo nombre».
     */
    private function exigirConfirmacionHomonimo(Request $request, int $empresaId, string $nombre, ?User $usuario = null): void
    {
        if ($request->boolean('confirmar_homonimo') || ! $this->homonimos->requiereConfirmacion($empresaId, $nombre, $usuario)) {
            return;
        }

        $iguales = $this->homonimos->usuarios($empresaId, $nombre, $usuario?->id);
        $ids = $this->visibles($request->user(), $empresaId)->whereIn('users.id', $iguales->pluck('id'))->pluck('users.id')->all();
        $visibles = $iguales->whereIn('id', $ids)->values();

        throw ValidationException::withMessages(['confirmar_homonimo' => HomonimosUsuarios::mensaje($visibles, $iguales->count() - $visibles->count())
            .' Si es otra persona con el mismo nombre, marca «Sí, es otra persona con el mismo nombre» y guarda de nuevo.'
            .' Si es la misma persona, cancela y edita su cuenta en la lista.']);
    }

    /**
     * Usuarios de la empresa que el actor puede ver según el alcance de "usuarios.ver":
     * toda la empresa, los asignados a sus sedes, o solo los que él dio de alta.
     */
    private function visibles(User $actor, int $empresaId): Builder
    {
        $consulta = User::query()->where('users.empresa_id', $empresaId)->where('users.es_superadmin', false);

        return $this->administrador->limitarAlcance($consulta, $actor, 'usuarios.ver');
    }

    /**
     * Seguridad (AZ-03): una cuenta de otra empresa (o el Super Administrador)
     * responde 404 antes de revisar el permiso, para no revelar que existe.
     */
    private function exigirDeLaEmpresa(Request $request, User $usuario): void
    {
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null || $usuario->es_superadmin || (int) $usuario->empresa_id !== $empresaId, 404);
    }

    private function exigirVisible(Request $request, User $usuario): int
    {
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);
        abort_unless($this->visibles($request->user(), $empresaId)->whereKey($usuario->id)->exists(), 404);

        return $empresaId;
    }

    /**
     * @return array<string, mixed>
     */
    private function validar(Request $request, int $empresaId, ?User $usuario = null): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'numero_colaborador' => ['nullable', 'string', 'max:30', Rule::unique('users', 'numero_colaborador')->where('empresa_id', $empresaId)->ignore($usuario?->id)],
            'colaborador_id' => ['nullable', 'integer'],
            'username' => ['required', 'string', 'max:60', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('users', 'username')->ignore($usuario?->id)],
            'email' => ['required', 'email:rfc', 'max:150', Rule::unique('users', 'email')->ignore($usuario?->id)],
            'password' => [$usuario === null ? 'required' : 'nullable', 'string', Password::min(8)->letters()->numbers(),
                new ContrasenaSegura((string) $request->input('username'), (string) $request->input('email'))],
            'rol_id' => ['required', 'integer'],
            'sede_id' => ['nullable', 'integer'],
        ], [
            'username.unique' => 'Ya existe otro usuario registrado con ese nombre de usuario.',
            'email.unique' => 'Ya existe otro usuario registrado con ese correo.',
            'numero_colaborador.unique' => 'Ya existe otro usuario registrado con ese número de colaborador.',
            'username.regex' => 'El nombre de usuario solo puede tener letras, números, punto, guion y guion bajo (sin espacios).',
        ], [
            'name' => 'nombre completo',
            'numero_colaborador' => 'número de colaborador',
            'username' => 'nombre de usuario',
            'email' => 'correo',
            'password' => 'contraseña',
            'rol_id' => 'rol',
        ]);
    }

    /**
     * Vínculo opcional con Colaboradores (SEGCAT: usuarios.id_colaborador): el
     * colaborador debe ser de la misma empresa y no tener ya otra cuenta. El
     * número de colaborador de la cuenta se toma del colaborador.
     *
     * @param  array<string, mixed>  $datos
     * @return array<string, mixed>
     */
    private function vincularColaborador(array $datos, int $empresaId, ?User $usuario = null): array
    {
        if (empty($datos['colaborador_id'])) {
            $datos['colaborador_id'] = null;

            return $datos;
        }

        $id = (int) $datos['colaborador_id'];
        $colaborador = $this->tenant->conEmpresa($empresaId, fn () => Colaborador::find($id));
        if ($colaborador === null) {
            throw ValidationException::withMessages(['colaborador_id' => 'El colaborador no existe en esta empresa.']);
        }
        if (! $colaborador->activo && $id !== (int) $usuario?->colaborador_id) {
            throw ValidationException::withMessages(['colaborador_id' => 'Ese colaborador está dado de baja.']);
        }
        if (User::where('colaborador_id', $id)->when($usuario !== null, fn ($q) => $q->whereKeyNot($usuario->id))->exists()) {
            throw ValidationException::withMessages(['colaborador_id' => 'Ese colaborador ya tiene una cuenta de usuario: búscala en la lista en vez de crear otra.']);
        }
        if (User::where('empresa_id', $empresaId)->where('numero_colaborador', $colaborador->num_empleado)
            ->when($usuario !== null, fn ($q) => $q->whereKeyNot($usuario->id))->exists()) {
            throw ValidationException::withMessages(['numero_colaborador' => 'Ya existe otro usuario registrado con ese número de colaborador.']);
        }

        $datos['colaborador_id'] = $id;
        $datos['numero_colaborador'] = $colaborador->num_empleado;

        return $datos;
    }

    /**
     * @return array{0: Rol, 1: ?Sede}
     */
    private function rolYSede(array $datos, int $empresaId): array
    {
        $rol = Rol::where('empresa_id', $empresaId)->find($datos['rol_id']);
        abort_if($rol === null, 422, 'El rol no existe en esta empresa.');

        $sede = null;
        if (! empty($datos['sede_id'])) {
            $sede = $this->tenant->conEmpresa($empresaId, fn () => Sede::find($datos['sede_id']));
            abort_if($sede === null, 422, 'La sede no existe en esta empresa.');
        }

        return [$rol, $sede];
    }

    /**
     * @return array{name: string, username: string, numero_colaborador: ?string, colaborador_id: ?int, email: string, password: ?string}
     */
    private function campos(array $datos): array
    {
        return [
            'name' => trim($datos['name']),
            'username' => trim($datos['username']),
            'numero_colaborador' => isset($datos['numero_colaborador']) && trim($datos['numero_colaborador']) !== '' ? trim($datos['numero_colaborador']) : null,
            'colaborador_id' => $datos['colaborador_id'] ?? null,
            'email' => mb_strtolower(trim($datos['email'])),
            'password' => $datos['password'] ?? null,
        ];
    }
}
