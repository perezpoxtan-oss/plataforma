<?php

namespace App\Http\Controllers\Organizacion;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Organizacion\Concerns\CatalogoDeEmpresa;
use App\Models\Departamento;
use App\Models\Puesto;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Autorizador;
use App\Support\Entrada;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * Puestos (réplica de modules/puestos de SEGCAT): catálogo de rangos,
 * independiente del departamento.
 */
class PuestoController extends Controller
{
    use CatalogoDeEmpresa;

    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly Autorizador $autorizadorPermisos,
        private readonly AdministradorRoles $auditoria,
    ) {}

    protected function autorizador(): Autorizador
    {
        return $this->autorizadorPermisos;
    }

    public function index(Request $request): View
    {
        Gate::authorize('puestos.ver');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);

        if ($empresaId === null) {
            return view('organizacion.puestos.index', ['sinEmpresa' => true]);
        }

        return $this->tenant->conEmpresa($empresaId, fn () => view('organizacion.puestos.index', [
            'sinEmpresa' => false,
            'puestos' => Puesto::with(['departamentos' => fn ($q) => $q->select('departamentos.id', 'departamentos.nombre', 'departamentos.activo')->orderBy('departamentos.nombre')])
                ->leftJoin('users as uc', 'uc.id', '=', 'puestos.creado_por')
                ->leftJoin('users as ua', 'ua.id', '=', 'puestos.actualizado_por')
                ->select('puestos.*', 'uc.name as creado_por_nombre', 'ua.name as actualizado_por_nombre')
                ->orderBy('puestos.nombre')
                ->get(),
            'departamentos' => Departamento::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'tipos' => Puesto::TIPOS,
            'puede' => $this->permisosCatalogo($actor, 'puestos'),
        ]));
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('puestos.crear');
        $this->exigirAlcanceDeEmpresa($request->user(), 'puestos.crear');
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        [$puesto, $foto] = $this->tenant->conEmpresa($empresaId, function () use ($request, $empresaId) {
            [$datos, $departamentos] = $this->validar($request, $empresaId);
            $puesto = DB::transaction(function () use ($datos, $departamentos) {
                $puesto = Puesto::create($datos);
                $puesto->departamentos()->sync($departamentos);

                return $puesto;
            });

            return [$puesto, $this->foto($puesto)];
        });

        $this->auditoria->auditar($request->user(), 'puestos.creado', $puesto, null, $foto);

        return redirect()->route('puestos.index')->with('ok', "Puesto «{$puesto->nombre}» creado correctamente.");
    }

    public function update(Request $request, int $puesto): RedirectResponse
    {
        Gate::authorize('puestos.editar');
        $this->exigirAlcanceDeEmpresa($request->user(), 'puestos.editar');
        [$modelo, $empresaId] = $this->buscar($request, $puesto);

        [$antes, $despues] = $this->tenant->conEmpresa($empresaId, function () use ($request, $empresaId, $modelo) {
            $antes = $this->foto($modelo);
            [$datos, $departamentos] = $this->validar($request, $empresaId, $modelo);
            DB::transaction(function () use ($modelo, $datos, $departamentos) {
                $modelo->fill($datos)->save();
                $modelo->departamentos()->sync($departamentos);
            });

            return [$antes, $this->foto($modelo)];
        });

        $this->auditoria->auditar($request->user(), 'puestos.actualizado', $modelo, $antes, $despues);

        return redirect()->route('puestos.index')->with('ok', "Puesto «{$modelo->nombre}» actualizado correctamente.");
    }

    public function estado(Request $request, int $puesto): RedirectResponse
    {
        Gate::authorize('puestos.eliminar');
        $this->exigirAlcanceDeEmpresa($request->user(), 'puestos.eliminar');
        [$modelo, $empresaId] = $this->buscar($request, $puesto);

        $activo = $request->boolean('activo');
        $this->tenant->conEmpresa($empresaId, fn () => $modelo->forceFill(['activo' => $activo])->save());
        $this->auditoria->auditar($request->user(), $activo ? 'puestos.reactivado' : 'puestos.desactivado', $modelo, ['activo' => ! $activo], ['activo' => $activo]);

        return redirect()->route('puestos.index')->with($activo ? 'ok' : 'aviso', $activo
            ? "Puesto «{$modelo->nombre}» reactivado."
            : "Puesto «{$modelo->nombre}» desactivado. Puedes reactivarlo con el mismo botón cuando quieras.");
    }

    /**
     * @return array{0: Puesto, 1: int}
     */
    private function buscar(Request $request, int $id): array
    {
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        $modelo = $this->tenant->conEmpresa($empresaId, fn () => Puesto::find($id));
        abort_if($modelo === null, 404);

        return [$modelo, $empresaId];
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<int>}
     */
    private function validar(Request $request, int $empresaId, ?Puesto $puesto = null): array
    {
        $request->merge(['nombre' => $this->normalizarNombre(Entrada::texto($request->input('nombre')))]);

        $datos = $request->validate([
            'tipo' => ['required', Rule::in(array_keys(Puesto::TIPOS))],
            'nombre' => ['required', 'string', 'max:100', $this->nombreLibre('puestos', $empresaId, $puesto?->id, 'Ya existe un puesto con ese nombre en esta empresa.')],
            'departamentos' => ['nullable', 'array'],
            'departamentos.*' => ['integer'],
        ], [
        ], ['tipo' => 'tipo de puesto']);

        // Solo departamentos de esta empresa (el filtro de empresa aplica solo)
        $departamentos = Departamento::whereIn('id', array_map('intval', $datos['departamentos'] ?? []))->pluck('id')->all();
        // Los departamentos desactivados no salen en la lista: se conservan sus ligas
        if ($puesto !== null) {
            $departamentos = array_values(array_unique([...$departamentos, ...$puesto->departamentos()->where('departamentos.activo', false)->pluck('departamentos.id')->all()]));
        }

        return [['nombre' => $datos['nombre'], 'tipo' => $datos['tipo']], $departamentos];
    }

    /**
     * @return array<string, mixed>
     */
    private function foto(Puesto $p): array
    {
        return $p->only(['nombre', 'tipo', 'activo']) + ['departamentos' => $p->departamentos()->pluck('departamentos.id')->all()];
    }
}
