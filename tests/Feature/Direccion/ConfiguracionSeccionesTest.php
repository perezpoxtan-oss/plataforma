<?php

namespace Tests\Feature\Direccion;

use App\Models\Auditoria;
use App\Models\Empresa;
use App\Models\LostFoundUmbral;
use App\Models\Modulo;
use App\Models\Sede;
use App\Models\User;
use App\Services\Novedades\AjustesNovedades;
use App\Services\Procedimientos\AdministradorProcedimientos;
use App\Support\AjustesCaseta;
use App\Support\RelojVoBo;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Configuración por secciones (petición del dueño, 2026-10-15): índice con
 * anclas, ajustes que solo estaban en código (reloj del Vo.Bo., recordatorio
 * de procedimientos, nombres del menú), «Restablecer valor original» y permisos.
 */
class ConfiguracionSeccionesTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private User $admin;

    private User $director;

    private User $agente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->director = $this->crearUsuario($this->empresa, 'Director');
        $this->agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
    }

    public function test_la_pagina_lista_todas_las_secciones_con_indice_para_el_administrador(): void
    {
        $html = $this->actingAs($this->admin)->get('/configuracion')->assertOk()->getContent();

        foreach (['sec-general' => 'General', 'caseta' => 'Caseta y accesos', 'sec-rh' => 'Recepción y RR. HH.', 'sec-novedades' => 'Novedades',
            'sec-activos' => 'Activos', 'sec-transporte' => 'Transporte', 'sec-pases' => 'Pases de salida', 'sec-avisos' => 'Avisos por correo',
            'sec-cuentas' => 'Cuentas y seguridad'] as $ancla => $nombre) {
            $this->assertStringContainsString('href="#'.$ancla.'"', $html, "Falta {$nombre} en el índice");
            $this->assertStringContainsString('id="'.$ancla.'"', $html, "Falta la sección {$nombre}");
        }
        // Plataforma es solo del Super Administrador
        $this->assertStringNotContainsString('id="sec-plataforma"', $html);
        $this->assertStringNotContainsString('Respaldar ahora', $html);

        // Cada tarjeta nueva y los enlaces «Configurar en …» a la pantalla natural de cada ajuste
        foreach (['cfg-vobo', 'cfg-menu', 'cfg-procedimientos', 'cfg-llaves', 'cfg-responsivas', 'cfg-transporte', 'cfg-esperas', 'cfg-plazos', 'cfg-criterios',
            'cfg-impresion', 'cfg-sesion', 'config-cuentas', 'config-pases_salida', 'lost-found', 'novedades'] as $id) {
            $this->assertStringContainsString('id="'.$id.'"', $html, "Falta la tarjeta {$id}");
        }
        foreach (['Configurar en Responsables por departamento', 'Configurar en Candidatos → Documentos y plazos', 'Configurar en Candidatos → Criterios de entrevista',
            'Configurar en Etiquetas QR → Plantillas', 'Configurar en Empresas', 'Configurar circuito'] as $enlace) {
            $this->assertStringContainsString($enlace, $html);
        }
        // Cada ajuste dice su valor original; sin cambios no hay nada que restablecer
        $this->assertStringContainsString('Valor original: 24 y 72 horas.', $html);
        $this->assertStringContainsString('Valor original: 3 días.', $html);
        $this->assertStringNotContainsString('name="ajuste" value="vobo"', $html);
    }

    public function test_el_super_administrador_ve_la_seccion_plataforma_con_sus_enlaces(): void
    {
        $sa = $this->crearSuperadmin();
        $this->actingAs($sa)->get('/configuracion')->assertOk()
            ->assertSee('id="sec-plataforma"', false)->assertSee('Identidad de la plataforma')->assertSee('Catálogo comercial → Ajustes')
            ->assertSee('Correo de la plataforma')->assertSee('Respaldar ahora');
    }

    public function test_reloj_del_visto_bueno_configurable_se_guarda_y_se_aplica(): void
    {
        $this->actingAs($this->admin)->put('/configuracion/caseta', ['_tarjeta' => 'vobo', 'vobo_horas_amarillo' => '12', 'vobo_horas_rojo' => '36'])
            ->assertSessionHasNoErrors()->assertRedirect(route('configuracion.index').'#cfg-vobo');
        $this->assertSame(['vobo_horas_amarillo' => 12, 'vobo_horas_rojo' => 36], $this->empresa->refresh()->preferencias['caseta']);
        $this->assertTrue(Auditoria::where('evento', 'configuracion.caseta')->exists());

        $base = Carbon::parse('2026-10-15 08:00:00');
        $this->assertSame('ambar', RelojVoBo::deEmpresa($base, $this->empresa, $base->copy()->addHours(13))['nivel']);
        $this->assertSame('Esperando Vo.Bo. hace 1 día 13 h (más de 36 h)', RelojVoBo::deEmpresa($base, $this->empresa, $base->copy()->addHours(37))['texto']);
        // Sin empresa (Catálogo comercial) siguen los originales
        $this->assertSame('normal', RelojVoBo::de($base, $base->copy()->addHours(13))['nivel']);

        // El rojo debe ir después del amarillo
        $this->actingAs($this->admin)->put('/configuracion/caseta', ['_tarjeta' => 'vobo', 'vobo_horas_amarillo' => '48', 'vobo_horas_rojo' => '24'])
            ->assertSessionHasErrors(['vobo_horas_rojo' => 'Las horas para el rojo deben ser más que las del amarillo.']);
    }

    public function test_cada_tarjeta_guarda_solo_lo_suyo_y_conserva_lo_demas(): void
    {
        $this->actingAs($this->admin)->put('/configuracion/caseta', ['_tarjeta' => 'transporte', 'transporte_firmas' => 'guardia'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->put('/configuracion/caseta', ['_tarjeta' => 'llaves', 'llaves_horas_aviso' => '8', 'llaves_horas_escalar' => '12'])
            ->assertRedirect(route('configuracion.index').'#cfg-llaves');

        $this->assertSame(['transporte_firmas' => 'guardia', 'llaves_horas_aviso' => 8, 'llaves_horas_escalar' => 12], $this->empresa->refresh()->preferencias['caseta']);
    }

    public function test_recordatorio_de_procedimientos_cada_n_dias_por_empresa(): void
    {
        $this->assertSame(AdministradorProcedimientos::RECORDATORIO_CADA_DIAS, AjustesCaseta::valor($this->empresa, 'procedimientos_recordatorio_dias'));
        $this->actingAs($this->admin)->put('/configuracion/caseta', ['_tarjeta' => 'procedimientos', 'procedimientos_acuse' => 'firma', 'procedimientos_recordatorio_dias' => '7'])
            ->assertSessionHasNoErrors()->assertRedirect(route('configuracion.index').'#cfg-procedimientos');
        $this->assertSame(7, AjustesCaseta::valor($this->empresa->refresh(), 'procedimientos_recordatorio_dias'));

        $this->actingAs($this->admin)->put('/configuracion/caseta', ['_tarjeta' => 'procedimientos', 'procedimientos_recordatorio_dias' => '0'])
            ->assertSessionHasErrors(['procedimientos_recordatorio_dias' => 'Escribe un número entero de días, de 1 a 30.']);
    }

    public function test_nombres_del_menu_se_guardan_se_aplican_y_se_restablecen(): void
    {
        $modulo = Modulo::where('clave', 'rutas')->firstOrFail();
        $this->actingAs($this->admin)->get('/configuracion')->assertSee('id="menu_'.$modulo->id.'"', false)
            // La bitácora tiene su propio ajuste: no se repite en la lista
            ->assertDontSee('id="menu_'.Modulo::where('clave', 'novedades')->value('id').'"', false);

        $this->actingAs($this->admin)->put('/configuracion/menu', ['nombres' => [$modulo->id => 'Rutas del personal']])
            ->assertSessionHasNoErrors()->assertRedirect(route('configuracion.index').'#cfg-menu');
        $this->assertSame('Rutas del personal', DB::table('empresa_modulos')->where('empresa_id', $this->empresa->id)->where('modulo_id', $modulo->id)->value('nombre_visible'));
        $this->assertTrue(Auditoria::where('evento', 'configuracion.menu')->exists());
        // El menú ya lo muestra así
        $this->actingAs($this->admin)->get('/configuracion')->assertSee('Rutas del personal');

        $this->actingAs($this->admin)->put('/configuracion/menu', ['nombres' => [$modulo->id => '<script>']])->assertSessionHasErrors('nombres.'.$modulo->id);

        $this->actingAs($this->admin)->put('/configuracion/restablecer', ['ajuste' => 'menu'])
            ->assertRedirect(route('configuracion.index').'#cfg-menu')->assertSessionHas('ok', 'Listo: los nombres del menú volvieron a su valor original.');
        $this->assertNull(DB::table('empresa_modulos')->where('empresa_id', $this->empresa->id)->where('modulo_id', $modulo->id)->value('nombre_visible'));
    }

    public function test_restablecer_regresa_cada_tarjeta_a_su_valor_original_sin_tocar_las_demas(): void
    {
        $this->actingAs($this->admin)->put('/configuracion/caseta', ['transporte_firmas' => 'ninguna', 'vobo_horas_amarillo' => '10', 'vobo_horas_rojo' => '20']);
        $this->actingAs($this->admin)->get('/configuracion')->assertSee('name="ajuste" value="vobo"', false)->assertSee('name="ajuste" value="transporte"', false);

        $this->actingAs($this->admin)->put('/configuracion/restablecer', ['ajuste' => 'vobo'])->assertRedirect(route('configuracion.index').'#cfg-vobo');
        $this->assertSame(['transporte_firmas' => 'ninguna'], $this->empresa->refresh()->preferencias['caseta']);
        $this->assertTrue(Auditoria::where('evento', 'configuracion.restablecido')->exists());

        $this->actingAs($this->admin)->put('/configuracion/restablecer', ['ajuste' => 'transporte']);
        $this->assertArrayNotHasKey('caseta', $this->empresa->refresh()->preferencias ?? []);

        // Novedades, auditoría, avisos y Lost & Found
        $this->actingAs($this->admin)->put('/configuracion/novedades', ['nombre_opcion' => 'Incidentes', 'categoria_hallazgo_pc' => 'mantenimiento_pc']);
        $this->actingAs($this->admin)->put('/configuracion/auditoria', ['auditoria_sede' => 'propios']);
        $this->actingAs($this->admin)->put('/configuracion/avisos', ['avisos' => [], 'avisos_modulos' => ['pases_salida' => 0], 'destinatarios' => ['vale_taxi' => 'caja@ejemplo.com']]);
        $this->actingAs($this->admin)->put('/configuracion/lost-found', ['dias' => array_merge(LostFoundUmbral::POR_OMISION, ['ROPA' => 45])]);

        foreach (['novedades', 'auditoria', 'avisos', 'lost_found'] as $ajuste) {
            $this->actingAs($this->admin)->put('/configuracion/restablecer', ['ajuste' => $ajuste])->assertSessionHasNoErrors()->assertSessionHas('ok');
        }
        $empresa = $this->empresa->refresh();
        $this->assertSame('Novedades', app(AjustesNovedades::class)->nombreMenu($empresa->id));
        $this->assertArrayNotHasKey('auditoria_sede', $empresa->preferencias ?? []);
        $this->assertArrayNotHasKey('avisos', $empresa->preferencias ?? []);
        $this->assertTrue($empresa->aviso('vale_taxi'));
        $this->assertSame(['caja@ejemplo.com'], $empresa->destinatariosAviso('vale_taxi'), 'Los correos capturados se conservan');
        $this->assertSame(LostFoundUmbral::POR_OMISION, app(Tenant::class)->conEmpresa($empresa->id, fn () => LostFoundUmbral::vigentes()));

        $this->actingAs($this->admin)->put('/configuracion/restablecer', ['ajuste' => 'otra-cosa'])->assertSessionHasErrors('ajuste');
    }

    public function test_rol_que_solo_consulta_ve_las_secciones_pero_no_edita(): void
    {
        $html = $this->actingAs($this->director)->get('/configuracion')->assertOk()->getContent();
        $this->assertStringContainsString('id="sec-general"', $html);
        $this->assertStringNotContainsString('Guardar avisos', $html);
        $this->assertStringNotContainsString('Restablecer valor original', $html);
        $this->assertStringContainsString('Tu rol solo puede consultar esta configuración', $html);

        $this->actingAs($this->director)->put('/configuracion/caseta', ['vobo_horas_amarillo' => '10', 'vobo_horas_rojo' => '20'])->assertForbidden();
        $this->actingAs($this->director)->put('/configuracion/menu', ['nombres' => []])->assertForbidden();
        $this->actingAs($this->director)->put('/configuracion/restablecer', ['ajuste' => 'vobo'])->assertForbidden();
        $this->actingAs($this->director)->put('/configuracion/restablecer', ['ajuste' => 'lost_found'])->assertForbidden();
        $this->assertArrayNotHasKey('caseta', $this->empresa->refresh()->preferencias ?? []);

        // Sin permiso de Configuración no entra
        $this->actingAs($this->agente)->get('/configuracion')->assertForbidden();
        $this->actingAs($this->agente)->put('/configuracion/restablecer', ['ajuste' => 'avisos'])->assertForbidden();
    }

    public function test_reloj_de_un_vale_de_taxi_en_pantalla_usa_los_umbrales_de_la_empresa(): void
    {
        $this->actingAs($this->admin)->put('/configuracion/caseta', ['vobo_horas_amarillo' => '1', 'vobo_horas_rojo' => '2']);
        $this->actingAs($this->admin->fresh()); // como en cada petición: la empresa recién leída
        $vista = view('componentes.reloj-vobo', ['desde' => now()->subMinutes(90)])->render();
        $this->assertStringContainsString('data-reloj-vobo="ambar"', $vista);
        $this->assertStringContainsString('más de 1 h', $vista);
    }
}
