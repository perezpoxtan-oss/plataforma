<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cierra la sesion tras N minutos sin actividad, aunque la cookie siga viva,
 * y avisa en la pantalla de acceso que fue por seguridad.
 */
class ControlarInactividad
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() === null) {
            return $next($request);
        }

        $limite = (int) config('plataforma.sesion.inactividad_minutos') * 60;
        $ultima = (int) $request->session()->get('ultima_actividad', 0);
        $ahora = now()->getTimestamp();

        if ($ultima > 0 && ($ahora - $ultima) > $limite) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'mensaje' => 'Sesión finalizada por seguridad.'], 401);
            }

            return redirect()->route('login')->with('acceso', 'expirado');
        }

        $request->session()->put('ultima_actividad', $ahora);

        return $next($request);
    }
}
