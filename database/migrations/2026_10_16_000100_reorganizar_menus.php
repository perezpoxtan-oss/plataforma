<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reorganización de los menús aprobada por el dueño del proyecto:
 * Operación · Padrones · Recursos Humanos · Informes · Estructura (el mismo
 * orden en PC y en celular). Agrega `modulos.nombre_menu` (nombre corto que
 * se ve en el menú, igual al de la pantalla) y el menú nuevo «Informes».
 *
 * En una instalación nueva no hace nada con los datos (el MenuSeeder ya
 * acomoda todo así); en QA/Producción reacomoda los módulos existentes.
 * Se puede correr más de una vez sin efectos dobles.
 */
return new class extends Migration
{
    /** menu => [nombre, icono, orden (PC y celular)] */
    private const MENUS = [
        'operacion' => ['Operación', 'bi-shield-shaded', 1],
        'padrones' => ['Padrones', 'bi-folder2-open', 2],
        'recursos_humanos' => ['Recursos Humanos', 'bi-people-fill', 3],
        'informes' => ['Informes', 'bi-graph-up', 4],
        'estructura' => ['Estructura', 'bi-building-gear', 5],
    ];

    /** menu => [sección => [módulo => [color, nombre en el menú]]] (copia fija del MenuSeeder de esta versión) */
    private const ACOMODO = [
        'operacion' => [
            'Caseta' => ['accesos' => ['primary', null], 'prestamo_llaves' => ['info', null], 'pases_salida' => ['primary', null], 'transporte' => ['warning', null]],
            'Incidentes' => ['novedades' => ['danger', 'Novedades'], 'lost_found' => ['warning', null], 'robo' => ['danger', 'Robo']],
            'Protección civil' => ['recorridos_pc' => ['success', null]],
            'Activos' => ['responsivas' => ['success', null], 'vouchers' => ['danger', null]],
            'Consulta' => ['procedimientos' => ['primary', null]],
        ],
        'padrones' => [
            'Personas y vehículos' => ['proveedores' => ['success', 'Empresas externas'], 'visitantes' => ['primary', null], 'vehiculos' => ['secondary', null]],
            'Inventarios' => ['llaves' => ['primary', null], 'gafetes' => ['warning', null], 'equipos' => ['dark', null], 'equipos_pc' => ['danger', null]],
            'Instalaciones' => ['estacionamientos' => ['primary', null], 'rutas' => ['info', null]],
            'Herramientas' => ['etiquetas_qr' => ['dark', null]],
        ],
        'recursos_humanos' => [
            'Personal' => ['colaboradores' => ['success', null]],
            'Recepción y candidatos' => ['recepcion_rh' => ['primary', null], 'candidatos' => ['success', null]],
            'Catálogos' => ['departamentos' => ['warning', null], 'puestos' => ['info', null], 'turnos' => ['secondary', null]],
        ],
        'informes' => [
            'Informes' => ['dashboard' => ['primary', null], 'bitacora_dia' => ['info', 'Bitácora del día'], 'tendencias' => ['success', null], 'informe_ejecutivo' => ['indigo', null]],
        ],
        'estructura' => [
            'Empresa' => ['empresas' => ['primary', null], 'sedes' => ['success', null], 'espacios' => ['danger', null]],
            'Accesos y permisos' => ['usuarios' => ['danger', null], 'roles' => ['indigo', null], 'permisos' => ['warning', null]],
            'Sistema' => ['configuracion' => ['indigo', null], 'identidad' => ['primary', null], 'auditoria' => ['indigo', null]],
        ],
    ];

    /** Módulos que salen de los menús (conservan su ruta y su permiso). */
    private const FUERA_DE_MENU = ['autorizaciones'];

    public function up(): void
    {
        if (! Schema::hasColumn('modulos', 'nombre_menu')) {
            Schema::table('modulos', function (Blueprint $table) {
                // Nombre corto en el menú (si falta, se usa el nombre del módulo)
                $table->string('nombre_menu', 80)->nullable()->after('seccion_menu');
            });
        }

        if (! DB::table('menus')->exists()) {
            return; // instalación nueva: el MenuSeeder arma los menús
        }

        $ahora = now();
        foreach (self::MENUS as $clave => [$nombre, $icono, $orden]) {
            $datos = ['nombre' => $nombre, 'icono' => $icono, 'orden' => $orden, 'orden_movil' => $orden, 'updated_at' => $ahora];
            if (DB::table('menus')->where('clave', $clave)->exists()) {
                DB::table('menus')->where('clave', $clave)->update($datos);
            } else {
                DB::table('menus')->insert([...$datos, 'clave' => $clave, 'activo' => true, 'created_at' => $ahora]);
            }
        }
        $menus = DB::table('menus')->pluck('id', 'clave');

        foreach (self::ACOMODO as $menu => $secciones) {
            $orden = 0;
            foreach ($secciones as $seccion => $modulos) {
                foreach ($modulos as $modulo => [$color, $nombreMenu]) {
                    DB::table('modulos')->where('clave', $modulo)->update([
                        'menu_id' => $menus[$menu],
                        'seccion_menu' => $seccion,
                        'orden_menu' => ++$orden,
                        'color_icono' => $color,
                        'nombre_menu' => $nombreMenu,
                        'updated_at' => $ahora,
                    ]);
                }
            }
        }

        DB::table('modulos')->whereIn('clave', self::FUERA_DE_MENU)
            ->update(['menu_id' => null, 'seccion_menu' => null, 'orden_menu' => 0, 'updated_at' => $ahora]);
    }

    public function down(): void
    {
        // Los módulos de «Informes» quedan sin menú (la llave foránea los suelta)
        DB::table('menus')->where('clave', 'informes')->delete();

        if (Schema::hasColumn('modulos', 'nombre_menu')) {
            Schema::table('modulos', function (Blueprint $table) {
                $table->dropColumn('nombre_menu');
            });
        }
    }
};
