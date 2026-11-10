<?php

namespace App\Services\AppMovil;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * La app de Android que trae la versión instalada de la plataforma
 * (Mi cuenta → App para tu celular). La compila la CI (.github/workflows/apk.yml),
 * viaja dentro del paquete del servidor y desplegar.sh la copia a una carpeta NO
 * pública (config app_movil.carpeta):
 *   app-movil.json                 versión, fecha, sha256, tamaño, huella de la firma…
 *   smart-centinel-<sha256>.apk    el archivo
 *   historial.jsonl                las últimas versiones instaladas (una por renglón)
 * Es de la plataforma: no lleva datos de ninguna empresa. Ver docs/tecnico/app-movil.md.
 */
class ApkInstalada
{
    public const TIPO = 'application/vnd.android.package-archive';

    /**
     * Datos de la app disponible, o null si esta versión de la plataforma no trae app
     * (o lo instalado no cuadra: JSON roto, archivo faltante o de otro tamaño).
     *
     * @return array{version: string, version_code: int|null, servidor: string|null, commit: string|null, fecha: Carbon|null, instalado_en: Carbon|null, sha256: string, tamano: int, huella_firma: string|null, firma: string|null, app: string|null, ruta: string}|null
     */
    public function actual(): ?array
    {
        $carpeta = $this->carpeta();
        $archivo = $carpeta.'/app-movil.json';
        if (! is_file($archivo)) {
            return null;
        }
        $datos = $this->normalizar(json_decode((string) @file_get_contents($archivo), true));
        if ($datos === null) {
            Log::warning('App de Android: app-movil.json no es válido; la pantalla dice que no hay app.');

            return null;
        }
        $ruta = $carpeta.'/smart-centinel-'.$datos['sha256'].'.apk';
        clearstatcache(true, $ruta);
        if (! is_file($ruta) || filesize($ruta) !== $datos['tamano']) {
            Log::warning('App de Android: falta el APK de app-movil.json o su tamaño no coincide.');

            return null;
        }

        return [...$datos, 'ruta' => $ruta];
    }

    /**
     * Últimas versiones instaladas, de la más reciente a la más antigua (solo Super Administrador).
     *
     * @return list<array<string, mixed>>
     */
    public function historial(int $maximo = 10): array
    {
        $archivo = $this->carpeta().'/historial.jsonl';
        if (! is_file($archivo)) {
            return [];
        }
        $renglones = file($archivo, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        $lista = [];
        foreach (array_reverse($renglones) as $renglon) {
            $datos = $this->normalizar(json_decode($renglon, true));
            if ($datos !== null) {
                $lista[] = $datos;
            }
            if (count($lista) >= $maximo) {
                break;
            }
        }

        return $lista;
    }

    /** Nombre con el que se guarda en el teléfono: smart-centinel-<versión>.apk */
    public function nombreDescarga(array $apk): string
    {
        return 'smart-centinel-'.$apk['version'].'.apk';
    }

    /**
     * Huella de la llave con la que se firmó la app instalada (para App Links),
     * solo si es la firma de publicación y del paquete configurado.
     */
    public function huellaFirma(): ?string
    {
        $apk = $this->actual();
        if ($apk === null || $apk['firma'] !== 'publicacion' || $apk['huella_firma'] === null) {
            return null;
        }
        if ($apk['app'] !== null && $apk['app'] !== trim((string) config('app_movil.paquete'))) {
            return null;
        }

        return $apk['huella_firma'];
    }

    private function carpeta(): string
    {
        return rtrim((string) config('app_movil.carpeta'), '/');
    }

    /**
     * Valida el JSON (lo escribe la CI, pero se trata como dato externo).
     *
     * @return array<string, mixed>|null
     */
    private function normalizar(mixed $j): ?array
    {
        if (! is_array($j)
            || ! is_string($j['sha256'] ?? null) || ! preg_match('/^[0-9a-f]{64}$/', $j['sha256'])
            || ! is_string($j['version'] ?? null) || ! preg_match('/^[A-Za-z0-9._-]{1,40}$/', $j['version'])
            || ! is_int($j['tamano'] ?? null) || $j['tamano'] <= 0) {
            return null;
        }
        $texto = fn (string $clave, string $patron) => is_string($j[$clave] ?? null) && preg_match($patron, $j[$clave]) ? $j[$clave] : null;
        $fecha = function (string $clave) use ($j): ?Carbon {
            try {
                return is_string($j[$clave] ?? null) ? Carbon::parse($j[$clave]) : null;
            } catch (\Throwable) {
                return null;
            }
        };

        return [
            'version' => $j['version'],
            'version_code' => is_int($j['version_code'] ?? null) ? $j['version_code'] : null,
            'servidor' => $texto('servidor', '/^(qa|produccion)$/'),
            'commit' => $texto('commit', '/^[0-9a-f]{7,40}$/'),
            'fecha' => $fecha('fecha'),
            'instalado_en' => $fecha('instalado_en'),
            'sha256' => $j['sha256'],
            'tamano' => $j['tamano'],
            'huella_firma' => $texto('huella_firma', '/^([0-9A-F]{2}:){31}[0-9A-F]{2}$/'),
            'firma' => $texto('firma', '/^(publicacion|depuracion)$/'),
            'app' => $texto('app', '/^[A-Za-z0-9._]{1,150}$/'),
        ];
    }
}
