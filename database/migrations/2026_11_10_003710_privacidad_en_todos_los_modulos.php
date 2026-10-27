<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Privacidad completa (C-37, X-01; docs/tecnico/privacidad.md).
 *
 *  - datos_eliminados_en en las tablas que ahora alcanzan las solicitudes
 *    ARCO y la conservación de datos: reportes de pérdida y entregas de Lost
 *    & Found, novedades (sus detalles de accidente y testigos), reservas y
 *    movimientos de transporte. Marca que ya se quitaron los datos
 *    personales de ese registro (el registro se conserva).
 *  - Empresas que ya existían: los plazos nuevos de conservación quedan SIN
 *    CONFIRMAR (preferencias.privacidad.plazos_ampliados = false) hasta que
 *    el administrador guarde la tarjeta de Conservación: nada se borra sin
 *    su aprobación. Las empresas nuevas los traen confirmados.
 *
 * down(): quita la marca de las empresas y las columnas.
 */
return new class extends Migration
{
    public const TABLAS = ['lost_found_reportes_perdida', 'lost_found_entregas', 'novedades', 'reservas', 'movimientos_transporte'];

    public function up(): void
    {
        foreach (self::TABLAS as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->timestamp('datos_eliminados_en')->nullable();
            });
        }

        foreach (DB::table('empresas')->get(['id', 'preferencias']) as $e) {
            $p = is_string($e->preferencias) ? (json_decode($e->preferencias, true) ?: []) : [];
            $p['privacidad'] = array_merge(is_array($p['privacidad'] ?? null) ? $p['privacidad'] : [], ['plazos_ampliados' => false]);
            DB::table('empresas')->where('id', $e->id)->update(['preferencias' => json_encode($p, JSON_UNESCAPED_UNICODE)]);
        }
    }

    public function down(): void
    {
        foreach (DB::table('empresas')->whereNotNull('preferencias')->get(['id', 'preferencias']) as $e) {
            $p = is_string($e->preferencias) ? (json_decode($e->preferencias, true) ?: []) : [];
            if (! is_array($p['privacidad'] ?? null) || ! array_key_exists('plazos_ampliados', $p['privacidad'])) {
                continue;
            }
            unset($p['privacidad']['plazos_ampliados']);
            if ($p['privacidad'] === []) {
                unset($p['privacidad']);
            }
            DB::table('empresas')->where('id', $e->id)->update(['preferencias' => $p === [] ? null : json_encode($p, JSON_UNESCAPED_UNICODE)]);
        }

        foreach (self::TABLAS as $tabla) {
            Schema::table($tabla, function (Blueprint $table) {
                $table->dropColumn('datos_eliminados_en');
            });
        }
    }
};
