<?php

namespace App\Http\Controllers\Seguridad;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\User;
use App\Models\ZonaEstacionamiento;
use App\Services\Estacionamientos\AdministradorEstacionamientos;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Estacionamientos y zonas (réplica de modules/estacionamientos de SEGCAT):
 * "Cupos por sede" con la ocupación de cada estacionamiento y las zonas de
 * descarga. La ocupación la aporta la Bitácora de accesos
 * (OcupacionEstacionamientos).
 */
class EstacionamientoController extends Controller
{
    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly AdministradorEstacionamientos $zonas,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('estacionamientos.ver');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);

        if ($empresaId === null) {
            return view('seguridad.estacionamientos.index', ['sinEmpresa' => true]);
        }

        return $this->tenant->conEmpresa($empresaId, function () use ($actor, $empresaId) {
            $lista = $this->zonas->limitar(ZonaEstacionamiento::query(), $actor, 'estacionamientos.ver')
                ->with('sede:id,nombre')
                ->leftJoin('users as uc', 'uc.id', '=', 'zonas_estacionamiento.creado_por')
                ->leftJoin('users as ua', 'ua.id', '=', 'zonas_estacionamiento.actualizado_por')
                ->select(['zonas_estacionamiento.*', 'uc.name as creado_por_nombre', 'ua.name as actualizado_por_nombre'])
                // SEGCAT: por sede, tipo y nombre
                ->orderBy('zonas_estacionamiento.tipo')->orderBy('zonas_estacionamiento.nombre')
                ->get();

            $puedeCrear = $actor->can('estacionamientos.crear');
            $puedeEditar = $actor->can('estacionamientos.editar');

            return view('seguridad.estacionamientos.index', [
                'sinEmpresa' => false,
                'porSede' => $lista->groupBy('sede_id')->sortBy(fn ($zonas) => mb_strtolower($zonas->first()->sede->nombre ?? '')),
                'total' => $lista->count(),
                'ocupados' => $this->zonas->ocupados($lista),
                'empresaNombre' => Empresa::whereKey($empresaId)->value('nombre_comercial'),
                'sedesFiltro' => $lista->pluck('sede')->filter()->unique('id')->sortBy('nombre')->values(),
                'sedesAlta' => $puedeCrear ? $this->zonas->sedesParaElegir($actor, 'estacionamientos.crear') : collect(),
                'sedesEdicion' => $puedeEditar ? $this->zonas->sedesParaElegir($actor, 'estacionamientos.editar') : collect(),
                'editables' => $this->zonas->idsEnAlcance($actor, 'estacionamientos.editar'),
                'desactivables' => $this->zonas->idsEnAlcance($actor, 'estacionamientos.eliminar'),
                'puede' => [
                    'crear' => $puedeCrear,
                    'editar' => $puedeEditar,
                    'estado' => $actor->can('estacionamientos.eliminar'),
                ],
            ]);
        });
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('estacionamientos.crear');
        $empresaId = $this->empresaDeTrabajo($request);

        $zona = $this->tenant->conEmpresa($empresaId, fn () => $this->zonas->crear($request->user(), $request->all()));

        return redirect()->to(route('estacionamientos.index').'#zona-'.$zona->id)->with('ok', "Zona «{$zona->nombre}» creada correctamente.");
    }

    public function update(Request $request, int $zona): RedirectResponse
    {
        Gate::authorize('estacionamientos.editar');
        $empresaId = $this->empresaDeTrabajo($request);

        $modelo = $this->tenant->conEmpresa($empresaId, function () use ($request, $zona) {
            $modelo = $this->buscarEnAlcance($request->user(), $zona, 'estacionamientos.editar');

            return $this->zonas->actualizar($request->user(), $modelo, $request->all());
        });

        return redirect()->to(route('estacionamientos.index').'#zona-'.$modelo->id)->with('ok', "Zona «{$modelo->nombre}» actualizada correctamente.");
    }

    public function estado(Request $request, int $zona): RedirectResponse
    {
        Gate::authorize('estacionamientos.eliminar');
        $empresaId = $this->empresaDeTrabajo($request);
        $activo = $request->boolean('activo');

        $modelo = $this->tenant->conEmpresa($empresaId, function () use ($request, $zona, $activo) {
            $modelo = $this->buscarEnAlcance($request->user(), $zona, 'estacionamientos.eliminar');
            $this->zonas->cambiarEstado($request->user(), $modelo, $activo);

            return $modelo;
        });

        return redirect()->to(route('estacionamientos.index').'#zona-'.$modelo->id)->with($activo ? 'ok' : 'aviso', $activo
            ? "Zona «{$modelo->nombre}» reactivada correctamente."
            : "Zona «{$modelo->nombre}» desactivada: ya no se podrá asignar en Accesos, pero puedes reactivarla cuando quieras.");
    }

    private function empresaDeTrabajo(Request $request): int
    {
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);

        return $empresaId;
    }

    /**
     * Una zona de otra empresa o fuera del alcance del permiso responde 404.
     */
    private function buscarEnAlcance(User $actor, int $id, string $permiso): ZonaEstacionamiento
    {
        $modelo = $this->zonas->limitar(ZonaEstacionamiento::query(), $actor, $permiso)->find($id);
        abort_if($modelo === null, 404);

        return $modelo;
    }
}
