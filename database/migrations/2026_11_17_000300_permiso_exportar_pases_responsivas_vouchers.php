<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Plan de correcciones, Etapa 7 · C-43 (E-13): exportar a Excel también
 * Pases de salida, Responsivas y Vouchers, cada uno con su permiso
 * «Exportar». (Colaboradores → Exportar ya existía en el catálogo; ahora
 * tiene su botón.)
 *
 *  - Liga la acción «exportar» a los tres módulos (el catálogo la vuelve a
 *    poner en cada despliegue; aquí se pone para no depender de él).
 *  - La reciben, con el alcance con que ven el módulo, los roles que ya
 *    exportan la Bitácora de accesos (accesos.exportar): es la misma regla de
 *    las plantillas (Administrador, Director, Jefe de seguridad, Asistente y
 *    Supervisor sí; Agente y Solicitante no), y los roles propios siguen a su
 *    permiso de exportar la operación.
 *
 * En una instalación nueva no otorga nada (las plantillas lo traen).
 *
 * down(): quita la acción de los tres módulos (con sus permisos). La acción
 * «exportar» se queda: la usan otros módulos.
 */
return new class extends Migration
{
    private const MODULOS = ['pases_salida', 'responsivas', 'vouchers'];

    public function up(): void
    {
        $ahora = now();
        $exportar = DB::table('acciones')->where('clave', 'exportar')->value('id');
        $ver = DB::table('acciones')->where('clave', 'ver')->value('id');
        if ($exportar === null || $ver === null) {
            return;
        }
        $accesosExportar = DB::table('modulo_acciones')->where('accion_id', $exportar)
            ->where('modulo_id', DB::table('modulos')->where('clave', 'accesos')->value('id'))->value('id');
        $exportan = $accesosExportar === null ? collect() : DB::table('rol_permisos')->where('modulo_accion_id', $accesosExportar)->pluck('rol_id');
        foreach (DB::table('modulos')->whereIn('clave', self::MODULOS)->pluck('id') as $modulo) {
            DB::table('modulo_acciones')->insertOrIgnore(['modulo_id' => $modulo, 'accion_id' => $exportar, 'created_at' => $ahora, 'updated_at' => $ahora]);
            $nueva = DB::table('modulo_acciones')->where('modulo_id', $modulo)->where('accion_id', $exportar)->value('id');
            $deVer = DB::table('modulo_acciones')->where('modulo_id', $modulo)->where('accion_id', $ver)->value('id');
            if ($nueva === null || $deVer === null) {
                continue;
            }
            foreach (DB::table('rol_permisos')->where('modulo_accion_id', $deVer)->whereIn('rol_id', $exportan)->get(['rol_id', 'alcance']) as $p) {
                DB::table('rol_permisos')->insertOrIgnore([
                    'rol_id' => $p->rol_id, 'modulo_accion_id' => $nueva, 'alcance' => $p->alcance, 'created_at' => $ahora, 'updated_at' => $ahora,
                ]);
            }
        }
    }

    public function down(): void
    {
        $exportar = DB::table('acciones')->where('clave', 'exportar')->value('id');
        if ($exportar === null) {
            return;
        }
        $ids = DB::table('modulo_acciones')->where('accion_id', $exportar)
            ->whereIn('modulo_id', DB::table('modulos')->whereIn('clave', self::MODULOS)->select('id'))->pluck('id');
        DB::table('rol_permisos')->whereIn('modulo_accion_id', $ids)->delete();
        DB::table('modulo_acciones')->whereIn('id', $ids)->delete();
    }
};
