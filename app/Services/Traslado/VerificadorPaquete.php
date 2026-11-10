<?php

namespace App\Services\Traslado;

use RuntimeException;
use ZipArchive;

/**
 * Revisa la integridad de un paquete sin restaurarlo: que sea un paquete de
 * la plataforma, que traiga todo lo que su manifest.json dice y que cada
 * archivo conserve su huella SHA-256. Se puede hacer por partes (pantalla) o
 * de una vez (consola).
 */
class VerificadorPaquete
{
    /** Problemas que se guardan como máximo (para no inflar el estado). */
    private const MAXIMO_PROBLEMAS = 50;

    /**
     * Revisa de una vez (consola).
     *
     * @return array<string, mixed>
     */
    public function verificar(string $ruta): array
    {
        $r = self::inicial();
        do {
            $r = $this->avanzar($ruta, $r, INF);
        } while ($r['valido'] === null);

        return $r;
    }

    /**
     * @return array<string, mixed>
     */
    public static function inicial(): array
    {
        return ['revisados' => 0, 'total' => 0, 'problemas' => [], 'avisos' => [], 'valido' => null];
    }

    /**
     * Avanza la revisión hasta $limite (microtime). valido = null mientras falte.
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    public function avanzar(string $ruta, array $r, float $limite): array
    {
        $zip = new ZipArchive;
        if (! is_file($ruta) || $zip->open($ruta, ZipArchive::RDONLY) !== true) {
            return $this->terminar($r, 'El archivo no es un ZIP válido o está incompleto (¿se cortó la descarga?).');
        }

        try {
            $manifest = $this->manifest($zip);
            if (is_string($manifest)) {
                return $this->terminar($r, $manifest);
            }
            $lista = array_values($manifest['archivos'] ?? []);

            if (! isset($r['manifest'])) {
                $r['total'] = count($lista);
                $r['manifest'] = self::resumenManifest($manifest);
                $this->revisarEstructura($zip, $manifest, $lista, $r);
            }

            $primera = true; // al menos un archivo por parte
            while ($r['revisados'] < $r['total'] && ($primera || microtime(true) < $limite)) {
                $primera = false;
                $entrada = $lista[$r['revisados']];
                $this->revisarEntrada($zip, $entrada, $r);
                $r['revisados']++;
            }

            if ($r['revisados'] >= $r['total']) {
                $r['valido'] = $r['problemas'] === [];
            }

            return $r;
        } finally {
            $zip->close();
        }
    }

    /**
     * Lo que se muestra del manifest (sin la lista de archivos).
     *
     * @param  array<string, mixed>  $m
     * @return array<string, mixed>
     */
    public static function resumenManifest(array $m): array
    {
        return [
            'tipo' => $m['tipo'] ?? null,
            'generado_en' => $m['generado_en'] ?? null,
            'generado_por' => $m['generado_por'] ?? null,
            'version' => $m['plataforma']['version'] ?? null,
            'ultima_migracion' => $m['plataforma']['ultima_migracion'] ?? null,
            'motor' => $m['plataforma']['motor'] ?? null,
            'archivos' => (int) ($m['totales']['archivos'] ?? 0),
            'bytes' => (int) ($m['totales']['bytes'] ?? 0),
            'filas' => (int) ($m['base']['filas'] ?? array_sum($m['tablas'] ?? [])),
            'empresa' => $m['empresa']['nombre_comercial'] ?? null,
        ];
    }

