<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Exportar los datos de una empresa (portabilidad; docs/tecnico/traslado.md).
 *
 *  - Da de alta la acción `exportar` en el módulo Empresas (el catálogo la
 *    vuelve a poner en cada despliegue; aquí se pone para no depender de él).
 *  - Se otorga, con alcance de empresa, solo a los roles que hoy EDITAN la
 *    empresa con alcance de empresa (en la práctica, el Administrador de cada
 *    cliente y su plantilla). El Director no la recibe: exportar es sacar
 *    todos los datos personales de la empresa.
 *
 * En una instalación nueva no hace nada (las tablas están vacías; el catálogo
 * y las plantillas los crean los seeders).
 *
 * down(): quita la acción del módulo Empresas; los permisos otorgados se
 * borran en cascada (rol_permisos → modulo_acciones). No toca índices ni
 * llaves foráneas (seguro en MariaDB).
 */
return new class extends Migration
{
    public function up(): void
    {
        $exportar = DB::table('acciones')->where('clave', 'exportar')->value('id');
        $editar = DB::table('acciones')->where('clave', 'editar')->value('id');
        $modulo = DB::table('modulos')->where('clave', 'empresas')->value('id');
        if ($exportar === null || $editar === null || $modulo === null) {
            return;
        }
        $ahora = now();

        DB::table('modulo_acciones')->insertOrIgnore([
            'modulo_id' => $modulo,
            'accion_id' => $exportar,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);
        $maExportar = DB::table('modulo_acciones')->where('modulo_id', $modulo)->where('accion_id', $exportar)->value('id');
        $maEditar = DB::table('modulo_acciones')->where('modulo_id', $modulo)->where('accion_id', $editar)->value('id');
        if ($maExportar === null || $maEditar === null) {
            return;
        }

        foreach (DB::table('rol_permisos')->where('modulo_accion_id', $maEditar)->where('alcance', 'empresa')->pluck('rol_id') as $rolId) {
            DB::table('rol_permisos')->insertOrIgnore([
                'rol_id' => $rolId,
                'modulo_accion_id' => $maExportar,
                'alcance' => 'empresa',
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }
    }

    public function down(): void
    {
        $exportar = DB::table('acciones')->where('clave', 'exportar')->value('id');
        $modulo = DB::table('modulos')->where('clave', 'empresas')->value('id');
        if ($exportar === null || $modulo === null) {
            return;
        }
        $maExportar = DB::table('modulo_acciones')->where('modulo_id', $modulo)->where('accion_id', $exportar)->value('id');
        if ($maExportar === null) {
            return;
        }
        // Explícito además de la cascada (SQLite sin llaves foráneas activas)
        DB::table('rol_permisos')->where('modulo_accion_id', $maExportar)->delete();
        DB::table('modulo_acciones')->where('id', $maExportar)->delete();
    }
};
