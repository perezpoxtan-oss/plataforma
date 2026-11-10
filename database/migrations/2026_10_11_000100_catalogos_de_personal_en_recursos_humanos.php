<?php

use App\Services\Permisos\Alcance;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Departamentos, Puestos y Turnos pasan de Dirección a Recursos Humanos
 * (área de la Matriz de permisos y menú), a petición del responsable del
 * proyecto. Los permisos que ya tenía cada rol no cambian; el rol
 * Recursos Humanos (plantilla y copias de cada empresa) recibe todas las
 * acciones de esos tres catálogos con alcance de empresa.
 */
return new class extends Migration
{
    private const MODULOS = ['departamentos' => 1, 'puestos' => 2, 'turnos' => 3];

    public function up(): void
    {
        $rh = DB::table('areas')->where('clave', 'recursos_humanos')->value('id');
        $menu = DB::table('menus')->where('clave', 'recursos_humanos')->value('id');
        if ($rh === null) {
            return; // instalación nueva: el catálogo ya los crea en Recursos Humanos
        }
        $ahora = now();

        foreach (self::MODULOS as $clave => $orden) {
            DB::table('modulos')->where('clave', $clave)->update(array_filter([
                'area_id' => $rh,
                'menu_id' => $menu,
                'seccion_menu' => $menu ? 'Catálogos del personal' : null,
                'orden_menu' => $menu ? 10 + $orden : null,
                'updated_at' => $ahora,
            ], fn ($v) => $v !== null));
        }

        $permisos = DB::table('modulo_acciones as ma')->join('modulos as m', 'm.id', '=', 'ma.modulo_id')
            ->whereIn('m.clave', array_keys(self::MODULOS))->pluck('ma.id');
        foreach (DB::table('roles')->where('nombre', 'Recursos Humanos')->pluck('id') as $rolId) {
            foreach ($permisos as $permisoId) {
                $existe = DB::table('rol_permisos')->where('rol_id', $rolId)->where('modulo_accion_id', $permisoId);
                if ($existe->exists()) {
                    $existe->update(['alcance' => Alcance::Empresa->value, 'updated_at' => $ahora]);
                } else {
                    DB::table('rol_permisos')->insert([
                        'rol_id' => $rolId, 'modulo_accion_id' => $permisoId, 'alcance' => Alcance::Empresa->value,
                        'created_at' => $ahora, 'updated_at' => $ahora,
                    ]);
                }
            }
        }
    }

    public function down(): void
    {
        $direccion = DB::table('areas')->where('clave', 'direccion')->value('id');
        $menu = DB::table('menus')->where('clave', 'estructura')->value('id');
        if ($direccion === null) {
            return;
        }
        foreach (array_keys(self::MODULOS) as $clave) {
            DB::table('modulos')->where('clave', $clave)->update(array_filter([
                'area_id' => $direccion, 'menu_id' => $menu, 'seccion_menu' => $menu ? 'Organización Interna' : null,
            ], fn ($v) => $v !== null));
        }
    }
};
