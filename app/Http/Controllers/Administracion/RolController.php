<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\Rol;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\PerfilesDeRol;
use App\Support\Tenancy\EmpresaDeTrabajo;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Roles y Jerarquía (réplica de modules/roles/rol_lista.php de SEGCAT).
 */
class RolController extends Controller
{
    public function __construct(
        private readonly AdministradorRoles $administrador,
        private readonly EmpresaDeTrabajo $empresa,
        private readonly PerfilesDeRol $perfiles,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('roles.ver');

        $usuario = $request->user();
        $empresaId = $this->empresa->id($usuario);

        $roles = $this->consultaRoles($empresaId)
            ->leftJoin('users as uc', 'uc.id', '=', 'roles.creado_por')
            ->leftJoin('users as ua', 'ua.id', '=', 'roles.actualizado_por')
            ->select([
                'roles.*',
                'uc.name as creado_por_nombre',
                'ua.name as actualizado_por_nombre',
                DB::raw('(SELECT COUNT(DISTINCT ur.user_id) FROM usuario_roles ur WHERE ur.rol_id = roles.id) as total_usuarios'),
            ])
            ->orderBy('roles.nivel_jerarquia')
            ->get();

        $nivelPropio = $usuario->nivelJerarquia();

        return view('administracion.roles.index', [
            'roles' => $roles,
            'nivelesUsados' => $roles->pluck('nivel_jerarquia')->all(),
            'nivelPropio' => $nivelPropio,
            'esPlantillas' => $this->empresa->esPlantillas($usuario),
            'empresaNombre' => $empresaId === null ? null : Empresa::whereKey($empresaId)->value('nombre_comercial'),
            'puede' => [
                'crear' => $usuario->can('roles.crear'),
                'editar' => $usuario->can('roles.editar'),
                'eliminar' => $usuario->can('roles.eliminar'),
                'permisos' => $usuario->can('permisos.ver'),
                // «Crear a partir de» otro rol (ADR-0016): copiar permisos exige poder editarlos
                'copiar' => $usuario->can('permisos.editar'),
            ],
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('roles.crear');

        $empresaId = $this->empresa->id($request->user());
        $datos = $this->validar($request, $empresaId);

        // «Crear a partir de»: los permisos de otro rol de la misma empresa (o de las plantillas)
        $origen = $request->filled('copiar_de')
            ? Rol::query()->when($empresaId === null, fn ($q) => $q->whereNull('empresa_id'), fn ($q) => $q->where('empresa_id', $empresaId))->find((int) $request->input('copiar_de'))
            : null;
        if ($request->filled('copiar_de') && $origen === null) {
            throw ValidationException::withMessages(['copiar_de' => 'El rol del que quieres copiar los permisos no existe.']);
        }

        try {
            $rol = DB::transaction(function () use ($request, $empresaId, $datos, $origen) {
                $rol = $this->administrador->crearRol($request->user(), $empresaId, $datos);

                return [$rol, $origen === null ? null : $this->perfiles->copiar($request->user(), $origen, $rol)];
            });
        } catch (AuthorizationException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }
        [$rol, $copia] = $rol;

        if ($copia === null) {
            return redirect()->route('roles.index')->with('ok', "Rol «{$rol->nombre}» creado con nivel {$rol->nivel_jerarquia}. Ya puedes configurarle sus permisos: toca «Ver permisos» en su ficha.");
        }

        return redirect()->route('permisos.index', ['rol' => $rol->id])->with('ok', "Rol «{$rol->nombre}» creado con los permisos de «{$origen->nombre}»"
            .($copia['omitidos'] > 0 ? " (sin {$copia['omitidos']} que tú no tienes)" : '')
            .'. Revisa abajo lo que verá y ajusta lo que haga falta.');
    }

    public function update(Request $request, Rol $rol): RedirectResponse
    {
        // Seguridad (AZ-03): primero la empresa (404), luego el permiso (403)
        $this->exigirMismaEmpresa($request, $rol);
        Gate::authorize('roles.editar');

        $datos = $this->validar($request, $rol->empresa_id, $rol);

        try {
            $this->administrador->actualizarRol($request->user(), $rol, $datos);
        } catch (AuthorizationException $e) {
            return back()->withInput()->with('error', $e->getMessage());
        }

        return redirect()->route('roles.index')->with('ok', "Rol «{$datos['nombre']}» actualizado (nivel {$datos['nivel_jerarquia']}).");
    }

    public function destroy(Request $request, Rol $rol): RedirectResponse
    {
        // Seguridad (AZ-03): primero la empresa (404), luego el permiso (403)
        $this->exigirMismaEmpresa($request, $rol);
        Gate::authorize('roles.eliminar');

        try {
            $this->administrador->eliminarRol($request->user(), $rol);
        } catch (AuthorizationException|\DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('roles.index')->with('ok', 'Rol eliminado.');
    }

    /**
     * El nivel se elige como «Debajo de / Arriba de» otro rol (ADR-0016); el
     * número directo queda en «Avanzado». Si se elige una posición, manda ella.
     *
     * @return array{nombre: string, descripcion: ?string, nivel_jerarquia: int, activo: bool}
     */
    private function validar(Request $request, ?int $empresaId, ?Rol $rol = null): array
    {
        $this->nivelDesdePosicion($request, $empresaId, $rol);

        $deEmpresa = fn ($regla) => $empresaId === null
            ? $regla->whereNull('empresa_id')
            : $regla->where('empresa_id', $empresaId);

        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:80', $deEmpresa(Rule::unique('roles', 'nombre'))->ignore($rol?->id)],
            'descripcion' => ['nullable', 'string', 'max:255'],
            'nivel_jerarquia' => ['required', 'integer', 'min:1', 'max:999', $deEmpresa(Rule::unique('roles', 'nivel_jerarquia'))->ignore($rol?->id)],
            'activo' => ['sometimes', 'boolean'],
        ], [
            'nombre.unique' => 'Ya existe un rol con ese nombre.',
            'nivel_jerarquia.unique' => 'Ya existe un rol con ese nivel jerárquico; cada nivel debe ser único.',
            'nivel_jerarquia.required' => 'Elige dónde va el rol en la jerarquía (debajo o arriba de otro rol).',
        ], [
            'nivel_jerarquia' => 'nivel jerárquico',
        ]);

        return [
            'nombre' => trim($datos['nombre']),
            'descripcion' => $datos['descripcion'] ?? null,
            'nivel_jerarquia' => (int) $datos['nivel_jerarquia'],
            'activo' => $request->boolean('activo', $rol?->activo ?? true),
        ];
    }

    /**
     * «Debajo de / Arriba de» → nivel_jerarquia (a la mitad entre los vecinos).
     */
    private function nivelDesdePosicion(Request $request, ?int $empresaId, ?Rol $rol): void
    {
        if (! $request->filled('posicion_rol')) {
            return;
        }
        $relacion = $request->input('posicion_relacion') === 'arriba' ? 'arriba' : 'debajo';
        $roles = Rol::query()->when($empresaId === null, fn ($q) => $q->whereNull('empresa_id'), fn ($q) => $q->where('empresa_id', $empresaId))->get();
        $referencia = $roles->firstWhere('id', (int) $request->input('posicion_rol'));
        if ($referencia === null || ($rol !== null && $referencia->id === $rol->id)) {
            throw ValidationException::withMessages(['posicion_rol' => 'Elige otro rol como referencia.']);
        }

        try {
            $nivel = $this->perfiles->nivelPara($roles, $relacion, $referencia, $request->user()->nivelJerarquia(), $rol?->id);
        } catch (\DomainException $e) {
            throw ValidationException::withMessages(['posicion_rol' => $e->getMessage()]);
        }
        $request->merge(['nivel_jerarquia' => $nivel]);
    }

    private function consultaRoles(?int $empresaId): Builder
    {
        return DB::table('roles')->when(
            $empresaId === null,
            fn (Builder $q) => $q->whereNull('roles.empresa_id'),
            fn (Builder $q) => $q->where('roles.empresa_id', $empresaId),
        );
    }

    /**
     * Un rol solo se modifica desde el contexto de su empresa (sin mezclar).
     */
    private function exigirMismaEmpresa(Request $request, Rol $rol): void
    {
        abort_unless($rol->empresa_id === $this->empresa->id($request->user()), 404);
    }
}
