<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Candidatos, fase 2 (ADR-0009): permiso «candidatos.evaluar» (Entrevistar:
 * evaluar y elegir a los candidatos que Recursos Humanos canaliza).
 *
 * En una instalación nueva lo crean los seeders (CatalogoSeeder y
 * RolesPlantillaSeeder). En una base existente:
 *  - crea la acción «evaluar» y el permiso candidatos.evaluar;
 *  - lo otorga, con el MISMO alcance, a cada rol (plantillas y roles de cada
 *    empresa) que hoy responde autorizaciones (autorizaciones.responder): así
 *    quien respondía «Bajar a entrevistar» (Jefe de departamento, Director,
 *    Jefe de seguridad, Supervisor…) sigue entrevistando.
 * Solo agrega (idempotente).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! DB::table('acciones')->exists()) {
            return; // instalación nueva: lo crean los seeders
        }
        $ahora = now();
        $accion = DB::table('acciones')->where('clave', 'evaluar')->value('id')
            ?? DB::table('acciones')->insertGetId(['clave' => 'evaluar', 'nombre' => 'Evaluar', 'orden' => (int) DB::table('acciones')->max('orden') + 1,
                'created_at' => $ahora, 'updated_at' => $ahora]);
        $modulo = DB::table('modulos')->where('clave', 'candidatos')->value('id');
        if ($modulo === null) {
            return;
        }
        DB::table('modulo_acciones')->insertOrIgnore(['modulo_id' => $modulo, 'accion_id' => $accion, 'created_at' => $ahora, 'updated_at' => $ahora]);
        $destino = DB::table('modulo_acciones')->where('modulo_id', $modulo)->where('accion_id', $accion)->value('id');
        $origen = DB::table('modulo_acciones as ma')->join('modulos as m', 'm.id', '=', 'ma.modulo_id')->join('acciones as a', 'a.id', '=', 'ma.accion_id')
            ->where('m.clave', 'autorizaciones')->where('a.clave', 'responder')->value('ma.id');
        if ($destino === null || $origen === null) {
            return;
        }
        foreach (DB::table('rol_permisos')->where('modulo_accion_id', $origen)->get(['rol_id', 'alcance']) as $p) {
            DB::table('rol_permisos')->insertOrIgnore(['rol_id' => $p->rol_id, 'modulo_accion_id' => $destino, 'alcance' => $p->alcance,
                'created_at' => $ahora, 'updated_at' => $ahora]);
        }
    }

    public function down(): void
    {
        $accion = DB::table('acciones')->where('clave', 'evaluar')->value('id');
        if ($accion === null) {
            return;
        }
        $ids = DB::table('modulo_acciones')->where('accion_id', $accion)->pluck('id');
        DB::table('rol_permisos')->whereIn('modulo_accion_id', $ids)->delete();
        DB::table('modulo_acciones')->whereIn('id', $ids)->delete();
        DB::table('acciones')->where('id', $accion)->delete();
    }
};
