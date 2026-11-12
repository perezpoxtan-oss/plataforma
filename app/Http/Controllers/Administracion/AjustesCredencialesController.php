<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Autorizador;
use App\Support\AjustesCredenciales;
use App\Support\TemasConfiguracion;
use App\Support\Tenancy\EmpresaDeTrabajo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * C-44 (Etapa 8): Configuración → Estructura → Credenciales (vigencia,
 * aviso, tecnología y reverso). De toda la empresa: «configuracion.editar»
 * con alcance de empresa, como los ajustes de caseta.
 */
class AjustesCredencialesController extends Controller
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
            'Los ajustes de credenciales son de toda la empresa: hace falta el permiso «editar» de Configuración con alcance de empresa.');
        $empresaId = $this->empresa->id($actor);
        abort_if($empresaId === null, 404);

        $empresa = Empresa::findOrFail($empresaId);
        $antes = AjustesCredenciales::de($empresa);
        $guardar = AjustesCredenciales::validar(array_merge($antes, $request->only(array_keys(AjustesCredenciales::OMISION))));
        $preferencias = $empresa->preferencias ?? [];
        if ($guardar === []) {
            unset($preferencias[AjustesCredenciales::CLAVE]);
        } else {
            $preferencias[AjustesCredenciales::CLAVE] = $guardar;
        }
        $empresa->forceFill(['preferencias' => $preferencias])->save();
        $this->auditoria->auditar($actor, 'configuracion.credenciales', $empresa, $antes, AjustesCredenciales::de($empresa->refresh()));

        return redirect()->to(TemasConfiguracion::url('cfg-credenciales'))
            ->with('ok', $guardar === [] ? 'Credenciales: todo quedó en el valor original (sin vencimiento, solo QR).' : 'Ajustes de credenciales guardados.')
            ->with('siguientes', [['Ir a Credenciales', route('credenciales.index'), 'bi-person-vcard']]);
    }
}
