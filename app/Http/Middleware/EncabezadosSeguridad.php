<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Seguridad: encabezados HTTP de endurecimiento en todas las respuestas.
 *
 *  - X-Content-Type-Options: el navegador no "adivina" tipos (un archivo
 *    subido no se interpreta como HTML/JS).
 *  - X-Frame-Options: la plataforma no se puede incrustar en otro sitio
 *    (clickjacking).
 *  - Referrer-Policy: los enlaces externos no reciben la ruta interna.
 *  - Permissions-Policy: solo se permiten la cámara (lector QR) y el puerto
 *    serie (lector RFID) del propio sitio; nada de micrófono ni ubicación.
 *  - Strict-Transport-Security: solo cuando la petición llegó por HTTPS.
 *
 * Si una pantalla ya fijó alguno de estos encabezados, se respeta el suyo.
 */
class EncabezadosSeguridad
{
    public const ENCABEZADOS = [
        'X-Content-Type-Options' => 'nosniff',
        'X-Frame-Options' => 'SAMEORIGIN',
        'Referrer-Policy' => 'strict-origin-when-cross-origin',
        'Permissions-Policy' => 'camera=(self), serial=(self), microphone=(), geolocation=(), payment=(), usb=()',
        'Cross-Origin-Opener-Policy' => 'same-origin',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $respuesta = $next($request);

        foreach (self::ENCABEZADOS as $nombre => $valor) {
            if (! $respuesta->headers->has($nombre)) {
                $respuesta->headers->set($nombre, $valor);
            }
        }

        if ($request->isSecure() && ! $respuesta->headers->has('Strict-Transport-Security')) {
            // Sin includeSubDomains: otros subdominios de la empresa podrían no tener HTTPS
            $respuesta->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        return $respuesta;
    }
}
