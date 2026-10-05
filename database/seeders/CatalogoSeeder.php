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
        'desbloquear' => 'Desbloquear',
        'datos_personales' => 'Datos personales',
        'provisional' => 'Alta provisional',
    ];

    private const CRUD = ['ver', 'crear', 'editar', 'eliminar'];

    /**
     * Pantalla (nombre de ruta) de cada modulo ya migrado. El menu enlaza aqui;
     * los modulos sin ruta muestran el aviso "en migracion".
     */
    public const RUTAS = [
        'empresas' => 'empresas.index',
        'sedes' => 'sedes.index',
        'espacios' => 'espacios.index',
        'departamentos' => 'departamentos.index',
        'puestos' => 'puestos.index',
        'turnos' => 'turnos.index',
        'colaboradores' => 'colaboradores.index',
        'roles' => 'roles.index',
        'permisos' => 'permisos.index',
        'usuarios' => 'usuarios.index',
        'identidad' => 'identidad.edit',
        'auditoria' => 'auditoria.index',
        'configuracion' => 'configuracion.index',
        'proveedores' => 'proveedores.index',
        'visitantes' => 'personas.index',
        'vehiculos' => 'vehiculos.index',
        'llaves' => 'llaves.index',
        'gafetes' => 'gafetes.index',
        'vouchers' => 'vouchers.index',
        'equipos' => 'equipos.index',
        'estacionamientos' => 'estacionamientos.index',
        'rutas' => 'rutas.index',
        'transporte' => 'transporte.index',
    ];

    /**
     * area => [nombre, icono, modulos[clave => [nombre, icono, acciones extra, submodulos]]]
     */
    public static function catalogo(): array
    {
        return [
            // Dirección: la estructura de la empresa y su gobierno. Es la base que
            // toda empresa tiene, contrate o no los demás módulos.
            'direccion' => ['Dirección', 'bi-building-gear', [
                'empresas' => ['Empresas', 'bi-building', []],
                'sedes' => ['Sedes', 'bi-geo-alt', []],
                'espacios' => ['Zonas y áreas', 'bi-grid-3x3-gap', ['imprimir']],
                'departamentos' => ['Departamentos', 'bi-diagram-2', []],
                'puestos' => ['Puestos', 'bi-person-badge', []],
                'turnos' => ['Turnos', 'bi-clock', []],
                'usuarios' => ['Usuarios', 'bi-person-gear', ['desbloquear']],
                'roles' => ['Roles', 'bi-person-rolodex', []],
                'permisos' => ['Matriz de permisos', 'bi-ui-checks-grid', [], [], ['ver', 'editar']],
                'configuracion' => ['Configuración', 'bi-sliders', ['configurar'], [], ['ver', 'editar']],
                'auditoria' => ['Bitácora de auditoría', 'bi-clipboard-data', ['exportar'], [], ['ver']],
                'identidad' => ['Identidad de la plataforma', 'bi-palette', [], [], ['ver', 'editar'], Modulo::TIPO_PLATAFORMA],
            ]],
            // Recursos Humanos: el personal. "aprobar" = validar las altas
            // provisionales que hace la caseta; "provisional" = darlas de alta.
            'recursos_humanos' => ['Recursos Humanos', 'bi-people-fill', [
                'colaboradores' => ['Colaboradores', 'bi-people', ['exportar', 'datos_personales', 'aprobar', 'provisional']],
            ]],
            // Seguridad: padrones, operación y sus reportes.
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
                'dashboard' => ['Tablero', 'bi-speedometer2', [], [], ['ver']],
                'informe_ejecutivo' => ['Informe ejecutivo', 'bi-file-earmark-bar-graph', ['exportar'], [], ['ver']],
                'tendencias' => ['Tendencias', 'bi-graph-up', ['exportar'], [], ['ver']],
                'bitacora_dia' => ['Bitácora general del día', 'bi-calendar-day', ['exportar', 'imprimir'], [], ['ver']],
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
            'tipo' => $definicion[5] ?? Modulo::TIPO_SISTEMA,
            'ruta' => self::RUTAS[$clave] ?? null,
        ]);

        $base = $definicion[4] ?? self::CRUD;
        $claves = array_unique(array_merge($base, $definicion[2] ?? []));
        $modulo->acciones()->syncWithoutDetaching(
            array_map(fn (string $accion) => $acciones[$accion], $claves),
        );

        return $modulo;
    }
}
