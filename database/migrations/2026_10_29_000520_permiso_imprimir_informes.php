<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Decisión del dueño (mapa de procesos): «Imprimir» es un permiso propio en
 * los informes. La Bitácora general del día ya lo tenía; se agrega a
 * Tablero (dashboard), Informe ejecutivo y Tendencias.
 *
 *  - Da de alta la acción `imprimir` en esos tres módulos (el catálogo la
 *    vuelve a poner en cada despliegue; aquí se pone para no depender de él).
 *  - Para no quitar nada a nadie, todo rol (plantillas y roles de cada
 *    empresa) que hoy tiene `ver` en uno de esos tres módulos recibe
 *    `imprimir` con el MISMO alcance que su `ver`.
 *  - La Bitácora general del día no cambia: su hoja ya pedía `imprimir` y
 *    cada empresa ya decidió quién la imprime (dárselo a todos los que la ven
 *    ampliaría permisos que alguien quitó a propósito).
 *
 * En una instalación nueva no hace nada (las tablas están vacías; el
 * catálogo y las plantillas los crean los seeders).
 *
 * down(): quita la acción de los tres módulos; los permisos otorgados se
 * borran en cascada (rol_permisos → modulo_acciones).
 */
return new class extends Migration
{
    private const MODULOS = ['dashboard', 'informe_ejecutivo', 'tendencias'];

    public function up(): void
    {
        $imprimir = DB::table('acciones')->where('clave', 'imprimir')->value('id');
        if ($imprimir === null) {
            return;
        }
        $ver = DB::table('acciones')->where('clave', 'ver')->value('id');
        $ahora = now();

        foreach (DB::table('modulos')->whereIn('clave', self::MODULOS)->pluck('id') as $moduloId) {
            DB::table('modulo_acciones')->insertOrIgnore([
                'modulo_id' => $moduloId,
                'accion_id' => $imprimir,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
            $maImprimir = DB::table('modulo_acciones')->where('modulo_id', $moduloId)->where('accion_id', $imprimir)->value('id');
            $maVer = DB::table('modulo_acciones')->where('modulo_id', $moduloId)->where('accion_id', $ver)->value('id');
            if ($maImprimir === null || $maVer === null) {
                continue;
            }

            foreach (DB::table('rol_permisos')->where('modulo_accion_id', $maVer)->get(['rol_id', 'alcance']) as $permiso) {
                DB::table('rol_permisos')->insertOrIgnore([
                    'rol_id' => $permiso->rol_id,
                    'modulo_accion_id' => $maImprimir,
                    'alcance' => $permiso->alcance,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ]);
            }
        }
    }

    public function down(): void
    {
        $imprimir = DB::table('acciones')->where('clave', 'imprimir')->value('id');
        if ($imprimir === null) {
            return;
        }

        $modulos = DB::table('modulos')->whereIn('clave', self::MODULOS)->pluck('id');
        // Los permisos de rol se borran en cascada
        DB::table('modulo_acciones')->where('accion_id', $imprimir)->whereIn('modulo_id', $modulos)->delete();
    }
};
