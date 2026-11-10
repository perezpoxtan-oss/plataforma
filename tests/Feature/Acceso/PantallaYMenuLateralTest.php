<?php

namespace Tests\Feature\Acceso;

use App\Models\Empresa;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Modos de pantalla (Normal, Sol, Noche) y menú lateral del celular (QA M-03 y M-04).
 */
class PantallaYMenuLateralTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
    }

    public function test_los_grupos_del_menu_lateral_inician_cerrados_y_se_resalta_el_actual(): void
    {
        $admin = $this->crearUsuario($this->empresa, 'Administrador');

        $html = $this->actingAs($admin)->get('/usuarios')->assertOk()->getContent();

        $this->assertDoesNotMatchRegularExpression('/class="collapse show" id="grupo-/', $html);
        $this->assertStringNotContainsString('aria-expanded="true"', $html);
        // "Usuarios" está en Estructura: ese grupo se marca como el actual (cerrado)
        $this->assertMatchesRegularExpression('/menu-lateral-grupo contraible[^"]*actual"[^>]*data-bs-target="#grupo-estructura"/', $html);
        $this->assertDoesNotMatchRegularExpression('/actual"[^>]*data-bs-target="#grupo-operacion"/', $html);
    }

    public function test_el_boton_de_modo_de_pantalla_y_su_script_se_cargan_desde_el_inicio(): void
    {
        $html = $this->actingAs($this->crearUsuario($this->empresa, 'Agente'))->get('/')->assertOk()->getContent();

        $cabeza = substr($html, 0, (int) strpos($html, '</head>'));
        $this->assertStringContainsString('js/modo-pantalla.js', $cabeza);
        $this->assertStringContainsString('css/modos-pantalla.css', $cabeza);
        $this->assertStringContainsString('data-accion="modo-pantalla"', $html);
        $this->assertStringContainsString('Modo de pantalla: Normal', $html);
        $this->assertStringNotContainsString('data-accion="alto-contraste"', $html);
        // Sin código JavaScript en línea
        $this->assertDoesNotMatchRegularExpression('/<script>(?!\s*<\/script>)/', $html);
    }

    public function test_la_pantalla_de_acceso_tambien_respeta_el_modo(): void
    {
        $this->get('/login')->assertOk()->assertSee('js/modo-pantalla.js', false);
    }
}
