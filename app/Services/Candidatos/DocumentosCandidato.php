<?php

namespace App\Services\Candidatos;

use App\Models\Candidato;
use App\Models\CandidatoDocumento;
use App\Support\ImagenSegura;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documentos del candidato (CV en PDF, INE, comprobante) y fotos de la caseta.
 *
 * Seguridad:
 *  - disco PRIVADO (storage/app/private/candidatos/<empresa>/...), nunca public;
 *  - solo PDF, JPG o PNG, revisando el contenido real (no la extensión);
 *  - las imágenes se vuelven a dibujar (ImagenSegura): sin código pegado ni
 *    datos EXIF (ubicación del celular);
 *  - nombre aleatorio; se entregan solo a través del controlador, con permiso.
 */
class DocumentosCandidato
{
    public const DISCO = 'local';

    public const MAXIMO_KB = 5120;

    public function subir(Candidato $candidato, UploadedFile $archivo, string $tipo, string $origen, string $campo = 'documento'): CandidatoDocumento
    {
        if (! $archivo->isValid()) {
            throw ValidationException::withMessages([$campo => 'No se pudo recibir el archivo. Inténtalo de nuevo.']);
        }
        if ($archivo->getSize() > self::MAXIMO_KB * 1024) {
            throw ValidationException::withMessages([$campo => 'El archivo pesa más de 5 MB. Toma la foto con menos calidad o reduce el PDF.']);
        }
        $binario = (string) file_get_contents($archivo->getRealPath());
        $esPdf = str_starts_with($binario, '%PDF-');
        if ($esPdf) {
            $extension = 'pdf';
            $mime = 'application/pdf';
        } else {
            [$binario, $extension] = ImagenSegura::recodificar($binario, $campo, [IMAGETYPE_JPEG, IMAGETYPE_PNG],
                'El archivo debe ser un PDF o una foto JPG o PNG.');
            $mime = $extension === 'png' ? 'image/png' : 'image/jpeg';
        }

        $ruta = 'candidatos/'.$candidato->empresa_id.'/'.$candidato->id.'/'.Str::uuid().'.'.$extension;
        Storage::disk(self::DISCO)->put($ruta, $binario);

        $nombre = trim((string) preg_replace('/[^\pL\pN ._()-]+/u', '', $archivo->getClientOriginalName())) ?: 'documento.'.$extension;
        $documento = new CandidatoDocumento([
            'candidato_id' => $candidato->id, 'tipo' => array_key_exists($tipo, CandidatoDocumento::TIPOS) ? $tipo : 'otro',
            'nombre_original' => mb_substr($nombre, 0, 150), 'ruta' => $ruta, 'mime' => $mime, 'bytes' => strlen($binario), 'origen' => $origen,
        ]);
        $documento->forceFill(['empresa_id' => $candidato->empresa_id])->save();

        return $documento;
    }

    /**
     * Foto de la persona o de su identificación (caseta): JPG/PNG/WEBP,
     * redibujada y reducida, en el disco privado.
     */
    public function guardarFoto(int $empresaId, UploadedFile $archivo, string $campo): string
    {
        if (! $archivo->isValid() || $archivo->getSize() > 8 * 1024 * 1024) {
            throw ValidationException::withMessages([$campo => 'No se pudo recibir la foto (máximo 8 MB). Tómala de nuevo.']);
        }
        [$binario, $extension] = ImagenSegura::recodificar((string) file_get_contents($archivo->getRealPath()), $campo, null,
            'La foto no es válida: toma la foto con la cámara o sube un JPG o PNG.');
        $reducida = $this->reducir($binario);
        if ($reducida !== null) {
            [$binario, $extension] = [$reducida, 'jpg'];
        }
        $ruta = 'accesos/'.$empresaId.'/fotos/'.now()->format('Y/m').'/'.Str::uuid().'.'.$extension;
        Storage::disk(self::DISCO)->put($ruta, $binario);

        return $ruta;
    }

    /** Las fotos del celular pesan mucho: se guardan a 1280 px como máximo (JPG). */
    private function reducir(string $binario): ?string
    {
        $imagen = @imagecreatefromstring($binario);
        if ($imagen === false) {
            return null;
        }
        $ancho = imagesx($imagen);
        $alto = imagesy($imagen);
        $max = 1280;
        if ($ancho <= $max && $alto <= $max) {
            return null;
        }
        $escala = $max / max($ancho, $alto);
        $nueva = imagescale($imagen, (int) round($ancho * $escala), (int) round($alto * $escala));
        if ($nueva === false) {
            return null;
        }
        ob_start();
        imagejpeg($nueva, null, 85);

        return (string) ob_get_clean();
    }

    /**
     * Entrega un archivo privado (el controlador ya revisó permiso y alcance).
     * Los PDF se descargan (no se abren dentro de la plataforma).
     */
    public function respuesta(?string $ruta, ?string $nombre = null, bool $descargar = false): StreamedResponse
    {
        abort_if($ruta === null || str_contains($ruta, '..') || ! (str_starts_with($ruta, 'candidatos/') || str_starts_with($ruta, 'accesos/')), 404);
        abort_unless(Storage::disk(self::DISCO)->exists($ruta), 404);
        $cabeceras = ['Cache-Control' => 'private, max-age=300', 'X-Content-Type-Options' => 'nosniff'];

        return $descargar
            ? Storage::disk(self::DISCO)->download($ruta, $nombre, $cabeceras)
            : Storage::disk(self::DISCO)->response($ruta, null, $cabeceras);
    }

    public function borrar(?string $ruta): void
    {
        if ($ruta !== null && (str_starts_with($ruta, 'candidatos/') || str_starts_with($ruta, 'accesos/'))) {
            Storage::disk(self::DISCO)->delete($ruta);
        }
    }
}
