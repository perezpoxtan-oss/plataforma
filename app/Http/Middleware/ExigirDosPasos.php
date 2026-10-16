<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verificación en dos pasos obligatoria (docs/tecnico/dos-pasos.md): si al
 * entrar la cuenta debía tenerla y no la tenía (Super Administrador o la
 * política de su empresa), la sesión lleva una marca y solo quedan abiertas
 * Mi cuenta (para activarla), salir y el latido. Al activarla, la marca se quita.
 */
class ExigirDosPasos
{
    public const MARCA = 'dos_pasos.exigir';

    public const PERMITIDAS = [
        'mi-cuenta.index', 'mi-cuenta.contrasena', 'mi-cuenta.dos-pasos', 'mi-cuenta.dos-pasos.activar',
        'logout', 'sesion.latido', 'sesion.expirada', 'sesion.expirada.cerrar', 'notificaciones.resumen',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();
        if ($usuario === null || ! $request->hasSession() || ! $request->session()->get(self::MARCA)) {
            return $next($request);
        }
        if ($usuario->tieneDosPasos()) {
            $request->session()->forget(self::MARCA);

            return $next($request);
        }
        if (in_array((string) $request->route()?->getName(), self::PERMITIDAS, true)) {
            return $next($request);
        }
        if ($request->expectsJson()) {
            return response()->json(['ok' => false, 'mensaje' => 'Primero activa la verificación en dos pasos en Mi cuenta.'], 403);
        }

        return redirect()->route('mi-cuenta.dos-pasos')->with('aviso', 'Antes de continuar, activa la verificación en dos pasos: tu cuenta la necesita.');
    }
}
