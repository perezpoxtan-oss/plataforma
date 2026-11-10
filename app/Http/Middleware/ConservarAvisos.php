<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Las consultas en segundo plano (fetch de la pantalla: «¿Es alguno de
 * estos?», sugerencias, Mis pendientes, latido…) no gastan los avisos de un
 * solo uso de la sesión (errores de validación, datos capturados y mensajes
 * verdes o rojos).
 *
 * Sin esto, una consulta que sale justo al enviar un formulario (por ejemplo,
 * la de parecidos al salir del campo Nombre en Nuevo Ingreso) llega al
 * servidor entre el envío y la recarga de la pantalla y se lleva el aviso: la
 * ventana se cierra sin decir qué faltó y no se guarda nada.
 *
 * Solo aplica a lecturas (GET/HEAD) hechas por código de la pantalla; la
 * pantalla que se recarga sí consume los avisos como siempre.
 */
class ConservarAvisos
{
    public function handle(Request $request, Closure $next): Response
    {
        $respuesta = $next($request);

        if ($request->isMethodSafe() && ($request->ajax() || $request->expectsJson()) && $request->hasSession()) {
            // Lo que llegó del envío anterior sigue disponible para la siguiente pantalla
            $request->session()->reflash();
        }

        return $respuesta;
    }
}
