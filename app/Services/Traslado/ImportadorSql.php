<?php

namespace App\Services\Traslado;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Importa en PHP un .sql.gz hecho por Respaldos (sin el cliente de la base
 * ni exec): lo lee por bloques y separa las sentencias respetando los textos
 * entre comillas (un «;» o un salto de línea dentro de un texto no corta).
 *
 * En MySQL/MariaDB las comillas se escapan con «\»; en SQLite, duplicándolas.
 */
class ImportadorSql
{
    /**
     * @param  int  $bloque  bytes que se leen por vez (las pruebas usan bloques diminutos para forzar cortes)
     */
    public function __construct(private readonly int $bloque = 1048576) {}

    /**
     * Tablas con columnas generadas vistas en el respaldo: tabla => columnas.
     *
     * @var array<string, list<string>>
     */
    private array $generadas = [];

    /**
     * @param  callable(int): void|null  $avance  recibe cuántas sentencias van
     */
    public function importar(string $rutaGz, Connection $conexion, ?callable $avance = null): int
    {
        $gz = @gzopen($rutaGz, 'rb');
        if ($gz === false) {
            throw new RuntimeException('No se pudo abrir el respaldo de la base.');
        }
        $this->generadas = [];
        $this->pendientesDeRevisar = [];
        $diagonal = in_array($conexion->getDriverName(), ['mysql', 'mariadb'], true);
        $sentencias = 0;
        $pendiente = '';
        $comilla = null;   // comilla abierta: ' " `
        $inicio = 0;       // inicio de la sentencia actual dentro de $pendiente
        $i = 0;

        try {
            while (true) {
                $bloque = gzread($gz, max(1, $this->bloque));
                $fin = $bloque === false || $bloque === '';
                if (! $fin) {
                    $pendiente .= $bloque;
                }
                $largo = strlen($pendiente);

                while ($i < $largo) {
                    if ($comilla === null) {
                        // Comentario «--» al inicio de una sentencia: se salta la línea
                        if (trim(substr($pendiente, $inicio, $i - $inicio)) === '') {
                            $salto = strspn($pendiente, " \t\r\n", $i);
                            $i += $salto;
                            if ($i >= $largo) {
                                break;
                            }
                            if (substr($pendiente, $i, 2) === '--' || ($i + 1 >= $largo && $pendiente[$i] === '-' && ! $fin)) {
                                $eol = strpos($pendiente, "\n", $i);
                                if ($eol === false) {
                                    if ($fin) {
                                        $i = $largo;
                                    }
                                    break; // falta el resto de la línea
                                }
                                $i = $eol + 1;
                                $inicio = $i;

                                continue;
                            }
                            $inicio = $i;
                        }
                        $i += strcspn($pendiente, "'\"`;", $i);
                        if ($i >= $largo) {
                            break;
                        }
                        $c = $pendiente[$i];
                        if ($c === ';') {
                            $sentencia = trim(substr($pendiente, $inicio, $i - $inicio));
                            if ($sentencia !== '') {
                                $conexion->unprepared($this->sinColumnasGeneradas($sentencia, $conexion, $diagonal));
                                $sentencias++;
                                if ($avance !== null && $sentencias % 50 === 0) {
                                    $avance($sentencias);
                                }
                            }
                            $i++;
                            $inicio = $i;
                        } else {
                            $comilla = $c;
                            $i++;
                        }
                    } else {
                        $buscar = $diagonal && $comilla !== '`' ? $comilla.'\\' : $comilla;
                        $i += strcspn($pendiente, $buscar, $i);
                        if ($i >= $largo) {
                            break;
                        }
                        if ($pendiente[$i] === '\\') {
                            if ($i + 1 >= $largo && ! $fin) {
                                break; // el carácter escapado llega en el siguiente bloque
                            }
                            $i += 2;

                            continue;
                        }
                        // Comilla duplicada = comilla escrita
                        if ($i + 1 >= $largo && ! $fin) {
                            break;
                        }
                        if (($pendiente[$i + 1] ?? '') === $comilla) {
                            $i += 2;

                            continue;
                        }
                        $comilla = null;
                        $i++;
                    }
                }

                if ($fin) {
                    break;
                }
                // Se descarta lo ya ejecutado para no crecer en memoria
                if ($inicio > 0) {
                    $pendiente = substr($pendiente, $inicio);
                    $i -= $inicio;
                    $inicio = 0;
                }
            }
        } finally {
            gzclose($gz);
        }

        if ($comilla !== null) {
            throw new RuntimeException('El respaldo de la base está incompleto (termina dentro de un texto).');
        }
        $resto = trim(substr($pendiente, $inicio));
        if ($resto !== '' && ! str_starts_with($resto, '--')) {
            $conexion->unprepared($this->sinColumnasGeneradas($resto, $conexion, $diagonal));
            $sentencias++;
        }

        return $sentencias;
    }

