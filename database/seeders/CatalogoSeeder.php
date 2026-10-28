<?php

namespace Database\Seeders;

use App\Models\Accion;
use App\Models\Area;
use App\Models\Modulo;
use App\Services\Borrado\RegistroBorrado;
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
        'eliminar' => 'Dar de baja', // Mejoras padrones: siempre fue la baja reversible (el borrado es «Eliminar definitivamente»)
        'aprobar' => 'Aprobar',
        'firmar' => 'Firmar',
        'imprimir' => 'Imprimir',
        'exportar' => 'Exportar',
        'reabrir' => 'Reabrir',
        'configurar' => 'Configurar',
        'desbloquear' => 'Desbloquear',
        'datos_personales' => 'Datos personales',
        'provisional' => 'Alta provisional',
        // Eliminar definitivamente (borrado físico controlado): solo catálogos y padrones
        'borrar' => 'Eliminar definitivamente',
        // Recepción de candidatos y autorizaciones departamentales (ADR-0007)
        'contratar' => 'Contratar',
        'responder' => 'Responder',
        // Candidatos, fase 2: entrevistar, evaluar y elegir (ADR-0009)
        'evaluar' => 'Evaluar',
        // Importar desde Excel (docs/tecnico/importacion.md): carga masiva desde archivo
        'importar' => 'Importar',
        // C-33 (D-14): pestaña de solo lectura «Accidentes» en la ficha del colaborador
        'accidentes' => 'Ver accidentes',
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
        'catalogo_comercial' => 'catalogo-comercial.index',
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
        'accesos' => 'accesos.index',
        'prestamo_llaves' => 'prestamo_llaves.index',
        'responsivas' => 'responsivas.index',
        'novedades' => 'novedades.index',
        'lost_found' => 'lost_found.archivo',
        'pases_salida' => 'pases-salida.index',
        'transporte' => 'transporte.index',
        'robo' => 'robo.index',
        'recorridos_pc' => 'recorridos_pc.index',
        'equipos_pc' => 'equipos_pc.index',
        'procedimientos' => 'procedimientos.index',
        'etiquetas_qr' => 'etiquetas.index',
        'recepcion_rh' => 'recepcion.index',
        'candidatos' => 'candidatos.index',
        'autorizaciones' => 'autorizaciones.index',
        'vacantes' => 'vacantes.index',
        'manual' => 'manual.index',
        // Informes (ADR-0017)
        'dashboard' => 'tablero.index',
        'bitacora_dia' => 'bitacora_dia.index',
        'tendencias' => 'tendencias.index',
        'informe_ejecutivo' => 'informe_ejecutivo.index',
        // Reservas de áreas (docs/tecnico/reservas.md)
        'reservas' => 'reservas.index',
    ];

    /**
     * area => [nombre, icono, modulos[clave => [nombre, icono, acciones extra, submodulos]]]
     */
    public static function catalogo(): array
    {
        return [
            // Dirección: la estructura de la empresa y su gobierno. Es la base que
            // toda empresa tiene, contrate o no los demás módulos (menú «Estructura»).
            'direccion' => ['Dirección', 'bi-building-gear', [
                // «exportar»: todos los datos de la empresa en un archivo (portabilidad; ver docs/tecnico/traslado.md)
                'empresas' => ['Empresas', 'bi-building', ['exportar']],
                'sedes' => ['Sedes', 'bi-geo-alt', []],
                // Mejoras estructura (ADR-0016): «área» queda solo para las partes de una habitación
                'espacios' => ['Zonas y edificios', 'bi-grid-3x3-gap', ['imprimir', 'importar']],
                'usuarios' => ['Usuarios', 'bi-person-gear', ['desbloquear']],
                'roles' => ['Roles', 'bi-person-rolodex', []],
                'permisos' => ['Matriz de permisos', 'bi-ui-checks-grid', [], [], ['ver', 'editar']],
                // «datos_personales»: Privacidad y solicitudes ARCO (conservación, versiones del aviso, bandeja; docs/tecnico/privacidad.md)
                'configuracion' => ['Configuración', 'bi-sliders', ['configurar', 'datos_personales'], [], ['ver', 'editar']],
                'auditoria' => ['Bitácora de auditoría', 'bi-clipboard-data', ['exportar'], [], ['ver']],
                // Organización base en todos los planes (plan de correcciones C-01, ADR-0004): el directorio
                // de colaboradores, sus catálogos y quién autoriza. "aprobar" = validar las altas provisionales
                // que hace la caseta; "provisional" = darlas de alta. El expediente y los datos personales son de RR. HH.
                'colaboradores' => ['Colaboradores', 'bi-people', ['exportar', 'datos_personales', 'aprobar', 'provisional', 'importar', 'accidentes']],
                'departamentos' => ['Departamentos', 'bi-diagram-2', []],
                'puestos' => ['Puestos', 'bi-person-badge', []],
                'turnos' => ['Turnos', 'bi-clock', []],
                'autorizaciones' => ['Autorizaciones departamentales', 'bi-patch-check', [], [], ['ver', 'responder', 'configurar']],
                'identidad' => ['Identidad de la plataforma', 'bi-palette', [], [], ['ver', 'editar'], Modulo::TIPO_PLATAFORMA],
                // Catálogo comercial: productos, planes, paquetes y la página pública de planes (solo Super Administrador)
                'catalogo_comercial' => ['Catálogo comercial', 'bi-shop', [], [], ['ver', 'editar'], Modulo::TIPO_PLATAFORMA],
                // Manual de usuario (lección 37): toda sesión lo abre sin permiso propio (cada página se filtra por
                // los módulos que el usuario puede ver); por eso no se contrata ni sale en la matriz de permisos
                'manual' => ['Manual', 'bi-question-circle', [], [], ['ver'], Modulo::TIPO_PLATAFORMA],
            ]],
            // Recursos Humanos: recepción de candidatos, vacantes, entrevistas y el
            // expediente del colaborador (datos personales: la acción
            // «colaboradores.datos_personales» se vende con Candidatos, ver
            // App\Support\DependenciasModulos::ACCIONES_CON_MODULO).
            'recursos_humanos' => ['Recursos Humanos', 'bi-people-fill', [
                // Recepción de candidatos y autorizaciones departamentales (ADR-0007)
                'recepcion_rh' => ['Recepción de RR. HH.', 'bi-person-check', [], [], ['ver']],
                // «evaluar» (fase 2): entrevistar, evaluar y elegir a los candidatos que RR. HH. le canaliza
                'candidatos' => ['Candidatos', 'bi-person-workspace', ['exportar', 'contratar', 'configurar', 'evaluar']],
                // Bolsa de trabajo (lección 36): «configurar» = bolsa pública por internet
                'vacantes' => ['Vacantes', 'bi-megaphone', ['configurar']],
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
                'equipos_pc' => ['Equipos de Protección Civil', 'bi-fire', ['imprimir']],
                'responsivas' => ['Responsivas', 'bi-pen', ['firmar', 'imprimir']],
                'estacionamientos' => ['Estacionamientos', 'bi-p-square', []],
                'pases_salida' => ['Pases de salida', 'bi-box-arrow-right', ['aprobar', 'firmar', 'imprimir', 'configurar']],
                'visitantes' => ['Padrón de personas', 'bi-person-lines-fill', ['importar']],
                'vehiculos' => ['Padrón vehicular', 'bi-car-front', ['imprimir', 'importar']],
                'proveedores' => ['Proveedores', 'bi-truck', ['importar']],
                'rutas' => ['Rutas de transporte', 'bi-signpost-split', ['imprimir']],
                'transporte' => ['Bitácora de transporte', 'bi-bus-front', ['aprobar', 'firmar', 'exportar']],
                'procedimientos' => ['Procedimientos', 'bi-book', ['aprobar']],
                // Decisión del dueño (mapa de procesos): «Imprimir» es permiso propio en los cuatro informes
                'dashboard' => ['Tablero', 'bi-speedometer2', ['imprimir'], [], ['ver']],
                'informe_ejecutivo' => ['Informe ejecutivo', 'bi-file-earmark-bar-graph', ['exportar', 'imprimir'], [], ['ver']],
                'tendencias' => ['Tendencias', 'bi-graph-up', ['exportar', 'imprimir'], [], ['ver']],
                'bitacora_dia' => ['Bitácora general del día', 'bi-calendar-day', ['exportar', 'imprimir'], [], ['ver']],
                // Ronda 6 (LL-06): impresión masiva de etiquetas QR; cada tipo pide además su propio permiso
                // Ronda 7: «configurar» = sub-pantalla Plantillas del gestor de impresión
                'etiquetas_qr' => ['Etiquetas QR', 'bi-qr-code', ['configurar'], [], ['ver']],
            ]],
            // Servicios: lo que la sede ofrece a su personal y a sus clientes (Reservas de áreas)
            'servicios' => ['Servicios', 'bi-calendar-check', [
                'reservas' => ['Reservas de áreas', 'bi-calendar-check', ['aprobar', 'configurar', 'exportar', 'imprimir'], [], ['ver', 'crear']],
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
        // Eliminar definitivamente: solo los módulos de RegistroBorrado (nunca bitácoras ni evidencias)
        if (in_array($clave, RegistroBorrado::modulos(), true)) {
            $claves[] = 'borrar';
        }
        $modulo->acciones()->syncWithoutDetaching(
            array_map(fn (string $accion) => $acciones[$accion], $claves),
        );

        return $modulo;
    }
}
