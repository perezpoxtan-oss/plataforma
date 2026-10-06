<?php

namespace Tests\Feature\Acceso;

use App\Models\Empresa;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

class InicioSesionTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        RateLimiter::clear('acceso-ip:127.0.0.1');
    }

    private function agente(): User
    {
        $usuario = $this->crearUsuario($this->empresa, 'Agente');
        $usuario->forceFill(['username' => 'agente1', 'email' => 'agente@ejemplo.mx', 'password' => 'Secreta123!'])->save();

        return $usuario;
    }

    public function test_muestra_la_pantalla_de_acceso(): void
    {
        $this->get('/login')
            ->assertOk()
            ->assertSee('Autenticar Ingreso')
            ->assertSee('Usuario o Correo');
    }

    public function test_un_invitado_es_enviado_al_acceso(): void
    {
        $this->get('/')->assertRedirect('/login');
    }

    public function test_entra_con_usuario_o_con_correo(): void
    {
        $usuario = $this->agente();

        $this->post('/login', ['username' => 'agente1', 'password' => 'Secreta123!'])->assertRedirect('/');
        $this->assertAuthenticatedAs($usuario);
        $this->assertNotNull($usuario->fresh()->ultimo_acceso_en);

        $this->post('/logout')->assertRedirect('/login');
        $this->assertGuest();

        $this->post('/login', ['username' => 'agente@ejemplo.mx', 'password' => 'Secreta123!'])->assertRedirect('/');
        $this->assertAuthenticatedAs($usuario);
    }

    public function test_contrasena_incorrecta_cuenta_el_intento(): void
    {
        $usuario = $this->agente();

        $this->post('/login', ['username' => 'agente1', 'password' => 'mal'])
            ->assertRedirect('/login')
            ->assertSessionHas('acceso', 'credenciales');

        $this->assertGuest();
        $this->assertSame(1, $usuario->fresh()->intentos_fallidos);
    }

    public function test_cinco_fallos_bloquean_la_cuenta_quince_minutos(): void
    {
        $usuario = $this->agente();

        for ($i = 0; $i < 4; $i++) {
            $this->post('/login', ['username' => 'agente1', 'password' => 'mal']);
        }
        $this->post('/login', ['username' => 'agente1', 'password' => 'mal'])
            ->assertSessionHas('acceso', 'bloqueado')
            ->assertSessionHas('minutos', 15);

        // Aun con la contrasena correcta sigue bloqueada
        $this->post('/login', ['username' => 'agente1', 'password' => 'Secreta123!'])
            ->assertSessionHas('acceso', 'bloqueado');
        $this->assertGuest();

        // Pasado el bloqueo entra y el contador se reinicia
        $this->travel(16)->minutes();
        $this->post('/login', ['username' => 'agente1', 'password' => 'Secreta123!'])->assertRedirect('/');
        $this->assertAuthenticatedAs($usuario);
        $this->assertNull($usuario->fresh()->bloqueado_hasta);
    }

    public function test_cuenta_inactiva_o_empresa_inactiva_no_entran(): void
    {
        $usuario = $this->agente();
        $usuario->forceFill(['activo' => false])->save();

        $this->post('/login', ['username' => 'agente1', 'password' => 'Secreta123!'])
            ->assertSessionHas('acceso', 'credenciales');
        $this->assertGuest();

        $usuario->forceFill(['activo' => true])->save();
        $this->empresa->forceFill(['activo' => false])->save();

        $this->post('/login', ['username' => 'agente1', 'password' => 'Secreta123!'])
            ->assertSessionHas('acceso', 'credenciales');
        $this->assertGuest();
    }

    public function test_desactivar_al_usuario_corta_su_sesion_abierta(): void
    {
        $usuario = $this->agente();
        $this->actingAs($usuario)->get('/')->assertOk();

        $usuario->forceFill(['activo' => false])->save();

        $this->get('/')->assertRedirect('/login')->assertSessionHas('acceso', 'inactiva');
        $this->assertGuest();
    }

    public function test_demasiados_intentos_desde_un_equipo_se_frenan(): void
    {
        config(['plataforma.sesion.intentos_por_ip' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->post('/login', ['username' => "nadie{$i}", 'password' => 'x']);
        }

        $this->post('/login', ['username' => 'nadie', 'password' => 'x'])
            ->assertSessionHas('acceso', 'equipo');
    }

    public function test_la_sesion_se_cierra_tras_la_inactividad(): void
    {
        $this->post('/login', ['username' => $this->agente()->username, 'password' => 'Secreta123!']);
        $this->get('/')->assertOk();

        $this->travel(21)->minutes();

        $this->get('/')->assertRedirect('/login')->assertSessionHas('acceso', 'expirado');
        $this->assertGuest();
    }

    public function test_el_latido_mantiene_viva_la_sesion(): void
    {
        $this->post('/login', ['username' => $this->agente()->username, 'password' => 'Secreta123!']);

        $this->travel(15)->minutes();
        $this->getJson('/sesion/latido')->assertOk()->assertJson(['ok' => true]);

        $this->travel(15)->minutes();
        $this->get('/')->assertOk();
    }

    public function test_el_aviso_de_inactividad_cierra_y_avisa(): void
    {
        $this->actingAs($this->agente());

        // Seguridad: el cierre es POST (con token); ver tests/Feature/SeguridadAuditoria
        $this->post('/sesion/expirada')->assertRedirect('/login')->assertSessionHas('acceso', 'expirado');
        $this->assertGuest();
    }

    public function test_no_redirige_a_otro_sitio_despues_de_entrar(): void
    {
        $this->agente();

        $this->withSession(['url.intended' => 'https://sitio-malicioso.example/robar'])
            ->post('/login', ['username' => 'agente1', 'password' => 'Secreta123!'])
            ->assertRedirect('/');
    }

    public function test_regresa_a_la_pantalla_que_intentaba_abrir(): void
    {
        $this->agente();

        $this->get('/modulos/accesos')->assertRedirect('/login');
        $this->post('/login', ['username' => 'agente1', 'password' => 'Secreta123!'])
            ->assertRedirect('/modulos/accesos');
    }
}
