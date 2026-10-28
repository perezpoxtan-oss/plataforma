<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan de correcciones, Etapa 6 · C-33 (D-14): la ficha del colaborador
 * muestra una pestaña de solo lectura «Accidentes» con su permiso propio,
 * «Colaboradores → Ver accidentes» (colaboradores.accidentes). El formato de
 * Accidente no cambia.
 *
 *  - Da de alta la acción y la liga al módulo Colaboradores (el catálogo la
 *    vuelve a poner en cada despliegue; aquí se pone para no depender de él).
 *  - Para no ampliar a quién se muestra un dato de salud, la reciben solo los
 *    roles que ya ven los datos personales del colaborador
 *    (colaboradores.datos_personales: Recursos Humanos y el Administrador) y
 *    los que ya pueden reabrir tickets de Novedades y ven colaboradores
 *    (jefes de seguridad), con el alcance que ya tenían para ver colaboradores.
 *
 * En una instalación nueva no otorga nada (las plantillas lo traen).
 *
 * down(): quita la acción del módulo (los permisos se borran en cascada) y
 * la acción, si ya no la usa ningún módulo.
 */
return new class extends Migration
{
    public function up(): void
    {
        $ahora = now();
        $orden = (int) DB::table('acciones')->max('orden') + 1;
        DB::table('acciones')->insertOrIgnore(['clave' => 'accidentes', 'nombre' => 'Ver accidentes', 'orden' => $orden, 'created_at' => $ahora, 'updated_at' => $ahora]);
        $accion = DB::table('acciones')->where('clave', 'accidentes')->value('id');
        $modulo = DB::table('modulos')->where('clave', 'colaboradores')->value('id');
        if ($accion === null || $modulo === null) {
            return;
        }
        DB::table('modulo_acciones')->insertOrIgnore(['modulo_id' => $modulo, 'accion_id' => $accion, 'created_at' => $ahora, 'updated_at' => $ahora]);
        $nueva = DB::table('modulo_acciones')->where('modulo_id', $modulo)->where('accion_id', $accion)->value('id');

        $ma = fn (string $moduloClave, string $accionClave) => DB::table('modulo_acciones as ma')
            ->join('modulos as m', 'm.id', '=', 'ma.modulo_id')->join('acciones as a', 'a.id', '=', 'ma.accion_id')
            ->where('m.clave', $moduloClave)->where('a.clave', $accionClave)->value('ma.id');
        $ver = $ma('colaboradores', 'ver');
        $personales = $ma('colaboradores', 'datos_personales');
        $reabrir = $ma('novedades', 'reabrir');
        if ($ver === null) {
            return;
        }
        $roles = DB::table('rol_permisos')->whereIn('modulo_accion_id', array_filter([$personales, $reabrir]))->distinct()->pluck('rol_id');
        foreach (DB::table('rol_permisos')->where('modulo_accion_id', $ver)->whereIn('rol_id', $roles)->get(['rol_id', 'alcance']) as $p) {
            DB::table('rol_permisos')->insertOrIgnore([
                'rol_id' => $p->rol_id, 'modulo_accion_id' => $nueva, 'alcance' => $p->alcance, 'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
        }
    }

    public function down(): void
    {
        $accion = DB::table('acciones')->where('clave', 'accidentes')->value('id');
        if ($accion === null) {
            return;
        }
        $ids = DB::table('modulo_acciones')->where('accion_id', $accion)->pluck('id');
        DB::table('rol_permisos')->whereIn('modulo_accion_id', $ids)->delete();
        DB::table('modulo_acciones')->whereIn('id', $ids)->delete();
        DB::table('acciones')->where('id', $accion)->delete();
    }
};
