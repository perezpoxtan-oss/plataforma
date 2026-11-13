<?php

namespace App\Http\Controllers\Publico;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Respaldo para las imágenes públicas subidas (logos de empresa e identidad de la plataforma).
 *
 * Normalmente Apache las entrega directo por el enlace public/storage. Si el hospedaje no
 * sigue ese enlace o no puede leer la carpeta compartida, la petición llega a Laravel y aquí
 * se entrega el archivo. Solo responde a los nombres que genera ImagenSegura (40 caracteres
 * aleatorios + png/jpg/webp) dentro de las dos carpetas públicas; la ruta lo limita con su
 * patrón, así que firmas, fotos y documentos (disco privado) nunca pasan por aquí.
 */
class ImagenPublicaController extends Controller
{
    /** Carpetas del disco «public» que se pueden servir por esta ruta. */
    public const CARPETAS = 'empresas/logos|identidad';

    private const TIPOS = ['png' => 'image/png', 'jpg' => 'image/jpeg', 'webp' => 'image/webp'];

    public function __invoke(string $ruta): BinaryFileResponse
    {
        $disco = Storage::disk('public');
        abort_unless($disco->exists($ruta), 404);

        return response()->file($disco->path($ruta), [
            'Content-Type' => self::TIPOS[pathinfo($ruta, PATHINFO_EXTENSION)],
            'Cache-Control' => 'public, max-age=604800',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; sandbox",
        ]);
    }
}
