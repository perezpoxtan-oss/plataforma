<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Response;

/**
 * Firmas que llegan como ARCHIVO (ver docs/tecnico/firmas.md).
 *
 * El navegador (public/js/plataforma.js) ya no manda la firma como un texto
 * largo `data:image/...;base64,...` dentro del formulario —el firewall del
 * hospedaje (ModSecurity / Imunify360) bloqueaba o redirigía esos envíos—,
 * sino como archivo: `__firmas[]` (la imagen) + `__firmas_campos[]` (el
 * nombre del campo original, p. ej. `firma`, `firma_persona`,
 * `taxis[0][firma]`).
 *
 * Aquí, antes de los controladores, cada archivo válido (PNG o JPEG real, de
 * hasta 1 MB) se vuelve a poner en la petición como el valor de siempre
 * (`data:image/...;base64,...`) en su campo original. Así App\Services\Firmas\Firmas
 * y los controladores no cambian. Lo que no sea válido no se inyecta: el
 * controlador da su error normal («Falta la firma…»).
 *
 * No registra nada (ni datos ni imágenes).
 */
class FirmasComoArchivo
{
    public const MAXIMO_BYTES = 1024 * 1024;

    private const MAXIMO_FIRMAS = 20;

    /** Nombre de campo: `firma`, `firma_persona`, `taxis[0][firma]`… (sin `[]` vacíos). */
    private const NOMBRE_VALIDO = '/^[A-Za-z0-9_\-]{1,60}(\[[A-Za-z0-9_\-]{1,60}\]){0,4}$/';

    public function handle(Request $request, Closure $next): Response
    {
        if ($request->files->has('__firmas') || $request->request->has('__firmas_campos')) {
            $this->reinyectar($request);
        }

        return $next($request);
    }

    private function reinyectar(Request $request): void
    {
        $archivos = $request->files->all()['__firmas'] ?? [];
        $campos = $request->request->all()['__firmas_campos'] ?? [];
        $archivos = is_array($archivos) ? array_values($archivos) : [$archivos];
        $campos = is_array($campos) ? array_values($campos) : [$campos];

        // La entrada interna no llega a los controladores
        $request->files->remove('__firmas');
        $request->request->remove('__firmas');
        $request->request->remove('__firmas_campos');
        $this->olvidarArchivosConvertidos($request);

        $entrada = $request->request->all();
        foreach (array_slice($archivos, 0, self::MAXIMO_FIRMAS) as $i => $archivo) {
            $campo = $campos[$i] ?? null;
            if (! is_string($campo) || ! preg_match(self::NOMBRE_VALIDO, $campo) || str_starts_with($campo, '_')) {
                continue;
            }
            $valor = $this->comoDataUrl($archivo);
            if ($valor !== null) {
                data_set($entrada, $this->aPuntos($campo), $valor);
            }
        }
        $request->request->replace($entrada);
    }

    /** `data:image/...;base64,...` si es una imagen PNG/JPEG real de hasta 1 MB; si no, null. */
    private function comoDataUrl(mixed $archivo): ?string
    {
        if (! $archivo instanceof UploadedFile || ! $archivo->isValid()) {
            return null;
        }
        $tamano = (int) $archivo->getSize();
        if ($tamano < 1 || $tamano > self::MAXIMO_BYTES) {
            return null;
        }
        $binario = @file_get_contents($archivo->getRealPath() ?: '');
        if (! is_string($binario) || $binario === '' || strlen($binario) > self::MAXIMO_BYTES) {
            return null;
        }
        // Misma validación de tipo que App\Services\Firmas\Firmas (la imagen se re-dibuja al guardarla)
        $info = @getimagesizefromstring($binario);
        if ($info === false || $info[0] < 1 || $info[1] < 1) {
            return null;
        }
        $tipo = match ($info[2]) {
            IMAGETYPE_JPEG => 'jpeg',
            IMAGETYPE_PNG => 'png',
            default => null,
        };

        return $tipo === null ? null : "data:image/{$tipo};base64,".base64_encode($binario);
    }

    /** `taxis[0][firma]` → `taxis.0.firma`. */
    private function aPuntos(string $campo): string
    {
        return str_replace(['][', '[', ']'], ['.', '.', ''], $campo);
    }

    /** Laravel guarda en caché los archivos ya convertidos: se descartan para que no reaparezca `__firmas`. */
    private function olvidarArchivosConvertidos(Request $request): void
    {
        (function () {
            $this->convertedFiles = null;
        })->call($request);
    }
}
