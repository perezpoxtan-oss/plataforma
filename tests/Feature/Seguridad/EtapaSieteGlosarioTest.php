<?php

namespace Tests\Feature\Seguridad;

use App\Models\Empresa;
use App\Models\Modulo;
use App\Models\PrestamoLlave;
use App\Models\Rubro;
use App\Models\Sede;
use App\Services\Auditoria\LectorAuditoria;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Etapa 7 · C-39: glosario único (DD-20). Revisa títulos, menús y mensajes
 * contra el glosario y que ninguna palabra retirada vuelva a aparecer en
 * las pantallas, los mensajes del código o el manual. Accidentes queda
 * fuera de la revisión (su formato y su terminología no se tocan).
 */
class EtapaSieteGlosarioTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    /** Palabra retirada => la del glosario. */
    private const RETIRADAS = [
        'Control de Accesos' => 'Bitácora de accesos',
        'Bitácora de Llaves' => 'Préstamo de llaves',
        'Inventario Gafetes' => 'Gafetes',
        'Gafete interno' => 'Credencial de empleado',
        'GAFETE INTERNO' => 'CREDENCIAL DE EMPLEADO',
        'Consola de Monitoreo' => 'Tablero',
        'Usuarios Operativos' => 'Usuarios',
        'Usuarios del Sistema' => 'Usuarios',
        'Horarios corporativos' => 'Horarios de trabajo',
        '>Rubro<' => '>Giro<',
        'el Host' => 'quien lo citó',
        'Visita a / Host' => 'A quién viene',
        'Operación → Incidentes' => 'Operación → Bitácora',
        'Considerar / cartera' => 'Considerar',
        'residente(es)' => 'residentes',
        'Residentees' => 'Residentes',
        // Decisión del dueño (3 nov): el módulo se llama igual en el menú, la pantalla y la Matriz de permisos
        'módulo Proveedores' => 'Empresas externas',
        'Padrones → Proveedores' => 'Padrones → Empresas externas',
        'dada de baja en Proveedores' => 'Empresas externas',
        // Pedido del dueño (13 nov 2026): nombres más cortos en el menú y en los títulos
        'Padrones → Equipos de Protección Civil' => 'Padrones → Equipos contra incendios',
        'Padrones → Estacionamientos y zonas de descarga' => 'Padrones → Estacionamiento y almacenes',
        'Protección civil → Recorridos de Protección Civil' => 'Protección civil → Recorridos',
        'Catálogo de Equipos de Protección Civil' => 'Equipos contra incendios',
    ];

    /** Accidentes no se toca (DD-16). */
    private const FUERA = ['formatos/accidente', 'formatos/_fila-testigo-accidente', 'accidentes'];

    /** @return iterable<string, string> ruta => contenido sin comentarios */
    private function archivos(): iterable
    {
        $carpetas = [resource_path('views'), base_path('docs/usuario'), app_path()];
        foreach ($carpetas as $carpeta) {
            $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($carpeta, \FilesystemIterator::SKIP_DOTS));
            foreach ($it as $archivo) {
                $ruta = $archivo->getPathname();
                if (! preg_match('/\.(php|md)$/', $ruta) || collect(self::FUERA)->contains(fn ($f) => str_contains($ruta, $f))) {
                    continue;
                }
                $texto = (string) file_get_contents($ruta);
                // Solo lo que se ve: sin comentarios de Blade, de HTML ni de PHP
                $texto = (string) preg_replace(['/\{\{--.*?--\}\}/s', '/<!--.*?-->/s', '#/\*.*?\*/#s', '#^\s*//.*$#m', '#\s//\s.*$#m'], '', $texto);
                yield $ruta => $texto;
            }
        }
        yield 'public/js/plataforma.js' => (string) preg_replace(['#/\*.*?\*/#s', '#^\s*//.*$#m'], '', (string) file_get_contents(public_path('js/plataforma.js')));
    }

    public function test_ninguna_palabra_retirada_vuelve_a_las_pantallas_ni_al_manual(): void
    {
        $hallazgos = [];
        foreach ($this->archivos() as $ruta => $texto) {
            foreach (self::RETIRADAS as $vieja => $nueva) {
                if (str_contains($texto, $vieja)) {
                    $hallazgos[] = str_replace(base_path().'/', '', $ruta)." dice «{$vieja}» (glosario: «{$nueva}»)";
                }
            }
        }
        $this->assertSame([], $hallazgos);
    }

    public function test_el_glosario_esta_en_el_manual_con_todos_sus_terminos(): void
    {
        $glosario = (string) file_get_contents(base_path('docs/usuario/glosario.md'));
        foreach (['Giro', 'Sede', 'Zona', 'Área', 'Vivienda', 'Personal', 'Residente', 'Empresa externa', 'Gafete', 'Credencial', 'Novedad o incidente', 'Bitácora',
            'Reserva (de áreas)', 'Reserva del huésped', 'Equipo de seguridad', 'Equipo contra incendios'] as $termino) {
            $this->assertStringContainsString('| **'.$termino.'** |', $glosario, "Falta «{$termino}» en el glosario");
        }

        $this->sembrarCatalogo();
        $empresa = $this->crearEmpresa();
        $this->crearSede($empresa, 'CEN');
        $agente = $this->crearUsuario($empresa, 'Agente', Sede::first());
        $this->actingAs($agente)->get('/manual/glosario')->assertOk()->assertSee('Cómo se llama cada cosa');
    }

    public function test_titulos_y_menu_dicen_lo_mismo(): void
    {
        $this->sembrarCatalogo();
        $empresa = $this->crearEmpresa();
        $centro = $this->crearSede($empresa, 'CEN');
        $admin = $this->crearUsuario($empresa, 'Administrador');

        // El título de cada pantalla es el nombre de su módulo en el menú
        $pantallas = ['/accesos' => 'Bitácora de accesos', '/prestamo-llaves' => 'Préstamo de llaves', '/gafetes' => 'Gafetes',
            '/informes/tablero' => 'Tablero', '/usuarios' => 'Usuarios', '/proveedores' => 'Empresas Externas'];
        foreach ($pantallas as $url => $titulo) {
            $html = $this->actingAs($admin)->get($url)->assertOk()->getContent();
            $this->assertStringContainsString('<h1>'.$titulo.'</h1>', $html, "{$url} se titula «{$titulo}»");
        }

        // La sección de Operación ya no repite el nombre de la bitácora
        $this->assertSame(['Bitácora'], Modulo::whereIn('clave', ['novedades', 'lost_found', 'robo'])->distinct()->pluck('seccion_menu')->all());
        $this->assertSame('Credencial de empleado', PrestamoLlave::GARANTIAS_OPCIONES['gafete_interno']);

        // Giro (antes «Rubro») al dar de alta una empresa
        $super = $this->crearSuperadmin();
        $this->actingAs($super)->get('/empresas')->assertOk()->assertSee('>Giro</label>', false)->assertDontSee('>Rubro<', false);
        unset($centro);
    }

    public function test_empresas_externas_se_llama_igual_en_menu_pantalla_matriz_auditoria_y_curso(): void
    {
        $this->sembrarCatalogo();
        $empresa = $this->crearEmpresa();
        $this->crearSede($empresa, 'CEN');
        $admin = $this->crearUsuario($empresa, 'Administrador');

        // La clave y los permisos no cambian; solo el nombre que se ve
        $modulo = Modulo::where('clave', 'proveedores')->firstOrFail();
        $this->assertSame('Empresas externas', $modulo->nombre);
        $this->assertSame('Empresas externas', $modulo->nombre_menu);
        $this->assertTrue($admin->can('proveedores.ver'));
        $this->assertTrue($admin->can('proveedores.importar'));

        // Matriz de permisos: renglón y casillas con el nombre del menú
        $matriz = (string) $this->actingAs($admin)->get('/permisos')->assertOk()->getContent();
        $this->assertStringContainsString('Ver · Empresas externas', $matriz);
        $this->assertStringContainsString('name="permisos[proveedores][acciones][]"', $matriz);
        $this->assertStringNotContainsString('· Proveedores"', $matriz);
        $this->assertDoesNotMatchRegularExpression('/<\/i>\s*Proveedores\s*</', $matriz);

        // Bitácora de auditoría: el nombre del módulo
        $lector = app(LectorAuditoria::class);
        $this->assertSame('Empresas externas', $lector->modulo('proveedores.crear'));

        // Curso: sin el nombre viejo del módulo
        foreach (glob(base_path('docs/cursos/*.md')) as $leccion) {
            $texto = (string) file_get_contents($leccion);
            foreach (['Padrones → Proveedores', 'módulo Proveedores', 'dada de baja en Proveedores', 'Abre Proveedores'] as $vieja) {
                $this->assertStringNotContainsString($vieja, $texto, basename($leccion));
            }
        }
    }

    public function test_el_nombre_empresas_externas_migra_y_revierte(): void
    {
        $this->sembrarCatalogo();
        $migracion = require database_path('migrations/2026_11_19_000100_empresas_externas_en_la_matriz.php');
        $migracion->down();
        $this->assertSame('Proveedores', Modulo::where('clave', 'proveedores')->value('nombre'));
        $migracion->up();
        $this->assertSame('Empresas externas', Modulo::where('clave', 'proveedores')->value('nombre'));
        // Un nombre cambiado a mano no se toca
        DB::table('modulos')->where('clave', 'proveedores')->update(['nombre' => 'Padrón de empresas']);
        $migracion->down();
        $migracion->up();
        $this->assertSame('Padrón de empresas', Modulo::where('clave', 'proveedores')->value('nombre'));
    }

    public function test_la_seccion_bitacora_migra_y_revierte(): void
    {
        $this->sembrarCatalogo();
        $migracion = require database_path('migrations/2026_11_17_000200_seccion_bitacora_en_operacion.php');
        $migracion->down();
        $this->assertSame(['Incidentes'], Modulo::whereIn('clave', ['novedades', 'lost_found', 'robo'])->distinct()->pluck('seccion_menu')->all());
        // Una sección cambiada a mano no se toca
        DB::table('modulos')->where('clave', 'robo')->update(['seccion_menu' => 'Seguridad']);
        $migracion->up();
        $this->assertSame('Bitácora', Modulo::where('clave', 'novedades')->value('seccion_menu'));
        $this->assertSame('Seguridad', Modulo::where('clave', 'robo')->value('seccion_menu'));
        $migracion->down();
        $migracion->up();
        $this->assertSame('Bitácora', Modulo::where('clave', 'lost_found')->value('seccion_menu'));
    }

    public function test_en_condominio_el_plural_de_residente_es_residentes(): void
    {
        $this->sembrarCatalogo();
        $empresa = $this->crearEmpresa('Condominio Las Palmas');
        $empresa->forceFill(['rubro_id' => Rubro::where('nombre', 'like', 'Condominio%')->value('id') ?? $empresa->rubro_id])->save();
        $this->crearSede($empresa, 'TOR');
        $admin = $this->crearUsuario($empresa, 'Administrador');
        foreach (['/residentes', '/colaboradores', '/manual/residentes', '/manual/glosario'] as $url) {
            $html = (string) $this->actingAs($admin)->get($url)->getContent();
            $this->assertStringNotContainsString('Residentees', $html, $url);
            $this->assertStringNotContainsString('residente(es)', $html, $url);
        }
        $this->assertInstanceOf(Empresa::class, $empresa);
    }
}
