<?php

namespace Database\Seeders;

use App\Models\Accion;
use App\Models\Area;
use App\Models\Modulo;
use Illuminate\Database\Seeder;

/**
 * Catalogo base de areas, modulos, submodulos y acciones, tomado del sistema
 * actual (SEGCAT) y del Mapa de modulos. Es idempotente: se puede ejecutar
 * en cada despliegue. Lo que se agregue despues desde la interfaz no se toca.
 */
class CatalogoSeeder extends Seeder
{
    /** @var array<string, string> clave => nombre */
    public const ACCIONES = [
        'ver' => 'Ver',
        'crear' => 'Crear',
        'editar' => 'Editar',
        'eliminar' => 'Eliminar',
        'aprobar' => 'Aprobar',
        'firmar' => 'Firmar',
        'imprimir' => 'Imprimir',
        'exportar' => 'Exportar',
        'reabrir' => 'Reabrir',
        'configurar' => 'Configurar',
    ];

    private const CRUD = ['ver', 'crear', 'editar', 'eliminar'];

    /**
     * area => [nombre, icono, modulos[clave => [nombre, icono, acciones extra, submodulos]]]
     */
    public static function catalogo(): array
    {
        return [
            'organizacion' => ['Organización', 'bi-diagram-3', [
                'empresas' => ['Empresas', 'bi-building', []],
                'sedes' => ['Sedes', 'bi-geo-alt', []],
                'espacios' => ['Zonas y áreas', 'bi-grid-3x3-gap', ['imprimir']],
                'departamentos' => ['Departamentos', 'bi-diagram-2', []],
                'puestos' => ['Puestos', 'bi-person-badge', []],
                'turnos' => ['Turnos', 'bi-clock', []],
                'colaboradores' => ['Colaboradores', 'bi-people', ['exportar']],
            ]],
            'seguridad' => ['Seguridad', 'bi-shield-lock', [
                'accesos' => ['Bitácora de accesos', 'bi-door-open', ['aprobar', 'exportar']],
                'novedades' => ['Bitácora de novedades', 'bi-journal-text', ['reabrir', 'exportar', 'imprimir'], [
                    'lost_found' => ['Lost & Found', 'bi-box-seam', ['imprimir', 'firmar', 'configurar']],
                    'robo' => ['Robo — seguimiento', 'bi-exclamation-octagon', []],
                    'recorridos_pc' => ['Recorridos de Protección Civil', 'bi-fire', ['exportar', 'imprimir']],
                ]],
                'llaves' => ['Catálogo de llaves', 'bi-key', ['imprimir', 'exportar']],
                'prestamo_llaves' => ['Préstamo de llaves', 'bi-key-fill', ['exportar']],
                'gafetes' => ['Gafetes', 'bi-person-vcard', ['imprimir']],
                'vouchers' => ['Vouchers de reposición', 'bi-receipt', ['imprimir']],
                'equipos' => ['Equipos de seguridad', 'bi-tools', ['imprimir']],
                'responsivas' => ['Responsivas', 'bi-pen', ['firmar', 'imprimir']],
                'estacionamientos' => ['Estacionamientos', 'bi-p-square', []],
                'pases_salida' => ['Pases de salida', 'bi-box-arrow-right', ['aprobar', 'firmar', 'imprimir']],
                'visitantes' => ['Padrón de personas', 'bi-person-lines-fill', []],
                'vehiculos' => ['Padrón vehicular', 'bi-car-front', ['imprimir']],
                'proveedores' => ['Proveedores', 'bi-truck', []],
                'rutas' => ['Rutas de transporte', 'bi-signpost-split', ['imprimir']],
                'transporte' => ['Bitácora de transporte', 'bi-bus-front', ['aprobar', 'firmar', 'exportar']],
                'procedimientos' => ['Procedimientos', 'bi-book', ['aprobar']],
            ]],
            'reportes' => ['Reportes', 'bi-bar-chart', [
                'dashboard' => ['Tablero', 'bi-speedometer2', [], [], ['ver']],
                'informe_ejecutivo' => ['Informe ejecutivo', 'bi-file-earmark-bar-graph', ['exportar'], [], ['ver']],
                'tendencias' => ['Tendencias', 'bi-graph-up', ['exportar'], [], ['ver']],
                'bitacora_dia' => ['Bitácora general del día', 'bi-calendar-day', ['exportar', 'imprimir'], [], ['ver']],
            ]],
            'administracion' => ['Administración', 'bi-gear', [
                'usuarios' => ['Usuarios', 'bi-person-gear', []],
                'roles' => ['Roles', 'bi-person-rolodex', []],
                'permisos' => ['Matriz de permisos', 'bi-ui-checks-grid', [], [], ['ver', 'editar']],
                'configuracion' => ['Configuración', 'bi-sliders', ['configurar'], [], ['ver', 'editar']],
                'auditoria' => ['Bitácora de auditoría', 'bi-clipboard-data', ['exportar'], [], ['ver']],
            ]],
        ];
    }

    public function run(): void
    {
        $orden = 0;
        foreach (self::ACCIONES as $clave => $nombre) {
            Accion::updateOrCreate(['clave' => $clave], ['nombre' => $nombre, 'orden' => ++$orden]);
        }
        $acciones = Accion::pluck('id', 'clave');

        $ordenArea = 0;
        foreach (self::catalogo() as $claveArea => [$nombreArea, $iconoArea, $modulos]) {
            $area = Area::updateOrCreate(
                ['clave' => $claveArea],
                ['nombre' => $nombreArea, 'icono' => $iconoArea, 'orden' => ++$ordenArea],
            );

            $ordenModulo = 0;
            foreach ($modulos as $claveModulo => $definicion) {
                $modulo = $this->guardarModulo($area, null, $claveModulo, $definicion, ++$ordenModulo, $acciones->all());

                $ordenSub = 0;
                foreach ($definicion[3] ?? [] as $claveSub => $definicionSub) {
                    $this->guardarModulo($area, $modulo, $claveSub, $definicionSub, ++$ordenSub, $acciones->all());
                }
            }
        }
    }

    /**
     * @param  array<string, int>  $acciones
     */
    private function guardarModulo(Area $area, ?Modulo $padre, string $clave, array $definicion, int $orden, array $acciones): Modulo
    {
        $modulo = Modulo::updateOrCreate(['clave' => $clave], [
            'area_id' => $area->id,
            'padre_id' => $padre?->id,
            'nombre' => $definicion[0],
            'icono' => $definicion[1],
            'orden' => $orden,
            'tipo' => Modulo::TIPO_SISTEMA,
        ]);

        $base = $definicion[4] ?? self::CRUD;
        $claves = array_unique(array_merge($base, $definicion[2] ?? []));
        $modulo->acciones()->syncWithoutDetaching(
            array_map(fn (string $accion) => $acciones[$accion], $claves),
        );

        return $modulo;
    }
}
