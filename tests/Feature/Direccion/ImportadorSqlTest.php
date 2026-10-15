<?php

namespace Tests\Feature\Direccion;

use App\Services\Traslado\ImportadorSql;
use Illuminate\Database\Connection;
use Mockery;
use Tests\TestCase;

/**
 * El importador separa las sentencias del respaldo sin cortar textos que
 * traen «;», saltos de línea, comillas escapadas o «--» (MySQL/MariaDB con
 * «\», SQLite con comillas duplicadas), aunque el corte de lectura caiga en
 * medio de un escape.
 */
class ImportadorSqlTest extends TestCase
{
    public function test_mysql_respeta_escapes_con_diagonal_en_cualquier_corte(): void
    {
        $sql = "-- Respaldo de prueba\n-- Para restaurar: lo que sea\nSET NAMES utf8mb4;\n\n"
            ."DROP TABLE IF EXISTS `t;1`;\nCREATE TABLE `t;1` (`a` text COMMENT 'nota ''uno''; dos');\n\n"
            ."-- t;1: comentario con 'comilla\n"
            ."INSERT INTO `t;1` (`a`) VALUES\n('O\\'Higgins; piso 2\\n-- no es comentario'),\n('termina en diagonal \\\\'),\n('\"doble\";');\n"
            ."SET FOREIGN_KEY_CHECKS=1;\n";
        $esperadas = [
            'SET NAMES utf8mb4',
            'DROP TABLE IF EXISTS `t;1`',
            "CREATE TABLE `t;1` (`a` text COMMENT 'nota ''uno''; dos')",
            "INSERT INTO `t;1` (`a`) VALUES\n('O\\'Higgins; piso 2\\n-- no es comentario'),\n('termina en diagonal \\\\'),\n('\"doble\";')",
            'SET FOREIGN_KEY_CHECKS=1',
        ];

        foreach ([1, 2, 3, 5, 7, 13, 1048576] as $bloque) {
            $this->assertSame($esperadas, $this->separar($sql, 'mysql', $bloque), "Bloque de {$bloque} bytes");
        }
    }

    public function test_sqlite_usa_comillas_duplicadas_y_la_diagonal_es_literal(): void
    {
        $sql = "PRAGMA foreign_keys=OFF;\nBEGIN TRANSACTION;\nINSERT INTO \"t\" (\"a\") VALUES\n('ruta\\carpeta'),\n('O''Higgins;\n-- sigue');\nCOMMIT;\n";
        $esperadas = ['PRAGMA foreign_keys=OFF', 'BEGIN TRANSACTION', "INSERT INTO \"t\" (\"a\") VALUES\n('ruta\\carpeta'),\n('O''Higgins;\n-- sigue')", 'COMMIT'];

        foreach ([1, 4, 9, 1048576] as $bloque) {
            $this->assertSame($esperadas, $this->separar($sql, 'sqlite', $bloque), "Bloque de {$bloque} bytes");
        }
    }

    public function test_un_respaldo_cortado_dentro_de_un_texto_se_rechaza(): void
    {
        $this->expectExceptionMessage('incompleto');
        $this->separar("INSERT INTO t VALUES ('sin cerrar;\n", 'mysql', 4);
    }

    /**
     * @return list<string>
     */
    private function separar(string $sql, string $motor, int $bloque): array
    {
        $ruta = tempnam(sys_get_temp_dir(), 'imp');
        file_put_contents($ruta, gzencode($sql));
        $sentencias = [];
        $conexion = Mockery::mock(Connection::class);
        $conexion->shouldReceive('getDriverName')->andReturn($motor);
        $conexion->shouldReceive('unprepared')->andReturnUsing(function ($s) use (&$sentencias) {
            $sentencias[] = $s;

            return true;
        });
        try {
            $n = (new ImportadorSql($bloque))->importar($ruta, $conexion);
            $this->assertSame(count($sentencias), $n);
        } finally {
            @unlink($ruta);
        }

        return $sentencias;
    }
}
