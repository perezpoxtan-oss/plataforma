<?php

namespace App\Http\Controllers\Publico;

use App\Http\Controllers\Controller;
use App\Support\AppMovil;
use App\Support\Identidad;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;

/**
 * Plataforma instalable (PWA). Las tres rutas van SIN el grupo «web»:
 * no abren sesión, no envían cookies y no dependen del usuario (ver routes/web.php).
 */
class PwaController extends Controller
{
    public function manifest(Identidad $identidad): JsonResponse
    {
        $porOmision = Identidad::VALORES_POR_DEFECTO;
        $nombre = $identidad->get('nombre') !== $porOmision['nombre'] ? $identidad->get('nombre') : AppMovil::NOMBRE;
        $corto = $identidad->get('nombre_corto') !== $porOmision['nombre_corto'] ? $identidad->get('nombre_corto') : AppMovil::NOMBRE_CORTO;

        $icono = fn (string $archivo, int $lado, string $uso) => [
            'src' => asset('iconos/'.$archivo),
            'sizes' => $lado.'x'.$lado,
            'type' => 'image/png',
            'purpose' => $uso,
        ];

        $manifest = [
            'id' => '/',
            'name' => $nombre,
            'short_name' => $corto,
            'description' => $identidad->get('eslogan'),
            'lang' => 'es-MX',
            'dir' => 'ltr',
            'start_url' => '/',
            'scope' => '/',
            'display' => 'standalone',
            'orientation' => 'any',
            'background_color' => '#ffffff',
            'theme_color' => $identidad->get('color_primario'),
            'icons' => [
                $icono('app-192.png', 192, 'any'),
                $icono('app-512.png', 512, 'any'),
                $icono('app-maskable-192.png', 192, 'maskable'),
                $icono('app-maskable-512.png', 512, 'maskable'),
            ],
        ];

        return response()->json($manifest, 200, [
            'Content-Type' => 'application/manifest+json; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** Service worker en la raíz (alcance «/»); el código vive en public/js/sw.js. */
    public function trabajador(): Response
    {
        $codigo = (string) @file_get_contents(public_path('js/sw.js'));
        abort_if($codigo === '', 404);
        $codigo = str_replace('__VERSION__', substr(md5($codigo), 0, 12), $codigo);

        return response($codigo, 200, [
            'Content-Type' => 'application/javascript; charset=UTF-8',
            // El navegador revisa siempre si cambió (nunca se queda con uno viejo)
            'Cache-Control' => 'no-cache',
            'Service-Worker-Allowed' => '/',
        ]);
    }

    /** Página que el service worker muestra cuando no hay internet. */
    public function sinConexion(): Response
    {
        return response()->view('publico.sin-conexion')
            ->header('Cache-Control', 'no-cache');
    }
}
