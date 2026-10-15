<?php

namespace App\Http\Middleware;

use App\Services\Suscripciones\EstadoCliente;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Suscripciones: una empresa cuya suscripción venció (pasó la gracia), se
 * suspendió o se dio de baja queda en SOLO CONSULTA. Regla central: ningún
 * usuario de esa empresa puede guardar nada (POST, PUT, PATCH, DELETE); sí
 * puede ver, exportar (son GET), cambiar su propia contraseña, cerrar sesión,
 * leer sus avisos y pedir la renovación desde «Tu suscripción».
 *
 * No aplica al Super Administrador ni a las empresas sin suscripción, ni a
 * las tareas automáticas (corren fuera de una petición web).
 */
class SoloConsulta
{
    public const PERMITIDAS = [
        'logout', 'sesion.latido', 'sesion.expirada', 'sesion.expirada.cerrar',
        'mi-cuenta.contrasena', 'mi-cuenta.avisos',
        'notificaciones.leer-todas', 'notificaciones.abrir', 'notificaciones.resumen',
        'mi-suscripcion.solicitar',
    ];

    public function __construct(private readonly EstadoCliente $estado) {}

    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();
        if ($usuario === null || $request->isMethodSafe() || $usuario->es_superadmin
            || in_array((string) $request->route()?->getName(), self::PERMITIDAS, true)) {
            return $next($request);
        }
        $s = $this->estado->suscripcionDe($usuario);
        if ($s === null || ! $s->soloConsulta()) {
            return $next($request);
        }

        $mensaje = $this->estado->mensajeSoloConsulta($s);
        if ($request->expectsJson()) {
            return response()->json(['ok' => false, 'mensaje' => $mensaje], 423);
        }

        return redirect()->back(fallback: route('panel'))->withInput($request->except(['password', 'password_confirmation']))->with('error', $mensaje);
    }
}
