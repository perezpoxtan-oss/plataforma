<?php

namespace App\Http\Controllers\Administracion;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\User;
use App\Services\Permisos\Autorizador;
use App\Services\Privacidad\AvisosPrivacidad;
use App\Services\Privacidad\ConservacionDatos;
use App\Services\Privacidad\SolicitudesArco;
use App\Support\Tenancy\EmpresaDeTrabajo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

/**
 * Configuración → Privacidad (docs/tecnico/privacidad.md): días de
 * conservación de datos personales (y encender la tarea) y versiones del
 * aviso de privacidad. Acción «configuracion.datos_personales» con alcance
 * de empresa.
 */
class PrivacidadController extends Controller
{
    public function __construct(
        private readonly EmpresaDeTrabajo $empresa,
        private readonly Autorizador $autorizador,
    ) {}

    public function conservacion(Request $request): RedirectResponse
    {
        [$actor, $empresa] = $this->exigir($request);
        $antes = app(ConservacionDatos::class)->ajustes($empresa);
        $nuevos = app(ConservacionDatos::class)->guardar($actor, $empresa, $request->only([...array_keys(ConservacionDatos::DIAS), 'conservacion_activa', 'arco_recordar_dias']));

        $mensaje = match (true) {
            $nuevos['activa'] && ! $antes['activa'] => 'Listo: la conservación quedó encendida. Esta noche se quitarán los datos que ya pasaron sus días; el resumen llegará a la Bitácora de auditoría.',
            ! $nuevos['activa'] && $antes['activa'] => 'Listo: la conservación quedó apagada. No se quitará ningún dato hasta que la vuelvas a encender.',
            default => 'Días de conservación guardados.'.($nuevos['activa'] ? ' Se aplican en la revisión de esta noche.' : ' La tarea sigue apagada: enciéndela para que se apliquen.'),
        };

        return redirect()->to(route('configuracion.index').'#cfg-conservacion')->with('ok', $mensaje);
    }

    public function publicarAviso(Request $request): RedirectResponse
    {
        [$actor, $empresa] = $this->exigir($request);
        $nueva = app(AvisosPrivacidad::class)->publicar($actor, $empresa, (string) $request->input('texto', ''));

        return redirect()->to(route('configuracion.index').'#cfg-aviso')->with('ok', "Versión {$nueva->numero} del aviso publicada: desde ahora es la que aceptan en el kiosco, la solicitud de empleo y la bolsa de trabajo. Revisa cómo se ve en su página pública.");
    }

    public function version(Request $request, int $version): View
    {
        [, $empresa] = $this->exigir($request);
        $aviso = app(AvisosPrivacidad::class)->version($empresa->id, $version);
        abort_if($aviso === null, 404);

        return view('administracion.configuracion.aviso-version', ['aviso' => $aviso, 'empresa' => $empresa]);
    }

    /** @return array{0: User, 1: Empresa} */
    private function exigir(Request $request): array
    {
        $actor = $request->user();
        Gate::authorize(SolicitudesArco::PERMISO);
        abort_unless($this->autorizador->alcanceDeEmpresa($actor, SolicitudesArco::PERMISO), 403, 'La privacidad es de toda la empresa: hace falta el permiso «Datos personales» de Configuración con alcance de empresa.');
        $empresaId = $this->empresa->id($actor);
        abort_if($empresaId === null, 404);

        return [$actor, Empresa::findOrFail($empresaId)];
    }
}
