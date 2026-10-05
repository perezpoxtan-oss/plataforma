<?php

namespace App\Http\Controllers\Seguridad;

use App\Http\Controllers\Controller;
use App\Models\Persona;
use App\Models\Proveedor;
use App\Models\User;
use App\Services\Personas\AdministradorPersonas;
use App\Services\Personas\FolioDuplicado;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Padrón de personas (réplica de modules/visitantes de SEGCAT): visitantes,
 * personal de proveedores y contratistas registrados antes de llegar, más la
 * búsqueda y el registro rápido que usará la Bitácora de accesos.
 */
class PersonaController extends Controller
{
    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly AdministradorPersonas $personas,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('visitantes.ver');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);

        if ($empresaId === null) {
            return view('seguridad.personas.index', ['sinEmpresa' => true]);
        }

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $request) {
            // "Ver" no se acota por alcance: el padrón es de toda la empresa
            $lista = Persona::query()
                ->with('proveedor:id,nombre,categoria,activo')
                ->leftJoin('users as uc', 'uc.id', '=', 'personas.creado_por')
                ->leftJoin('users as ua', 'ua.id', '=', 'personas.actualizado_por')
                ->select(['personas.*', 'uc.name as creado_por_nombre', 'ua.name as actualizado_por_nombre'])
                ->orderByDesc('personas.id')
                ->get();

            $puede = [
                'crear' => $actor->can('visitantes.crear'),
                'editar' => $actor->can('visitantes.editar'),
                'estado' => $actor->can('visitantes.eliminar'),
            ];

            $proveedores = $puede['crear'] || $puede['editar']
                ? Proveedor::where(fn ($q) => $q->where('activo', true)->orWhereIn('id', $lista->pluck('proveedor_id')->filter()->unique()->values()))
                    ->orderBy('nombre')->get(['id', 'nombre', 'categoria', 'activo'])
                : collect();

            return view('seguridad.personas.index', [
                'sinEmpresa' => false,
                'personas' => $lista,
                'proveedores' => $proveedores,
                'editables' => $this->personas->idsEnAlcance($actor, 'visitantes.editar'),
                'desactivables' => $this->personas->idsEnAlcance($actor, 'visitantes.eliminar'),
                'puede' => $puede,
                'desdeProveedor' => $puede['crear'] ? $this->desdeProveedor($request, $proveedores) : null,
            ]);
        });
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('visitantes.crear');
        $empresaId = $this->empresaDeTrabajo($request);

        $persona = $this->tenant->conEmpresa($empresaId, fn () => $this->personas->crear($request->user(), $request));

        return $this->volver($request, $persona)->with('ok', "Persona «{$persona->nombre_completo}» registrada correctamente.");
    }

    public function update(Request $request, int $persona): RedirectResponse
    {
        Gate::authorize('visitantes.editar');
        $empresaId = $this->empresaDeTrabajo($request);

        $modelo = $this->tenant->conEmpresa($empresaId, fn () => $this->personas->actualizar(
            $request->user(),
            $this->buscarEnAlcance($request->user(), $persona, 'visitantes.editar'),
            $request,
        ));

        return $this->volver($request, $modelo)->with('ok', "Perfil de «{$modelo->nombre_completo}» actualizado correctamente.");
    }

    /**
     * Baja lógica y reactivación (SEGCAT: estatus 0/1; nunca se borra, las
     * bitácoras de acceso dependen de la persona).
     */
    public function estado(Request $request, int $persona): RedirectResponse
    {
        Gate::authorize('visitantes.eliminar');
        $empresaId = $this->empresaDeTrabajo($request);
        $activo = $request->boolean('activo');

        $modelo = $this->tenant->conEmpresa($empresaId, function () use ($request, $persona, $activo) {
            $modelo = $this->buscarEnAlcance($request->user(), $persona, 'visitantes.eliminar');
            $this->personas->cambiarEstado($request->user(), $modelo, $activo);

            return $modelo;
        });

        return redirect()->to(route('personas.index').'#persona-'.$modelo->id)->with($activo ? 'ok' : 'aviso', $activo
            ? "Registro de «{$modelo->nombre_completo}» reactivado correctamente."
            : "Registro de «{$modelo->nombre_completo}» dado de baja. Puedes reactivarlo con un clic cuando quieras.");
    }

    /**
     * Registro rápido desde otros módulos (Bitácora de accesos).
     * 201 con la persona; 422 con errores; 409 si el folio ya es de alguien
     * (con esa persona, para usarla en vez de duplicarla).
     */
    public function rapido(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('visitantes.crear'), 403);

        $empresaId = $this->empresa->id($request->user());
        if ($empresaId === null) {
            return response()->json(['ok' => false, 'mensaje' => 'Elige primero la empresa de trabajo.', 'errores' => []], 422);
        }

        try {
            $resumen = $this->tenant->conEmpresa($empresaId, fn () => $this->personas->resumen($this->personas->crear($request->user(), $request)));
        } catch (FolioDuplicado $e) {
            return response()->json([
                'ok' => false,
                'mensaje' => collect($e->errors())->flatten()->first(),
                'persona' => $this->tenant->conEmpresa($empresaId, fn () => $this->personas->resumen($e->existente)),
            ], 409);
        } catch (ValidationException $e) {
            return response()->json(['ok' => false, 'mensaje' => collect($e->errors())->flatten()->first(), 'errores' => $e->errors()], 422);
        }

        return response()->json(['ok' => true, 'persona' => $resumen], 201);
    }

    /**
     * Autocompletar (SEGCAT: persona_buscar_ajax.php): por nombre o folio,
     * mínimo 2 caracteres, máximo 15, solo activos y de la empresa.
     */
    public function buscar(Request $request): JsonResponse
    {
        abort_unless($request->user()->can('visitantes.ver'), 403);

        $empresaId = $this->empresa->id($request->user());
        if ($empresaId === null) {
            return response()->json(['resultados' => []]);
        }

        return response()->json([
            'resultados' => $this->tenant->conEmpresa($empresaId, fn () => $this->personas->buscar((string) $request->query('q', ''))),
        ]);
    }

    /**
     * Contrato con la ficha del Proveedor: /personas?nuevo=1&proveedor={id}
     * abre el alta con el proveedor y el tipo ya elegidos.
     *
     * @param  Collection<int, Proveedor>  $proveedores
     * @return array{proveedor_id: int, tipo: string, nombre: string}|null
     */
    private function desdeProveedor(Request $request, $proveedores): ?array
    {
        if (! $request->boolean('nuevo')) {
            return null;
        }
        $proveedor = $proveedores->firstWhere('id', (int) $request->query('proveedor'));
        if ($proveedor === null || ! $proveedor->activo) {
            return ['proveedor_id' => 0, 'tipo' => 'visitante', 'nombre' => ''];
        }

        return [
            'proveedor_id' => $proveedor->id,
            'tipo' => $proveedor->categoria === 'contratista' ? 'contratista' : 'proveedor',
            'nombre' => $proveedor->nombre,
        ];
    }

    /**
     * Tras guardar: a la ficha del proveedor si de ahí vino (y la persona
     * quedó ligada a uno), si no a la lista con la ficha resaltada. Nunca se
     * acepta una URL del cliente: solo la bandera y el proveedor ya validado.
     */
    private function volver(Request $request, Persona $persona): RedirectResponse
    {
        if ($request->input('volver') === 'proveedor' && $persona->proveedor_id !== null && Route::has('proveedores.show')) {
            return redirect()->route('proveedores.show', $persona->proveedor_id);
        }

        return redirect()->to(route('personas.index').'#persona-'.$persona->id);
    }

    private function empresaDeTrabajo(Request $request): int
    {
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        return $empresaId;
    }

    /**
     * Una persona de otra empresa o fuera del alcance responde 404.
     */
    private function buscarEnAlcance(User $actor, int $id, string $permiso): Persona
    {
        $modelo = $this->personas->limitar(Persona::query(), $actor, $permiso)->find($id);
        abort_if($modelo === null, 404);

        return $modelo;
    }
}
