<?php

namespace Tests\Feature\Seguridad;

use App\Models\Empresa;
use App\Models\User;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Avisos del navegador: el resumen de la campana trae lo que necesita el
 * aviso del sistema (id, título, texto, «abrir», urgente), la consulta en
 * segundo plano no alarga la sesión y el botón para activarlos aparece en
 * la campana y en Mi cuenta (ver docs/tecnico/avisos-navegador.md).
 */
class AvisosNavegadorTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private User $usuario;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->usuario = $this->crearUsuario($this->empresa, 'Agente', $this->crearSede($this->empresa, 'CEN'));
    }

    private function avisar(string $tipo, string $titulo): void
    {
        app(Tenant::class)->conEmpresa($this->empresa->id, fn () => app(CentroNotificaciones::class)->avisar(
            $this->empresa->id, [$this->usuario->id], $tipo, ['titulo' => $titulo, 'texto' => 'Detalle de '.$titulo, 'url' => url('/panel')],
        ));
    }

    public function test_el_resumen_trae_id_abrir_y_prioridad_para_el_aviso(): void
    {
        $this->avisar('pase_aprobado', 'Pase aprobado');
        $this->avisar('autorizacion_visita', 'Visita en caseta');

        $lista = $this->actingAs($this->usuario)->getJson('/notificaciones/resumen')
            ->assertOk()->assertJsonPath('no_leidas', 2)->json('lista');

        $porTitulo = collect($lista)->keyBy('titulo');
        $visita = $porTitulo['Visita en caseta'];
        $this->assertIsInt($visita['id']);
        $this->assertSame('Detalle de Visita en caseta', $visita['texto']);
        $this->assertSame(route('notificaciones.abrir', $visita['id']), $visita['abrir']);
        $this->assertFalse($visita['leida']);
        $this->assertTrue($visita['urgente']);
        $this->assertFalse($porTitulo['Pase aprobado']['urgente']);
    }

    public function test_los_tipos_urgentes_son_las_autorizaciones(): void
    {
        foreach (CentroNotificaciones::URGENTES as $tipo) {
            $this->assertArrayHasKey($tipo, CentroNotificaciones::TIPOS, $tipo);
        }
        $this->assertContains('autorizacion_escalada', CentroNotificaciones::URGENTES);
        $this->assertContains('autorizacion_recepcion', CentroNotificaciones::URGENTES);
    }

    public function test_la_consulta_en_segundo_plano_no_cuenta_como_actividad(): void
    {
        $hace = now()->subMinutes(5)->getTimestamp();

        $this->actingAs($this->usuario)->withSession(['ultima_actividad' => $hace])
            ->getJson('/notificaciones/resumen', ['X-Segundo-Plano' => '1'])->assertOk()
            ->assertSessionHas('ultima_actividad', $hace);

        $this->actingAs($this->usuario)->withSession(['ultima_actividad' => $hace])
            ->getJson('/notificaciones/resumen')->assertOk();
        $this->assertGreaterThan($hace, session('ultima_actividad'));
    }

    public function test_la_consulta_en_segundo_plano_respeta_el_cierre_por_inactividad(): void
    {
        $limite = (int) config('plataforma.sesion.inactividad_minutos');

        $this->actingAs($this->usuario)->withSession(['ultima_actividad' => now()->subMinutes($limite + 1)->getTimestamp()])
            ->getJson('/notificaciones/resumen', ['X-Segundo-Plano' => '1'])->assertStatus(401);
        $this->assertGuest();
    }

    public function test_la_campana_y_mi_cuenta_muestran_el_boton_para_activar(): void
    {
        $this->actingAs($this->usuario)->get('/')->assertOk()
            ->assertSee('data-avisos-navegador', false)
            ->assertSee('Activar avisos en este navegador')
            ->assertSee('data-usuario="'.$this->usuario->id.'"', false);

        $this->actingAs($this->usuario)->get('/mi-cuenta')->assertOk()
            ->assertSee('Avisos en este navegador')
            ->assertSee('Activar avisos en este navegador');
    }
}
