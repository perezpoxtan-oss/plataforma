<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El rol "Agente" solo consulta los Padrones (llaves, gafetes, vehículos...):
 * en los módulos del menú Padrones se queda únicamente con "ver"; en
 * Operación conserva ver, crear, editar, imprimir y firmar.
 *
 * Se aplica a la plantilla y a las copias de cada empresa SOLO si el rol
 * sigue exactamente igual a la plantilla anterior (todas las acciones ver,
 * crear, editar, imprimir y firmar de los módulos de Seguridad, con alcance
 * de su sede, y nada más). Un Agente que el cliente ya ajustó no se toca.
 *
 * En una instalación nueva no hace nada: la plantilla ya nace con la regla nueva.
 */
return new class extends Migration
{
    private const ACCIONES_ANTERIORES = ['ver', 'crear', 'editar', 'imprimir', 'firmar'];

    public function up(): void
    {
        $padrones = DB::table('menus')->where('clave', 'padrones')->value('id');
        if ($padrones === null) {
            return;
        }

        // Plantilla anterior: módulo-acción => alcance
        $modulos = DB::table('modulos as m')
            ->join('areas as ar', 'ar.id', '=', 'm.area_id')
            ->leftJoin('modulos as mp', 'mp.id', '=', 'm.padre_id')
            ->where('ar.clave', 'seguridad')
            ->where('m.tipo', '!=', 'plataforma')
            ->get(['m.id', DB::raw('COALESCE(m.menu_id, mp.menu_id) as menu_id')]);

        $filas = DB::table('modulo_acciones as ma')
            ->join('acciones as a', 'a.id', '=', 'ma.accion_id')
            ->whereIn('ma.modulo_id', $modulos->pluck('id'))
            ->whereIn('a.clave', self::ACCIONES_ANTERIORES)
            ->get(['ma.id', 'ma.modulo_id', 'a.clave']);

        $esperado = $filas->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all();
        if ($esperado === []) {
            return;
        }

        $dePadrones = $modulos->filter(fn ($m) => (int) $m->menu_id === (int) $padrones)->pluck('id')->map(fn ($id) => (int) $id)->all();
        $quitar = $filas
            ->filter(fn ($f) => in_array((int) $f->modulo_id, $dePadrones, true) && $f->clave !== 'ver')
            ->pluck('id')
            ->all();

        if ($quitar === []) {
            return;
        }

        foreach (DB::table('roles')->where('nombre', 'Agente')->pluck('id') as $rolId) {
            $actuales = DB::table('rol_permisos')->where('rol_id', $rolId)->get(['modulo_accion_id', 'alcance']);

            $igualAPlantilla = $actuales->every(fn ($p) => $p->alcance === 'sede')
                && $actuales->pluck('modulo_accion_id')->map(fn ($id) => (int) $id)->sort()->values()->all() === $esperado;

            if ($igualAPlantilla) {
                DB::table('rol_permisos')->where('rol_id', $rolId)->whereIn('modulo_accion_id', $quitar)->delete();
            }
        }
    }

    public function down(): void
    {
        // Sin reversa: no se sabe qué Agentes se redujeron y cuáles ya estaban así.
        // Si hiciera falta, los permisos se devuelven desde la Matriz de permisos.
    }
};
