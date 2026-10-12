<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Autorizador;
use App\Support\AjustesCaseta;
use App\Support\Tenancy\EmpresaDeTrabajo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Configuración → «Caseta y activos» (ADR-0014): firmas de los vales de taxi,
 * semáforo de llaves fuera, hora de regreso de los equipos «Turno» y cómo se
 * firma el acuse de procedimientos. Son de toda la empresa: hace falta
 * «configuracion.editar» con alcance de empresa (como los avisos por correo).
 */
class AjustesCasetaController extends Controller
{
    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly AdministradorRoles $auditoria,
        private readonly Autorizador $autorizador,
    ) {}

    public function update(Request $request): RedirectResponse
    {
        Gate::authorize('configuracion.editar');
        $actor = $request->user();
        abort_unless($this->autorizador->alcanceDeEmpresa($actor, 'configuracion.editar'), 403,
            'Los ajustes de caseta son de toda la empresa: hace falta el permiso «editar» de Configuración con alcance de empresa.');
        $empresaId = $this->empresa->id($actor);
        abort_if($empresaId === null, 404);

        $empresa = Empresa::findOrFail($empresaId);
        $antes = AjustesCaseta::de($empresa);
        $guardar = AjustesCaseta::validar($request->only(array_keys(AjustesCaseta::OMISION)));
        $preferencias = $empresa->preferencias ?? [];
        if ($guardar === []) {
            unset($preferencias[AjustesCaseta::CLAVE]);
        } else {
            $preferencias[AjustesCaseta::CLAVE] = $guardar;
        }
        $empresa->forceFill(['preferencias' => $preferencias])->save();

        $this->auditoria->auditar($actor, 'configuracion.caseta', $empresa, $antes, AjustesCaseta::de($empresa->refresh()));

        return redirect()->to(route('configuracion.index').'#caseta')
            ->with('ok', $guardar === [] ? 'Ajustes de caseta guardados: todo funciona como el diseño original.' : 'Ajustes de caseta y activos guardados.');
    }
}
