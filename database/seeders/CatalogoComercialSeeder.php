<?php

namespace Database\Seeders;

use App\Models\Modulo;
use App\Models\Paquete;
use App\Models\Plan;
use App\Models\Producto;
use App\Services\Comercial\AjustesComerciales;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo comercial inicial: la plataforma por módulos, tres planes y siete
 * paquetes con PRECIOS DE EJEMPLO. Corre UNA sola vez (marca «sembrado_en»
 * en los ajustes comerciales): lo que el Super Administrador cambie o borre
 * después no se vuelve a crear en el siguiente despliegue. La página pública
 * nace SIN publicar, para revisar precios antes de mostrarlos.
 *
 * En una base que ya existe, la migración
 * 2026_11_22_000100_catalogo_comercial_coherente lleva el catálogo a esta
 * misma forma sin tocar precios ni borrar lo que el Super Administrador creó.
 *
 * Credenciales de empleado (QR, 125 kHz o MIFARE), el lector QR/NFC y los
 * equipos de acceso ZKTeco o Hikvision NO son módulos propios: vienen con
 * Colaboradores (Estructura) y con Accesos y Configuración (Caseta), en
 * todos los planes. Se cobran aparte solo los pagos únicos (kit de lector,
 * integración de cada equipo y tarjetas CR80).
 */
class CatalogoComercialSeeder extends Seeder
{
    /** PRECIO DE EJEMPLO del complemento «Reservas y eventos» (por sede al mes): el dueño lo revisa. */
    public const PRECIO_RESERVAS = 290;

    /** PRECIO DE EJEMPLO del complemento «Asistencia» (por sede al mes): el dueño lo revisa. */
    public const PRECIO_ASISTENCIA = 190;

    /** PRECIO DE EJEMPLO de «Integración de equipo de acceso» (pago único, por equipo): el dueño lo revisa. */
    public const PRECIO_INTEGRACION_EQUIPO = 1500;

    /** PRECIO DE EJEMPLO de «Credenciales CR80 (paquete de 100)» (pago único): el dueño lo revisa. */
    public const PRECIO_CREDENCIALES_CR80 = 1200;

    public const DESCRIPCION_PAQUETE_ASISTENCIA = 'Entradas, salidas, retardos y faltas del personal según su turno, con reporte por periodo para nómina externa.';

    /** clave => [nombre, descripción, ícono, módulos del catálogo] */
    public const PRODUCTOS = [
        // Organización base en todos los planes (C-01): directorio de colaboradores (con su credencial), departamentos, puestos, turnos y quién autoriza
        'estructura' => ['Estructura y usuarios', 'Empresas, sedes, zonas, usuarios, roles y permisos, directorio de colaboradores con su credencial de empleado (QR o tarjeta 125 kHz o MIFARE), departamentos, puestos, turnos, quién autoriza y bitácora de auditoría.', 'bi-building-gear',
            ['empresas', 'sedes', 'espacios', 'usuarios', 'roles', 'permisos', 'configuracion', 'auditoria', 'colaboradores', 'departamentos', 'puestos', 'turnos', 'autorizaciones']],
        // Equipos de acceso (ZKTeco, Hikvision) y lector QR/NFC van con Accesos y Configuración
        'caseta' => ['Caseta y accesos', 'Bitácora de accesos con autorización del departamento, lector de QR y tarjetas (NFC) y equipos de acceso ZKTeco o Hikvision, llaves, vouchers, pases de salida y transporte.', 'bi-door-open',
            ['accesos', 'prestamo_llaves', 'vouchers', 'pases_salida', 'transporte']],
        'padrones' => ['Padrones', 'Personas, vehículos, empresas externas, llaves, gafetes, equipos de seguridad, responsivas, estacionamientos, rutas y etiquetas QR.', 'bi-folder2-open',
            ['proveedores', 'visitantes', 'vehiculos', 'llaves', 'gafetes', 'equipos', 'estacionamientos', 'rutas', 'etiquetas_qr', 'responsivas']],
        // Equipos de Protección Civil va con sus recorridos (C-05, DD-03)
        'incidentes' => ['Incidentes y novedades', 'Bitácora de novedades, objetos olvidados, robos, equipos y recorridos de Protección Civil y procedimientos.', 'bi-journal-text',
            ['novedades', 'lost_found', 'robo', 'equipos_pc', 'recorridos_pc', 'procedimientos']],
        'recursos_humanos' => ['Recursos Humanos y candidatos', 'Recepción de candidatos, vacantes y bolsa de trabajo, entrevistas y expediente del colaborador.', 'bi-people',
            ['recepcion_rh', 'candidatos', 'vacantes']],
        'informes' => ['Informes', 'Tablero, bitácora del día, tendencias e informe ejecutivo por sede.', 'bi-graph-up',
            ['dashboard', 'bitacora_dia', 'tendencias', 'informe_ejecutivo']],
        // Complemento «Reservas y eventos» (C-05, DD-03): se suma a cualquier plan
        'reservas' => ['Reservas y eventos', 'Reservas de salones, salas y áreas comunes, con aprobación, órdenes de servicio y montaje por departamento.', 'bi-calendar-check',
            ['reservas']],
        // Asistencia (decisión 13, ADR-0024): incluida en Corporativo y complemento de Esencial y Profesional; apagada de fábrica
        'asistencia' => ['Asistencia', 'Asistencia del día y reporte por periodo para nómina: entradas, salidas, retardos, faltas y horas trabajadas de los colaboradores según su turno.', 'bi-calendar2-check',
            ['asistencia']],
    ];

