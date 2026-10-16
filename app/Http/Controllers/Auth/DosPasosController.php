<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\DosPasos\VerificacionDosPasos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\View\View;

/**
 * Segundo paso del inicio de sesión (docs/tecnico/dos-pasos.md): después de
 * la contraseña correcta, el código de la app de autenticación o uno de
 * respaldo. La cuenta queda «pendiente» en la sesión unos minutos; tras
 * INTENTOS códigos equivocados se vuelve a pedir la contraseña.
 */
class DosPasosController extends Controller
{
    public const PENDIENTE = 'dos_pasos.pendiente';

    public function __construct(private readonly VerificacionDosPasos $dosPasos) {}

    public function mostrar(Request $request): View|RedirectResponse
    {
        if ($this->pendiente($request) === null) {
            return redirect()->route('login');
        }

        return view('auth.dos-pasos');
    }

    public function verificar(Request $request): RedirectResponse
    {
        $usuario = $this->pendiente($request);
        if ($usuario === null) {
            return redirect()->route('login')->with('acceso', 'dos_pasos_vencido');
        }
        $datos = $request->validate(['codigo' => ['required', 'string', 'max:20']], [], ['codigo' => 'código']);

        $llave = 'dos-pasos:'.$usuario->id;
        if (RateLimiter::tooManyAttempts($llave, VerificacionDosPasos::INTENTOS)) {
            return $this->vencer($request, 'dos_pasos_intentos');
        }

        $tipo = $this->dosPasos->verificarCodigo($usuario, $datos['codigo']);
        if ($tipo === null) {
            RateLimiter::hit($llave, 15 * 60);
            $this->dosPasos->auditarFallo($usuario, 'inicio', $request->ip());
            if (RateLimiter::tooManyAttempts($llave, VerificacionDosPasos::INTENTOS)) {
                return $this->vencer($request, 'dos_pasos_intentos');
            }

            return back()->withErrors(['codigo' => 'El código no es correcto. Escribe el que se ve ahora en tu app (cambia cada 30 segundos) o uno de tus códigos de respaldo.']);
        }

        RateLimiter::clear($llave);
        $request->session()->forget(self::PENDIENTE);
        $respuesta = SesionController::completarInicio($request, $usuario, $this->dosPasos);
        if ($request->boolean('recordar')) {
            $respuesta->withCookie($this->dosPasos->cookieRecordar($usuario));
        }
        if ($tipo === 'respaldo') {
            $quedan = $this->dosPasos->codigosRestantes($usuario->refresh());
            $respuesta->with('aviso', "Entraste con un código de respaldo; ya no sirve otra vez. Te quedan {$quedan}."
                .($quedan <= 3 ? ' Crea códigos nuevos en Mi cuenta → Verificación en dos pasos.' : ''));
        }

        return $respuesta;
    }

    /** «Volver a entrar»: olvida la cuenta pendiente. */
    public function cancelar(Request $request): RedirectResponse
    {
        $request->session()->forget(self::PENDIENTE);

        return redirect()->route('login');
    }

    private function pendiente(Request $request): ?User
    {
        $p = $request->session()->get(self::PENDIENTE);
        if (! is_array($p) || (int) ($p['desde'] ?? 0) < now()->subMinutes(VerificacionDosPasos::MINUTOS_PENDIENTE)->getTimestamp()) {
            return null;
        }
        $usuario = User::with('empresa')->where('activo', true)->find((int) ($p['id'] ?? 0));
        if ($usuario === null || ! $usuario->tieneDosPasos() || (! $usuario->es_superadmin && ! $usuario->empresa?->activo)) {
            return null;
        }

        return $usuario;
    }

    private function vencer(Request $request, string $motivo): RedirectResponse
    {
        $request->session()->forget(self::PENDIENTE);

        return redirect()->route('login')->with('acceso', $motivo);
    }
}
