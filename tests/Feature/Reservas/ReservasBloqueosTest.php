<?php

namespace Tests\Feature\Reservas;

use App\Models\Auditoria;
use App\Models\Empresa;
use App\Models\Espacio;
use App\Models\Notificacion;
use App\Models\Reserva;
use App\Models\ReservaArea;
use App\Models\ReservaBloqueo;
use App\Models\Sede;
use App\Models\User;
use App\Services\Espacios\AdministradorEspacios;
use App\Services\Reservas\AjustesReservas;
use App\Services\Reservas\CatalogosReservas;
use App\Support\Menu\MisPendientes;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Bloqueos de sitios en Reservas de áreas (docs/tecnico/reservas.md,
 * «Bloqueos»): quién bloquea (general y suplente, responsable y suplente del
 * área, permiso reservas.bloquear), el bloqueo impide reservar (también entre
 * la zona y sus espacios interiores), el impacto antes de guardar, las
 * reservas «Afectada por bloqueo» con aviso y renglón de Mis pendientes,
 * moverla o dar de baja el bloqueo, el calendario («Bloqueado: motivo», horas
 * libres a un toque, responsables), otra empresa y las migraciones con down().
 *
 * Reloj fijo: lunes 2 de noviembre de 2026, 9:00 en la Ciudad de México.
 */
class ReservasBloqueosTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private const MIERCOLES = '2026-11-04';

    private Empresa $empresa;

    private Sede $centro;

    private User $admin;

    private User $julieta;

    private User $rita;

    private User $sergio;

    private User $agente;

    private ReservaArea $torre;

    private ReservaArea $salon;

    private ReservaArea $sala;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-02 15:00:00', 'UTC'));
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa('Hotel Ébano');
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->julieta = $this->crearUsuario($this->empresa, 'Jefe de departamento', $this->centro);
        $this->rita = $this->crearUsuario($this->empresa, 'Recursos Humanos', $this->centro);
        $this->sergio = $this->crearUsuario($this->empresa, 'Supervisor', $this->centro);
        $this->agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);

        $this->enEmpresa(function () {
            $this->actingAs($this->admin);
            // Responsable general: Julieta; suplente: el administrador
            app(AjustesReservas::class)->guardar($this->admin, $this->empresa->refresh(), [
                'responsable_id' => $this->julieta->id, 'suplente_id' => $this->admin->id, 'recordar_horas' => 4, 'escalar_horas' => 24,
            ]);
            $c = app(CatalogosReservas::class);
            $c->asegurar();
            $e = app(AdministradorEspacios::class);
            $torre = $e->crear($this->admin, $this->centro, null, Espacio::EDIFICIO, ['nombre' => 'Torre A'], false);
            $salon = $e->crear($this->admin, $this->centro, $torre, Espacio::AREA_ESPECIFICA, ['nombre' => 'Salón Maya'], false);
            $sala = $e->crear($this->admin, $this->centro, $torre, Espacio::AREA_ESPECIFICA, ['nombre' => 'Sala Ceiba'], false);
            $this->salon = $c->marcarReservable($this->admin, $salon);
            $this->sala = $c->marcarReservable($this->admin, $sala);
            // Sergio es el responsable de la Sala Ceiba (solo de ella)
            $semana = collect(range(1, 5))->mapWithKeys(fn ($d) => [$d => ['abierto' => '1', 'desde' => '08:00', 'hasta' => '20:00']])->all();
            $c->guardarArea($this->admin, $this->sala, ['aforo_maximo' => 12, 'confirmacion_inmediata' => '1', 'con_horario' => '1', 'horario' => $semana, 'responsable_id' => $this->sergio->id]);
            $c->guardarArea($this->admin, $this->salon, ['aforo_maximo' => 100, 'confirmacion_inmediata' => '1']);
            // La torre completa también se reserva (bloqueo cruzado con sus salas)
            $this->torre = $c->marcarReservable($this->admin, $torre, ReservaArea::COMPLETA);
        });
        $this->salon->refresh();
        $this->sala->refresh();
        auth()->forgetGuards();
    }

    // ------------------------------------------------------------------ Ayudas

    private function enEmpresa(callable $fn): mixed
    {
        return app(Tenant::class)->conEmpresa($this->empresa->id, $fn);
    }

    /** @param array<string, mixed> $datos */
    private function reservar(User $quien, ReservaArea $area, string $desde, string $hasta, array $datos = []): TestResponse
    {
        $this->flushSession();

        return $this->actingAs($quien)->post('/reservas', $datos + [
            'reserva_area_id' => $area->id, 'fecha' => self::MIERCOLES, 'hora_inicio' => $desde, 'hora_fin' => $hasta,
            'titulo' => 'Junta', 'asistentes' => 4, 'para_cliente' => '0',
        ]);
    }

    /** @param array<string, mixed> $datos */
    private function bloquear(User $quien, ReservaArea $area, string $desde = '08:00', string $hasta = '18:00', array $datos = []): TestResponse
    {
        $this->flushSession();

        return $this->actingAs($quien)->post('/reservas/bloqueos', $datos + [
            'reserva_area_id' => $area->id, 'fecha_desde' => self::MIERCOLES, 'hora_desde' => $desde, 'fecha_hasta' => self::MIERCOLES, 'hora_hasta' => $hasta,
            'motivo' => 'Mantenimiento del aire', 'impacto_visto' => 99,
        ]);
    }

    private function ultimoBloqueo(): ReservaBloqueo
    {
        return $this->enEmpresa(fn () => ReservaBloqueo::latest('id')->firstOrFail());
    }

    private function notificaciones(User $u, string $tipo): int
    {
        return Notificacion::withoutGlobalScopes()->where('user_id', $u->id)->where('tipo', $tipo)->count();
    }

    // -------------------------------------------------------------- Quién bloquea

    public function test_quien_puede_bloquear_general_responsable_del_area_y_permiso(): void
    {
        // El responsable general (sin permiso de bloquear) bloquea cualquier sitio
        $this->bloquear($this->julieta, $this->salon)->assertSessionHasNoErrors()->assertRedirect(route('reservas.bloqueos'));
        // El responsable de la sala solo la suya
        $this->bloquear($this->sergio, $this->sala, '08:00', '09:00')->assertSessionHasNoErrors();
        $this->bloquear($this->sergio, $this->salon, '19:00', '20:00')->assertSessionHasErrors('reserva_area_id');
        // Con el permiso reservas.bloquear (Administrador de fábrica)
        $this->bloquear($this->admin, $this->sala, '19:00', '20:00')->assertSessionHasNoErrors();
        // Sin designación ni permiso: no ve la pantalla ni puede bloquear
        $this->flushSession();
        $this->actingAs($this->agente)->get('/reservas/bloqueos')->assertForbidden();
        $this->actingAs($this->agente)->get('/reservas/bloqueos/nuevo')->assertForbidden();
        $this->bloquear($this->agente, $this->sala, '20:00', '21:00')->assertForbidden();
        $this->assertSame(3, $this->enEmpresa(fn () => ReservaBloqueo::count()));

        // La pestaña «Bloqueos» solo aparece a quien puede bloquear
        $this->flushSession();
        $this->actingAs($this->sergio)->get('/reservas/bloqueos')->assertOk()->assertSee('Bloquear un sitio')->assertSee('Mantenimiento del aire');
        $this->actingAs($this->rita)->get('/reservas/calendario')->assertOk()->assertDontSee(route('reservas.bloqueos'), false);

        // El formulario solo ofrece los sitios que le tocan
        $this->actingAs($this->sergio)->get('/reservas/bloqueos/nuevo')->assertOk()->assertSee('Sala Ceiba')->assertDontSee('Salón Maya');
    }

    public function test_validaciones_del_bloqueo(): void
    {
        $this->bloquear($this->julieta, $this->salon, '08:00', '18:00', ['motivo' => ''])->assertSessionHasErrors('motivo');
        $this->bloquear($this->julieta, $this->salon, '18:00', '08:00')->assertSessionHasErrors('fecha_hasta');
        $this->bloquear($this->julieta, $this->salon, '08:00', '09:00', ['fecha_desde' => '2026-11-01', 'fecha_hasta' => '2026-11-01'])->assertSessionHasErrors('fecha_hasta');
        // Todo el día de varios días
        $this->bloquear($this->julieta, $this->salon, '', '', ['todo_el_dia' => '1', 'fecha_hasta' => '2026-11-06'])->assertSessionHasNoErrors();
        $b = $this->ultimoBloqueo();
        $this->assertSame('2026-11-04 06:00:00', $b->desde->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-07 06:00:00', $b->hasta->utc()->format('Y-m-d H:i:s'));
    }

    // --------------------------------------------------- El bloqueo impide reservar

    public function test_mientras_esta_bloqueado_nadie_reserva_ni_la_zona_ni_sus_espacios(): void
    {
        $this->bloquear($this->julieta, $this->salon, '10:00', '14:00')->assertSessionHasNoErrors();

        // El sitio bloqueado
        $r = $this->reservar($this->rita, $this->salon, '11:00', '12:00')->assertSessionHasErrors('hora_inicio');
        $this->assertStringContainsString('Mantenimiento del aire', session('errors')->first('hora_inicio'));
        // La zona que lo contiene tampoco (comparte lugar)
        $this->reservar($this->rita, $this->torre, '13:00', '15:00')->assertSessionHasErrors('hora_inicio');
        // Otra sala de la torre sí, y el salón fuera del bloqueo también
        $this->reservar($this->rita, $this->sala, '11:00', '12:00')->assertSessionHasNoErrors();
        $this->reservar($this->rita, $this->salon, '14:00', '15:00')->assertSessionHasNoErrors();

        // Bloquear la zona bloquea sus espacios interiores
        $this->bloquear($this->julieta, $this->torre, '16:00', '18:00', ['motivo' => 'Evento de dirección'])->assertSessionHasNoErrors();
        $this->reservar($this->rita, $this->sala, '16:30', '17:00')->assertSessionHasErrors('hora_inicio');

        // El formulario lo muestra como bloqueado
        $this->flushSession();
        $ocupado = $this->actingAs($this->rita)->getJson('/reservas/disponibilidad?area='.$this->sala->id.'&fecha='.self::MIERCOLES)->assertOk()->json('ocupado');
        $this->assertContains('Evento de dirección', array_column($ocupado, 'bloqueado'));
        $this->assertContains('Torre A', array_column($ocupado, 'otro'));
    }

    // ------------------------------------------- Reservas existentes: impacto y avisos

    public function test_con_reservas_existentes_muestra_el_impacto_marca_y_avisa(): void
    {
        $this->reservar($this->rita, $this->sala, '10:00', '11:00', ['titulo' => 'Capacitación'])->assertSessionHasNoErrors();
        $reserva = $this->enEmpresa(fn () => Reserva::latest('id')->firstOrFail());

        // Impacto antes de guardar (con JavaScript)
        $this->flushSession();
        $this->actingAs($this->julieta)->getJson('/reservas/bloqueos/impacto?'.http_build_query([
            'reserva_area_id' => $this->sala->id, 'fecha_desde' => self::MIERCOLES, 'hora_desde' => '08:00', 'fecha_hasta' => self::MIERCOLES, 'hora_hasta' => '18:00',
        ]))->assertOk()->assertJsonPath('total', 1)->assertJsonPath('valido', true)
            ->assertJsonPath('texto', 'Hay 1 reserva en esas fechas; quedará «Afectada por bloqueo» y se avisará a quien la hizo para que la mueva o la dé de baja.');

        // Sin JavaScript: el primer «Guardar» solo muestra el impacto
        $this->bloquear($this->julieta, $this->sala, '08:00', '18:00', ['impacto_visto' => -1])->assertRedirect()->assertSessionHas('impacto_bloqueo');
        $this->assertSame(0, $this->enEmpresa(fn () => ReservaBloqueo::count()));
        $this->flushSession();
        $this->actingAs($this->julieta)->withSession(['impacto_bloqueo' => ['total' => 1, 'texto' => 'Hay 1 reserva en esas fechas', 'reservas' => ['RES-1 · Capacitación']]])
            ->get('/reservas/bloqueos/nuevo')->assertOk()->assertSee('Hay 1 reserva en esas fechas')->assertSee('name="impacto_visto" value="1"', false);

        // Con el impacto visto, entra: la reserva queda afectada (nada se da de baja solo)
        $this->bloquear($this->julieta, $this->sala, '08:00', '18:00', ['impacto_visto' => 1])->assertSessionHasNoErrors()
            ->assertSessionHas('ok', fn ($t) => str_contains($t, '1 reserva quedó afectada'));
        $bloqueo = $this->ultimoBloqueo();
        $reserva = $this->enEmpresa(fn () => $reserva->refresh());
        $this->assertSame($bloqueo->id, (int) $reserva->bloqueo_id);
        $this->assertSame(Reserva::CONFIRMADA, $reserva->estado);

        // Aviso a quien reservó y al responsable de la sala (no a quien bloqueó)
        $this->assertSame(1, $this->notificaciones($this->rita, 'reserva_bloqueada'));
        $this->assertSame(1, $this->notificaciones($this->sergio, 'reserva_bloqueada'));
        $this->assertSame(0, $this->notificaciones($this->julieta, 'reserva_bloqueada'));
        $this->assertTrue(Auditoria::where('evento', 'reservas.bloqueo_creado')->where('auditable_id', $bloqueo->id)->exists());

        // Mis pendientes, Mis reservas y la ficha lo dicen
        $this->flushSession();
        $this->actingAs($this->rita);
        $renglon = collect(app(MisPendientes::class)->para($this->rita)['items'])->firstWhere('clave', 'reservas_bloqueadas');
        $this->assertSame(1, $renglon['total']);
        $this->get('/reservas/mis-reservas?afectadas=1')->assertOk()->assertSee('Afectada por bloqueo')->assertSee('Capacitación');
        $this->get('/reservas/'.$reserva->id)->assertOk()->assertSee('Afectada por bloqueo:')->assertSee('Mantenimiento del aire');
        $this->get('/reservas/bloqueos')->assertForbidden();

        // Moverla fuera del bloqueo la deja de marcar
        $this->flushSession();
        $this->actingAs($this->rita)->put('/reservas/'.$reserva->id, [
            'reserva_area_id' => $this->sala->id, 'fecha' => self::MIERCOLES, 'hora_inicio' => '18:00', 'hora_fin' => '19:00',
            'titulo' => 'Capacitación', 'asistentes' => 4, 'para_cliente' => '0',
        ])->assertSessionHasNoErrors();
        $this->assertNull($this->enEmpresa(fn () => $reserva->refresh()->bloqueo_id));
        $this->assertSame([], collect(app(MisPendientes::class)->para($this->rita)['items'])->where('clave', 'reservas_bloqueadas')->all());
    }

    public function test_dar_de_baja_el_bloqueo_libera_el_horario_y_las_reservas_afectadas(): void
    {
        $this->reservar($this->rita, $this->sala, '10:00', '11:00')->assertSessionHasNoErrors();
        $reserva = $this->enEmpresa(fn () => Reserva::latest('id')->firstOrFail());
        $this->bloquear($this->sergio, $this->sala, '08:00', '12:00')->assertSessionHasNoErrors();
        $bloqueo = $this->ultimoBloqueo();
        $this->reservar($this->rita, $this->sala, '11:00', '12:00')->assertSessionHasErrors('hora_inicio');

        // Otro responsable no puede quitar el bloqueo de una sala que no es suya… pero el general sí
        $otro = $this->crearUsuario($this->empresa, 'Supervisor', $this->centro);
        $this->flushSession();
        $this->actingAs($otro)->patch("/reservas/bloqueos/{$bloqueo->id}/baja")->assertForbidden();

        $this->flushSession();
        $this->actingAs($this->julieta)->patch("/reservas/bloqueos/{$bloqueo->id}/baja", ['motivo_baja' => 'Terminó antes'])
            ->assertRedirect(route('reservas.bloqueos'))->assertSessionHas('ok', fn ($t) => str_contains($t, 'dado de baja'));
        $bloqueo = $this->enEmpresa(fn () => $bloqueo->refresh());
        $this->assertFalse($bloqueo->activo);
        $this->assertSame($this->julieta->id, (int) $bloqueo->dado_de_baja_por);
        $this->assertSame('Terminó antes', $bloqueo->motivo_baja);
        $this->assertNull($this->enEmpresa(fn () => $reserva->refresh()->bloqueo_id));
        $this->assertSame(1, $this->notificaciones($this->rita, 'reserva_bloqueo_quitado'));
        $this->assertTrue(Auditoria::where('evento', 'reservas.bloqueo_baja')->where('auditable_id', $bloqueo->id)->exists());

        // Ya se puede reservar y no se da de baja dos veces
        $this->reservar($this->rita, $this->sala, '11:00', '12:00')->assertSessionHasNoErrors();
        $this->flushSession();
        $this->actingAs($this->julieta)->patch("/reservas/bloqueos/{$bloqueo->id}/baja")->assertSessionHasErrors('bloqueo');
        // Queda en el historial
        $this->actingAs($this->julieta)->get('/reservas/bloqueos?ver=historial')->assertOk()->assertSee('Dado de baja')->assertSee('Terminó antes');
    }

    // ----------------------------------------------------------------- Calendario

    public function test_calendario_bloqueado_visible_para_todos_horas_libres_y_responsables(): void
    {
        $this->bloquear($this->julieta, $this->sala, '08:00', '12:00')->assertSessionHasNoErrors();

        // Quien solo reserva ve «Bloqueado: motivo» (sin enlace para gestionarlo)
        $this->flushSession();
        $dia = $this->actingAs($this->rita)->get('/reservas/calendario?vista=dia&fecha='.self::MIERCOLES.'&area='.$this->sala->id.'&sede='.$this->centro->id)->assertOk()
            ->assertSee('Bloqueado: Mantenimiento del aire')->assertSee('res-evento bloqueado', false)->assertDontSee('#bloqueo-', false)
            // Quién responde por la sala y quién es el general
            ->assertSee('Responsable de Sala Ceiba:')->assertSee($this->sergio->name)->assertSee('Responsable general de reservas:')->assertSee($this->julieta->name)
            // Horas libres a un toque: después del bloqueo sí, durante el bloqueo no
            ->assertSee(route('reservas.create', ['area' => $this->sala->id, 'fecha' => self::MIERCOLES, 'hora' => '12:00']))
            ->assertDontSee(route('reservas.create', ['area' => $this->sala->id, 'fecha' => self::MIERCOLES, 'hora' => '09:00']))
            ->assertDontSee('Bloquear este sitio');
        // En la semana también
        $this->get('/reservas/calendario?fecha='.self::MIERCOLES.'&area='.$this->sala->id)->assertOk()->assertSee('Bloqueado: Mantenimiento del aire')
            ->assertSee('Toca una hora libre para reservarla.');

        // Tocar una hora libre abre la reserva con área, día y horario puestos
        $this->get(route('reservas.create', ['area' => $this->sala->id, 'fecha' => self::MIERCOLES, 'hora' => '12:00']))->assertOk()
            ->assertSee('value="12:00"', false)->assertSee('value="13:00"', false)->assertSee('value="'.self::MIERCOLES.'"', false);

        // Quien puede bloquear la sala ve el botón y el enlace al bloqueo
        $this->flushSession();
        $this->actingAs($this->sergio)->get('/reservas/calendario?vista=dia&fecha='.self::MIERCOLES.'&area='.$this->sala->id)->assertOk()
            ->assertSee('Bloquear este sitio')->assertSee('#bloqueo-', false);

        // La ficha de la sala en la sede: responsable con suplente, «Bloqueado hoy» solo si es hoy, y el enlace «Bloquear este sitio»
        $this->get('/reservas?sede='.$this->centro->id.'&zona='.$this->torre->id)->assertOk()->assertSee('Responsable: '.$this->sergio->name.' · suplente '.$this->julieta->name)
            ->assertSee('Responsable general de reservas:')->assertSee('aria-label="Bloquear Sala Ceiba"', false)->assertDontSee('Bloqueado hoy');
    }

    // ------------------------------------------------------------------ Otra empresa

    public function test_otra_empresa_no_ve_ni_quita_bloqueos_ajenos(): void
    {
        $this->bloquear($this->julieta, $this->salon)->assertSessionHasNoErrors();
        $bloqueo = $this->ultimoBloqueo();
        $otra = $this->crearEmpresa('Hotel Otro');
        $ajeno = $this->crearUsuario($otra, 'Administrador');
        $this->flushSession();
        $this->actingAs($ajeno)->patch("/reservas/bloqueos/{$bloqueo->id}/baja")->assertNotFound();
        $this->actingAs($ajeno)->get('/reservas/bloqueos')->assertOk()->assertDontSee('Mantenimiento del aire');
        $this->bloquear($ajeno, $this->salon)->assertSessionHasErrors('reserva_area_id');
        $this->assertTrue($this->enEmpresa(fn () => $bloqueo->refresh()->activo));
    }

    // ------------------------------------------------------------------ Migraciones

    public function test_las_migraciones_se_revierten_y_se_vuelven_a_aplicar(): void
    {
        $permiso = $this->ruta('2026_11_24_000200_permiso_bloquear_reservas.php');
        $tabla = $this->ruta('2026_11_24_000100_crear_bloqueos_de_reservas.php');
        $tieneBloquear = fn () => DB::table('rol_permisos as rp')->join('modulo_acciones as ma', 'ma.id', '=', 'rp.modulo_accion_id')
            ->join('acciones as a', 'a.id', '=', 'ma.accion_id')->join('modulos as m', 'm.id', '=', 'ma.modulo_id')
            ->join('roles as r', 'r.id', '=', 'rp.rol_id')->where('m.clave', 'reservas')->where('a.clave', 'bloquear')->where('r.nombre', 'Administrador')->exists();
        $this->assertTrue($tieneBloquear());

        Artisan::call('migrate:rollback', ['--path' => $permiso, '--force' => true]);
        $this->assertFalse($tieneBloquear());
        $this->assertFalse(DB::table('acciones')->where('clave', 'bloquear')->exists());
        Artisan::call('migrate:rollback', ['--path' => $tabla, '--force' => true]);
        $this->assertFalse(Schema::hasTable('reserva_bloqueos'));
        $this->assertFalse(Schema::hasColumn('reservas', 'bloqueo_id'));

        Artisan::call('migrate', ['--path' => $tabla, '--force' => true]);
        Artisan::call('migrate', ['--path' => $permiso, '--force' => true]);
        $this->assertTrue(Schema::hasTable('reserva_bloqueos'));
        $this->assertTrue(Schema::hasColumn('reservas', 'bloqueo_id'));
        // Mismo alcance que «configurar»: el Administrador lo recupera
        $this->assertTrue($tieneBloquear());
    }

    private function ruta(string $archivo): string
    {
        return 'database/migrations/'.$archivo;
    }
}
