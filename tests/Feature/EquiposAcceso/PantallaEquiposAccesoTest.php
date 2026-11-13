<?php

namespace Tests\Feature\EquiposAcceso;

use App\Models\Auditoria;
use App\Models\ComandoEquipoAcceso;
use App\Models\EquipoAcceso;
use App\Models\Notificacion;
use App\Services\Relojes\MotorRelojes;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Decisión 10: Configuración → Caseta y accesos → Equipos de acceso (alta,
 * edición, baja, token, «Sincronizar», aviso de «sin conexión»), con
 * permisos `configuracion.ver` / `configuracion.editar` por sede, el jefe de
 * seguridad que solo consulta y la empresa de trabajo.
 */
class PantallaEquiposAccesoTest extends TestCase
{
    use CreaEquiposAcceso, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararEmpresa();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function alta(array $datos = []): TestResponse
    {
        return $this->actingAs($this->admin)->post(route('equipos-acceso.store'), $datos + [
            'sede_id' => $this->centro->id, 'marca' => 'zkteco', 'nombre' => 'Checador personal', 'numero_serie' => 'cqz7232460321',
            'puerta' => 'Acceso de personal', 'sentido' => 'ambos', '_dialogo' => 'crear',
        ]);
    }

    public function test_configuracion_lleva_a_equipos_de_acceso(): void
    {
        $this->actingAs($this->admin)->get(route('configuracion.tema', 'caseta'))->assertOk()
            ->assertSee('Equipos de acceso')->assertSee(route('equipos-acceso.index'), false);
        $this->actingAs($this->admin)->get(route('equipos-acceso.index'))->assertOk()
            ->assertSee('Nuevo equipo')->assertSee('Cuando un equipo se queda sin conexión');
    }

    public function test_alta_de_zkteco_con_pasos_para_configurarlo(): void
    {
        $this->alta()->assertSessionHasNoErrors()->assertRedirect();
        $equipo = $this->enEmpresa(fn () => EquipoAcceso::sole());
        $this->assertSame(['CQZ7232460321', 'zkteco', $this->centro->id, true], [$equipo->numero_serie, $equipo->marca, (int) $equipo->sede_id, $equipo->activo]);
        $this->assertSame(64, strlen($equipo->token_hash));
        $this->assertTrue(Auditoria::where('evento', 'equipos_acceso.creado')->where('user_id', $this->admin->id)->exists());

        $this->actingAs($this->admin)->get(route('equipos-acceso.index'))->assertOk()
            ->assertSee('Checador personal')->assertSee('SIN CONECTAR')->assertSee('Servidor en la nube')->assertSee('CQZ7232460321')
            ->assertSee('Sincronizar');
    }

    public function test_alta_de_hikvision_muestra_el_token_una_sola_vez(): void
    {
        $r = $this->alta(['marca' => 'hikvision', 'numero_serie' => 'DS-K1T341-0001', 'nombre' => 'Torniquete'])->assertSessionHasNoErrors();
        $token = session('token_equipo')['token'];
        $this->assertSame(40, strlen($token));
        $equipo = $this->enEmpresa(fn () => EquipoAcceso::sole());
        $this->assertSame(EquipoAcceso::hashToken($token), $equipo->token_hash);

        $this->followRedirects($r)->assertSee('cópialo ahora')->assertSee('/equipos-acceso/hikvision/'.$token)->assertDontSee('Sincronizar');
        $this->actingAs($this->admin)->get(route('equipos-acceso.index'))->assertOk()->assertDontSee($token)->assertSee('carga');

        // El token sirve: el equipo ya puede mandar eventos
        $this->colaborador('1001');
        $equipo->forceFill(['created_at' => now()->subDays(30)])->saveQuietly();
        $this->hik($equipo, $this->fixture('hikvision-evento.json'), $token)->assertOk();
        // «Generar otro token»: el anterior deja de servir
        $this->actingAs($this->admin)->post(route('equipos-acceso.token', $equipo->id))->assertSessionHasNoErrors();
        $nuevo = session('token_equipo')['token'];
        $this->hik($equipo, $this->fixture('hikvision-latido.json'), $token)->assertStatus(401);
        $this->hik($equipo, $this->fixture('hikvision-latido.json'), $nuevo)->assertOk();
    }

