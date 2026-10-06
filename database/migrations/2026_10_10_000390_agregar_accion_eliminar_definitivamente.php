<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Acción "borrar" (Eliminar definitivamente): borrado físico controlado de
 * catálogos y padrones (ver docs/tecnico/borrado.md). Es distinta de
 * "eliminar", que en casi todos los módulos es la baja (desactivar).
 *
 * En una instalación nueva no hace nada (el catálogo y las plantillas los
 * crean los seeders). En una base existente (QA, Producción) el despliegue
 * corre las migraciones ANTES que los seeders, así que aquí:
 *  - se da de alta la acción y se liga a los módulos de RegistroBorrado
 *    (nunca a Empresas, Usuarios ni a bitácoras o evidencias);
 *  - se otorga a los roles "Administrador" (plantilla y copias de cada
 *    empresa), con alcance de empresa, en los módulos donde ya tienen
 *    "eliminar". Nadie más la recibe.
 * Solo agrega; nunca quita ni cambia permisos que ya tenga un rol.
 */
return new class extends Migration
{
    /** Los de RegistroBorrado::modulos() al crear esta migración (fijos: no deben cambiar con el código). */
    private const MODULOS = [
        'sedes', 'espacios', 'departamentos', 'puestos', 'turnos', 'colaboradores', 'roles', 'proveedores',
        'visitantes', 'vehiculos', 'llaves', 'gafetes', 'equipos', 'estacionamientos', 'rutas',
    ];

    public function up(): void
    {
        $modulos = DB::table('modulos')->whereIn('clave', self::MODULOS)->pluck('id', 'clave');
        if ($modulos->isEmpty()) {
            return;
        }

        $ahora = now();

        $accion = DB::table('acciones')->where('clave', 'borrar')->value('id');
        if ($accion === null) {
            $accion = DB::table('acciones')->insertGetId([
                'clave' => 'borrar',
                'nombre' => 'Eliminar definitivamente',
                'orden' => (int) DB::table('acciones')->max('orden') + 1,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }

        $eliminar = DB::table('acciones')->where('clave', 'eliminar')->value('id');
        $administradores = DB::table('roles')->where('nombre', 'Administrador')->pluck('id');

        foreach ($modulos as $moduloId) {
            DB::table('modulo_acciones')->insertOrIgnore([
                'modulo_id' => $moduloId,
                'accion_id' => $accion,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);

            $borrar = DB::table('modulo_acciones')->where('modulo_id', $moduloId)->where('accion_id', $accion)->value('id');
            $baja = DB::table('modulo_acciones')->where('modulo_id', $moduloId)->where('accion_id', $eliminar)->value('id');
            if ($borrar === null || $baja === null) {
                continue;
            }

            $conBaja = DB::table('rol_permisos')->whereIn('rol_id', $administradores)->where('modulo_accion_id', $baja)->pluck('rol_id');
            foreach ($conBaja as $rolId) {
                DB::table('rol_permisos')->insertOrIgnore([
                    'rol_id' => $rolId,
                    'modulo_accion_id' => $borrar,
                    'alcance' => 'empresa',
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ]);
            }
        }
    }

    public function down(): void
    {
        $accion = DB::table('acciones')->where('clave', 'borrar')->value('id');
        if ($accion === null) {
            return;
        }

        // Al borrar la relación módulo-acción se borran en cascada los permisos otorgados
        DB::table('rol_permisos')->whereIn('modulo_accion_id', DB::table('modulo_acciones')->where('accion_id', $accion)->select('id'))->delete();
        DB::table('modulo_acciones')->where('accion_id', $accion)->delete();
        DB::table('acciones')->where('id', $accion)->delete();
    }
};
