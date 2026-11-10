<?php

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;

/**
 * Simula lo que hace public/js/plataforma.js al enviar un formulario con
 * firma: cada valor `data:image/...;base64,...` viaja como archivo
 * `__firmas[]` con su nombre de campo en `__firmas_campos[]`, y el campo
 * original va vacío (ver App\Http\Middleware\FirmasComoArchivo).
 */
trait EnviaFirmasComoArchivo
{
    /** @param  array<string, mixed>  $datos */
    protected function firmasComoArchivo(array $datos): array
    {
        $archivos = [];
        $campos = [];
        $recorrer = function (array &$nivel, string $prefijo) use (&$recorrer, &$archivos, &$campos): void {
            foreach ($nivel as $clave => &$valor) {
                $nombre = $prefijo === '' ? (string) $clave : $prefijo.'['.$clave.']';
                if (is_array($valor)) {
                    $recorrer($valor, $nombre);
                } elseif (is_string($valor) && preg_match('#^data:image/(png|jpeg);base64,(.+)$#', $valor, $m)) {
                    $archivos[] = UploadedFile::fake()->createWithContent($m[1] === 'png' ? 'firma.png' : 'firma.jpg', (string) base64_decode($m[2]));
                    $campos[] = $nombre;
                    $valor = '';
                }
            }
        };
        $recorrer($datos, '');

        return $datos + ['__firmas' => $archivos, '__firmas_campos' => $campos];
    }
}
