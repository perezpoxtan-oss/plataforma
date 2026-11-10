<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Seguridad: solo se atienden peticiones dirigidas al dominio de la plataforma.
 *
 * Laravel arma los enlaces (formularios, redirecciones, correos de aviso y
 * códigos QR) con el encabezado Host de la petición. Si alguien envía un Host
 * falso, los correos que dispara su captura (p. ej. el aviso de alta
 * provisional) llevarían enlaces a un sitio ajeno. Con esta verificación,
 * cualquier Host que no sea el de APP_URL (o los de PLATAFORMA_HOSTS) recibe 400.
 *
 * Se activa sola fuera de "local" y "testing" (config plataforma.hosts).
 */
class VerificarHost
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('plataforma.hosts.verificar')) {
            return $next($request);
        }

        $permitidos = array_map('strtolower', (array) config('plataforma.hosts.permitidos'));
        // El mismo valor con el que Laravel arma los enlaces (sin puerto; si algún
        // día se confía en un proxy, ya incluye X-Forwarded-Host)
        $host = strtolower($request->getHost());

        if ($permitidos === [] || ! in_array($host, $permitidos, true)) {
            abort(400, 'Dominio no reconocido.');
        }

        return $next($request);
    }
}
