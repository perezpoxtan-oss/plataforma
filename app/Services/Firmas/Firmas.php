<?php

namespace App\Services\Firmas;

use App\Support\ImagenSegura;
use App\Support\Tenancy\Tenant;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Firmas autógrafas capturadas en pantalla (componentes/firma.blade.php):
 * responsivas, cierres de Lost & Found, pases de salida, vales de taxi,
 * accidentes…
 *
 * - Llegan como imagen JPEG/PNG en base64 desde el navegador.
 * - Se validan (tipo real, tamaño, que no esté vacía) y se guardan en el
 *   disco PRIVADO (storage/app/private/firmas/<empresa>/<carpeta>/), nunca en
 *   public: solo se ven a través de la pantalla de su módulo, con permiso.
 *
 * En SEGCAT se guardaban en uploads/ con nombres predecibles y accesibles
 * para cualquiera que adivinara la dirección.
 */
class Firmas
{
    public const MAXIMO_BYTES = 300 * 1024;

    private const DISCO = 'local';

    public function __construct(private readonly Tenant $tenant) {}

    /**
     * Valida y guarda una firma. Devuelve la ruta interna a guardar en la base.
     *
     * @param  string  $campo  nombre del campo (para el mensaje de error)
     * @param  string  $carpeta  p. ej. "responsivas", "pases-salida"
     */
    public function guardar(?string $dataUrl, string $carpeta, string $campo = 'firma', string $etiqueta = 'la firma'): string
    {
        $binario = $this->decodificar($dataUrl);
        if ($binario === null) {
            throw ValidationException::withMessages([$campo => "Falta {$etiqueta}: firma en el recuadro antes de guardar."]);
        }
        if (strlen($binario) > self::MAXIMO_BYTES) {
            throw ValidationException::withMessages([$campo => "La imagen de {$etiqueta} es demasiado grande; límpiala y vuelve a firmar."]);
        }
        $info = @getimagesizefromstring($binario);
        if ($info === false || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG], true) || $info[0] > 2000 || $info[1] > 1200) {
            throw ValidationException::withMessages([$campo => "No se pudo leer {$etiqueta}. Vuelve a firmar."]);
        }

        // Seguridad: se guarda la imagen re-dibujada, no los bytes que llegaron
        // (descarta HTML/JS pegado a la imagen y metadatos)
        [$binario, $extension] = ImagenSegura::recodificar($binario, $campo, [IMAGETYPE_JPEG, IMAGETYPE_PNG], "No se pudo leer {$etiqueta}. Vuelve a firmar.");

        $carpeta = preg_replace('/[^a-z0-9\-]/', '', strtolower($carpeta)) ?: 'general';
        $empresa = $this->tenant->empresaId() ?? 0;
        $ruta = "firmas/{$empresa}/{$carpeta}/".now()->format('Y/m').'/'.Str::uuid().'.'.$extension;
        Storage::disk(self::DISCO)->put($ruta, $binario);

        return $ruta;
    }

    /** ¿Trae una firma (no vacía)? Para firmas opcionales. */
    public function viene(?string $dataUrl): bool
    {
        return $this->decodificar($dataUrl) !== null;
    }

    /**
     * Muestra la firma. El controlador del módulo debe revisar antes el
     * permiso y que el registro sea de la empresa y sede del usuario.
     */
    public function respuesta(?string $ruta): StreamedResponse
    {
        $empresa = $this->tenant->empresaId();
        abort_if($ruta === null || $empresa === null || ! str_starts_with($ruta, "firmas/{$empresa}/") || str_contains($ruta, '..'), 404);
        abort_unless(Storage::disk(self::DISCO)->exists($ruta), 404);

        return Storage::disk(self::DISCO)->response($ruta, null, [
            'Cache-Control' => 'private, max-age=600',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function borrar(?string $ruta): void
    {
        if ($ruta !== null && str_starts_with($ruta, 'firmas/')) {
            Storage::disk(self::DISCO)->delete($ruta);
        }
    }

    private function decodificar(?string $dataUrl): ?string
    {
        if (! is_string($dataUrl) || ! preg_match('#^data:image/(jpeg|png);base64,([A-Za-z0-9+/=]+)$#', trim($dataUrl), $m)) {
            return null;
        }
        $binario = base64_decode($m[2], true);

        return $binario === false || strlen($binario) < 100 ? null : $binario;
    }
}
