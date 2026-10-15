<?php

namespace App\Services\Traslado;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;
use ZipArchive;

/**
 * Restauración de un paquete de traslado en el servidor nuevo (comando
 * plataforma:restaurar-traslado). Solo trabaja con lo que el manifest
 * declara y con nombres seguros; nunca escribe fuera de storage/app.
 */
class RestauradorTraslado
{
    public function __construct(
        private readonly VerificadorPaquete $verificador,
        private readonly ImportadorSql $importador,
    ) {}

    /**
     * Copia los archivos a storage/app/private y storage/app/public.
     *
     * @param  array<string, mixed>  $manifest
     * @return array{nuevos: int, iguales: int, reemplazados: int, conflictos: list<string>}
     */
    public function restaurarArchivos(ZipArchive $zip, array $manifest, bool $sobrescribir): array
    {
        $r = ['nuevos' => 0, 'iguales' => 0, 'reemplazados' => 0, 'conflictos' => []];
        foreach ($manifest['archivos'] as $entrada) {
            $nombre = (string) $entrada['ruta'];
            if (! preg_match('#^archivos/(private|public)/(.+)$#', $nombre, $m) || ! ZipPorPartes::nombreSeguro($m[2])) {
                continue;
            }
            $raiz = Paquetes::raizDisco($m[1]);
            $destino = $raiz.'/'.$m[2];
            if (is_file($destino)) {
                if (hash_equals((string) $entrada['sha256'], (string) hash_file('sha256', $destino))) {
                    $r['iguales']++;

                    continue;
                }
                if (! $sobrescribir) {
                    $r['conflictos'][] = "{$m[1]}/{$m[2]}";

                    continue;
                }
            }
            $existia = is_file($destino);
            File::ensureDirectoryExists(dirname($destino), $m[1] === 'private' ? 0750 : 0755);
            $this->extraer($zip, $nombre, $destino, $m[1] === 'private' ? 0640 : 0644);
            $existia ? $r['reemplazados']++ : $r['nuevos']++;
        }

        return $r;
    }

    /**
     * Saca el respaldo de la base a una carpeta privada y devuelve su ruta.
     */
    public function extraerBase(ZipArchive $zip, Paquetes $paquetes): string
    {
        $destino = $paquetes->carpeta('restaurar').'/base_'.now()->utc()->format('Ymd_His').'.sql.gz';
        $this->extraer($zip, 'base/base.sql.gz', $destino, 0600);

        return $destino;
    }

    /**
     * ¿La base de destino está vacía (sin tablas)?
     */
    public function baseVacia(Connection $conexion): bool
    {
        return $this->tablas($conexion) === [];
    }

    /**
     * @return list<string>
     */
    public function tablas(Connection $conexion): array
    {
        return array_values(array_filter(
            array_map('strval', $conexion->getSchemaBuilder()->getTableListing(schemaQualified: false)),
            fn ($t) => ! str_starts_with($t, 'sqlite_')
        ));
    }

    /**
     * El respaldo solo se importa en el mismo motor que lo generó.
     */
    public function motorCompatible(string $motorPaquete, Connection $conexion): bool
    {
        $familia = fn (string $m) => in_array($m, ['mysql', 'mariadb'], true) ? 'mysql' : $m;

        return $familia($motorPaquete) === $familia($conexion->getDriverName());
    }

    /**
     * @param  callable(int): void|null  $avance
     */
    public function importarBase(string $rutaGz, Connection $conexion, ?callable $avance = null): int
    {
        if (! $this->baseVacia($conexion)) {
            throw new RuntimeException('La base de datos de destino no está vacía: por seguridad no se importa encima.');
        }

        return $this->importador->importar($rutaGz, $conexion, $avance);
    }

    /**
     * Compara los renglones por tabla con los del manifest.
     *
     * @param  array<string, int>  $esperados
     * @return list<array{tabla: string, esperado: int, encontrado: ?int}>
     */
    public function diferencias(Connection $conexion, array $esperados): array
    {
        $existentes = array_flip($this->tablas($conexion));
        $dif = [];
        foreach ($esperados as $tabla => $n) {
            $encontrado = isset($existentes[$tabla]) ? (int) $conexion->table($tabla)->count() : null;
            if ($encontrado !== (int) $n) {
                $dif[] = ['tabla' => (string) $tabla, 'esperado' => (int) $n, 'encontrado' => $encontrado];
            }
        }

        return $dif;
    }

    public function conexion(?string $nombre): Connection
    {
        return DB::connection($nombre ?: null);
    }

    private function extraer(ZipArchive $zip, string $nombre, string $destino, int $permisos): void
    {
        $flujo = $zip->getStream($nombre);
        if ($flujo === false) {
            throw new RuntimeException("No se pudo leer {$nombre} del paquete.");
        }
        $temporal = $destino.'.parcial';
        $salida = fopen($temporal, 'wb');
        if ($salida === false) {
            fclose($flujo);
            throw new RuntimeException("No se pudo escribir {$destino}.");
        }
        stream_copy_to_stream($flujo, $salida);
        fclose($flujo);
        fclose($salida);
        @chmod($temporal, $permisos);
        rename($temporal, $destino);
    }
}
