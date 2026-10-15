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
     * @param  callable(int): void|null  $avance  recibe cuántas sentencias van
     */
    public function importar(string $rutaGz, Connection $conexion, ?callable $avance = null): int
    {
        $gz = @gzopen($rutaGz, 'rb');
        if ($gz === false) {
            throw new RuntimeException('No se pudo abrir el respaldo de la base.');
        }
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
                                $conexion->unprepared($sentencia);
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
            $conexion->unprepared($resto);
            $sentencias++;
        }

        return $sentencias;
    }
}
