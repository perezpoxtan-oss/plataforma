<?php

namespace App\Http\Controllers;

use App\Models\Colaborador;
use App\Services\Colaboradores\AdministradorColaboradores;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PanelController extends Controller
{
    /**
     * Consola de Monitoreo Central. Las fichas (colaboradores, personas en
     * sitio, llaves...) se agregan conforme se migra cada modulo; arriba van
     * los pendientes que requieren atención de quien entra.
     */
    public function __invoke(Request $request, EmpresaDeTrabajo $empresa, Tenant $tenant, AdministradorColaboradores $colaboradores): View
    {
        $actor = $request->user();
        $empresaId = $empresa->id($actor);
        $pendientes = [];

        // Recursos Humanos: altas provisionales de la caseta por validar
        if ($empresaId !== null && $actor->can('colaboradores.aprobar')) {
            $total = $tenant->conEmpresa($empresaId, fn () => $colaboradores->limitar(Colaborador::query(), $actor, 'colaboradores.aprobar')
                ->where('colaboradores.provisional', true)->where('colaboradores.activo', true)->whereNull('colaboradores.fusionado_en_id')->count());
            if ($total > 0) {
                $pendientes[] = [
                    'icono' => 'bi-person-exclamation',
                    'titulo' => $total === 1 ? '1 alta provisional por validar' : "{$total} altas provisionales por validar",
                    'texto' => 'La caseta registró colaboradores que aún no estaban en el directorio. Valídalos o únelos con su registro correcto.',
                    'ruta' => route('colaboradores.index', ['registro' => 'provisional']),
                    'boton' => 'Revisar ahora',
                ];
            }
        }

        return view('panel.index', ['pendientes' => $pendientes]);
    }
}
