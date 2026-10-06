<?php

namespace App\Services\Respaldos;

use App\Support\HoraLocal;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Respaldos de la base de datos hechos en PHP (sin mysqldump ni exec: Neubox
 * no los permite desde la web). Cada respaldo es un .sql.gz que se puede
 * importar tal cual en phpMyAdmin (cPanel) para restaurar.
 *
 * Se guardan en storage/app/private/respaldos (fuera de la carpeta pública y
 * compartida entre versiones). Política:
 *   - diario: uno por día, después de las 3:00 (hora de la plataforma);
 *   - antes-de-actualizar: uno antes de cada migración del despliegue;
 *   - manual: desde Configuración o la consola.
 * Se conservan 14 días y siempre al menos los 3 más recientes.
 */
class Respaldos
{
    public const DIAS = 14;

    public const MINIMO = 3;

    private const FILAS_POR_INSERT = 200;

    /**
     * Seguridad: tablas que se respaldan sin filas. Son datos temporales que no
     * hacen falta para restaurar y no deben viajar en un archivo descargable
     * (sesiones abiertas, caché y fichas para restablecer contraseña).
     */
    public const TABLAS_SIN_FILAS = ['sessions', 'cache', 'cache_locks', 'password_reset_tokens'];

    public function carpeta(): string
    {
        $carpeta = storage_path('app/private/respaldos');
        if (! is_dir($carpeta)) {
            mkdir($carpeta, 0700, true);
        }
        // Seguridad: la carpeta queda privada aunque alguien la haya creado con otros permisos
        @chmod($carpeta, 0700);

        return $carpeta;
    }

    /**
     * @return Collection<int, array{archivo: string, ruta: string, bytes: int, fecha: Carbon, motivo: string}>
     */
    public function listar(): Collection
    {
        return collect(glob($this->carpeta().'/respaldo_*.sql.gz') ?: [])
            ->map(function (string $ruta) {
                $archivo = basename($ruta);
                preg_match('/^respaldo_\w+?_(\d{8}_\d{6})_([a-z-]+)\.sql\.gz$/', $archivo, $m);

                return [
                    'archivo' => $archivo,
                    'ruta' => $ruta,
                    'bytes' => (int) filesize($ruta),
                    'fecha' => isset($m[1]) ? Carbon::createFromFormat('Ymd_His', $m[1], 'UTC') : Carbon::createFromTimestamp(filemtime($ruta)),
                    'motivo' => $m[2] ?? 'manual',
                ];
            })
            ->sortByDesc('fecha')->values();
    }

    /**
     * Solo nombres que este servicio genera (nada de rutas ni "..").
     */
    public function ruta(string $archivo): ?string
    {
        if (! preg_match('/^respaldo_[a-z0-9]+_\d{8}_\d{6}_[a-z-]+\.sql\.gz$/', $archivo)) {
            return null;
        }
        $ruta = $this->carpeta().'/'.$archivo;

        return is_file($ruta) ? $ruta : null;
    }

    /**
     * ¿Toca el respaldo diario? Si ya pasaron las 3:00 y no hay uno de hoy.
     */
    public function tocaDiario(?Carbon $ahora = null): bool
    {
        $local = ($ahora ?? now())->copy()->setTimezone(HoraLocal::ZONA_PLATAFORMA);
        if ($local->hour < 3) {
            return false;
        }

        return ! $this->listar()->contains(fn ($r) => $r['motivo'] === 'diario'
            && $r['fecha']->copy()->setTimezone(HoraLocal::ZONA_PLATAFORMA)->isSameDay($local));
    }

    public function crear(string $motivo = 'manual'): array
    {
        if (! preg_match('/^[a-z-]{3,30}$/', $motivo)) {
            throw new RuntimeException('Motivo de respaldo no válido.');
        }

        $ambiente = preg_replace('/[^a-z0-9]/', '', strtolower((string) config('app.env'))) ?: 'local';
        // Dos respaldos en el mismo segundo no se pisan: se toma el siguiente segundo libre
        $momento = now()->utc();
        do {
            $archivo = sprintf('respaldo_%s_%s_%s.sql.gz', $ambiente, $momento->format('Ymd_His'), $motivo);
            $ruta = $this->carpeta().'/'.$archivo;
            $momento->addSecond();
        } while (file_exists($ruta));
        $temporal = $ruta.'.parcial';

        $gz = gzopen($temporal, 'wb6');
        if ($gz === false) {
            throw new RuntimeException('No se pudo crear el archivo de respaldo.');
        }
        @chmod($temporal, 0600); // Seguridad: privado desde el primer byte

        try {
            $driver = DB::connection()->getDriverName();
            match ($driver) {
                'mysql', 'mariadb' => $this->volcarMysql($gz),
                'sqlite' => $this->volcarSqlite($gz),
                default => throw new RuntimeException("Respaldo no disponible para {$driver}."),
            };
        } catch (\Throwable $e) {
            gzclose($gz);
            @unlink($temporal);
            throw $e;
        }

        gzclose($gz);
        rename($temporal, $ruta);
        @chmod($ruta, 0600);
        $this->podar();

        return $this->listar()->firstWhere('archivo', $archivo);
    }

