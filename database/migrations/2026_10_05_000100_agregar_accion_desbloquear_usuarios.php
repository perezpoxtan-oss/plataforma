<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Acción adicional "desbloquear" del módulo Usuarios (usuarios.desbloquear):
 * quita a mano el bloqueo por intentos fallidos.
 *
 * En una instalación nueva no hace nada (el catálogo y las plantillas los
 * crean los seeders). En una base existente (QA, Producción) el despliegue
 * corre las migraciones ANTES que los seeders, así que aquí se da de alta la
 * acción y se otorga a los roles que ya existen:
 *  - "Administrador" (plantilla y copias de cada empresa) que ya edita
 *    usuarios: con el mismo alcance que su "usuarios.editar";
 *  - "Jefe de seguridad" (plantilla y copias): "usuarios.ver" y
 *    "usuarios.desbloquear" con alcance de su sede.
 * Solo agrega; nunca quita ni cambia permisos que ya tenga un rol.
 */
return new class extends Migration
{
    public function up(): void
    {
        $modulo = DB::table('modulos')->where('clave', 'usuarios')->value('id');
        if ($modulo === null) {
            return;
        }

        $ahora = now();

        $accion = DB::table('acciones')->where('clave', 'desbloquear')->value('id');
        if ($accion === null) {
            $accion = DB::table('acciones')->insertGetId([
                'clave' => 'desbloquear',
                'nombre' => 'Desbloquear',
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

        $desbloquear = $permiso('desbloquear');
        $ver = $permiso('ver');
        $editar = $permiso('editar');

        // Administrador: quien ya edita usuarios también desbloquea, con el mismo alcance
        $administradores = DB::table('roles as r')
            ->join('rol_permisos as rp', 'rp.rol_id', '=', 'r.id')
            ->where('r.nombre', 'Administrador')
            ->where('rp.modulo_accion_id', $editar)
            ->get(['r.id', 'rp.alcance']);

        foreach ($administradores as $rol) {
            $this->otorgar($rol->id, $desbloquear, $rol->alcance, $ahora);
        }

        // Jefe de seguridad: ve los usuarios de su sede y los desbloquea
        foreach (DB::table('roles')->where('nombre', 'Jefe de seguridad')->pluck('id') as $rolId) {
            $this->otorgar($rolId, $ver, 'sede', $ahora);
            $this->otorgar($rolId, $desbloquear, 'sede', $ahora);
        }
    }

    private function otorgar(int $rolId, ?int $moduloAccionId, string $alcance, $ahora): void
    {
        if ($moduloAccionId === null) {
            return;
        }

        DB::table('rol_permisos')->insertOrIgnore([
            'rol_id' => $rolId,
            'modulo_accion_id' => $moduloAccionId,
            'alcance' => $alcance,
            'created_at' => $ahora,
            'updated_at' => $ahora,
        ]);
    }

    public function down(): void
    {
        $accion = DB::table('acciones')->where('clave', 'desbloquear')->value('id');
        if ($accion === null) {
            return;
        }

        // Al borrar la relación módulo-acción se borran en cascada los permisos otorgados
        DB::table('modulo_acciones')->where('accion_id', $accion)->delete();
        DB::table('acciones')->where('id', $accion)->delete();
    }
};
