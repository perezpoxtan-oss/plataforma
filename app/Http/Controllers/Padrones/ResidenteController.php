<?php

namespace App\Http\Controllers\Padrones;

use App\Http\Controllers\Controller;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\User;
use App\Services\Bajas\AntesDeDarDeBaja;
use App\Services\Colaboradores\AdministradorColaboradores;
use App\Services\Colaboradores\Residentes;
use App\Services\Espacios\Lugares;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use App\Support\Terminos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Padrones → Residentes (C-10, DD-14 opción B): solo en condominio y
 * fraccionamiento. Lista corta con la vivienda y el teléfono, alta corta y
 * «Dar de baja» / «Reactivar». El personal de la administración sigue en
 * Estructura → Organización (Colaboradores, que en estos giros se llama «Personal»).
 */
class ResidenteController extends Controller
{
    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Tenant $tenant,
        private readonly AdministradorColaboradores $colaboradores,
        private readonly Residentes $residentes,
        private readonly Lugares $lugares,
    ) {}

    public function index(Request $request): View
    {
        Gate::authorize('colaboradores.ver');
        $actor = $request->user();
        $empresaId = $this->empresa->id($actor);
        if ($empresaId === null) {
            return view('padrones.residentes.index', ['sinEmpresa' => true]);
        }
        $this->soloResidencial($empresaId);

        return $this->tenant->conEmpresa($empresaId, function () use ($actor) {
            $lista = $this->colaboradores->limitar(Colaborador::query(), $actor, 'colaboradores.ver')->residentes()
                ->with(['sede:id,nombre', 'vivienda:id,nombre,ruta,activo'])
                ->orderBy('colaboradores.nombre')->orderBy('colaboradores.apellido_paterno')->get();
            $sedesAlta = $actor->can('colaboradores.crear') ? $this->colaboradores->sedesPermitidas($actor, 'colaboradores.crear') : [];
            $sedesEdicion = $actor->can('colaboradores.editar') ? $this->colaboradores->sedesPermitidas($actor, 'colaboradores.editar') : [];
            $caminos = $this->lugares->opciones(null)->keyBy('id');

            return view('padrones.residentes.index', [
                'sinEmpresa' => false,
                'terminos' => Terminos::actual(),
                'residentes' => $lista,
                'caminos' => $caminos,
                'viviendasAlta' => $sedesAlta === [] ? collect() : $this->lugares->opciones($sedesAlta, soloViviendas: true), // sin áreas comunes
                'viviendasEdicion' => $sedesEdicion === [] ? collect() : $this->lugares->opciones($sedesEdicion, soloViviendas: true),
                'editables' => $this->colaboradores->idsEnAlcance($actor, 'colaboradores.editar'),
                'desactivables' => $this->colaboradores->idsEnAlcance($actor, 'colaboradores.eliminar'),
                'puede' => [
                    'crear' => $actor->can('colaboradores.crear'),
                    'editar' => $actor->can('colaboradores.editar'),
                    'estado' => $actor->can('colaboradores.eliminar'),
                ],
            ]);
        });
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('colaboradores.crear');
        $empresaId = $this->empresaDeTrabajo($request);
        $residente = $this->tenant->conEmpresa($empresaId, fn () => $this->residentes->crear($request->user(), $request->all()));

        return redirect()->route('residentes.index')->with('ok', "Residente «{$residente->nombreCompleto()}» registrado. Ya aparece en «¿A quién viene?» de la caseta. ¿Otro? Toca Nuevo residente.");
    }

    public function update(Request $request, int $residente): RedirectResponse
    {
        Gate::authorize('colaboradores.editar');
        $empresaId = $this->empresaDeTrabajo($request);
        $modelo = $this->tenant->conEmpresa($empresaId, fn () => $this->residentes->actualizar($request->user(),
            $this->buscar($request->user(), $residente, 'colaboradores.editar'), $request->all()));

        return redirect()->route('residentes.index')->with('ok', "Residente «{$modelo->nombreCompleto()}» guardado.");
    }

    public function estado(Request $request, int $residente): RedirectResponse
    {
        Gate::authorize('colaboradores.eliminar');
        $empresaId = $this->empresaDeTrabajo($request);
        $activo = $request->boolean('activo');
        $hecho = [];
        $modelo = $this->tenant->conEmpresa($empresaId, function () use ($request, $residente, $activo, &$hecho) {
            $modelo = $this->buscar($request->user(), $residente, 'colaboradores.eliminar');
            // C-20 (DD-06): la misma revisión «Antes de dar de baja» del directorio (vehículos, visitas abiertas…)
            $revision = $activo ? null : app(AntesDeDarDeBaja::class)->exigirRevisado('colaboradores', $modelo, $request);
            $this->colaboradores->cambiarEstado($request->user(), $modelo, $activo);
            if ($revision !== null && app(AntesDeDarDeBaja::class)->hayPendientes($revision)) {
                $hecho = app(AntesDeDarDeBaja::class)->aplicar('colaboradores', $modelo, $revision, $request->all(), $request->user());
            }

            return $modelo;
        });

        return redirect()->route('residentes.index')->with($activo ? 'ok' : 'aviso', $activo
            ? "Residente «{$modelo->nombreCompleto()}» reactivado."
            : "Residente «{$modelo->nombreCompleto()}» dado de baja.".($hecho !== [] ? ' Además: '.implode('; ', $hecho).'.' : '').' Puedes reactivarlo con el mismo botón.');
    }

    private function buscar(User $actor, int $id, string $permiso): Colaborador
    {
        $modelo = $this->colaboradores->limitar(Colaborador::query(), $actor, $permiso)->residentes()->find($id);
        abort_if($modelo === null, 404);

        return $modelo;
    }

    /** Residentes solo existe en condominio y fraccionamiento. */
    private function soloResidencial(int $empresaId): void
    {
        abort_unless(Terminos::para(Empresa::find($empresaId))->esResidencial(), 404);
    }

    private function empresaDeTrabajo(Request $request): int
    {
        $empresaId = $this->empresa->id($request->user());
        abort_if($empresaId === null, 404);
        $this->soloResidencial($empresaId);

        return $empresaId;
    }
}
