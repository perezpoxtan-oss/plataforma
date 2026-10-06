<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Imágenes subidas por los usuarios (logos, símbolo, ícono, firmas).
 *
 * Seguridad: no se guarda el archivo tal como llegó. Se vuelve a dibujar con
 * GD y se guarda la copia limpia, con nombre aleatorio y extensión según su
 * tipo real. Así se descartan los "políglotas" (una imagen válida con HTML,
 * JavaScript o PHP pegado al final o escondido en los metadatos), los datos
 * EXIF (ubicación, cámara) y cualquier truco con el nombre del archivo.
 */
final class ImagenSegura
{
    /** Tipos aceptados => extensión con que se guardan. */
    private const TIPOS = [IMAGETYPE_PNG => 'png', IMAGETYPE_JPEG => 'jpg', IMAGETYPE_WEBP => 'webp'];

    /**
     * Re-codifica la imagen subida y la guarda en el disco indicado.
     * Devuelve la ruta relativa dentro del disco.
     *
     * @throws ValidationException si no es una imagen PNG, JPG o WEBP legible
     */
    public static function guardar(UploadedFile $archivo, string $carpeta, string $campo, string $disco = 'public'): string
    {
        [$contenido, $extension] = self::recodificar((string) file_get_contents($archivo->getRealPath()), $campo);
        $ruta = trim($carpeta, '/').'/'.Str::random(40).'.'.$extension;
        Storage::disk($disco)->put($ruta, $contenido);

        return $ruta;
    }

    /**
     * @param  list<int>|null  $permitidos  tipos IMAGETYPE_* aceptados (por defecto PNG, JPEG y WEBP)
     * @return array{0: string, 1: string} [bytes limpios, extensión]
     *
     * @throws ValidationException
     */
    public static function recodificar(string $binario, string $campo, ?array $permitidos = null, string $mensaje = 'La imagen no es válida: sube un PNG, JPG o WEBP.'): array
    {
        $permitidos ??= array_keys(self::TIPOS);
        $info = @getimagesizefromstring($binario);
        if ($info === false || ! in_array($info[2], $permitidos, true) || ! isset(self::TIPOS[$info[2]])) {
            throw ValidationException::withMessages([$campo => $mensaje]);
        }

        $imagen = @imagecreatefromstring($binario);
        if ($imagen === false) {
            throw ValidationException::withMessages([$campo => $mensaje]);
        }

        ob_start();
        try {
            $ok = match ($info[2]) {
                IMAGETYPE_JPEG => imagejpeg($imagen, null, 90),
                IMAGETYPE_PNG => self::conTransparencia($imagen) && imagepng($imagen, null, 9),
                IMAGETYPE_WEBP => self::conTransparencia($imagen) && imagewebp($imagen, null, 90),
            };
            $limpio = (string) ob_get_contents();
        } finally {
            ob_end_clean();
        }

        if (! $ok || $limpio === '') {
            throw ValidationException::withMessages([$campo => $mensaje]);
        }

        return [$limpio, self::TIPOS[$info[2]]];
    }

    private static function conTransparencia(\GdImage $imagen): bool
    {
        imagealphablending($imagen, false);

        return imagesavealpha($imagen, true);
    }
}
