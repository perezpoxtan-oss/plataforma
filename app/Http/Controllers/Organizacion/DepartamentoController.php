<?php

namespace App\Http\Controllers\Organizacion;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Organizacion\Concerns\CatalogoDeEmpresa;
use App\Models\Departamento;
use App\Models\Sede;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Autorizador;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

/**
 * Departamentos (réplica de modules/departamentos de SEGCAT).
 */
class DepartamentoController extends Controller
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
        Gate::authorize('departamentos.ver');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);

        if ($empresaId === null) {
            return view('organizacion.departamentos.index', ['sinEmpresa' => true]);
        }

        $permitidas = $this->autorizador()->sedesPermitidas($actor, 'departamentos.ver');

        return $this->tenant->conEmpresa($empresaId, fn () => view('organizacion.departamentos.index', [
            'sinEmpresa' => false,
            'departamentos' => Departamento::with('sedes:id,nombre')
                ->withCount(['puestos' => fn ($q) => $q->where('activo', true)])
                ->when($permitidas !== null, fn ($q) => $q->aplicanEn($permitidas))
                ->leftJoin('users as uc', 'uc.id', '=', 'departamentos.creado_por')
                ->leftJoin('users as ua', 'ua.id', '=', 'departamentos.actualizado_por')
                ->addSelect('uc.name as creado_por_nombre', 'ua.name as actualizado_por_nombre')
                ->orderBy('departamentos.nombre')
                ->get(),
            'sedes' => Sede::where('activo', true)->orderBy('nombre')->get(['id', 'nombre']),
            'puede' => $this->permisosCatalogo($actor, 'departamentos'),
        ]));
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('departamentos.crear');
        $this->exigirAlcanceDeEmpresa($request->user(), 'departamentos.crear');
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        [$departamento, $foto] = $this->tenant->conEmpresa($empresaId, function () use ($request, $empresaId) {
            [$datos, $sedes] = $this->validar($request, $empresaId);
            $departamento = DB::transaction(function () use ($datos, $sedes) {
                $departamento = Departamento::create($datos);
                $departamento->sedes()->sync($sedes);

                return $departamento;
            });

            return [$departamento, $this->foto($departamento)];
        });

        $this->auditoria->auditar($request->user(), 'departamentos.creado', $departamento, null, $foto);

        return redirect()->route('departamentos.index')->with('ok', "Departamento «{$departamento->nombre}» creado correctamente.");
    }

    public function update(Request $request, int $departamento): RedirectResponse
    {
        Gate::authorize('departamentos.editar');
        $this->exigirAlcanceDeEmpresa($request->user(), 'departamentos.editar');
        [$modelo, $empresaId] = $this->buscar($request, $departamento);

        [$antes, $despues] = $this->tenant->conEmpresa($empresaId, function () use ($request, $empresaId, $modelo) {
            $antes = $this->foto($modelo);
            [$datos, $sedes] = $this->validar($request, $empresaId, $modelo);
            DB::transaction(function () use ($modelo, $datos, $sedes) {
                $modelo->fill($datos)->save();
                $modelo->sedes()->sync($sedes);
            });

            return [$antes, $this->foto($modelo)];
        });

        $this->auditoria->auditar($request->user(), 'departamentos.actualizado', $modelo, $antes, $despues);

        return redirect()->route('departamentos.index')->with('ok', "Departamento «{$modelo->nombre}» actualizado correctamente.");
    }

    public function estado(Request $request, int $departamento): RedirectResponse
    {
        Gate::authorize('departamentos.eliminar');
        $this->exigirAlcanceDeEmpresa($request->user(), 'departamentos.eliminar');
        [$modelo, $empresaId] = $this->buscar($request, $departamento);

        $activo = $request->boolean('activo');
        $this->tenant->conEmpresa($empresaId, fn () => $modelo->forceFill(['activo' => $activo])->save());
        $this->auditoria->auditar($request->user(), $activo ? 'departamentos.reactivado' : 'departamentos.desactivado', $modelo, ['activo' => ! $activo], ['activo' => $activo]);

        return redirect()->route('departamentos.index')->with($activo ? 'ok' : 'aviso', $activo
            ? "Departamento «{$modelo->nombre}» reactivado."
            : "Departamento «{$modelo->nombre}» desactivado. Puedes reactivarlo con el mismo botón cuando quieras.");
    }

    /**
     * @return array{0: Departamento, 1: int}
     */
    private function buscar(Request $request, int $id): array
    {
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        $modelo = $this->tenant->conEmpresa($empresaId, fn () => Departamento::find($id));
        abort_if($modelo === null, 404);

        return [$modelo, $empresaId];
    }

    /**
     * @return array{0: array<string, mixed>, 1: list<int>}
     */
    private function validar(Request $request, int $empresaId, ?Departamento $departamento = null): array
    {
        $request->merge(['nombre' => $this->normalizarNombre($request->input('nombre'))]);
        $todas = $request->boolean('todas_las_sedes', true);

        $datos = $request->validate([
            'nombre' => ['required', 'string', 'max:100', $this->nombreLibre('departamentos', $empresaId, $departamento?->id, 'Ya existe un departamento con ese nombre en esta empresa.')],
            'todas_las_sedes' => ['boolean'],
            'sedes' => [$todas ? 'nullable' : 'required', 'array'],
            'sedes.*' => ['integer'],
        ], [
            'sedes.required' => 'Marca al menos una sede o elige «Todas las sedes».',
        ]);

        // Solo sedes activas de esta misma empresa
        $sedes = $todas ? [] : Sede::where('activo', true)->whereIn('id', array_map('intval', $datos['sedes'] ?? []))->pluck('id')->all();
        if (! $todas && $sedes === []) {
            throw ValidationException::withMessages(['sedes' => 'Marca al menos una sede o elige «Todas las sedes».']);
        }

        return [['nombre' => $datos['nombre'], 'todas_las_sedes' => $todas], $sedes];
    }

    /**
     * @return array<string, mixed>
     */
    private function foto(Departamento $d): array
    {
        return $d->only(['nombre', 'todas_las_sedes', 'activo']) + ['sedes' => $d->sedes()->pluck('sedes.id')->all()];
    }
}
