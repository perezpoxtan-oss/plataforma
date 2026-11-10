<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Acción nueva «importar» (Importar desde Excel) en Colaboradores, Padrón de
 * personas, Proveedores (Empresas externas), Padrón vehicular y Zonas y
 * edificios.
 *
 *  - Da de alta la acción y la liga a esos cinco módulos (el catálogo la
 *    vuelve a poner en cada despliegue; aquí se pone para no depender de él).
 *  - Para no quitar nada a nadie ni ampliar de más: todo rol (plantillas y
 *    roles de cada empresa) que hoy tiene `crear` en uno de esos módulos
 *    recibe `importar` con el MISMO alcance que su `crear`.
 *
 * En una instalación nueva no hace nada (las tablas están vacías; el
 * catálogo y las plantillas los crean los seeders).
 *
 * down(): quita la acción de los cinco módulos (los permisos otorgados se
 * borran en cascada: rol_permisos → modulo_acciones) y borra la acción si ya
 * no la usa ningún módulo.
 */
return new class extends Migration
{
    private const MODULOS = ['colaboradores', 'visitantes', 'proveedores', 'vehiculos', 'espacios'];

    public function up(): void
    {
        $modulos = DB::table('modulos')->whereIn('clave', self::MODULOS)->pluck('id');
        if ($modulos->isEmpty()) {
            return;
        }
        $ahora = now();

        $importar = DB::table('acciones')->where('clave', 'importar')->value('id');
        if ($importar === null) {
            $importar = DB::table('acciones')->insertGetId([
                'clave' => 'importar',
                'nombre' => 'Importar',
                'orden' => (int) DB::table('acciones')->max('orden') + 1,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }
        $crear = DB::table('acciones')->where('clave', 'crear')->value('id');

        foreach ($modulos as $moduloId) {
            DB::table('modulo_acciones')->insertOrIgnore([
                'modulo_id' => $moduloId,
                'accion_id' => $importar,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
            $maImportar = DB::table('modulo_acciones')->where('modulo_id', $moduloId)->where('accion_id', $importar)->value('id');
            $maCrear = DB::table('modulo_acciones')->where('modulo_id', $moduloId)->where('accion_id', $crear)->value('id');
            if ($maImportar === null || $maCrear === null) {
                continue;
            }

            foreach (DB::table('rol_permisos')->where('modulo_accion_id', $maCrear)->get(['rol_id', 'alcance']) as $permiso) {
                DB::table('rol_permisos')->insertOrIgnore([
                    'rol_id' => $permiso->rol_id,
                    'modulo_accion_id' => $maImportar,
                    'alcance' => $permiso->alcance,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ]);
            }
        }
    }

    public function down(): void
    {
        $importar = DB::table('acciones')->where('clave', 'importar')->value('id');
        if ($importar === null) {
            return;
        }

        $modulos = DB::table('modulos')->whereIn('clave', self::MODULOS)->pluck('id');
        // Los permisos de rol se borran en cascada
        DB::table('modulo_acciones')->where('accion_id', $importar)->whereIn('modulo_id', $modulos)->delete();
        if (! DB::table('modulo_acciones')->where('accion_id', $importar)->exists()) {
            DB::table('acciones')->where('id', $importar)->delete();
        }
    }
};
