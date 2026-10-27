<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Autorizador;
use App\Services\Relojes\AjustesRelojes;
use App\Services\Relojes\MotorRelojes;
use App\Support\AjustesCaseta;
use App\Support\Tenancy\EmpresaDeTrabajo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Configuración → General → «Reloj y suplente» (C-22, DD-10): las horas
 * generales de recordatorio y de suplente (las del antiguo «Reloj del visto
 * bueno»), si se avisa o no, y en «Avanzado» lo de cada módulo. Son de toda
 * la empresa: «configuracion.editar» con alcance de empresa.
 */
class RelojesController extends Controller
{
    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly AdministradorRoles $auditoria,
        private readonly Autorizador $autorizador,
        private readonly AjustesRelojes $ajustes,
    ) {}

    public function update(Request $request): RedirectResponse
    {
        Gate::authorize('configuracion.editar');
        $actor = $request->user();
        abort_unless($this->autorizador->alcanceDeEmpresa($actor, 'configuracion.editar'), 403,
            '«Reloj y suplente» es de toda la empresa: hace falta el permiso «editar» de Configuración con alcance de empresa.');
        $empresaId = $this->empresa->id($actor);
        abort_if($empresaId === null, 404);
        $empresa = Empresa::findOrFail($empresaId);

        $antes = ['horas' => $this->ajustes->generales($empresa), 'modulos' => $empresa->preferencias[AjustesRelojes::CLAVE] ?? null];
        // Las horas generales viven con los demás ajustes de la caseta (vobo_horas_*): se validan igual
        $caseta = AjustesCaseta::validar(array_merge(AjustesCaseta::de($empresa), $request->only(['vobo_horas_amarillo', 'vobo_horas_rojo'])));
        $relojes = app(MotorRelojes::class)->relojesDe($empresaId);
        $this->ajustes->guardar($empresa, ['encendido' => $request->boolean('encendido')] + ['modulos' => (array) $request->input('modulos', [])], $relojes);
        $preferencias = $empresa->refresh()->preferencias ?? [];
        if ($caseta === []) {
            unset($preferencias[AjustesCaseta::CLAVE]);
        } else {
            $preferencias[AjustesCaseta::CLAVE] = $caseta;
        }
        $empresa->forceFill(['preferencias' => $preferencias === [] ? null : $preferencias])->save();
        $this->auditoria->auditar($actor, 'configuracion.relojes', $empresa, $antes,
            ['horas' => $this->ajustes->generales($empresa->refresh()), 'modulos' => $empresa->preferencias[AjustesRelojes::CLAVE] ?? null]);

        return redirect()->to(route('configuracion.index').'#cfg-vobo')->with('ok', '«Reloj y suplente» guardado.');
    }
}
