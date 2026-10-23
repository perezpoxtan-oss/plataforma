<?php

namespace App\Support\BaseDatos;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

/**
 * Restricciones de la base de datos que Laravel no cambia igual en todos los
 * motores: la acción al borrar de una llave foránea (cascade → restrict) y
 * los CHECK de las claves de texto (estados, etapas, categorías). Ver
 * docs/tecnico/integridad-bd.md.
 *
 * - MariaDB/MySQL (QA y Producción): ALTER TABLE. Las llaves foráneas se
 *   cambian con la revisión de llaves apagada (los datos ya cumplían la llave
 *   anterior), así InnoDB no copia la tabla. Los CHECK revisan las filas.
 * - SQLite (pruebas y desarrollo): editar el esquema guardado (sqlite_master)
 *   con writable_schema, el procedimiento que SQLite documenta para cambios
 *   que no tocan los datos en disco («Making Other Kinds Of Table Schema
 *   Changes»). No reconstruye la tabla, así que funciona dentro de la
 *   transacción de las pruebas aunque haya filas hijas. Antes de agregar un
 *   CHECK siempre se revisa que ninguna fila lo viole.
 *
 * Todo es idempotente: si la migración se corta y se vuelve a correr en el
 * siguiente despliegue, termina lo que faltó.
 */
final class Restricciones
{
    /** Prefijo de los CHECK de la plataforma (no toca los de MariaDB para JSON). */
    public const PREFIJO_CHECK = 'chk_';

    // ------------------------------------------------------------ Llaves foráneas

    /** Acción al borrar de la llave foránea de esa columna («cascade», «restrict», «set null»…), o null si no hay. */
    public static function accionAlBorrar(string $tabla, string $columna): ?string
    {
        $fk = self::foranea($tabla, $columna);

        return $fk === null ? null : self::normalizarAccion((string) $fk['on_delete']);
    }

    /**
     * Cambia la acción al borrar de las llaves foráneas de una tabla. Si la
     * llave no existe (una corrida anterior se cortó entre quitarla y
     * volverla a crear), la crea.
     *
     * @param  array<string, string>  $columnas  columna => tabla a la que apunta (siempre su «id»)
     */
    public static function cambiarAccionAlBorrar(string $tabla, array $columnas, string $accion): void
    {
        $accion = self::normalizarAccion($accion);
        $pendientes = array_filter($columnas, fn (string $ref, string $col) => self::accionAlBorrar($tabla, $col) !== $accion, ARRAY_FILTER_USE_BOTH);
        if ($pendientes === []) {
            return;
        }

        $con = DB::connection();
        if ($con->getDriverName() === 'sqlite') {
            self::editarEsquemaSqlite($tabla, function (string $sql) use ($pendientes, $accion, $tabla): string {
                foreach ($pendientes as $columna => $referencia) {
                    $patron = '/(foreign key\s*\(\s*"?'.preg_quote($columna, '/').'"?\s*\)\s*references\s*"?'.preg_quote($referencia, '/').'"?\s*\(\s*"?id"?\s*\))(\s+on delete\s+(?:cascade|restrict|set null|set default|no action))?/i';
                    $nuevo = preg_replace($patron, '$1 on delete '.$accion, $sql, 1, $n);
                    if ($n !== 1) {
                        throw new RuntimeException("No se encontró la llave foránea {$tabla}.{$columna} → {$referencia} en SQLite.");
                    }
                    $sql = $nuevo;
                }

                return $sql;
            });

            return;
        }

        $mysql = in_array($con->getDriverName(), ['mysql', 'mariadb'], true);
        if ($mysql) {
            // Los datos ya cumplen la llave (solo cambia la acción): sin revisión, InnoDB no copia la tabla
            Schema::disableForeignKeyConstraints();
        }
        try {
            Schema::table($tabla, function (Blueprint $t) use ($tabla, $pendientes, $accion) {
                foreach ($pendientes as $columna => $referencia) {
                    $fk = self::foranea($tabla, $columna);
                    if ($fk !== null) {
                        $t->dropForeign($fk['name'] ?: [$columna]);
                    }
                    $t->foreign($columna)->references('id')->on($referencia)->onDelete($accion);
                }
            });
        } finally {
            if ($mysql) {
                Schema::enableForeignKeyConstraints();
            }
        }
    }

    /** @return array{name: ?string, columns: list<string>, foreign_table: string, on_delete: ?string}|null */
    private static function foranea(string $tabla, string $columna): ?array
    {
        foreach (Schema::getForeignKeys($tabla) as $fk) {
            if ($fk['columns'] === [$columna]) {
                return $fk;
            }
        }

        return null;
    }

    private static function normalizarAccion(string $accion): string
    {
        return strtolower(trim(str_replace('_', ' ', $accion)));
    }

