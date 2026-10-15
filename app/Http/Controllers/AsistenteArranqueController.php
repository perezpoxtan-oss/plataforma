<?php

namespace App\Http\Controllers;

use App\Models\Empresa;
use App\Services\Arranque\AsistenteArranque;
use App\Services\Permisos\AdministradorRoles;
use App\Support\Tenancy\EmpresaDeTrabajo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * «Configura tu empresa»: «Ya terminé» la oculta de Inicio; desde
 * Configuración se vuelve a mostrar. Aplica a toda la empresa.
 */
class AsistenteArranqueController extends Controller
{
    public function __invoke(Request $request, EmpresaDeTrabajo $empresa, AsistenteArranque $asistente, AdministradorRoles $auditoria): RedirectResponse
    {
        Gate::authorize('configuracion.editar');
        $empresaId = $empresa->id($request->user());
        abort_if($empresaId === null, 404);
        $datos = $request->validate(['visible' => ['required', 'boolean']]);
        $modelo = Empresa::findOrFail($empresaId);

        $antes = $asistente->preferencia($modelo);
        $asistente->cambiar($modelo, (bool) $datos['visible']);
        $auditoria->auditar($request->user(), 'configuracion.configura_tu_empresa', $modelo, ['configura_tu_empresa' => $antes], ['configura_tu_empresa' => $asistente->preferencia($modelo)]);

        return $datos['visible']
            ? redirect()->route('panel')->with('ok', 'Listo: «Configura tu empresa» ya se ve en Inicio.')
            : redirect()->back(fallback: route('panel'))->with('ok', 'Ocultamos «Configura tu empresa» de Inicio. Si la necesitas otra vez, ábrela desde Configuración.')
                ->with('siguientes', [['Ir a Configuración', route('configuracion.index').'#configura-tu-empresa', 'bi-sliders']]);
    }
}
