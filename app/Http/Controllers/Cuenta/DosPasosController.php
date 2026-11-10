<?php

namespace App\Http\Controllers\Cuenta;

use App\Http\Controllers\Controller;
use App\Http\Middleware\ExigirDosPasos;
use App\Services\DosPasos\Totp;
use App\Services\DosPasos\VerificacionDosPasos;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Mi cuenta → Verificación en dos pasos (sin permiso propio: la cuenta es de
 * su dueño, criterio 6). Activar (QR + clave para escribir a mano, confirmar
 * con un código), ver los códigos de respaldo una sola vez, crear códigos
 * nuevos y quitarla (contraseña + código). Ver docs/tecnico/dos-pasos.md.
 */
class DosPasosController extends Controller
{
    /** Clave nueva mientras la persona la confirma (solo en su sesión). */
    private const NUEVA = 'dos_pasos.nueva';

    public function __construct(private readonly VerificacionDosPasos $dosPasos) {}

    public function mostrar(Request $request): View|RedirectResponse
    {
        $usuario = $request->user()->fresh() ?? $request->user();
        $codigos = $request->session()->get('dos_pasos.codigos');

        if ($usuario->tieneDosPasos()) {
            if (! is_array($codigos)) {
                return redirect()->to(route('mi-cuenta.index').'#dos-pasos');
            }

            return view('cuenta.dos-pasos', ['codigos' => $codigos, 'activa' => true, 'obligatoria' => false]);
        }

        $secreto = $request->session()->get(self::NUEVA);
        if (! is_string($secreto) || strlen($secreto) !== 32) {
            $secreto = Totp::nuevoSecreto();
            $request->session()->put(self::NUEVA, $secreto);
        }
        $uri = Totp::uri($secreto, (string) ($usuario->email ?: $usuario->username), $this->dosPasos->emisor());

        return view('cuenta.dos-pasos', [
            'activa' => false,
            'codigos' => null,
            'qr' => (new Writer(new ImageRenderer(new RendererStyle(220, 1), new SvgImageBackEnd)))->writeString($uri),
            'uri' => $uri,
            'clave' => trim(chunk_split($secreto, 4, ' ')),
            'obligatoria' => (bool) $request->session()->get(ExigirDosPasos::MARCA) || $this->dosPasos->exigida($usuario),
        ]);
    }

    public function activar(Request $request): RedirectResponse
    {
        $usuario = $request->user();
        $datos = $request->validate(['codigo' => ['required', 'string', 'max:12']], [], ['codigo' => 'código']);
        $secreto = $request->session()->get(self::NUEVA);
        if ($usuario->tieneDosPasos() || ! is_string($secreto)) {
            return redirect()->route('mi-cuenta.dos-pasos');
        }

        $codigos = $this->dosPasos->activar($usuario, $secreto, $datos['codigo']);
        $request->session()->forget([self::NUEVA, ExigirDosPasos::MARCA]);

        return redirect()->route('mi-cuenta.dos-pasos')->with('dos_pasos.codigos', $codigos)
            ->with('ok', 'Listo: la verificación en dos pasos quedó activa. Guarda o imprime tus códigos de respaldo y después vuelve al inicio.');
    }

    public function codigos(Request $request): RedirectResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->tieneDosPasos(), 404);
        $datos = $request->validate(['contrasena_actual' => ['required', 'string', 'max:255']], [], ['contrasena_actual' => 'contraseña']);
        $codigos = $this->dosPasos->regenerarCodigos($usuario, $datos['contrasena_actual']);

        return redirect()->route('mi-cuenta.dos-pasos')->with('dos_pasos.codigos', $codigos)
            ->with('ok', 'Códigos nuevos listos: los anteriores ya no sirven. Guárdalos o imprímelos.');
    }

    public function desactivar(Request $request): RedirectResponse
    {
        $usuario = $request->user();
        abort_unless($usuario->tieneDosPasos(), 404);
        $datos = $request->validate([
            'contrasena_actual' => ['required', 'string', 'max:255'],
            'codigo' => ['required', 'string', 'max:20'],
        ], [], ['contrasena_actual' => 'contraseña', 'codigo' => 'código']);
        $this->dosPasos->desactivar($usuario, $datos['contrasena_actual'], $datos['codigo']);

        return redirect()->to(route('mi-cuenta.index').'#dos-pasos')
            ->withCookie($this->dosPasos->olvidarDispositivo($usuario))
            ->with('ok', 'Quitaste la verificación en dos pasos: ya solo se pide tu contraseña. Puedes volver a activarla aquí mismo.');
    }
}
