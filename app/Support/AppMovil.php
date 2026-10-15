<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Plataforma instalable (PWA) y app de Android (Capacitor, carpeta movil/).
 * Ver docs/tecnico/app-movil.md.
 */
class AppMovil
{
    /** Texto que la app agrega al user-agent (movil/capacitor.config.js, appendUserAgent). */
    public const MARCA_AGENTE = 'SmartCentinelApp/';

    /** Nombre de la app instalada cuando Identidad conserva su nombre por omisión. */
    public const NOMBRE = 'Smart Centinel';

    public const NOMBRE_CORTO = 'Centinel';

    /** ¿La petición viene de la app de Android? */
    public static function es(?Request $request = null): bool
    {
        $request ??= request();

        return str_contains((string) $request->userAgent(), self::MARCA_AGENTE);
    }
}
