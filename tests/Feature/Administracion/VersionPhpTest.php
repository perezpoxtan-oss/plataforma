<?php

namespace Tests\Feature\Administracion;

use App\Services\Plataforma\VersionPhp;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Configuración → Plataforma: versión de PHP del servidor (solo Super
 * Administrador). Ver docs/tecnico/configuracion.md, «Versión de PHP del servidor».
 */
class VersionPhpTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private const AVISO = 'Corrige la falla de seguridad PHP2Shell (CVE-2026-109618 y CVE-2026-109619). Pide a tu proveedor de hospedaje la versión corregida.';

    private ?string $archivo = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        config(['plataforma.php.estado_despliegue' => storage_path('framework/testing/sin-estado.txt')]);
    }

    protected function tearDown(): void
    {
        if ($this->archivo && is_file($this->archivo)) {
            unlink($this->archivo);
        }
        parent::tearDown();
    }

    private function conVersion(string $version): void
    {
        $this->app->instance(VersionPhp::class, new VersionPhp($version));
    }

    private function conEstadoDespliegue(string $lineaCron): void
    {
        $this->archivo = storage_path('framework/testing/despliegue_qa_estado_'.uniqid().'.txt');
        @mkdir(dirname($this->archivo), 0777, true);
        file_put_contents($this->archivo, "Ambiente:        qa\nResultado:       OK: sin cambios\n{$lineaCron}\n\nDetalle en: despliegue.log\n");
        config(['plataforma.php.estado_despliegue' => $this->archivo]);
    }

    public function test_solo_el_super_administrador_ve_la_version_de_php(): void
    {
        $this->conVersion('8.4.27');
        $empresa = $this->crearEmpresa();
        $admin = $this->crearUsuario($empresa, 'Administrador');
        $sa = $this->crearSuperadmin();

        $this->actingAs($sa)->get('/configuracion')->assertOk()
            ->assertSee('Versión de PHP del servidor')->assertSee('PHP 8.4.27')->assertSee('Al día')
            ->assertSee('Aceleración (OPcache)')->assertSee('Memoria máxima')->assertSee('Tiempo máximo por página')
            ->assertDontSee('Requiere actualización')->assertDontSee(self::AVISO);

        $respuesta = $this->actingAs($admin)->get('/configuracion')->assertOk()
            ->assertDontSee('Versión de PHP del servidor')->assertDontSee('PHP 8.4.27')->assertDontSee('id="cfg-php"', false);
        // Nunca en cabeceras HTTP
        foreach ($respuesta->headers->all() as $nombre => $valores) {
            $this->assertStringNotContainsString(PHP_VERSION, $nombre.': '.implode(' ', $valores));
        }
        $this->assertFalse($respuesta->headers->has('X-Powered-By'));
    }

    public function test_quien_no_es_super_administrador_no_calcula_la_version(): void
    {
        $empresa = $this->crearEmpresa();
        $admin = $this->crearUsuario($empresa, 'Administrador');
        $this->app->bind(VersionPhp::class, fn () => $this->fail('No debe calcularse la versión de PHP para quien no es Super Administrador'));

        $this->actingAs($admin)->get('/configuracion')->assertOk();
    }

    public function test_version_vieja_de_su_rama_requiere_actualizacion_con_el_aviso(): void
    {
        $this->conVersion('8.4.20');
        $this->actingAs($this->crearSuperadmin())->get('/configuracion')->assertOk()
            ->assertSee('PHP 8.4.20')->assertSee('Requiere actualización')->assertSee(self::AVISO);
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function versiones(): array
    {
        return [
            '8.5 corregida' => ['8.5.12', true],
            '8.5 posterior' => ['8.5.13', true],
            '8.5 anterior' => ['8.5.11', false],
            '8.4 corregida' => ['8.4.27', true],
            '8.4 anterior' => ['8.4.26', false],
            '8.4 inicial' => ['8.4.1', false],
            '8.3 corregida' => ['8.3.36', true],
            '8.3 anterior' => ['8.3.35', false],
            '8.2 corregida' => ['8.2.35', true],
            '8.2 anterior' => ['8.2.34', false],
            '8.1 sin soporte' => ['8.1.99', false],
            '7.4 sin soporte' => ['7.4.33', false],
            'rama más nueva' => ['8.6.0', true],
            'versión de desarrollo' => ['8.5.12-dev', true],
            'texto sin versión' => ['desconocida', false],
        ];
    }

    #[DataProvider('versiones')]
    public function test_calcula_al_dia_o_requiere_actualizacion(string $version, bool $alDia): void
    {
        $this->assertSame($alDia, VersionPhp::evaluar($version)['al_dia'], $version);
    }

    public function test_las_versiones_minimas_salen_de_la_configuracion(): void
    {
        config(['plataforma.php.minimas' => ['8.4' => '8.4.30']]);
        $this->assertFalse(VersionPhp::evaluar('8.4.27')['al_dia']);
        $this->assertTrue(VersionPhp::evaluar('8.4.30')['al_dia']);
        $this->assertSame('8.4.30', VersionPhp::evaluar('8.4.30')['minima']);
    }

    public function test_muestra_la_version_del_cron_del_archivo_de_estado(): void
    {
        $this->conVersion('8.5.12');
        $this->conEstadoDespliegue('PHP del cron:    8.4.20 (/opt/alt/php84/usr/bin/php)');

        $this->actingAs($this->crearSuperadmin())->get('/configuracion')->assertOk()
            ->assertSee('Tareas programadas')->assertSee('PHP 8.5.12')->assertSee('PHP 8.4.20')
            ->assertSee('Al día')->assertSee('Requiere actualización')->assertSee(self::AVISO)
            // Solo la versión: nada de rutas del servidor
            ->assertDontSee('/opt/alt');
    }

    public function test_sin_archivo_de_estado_o_sin_deteccion_no_inventa_la_del_cron(): void
    {
        $this->conVersion('8.5.12');
        $sa = $this->crearSuperadmin();
        $this->actingAs($sa)->get('/configuracion')->assertOk()->assertSee('Sin dato todavía')->assertDontSee('Requiere actualización');

        $this->conEstadoDespliegue('PHP del cron:    sin detectar');
        $this->assertNull(app(VersionPhp::class)->cron());
    }

    public function test_deduce_el_archivo_de_estado_de_la_carpeta_del_servidor(): void
    {
        config(['plataforma.php.estado_despliegue' => null]);
        $original = $this->app->basePath();
        $this->app->setBasePath('/home/cuenta/apps/plataforma-produccion/releases/v1.2.3');
        $this->assertSame('/home/cuenta/despliegue_prod_estado.txt', (new VersionPhp)->archivoEstado());
        $this->app->setBasePath('/home/cuenta/apps/plataforma-qa/releases/qa-20261023');
        $this->assertSame('/home/cuenta/despliegue_qa_estado.txt', (new VersionPhp)->archivoEstado());
        $this->app->setBasePath('/var/www/otra');
        $this->assertNull((new VersionPhp)->archivoEstado());
        $this->app->setBasePath($original);
    }
}