    /** Productos de cada plan (C-05): cada plan opera solo y cumple la lista de dependencias. */
    public const PLANES = [
        'esencial' => ['estructura', 'caseta', 'padrones'],
        'profesional' => ['estructura', 'caseta', 'padrones', 'incidentes', 'informes'],
        // Asistencia incluida, pero la empresa la enciende cuando quiera usarla (Modulo::APAGADOS_DE_FABRICA)
        'corporativo' => ['estructura', 'caseta', 'padrones', 'incidentes', 'informes', 'recursos_humanos', 'asistencia'],
    ];

    /** Textos de cada plan: slug => [frase, descripción]. */
    public const TEXTOS_PLANES = [
        'esencial' => ['La caseta en orden desde el primer día, con credenciales QR y lector.',
            'Bitácora de accesos, llaves, pases de salida y padrones, con credenciales de empleado con QR o tarjeta 125 kHz o MIFARE, lector QR/NFC y equipos de acceso ZKTeco o Hikvision incluidos.'],
        'profesional' => ['Seguridad completa con incidentes e informes.',
            'Todo lo de Esencial (credenciales, lector y equipos de acceso incluidos), más la bitácora de incidentes, objetos olvidados, recorridos y los informes para la dirección.'],
        'corporativo' => ['Para grupos con varias sedes, Recursos Humanos y asistencia.',
            'Todo lo de Profesional, más Recursos Humanos y Asistencia del personal (la enciendes cuando la quieras usar), usuarios ilimitados y acompañamiento en la puesta en marcha de cada sede.'],
    ];

    /** Paquetes de suscripción: slug => [productos, planes a los que se suma]. */
    public const PAQUETES = [
        'recursos-humanos' => [['recursos_humanos'], ['esencial', 'profesional']],
        'reservas-y-eventos' => [['reservas'], ['esencial', 'profesional', 'corporativo']],
        // Corporativo ya la incluye
        'asistencia' => [['asistencia'], ['esencial', 'profesional']],
    ];

    /** Pagos únicos: slug => [nombre, descripción, precio, por sede]. Se suman a cualquier plan. */
    public const PAQUETES_UNICOS = [
        'puesta-en-marcha' => ['Puesta en marcha y capacitación',
            'Carga inicial de padrones, configuración de la sede y capacitación del personal de caseta.', 4500, true],
        'kit-lector' => ['Kit de lector para caseta',
            'Lector para la computadora de la caseta que lee códigos QR y tarjetas 125 kHz o MIFARE: credenciales de empleado, gafetes y pases.', 2800, false],
        'integracion-equipo-acceso' => ['Integración de equipo de acceso',
            'Configuración de un equipo ZKTeco o Hikvision (torniquete, puerta o checador) con la plataforma: alta del equipo, prueba de entradas y salidas y envío de colaboradores. Precio por equipo; si son varios, indícalo en tus comentarios.', self::PRECIO_INTEGRACION_EQUIPO, false],
        'credenciales-cr80' => ['Credenciales CR80 (paquete de 100)',
            'Cien tarjetas CR80 para imprimir credenciales de empleado. Pueden ser con chip 125 kHz o MIFARE para leerse en el lector de la caseta y en los equipos de acceso.', self::PRECIO_CREDENCIALES_CR80, false],
    ];

