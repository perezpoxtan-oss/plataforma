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
 * Configuración → ajustes de caseta y activos (ADR-0014): firmas de los vales
 * de taxi, semáforo de llaves fuera, hora de regreso de los equipos «Turno»,
 * acuse y recordatorio de procedimientos y reloj del visto bueno. Desde la
 * Configuración por secciones cada tarjeta manda solo sus campos
 * (AjustesCaseta::GRUPOS); lo que no llega conserva su valor. Son de toda la empresa: hace falta
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
        // Configuración por secciones: cada tarjeta manda solo sus campos; los demás se conservan
        $guardar = AjustesCaseta::validar(array_merge($antes, $request->only(array_keys(AjustesCaseta::OMISION))));
        $preferencias = $empresa->preferencias ?? [];
        if ($guardar === []) {
            unset($preferencias[AjustesCaseta::CLAVE]);
        } else {
            $preferencias[AjustesCaseta::CLAVE] = $guardar;
        }
        $empresa->forceFill(['preferencias' => $preferencias])->save();

        $this->auditoria->auditar($actor, 'configuracion.caseta', $empresa, $antes, AjustesCaseta::de($empresa->refresh()));

        // Regresa a la tarjeta desde la que se guardó (Configuración por secciones)
        $tarjeta = $request->input('_tarjeta');
        $ancla = is_string($tarjeta) && isset(AjustesCaseta::GRUPOS[$tarjeta]) ? 'cfg-'.$tarjeta : 'caseta';

        return redirect()->to(route('configuracion.index').'#'.$ancla)
            ->with('ok', $guardar === [] ? 'Ajustes de caseta guardados: todo funciona como el diseño original.' : 'Ajustes de caseta y activos guardados.');
    }
}
