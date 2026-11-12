<?php

namespace Tests\Feature\Direccion;

use App\Http\Controllers\Administracion\ConfiguracionController;
use App\Models\Empresa;
use App\Models\User;
use App\Support\TemasConfiguracion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Configuración en mosaico + buscador (pedido del dueño, 12 nov 2026;
 * docs/tecnico/configuracion.md): al entrar, buscador y una tarjeta por tema;
 * cada tema abre solo sus secciones en /configuracion/{tema}; las anclas
 * viejas siguen llevando a su tarjeta y ningún ajuste se pierde.
 */
class ConfiguracionMosaicoTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->crearSede($this->empresa, 'CEN');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
    }

    public function test_el_mosaico_trae_buscador_y_una_tarjeta_por_tema_sin_ajustes_a_la_vista(): void
    {
        $html = $this->actingAs($this->admin)->get('/configuracion')->assertOk()
            ->assertSee('¿Qué quieres ajustar?')->assertSee('name="q"', false)->assertSee('Versión instalada')
            ->assertSee('js/configuracion.js', false)->getContent();

        $this->assertGreaterThanOrEqual(8, count(TemasConfiguracion::TEMAS));
        $this->assertLessThanOrEqual(10, count(TemasConfiguracion::TEMAS));
        foreach (['general', 'caseta', 'activos', 'seguridad', 'avisos'] as $tema) {
            $this->assertStringContainsString('id="tema-'.$tema.'"', $html);
            $this->assertStringContainsString(e(TemasConfiguracion::TEMAS[$tema][2]), $html, "La tarjeta {$tema} dice qué contiene");
        }
        // Ningún formulario de ajustes en el mosaico: cada tema abre los suyos
        $this->assertStringNotContainsString('id="formAvisos"', $html);
        $this->assertStringNotContainsString('id="cfg-vobo"', $html);
        $this->assertStringNotContainsString('Guardar avisos', $html);
    }

    public function test_un_tema_abre_solo_sus_secciones_con_volver_a_configuracion(): void
    {
        $html = $this->actingAs($this->admin)->get('/configuracion/caseta')->assertOk()
            ->assertSee('<h1>Caseta y accesos</h1>', false)->assertSee('href="'.route('configuracion.index').'"', false)
            ->assertSee('id="cfg-motivos"', false)->assertSee('id="formAvisos"', false)->getContent();
        foreach (['sec-general', 'sec-avisos', 'sec-cuentas', 'sec-activos', 'id="tema-'] as $ajeno) {
            $this->assertStringNotContainsString('id="'.$ajeno, $html);
        }
        // Un tema que el rol no ve regresa al mosaico; un tema que no existe, 404
        $this->actingAs($this->admin)->get('/configuracion/plataforma')->assertRedirect(route('configuracion.index'));
        $this->actingAs($this->admin)->get('/configuracion/no-existe')->assertNotFound();
        // Sin permiso de Configuración, tampoco los temas
        $agente = $this->crearUsuario($this->empresa, 'Agente');
        $this->actingAs($agente)->get('/configuracion/caseta')->assertForbidden();
    }

    public function test_el_buscador_sin_javascript_filtra_temas_y_lleva_al_ajuste(): void
    {
        $html = $this->actingAs($this->admin)->get('/configuracion?q=LLAVES')->assertOk()->assertSee('Ajustes que coinciden')->getContent();
        // El ajuste encontrado lleva directo a su tarjeta (sin acentos ni mayúsculas importa)
        $this->assertMatchesRegularExpression('#<li data-buscar="[^"]*" data-tema="activos"\s*>\s*<a href="'.preg_quote(route('configuracion.tema', 'activos').'#cfg-llaves', '#').'"#', $html);
        $this->assertMatchesRegularExpression('#<li data-buscar="[^"]*" data-tema="activos"\s*>\s*<a href="[^"]*/configuracion/activos" class="tarjeta#', $html);
        // Los temas que no coinciden quedan ocultos
        $this->assertMatchesRegularExpression('#<li data-buscar="[^"]*" data-tema="seguridad"\s+hidden\s*>#', $html);

        $this->actingAs($this->admin)->get('/configuracion?q=verificacion+dos+pasos')
            ->assertSee(route('configuracion.tema', 'seguridad').'#cfg-dos-pasos', false);
        $this->actingAs($this->admin)->get('/configuracion?q=zzzz')->assertSee('No encontramos «');

        // Solo encuentra lo que el rol ve: el correo de la plataforma es del Super Administrador
        $this->assertDoesNotMatchRegularExpression('#<li data-buscar="[^"]*" data-tema="plataforma"#', $this->actingAs($this->admin)->get('/configuracion?q=smtp')->getContent());
    }

    public function test_cada_ajuste_del_buscador_existe_en_su_tema(): void
    {
        $html = $this->actingAs($this->admin)->get('/configuracion')->getContent();
        preg_match_all('#data-tema="([a-z]+)"\s+hidden\s*>\s*<a href="([^"]+)\#([^"]+)"#', $html, $m, PREG_SET_ORDER);
        $this->assertGreaterThan(25, count($m), 'El buscador conoce los ajustes de cada tema');
        foreach ($m as [, $tema, $url, $ancla]) {
            $this->assertSame(route('configuracion.tema', $tema), $url);
            $this->actingAs($this->admin)->get($url)->assertOk()->assertSee('id="'.$ancla.'"', false);
        }

        $sa = $this->crearSuperadmin();
        foreach (['cfg-superadmins', 'cfg-php', 'cfg-correo', 'cfg-respaldos'] as $ancla) {
            $this->actingAs($sa)->get(TemasConfiguracion::url($ancla))->assertOk()->assertSee('id="'.$ancla.'"', false);
        }
    }

    public function test_las_anclas_viejas_llevan_a_su_tema(): void
    {
        // Lo que antes era /configuracion#… ahora es /configuracion/{tema}#…
        $this->assertSame(route('configuracion.tema', 'general').'#cfg-vobo', TemasConfiguracion::url('cfg-vobo'));
        $this->assertSame(route('configuracion.tema', 'novedades').'#lost-found', TemasConfiguracion::url('#lost-found'));
        $this->assertSame(route('configuracion.tema', 'caseta').'#config-autorizaciones', TemasConfiguracion::url('config-autorizaciones'));
        $this->assertSame(route('configuracion.tema', 'reservas').'#sec-reservas', TemasConfiguracion::url('sec-reservas'));
        $this->assertSame(route('configuracion.tema', 'seguridad').'#config-cuentas', TemasConfiguracion::url('config-cuentas'));
        $this->assertSame(route('configuracion.index').'#otra-cosa', TemasConfiguracion::url('otra-cosa'));

        // Cada ancla a la que regresa «Restablecer» está en su tema
        foreach (ConfiguracionController::RESTABLECIBLES as [, $ancla]) {
            $this->assertNotNull(TemasConfiguracion::temaDe($ancla), "El ancla {$ancla} no tiene tema");
        }
        $this->actingAs($this->admin)->get(TemasConfiguracion::url('config-auditoria'))->assertSee('id="config-auditoria"', false);

        // La pantalla lleva el mapa para los enlaces viejos con #ancla (js/configuracion.js)
        $html = $this->actingAs($this->admin)->get('/configuracion')->getContent();
        preg_match("#data-anclas='([^']+)'#", $html, $m);
        $mapa = json_decode(html_entity_decode($m[1]), true);
        $this->assertSame(route('configuracion.tema', 'general'), $mapa['cfg-vobo']);
        $this->assertSame(route('configuracion.tema', 'seguridad'), $mapa['cfg-aviso']);
        $this->assertSame(route('configuracion.tema', 'plataforma'), $mapa['t-respaldos']);

        // Ruta vieja de Lost & Found: directo al tema
        $this->actingAs($this->admin)->get('/lost-found/dias-resguardo')->assertStatus(301)->assertRedirect(route('configuracion.tema', 'novedades').'#lost-found');
    }

    public function test_guardar_regresa_al_mismo_tema_con_su_mensaje(): void
    {
        $this->actingAs($this->admin)->put('/configuracion/caseta', ['_tarjeta' => 'noche', 'agente_excepcion' => 'si'])
            ->assertRedirect(route('configuracion.tema', 'caseta').'#cfg-noche')->assertSessionHas('ok');
        $this->actingAs($this->admin)->put('/configuracion/equipos-pc', ['frecuencia_dias' => ['EXTINTOR' => 15]])
            ->assertRedirect(route('configuracion.tema', 'novedades').'#cfg-equipos-pc');
        $this->actingAs($this->admin)->put('/configuracion/avisos', ['avisos_modulos' => ['transporte' => 1], 'avisos' => ['vale_taxi' => 1], '_grupo' => 'transporte'])
            ->assertRedirect(route('configuracion.tema', 'activos').'#config-transporte')->assertSessionHas('ok', 'Avisos actualizados.');
        $this->actingAs($this->admin)->put('/configuracion/auditoria', ['auditoria_sede' => 'propios'])
            ->assertRedirect(route('configuracion.tema', 'seguridad').'#config-auditoria');
    }

    public function test_guardar_los_avisos_de_un_tema_conserva_los_de_los_demas(): void
    {
        // Antes: vale de taxi apagado, con sus correos, y el alta provisional apagada
        $this->actingAs($this->admin)->put('/configuracion/avisos', ['avisos' => ['alta_por_verificar' => 1], 'destinatarios' => ['vale_taxi' => 'caja@ejemplo.com']])
            ->assertSessionHasNoErrors();
        $this->assertFalse($this->empresa->refresh()->aviso('vale_taxi'));

        // Caseta guarda solo sus grupos (Altas por verificar y Autorizaciones): apaga «alta por verificar»
        $this->actingAs($this->admin)->put('/configuracion/avisos', ['avisos_modulos' => ['padrones' => 1, 'autorizaciones' => 1],
            'avisos' => ['autorizacion_departamento' => 1], '_grupo' => 'padrones'])->assertSessionHasNoErrors();

        $empresa = $this->empresa->refresh();
        $this->assertFalse($empresa->aviso('alta_por_verificar'), 'Se guardó lo del tema');
        $this->assertTrue($empresa->aviso('autorizacion_departamento'));
        $this->assertFalse($empresa->aviso('vale_taxi'), 'Lo de otro tema no cambia');
        $this->assertFalse($empresa->aviso('alta_provisional'), 'Lo de otro tema no cambia');
        $this->assertSame(['caja@ejemplo.com'], $empresa->destinatariosAviso('vale_taxi'), 'Los correos de otro tema se conservan');
    }

    public function test_la_insignia_cambiado_sale_solo_en_el_tema_con_ajustes_propios(): void
    {
        $insignia = fn (string $html, string $tema) => (bool) preg_match('#id="tema-'.$tema.'">.*?</a>#s', $html, $m) && str_contains($m[0], 'insignia-cambiado');

        $html = $this->actingAs($this->admin)->get('/configuracion')->getContent();
        $this->assertFalse($insignia($html, 'activos'));

        $this->actingAs($this->admin)->put('/configuracion/caseta', ['_tarjeta' => 'llaves', 'llaves_horas_aviso' => '8', 'llaves_horas_escalar' => '12'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->put('/configuracion/dos-pasos', ['dos_pasos' => 'todos'])->assertSessionHasNoErrors();

        $html = $this->actingAs($this->admin)->get('/configuracion')->getContent();
        $this->assertTrue($insignia($html, 'activos'));
        $this->assertTrue($insignia($html, 'seguridad'));
        $this->assertFalse($insignia($html, 'general'));
        $this->assertFalse($insignia($html, 'caseta'));

        // Restablecer lo quita
        $this->actingAs($this->admin)->put('/configuracion/restablecer', ['ajuste' => 'llaves']);
        $this->assertFalse($insignia($this->actingAs($this->admin)->get('/configuracion')->getContent(), 'activos'));
    }
}
