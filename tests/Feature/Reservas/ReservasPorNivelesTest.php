<?php

namespace Tests\Feature\Reservas;

use App\Models\Auditoria;
use App\Models\Empresa;
use App\Models\Espacio;
use App\Models\Reserva;
use App\Models\ReservaArea;
use App\Models\ReservaMontaje;
use App\Models\Rol;
use App\Models\RolPermiso;
use App\Models\Sede;
use App\Models\User;
use App\Services\Espacios\AdministradorEspacios;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use App\Services\Reservas\AjustesReservas;
use App\Services\Reservas\CatalogosReservas;
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
 * Reservas de áreas por niveles (docs/tecnico/reservas.md, «Espacios
 * interiores» y «Agregar y quitar áreas»): zona completa o por sus espacios
 * interiores (heredan la configuración de la zona), bloqueo cruzado entre la
 * zona y sus espacios (con montaje y desmontaje), fichas por niveles, agregar
 * varios a la vez, quitar con y sin reservas, permisos por sede y otra empresa,
 * y la migración del modo con su down().
 *
 * Reloj fijo: lunes 2 de noviembre de 2026, 9:00 en la Ciudad de México.
 */
class ReservasPorNivelesTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private const MIERCOLES = '2026-11-04';

    private Empresa $empresa;

    private Sede $centro;

    private Sede $playa;

    private User $admin;

    private User $rita;

    private Espacio $torre;

    private Espacio $piso1;

    private Espacio $h101;

    private Espacio $h102;

    private Espacio $h201;

    private Espacio $salon;

    private Espacio $club;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-11-02 15:00:00', 'UTC'));
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa('Hotel Ébano');
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->playa = $this->crearSede($this->empresa, 'PLA');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->rita = $this->crearUsuario($this->empresa, 'Recursos Humanos', $this->centro);

        $this->enEmpresa(function () {
            $this->actingAs($this->admin);
            app(AjustesReservas::class)->guardar($this->admin, $this->empresa->refresh(), ['responsable_id' => $this->admin->id, 'recordar_horas' => 4, 'escalar_horas' => 24]);
            app(CatalogosReservas::class)->asegurar();
            $e = app(AdministradorEspacios::class);
            // Torre A › Piso 1 › 101 y 102 (con su Baño) · Piso 2 › 201 · Salón Jade (zona sin espacios interiores)
            $this->torre = $e->crear($this->admin, $this->centro, null, Espacio::EDIFICIO, ['nombre' => 'Torre A'], false);
            $this->piso1 = $e->crear($this->admin, $this->centro, $this->torre, Espacio::AREA, ['nombre' => 'Piso 1'], false);
            $piso2 = $e->crear($this->admin, $this->centro, $this->torre, Espacio::AREA, ['nombre' => 'Piso 2'], false);
            $this->h101 = $e->crear($this->admin, $this->centro, $this->piso1, Espacio::AREA_ESPECIFICA, ['nombre' => 'Sala 101'], false);
            $this->h102 = $e->crear($this->admin, $this->centro, $this->piso1, Espacio::AREA_ESPECIFICA, ['nombre' => 'Sala 102'], false);
            $e->crear($this->admin, $this->centro, $this->h102, Espacio::SUBAREA, ['nombre' => 'Baño 102'], false);
            $this->h201 = $e->crear($this->admin, $this->centro, $piso2, Espacio::AREA_ESPECIFICA, ['nombre' => 'Sala 201'], false);
            $edificio = $e->crear($this->admin, $this->centro, null, Espacio::EDIFICIO, ['nombre' => 'Centro de convenciones'], false);
            $this->salon = $e->crear($this->admin, $this->centro, $edificio, Espacio::AREA_ESPECIFICA, ['nombre' => 'Salón Jade'], false);
            $this->club = $e->crear($this->admin, $this->playa, null, Espacio::EDIFICIO, ['nombre' => 'Club de playa'], false);
        });
        auth()->forgetGuards();
    }

    // ------------------------------------------------------------------ Ayudas

    private function enEmpresa(callable $fn): mixed
    {
        return app(Tenant::class)->conEmpresa($this->empresa->id, $fn);
    }

    private function area(Espacio $espacio): ?ReservaArea
    {
        return $this->enEmpresa(fn () => ReservaArea::firstWhere('espacio_id', $espacio->id));
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

    /** Torre A completa y, además, sus salas (bloqueo cruzado). */
    private function torreCompletaYSalas(): array
    {
        return $this->enEmpresa(function () {
            $this->actingAs($this->admin);
            $c = app(CatalogosReservas::class);
            $torre = $c->marcarReservable($this->admin, $this->torre, ReservaArea::COMPLETA);
            $c->guardarArea($this->admin, $torre, ['montaje_minutos' => 60, 'desmontaje_minutos' => 30]);

            return [$torre->refresh(), $c->marcarReservable($this->admin, $this->h101), $c->marcarReservable($this->admin, $this->h201)];
        });
    }

    // ------------------------------------------------------------ Modo y herencia

    public function test_por_espacios_interiores_marca_las_salas_y_heredan_la_configuracion_de_la_zona(): void
    {
        // La zona ya tenía configuración (aforo, horario, responsable, montajes, tarifas y montaje)
        $zona = $this->enEmpresa(function () {
            $this->actingAs($this->admin);
            $c = app(CatalogosReservas::class);
            $zona = $c->marcarReservable($this->admin, $this->torre);
            $c->guardarArea($this->admin, $zona, [
                'aforo_maximo' => 20, 'montaje_minutos' => 15, 'desmontaje_minutos' => 10, 'tarifa_hora' => 300, 'responsable_id' => $this->rita->id,
                'con_horario' => '1', 'horario' => [3 => ['abierto' => '1', 'desde' => '08:00', 'hasta' => '18:00']],
                'montajes' => [ReservaMontaje::where('nombre', 'Libre')->value('id') => ['usar' => '1', 'aforo' => 12]],
            ]);

            return $zona;
        });
        auth()->forgetGuards();

        $this->actingAs($this->admin)->post('/reservas/configuracion/areas', ['espacios' => [$this->torre->id], 'modo' => 'interiores', 'sede' => $this->centro->id])
            ->assertRedirect(route('reservas.index', ['sede' => $this->centro->id]))->assertSessionHas('ok');

        $this->assertSame(ReservaArea::INTERIORES, $zona->refresh()->modo);
        // Se marcan las salas (área específica), no los pisos ni el baño
        $this->assertNotNull($this->area($this->h101));
        $this->assertNotNull($this->area($this->h102));
        $this->assertNotNull($this->area($this->h201));
        $this->assertNull($this->area($this->piso1));
        $sala = $this->area($this->h101);
        $this->assertSame(20, $sala->aforo_maximo);
        $this->assertSame(15, $sala->montaje_minutos);
        $this->assertSame(10, $sala->desmontaje_minutos);
        $this->assertSame(['hora' => 300.0], $sala->tarifas());
        $this->assertSame($this->rita->id, (int) $sala->responsable_id);
        $this->assertSame([['08:00', '18:00']], $sala->horario['3']);
        $this->assertSame(12, $this->enEmpresa(fn () => $sala->montajes()->first()->pivot->aforo));

        // Valores iniciales: cada sala se edita aparte
        $this->put("/reservas/configuracion/areas/{$sala->id}", ['aforo_maximo' => 6])->assertSessionHasNoErrors();
        $this->assertSame(6, $sala->refresh()->aforo_maximo);
        $this->assertSame(20, $this->area($this->h102)->aforo_maximo);

        // «Copiar a sus espacios interiores» desde la zona
        $this->put("/reservas/configuracion/areas/{$zona->id}", ['aforo_maximo' => 30, 'copiar_interiores' => '1'])->assertSessionHas('ok');
        $this->assertSame(30, $sala->refresh()->aforo_maximo);

        // La zona solo agrupa: no se reserva ni sale en el calendario
        $this->reservar($this->rita, $zona, '10:00', '11:00')->assertSessionHasErrors(['reserva_area_id' => 'Esta zona se reserva por sus espacios interiores: elige uno de ellos.']);
        $this->reservar($this->rita, $sala, '10:00', '11:00')->assertSessionHasNoErrors();

        // Nada cambia en Zonas y edificios
        $this->assertSame(10, $this->enEmpresa(fn () => Espacio::count()));
        $this->assertTrue($this->enEmpresa(fn () => Espacio::whereKey($this->piso1->id)->value('activo')) == true);
    }

    public function test_una_zona_sin_espacios_interiores_siempre_se_reserva_completa(): void
    {
        $this->actingAs($this->admin)->post('/reservas/configuracion/areas', ['espacios' => [$this->salon->id], 'modo' => 'interiores'])
            ->assertRedirect();
        $this->assertSame(ReservaArea::COMPLETA, $this->area($this->salon)->modo);
    }

    // ------------------------------------------------------------ Bloqueo cruzado

    public function test_reservar_la_zona_completa_ocupa_sus_espacios_interiores_y_al_reves(): void
    {
        [$torre, $s101, $s201] = $this->torreCompletaYSalas();

        // Una sala reservada de 10:00 a 11:00 impide la zona completa a esa hora (la zona bloquea 60 min antes y 30 después)
        $this->reservar($this->rita, $s101, '10:00', '11:00')->assertSessionHasNoErrors();
        $this->reservar($this->rita, $torre, '11:15', '12:00')->assertSessionHasErrors('hora_inicio');
        $this->assertStringContainsString('comparte lugar', session('errors')->first('hora_inicio'));
        $this->reservar($this->rita, $torre, '12:00', '13:00')->assertSessionHasNoErrors();

        // La zona completa de 12:00 a 13:00 (bloquea 11:00–13:30) ocupa sus salas
        $this->reservar($this->rita, $s201, '13:00', '14:00')->assertSessionHasErrors('hora_inicio');
        $this->reservar($this->rita, $s201, '13:30', '14:00')->assertSessionHasNoErrors();
        // Dos salas distintas no chocan entre sí
        $this->reservar($this->rita, $s101, '13:30', '14:00')->assertSessionHasNoErrors();

        // El formulario muestra lo ocupado por la zona en la sala
        $ocupado = $this->getJson('/reservas/disponibilidad?area='.$s101->id.'&fecha='.self::MIERCOLES)->assertOk()->json('ocupado');
        $this->assertContains('Torre A', array_column($ocupado, 'otro'));

        // Cancelar la reserva de la zona libera sus salas
        $zona = $this->enEmpresa(fn () => Reserva::where('reserva_area_id', $torre->id)->firstOrFail());
        $this->flushSession();
        $this->actingAs($this->admin)->post("/reservas/{$zona->id}/cancelar", ['motivo' => 'Se cambió de fecha'])->assertSessionHasNoErrors();
        $this->reservar($this->rita, $s201, '12:00', '13:00')->assertSessionHasNoErrors();
    }

    // ------------------------------------------------------------ Fichas por niveles

    public function test_fichas_por_niveles_con_ficha_contenedora_migas_y_cascada(): void
    {
        $this->actingAs($this->admin)->post('/reservas/configuracion/areas', ['espacios' => [$this->torre->id, $this->salon->id], 'modo' => 'interiores'])->assertRedirect();
        $torre = $this->area($this->torre);

        $this->flushSession();
        $this->actingAs($this->rita)->get('/reservas?sede='.$this->centro->id)->assertOk()
            ->assertSee('Torre A')->assertSee('3 espacios reservables')->assertSee('Entrar a sus espacios')->assertSee('Salón Jade')
            ->assertDontSee('Sala 101');
        $this->get('/reservas?sede='.$this->centro->id.'&zona='.$torre->id)->assertOk()
            ->assertSee('class="migas migas-espacios"', false)->assertSee('Sala 101')->assertSee('Sala 102')->assertSee('Sala 201')
            ->assertSee('Piso 1')->assertSee('Piso 2')->assertDontSee('Salón Jade')->assertSee('aria-label="Reservar Sala 101"', false);

        // Formulario en cascada: la zona y luego el espacio (con su piso)
        $this->get('/reservas/nueva?zona='.$torre->id)->assertOk()->assertSee('data-res-zona', false)
            ->assertSee('Torre A (3 espacios)')->assertSee('Piso 1 › Sala 101')->assertSee('data-zona="'.$torre->id.'"', false);
        // El calendario no ofrece la zona que solo agrupa
        $this->get('/reservas/calendario')->assertOk()->assertDontSee('<option value="'.$torre->id.'"', false);
    }

    // ------------------------------------------------------------ Agregar y quitar

    public function test_agregar_varias_y_quitar_sin_reservas_borra_solo_la_configuracion(): void
    {
        $this->actingAs($this->admin)->get('/reservas?sede='.$this->centro->id)->assertOk();
        $this->post('/reservas/configuracion/areas', ['espacios' => [$this->h101->id, $this->h102->id], 'sede' => $this->centro->id])
            ->assertRedirect(route('reservas.index', ['sede' => $this->centro->id]))->assertSessionHas('ok', fn ($t) => str_contains($t, '2 áreas ya se pueden reservar'));
        $sala = $this->area($this->h101);

        // El diálogo muestra el árbol con casillas y las ya agregadas
        $this->flushSession();
        $this->actingAs($this->admin)->get('/reservas/configuracion')->assertOk()->assertSee('name="espacios[]"', false)
            ->assertSee('Ya se reserva')->assertSee('Por sus espacios interiores')->assertSee('3 espacios interiores');

        $this->delete("/reservas/configuracion/areas/{$sala->id}", ['volver' => 'sede'])
            ->assertRedirect(route('reservas.index', ['sede' => $this->centro->id]))->assertSessionHas('ok');
        $this->assertNull($this->area($this->h101));
        $this->assertTrue($this->enEmpresa(fn () => Espacio::whereKey($this->h101->id)->exists()));
        $this->assertTrue($this->enEmpresa(fn () => Auditoria::where('evento', 'reservas.area_quitada')->where('auditable_id', $sala->id)->exists()));

        // Se puede volver a agregar
        $this->post('/reservas/configuracion/areas', ['espacio_id' => $this->h101->id])->assertRedirect();
        $this->assertTrue($this->area($this->h101)->reservable);
    }

    public function test_quitar_con_reservas_proximas_se_bloquea_y_con_historial_se_desactiva_y_se_reactiva(): void
    {
        $sala = $this->enEmpresa(function () {
            $this->actingAs($this->admin);
            $c = app(CatalogosReservas::class);
            $sala = $c->marcarReservable($this->admin, $this->h101);
            $c->guardarArea($this->admin, $sala, ['aforo_maximo' => 8]);

            return $sala;
        });
        auth()->forgetGuards();
        $this->reservar($this->rita, $sala, '10:00', '11:00')->assertSessionHasNoErrors();
        $this->reservar($this->rita, $sala, '12:00', '13:00')->assertSessionHasNoErrors();

        $this->flushSession();
        $this->actingAs($this->admin)->delete("/reservas/configuracion/areas/{$sala->id}")
            ->assertRedirect(route('reservas.configuracion'))->assertSessionHas('error', fn ($t) => str_contains($t, 'Tiene 2 reservas próximas; cancélalas o reprográmalas primero.'));
        $this->assertTrue($sala->refresh()->reservable);

        // Pasan las reservas: ya es historial. Quitar la desactiva y conserva su configuración
        $this->travelTo(CarbonImmutable::parse('2026-11-10 15:00:00', 'UTC'));
        $this->flushSession();
        $this->actingAs($this->admin)->delete("/reservas/configuracion/areas/{$sala->id}")->assertSessionHas('ok', fn ($t) => str_contains($t, 'se conserva desactivada'));
        $this->assertFalse($sala->refresh()->reservable);
        $this->assertSame(2, $this->enEmpresa(fn () => Reserva::where('reserva_area_id', $sala->id)->count()));
        $this->flushSession();
        $this->actingAs($this->rita)->get('/reservas')->assertOk()->assertDontSee('Sala 101');

        // Volver a agregarla: se reactiva con su configuración
        $this->flushSession();
        $this->actingAs($this->admin)->get('/reservas/configuracion')->assertOk()->assertSee('Se quitó · vuelve con su configuración');
        $this->post('/reservas/configuracion/areas', ['espacios' => [$this->h101->id]])->assertRedirect();
        $this->assertTrue($sala->refresh()->reservable);
        $this->assertSame(8, $sala->aforo_maximo);
    }

    public function test_quitar_una_zona_por_interiores_quita_sus_espacios(): void
    {
        $this->actingAs($this->admin)->post('/reservas/configuracion/areas', ['espacios' => [$this->torre->id], 'modo' => 'interiores'])->assertRedirect();
        $zona = $this->area($this->torre);
        $this->reservar($this->rita, $this->area($this->h201), '10:00', '11:00')->assertSessionHasNoErrors();

        $this->flushSession();
        $this->actingAs($this->admin)->delete("/reservas/configuracion/areas/{$zona->id}")->assertSessionHas('error', fn ($t) => str_contains($t, 'Tiene 1 reserva próxima'));
        $this->assertNotNull($this->area($this->h101));

        $r = $this->enEmpresa(fn () => Reserva::firstOrFail());
        $this->post("/reservas/{$r->id}/cancelar", ['motivo' => 'Ya no se necesita'])->assertSessionHasNoErrors();
        $this->flushSession();
        $this->actingAs($this->admin)->delete("/reservas/configuracion/areas/{$zona->id}")->assertSessionHas('ok');
        $this->assertNull($this->area($this->torre));
        $this->assertNull($this->area($this->h101));
        // La sala con una reserva (cancelada) conserva su historial desactivada
        $this->assertFalse($this->area($this->h201)->reservable);
    }

    // ------------------------------------------------------------ Permisos y empresa

    public function test_permisos_por_sede_y_otra_empresa(): void
    {
        // Sin «configurar»: no ve Agregar ni Quitar y no puede usarlos
        $sala = $this->enEmpresa(function () {
            $this->actingAs($this->admin);

            return app(CatalogosReservas::class)->marcarReservable($this->admin, $this->h101);
        });
        auth()->forgetGuards();
        $this->actingAs($this->rita)->get('/reservas')->assertOk()->assertDontSee('Agregar área reservable')->assertDontSee('title="Quitar de reservas"', false);
        $this->post('/reservas/configuracion/areas', ['espacios' => [$this->h102->id]])->assertForbidden();
        $this->delete("/reservas/configuracion/areas/{$sala->id}")->assertForbidden();

        // Configura solo en la playa: no agrega ni quita en el centro
        $rol = $this->enEmpresa(fn () => Rol::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Eventos playa', 'nivel_jerarquia' => 70]));
        foreach (['ver', 'crear', 'configurar'] as $accion) {
            $id = DB::table('modulo_acciones as ma')->join('modulos as m', 'm.id', '=', 'ma.modulo_id')->join('acciones as a', 'a.id', '=', 'ma.accion_id')
                ->where('m.clave', 'reservas')->where('a.clave', $accion)->value('ma.id');
            RolPermiso::create(['rol_id' => $rol->id, 'modulo_accion_id' => $id, 'alcance' => Alcance::Sede]);
        }
        $eventos = $this->crearUsuario($this->empresa, 'Eventos playa', $this->playa);
        app(Autorizador::class)->olvidar();
        $this->flushSession();
        $this->actingAs($eventos)->post('/reservas/configuracion/areas', ['espacios' => [$this->h102->id]])->assertSessionHasErrors('espacio_id');
        $this->assertNull($this->area($this->h102));
        $this->delete("/reservas/configuracion/areas/{$sala->id}")->assertForbidden();
        $this->post('/reservas/configuracion/areas', ['espacios' => [$this->club->id], 'sede' => $this->playa->id])->assertRedirect();
        $this->assertNotNull($this->area($this->club));
        // Su diálogo solo ofrece espacios de la playa
        $this->flushSession();
        $this->actingAs($eventos)->get('/reservas/configuracion')->assertOk()->assertSee('Club de playa')->assertDontSee('Sala 102');

        // Otra empresa: los espacios y las áreas no existen para ella
        $otra = $this->crearEmpresa('Hotel Otro');
        $ajeno = $this->crearUsuario($otra, 'Administrador');
        $this->flushSession();
        $this->actingAs($ajeno)->post('/reservas/configuracion/areas', ['espacios' => [$this->h102->id]])->assertSessionHasErrors('espacio_id');
        $this->delete("/reservas/configuracion/areas/{$sala->id}")->assertNotFound();
        $this->assertNotNull($this->area($this->h101));
    }

    // ------------------------------------------------------------ Migración

    public function test_la_migracion_del_modo_se_revierte_y_se_vuelve_a_aplicar(): void
    {
        $this->assertTrue(Schema::hasColumn('reserva_areas', 'modo'));
        $ruta = 'database/migrations/2026_11_06_000100_modo_de_areas_reservables.php';
        Artisan::call('migrate:rollback', ['--path' => $ruta, '--force' => true]);
        $this->assertFalse(Schema::hasColumn('reserva_areas', 'modo'));
        Artisan::call('migrate', ['--path' => $ruta, '--force' => true]);
        $this->assertTrue(Schema::hasColumn('reserva_areas', 'modo'));
        // Las áreas que ya existían quedan «completa»
        $sala = $this->enEmpresa(function () {
            $this->actingAs($this->admin);

            return app(CatalogosReservas::class)->marcarReservable($this->admin, $this->h101);
        });
        $this->assertSame(ReservaArea::COMPLETA, $sala->refresh()->modo);
    }
}