    public function run(): void
    {
        $ajustes = app(AjustesComerciales::class);
        if (! DB::getSchemaBuilder()->hasTable('productos') || $ajustes->get('sembrado_en') !== null
            || Producto::exists() || Plan::exists() || Paquete::exists()) {
            return;
        }

        DB::transaction(function () use ($ajustes) {
            $modulos = Modulo::pluck('id', 'clave');
            $productos = [];
            $orden = 0;
            foreach (self::PRODUCTOS as $clave => [$nombre, $descripcion, $icono, $claves]) {
                $producto = Producto::create(['clave' => $clave, 'nombre' => $nombre, 'descripcion' => $descripcion, 'icono' => $icono, 'orden' => ++$orden]);
                $producto->modulos()->sync(collect($claves)->map(fn ($c) => $modulos[$c] ?? null)->filter()->values());
                $productos[$clave] = $producto->id;
            }
            $ids = fn (array $claves) => array_map(fn ($c) => $productos[$c], $claves);

            // PRECIOS DE EJEMPLO: el dueño los revisa antes de publicar la página
            $datosPlanes = [
                'esencial' => ['nombre' => 'Esencial', 'precio_por_sede_mensual' => 890, 'sedes_minimas' => 1, 'usuarios_por_sede' => 5, 'orden' => 1],
                'profesional' => ['nombre' => 'Profesional', 'precio_por_sede_mensual' => 1490, 'sedes_minimas' => 1, 'usuarios_por_sede' => 15, 'destacado' => true, 'orden' => 2],
                'corporativo' => ['nombre' => 'Corporativo', 'precio_por_sede_mensual' => 2190, 'sedes_minimas' => 5, 'usuarios_por_sede' => null, 'cotizar' => true, 'orden' => 3],
            ];
            $planes = [];
            foreach ($datosPlanes as $slug => $datos) {
                [$frase, $descripcion] = self::TEXTOS_PLANES[$slug];
                $plan = Plan::create($datos + ['slug' => $slug, 'frase' => $frase, 'descripcion' => $descripcion, 'publicado' => true]);
                $plan->productos()->sync($ids(self::PLANES[$slug]));
                $planes[$slug] = $plan->id;
            }

            $suscripcion = [
                'recursos-humanos' => ['Recursos Humanos y candidatos', 'Recepción de candidatos, vacantes en internet, entrevistas y expediente del colaborador.', 390],
                'reservas-y-eventos' => ['Reservas y eventos', 'Reservas de salones, salas y áreas comunes, con aprobación, órdenes de servicio y montaje.', self::PRECIO_RESERVAS],
                'asistencia' => ['Asistencia', self::DESCRIPCION_PAQUETE_ASISTENCIA, self::PRECIO_ASISTENCIA],
            ];
            $orden = 0;
            foreach ($suscripcion as $slug => [$nombre, $descripcion, $precio]) {
                $paquete = Paquete::create(['nombre' => $nombre, 'slug' => $slug, 'modalidad' => Paquete::SUSCRIPCION, 'descripcion' => $descripcion,
                    'precio' => $precio, 'por_sede' => true, 'publicado' => true, 'orden' => ++$orden]);
                [$suyos, $conPlanes] = self::PAQUETES[$slug];
                $paquete->productos()->sync($ids($suyos));
                $paquete->planes()->sync(array_map(fn ($p) => $planes[$p], $conPlanes));
            }
            foreach (self::PAQUETES_UNICOS as $slug => [$nombre, $descripcion, $precio, $porSede]) {
                Paquete::create(['nombre' => $nombre, 'slug' => $slug, 'modalidad' => Paquete::UNICO, 'descripcion' => $descripcion,
                    'precio' => $precio, 'por_sede' => $porSede, 'publicado' => true, 'orden' => ++$orden]);
            }

            $ajustes->guardar(['sembrado_en' => now()->toIso8601String(), 'landing_publicada' => false]);
        });
    }
}
