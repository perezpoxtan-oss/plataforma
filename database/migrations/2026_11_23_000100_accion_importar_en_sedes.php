<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Importar estructura (docs/tecnico/importar-estructura.md): acción
 * «importar» en el módulo Sedes = permiso `sedes.importar`.
 *
 *  - La liga al módulo (el catálogo la vuelve a poner en cada despliegue;
 *    aquí se pone para no depender de él).
 *  - Para no ampliar de más: solo los roles (plantillas y roles de cada
 *    empresa) que hoy pueden dar de alta sedes (`sedes.crear`) reciben
 *    `sedes.importar` con el MISMO alcance. De fábrica es el Administrador
 *    de la empresa. El Super Administrador no necesita permiso.
 *
 * En una instalación nueva no hace nada (el catálogo y las plantillas los
 * crean los seeders).
 *
 * down(): quita la acción del módulo Sedes (los permisos otorgados se
 * borran en cascada: rol_permisos → modulo_acciones). La acción «importar»
 * se queda: la usan los padrones.
 */
return new class extends Migration
{
    public function up(): void
    {
        $sedes = DB::table('modulos')->where('clave', 'sedes')->value('id');
        if ($sedes === null) {
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

        DB::table('modulo_acciones')->insertOrIgnore([
            'modulo_id' => $sedes,
            'accion_id' => $importar,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);
        $maImportar = DB::table('modulo_acciones')->where('modulo_id', $sedes)->where('accion_id', $importar)->value('id');
        $maCrear = DB::table('modulo_acciones')->where('modulo_id', $sedes)->where('accion_id', $crear)->value('id');
        if ($maImportar === null || $maCrear === null) {
            return;
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

    public function down(): void
    {
        $sedes = DB::table('modulos')->where('clave', 'sedes')->value('id');
        $importar = DB::table('acciones')->where('clave', 'importar')->value('id');
        if ($sedes === null || $importar === null) {
            return;
        }

        $ma = DB::table('modulo_acciones')->where('modulo_id', $sedes)->where('accion_id', $importar)->value('id');
        if ($ma !== null) {
            // Por si la base no borra en cascada
            DB::table('rol_permisos')->where('modulo_accion_id', $ma)->delete();
            DB::table('modulo_acciones')->where('id', $ma)->delete();
        }
    }
};
