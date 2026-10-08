<?php

namespace Database\Seeders;

use App\Models\Menu;
use App\Models\Modulo;
use Illuminate\Database\Seeder;

/**
 * Menu principal: Operación, Padrones, Recursos Humanos, Informes y Estructura.
 * Solo crea lo que falta y solo acomoda modulos que aun no tienen menu:
 * lo que se cambie despues desde la interfaz se respeta.
 */
class MenuSeeder extends Seeder
{
    /**
     * menu => [nombre, icono, orden_movil, secciones[seccion => [modulo => color | [color, nombre en el menú]]]]
     *
     * Orden aprobado (PC y celular): Operación, Padrones, Recursos Humanos,
     * Informes y Estructura. Autorizaciones departamentales no va en ningún
     * menú (se llega desde «Mis pendientes» y desde la campana).
     */
    public static function menus(): array
    {
        return [
            'operacion' => ['Operación', 'bi-shield-shaded', 1, [
                'Caseta' => [
                    'accesos' => 'primary', 'prestamo_llaves' => 'info', 'pases_salida' => 'primary', 'transporte' => 'warning',
                ],
                'Incidentes' => [
                    'novedades' => ['danger', 'Novedades'], 'lost_found' => 'warning', 'robo' => ['danger', 'Robo'],
                ],
                'Protección civil' => [
                    'recorridos_pc' => 'success',
                ],
                'Activos' => [
                    'responsivas' => 'success', 'vouchers' => 'danger',
                ],
                'Consulta' => [
                    'procedimientos' => 'primary',
                ],
            ]],
            'padrones' => ['Padrones', 'bi-folder2-open', 2, [
                'Personas y vehículos' => [
                    'proveedores' => ['success', 'Empresas externas'], 'visitantes' => 'primary', 'vehiculos' => 'secondary',
                ],
                'Inventarios' => [
                    'llaves' => 'primary', 'gafetes' => 'warning', 'equipos' => 'dark', 'equipos_pc' => 'danger',
                ],
                'Instalaciones' => [
                    'estacionamientos' => 'primary', 'rutas' => 'info',
                ],
                'Herramientas' => [
                    'etiquetas_qr' => 'dark',
                ],
            ]],
            'recursos_humanos' => ['Recursos Humanos', 'bi-people-fill', 3, [
                'Personal' => [
                    'colaboradores' => 'success',
                ],
                'Recepción y candidatos' => [
                    'recepcion_rh' => 'primary', 'candidatos' => 'success',
                ],
                'Catálogos' => [
                    'departamentos' => 'warning', 'puestos' => 'info', 'turnos' => 'secondary',
                ],
            ]],
            // Sin pantallas todavía: el menú se oculta solo mientras ningún módulo tenga ruta
            'informes' => ['Informes', 'bi-graph-up', 4, [
                'Informes' => [
                    'dashboard' => 'primary', 'bitacora_dia' => ['info', 'Bitácora del día'], 'tendencias' => 'success', 'informe_ejecutivo' => 'indigo',
                ],
            ]],
            'estructura' => ['Estructura', 'bi-building-gear', 5, [
                'Empresa' => [
                    'empresas' => 'primary', 'sedes' => 'success', 'espacios' => 'danger',
                ],
                'Accesos y permisos' => [
                    'usuarios' => 'danger', 'roles' => 'indigo', 'permisos' => 'warning',
                ],
                'Sistema' => [
                    'configuracion' => 'indigo', 'identidad' => 'primary', 'auditoria' => 'indigo',
                ],
            ]],
        ];
    }

    public function run(): void
    {
        $orden = 0;
        foreach (self::menus() as $clave => [$nombre, $icono, $ordenMovil, $secciones]) {
            $menu = Menu::firstOrCreate(
                ['clave' => $clave],
                ['nombre' => $nombre, 'icono' => $icono, 'orden' => ++$orden, 'orden_movil' => $ordenMovil],
            );

            $ordenModulo = 0;
            foreach ($secciones as $seccion => $modulos) {
                foreach ($modulos as $claveModulo => $definicion) {
                    [$color, $nombreMenu] = is_array($definicion) ? $definicion : [$definicion, null];
                    $ordenModulo++;
                    Modulo::where('clave', $claveModulo)->whereNull('menu_id')->update([
                        'menu_id' => $menu->id,
                        'seccion_menu' => $seccion,
                        'orden_menu' => $ordenModulo,
                        'color_icono' => $color,
                        'nombre_menu' => $nombreMenu,
                    ]);
                }
            }
        }
    }
}
