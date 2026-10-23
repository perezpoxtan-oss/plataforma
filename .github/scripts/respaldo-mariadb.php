<?php

/**
 * CI: respaldo → restauración en MariaDB con columnas generadas.
 *
 * Sobre la base ya migrada escribe un préstamo de llave vigente y un equipo
 * en resguardo (columnas generadas llave_en_uso y equipo_en_campo), hace un
 * respaldo con el servicio de Respaldos y lo importa con ImportadorSql en una
 * base vacía. También importa un respaldo con el formato anterior (que traía
 * el valor de la columna generada en el INSERT). Sale con 1 si algo falla.
 */

use App\Services\Respaldos\Respaldos;
use App\Services\Traslado\ImportadorSql;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

function falla(string $mensaje): never
{
    fwrite(STDERR, "FALLA: {$mensaje}\n");
    exit(1);
}

$root = new PDO('mysql:host='.env('DB_HOST').';port='.env('DB_PORT'), 'root', 'root');
$root->exec('DROP DATABASE IF EXISTS plataforma_ci_destino');
$root->exec('CREATE DATABASE plataforma_ci_destino CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
$root->exec("GRANT ALL ON plataforma_ci_destino.* TO 'ci'@'%'");
config(['database.connections.destino' => array_merge(config('database.connections.mysql'), ['database' => 'plataforma_ci_destino'])]);

$generadas = [];
foreach (DB::select("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'") as $fila) {
    $tabla = array_values((array) $fila)[0];
    foreach (Respaldos::columnasGeneradas($tabla) as $c) {
        $generadas[] = "{$tabla}.{$c}";
    }
}
sort($generadas);
echo 'Columnas generadas: '.implode(', ', $generadas)."\n";
if ($generadas !== ['equipos_responsiva.equipo_en_campo', 'prestamos_llaves.llave_en_uso']) {
    falla('las columnas generadas del esquema no son las esperadas');
}

// Filas mínimas (sin revisar llaves foráneas: solo importa la columna generada)
DB::statement('SET FOREIGN_KEY_CHECKS=0');
$ahora = now()->utc()->toDateTimeString();
DB::table('prestamos_llaves')->insert([
    ['id' => 1, 'empresa_id' => 1, 'sede_id' => 1, 'llave_id' => 7, 'colaborador_id' => 1, 'tipo_garantia' => 'ninguna', 'estado' => 'en_uso', 'prestado_en' => $ahora],
    ['id' => 2, 'empresa_id' => 1, 'sede_id' => 1, 'llave_id' => 8, 'colaborador_id' => 1, 'tipo_garantia' => 'ninguna', 'estado' => 'devuelta', 'prestado_en' => $ahora],
]);
DB::table('equipos_responsiva')->insert(['id' => 1, 'empresa_id' => 1, 'responsiva_id' => 1, 'equipo_id' => 5, 'modalidad' => 'prestado']);
DB::statement('SET FOREIGN_KEY_CHECKS=1');

$respaldo = app(Respaldos::class)->crear('prueba-ci');
$sql = gzdecode(file_get_contents($respaldo['ruta']));
if (preg_match('/INSERT INTO `prestamos_llaves` \([^)]*llave_en_uso/', $sql)) {
    falla('el respaldo escribe la columna generada llave_en_uso en el INSERT');
}

$n = (new ImportadorSql)->importar($respaldo['ruta'], DB::connection('destino'));
$destino = DB::connection('destino');
if ((int) $destino->table('prestamos_llaves')->where('id', 1)->value('llave_en_uso') !== 7
    || $destino->table('prestamos_llaves')->where('id', 2)->value('llave_en_uso') !== null
    || (int) $destino->table('equipos_responsiva')->value('equipo_en_campo') !== 5) {
    falla('la base restaurada no recalculó las columnas generadas');
}
echo "Respaldo nuevo importado en MariaDB: {$n} sentencias, columnas generadas recalculadas.\n";

// Formato anterior: el INSERT trae la columna generada
$anterior = tempnam(sys_get_temp_dir(), 'ant');
file_put_contents($anterior, gzencode("SET FOREIGN_KEY_CHECKS=0;\nDROP TABLE IF EXISTS `prueba_gen`;\n"
    ."CREATE TABLE `prueba_gen` (`id` bigint(20) unsigned NOT NULL, `estado` varchar(12) NOT NULL, `nota` varchar(80) DEFAULT NULL, `llave_id` bigint(20) unsigned NOT NULL,\n"
    ."  `llave_en_uso` bigint(20) unsigned GENERATED ALWAYS AS (case when `estado` = 'en_uso' then `llave_id` else NULL end) STORED, PRIMARY KEY (`id`)) ENGINE=InnoDB;\n\n"
    ."INSERT INTO `prueba_gen` (`id`, `estado`, `nota`, `llave_id`, `llave_en_uso`) VALUES\n(1, 'en_uso', 'O\\'Higgins, (piso 2)', 7, 7),\n(2, 'devuelta', NULL, 8, NULL);\n"
    ."SET FOREIGN_KEY_CHECKS=1;\n"));
(new ImportadorSql)->importar($anterior, $destino);
@unlink($anterior);
if ((int) $destino->table('prueba_gen')->where('id', 1)->value('llave_en_uso') !== 7
    || $destino->table('prueba_gen')->where('id', 1)->value('nota') !== "O'Higgins, (piso 2)") {
    falla('el respaldo con el formato anterior no se importó bien');
}
echo "Respaldo con formato anterior importado en MariaDB.\n";

// Mantenimiento de la base (docs/tecnico/integridad-bd.md): la base restaurada conserva los CHECK y las llaves restrict
$contar = fn (string $base, string $sql) => (int) $root->query(str_replace('{base}', $base, $sql))->fetchColumn();
$checks = "SELECT COUNT(*) FROM information_schema.table_constraints WHERE constraint_schema = '{base}' AND constraint_type = 'CHECK' AND constraint_name LIKE 'chk\\_%'";
$restrict = "SELECT COUNT(*) FROM information_schema.referential_constraints WHERE constraint_schema = '{base}' AND delete_rule = 'RESTRICT'";
$origen = env('DB_DATABASE');
if ($contar($origen, $checks) < 15 || $contar('plataforma_ci_destino', $checks) !== $contar($origen, $checks)) {
    falla('la base restaurada no tiene los mismos CHECK ('.$contar('plataforma_ci_destino', $checks).' de '.$contar($origen, $checks).')');
}
$llaves = fn (string $base) => $root->query("SELECT CONCAT(table_name, '.', constraint_name, ' ', delete_rule) FROM information_schema.referential_constraints WHERE constraint_schema = '{$base}' ORDER BY 1")->fetchAll(PDO::FETCH_COLUMN);
$diferencia = array_merge(
    array_map(fn ($l) => "solo en origen: {$l}", array_diff($llaves($origen), $llaves('plataforma_ci_destino'))),
    array_map(fn ($l) => "solo en destino: {$l}", array_diff($llaves('plataforma_ci_destino'), $llaves($origen))),
);
if ($diferencia !== [] || $contar($origen, $restrict) < 95) {
    falla('la base restaurada no tiene las mismas llaves foráneas ('.$contar($origen, $restrict).' restrict en origen): '.implode('; ', array_slice($diferencia, 0, 20)));
}
try {
    $destino->table('prestamos_llaves')->where('id', 2)->update(['estado' => 'inventado']);
    falla('la base restaurada aceptó un estado fuera de catálogo');
} catch (Illuminate\Database\QueryException) {
}
echo 'Base restaurada con '.$contar('plataforma_ci_destino', $checks).' CHECK y '.$contar('plataforma_ci_destino', $restrict)." llaves restrict.\n";

@unlink($respaldo['ruta']);
DB::table('prestamos_llaves')->delete();
DB::table('equipos_responsiva')->delete();
$root->exec('DROP DATABASE plataforma_ci_destino');