    public function test_numero_de_serie_repetido_aunque_sea_de_otra_empresa_sin_decir_de_quien(): void
    {
        $otra = $this->crearEmpresa('Corporativo Norte');
        $this->crearSede($otra, 'NTE');
        $this->equipo(datos: ['numero_serie' => 'CQZ7232460321'], empresa: $otra);

        $this->alta()->assertSessionHasErrors(['numero_serie' => 'Ese número de serie ya está registrado. Si es este mismo equipo, búscalo en la lista (puede estar dado de baja) y reactívalo.']);
        $this->alta(['numero_serie' => 'mal serie!'])->assertSessionHasErrors('numero_serie');
        $this->alta(['numero_serie' => 'CQZ0000000009', 'ips_permitidas' => '201.150.10.20, no-es-ip'])->assertSessionHasErrors('ips_permitidas');
        $this->alta(['numero_serie' => 'CQZ0000000009', 'sede_id' => $this->crearSede($otra, 'NT2')->id])->assertSessionHasErrors('sede_id');
        $this->assertSame(0, $this->enEmpresa(fn () => EquipoAcceso::count()));
    }

    public function test_editar_dar_de_baja_y_reactivar(): void
    {
        $equipo = $this->equipo();
        $this->actingAs($this->admin)->put(route('equipos-acceso.update', $equipo->id), [
            'sede_id' => $this->centro->id, 'nombre' => 'Checador cocina', 'numero_serie' => $equipo->numero_serie, 'sentido' => 'entrada', 'marca' => 'hikvision',
        ])->assertSessionHasNoErrors();
        $equipo->refresh();
        $this->assertSame(['Checador cocina', 'entrada', 'zkteco'], [$equipo->nombre, $equipo->sentido, $equipo->marca], 'La marca no cambia');

        $this->actingAs($this->admin)->patch(route('equipos-acceso.estado', $equipo->id), ['activo' => 0])->assertSessionHasNoErrors();
        $this->assertFalse($equipo->refresh()->activo);
        $this->zk('GET', '/iclock/getrequest', ['SN' => $equipo->numero_serie])->assertStatus(401);
        $this->actingAs($this->admin)->patch(route('equipos-acceso.estado', $equipo->id), ['activo' => 1]);
        $this->zk('GET', '/iclock/getrequest', ['SN' => $equipo->numero_serie])->assertOk();
        $this->assertTrue(Auditoria::where('evento', 'equipos_acceso.baja')->exists() && Auditoria::where('evento', 'equipos_acceso.reactivado')->exists());
    }

    public function test_sincronizar_pone_en_cola_y_lo_dice(): void
    {
        $equipo = $this->equipo();
        $juan = $this->colaborador('1001');
        $this->darCredencial($juan, '0012345678');
        $this->actingAs($this->admin)->post(route('equipos-acceso.sincronizar', $equipo->id))->assertSessionHasNoErrors()
            ->assertSessionHas('ok', fn ($t) => str_contains($t, '1 alta(s)'));
        $this->assertSame(1, $this->enEmpresa(fn () => ComandoEquipoAcceso::where('equipo_acceso_id', $equipo->id)->count()), 'El alta aún no entregada se reemplaza: el equipo recibe una');
        $this->assertTrue(Auditoria::where('evento', 'equipos_acceso.sincronizacion')->exists());

        $hik = $this->equipo('hikvision');
        $this->actingAs($this->admin)->post(route('equipos-acceso.sincronizar', $hik->id))->assertSessionHasErrors('sincronizar');
    }

    public function test_permisos_alcance_y_tenant(): void
    {
        $equipoCentro = $this->equipo();
        $equipoPlaya = $this->equipo(datos: ['numero_serie' => 'CQZ0000000002', 'nombre' => 'Checador playa'], sede: $this->playa);
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);

        $this->actingAs($agente)->get(route('equipos-acceso.index'))->assertForbidden();
        $this->actingAs($agente)->post(route('equipos-acceso.store'), [])->assertForbidden();