    /**
     * @return array<string, mixed>|string El manifest o el problema
     */
    public function manifest(ZipArchive $zip): array|string
    {
        $texto = $zip->getFromName('manifest.json');
        if ($texto === false) {
            return 'El paquete no trae manifest.json: no es un paquete de la plataforma o está incompleto.';
        }
        $m = json_decode($texto, true);
        if (! is_array($m) || ($m['formato'] ?? null) !== Paquetes::FORMATO || ! is_array($m['archivos'] ?? null)) {
            return 'El manifest.json no tiene el formato de un paquete de la plataforma.';
        }
        if ((int) ($m['version_formato'] ?? 0) > Paquetes::VERSION_FORMATO) {
            return 'El paquete se hizo con una versión más nueva de la plataforma; actualiza esta instalación antes de usarlo.';
        }

        return $m;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @param  list<array<string, mixed>>  $lista
     * @param  array<string, mixed>  $r
     */
    private function revisarEstructura(ZipArchive $zip, array $manifest, array $lista, array &$r): void
    {
        $declarados = [];
        foreach ($lista as $e) {
            $declarados[(string) ($e['ruta'] ?? '')] = true;
        }
        if (($manifest['tipo'] ?? '') === 'traslado' && ! isset($declarados['base/base.sql.gz'])) {
            $this->problema($r, 'Falta el respaldo de la base (base/base.sql.gz).');
        }
        if (($manifest['tipo'] ?? '') === 'empresa' && ! isset($declarados['datos/empresas.json'])) {
            $this->problema($r, 'Falta el archivo con los datos de la empresa (datos/empresas.json).');
        }
        $extras = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $nombre = (string) $zip->getNameIndex($i);
            if (! ZipPorPartes::nombreSeguro($nombre)) {
                $this->problema($r, "Nombre de archivo peligroso dentro del paquete: {$nombre}");

                continue;
            }
            if (! isset($declarados[$nombre]) && ! in_array($nombre, ['manifest.json', 'LEEME.txt'], true) && ! str_ends_with($nombre, '/')) {
                $extras++;
            }
        }
        if ($extras > 0) {
            $r['avisos'][] = "El paquete trae {$extras} archivo(s) que no están en su lista; no se restaurarán.";
        }
    }

    /**
     * @param  array<string, mixed>  $entrada
     * @param  array<string, mixed>  $r
     */
    private function revisarEntrada(ZipArchive $zip, array $entrada, array &$r): void
    {
        $nombre = (string) ($entrada['ruta'] ?? '');
        if (! ZipPorPartes::nombreSeguro($nombre)) {
            $this->problema($r, "Nombre de archivo peligroso en la lista: {$nombre}");

            return;
        }
        $stat = $zip->statName($nombre);
        if ($stat === false) {
            $this->problema($r, "Falta {$nombre}.");

            return;
        }
        $flujo = $zip->getStream($nombre);
        if ($flujo === false) {
            $this->problema($r, "No se pudo leer {$nombre}.");

            return;
        }
        $sha = hash_init('sha256');
        $bytes = 0;
        while (! feof($flujo)) {
            $bloque = fread($flujo, 1048576);
            if ($bloque === false || $bloque === '') {
                break;
            }
            hash_update($sha, $bloque);
            $bytes += strlen($bloque);
        }
        fclose($flujo);
        if ($bytes !== (int) ($entrada['bytes'] ?? -1) || ! hash_equals((string) ($entrada['sha256'] ?? ''), hash_final($sha))) {
            $this->problema($r, "{$nombre} no coincide con su huella: el archivo cambió o se dañó.");
        }
    }

    /**
     * @param  array<string, mixed>  $r
     */
    private function problema(array &$r, string $texto): void
    {
        if (count($r['problemas']) < self::MAXIMO_PROBLEMAS) {
            $r['problemas'][] = $texto;
        } elseif (count($r['problemas']) === self::MAXIMO_PROBLEMAS) {
            $r['problemas'][] = 'Hay más problemas; se muestran los primeros '.self::MAXIMO_PROBLEMAS.'.';
        }
    }

    /**
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    private function terminar(array $r, string $problema): array
    {
        $this->problema($r, $problema);
        $r['valido'] = false;

        return $r;
    }

    /**
     * Abre y valida; para el restaurador.
     *
     * @return array{0: ZipArchive, 1: array<string, mixed>}
     */
    public function abrir(string $ruta): array
    {
        $zip = new ZipArchive;
        if (! is_file($ruta) || $zip->open($ruta, ZipArchive::RDONLY) !== true) {
            throw new RuntimeException('El archivo no es un ZIP válido o está incompleto.');
        }
        $m = $this->manifest($zip);
        if (is_string($m)) {
            $zip->close();
            throw new RuntimeException($m);
        }

        return [$zip, $m];
    }
}
