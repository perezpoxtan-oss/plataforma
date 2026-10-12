<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cuando el navegador abre como página (GET) una dirección que solo sirve para
 * enviar un formulario (POST) —al recargar, al volver atrás, cuando el
 * teléfono restaura una pestaña o al iniciar sesión de nuevo—, en lugar del
 * error 405 se regresa a la pantalla de donde salió el envío. Ahí la persona
 * ve si lo que guardó ya quedó (p. ej. «Firmaste «Leí y entendí»…»).
 *
 * Solo direcciones de este mismo sitio y solo pantallas que existen como GET.
 */
class RegresoTrasEnvio
{
    /** Destinos conocidos (patrón de la ruta de envío → pantalla a la que regresa). */
    private const CONOCIDOS = [
        '#^procedimientos/(\d+)/acuse$#' => 'procedimientos/$1/leer#acuse',
    ];

    public static function destino(Request $request): ?string
    {
        $ruta = trim($request->path(), '/');

        foreach (self::CONOCIDOS as $patron => $destino) {
            if (preg_match($patron, $ruta)) {
                return url((string) preg_replace($patron, $destino, $ruta));
            }
        }

        // Si no es conocido: la pantalla «padre» más cercana que se abra con GET
        $partes = explode('/', $ruta);
        /** @var Router $router */
        $router = app('router');
        for ($n = count($partes) - 1; $n >= 1; $n--) {
            $candidato = implode('/', array_slice($partes, 0, $n));
            try {
                $router->getRoutes()->match(Request::create('/'.$candidato, 'GET'));

                return url($candidato);
            } catch (Throwable) {
                continue;
            }
        }

        return null;
    }

    /** Deja constancia para diagnosticar (sin datos del formulario ni del usuario). */
    public static function registrar(Request $request, ?string $destino): void
    {
        $referencia = (string) $request->headers->get('referer', '');
        Log::warning('Dirección de envío abierta como página (405)', [
            'metodo' => $request->method(),
            'ruta' => '/'.trim($request->path(), '/'),
            'regresa_a' => $destino,
            'viene_de' => $referencia !== '' ? parse_url($referencia, PHP_URL_PATH) : null,
            'navegador' => mb_substr((string) $request->userAgent(), 0, 160),
        ]);
    }
}
