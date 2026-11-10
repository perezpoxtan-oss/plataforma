<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Informes (ADR-0017): Tablero, Bitácora general del día, Tendencias e
 * Informe ejecutivo ya tienen pantalla.
 *
 *  - modulos.ruta de los cuatro (el menú Informes aparece y la Matriz deja
 *    de marcarlos «Próximamente»). El catálogo lo vuelve a poner en cada
 *    despliegue; aquí se pone para que no dependa de él.
 *  - Índices por empresa y fecha para contar por periodo sin recorrer toda
 *    la tabla (accesos, novedades, transporte y pases de salida). Ninguno
 *    sostiene llaves foráneas: cada tabla ya tiene otro índice que empieza
 *    por empresa_id.
 *
 * No hay tablas nuevas ni datos que convertir.
 */
return new class extends Migration
{
    private const RUTAS = [
        'dashboard' => 'tablero.index',
        'bitacora_dia' => 'bitacora_dia.index',
        'tendencias' => 'tendencias.index',
        'informe_ejecutivo' => 'informe_ejecutivo.index',
    ];

    /** tabla => [columna de fecha, nombre del índice] */
    private const INDICES = [
        'accesos' => ['entrada_at', 'accesos_empresa_entrada_idx'],
        'novedades' => ['created_at', 'novedades_empresa_creado_idx'],
        'movimientos_transporte' => ['created_at', 'mov_transporte_empresa_creado_idx'],
        'pases_salida' => ['created_at', 'pases_salida_empresa_creado_idx'],
    ];

    public function up(): void
    {
        foreach (self::RUTAS as $clave => $ruta) {
            DB::table('modulos')->where('clave', $clave)->whereNull('ruta')->update(['ruta' => $ruta]);
        }

        foreach (self::INDICES as $tabla => [$columna, $nombre]) {
            if (Schema::hasTable($tabla) && ! Schema::hasIndex($tabla, $nombre)) {
                Schema::table($tabla, fn (Blueprint $t) => $t->index(['empresa_id', $columna], $nombre));
            }
        }
    }

    public function down(): void
    {
        foreach (self::INDICES as $tabla => [, $nombre]) {
            if (Schema::hasTable($tabla) && Schema::hasIndex($tabla, $nombre)) {
                Schema::table($tabla, fn (Blueprint $t) => $t->dropIndex($nombre));
            }
        }

        foreach (self::RUTAS as $clave => $ruta) {
            DB::table('modulos')->where('clave', $clave)->where('ruta', $ruta)->update(['ruta' => null]);
        }
    }
};
