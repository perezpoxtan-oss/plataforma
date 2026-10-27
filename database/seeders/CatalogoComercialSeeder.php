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
 * Catálogo comercial inicial: la plataforma por módulos, tres planes y cuatro
 * paquetes con PRECIOS DE EJEMPLO. Corre UNA sola vez (marca «sembrado_en»
 * en los ajustes comerciales): lo que el Super Administrador cambie o borre
 * después no se vuelve a crear en el siguiente despliegue. La página pública
 * nace SIN publicar, para revisar precios antes de mostrarlos.
 */
class CatalogoComercialSeeder extends Seeder
{
    /** PRECIO DE EJEMPLO del complemento «Reservas y eventos» (por sede al mes): el dueño lo revisa. */
    public const PRECIO_RESERVAS = 290;

    /** clave => [nombre, descripción, ícono, módulos del catálogo] */
    public const PRODUCTOS = [
        // Organización base en todos los planes (C-01): directorio de colaboradores, departamentos, puestos, turnos y quién autoriza
        'estructura' => ['Estructura y usuarios', 'Empresas, sedes, zonas, usuarios, roles y permisos, directorio de colaboradores, departamentos, puestos, turnos, quién autoriza y bitácora de auditoría.', 'bi-building-gear',
            ['empresas', 'sedes', 'espacios', 'usuarios', 'roles', 'permisos', 'configuracion', 'auditoria', 'colaboradores', 'departamentos', 'puestos', 'turnos', 'autorizaciones']],
        'caseta' => ['Caseta y accesos', 'Bitácora de accesos con autorización del departamento, llaves, vouchers, pases de salida y transporte.', 'bi-door-open',
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
    ];

    /** Productos de cada plan (C-05): cada plan opera solo y cumple la lista de dependencias. */
    public const PLANES = [
        'esencial' => ['estructura', 'caseta', 'padrones'],
        'profesional' => ['estructura', 'caseta', 'padrones', 'incidentes', 'informes'],
        'corporativo' => ['estructura', 'caseta', 'padrones', 'incidentes', 'informes', 'recursos_humanos'],
    ];

    /** Paquetes de suscripción: slug => [productos, planes a los que se suma]. */
    public const PAQUETES = [
        'recursos-humanos' => [['recursos_humanos'], ['esencial', 'profesional']],
        'reservas-y-eventos' => [['reservas'], ['esencial', 'profesional', 'corporativo']],
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
            $esencial = Plan::create([
                'nombre' => 'Esencial', 'slug' => 'esencial', 'frase' => 'La caseta en orden desde el primer día.',
                'descripcion' => 'Bitácora de accesos, llaves, pases de salida y padrones para una operación de seguridad ordenada.',
                'precio_por_sede_mensual' => 890, 'sedes_minimas' => 1, 'usuarios_por_sede' => 5, 'publicado' => true, 'orden' => 1,
            ]);
            $esencial->productos()->sync($ids(self::PLANES['esencial']));
            $profesional = Plan::create([
                'nombre' => 'Profesional', 'slug' => 'profesional', 'frase' => 'Seguridad completa con incidentes e informes.',
                'descripcion' => 'Todo lo de Esencial, más la bitácora de incidentes, objetos olvidados, recorridos y los informes para la dirección.',
                'precio_por_sede_mensual' => 1490, 'sedes_minimas' => 1, 'usuarios_por_sede' => 15, 'destacado' => true, 'publicado' => true, 'orden' => 2,
            ]);
            $profesional->productos()->sync($ids(self::PLANES['profesional']));
            $corporativo = Plan::create([
                'nombre' => 'Corporativo', 'slug' => 'corporativo', 'frase' => 'Para grupos con varias sedes y Recursos Humanos.',
                'descripcion' => 'Seguridad, incidentes, informes y Recursos Humanos, usuarios ilimitados y acompañamiento en la puesta en marcha de cada sede.',
                'precio_por_sede_mensual' => 2190, 'sedes_minimas' => 5, 'usuarios_por_sede' => null, 'cotizar' => true, 'publicado' => true, 'orden' => 3,
            ]);
            $corporativo->productos()->sync($ids(self::PLANES['corporativo']));

            $rh = Paquete::create([
                'nombre' => 'Recursos Humanos y candidatos', 'slug' => 'recursos-humanos', 'modalidad' => Paquete::SUSCRIPCION,
                'descripcion' => 'Recepción de candidatos, vacantes en internet, entrevistas y expediente del colaborador.',
                'precio' => 390, 'por_sede' => true, 'publicado' => true, 'orden' => 1,
            ]);
            $rh->productos()->sync($ids(self::PAQUETES['recursos-humanos'][0]));
            $rh->planes()->sync([$esencial->id, $profesional->id]);
            $reservas = Paquete::create([
                'nombre' => 'Reservas y eventos', 'slug' => 'reservas-y-eventos', 'modalidad' => Paquete::SUSCRIPCION,
                'descripcion' => 'Reservas de salones, salas y áreas comunes, con aprobación, órdenes de servicio y montaje.',
                'precio' => self::PRECIO_RESERVAS, 'por_sede' => true, 'publicado' => true, 'orden' => 2,
            ]);
            $reservas->productos()->sync($ids(self::PAQUETES['reservas-y-eventos'][0]));
            $reservas->planes()->sync([$esencial->id, $profesional->id, $corporativo->id]);
            Paquete::create([
                'nombre' => 'Puesta en marcha y capacitación', 'slug' => 'puesta-en-marcha', 'modalidad' => Paquete::UNICO,
                'descripcion' => 'Carga inicial de padrones, configuración de la sede y capacitación del personal de caseta.',
                'precio' => 4500, 'por_sede' => true, 'publicado' => true, 'orden' => 3,
            ]);
            Paquete::create([
                'nombre' => 'Kit de lector para caseta', 'slug' => 'kit-lector', 'modalidad' => Paquete::UNICO,
                'descripcion' => 'Lector de gafetes y códigos QR listo para conectarse a la computadora de la caseta.',
                'precio' => 2800, 'por_sede' => false, 'publicado' => true, 'orden' => 4,
            ]);

            $ajustes->guardar(['sembrado_en' => now()->toIso8601String(), 'landing_publicada' => false]);
        });
    }
}
