<?php

namespace App\Http\Controllers\Publico;

use App\Http\Controllers\Controller;
use App\Models\Empresa;
use App\Models\SolicitudArco;
use App\Services\Privacidad\AvisosPrivacidad;
use App\Services\Privacidad\SolicitudesArco;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Privacidad pública, SIN sesión (docs/tecnico/privacidad.md):
 *  - /privacidad/{codigo}: aviso vigente de una empresa (con su versión) y
 *    el botón para ejercer los derechos ARCO;
 *  - /privacidad/solicitud y /privacidad/{codigo}/solicitud: formulario
 *    sencillo (con campo trampa y límite de peticiones por equipo);
 *  - /privacidad/verificar/{token}: la persona confirma su correo (POST).
 * Las respuestas no revelan datos de nadie.
 */
class PrivacidadController extends Controller
{
    public const TRAMPA = 'sitio_web';

    public function __construct(
        private readonly AvisosPrivacidad $avisos,
        private readonly SolicitudesArco $solicitudes,
    ) {}

    public function aviso(string $codigo): View
    {
        $empresa = $this->empresa($codigo);
        $vigente = $this->avisos->vigente($empresa);

        return view('publico.privacidad.aviso', ['empresa' => $empresa, 'aviso' => $vigente, 'codigo' => $codigo]);
    }

    public function formulario(?string $codigo = null): View
    {
        $empresa = $codigo === null ? null : $this->empresa($codigo);

        return view('publico.privacidad.solicitud', ['empresa' => $empresa, 'codigo' => $codigo]);
    }

    public function guardar(Request $request, ?string $codigo = null): RedirectResponse
    {
        $empresa = $codigo === null ? null : $this->empresa($codigo);
        $volver = $codigo === null ? route('privacidad.solicitud') : route('privacidad.empresa.solicitud', $codigo);
        // Campo trampa: un robot lo llena; se responde igual que si todo saliera bien, sin guardar nada
        if (filled($request->input(self::TRAMPA))) {
            return redirect()->to($volver)->with('enviada', true);
        }
        [, $correo] = $this->solicitudes->crear($empresa, $request->only(['derecho', 'nombre', 'correo', 'telefono', 'relacion', 'detalle', 'acepta']), (string) $request->ip());

        return redirect()->to($volver)->with('enviada', true)->with('sin_correo', ! $correo);
    }

    public function verificar(string $token): View
    {
        return view('publico.privacidad.verificar', ['solicitud' => $this->solicitudes->porToken($token), 'token' => $token, 'confirmada' => false]);
    }

    public function confirmar(string $token): View
    {
        $s = $this->solicitudes->porToken($token);
        if ($s !== null) {
            $this->solicitudes->confirmarCorreo($s);
            $s->refresh();
        }

        return view('publico.privacidad.verificar', ['solicitud' => $s, 'token' => $token, 'confirmada' => $s !== null]);
    }

    private function empresa(string $codigo): Empresa
    {
        $empresa = $this->avisos->empresaPorCodigo($codigo);
        abort_if($empresa === null, 404);

        return $empresa;
    }

    /** Folio y plazo para la pantalla de «confirmada». */
    public static function textoPlazo(SolicitudArco $s): string
    {
        return $s->vence_en ? 'Tenemos hasta el '.$s->vence_en->format('d/m/Y').' para responderte ('.SolicitudesArco::DIAS_HABILES.' días hábiles).' : '';
    }
}
