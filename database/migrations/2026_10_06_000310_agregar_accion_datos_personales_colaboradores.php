<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Acción adicional "datos_personales" del módulo Colaboradores
 * (colaboradores.datos_personales): ver y capturar CURP, RFC, NSS, fecha y
 * estado de nacimiento, nacionalidad, correo personal y dirección.
 *
 * En una instalación nueva no hace nada (el catálogo y las plantillas los
 * crean los seeders). En una base existente (QA, Producción) el despliegue
 * corre las migraciones ANTES que los seeders, así que aquí se da de alta la
 * acción y se otorga a los roles "Administrador" (plantilla y copias de cada
 * empresa) que ya editan colaboradores, con el mismo alcance que su
 * "colaboradores.editar". Solo agrega; nunca quita ni cambia permisos.
 */
return new class extends Migration
{
    public function up(): void
    {
        $modulo = DB::table('modulos')->where('clave', 'colaboradores')->value('id');
        if ($modulo === null) {
            return;
        }

        $ahora = now();

        $accion = DB::table('acciones')->where('clave', 'datos_personales')->value('id');
        if ($accion === null) {
            $accion = DB::table('acciones')->insertGetId([
                'clave' => 'datos_personales',
                'nombre' => 'Datos personales',
                'orden' => (int) DB::table('acciones')->max('orden') + 1,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }

        DB::table('modulo_acciones')->insertOrIgnore([
            'modulo_id' => $modulo,
            'accion_id' => $accion,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);

        $permiso = fn (string $clave) => DB::table('modulo_acciones as ma')
            ->join('acciones as a', 'a.id', '=', 'ma.accion_id')
            ->where('ma.modulo_id', $modulo)
            ->where('a.clave', $clave)
            ->value('ma.id');

        $datosPersonales = $permiso('datos_personales');
        $editar = $permiso('editar');
        if ($datosPersonales === null || $editar === null) {
            return;
        }

        $administradores = DB::table('roles as r')
            ->join('rol_permisos as rp', 'rp.rol_id', '=', 'r.id')
            ->where('r.nombre', 'Administrador')
            ->where('rp.modulo_accion_id', $editar)
            ->get(['r.id', 'rp.alcance']);

        foreach ($administradores as $rol) {
            DB::table('rol_permisos')->insertOrIgnore([
                'rol_id' => $rol->id,
                'modulo_accion_id' => $datosPersonales,
                'alcance' => $rol->alcance,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }
    }

    public function down(): void
    {
        $accion = DB::table('acciones')->where('clave', 'datos_personales')->value('id');
        if ($accion === null) {
            return;
        }

        // Al borrar la relación módulo-acción se borran en cascada los permisos otorgados
        DB::table('modulo_acciones')->where('accion_id', $accion)->delete();
        DB::table('acciones')->where('id', $accion)->delete();
    }
};
