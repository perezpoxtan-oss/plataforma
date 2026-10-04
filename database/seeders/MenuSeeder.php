<?php

namespace Database\Seeders;

use App\Models\Menu;
use App\Models\Modulo;
use Illuminate\Database\Seeder;

/**
 * Menu principal tal como esta en SEGCAT (Estructura, Padrones, Operacion).
 * Solo crea lo que falta y solo acomoda modulos que aun no tienen menu:
 * lo que se cambie despues desde la interfaz se respeta.
 */
class MenuSeeder extends Seeder
{
    /**
     * menu => [nombre, icono, orden_movil, secciones[seccion => [modulo => color]]]
     */
    public static function menus(): array
    {
        return [
            'estructura' => ['Estructura', 'bi-building-gear', 3, [
                'Entidades Legales' => [
                    'empresas' => 'primary', 'sedes' => 'success', 'espacios' => 'danger',
                ],
                'Organización Interna' => [
                    'departamentos' => 'warning', 'puestos' => 'info', 'turnos' => 'secondary',
                    'usuarios' => 'danger', 'permisos' => 'warning',
                    'roles' => 'indigo', 'configuracion' => 'indigo', 'informe_ejecutivo' => 'indigo',
                    'auditoria' => 'indigo',
                ],
                'Plataforma' => [
                    'identidad' => 'primary',
                ],
            ]],
            'recursos_humanos' => ['Recursos Humanos', 'bi-people-fill', 4, [
                'Personal' => [
                    'colaboradores' => 'success',
                ],
            ]],
            'padrones' => ['Padrones', 'bi-folder2-open', 2, [
                'Identidad y Personas' => [
                    'proveedores' => 'success', 'visitantes' => 'primary', 'vehiculos' => 'secondary',
                ],
                'Inventarios de Seguridad' => [
                    'llaves' => 'primary', 'gafetes' => 'warning', 'vouchers' => 'danger',
                    'equipos' => 'dark', 'estacionamientos' => 'primary',
                ],
                'Logística' => [
                    'rutas' => 'info',
                ],
            ]],
            'operacion' => ['Operación', 'bi-shield-shaded', 1, [
                'Caseta y Control' => [
                    'novedades' => 'danger', 'lost_found' => 'warning', 'robo' => 'danger',
                    'pases_salida' => 'primary', 'recorridos_pc' => 'success', 'accesos' => 'primary',
                    'transporte' => 'warning', 'prestamo_llaves' => 'info',
                ],
                'Control de Activos' => [
                    'responsivas' => 'success',
                ],
                'Consulta' => [
                    'procedimientos' => 'primary',
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
                foreach ($modulos as $claveModulo => $color) {
                    $ordenModulo++;
                    Modulo::where('clave', $claveModulo)->whereNull('menu_id')->update([
                        'menu_id' => $menu->id,
                        'seccion_menu' => $seccion,
                        'orden_menu' => $ordenModulo,
                        'color_icono' => $color,
                    ]);
                }
            }
        }
    }
}
