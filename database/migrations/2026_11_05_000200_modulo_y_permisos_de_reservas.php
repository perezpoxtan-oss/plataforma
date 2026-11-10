<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reservas de áreas: módulo del catálogo, menú y permisos en una base que ya
 * existe (en una instalación nueva no hace nada: el catálogo, el menú y las
 * plantillas los crean los seeders con las mismas reglas).
 *
 *  - Área del catálogo «Servicios» (nueva) con el módulo `reservas` y sus
 *    acciones ver, crear, aprobar, configurar, exportar e imprimir.
 *  - Menú Operación → sección «Reservas» (al final del menú).
 *  - Se activa en todas las empresas que ya existen (empresa_modulos).
 *  - Permisos por nombre de rol (plantillas y sus copias en cada empresa),
 *    igual que RolesPlantillaSeeder::RESERVAS: Administrador todo (empresa);
 *    Director ver (empresa); Jefe de departamento ver, crear, imprimir y
 *    aprobar (sede); Solicitante ver, crear e imprimir las suyas (propios);
 *    los demás roles de sistema ver, crear e imprimir (sede). Los roles
 *    propios de cada empresa no reciben nada: se dan en la Matriz de permisos.
 *
 * down(): quita los permisos, el módulo (con sus acciones y su activación por
 * empresa) y el área si quedó vacía. Solo borra filas: no toca índices ni
 * llaves foráneas (seguro en MariaDB).
 */
return new class extends Migration
{
    private const ACCIONES = ['ver', 'crear', 'aprobar', 'configurar', 'exportar', 'imprimir'];

    /** rol => [acciones, alcance] (mismo contenido que RolesPlantillaSeeder::RESERVAS) */
    private const PERMISOS = [
        'Administrador' => [['ver', 'crear', 'aprobar', 'configurar', 'exportar', 'imprimir'], 'empresa'],
        'Director' => [['ver'], 'empresa'],
        'Recursos Humanos' => [['ver', 'crear', 'imprimir'], 'sede'],
        'Jefe de seguridad' => [['ver', 'crear', 'imprimir'], 'sede'],
        'Asistente de seguridad' => [['ver', 'crear', 'imprimir'], 'sede'],
        'Supervisor' => [['ver', 'crear', 'imprimir'], 'sede'],
        'Agente' => [['ver', 'crear', 'imprimir'], 'sede'],
        'Jefe de departamento' => [['ver', 'crear', 'imprimir', 'aprobar'], 'sede'],
        'Solicitante' => [['ver', 'crear', 'imprimir'], 'propios'],
    ];

    public function up(): void
    {
        $acciones = DB::table('acciones')->pluck('id', 'clave');
        if ($acciones->isEmpty() || DB::table('modulos')->where('clave', 'reservas')->exists()) {
            return; // instalación nueva (lo crean los seeders) o ya aplicada
        }
        $ahora = now();

        $area = DB::table('areas')->where('clave', 'servicios')->value('id')
            ?? DB::table('areas')->insertGetId(['clave' => 'servicios', 'nombre' => 'Servicios', 'icono' => 'bi-calendar-check',
                'orden' => (int) DB::table('areas')->max('orden') + 1, 'activo' => true, 'created_at' => $ahora, 'updated_at' => $ahora]);

        $menu = DB::table('menus')->where('clave', 'operacion')->value('id');
        $modulo = DB::table('modulos')->insertGetId([
            'area_id' => $area, 'padre_id' => null, 'clave' => 'reservas', 'nombre' => 'Reservas de áreas', 'icono' => 'bi-calendar-check',
            'ruta' => 'reservas.index', 'orden' => 1, 'tipo' => 'sistema', 'activo' => true,
            'menu_id' => $menu, 'seccion_menu' => $menu ? 'Reservas' : null,
            'orden_menu' => $menu ? (int) DB::table('modulos')->where('menu_id', $menu)->max('orden_menu') + 1 : 0,
            'color_icono' => 'success', 'created_at' => $ahora, 'updated_at' => $ahora,
        ]);

        $ma = [];
        foreach (self::ACCIONES as $accion) {
            if (! isset($acciones[$accion])) {
                continue;
            }
            DB::table('modulo_acciones')->insertOrIgnore(['modulo_id' => $modulo, 'accion_id' => $acciones[$accion], 'created_at' => $ahora, 'updated_at' => $ahora]);
            $ma[$accion] = DB::table('modulo_acciones')->where('modulo_id', $modulo)->where('accion_id', $acciones[$accion])->value('id');
        }

        foreach (DB::table('empresas')->pluck('id') as $empresaId) {
            DB::table('empresa_modulos')->insertOrIgnore(['empresa_id' => $empresaId, 'modulo_id' => $modulo, 'activo' => true,
                'created_at' => $ahora, 'updated_at' => $ahora]);
        }

        foreach (self::PERMISOS as $rol => [$permitidas, $alcance]) {
            foreach (DB::table('roles')->where('nombre', $rol)->pluck('id') as $rolId) {
                foreach ($permitidas as $accion) {
                    if (! isset($ma[$accion])) {
                        continue;
                    }
                    DB::table('rol_permisos')->insertOrIgnore(['rol_id' => $rolId, 'modulo_accion_id' => $ma[$accion], 'alcance' => $alcance,
                        'created_at' => $ahora, 'updated_at' => $ahora]);
                }
            }
        }
    }

    public function down(): void
    {
        $modulo = DB::table('modulos')->where('clave', 'reservas')->value('id');
        if ($modulo !== null) {
            // Explícito además de la cascada (SQLite sin llaves foráneas activas)
            DB::table('rol_permisos')->whereIn('modulo_accion_id', DB::table('modulo_acciones')->where('modulo_id', $modulo)->select('id'))->delete();
            DB::table('modulo_acciones')->where('modulo_id', $modulo)->delete();
            DB::table('empresa_modulos')->where('modulo_id', $modulo)->delete();
            DB::table('modulos')->where('id', $modulo)->delete();
        }
        $area = DB::table('areas')->where('clave', 'servicios')->value('id');
        if ($area !== null && ! DB::table('modulos')->where('area_id', $area)->exists()) {
            DB::table('areas')->where('id', $area)->delete();
        }
    }
};
