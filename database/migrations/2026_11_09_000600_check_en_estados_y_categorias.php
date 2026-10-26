<?php

use App\Models\ConfiguracionPlataforma;
use App\Support\BaseDatos\CatalogoChecks;
use App\Support\BaseDatos\Restricciones;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Mantenimiento de la base (docs/tecnico/integridad-bd.md, «CHECK»): la base
 * rechaza un estado, etapa o categoría que no esté en las constantes de los
 * modelos (CatalogoChecks::definiciones()): accesos, novedades, pases de
 * salida, préstamos de llaves, responsivas, reservas, candidatos y
 * postulaciones, transporte, recorridos PC, autorizaciones, Lost & Found y
 * solicitudes ARCO.
 *
 * - Solo se agrega el CHECK de una columna si TODAS sus filas cumplen (la
 *   migración anterior normalizó); si no, se omite esa columna, se anota en
 *   el log y en configuracion_plataforma («restricciones_check.omitidos») y
 *   la migración no falla. Se puede agregar después con otra migración.
 * - MariaDB/MySQL: un ALTER por tabla (revisa las filas una vez). SQLite
 *   (pruebas): edición del esquema sin reconstruir la tabla.
 *
 * down() quita los CHECK. Idempotente.
 */
return new class extends Migration
{
    public $withinTransaction = false;

    public function up(): void
    {
        $omitidos = [];
        foreach (CatalogoChecks::definiciones() as $tabla => $columnas) {
            $poner = [];
            foreach ($columnas as $columna => $valores) {
                if (! Schema::hasColumn($tabla, $columna)) {
                    continue; // la crea una migración posterior (C-14 agrega su propio CHECK)
                }
                $malos = Restricciones::fueraDeCatalogo($tabla, $columna, $valores);
                if ($malos > 0) {
                    $omitidos["{$tabla}.{$columna}"] = $malos;

                    continue;
                }
                $poner[$columna] = $valores;
            }
            if ($poner !== []) {
                Restricciones::agregarChecks($tabla, $poner);
            }
        }
        if ($omitidos !== []) {
            Log::warning('Mantenimiento de la base: CHECK omitidos por valores fuera de catálogo', $omitidos);
        }
        $registro = ConfiguracionPlataforma::firstOrNew(['clave' => 'restricciones_check']);
        $registro->valor = array_merge(is_array($registro->valor) ? $registro->valor : [], ['omitidos' => $omitidos, 'check_en' => now()->toIso8601String()]);
        $registro->save();
    }

    public function down(): void
    {
        foreach (CatalogoChecks::definiciones() as $tabla => $columnas) {
            Restricciones::quitarChecks($tabla, array_keys($columnas));
        }
        $registro = ConfiguracionPlataforma::where('clave', 'restricciones_check')->first();
        if ($registro !== null && is_array($registro->valor)) {
            $valor = $registro->valor;
            unset($valor['omitidos'], $valor['check_en']);
            $registro->valor = $valor;
            $registro->save();
        }
    }
};