        // El jefe de seguridad consulta los de su sede y no cambia nada
        $this->actingAs($jefe)->get(route('equipos-acceso.index'))->assertOk()
            ->assertSee('Checador personal')->assertDontSee('Checador playa')->assertDontSee('Nuevo equipo');
        $this->actingAs($jefe)->patch(route('equipos-acceso.estado', $equipoCentro->id), ['activo' => 0])->assertForbidden();
        $this->actingAs($jefe)->post(route('equipos-acceso.sincronizar', $equipoCentro->id))->assertForbidden();

        // Equipo de otra empresa: como si no existiera
        $otra = $this->crearEmpresa('Corporativo Norte');
        $this->crearSede($otra, 'NTE');
        $ajeno = $this->equipo(datos: ['numero_serie' => 'CQZAJENO0001'], empresa: $otra);
        $this->actingAs($this->admin)->patch(route('equipos-acceso.estado', $ajeno->id), ['activo' => 0])->assertNotFound();
        $this->actingAs($this->admin)->put(route('equipos-acceso.update', $ajeno->id), ['nombre' => 'X'])->assertNotFound();
        $this->assertTrue(EquipoAcceso::withoutGlobalScopes()->find($ajeno->id)->activo);
        $this->actingAs($this->admin)->get(route('equipos-acceso.index'))->assertDontSee('CQZAJENO0001');
        $this->assertNotNull($equipoPlaya);
    }

    public function test_ajuste_de_horas_sin_conexion(): void
    {
        $this->actingAs($this->admin)->put(route('equipos-acceso.ajustes'), ['sin_conexion_horas' => 6])->assertSessionHasNoErrors();
        $this->assertSame(['sin_conexion_horas' => 6], $this->empresa->refresh()->preferencias['equipos_acceso']);
        $this->actingAs($this->admin)->put(route('equipos-acceso.ajustes'), ['sin_conexion_horas' => 0])->assertSessionHasErrors('sin_conexion_horas');
        $this->actingAs($this->admin)->put(route('equipos-acceso.ajustes'), ['sin_conexion_horas' => 2])->assertSessionHasNoErrors();
        $this->assertArrayNotHasKey('equipos_acceso', $this->empresa->refresh()->preferencias ?? [], 'El valor original no se guarda');
        $jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        $this->actingAs($jefe)->put(route('equipos-acceso.ajustes'), ['sin_conexion_horas' => 6])->assertForbidden();
    }

    public function test_sin_conexion_avisa_al_jefe_de_seguridad_y_al_doble_a_su_suplente(): void
    {
        $jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        $equipo = $this->equipo();
        $this->zk('GET', '/iclock/getrequest', ['SN' => $equipo->numero_serie])->assertOk();
        $correr = function () {
            Cache::flush();
            $this->app->forgetScopedInstances();
            app(MotorRelojes::class)->correrEmpresa($this->empresa->id);
        };
        $avisos = fn (string $tipo) => Notificacion::query()->withoutGlobalScopes()->where('tipo', $tipo)->where('referencia_tipo', 'equipo_sin_conexion')->where('referencia_id', $equipo->id)->count();
        $correr();
        $this->assertSame(0, $avisos('reloj_recordatorio'), 'En línea: nada');

        Carbon::setTestNow(now()->addHours(2)->addMinutes(10));
        $correr();
        $this->assertTrue(Notificacion::query()->withoutGlobalScopes()->where('user_id', $jefe->id)->where('tipo', 'reloj_recordatorio')
            ->where('titulo', 'Sin conexión: Checador personal (Acceso de personal)')->exists(), 'A las 2 h, al jefe de seguridad');
        $correr();
        $this->assertSame(1, $avisos('reloj_recordatorio'), 'No repite');

        Carbon::setTestNow(now()->addHours(2));
        $correr();
        $this->assertGreaterThan(0, $avisos('reloj_escalado'), 'Al doble, al suplente');

        // Vuelve a llamar: el asunto se resuelve
        $this->zk('GET', '/iclock/getrequest', ['SN' => $equipo->numero_serie])->assertOk();
        $correr();
        $this->actingAs($jefe)->get(route('equipos-acceso.index'))->assertOk()->assertSee('EN LÍNEA');
    }
}