    /**
     * Borra los de más de 14 días, conservando siempre los 3 más recientes.
     */
    public function podar(): int
    {
        $limite = now()->subDays(self::DIAS);
        $borrados = 0;
        foreach ($this->listar()->slice(self::MINIMO) as $r) {
            if ($r['fecha']->lt($limite)) {
                @unlink($r['ruta']);
                $borrados++;
            }
        }

        return $borrados;
    }

    /**
     * @param  resource  $gz
     */
    private function volcarMysql($gz): void
    {
        $pdo = DB::connection()->getPdo();
        $base = DB::connection()->getDatabaseName();

        gzwrite($gz, "-- Respaldo de {$base} · ".now()->utc()->toDateTimeString()." UTC\n");
        gzwrite($gz, "-- Restaurar: phpMyAdmin → base de datos → Importar este archivo (.sql.gz)\n");
        gzwrite($gz, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET time_zone='+00:00';\n\n");

        $tablas = collect(DB::select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'"))
            ->map(fn ($fila) => array_values((array) $fila)[0]);

        foreach ($tablas as $tabla) {
            $crear = (array) DB::selectOne('SHOW CREATE TABLE `'.str_replace('`', '``', $tabla).'`');
            gzwrite($gz, "DROP TABLE IF EXISTS `{$tabla}`;\n".array_values($crear)[1].";\n\n");
            $this->volcarFilas($gz, $tabla, fn ($v) => $pdo->quote((string) $v), '`');
        }

        gzwrite($gz, "SET FOREIGN_KEY_CHECKS=1;\n");
    }

    /**
     * En desarrollo (SQLite) se vuelca el esquema y los datos en SQL estándar.
     *
     * @param  resource  $gz
     */
    private function volcarSqlite($gz): void
    {
        $pdo = DB::connection()->getPdo();
        gzwrite($gz, '-- Respaldo SQLite · '.now()->utc()->toDateTimeString()." UTC\nPRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\n");
        foreach (DB::select("SELECT name, sql FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'") as $t) {
            gzwrite($gz, "DROP TABLE IF EXISTS \"{$t->name}\";\n{$t->sql};\n");
            $this->volcarFilas($gz, $t->name, fn ($v) => $pdo->quote((string) $v), '"');
        }
        gzwrite($gz, "COMMIT;\n");
    }

    /**
     * @param  resource  $gz
     */
    private function volcarFilas($gz, string $tabla, callable $citar, string $comilla): void
    {
        if (in_array($tabla, self::TABLAS_SIN_FILAS, true)) {
            gzwrite($gz, "-- {$tabla}: solo estructura (datos temporales)\n\n");

            return;
        }

        $lote = [];
        $columnas = null;
        foreach (DB::table($tabla)->cursor() as $fila) {
            $fila = (array) $fila;
            $columnas ??= implode(', ', array_map(fn ($c) => $comilla.$c.$comilla, array_keys($fila)));
            $lote[] = '('.implode(', ', array_map(fn ($v) => $v === null ? 'NULL' : (is_int($v) || is_float($v) ? (string) $v : $citar($v)), $fila)).')';
            if (count($lote) >= self::FILAS_POR_INSERT) {
                gzwrite($gz, "INSERT INTO {$comilla}{$tabla}{$comilla} ({$columnas}) VALUES\n".implode(",\n", $lote).";\n");
                $lote = [];
            }
        }
        if ($lote !== []) {
            gzwrite($gz, "INSERT INTO {$comilla}{$tabla}{$comilla} ({$columnas}) VALUES\n".implode(",\n", $lote).";\n");
        }
        gzwrite($gz, "\n");
    }
}
