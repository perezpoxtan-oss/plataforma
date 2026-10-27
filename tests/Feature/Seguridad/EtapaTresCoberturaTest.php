<?php

namespace Tests\Feature\Seguridad;

use App\Models\Acceso;
use App\Models\Autorizacion;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\DepartamentoResponsable;
use App\Models\Empresa;
use App\Models\Notificacion;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\User;
use App\Services\Arranque\AsistenteArranque;
use App\Services\Autorizaciones\Autorizaciones;
use App\Services\Autorizaciones\Cobertura;
use App\Services\Colaboradores\AdministradorColaboradores;
use App\Services\Permisos\Autorizador;
use App\Services\Reservas\ResponsablesReservas;
use App\Support\AppMovil;
use App\Support\Menu\MisPendientes;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Etapa 3 · C-08 · Cobertura real de responsables (A-03, A-04, DD-11).
 *
 * Un departamento solo está cubierto en una sede si su responsable está
 * activo, tiene cuenta, puede entrar a esa sede y tiene «Responder». Se
 * repiten las tres pruebas de A-03 (otra sede, baja en cascada y cuenta dada
 * de baja) y se revisa cada vínculo de §9: caseta (visita y proveedor),
 * Responsables, Departamentos, Inicio, Mis pendientes, campana, Configuración,
 * Configura tu empresa, Pases de salida, Reservas, Entrevistas, informes y la app.
 */
class EtapaTresCoberturaTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private Sede $playa;

    private User $admin;

    private User $agente;

    private User $agentePlaya;

    private User $supervisorPlaya;

    private Departamento $recepcion;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa('Hotel Cobertura');
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->playa = $this->crearSede($this->empresa, 'PLA');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->agentePlaya = $this->crearUsuario($this->empresa, 'Agente', $this->playa);
        $this->supervisorPlaya = $this->crearUsuario($this->empresa, 'Supervisor', $this->playa);
        $this->recepcion = $this->enEmpresa(fn () => Departamento::create(['nombre' => 'Recepción']));
        $this->ajustes(['visitas_requieren_autorizacion' => true]);
    }

    private function enEmpresa(callable $fn): mixed
    {
        return app(Tenant::class)->conEmpresa($this->empresa->id, $fn);
    }

    /** @param array<string, mixed> $recepcion */
    private function ajustes(array $recepcion): void
    {
        $this->empresa->refresh();
        $actual = $this->empresa->preferencias['recepcion'] ?? [];
        $this->empresa->forceFill(['preferencias' => array_merge($this->empresa->preferencias ?? [], ['recepcion' => array_merge($actual, $recepcion)])])->save();
    }

    private function responsable(User $u, ?Sede $sede = null, bool $suplente = false, ?Departamento $d = null): DepartamentoResponsable
    {
        return $this->enEmpresa(fn () => DepartamentoResponsable::create(['departamento_id' => ($d ?? $this->recepcion)->id, 'user_id' => $u->id, 'sede_id' => $sede?->id, 'es_suplente' => $suplente]));
    }

    /** Jefe de Recepción limitado a Centro (como jefedepto.demo). */
    private function jefaCentro(): User
    {
        return $this->crearUsuario($this->empresa, 'Jefe de departamento', $this->centro);
    }

    private function visita(string $nombre, Sede $sede, ?User $quien = null, array $extra = []): TestResponse
    {
        return $this->actingAs($quien ?? ($sede->is($this->playa) ? $this->agentePlaya : $this->agente))->post('/accesos', $extra + [
            'sede_id' => $sede->id, 'tipo' => 'visitante', 'nombre' => $nombre, 'motivo_visita' => 'departamento',
            'departamento_id' => $this->recepcion->id, 'modo_arribo' => 'a_pie', 'identificacion' => 'ine',
        ]);
    }

    /** @return array{0: string, 1: ?string} */
    private function estado(string $nombre): array
    {
        return $this->enEmpresa(fn () => array_values(Acceso::where('nombre', mb_strtoupper($nombre))->firstOrFail()->only(['estado', 'autorizacion'])));
    }

    private function olvidar(): void
    {
        app(Autorizador::class)->olvidar();
        $this->app->forgetScopedInstances();
        Cache::flush();
    }

    // -------------------------------------------- A-03 · prueba 1: otra sede

    public function test_a03_otra_sede_la_visita_espera_al_supervisor_y_las_pantallas_lo_dicen(): void
    {
        $jefa = $this->jefaCentro();
        $this->responsable($jefa); // «Todas sus sedes», pero su cuenta solo entra a Centro (fila de antes de A-04)

        // Centro: espera a la jefa, como siempre
        $this->visita('Ana Centro', $this->centro)->assertSessionHas('ok');
        $this->assertSame(['pendiente', 'esperando'], $this->estado('Ana Centro'));

        // Playa: antes entraba directo (acceso 466 de A-03); ahora espera al supervisor de Playa, con aviso
        $this->visita('Beto Playa', $this->playa)->assertSessionHas('ok', fn ($m) => str_contains($m, 'Recepción no tiene quien autorice en esta sede'));
        $this->assertSame(['pendiente', Acceso::SIN_RESPONSABLE], $this->estado('Beto Playa'));
        $acceso = $this->enEmpresa(fn () => Acceso::where('nombre', 'BETO PLAYA')->firstOrFail());
        $this->assertSame(0, $this->enEmpresa(fn () => Autorizacion::where('acceso_id', $acceso->id)->count()), 'A la jefa de Centro no se le pide nada de Playa');
        $aviso = Notificacion::withoutGlobalScopes()->where('tipo', 'acceso_sin_cobertura')->where('referencia_id', $acceso->id)->get();
        $this->assertSame([$this->supervisorPlaya->id], $aviso->pluck('user_id')->all(), 'El aviso le llega al supervisor de Playa (no a la jefa ni al agente)');

        // Responsables: la fila se marca y el departamento avisa «Sin quien autorice en Playa»
        $this->actingAs($this->admin)->get('/autorizaciones/responsables')->assertOk()
            ->assertSee('(solo responde en '.$this->centro->nombre.')', false)
            ->assertSee('Sin quien autorice en '.$this->playa->nombre);
        // Departamentos: la tarjeta usa la misma regla
        $this->actingAs($this->admin)->get('/departamentos')->assertOk()->assertSee('Sin quien autorice en '.$this->playa->nombre);
        // Inicio: «Sin quien autorice en Recepción (Playa)»
        $this->actingAs($this->admin)->get('/')->assertOk()->assertSee('Sin quien autorice en Recepción ('.$this->playa->nombre.')');
        // Configuración: la verdad de hoy
        $this->actingAs($this->admin)->get('/configuracion')->assertOk()->assertSee('hoy 1 departamento no tiene');

        // La bitácora: reloj y a quién espera; el agente no puede autorizar, el supervisor sí
        $this->actingAs($this->agentePlaya)->get('/accesos?pestana=pendientes')->assertOk()
            ->assertSee('Sin quien autorice en Recepción: espera al supervisor o al jefe de seguridad')
            ->assertSee('espera a tu supervisor o al jefe de seguridad');
        $this->olvidar();
        $mis = collect(app(MisPendientes::class)->para($this->supervisorPlaya)['items'])->keyBy('clave');
        $this->assertSame(1, $mis['esperan_supervisor']['total']);
        $this->actingAs($this->supervisorPlaya)->patch("/accesos/{$acceso->id}/autorizar")->assertSessionHas('ok');
        $this->assertSame('en_sitio', $acceso->fresh()->estado);
        $this->assertNull($aviso->first()->fresh()->acciones, 'El aviso pierde su botón al autorizarse');
        $this->olvidar();
        $this->assertArrayNotHasKey('esperan_supervisor', collect(app(MisPendientes::class)->para($this->supervisorPlaya)['items'])->keyBy('clave')->all());
    }

    // --------------------------------- A-03 · prueba 2: baja en cascada

    public function test_a03_baja_en_cascada_pide_reemplazo_y_si_se_pierde_la_cobertura_avisa(): void
    {
        $julieta = $this->jefaCentro();
        $colab = $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => '501', 'nombre' => 'Julieta', 'apellido_paterno' => 'Pech', 'sede_id' => $this->centro->id]));
        $julieta->forceFill(['colaborador_id' => $colab->id])->save();
        $this->responsable($julieta, $this->centro);

        // Con Julieta activa la visita espera (acceso 465 de A-03)
        $this->visita('Carla Uno', $this->centro);
        $this->assertSame(['pendiente', 'esperando'], $this->estado('Carla Uno'));

        // Dar de baja a la colaboradora pide reemplazo (Etapa 2) y solo ofrece a quien puede responder
        $this->actingAs($this->admin)->patch("/colaboradores/{$colab->id}/estado", ['activo' => 0])->assertRedirect();
        $this->actingAs($this->admin)->get("/antes-de-dar-de-baja/colaboradores/{$colab->id}")->assertOk()
            ->assertSee('Es el único responsable de Recepción')
            ->assertSee($this->supervisorPlaya->name)->assertDontSee('>'.$this->agente->name.'<', false);
        $this->actingAs($this->admin)->patch("/colaboradores/{$colab->id}/estado", ['activo' => 0, 'revisado' => 1, 'reemplazo_id' => $this->agente->id])
            ->assertSessionHasErrors('reemplazo_id');

        // Una baja que no pasa por la pantalla (importación): la cuenta se da de baja sola y la cobertura se pierde
        $this->enEmpresa(fn () => app(AdministradorColaboradores::class)->cambiarEstado($this->admin, $colab, false));
        $this->assertFalse($julieta->fresh()->activo);
        $this->olvidar();
        $campana = Notificacion::withoutGlobalScopes()->where('tipo', 'cobertura_perdida')->where('user_id', $this->admin->id)->get();
        $this->assertCount(1, $campana);
        $this->assertStringContainsString('Sin quien autorice en Recepción ('.$this->centro->nombre.')', $campana->first()->titulo);
        $mis = collect(app(MisPendientes::class)->para($this->admin)['items'])->keyBy('clave');
        $this->assertSame(1, $mis['cobertura']['total']);
        $this->actingAs($this->admin)->get('/')->assertOk()->assertSee('Sin quien autorice en Recepción ('.$this->centro->nombre.')')->assertSee('Nombrar responsable');

        // La siguiente visita ya no entra sin autorización (acceso 464 de A-03): espera al supervisor
        $this->visita('Carla Dos', $this->centro);
        $this->assertSame(['pendiente', Acceso::SIN_RESPONSABLE], $this->estado('Carla Dos'));

        // Nombrar a otra persona resuelve el aviso
        $jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad');
        $this->actingAs($this->admin)->put("/autorizaciones/responsables/{$this->recepcion->id}", ['responsables' => [['user_id' => $jefe->id, 'sede_id' => '', 'es_suplente' => 0]]])
            ->assertSessionHasNoErrors();
        $this->assertNull($campana->first()->fresh()->acciones);
        $this->olvidar();
        $this->assertArrayNotHasKey('cobertura', collect(app(MisPendientes::class)->para($this->admin)['items'])->keyBy('clave')->all());
    }

    // ------------------------------- A-03 · prueba 3: cuenta dada de baja

    public function test_a03_cuenta_dada_de_baja_o_sin_permiso_no_cuenta_y_se_marca(): void
    {
        $director = $this->crearUsuario($this->empresa, 'Director');
        $this->responsable($director);
        $this->assertTrue($this->enEmpresa(fn () => app(Cobertura::class)->cubierto($this->recepcion->id, $this->playa->id)));

        $this->actingAs($this->admin)->patch("/usuarios/{$director->id}/estado", ['activo' => 0])->assertSessionHas('aviso');
        $this->assertFalse($director->fresh()->activo);
        $this->actingAs($this->admin)->get('/autorizaciones/responsables')->assertOk()->assertSee('(cuenta dada de baja)', false);
        $this->assertSame(1, Notificacion::withoutGlobalScopes()->where('tipo', 'cobertura_perdida')->count(), 'Avisa a quien nombra responsables');
        $this->visita('Dora Tres', $this->playa);
        $this->assertSame(['pendiente', Acceso::SIN_RESPONSABLE], $this->estado('Dora Tres'));

        // Sin permiso «Responder» (cambio de rol): tampoco cuenta y no le llegan avisos
        $sinPermiso = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->responsable($sinPermiso, $this->centro);
        $this->actingAs($this->admin)->get('/autorizaciones/responsables')->assertSee('(sin permiso para responder)', false);
        $this->visita('Eva Cuatro', $this->centro);
        $this->assertSame(['pendiente', Acceso::SIN_RESPONSABLE], $this->estado('Eva Cuatro'));
        $this->assertSame(0, Notificacion::withoutGlobalScopes()->where('user_id', $sinPermiso->id)->where('tipo', 'autorizacion_visita')->count());
    }

    // ----------------------------------------------------------- A-04

    public function test_a04_solo_se_nombra_en_las_sedes_de_la_cuenta_y_no_hay_404(): void
    {
        $jefa = $this->jefaCentro();
        $url = "/autorizaciones/responsables/{$this->recepcion->id}";
        $this->actingAs($this->admin)->put($url, ['responsables' => [['user_id' => $jefa->id, 'sede_id' => '', 'es_suplente' => 0]]])
            ->assertSessionHasErrors('responsables.0.sede_id');
        $this->assertStringContainsString('solo puede responder en '.$this->centro->nombre, session('errors')->first('responsables.0.sede_id'));
        $this->actingAs($this->admin)->put($url, ['responsables' => [['user_id' => $jefa->id, 'sede_id' => $this->playa->id, 'es_suplente' => 0]]])
            ->assertSessionHasErrors('responsables.0.sede_id');
        $this->actingAs($this->admin)->put($url, ['responsables' => [['user_id' => $jefa->id, 'sede_id' => $this->centro->id, 'es_suplente' => 0]]])
            ->assertSessionHasNoErrors();
        // El diálogo solo ofrece sus sedes (el navegador las acota con data-sedes)
        $this->actingAs($this->admin)->get('/autorizaciones/responsables')->assertSee('data-sedes="'.$this->centro->id.'"', false);

        // Una solicitud de Playa que le llegó antes (aviso viejo): mensaje claro, no 404
        $acceso = $this->enEmpresa(fn () => Acceso::create(['sede_id' => $this->playa->id, 'tipo' => 'visitante', 'nombre' => 'VIEJA', 'estado' => 'pendiente', 'entrada_at' => now()]));
        $a = $this->enEmpresa(fn () => Autorizacion::create(['sede_id' => $this->playa->id, 'departamento_id' => $this->recepcion->id, 'tipo' => 'visita',
            'acceso_id' => $acceso->id, 'solicitada_en' => now(), 'avisados' => [$jefa->id]]));
        $this->actingAs($jefa)->get("/autorizaciones/{$a->id}")->assertRedirect(route('autorizaciones.index'))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'tu cuenta no puede responder ahí'));
        // Alguien sin aviso sigue recibiendo 404 (no se filtra nada)
        $otro = $this->crearUsuario($this->empresa, 'Jefe de departamento', $this->centro);
        $this->actingAs($otro)->get("/autorizaciones/{$a->id}")->assertNotFound();
        // Su bandeja y Mis pendientes no le muestran lo de Playa
        $this->olvidar();
        $this->assertSame(0, collect(app(MisPendientes::class)->para($jefa)['items'])->firstWhere('clave', 'autorizaciones')['total'] ?? 0);
    }

    // ----------------------------------------------------------- DD-11

    public function test_dd11_es_configurable_y_el_proveedor_avisa_al_supervisor(): void
    {
        $this->actingAs($this->admin)->get('/autorizaciones/responsables')->assertOk()
            ->assertSee('Espera al supervisor o al jefe de seguridad de la sede')->assertSee('La visita pasa sin esperar');
        // El agente sigue igual de rápido: el mismo formulario; el botón anticipa que queda pendiente
        $this->actingAs($this->agentePlaya)->get('/accesos')->assertOk()->assertSee('data-sin-cobertura-espera="1"', false);

        $this->actingAs($this->admin)->put('/rh/recepcion/ajustes', ['visitas_requieren_autorizacion' => 1, 'proveedores_requieren_autorizacion' => 1,
            'contratistas_requieren_autorizacion' => 1, 'rh_autoriza_paso' => 1, 'sin_cobertura' => 'directo'])->assertSessionHasNoErrors();
        $this->assertSame('directo', $this->empresa->fresh()->preferencias['recepcion']['sin_cobertura']);
        $this->actingAs($this->agentePlaya)->get('/accesos')->assertSee('data-sin-cobertura-espera="0"', false);
        $this->visita('Fina Directa', $this->playa);
        $this->assertSame('en_sitio', $this->estado('Fina Directa')[0]);

        // El proveedor sin quien autorice siempre espera, y ahora el supervisor recibe el aviso
        $host = $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => '900', 'nombre' => 'Hilda', 'apellido_paterno' => 'Host', 'sede_id' => $this->playa->id]));
        $this->actingAs($this->agentePlaya)->post('/accesos', ['sede_id' => $this->playa->id, 'tipo' => 'proveedor', 'nombre' => 'Gas Rápido',
            'empresa_procedencia' => 'Gas SA', 'host_colaborador_id' => $host->id, 'departamento_id' => $this->recepcion->id, 'modo_arribo' => 'a_pie', 'identificacion' => 'ine'])->assertSessionHas('ok');
        $this->assertSame(['pendiente', Acceso::SIN_RESPONSABLE], $this->estado('Gas Rápido'));
        $this->assertSame(1, Notificacion::withoutGlobalScopes()->where('tipo', 'acceso_sin_cobertura')->where('user_id', $this->supervisorPlaya->id)->count());
    }

    // ------------------------------------------------ Vínculos (§9)

    public function test_vinculos_pases_reservas_entrevistas_configura_informes_y_app(): void
    {
        $jefa = $this->jefaCentro();
        $this->responsable($jefa, $this->centro);
        $cobertura = fn () => $this->enEmpresa(fn () => app(Cobertura::class));

        // Entrevistas, Reservas y Pases usan la misma lista de responsables
        $this->assertSame([$jefa->id], $this->enEmpresa(fn () => app(Autorizaciones::class)->responsablesDe($this->recepcion->id, $this->centro->id)->pluck('user_id')->all()));
        $this->assertSame([], $this->enEmpresa(fn () => app(Autorizaciones::class)->responsablesDe($this->recepcion->id, $this->playa->id)->pluck('user_id')->all()));
        $jefa->forceFill(['activo' => false])->save();
        $this->olvidar();
        $this->assertFalse($cobertura()->cubierto($this->recepcion->id, $this->centro->id));
        $this->assertSame([], $this->enEmpresa(fn () => app(ResponsablesReservas::class)->delDepartamento($this->recepcion->id, $this->centro->id, $this->empresa->id)
            ->pluck('id')->intersect([$jefa->id])->values()->all()), 'Reservas no manda la orden a una cuenta dada de baja');
        $jefa->forceFill(['activo' => true])->save();

        // Configura tu empresa no marca «hecho» mientras falte quien autorice en una sede
        $this->olvidar();
        $paso = collect(app(AsistenteArranque::class)->avance($this->admin, $this->empresa)['pasos'])->firstWhere('clave', 'departamentos');
        $this->assertFalse($paso['hecho']);
        $this->assertStringContainsString('Sin quien autorice en Recepción ('.$this->playa->nombre.')', $paso['texto']);

        // Informes y bitácora del día siguen cargando con visitas que esperan al supervisor
        $this->visita('Hugo Informe', $this->playa);
        foreach (['/informes/tablero', '/informes/bitacora-del-dia', '/accesos?pestana=pendientes'] as $ruta) {
            $this->actingAs($this->admin)->get($ruta)->assertOk();
        }

        // La app (misma pantalla, con su marca en el navegador): la campana trae el aviso del supervisor
        $this->actingAs($this->supervisorPlaya)->withHeader('User-Agent', AppMovil::MARCA_AGENTE.'1.0')->getJson('/notificaciones/resumen')->assertOk()
            ->assertJsonPath('lista.0.titulo', 'Espera tu autorización: HUGO INFORME');
    }

    public function test_la_fila_todas_sus_sedes_solo_cubre_donde_entra_y_el_rol_dado_de_baja_avisa(): void
    {
        $jefa = $this->jefaCentro();
        $this->responsable($jefa);
        // La fila «todas sus sedes» de una cuenta limitada a Centro no cubre Playa
        $this->assertSame([$this->recepcion->id => [$this->centro->id]], $this->enEmpresa(fn () => app(Cobertura::class)->sedesCubiertas()));
        $this->assertTrue($this->enEmpresa(fn () => app(Cobertura::class)->cubierto($this->recepcion->id, $this->centro->id)));
        $this->assertFalse($this->enEmpresa(fn () => app(Cobertura::class)->cubierto($this->recepcion->id, $this->playa->id)));
        // Rol dado de baja: pierde «Responder» y la campana avisa
        $rol = Rol::where('empresa_id', $this->empresa->id)->where('nombre', 'Jefe de departamento')->firstOrFail();
        $this->actingAs($this->admin)->put("/roles/{$rol->id}", ['nombre' => $rol->nombre, 'descripcion' => $rol->descripcion, 'nivel_jerarquia' => $rol->nivel_jerarquia, 'activo' => 0]);
        $this->assertFalse((bool) $rol->fresh()->activo);
        $this->assertSame(1, Notificacion::withoutGlobalScopes()->where('tipo', 'cobertura_perdida')->where('user_id', $this->admin->id)->count());
        $this->assertTrue(DB::table('departamento_responsables')->where('user_id', $jefa->id)->exists());
    }
}
