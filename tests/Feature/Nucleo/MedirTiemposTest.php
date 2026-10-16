<?php

namespace Tests\Feature\Nucleo;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class MedirTiemposTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        File::delete(File::glob(storage_path('logs/lentas*.log')));
    }

    protected function tearDown(): void
    {
        File::delete(File::glob(storage_path('logs/lentas*.log')));
        parent::tearDown();
    }

    public function test_el_superadministrador_ve_el_desglose_de_tiempos_en_el_navegador(): void
    {
        $respuesta = $this->actingAs($this->crearSuperadmin())->get('/');

        $cabecera = (string) $respuesta->headers->get('Server-Timing');
        foreach (['espera;dur=', 'arranque;dur=', 'bd;dur=', 'codigo;dur=', 'total;dur='] as $parte) {
            $this->assertStringContainsString($parte, $cabecera);
        }
        $this->assertMatchesRegularExpression('/Base de datos \(\d+ consultas\)/', $cabecera);
    }

    public function test_nadie_mas_ve_los_tiempos(): void
    {
        $this->get('/login')->assertHeaderMissing('Server-Timing');

        $empresa = $this->crearEmpresa();
        $this->actingAs($this->crearUsuario($empresa, 'Administrador'))->get('/')->assertHeaderMissing('Server-Timing');
    }

    public function test_las_visitas_lentas_se_anotan_sin_valores_de_las_consultas(): void
    {
        config(['plataforma.velocidad.lento_ms' => 0]);
        $empresa = $this->crearEmpresa();
        $this->actingAs($this->crearUsuario($empresa, 'Administrador'))->get('/');

        $archivos = File::glob(storage_path('logs/lentas*.log'));
        $this->assertCount(1, $archivos);
        $texto = File::get($archivos[0]);
        $this->assertStringContainsString('GET panel → 200', $texto);
        $this->assertMatchesRegularExpression('/bd \d+ \(\d+ consultas\)/', $texto);
        // Sin valores: solo el texto de la consulta con sus «?»
        $this->assertStringNotContainsString((string) $empresa->id.' and', $texto);
    }

    public function test_las_visitas_rapidas_no_se_anotan(): void
    {
        config(['plataforma.velocidad.lento_ms' => 600000]);
        $this->get('/login');

        $this->assertSame([], File::glob(storage_path('logs/lentas*.log')));
    }
}
