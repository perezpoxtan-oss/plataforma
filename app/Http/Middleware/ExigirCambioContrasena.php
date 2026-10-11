<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cambio obligatorio al primer acceso (docs/criterios-ux.md, regla 6): si la
 * cuenta nació o se restableció con una contraseña que conoce el
 * administrador, la persona la cambia en Mi cuenta antes de usar cualquier
 * otra pantalla. Solo quedan abiertas Mi cuenta, salir y el latido.
 */
class ExigirCambioContrasena
{
    public const PERMITIDAS = ['mi-cuenta.index', 'mi-cuenta.contrasena', 'logout', 'sesion.latido', 'sesion.expirada', 'sesion.expirada.cerrar', 'notificaciones.resumen'];

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();
        if ($usuario === null || ! $usuario->debe_cambiar_contrasena || in_array((string) $request->route()?->getName(), self::PERMITIDAS, true)) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => false, 'mensaje' => 'Primero cambia tu contraseña en Mi cuenta.'], 403);
        }

        return redirect()->route('mi-cuenta.index')->with('aviso', 'Antes de continuar, crea tu propia contraseña: la actual la conoce quien te dio de alta.');
    }
}