    // ------------------------------------------------------------ CHECK

    public static function nombreCheck(string $tabla, string $columna): string
    {
        return self::PREFIJO_CHECK.$tabla.'_'.$columna;
    }

    /**
     * CHECK de la plataforma que tiene la tabla: nombre => columna (SQLite y
     * MariaDB/MySQL; en otros motores, ninguno).
     *
     * @return array<string, string>
     */
    public static function checks(string $tabla): array
    {
        $con = DB::connection();
        $nombres = match ($con->getDriverName()) {
            'sqlite' => self::checksSqlite($tabla),
            'mysql', 'mariadb' => array_map(fn ($f) => (string) ($f->nombre ?? $f->NOMBRE ?? ''), $con->select(
                "select constraint_name as nombre from information_schema.table_constraints where constraint_schema = database() and table_name = ? and constraint_type = 'CHECK' and constraint_name like ?",
                [$tabla, self::PREFIJO_CHECK.'%'])),
            default => [],
        };
        $prefijo = self::PREFIJO_CHECK.$tabla.'_';
        $r = [];
        foreach ($nombres as $n) {
            if (str_starts_with($n, $prefijo)) {
                $r[$n] = substr($n, strlen($prefijo));
            }
        }
        ksort($r);

        return $r;
    }

    /**
     * Valores permitidos de un CHECK tal como está en la base (para comparar
     * con las constantes de los modelos). null si no existe.
     *
     * @return list<string>|null
     */
    public static function valoresDelCheck(string $tabla, string $columna): ?array
    {
        $nombre = self::nombreCheck($tabla, $columna);
        $con = DB::connection();
        $expresion = match ($con->getDriverName()) {
            'sqlite' => preg_match('/constraint\s+"'.preg_quote($nombre, '/').'"\s+check\s*\((.*?)\)\s*(?:,|\)\s*$)/is', self::sqlTablaSqlite($tabla), $m) ? $m[1] : null,
            // MariaDB guarda la tabla en check_constraints; MySQL no (ahí el nombre ya es único por base)
            'mysql', 'mariadb' => self::esMariaDb()
                ? $con->selectOne('select check_clause as expresion from information_schema.check_constraints where constraint_schema = database() and table_name = ? and constraint_name = ?', [$tabla, $nombre])?->expresion
                : $con->selectOne('select check_clause as expresion from information_schema.check_constraints where constraint_schema = database() and constraint_name = ?', [$nombre])?->expresion,
            default => null,
        };
        if ($expresion === null) {
            return null;
        }
        preg_match_all("/'((?:[^']|'')*)'/", $expresion, $valores);
        $lista = array_map(fn ($v) => str_replace("''", "'", $v), $valores[1]);
        sort($lista);

        return $lista;
    }

    /**
     * Agrega (o actualiza, si ya existe con otros valores) los CHECK
     * «columna IN (valores)» de una tabla. Quien llama revisa antes que los
     * datos cumplan (fueraDeCatalogo()); si no, la base lo rechaza.
     *
     * @param  array<string, list<string>>  $columnas  columna => valores permitidos
     */
    public static function agregarChecks(string $tabla, array $columnas): void
    {
        $con = DB::connection();
        $driver = $con->getDriverName();
        if (! in_array($driver, ['sqlite', 'mysql', 'mariadb'], true)) {
            return; // otros motores: la aplicación ya valida contra las constantes
        }
        $pendientes = [];
        foreach ($columnas as $columna => $valores) {
            $valores = array_values(array_unique(array_map('strval', $valores)));
            $actuales = self::valoresDelCheck($tabla, $columna);
            $ordenados = $valores;
            sort($ordenados);
            if ($actuales !== $ordenados) {
                $pendientes[$columna] = $valores;
            }
        }
        if ($pendientes === []) {
            return;
        }
        self::quitarChecks($tabla, array_keys($pendientes));

        $pdo = $con->getPdo();
        $lista = fn (array $valores) => implode(', ', array_map(fn ($v) => $pdo->quote($v), $valores));
        if ($driver === 'sqlite') {
            self::editarEsquemaSqlite($tabla, function (string $sql) use ($tabla, $pendientes, $lista): string {
                $sql = rtrim($sql);
                if (! str_ends_with($sql, ')')) {
                    throw new RuntimeException("Esquema inesperado de {$tabla} en SQLite.");
                }
                $extra = '';
                foreach ($pendientes as $columna => $valores) {
                    $extra .= ', constraint "'.self::nombreCheck($tabla, $columna).'" check ("'.$columna.'" in ('.$lista($valores).'))';
                }

                return substr($sql, 0, -1).$extra.')';
            });

            return;
        }

        $partes = [];
        foreach ($pendientes as $columna => $valores) {
            // Comparación exacta: con utf8mb4_unicode_ci «RESUELTO» pasaría por «resuelto»
            $partes[] = 'add constraint `'.self::nombreCheck($tabla, $columna).'` check ('.self::exacta($columna).' in ('.$lista($valores).'))';
        }
        $con->statement('alter table `'.$tabla.'` '.implode(', ', $partes));
    }

