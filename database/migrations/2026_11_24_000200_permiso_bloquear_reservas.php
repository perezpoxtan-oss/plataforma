<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Bloqueos de sitios (docs/tecnico/reservas.md, «Bloqueos»): acción
 * «bloquear» en el módulo Reservas de áreas = permiso `reservas.bloquear`.
 *
 * Bloquean, además, por designación (sin permiso): el responsable general
 * de reservas y su suplente (cualquier sitio de la empresa) y el responsable
 * y el suplente de cada área (solo la suya). El permiso es para quien
 * administra las reservas de la sede sin estar designado.
 *
 *  - Para no ampliar de más: solo los roles (plantillas y roles de cada
 *    empresa) que hoy configuran reservas (`reservas.configurar`) reciben
 *    `reservas.bloquear` con el MISMO alcance. De fábrica es el
 *    Administrador (empresa). El Super Administrador no necesita permiso.
 *  - En una instalación nueva no hace nada (el catálogo y las plantillas los
 *    crean los seeders con la misma regla).
 *
 * down(): quita la acción del módulo (y los permisos otorgados con ella) y
 * la acción «bloquear» si ningún otro módulo la usa.
 */
return new class extends Migration
{
    public function up(): void
    {
        $reservas = DB::table('modulos')->where('clave', 'reservas')->value('id');
        if ($reservas === null) {
            return;
        }
        $ahora = now();

        $bloquear = DB::table('acciones')->where('clave', 'bloquear')->value('id');
        if ($bloquear === null) {
            $bloquear = DB::table('acciones')->insertGetId([
                'clave' => 'bloquear',
                'nombre' => 'Bloquear',
                'orden' => (int) DB::table('acciones')->max('orden') + 1,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }
        $configurar = DB::table('acciones')->where('clave', 'configurar')->value('id');

        DB::table('modulo_acciones')->insertOrIgnore([
            'modulo_id' => $reservas,
            'accion_id' => $bloquear,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);
        $maBloquear = DB::table('modulo_acciones')->where('modulo_id', $reservas)->where('accion_id', $bloquear)->value('id');
        $maConfigurar = DB::table('modulo_acciones')->where('modulo_id', $reservas)->where('accion_id', $configurar)->value('id');
        if ($maBloquear === null || $maConfigurar === null) {
            return;
        }

        foreach (DB::table('rol_permisos')->where('modulo_accion_id', $maConfigurar)->get(['rol_id', 'alcance']) as $permiso) {
            DB::table('rol_permisos')->insertOrIgnore([
                'rol_id' => $permiso->rol_id,
                'modulo_accion_id' => $maBloquear,
                'alcance' => $permiso->alcance,
                'created_at' => $ahora,
                'updated_at' => $ahora,
            ]);
        }
    }

    public function down(): void
    {
        $bloquear = DB::table('acciones')->where('clave', 'bloquear')->value('id');
        if ($bloquear === null) {
            return;
        }
        $reservas = DB::table('modulos')->where('clave', 'reservas')->value('id');
        $ma = $reservas === null ? null : DB::table('modulo_acciones')->where('modulo_id', $reservas)->where('accion_id', $bloquear)->value('id');
        if ($ma !== null) {
            // Por si la base no borra en cascada
            DB::table('rol_permisos')->where('modulo_accion_id', $ma)->delete();
            DB::table('modulo_acciones')->where('id', $ma)->delete();
        }
        if (! DB::table('modulo_acciones')->where('accion_id', $bloquear)->exists()) {
            DB::table('acciones')->where('id', $bloquear)->delete();
        }
    }
};
