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
 * Catálogo comercial inicial: la plataforma por módulos, tres planes y tres
 * paquetes con PRECIOS DE EJEMPLO. Corre UNA sola vez (marca «sembrado_en»
 * en los ajustes comerciales): lo que el Super Administrador cambie o borre
 * después no se vuelve a crear en el siguiente despliegue. La página pública
 * nace SIN publicar, para revisar precios antes de mostrarlos.
 */
class CatalogoComercialSeeder extends Seeder
{
    /** clave => [nombre, descripción, ícono, módulos del catálogo] */
    public const PRODUCTOS = [
        'estructura' => ['Estructura y usuarios', 'Empresas, sedes, zonas, usuarios, roles, permisos y bitácora de auditoría.', 'bi-building-gear',
            ['empresas', 'sedes', 'espacios', 'usuarios', 'roles', 'permisos', 'configuracion', 'auditoria']],
        'caseta' => ['Caseta y accesos', 'Bitácora de accesos con autorización del departamento, llaves, vouchers, pases de salida y transporte.', 'bi-door-open',
            ['accesos', 'prestamo_llaves', 'vouchers', 'pases_salida', 'transporte']],
        'padrones' => ['Padrones', 'Personas, vehículos, empresas externas, llaves, gafetes, equipos, estacionamientos, rutas y etiquetas QR.', 'bi-folder2-open',
            ['proveedores', 'visitantes', 'vehiculos', 'llaves', 'gafetes', 'equipos', 'equipos_pc', 'estacionamientos', 'rutas', 'etiquetas_qr', 'responsivas']],
        'incidentes' => ['Incidentes y novedades', 'Bitácora de novedades, objetos olvidados, robos, recorridos de Protección Civil y procedimientos.', 'bi-journal-text',
            ['novedades', 'lost_found', 'robo', 'recorridos_pc', 'procedimientos']],
        'recursos_humanos' => ['Recursos Humanos y candidatos', 'Colaboradores, turnos, recepción de candidatos, vacantes y autorizaciones del departamento.', 'bi-people',
            ['colaboradores', 'departamentos', 'puestos', 'turnos', 'recepcion_rh', 'candidatos', 'autorizaciones', 'vacantes']],
        'informes' => ['Informes', 'Tablero, bitácora del día, tendencias e informe ejecutivo por sede.', 'bi-graph-up',
            ['dashboard', 'bitacora_dia', 'tendencias', 'informe_ejecutivo']],
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
            $esencial->productos()->sync($ids(['estructura', 'caseta', 'padrones']));
            $profesional = Plan::create([
                'nombre' => 'Profesional', 'slug' => 'profesional', 'frase' => 'Seguridad completa con incidentes e informes.',
                'descripcion' => 'Todo lo de Esencial, más la bitácora de incidentes, objetos olvidados, recorridos y los informes para la dirección.',
                'precio_por_sede_mensual' => 1490, 'sedes_minimas' => 1, 'usuarios_por_sede' => 15, 'destacado' => true, 'publicado' => true, 'orden' => 2,
            ]);
            $profesional->productos()->sync($ids(['estructura', 'caseta', 'padrones', 'incidentes', 'informes']));
            $corporativo = Plan::create([
                'nombre' => 'Corporativo', 'slug' => 'corporativo', 'frase' => 'Para grupos con varias sedes y Recursos Humanos.',
                'descripcion' => 'Toda la plataforma, usuarios ilimitados y acompañamiento en la puesta en marcha de cada sede.',
                'precio_por_sede_mensual' => 2190, 'sedes_minimas' => 5, 'usuarios_por_sede' => null, 'cotizar' => true, 'publicado' => true, 'orden' => 3,
            ]);
            $corporativo->productos()->sync(array_values($productos));

            $rh = Paquete::create([
                'nombre' => 'Recursos Humanos y candidatos', 'slug' => 'recursos-humanos', 'modalidad' => Paquete::SUSCRIPCION,
                'descripcion' => 'Recepción de candidatos, vacantes en internet, entrevistas y expediente del colaborador.',
                'precio' => 390, 'por_sede' => true, 'publicado' => true, 'orden' => 1,
            ]);
            $rh->productos()->sync($ids(['recursos_humanos']));
            $rh->planes()->sync([$esencial->id, $profesional->id]);
            Paquete::create([
                'nombre' => 'Puesta en marcha y capacitación', 'slug' => 'puesta-en-marcha', 'modalidad' => Paquete::UNICO,
                'descripcion' => 'Carga inicial de padrones, configuración de la sede y capacitación del personal de caseta.',
                'precio' => 4500, 'por_sede' => true, 'publicado' => true, 'orden' => 2,
            ]);
            Paquete::create([
                'nombre' => 'Kit de lector para caseta', 'slug' => 'kit-lector', 'modalidad' => Paquete::UNICO,
                'descripcion' => 'Lector de gafetes y códigos QR listo para conectarse a la computadora de la caseta.',
                'precio' => 2800, 'por_sede' => false, 'publicado' => true, 'orden' => 3,
            ]);

            $ajustes->guardar(['sembrado_en' => now()->toIso8601String(), 'landing_publicada' => false]);
        });
    }
}