    /** @param  list<string>  $columnas */
    public static function quitarChecks(string $tabla, array $columnas): void
    {
        $con = DB::connection();
        $existentes = self::checks($tabla);
        $quitar = array_values(array_filter(array_map(fn ($c) => self::nombreCheck($tabla, $c), $columnas), fn ($n) => isset($existentes[$n])));
        if ($quitar === []) {
            return;
        }
        if ($con->getDriverName() === 'sqlite') {
            self::editarEsquemaSqlite($tabla, function (string $sql) use ($quitar): string {
                foreach ($quitar as $nombre) {
                    // Lo que agregarChecks() escribió: «, constraint "chk_…" check ("col" in ('a', 'b'))»
                    $sql = preg_replace('/,\s*constraint\s+"'.preg_quote($nombre, '/').'"\s+check\s*\("[^"]+"\s+in\s*\((?:\'(?:[^\']|\'\')*\'(?:\s*,\s*)?)*\)\)/i', '', $sql, 1);
                }

                return $sql;
            });

            return;
        }
        $con->statement('alter table `'.$tabla.'` '.implode(', ', array_map(fn ($n) => 'drop constraint `'.$n.'`', $quitar)));
    }

    /**
     * Filas con un valor que no está en la lista (NULL no cuenta: el CHECK lo permite).
     *
     * @param  list<string>  $valores
     */
    public static function fueraDeCatalogo(string $tabla, string $columna, array $valores): int
    {
        return self::consultaFuera($tabla, $columna, $valores)->count();
    }

    /**
     * Filas con un valor fuera de la lista, comparando exacto (mayúsculas y
     * acentos cuentan, como en el CHECK).
     *
     * @param  list<string>  $valores
     */
    public static function consultaFuera(string $tabla, string $columna, array $valores): Builder
    {
        $consulta = DB::table($tabla)->whereNotNull($columna);
        if ($valores === []) {
            return $consulta;
        }

        return $consulta->whereRaw(self::exacta($columna).' not in ('.implode(', ', array_fill(0, count($valores), '?')).')', array_values($valores));
    }

    /** La columna para comparar exacto: binaria en MariaDB/MySQL; en SQLite «=» ya distingue mayúsculas. */
    public static function exacta(string $columna): string
    {
        return in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true)
            ? 'cast(`'.$columna.'` as binary)'
            : '"'.$columna.'"';
    }

    private static function esMariaDb(): bool
    {
        $con = DB::connection();

        return $con->getDriverName() === 'mariadb' || str_contains(strtolower((string) $con->scalar('select version()')), 'mariadb');
    }

    // ------------------------------------------------------------ SQLite

    /** @return list<string> */
    private static function checksSqlite(string $tabla): array
    {
        preg_match_all('/constraint\s+"('.preg_quote(self::PREFIJO_CHECK, '/').'[^"]+)"\s+check/i', self::sqlTablaSqlite($tabla), $m);

        return $m[1];
    }

    private static function sqlTablaSqlite(string $tabla): string
    {
        return (string) DB::connection()->scalar("select sql from sqlite_master where type = 'table' and name = ?", [$tabla]);
    }

    /**
     * Procedimiento de SQLite para cambiar restricciones sin tocar los datos:
     * writable_schema, nuevo texto del CREATE TABLE y schema_version + 1 (para
     * que la conexión vuelva a leer el esquema). Revisa la integridad al final.
     */
    private static function editarEsquemaSqlite(string $tabla, callable $transformar): void
    {
        /** @var Connection $con */
        $con = DB::connection();
        $sql = self::sqlTablaSqlite($tabla);
        if ($sql === '') {
            throw new RuntimeException("No existe la tabla {$tabla}.");
        }
        $nuevo = $transformar($sql);
        if ($nuevo === $sql) {
            return;
        }
        $con->transaction(function () use ($con, $tabla, $nuevo) {
            $version = (int) $con->scalar('pragma schema_version');
            $con->statement('pragma writable_schema = on');
            try {
                $con->update("update sqlite_master set sql = ? where type = 'table' and name = ?", [$nuevo, $tabla]);
                $con->statement('pragma schema_version = '.($version + 1));
            } finally {
                $con->statement('pragma writable_schema = off');
            }
        });
        $revision = (string) $con->scalar('pragma quick_check');
        if ($revision !== 'ok') {
            throw new RuntimeException("SQLite: el esquema de {$tabla} quedó dañado ({$revision}).");
        }
    }
}
