<?php

namespace App\Http\Middleware;

use App\Http\Controllers\Publico\PlanesController;
use App\Services\Comercial\AjustesComerciales;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * «/» para visitantes SIN sesión: si la página de planes está publicada
 * (Catálogo comercial → Ajustes), se muestra ella en lugar de enviar a la
 * pantalla de acceso. Con sesión, o sin publicar, «/» sigue siendo Inicio
 * (el panel) como siempre.
 *
 * Va ANTES de «auth» en la lista de prioridad (bootstrap/app.php); si no,
 * Laravel ordena «auth» primero y la portada nunca se vería.
 */
class PortadaPublica
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() === null && $request->isMethodSafe() && app(AjustesComerciales::class)->landingPublicada()) {
            return app(PlanesController::class)->inicio();
        }

        // Con sesión, o sin página publicada: sigue «auth» (al acceso, recordando a dónde iba) y el Inicio
        return $next($request);
    }
}
