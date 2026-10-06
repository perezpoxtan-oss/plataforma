<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Cabeceras de seguridad en todas las respuestas (ver docs/seguridad/auditoria-2026-10-06-autenticacion.md):
 *  - CSP: solo scripts propios (la plataforma no usa JavaScript en línea) y la
 *    pantalla no se puede incrustar en otro sitio (clickjacking).
 *  - Cámara solo para el propio sitio (lector QR); micrófono, ubicación, etc. apagados.
 *  - HSTS cuando la petición llega por HTTPS.
 *  - Pantallas con sesión iniciada: no se guardan en la caché del navegador
 *    (en una caseta compartida, "Atrás" tras cerrar sesión no muestra datos).
 */
class CabecerasSeguridad
{
    public const CSP = "default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; "
        ."img-src 'self' data: blob:; font-src 'self' data:; connect-src 'self'; media-src 'self' blob:; "
        ."worker-src 'self' blob:; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'self'";

    public const PERMISOS = 'camera=(self), microphone=(), geolocation=(), payment=(), usb=(), serial=(self), hid=(), bluetooth=()';

    public function handle(Request $request, Closure $next): Response
    {
        $respuesta = $next($request);

        // No anunciar la versión de PHP
        if (! headers_sent()) {
            header_remove('X-Powered-By');
        }
        $respuesta->headers->remove('X-Powered-By');

        $cabeceras = [
            'Content-Security-Policy' => self::CSP,
            'X-Frame-Options' => 'SAMEORIGIN',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'same-origin',
            'Permissions-Policy' => self::PERMISOS,
            'Cross-Origin-Opener-Policy' => 'same-origin',
        ];

        foreach ($cabeceras as $nombre => $valor) {
            if (! $respuesta->headers->has($nombre)) {
                $respuesta->headers->set($nombre, $valor);
            }
        }

        if ($request->isSecure()) {
            $respuesta->headers->set('Strict-Transport-Security', 'max-age=31536000');
        }

        $conSesion = $request->hasSession() && $request->user() !== null;
        $esArchivo = $respuesta instanceof BinaryFileResponse || $respuesta instanceof StreamedResponse;
        if ($conSesion && ! $esArchivo) {
            $respuesta->headers->set('Cache-Control', 'no-store, private');
        }

        return $respuesta;
    }
}