    /**
     * Respaldos hechos antes de omitir las columnas generadas traen su valor en
     * el INSERT (p. ej. prestamos_llaves.llave_en_uso) y la base lo rechaza.
     * Al pasar el CREATE TABLE se anotan esas columnas (consultando la tabla
     * recién creada) y a sus INSERT se les quita la columna y su valor: la
     * base la recalcula. Los respaldos nuevos ya no las traen.
     */
    private function sinColumnasGeneradas(string $sentencia, Connection $conexion, bool $diagonal): string
    {
        if (preg_match('/^CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?[`"]?([^`"\s(]+)[`"]?/i', $sentencia, $m)) {
            unset($this->generadas[$m[1]]);
            if (preg_match('/\bGENERATED\s+ALWAYS\b|\)\s*(?:STORED|VIRTUAL|PERSISTENT)\b/i', $sentencia)) {
                $this->pendientesDeRevisar[$m[1]] = true;
            }

            return $sentencia;
        }
        if (! preg_match('/^INSERT\s+INTO\s+([`"])(.+?)\1\s*\(/i', $sentencia, $m)) {
            return $sentencia;
        }
        $tabla = $m[2];
        if (isset($this->pendientesDeRevisar[$tabla])) {
            unset($this->pendientesDeRevisar[$tabla]);
            $generadas = array_values(array_map(fn (array $c) => $c['name'], array_filter(
                $conexion->getSchemaBuilder()->getColumns($tabla), fn (array $c) => ! empty($c['generation']),
            )));
            if ($generadas !== []) {
                $this->generadas[$tabla] = $generadas;
            }
        }
        if (! isset($this->generadas[$tabla])) {
            return $sentencia;
        }

        return $this->quitarColumnas($sentencia, strlen($m[0]), $m[1], $this->generadas[$tabla], $diagonal);
    }

    /** @var array<string, true> */
    private array $pendientesDeRevisar = [];

    /**
     * @param  int  $desde  posición justo después del «(» de la lista de columnas
     * @param  list<string>  $quitar
     */
    private function quitarColumnas(string $sql, int $desde, string $comilla, array $quitar, bool $diagonal): string
    {
        $cierre = strpos($sql, ')', $desde);
        $columnas = array_map(fn ($c) => trim(trim($c), $comilla), explode(',', substr($sql, $desde, $cierre - $desde)));
        $indices = array_keys(array_filter($columnas, fn ($c) => in_array($c, $quitar, true)));
        if ($indices === [] || ! preg_match('/\G\s*VALUES\s*/i', $sql, $v, 0, $cierre + 1)) {
            return $sql;
        }
        $conservar = fn (array $lista) => array_values(array_diff_key($lista, array_flip($indices)));
        $cabeza = substr($sql, 0, $desde - 1).'('.implode(', ', array_map(fn ($c) => $comilla.$c.$comilla, $conservar($columnas))).') VALUES'."\n";

        $tuplas = [];
        $i = $cierre + 1 + strlen($v[0]);
        $largo = strlen($sql);
        while ($i < $largo) {
            $i += strspn($sql, " \t\r\n,", $i);
            if ($i >= $largo || $sql[$i] !== '(') {
                break;
            }
            $i++;
            $valores = [];
            $actual = '';
            while ($i < $largo) {
                $c = $sql[$i];
                if ($c === "'" || $c === '"') {
                    // Texto entre comillas: se copia completo
                    $j = $i + 1;
                    while ($j < $largo) {
                        if ($diagonal && $sql[$j] === '\\') {
                            $j += 2;

                            continue;
                        }
                        if ($sql[$j] === $c) {
                            if (($sql[$j + 1] ?? '') === $c) {
                                $j += 2;

                                continue;
                            }
                            break;
                        }
                        $j++;
                    }
                    $actual .= substr($sql, $i, $j - $i + 1);
                    $i = $j + 1;
                } elseif ($c === ',' || $c === ')') {
                    $valores[] = trim($actual);
                    $actual = '';
                    $i++;
                    if ($c === ')') {
                        break;
                    }
                } else {
                    $actual .= $c;
                    $i++;
                }
            }
            $tuplas[] = '('.implode(', ', $conservar($valores)).')';
        }

        return $cabeza.implode(",\n", $tuplas);
    }
}
