<?php

namespace Tests\Feature\Reservas;

use App\Models\Auditoria;
use App\Models\Empresa;
use App\Models\Espacio;
use App\Models\Reserva;
use App\Models\ReservaArea;
use App\Models\ReservaMontaje;
use App\Models\ReservaTipoUso;
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
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Editar y eliminar en Configuración de reservas (docs/tecnico/reservas.md,
 * «Editar, eliminar y ocultar»): botones Editar (lápiz) y Eliminar de reservas
 * (bote de basura) en las fichas de áreas; eliminar sin reservas borra la
 * configuración, con solo reservas pasadas la archiva y deja de verse, con
 * reservas próximas se bloquea; «Mostrar quitadas / dados de baja (N)»;
 * catálogos con Eliminar solo si nunca se usaron; permisos y otra empresa.
 *
 * Reloj fijo: lunes 2 de noviembre de 2026, 9:00 en la Ciudad de México.
 */
class ReservasEditarEliminarTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private const MIERCOLES = '2026-11-04';

    private Empresa $empresa;

    private Sede $centro;

    private Sede $playa;

    private User $admin;

    private User $rita;

    private Espacio $h101;

    private Espacio $h102;

    private Espacio $h103;

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
            $torre = $e->crear($this->admin, $this->centro, null, Espacio::EDIFICIO, ['nombre' => 'Torre A'], false);
            $this->h101 = $e->crear($this->admin, $this->centro, $torre, Espacio::AREA_ESPECIFICA, ['nombre' => 'Sala 101'], false);
            $this->h102 = $e->crear($this->admin, $this->centro, $torre, Espacio::AREA_ESPECIFICA, ['nombre' => 'Sala 102'], false);
            $this->h103 = $e->crear($this->admin, $this->centro, $torre, Espacio::AREA_ESPECIFICA, ['nombre' => 'Sala 103'], false);
        });
        auth()->forgetGuards();
    }

    // ------------------------------------------------------------------ Ayudas

    private function enEmpresa(callable $fn): mixed
    {
        return app(Tenant::class)->conEmpresa($this->empresa->id, $fn);
    }

    private function marcar(Espacio $espacio, array $config = []): ReservaArea
    {
        $area = $this->enEmpresa(function () use ($espacio, $config) {
            $this->actingAs($this->admin);
            $c = app(CatalogosReservas::class);
            $area = $c->marcarReservable($this->admin, $espacio);
            if ($config !== []) {
                $c->guardarArea($this->admin, $area, $config);
            }

            return $area->refresh();
        });
        auth()->forgetGuards();

        return $area;
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

    private function config(string $consulta = ''): TestResponse
    {
        $this->flushSession();

        return $this->actingAs($this->admin)->get('/reservas/configuracion'.$consulta)->assertOk();
    }

    private function montaje(string $nombre): ReservaMontaje
    {
        return $this->enEmpresa(fn () => ReservaMontaje::where('nombre', $nombre)->firstOrFail());
    }

    // ------------------------------------------------------------ Áreas: botones

    public function test_las_fichas_de_areas_usan_lapiz_para_editar_y_bote_para_eliminar_como_zonas_y_edificios(): void
    {
        $sala = $this->marcar($this->h101);

        foreach (['/reservas/configuracion', '/reservas?sede='.$this->centro->id] as $url) {
            $this->flushSession();
            $html = $this->actingAs($this->admin)->get($url)->assertOk()
                ->assertSee('title="Editar" aria-label="Editar Sala 101"', false)->assertSee('bi-pencil"', false)
                ->assertSee('href="'.route('reservas.configuracion.area', $sala->id).'"', false)
                ->assertSee('title="Eliminar de reservas" aria-label="Eliminar Sala 101 de reservas"', false)->assertSee('bi-trash3', false)
                ->assertDontSee('title="Configurar"', false)->assertDontSee('Quitar de reservas')->getContent();
            // Un solo botón para eliminar (no queda el tachado «Quitar» junto al bote)
            $this->assertSame(1, substr_count($html, 'action="'.route('reservas.configuracion.area.quitar', $sala->id).'"'));
        }

        // Editar navega a la configuración del área y guarda
        $this->flushSession();
        $this->actingAs($this->admin)->get(route('reservas.configuracion.area', $sala->id))->assertOk()->assertSee('Sala 101');
        $this->put(route('reservas.configuracion.area.guardar', $sala->id), ['aforo_maximo' => 14])
            ->assertRedirect(route('reservas.configuracion.area', $sala->id))->assertSessionHas('ok');
        $this->assertSame(14, $sala->refresh()->aforo_maximo);
    }

    public function test_la_confirmacion_dice_que_pasara_segun_sus_reservas(): void
    {
        $nueva = $this->marcar($this->h101);
        $pasada = $this->marcar($this->h102);
        $this->reservar($this->rita, $pasada, '10:00', '11:00')->assertSessionHasNoErrors();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 15:00:00', 'UTC'));
        $proxima = $this->marcar($this->h103);
        $this->reservar($this->rita, $proxima, '10:00', '11:00', ['fecha' => '2026-11-11'])->assertSessionHasNoErrors();

        $this->config()
            ->assertSee('¿Eliminar «Sala 101» de reservas? Nunca se ha reservado: se borra su configuración')
            ->assertSee('¿Eliminar «Sala 102» de reservas? Ya tiene reservas pasadas: dejará de verse y de ofrecerse, pero guardamos su historial')
            ->assertSee('«Sala 103» tiene 1 reserva próxima, así que todavía no se puede eliminar de reservas');
        $this->assertNotNull($nueva->refresh());
    }

    // ------------------------------------------------------------ Áreas: reglas

    public function test_eliminar_sin_reservas_borra_su_configuracion_y_desaparece_sin_tocar_zonas_y_edificios(): void
    {
        $sala = $this->marcar($this->h101, ['aforo_maximo' => 10, 'montajes' => [$this->montaje('Auditorio')->id => ['usar' => '1', 'aforo' => 8]]]);

        $this->flushSession();
        $this->actingAs($this->admin)->delete(route('reservas.configuracion.area.quitar', $sala->id))
            ->assertRedirect(route('reservas.configuracion'))
            ->assertSessionHas('ok', fn ($t) => str_contains($t, '«Sala 101» se eliminó de reservas. Sigue en Zonas y edificios.'));
        $this->assertNull($this->enEmpresa(fn () => ReservaArea::find($sala->id)));
        $this->assertSame(0, DB::table('reserva_area_montajes')->where('reserva_area_id', $sala->id)->count());
        $this->assertTrue($this->enEmpresa(fn () => Espacio::whereKey($this->h101->id)->where('activo', true)->exists()));
        $this->config()->assertDontSee('id="config-area-'.$sala->id.'"', false)->assertDontSee('data-ver-ocultas="areas"', false);
    }

    public function test_eliminar_con_solo_reservas_pasadas_la_archiva_y_deja_de_verse_hasta_mostrar_quitadas(): void
    {
        $sala = $this->marcar($this->h101, ['aforo_maximo' => 9]);
        $this->reservar($this->rita, $sala, '10:00', '11:00')->assertSessionHasNoErrors();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 15:00:00', 'UTC'));

        $this->flushSession();
        $this->actingAs($this->admin)->delete(route('reservas.configuracion.area.quitar', $sala->id), ['volver' => 'sede'])
            ->assertRedirect(route('reservas.index', ['sede' => $this->centro->id]))
            ->assertSessionHas('ok', fn ($t) => str_contains($t, 'se guardó con su historial') && str_contains($t, '«Mostrar quitadas»'))
            ->assertSessionHas('siguientes', fn ($s) => $s[0][0] === 'Ver áreas quitadas' && str_contains($s[0][1], 'ver%5B0%5D=areas'));
        $this->assertFalse($sala->refresh()->reservable);
        $this->assertSame(9, $sala->aforo_maximo);
        $this->assertSame(1, $this->enEmpresa(fn () => Reserva::where('reserva_area_id', $sala->id)->count()));

        // Por omisión ya no se ve en Configuración ni en las fichas de la sede
        $this->config()->assertDontSee('id="config-area-'.$sala->id.'"', false)
            ->assertSee('Mostrar quitadas <span class="conteo-pill">(1)</span>', false);
        $this->flushSession();
        $this->actingAs($this->admin)->get('/reservas?sede='.$this->centro->id)->assertOk()->assertDontSee('id="area-'.$sala->id.'"', false);

        // «Mostrar quitadas»: se ve con su etiqueta, Editar y Reactivar (sin bote)
        $html = $this->config('?ver[]=areas')->assertSee('id="config-area-'.$sala->id.'"', false)->assertSee('QUITADA')
            ->assertSee('Ocultar quitadas', false)->assertSee('aria-label="Reactivar Sala 101"', false)
            ->assertSee('aria-label="Editar Sala 101"', false)->assertDontSee('aria-label="Eliminar Sala 101 de reservas"', false)->getContent();
        $this->assertStringContainsString('href="'.route('reservas.configuracion').'#areas"', $html);

        // Editar una quitada avisa que no se ofrece; reactivarla la vuelve a mostrar
        $this->flushSession();
        $this->actingAs($this->admin)->get(route('reservas.configuracion.area', $sala->id))->assertOk()->assertSee('Esta área está quitada de reservas');
        $this->patch(route('reservas.configuracion.area.estado', $sala->id), ['reservable' => 1])->assertSessionHas('ok');
        $this->assertTrue($sala->refresh()->reservable);
        $this->config()->assertSee('id="config-area-'.$sala->id.'"', false)->assertDontSee('data-ver-ocultas="areas"', false);
    }

    public function test_volver_a_agregar_una_archivada_la_reactiva_con_su_configuracion(): void
    {
        $sala = $this->marcar($this->h101, ['aforo_maximo' => 7]);
        $this->reservar($this->rita, $sala, '10:00', '11:00')->assertSessionHasNoErrors();
        $this->travelTo(CarbonImmutable::parse('2026-11-10 15:00:00', 'UTC'));
        $this->flushSession();
        $this->actingAs($this->admin)->delete(route('reservas.configuracion.area.quitar', $sala->id))->assertSessionHas('ok');

        $this->config()->assertSee('Se quitó · vuelve con su configuración');
        $this->post('/reservas/configuracion/areas', ['espacios' => [$this->h101->id]])->assertRedirect();
        $this->assertTrue($sala->refresh()->reservable);
        $this->assertSame(7, $sala->aforo_maximo);
    }

    public function test_eliminar_con_reservas_proximas_se_bloquea_con_mensaje_y_enlace_al_calendario(): void
    {
        $sala = $this->marcar($this->h101);
        $this->reservar($this->rita, $sala, '10:00', '11:00')->assertSessionHasNoErrors();

        $this->flushSession();
        $this->actingAs($this->admin)->delete(route('reservas.configuracion.area.quitar', $sala->id))
            ->assertRedirect(route('reservas.configuracion'))
            ->assertSessionHas('error', 'No se eliminó «Sala 101» de reservas: tiene 1 reserva próxima; cancélala o reprográmala primero.')
            ->assertSessionHas('siguiente_paso', fn ($p) => $p['texto'] === 'Ver su calendario');
        $this->assertTrue($sala->refresh()->reservable);
        $this->config()->assertSee('id="config-area-'.$sala->id.'"', false);
    }

    // ------------------------------------------------------------ Catálogos

    public function test_catalogos_editar_con_lapiz_y_dados_de_baja_ocultos_hasta_mostrarlos(): void
    {
        $libre = $this->montaje('Libre');
        $tipo = $this->enEmpresa(fn () => ReservaTipoUso::firstOrFail());
        $this->flushSession();
        $this->actingAs($this->admin)->patch(route('reservas.configuracion.catalogo.estado', ['montajes', $libre->id]), ['activo' => 0])->assertSessionHas('ok');
        $this->patch(route('reservas.configuracion.catalogo.estado', ['tipos', $tipo->id]), ['activo' => 0])->assertSessionHas('ok');

        $this->config()->assertSee('aria-label="Editar Auditorio"', false)->assertDontSee('bi-pencil-square', false)
            ->assertDontSee('id="montajes-'.$libre->id.'"', false)->assertDontSee('id="tipos-'.$tipo->id.'"', false)
            ->assertSee('Mostrar dados de baja <span class="conteo-pill">(1)</span>', false);

        // Mostrar los montajes dados de baja no muestra los tipos (cada sección por separado)
        $this->config('?ver[]=montajes')->assertSee('id="montajes-'.$libre->id.'"', false)->assertSee('aria-label="Reactivar Libre"', false)
            ->assertDontSee('id="tipos-'.$tipo->id.'"', false);
        $this->config('?ver[]=montajes&ver[]=tipos')->assertSee('id="tipos-'.$tipo->id.'"', false);
        // Valores desconocidos se ignoran
        $this->config('?ver[]=otra&ver=x')->assertDontSee('id="montajes-'.$libre->id.'"', false);

        // Editar desde el lápiz guarda
        $this->put(route('reservas.configuracion.catalogo.actualizar', ['montajes', $libre->id]), ['nombre' => 'Libre (sin mobiliario)'])->assertSessionHas('ok');
        $this->assertSame('Libre (sin mobiliario)', $libre->refresh()->nombre);
    }

    public function test_eliminar_de_catalogo_solo_si_nunca_se_uso(): void
    {
        $auditorio = $this->montaje('Auditorio');
        $escuela = $this->montaje('Escuela');
        $coctel = $this->montaje('Cóctel');
        $sala = $this->marcar($this->h101, ['montajes' => [$auditorio->id => ['usar' => '1'], $escuela->id => ['usar' => '1']]]);
        [$tipoUsado, $tipoLibre] = $this->enEmpresa(fn () => ReservaTipoUso::orderBy('orden')->take(2)->get()->all());
        $servicios = $this->enEmpresa(function () {
            $this->actingAs($this->admin);
            $c = app(CatalogosReservas::class);

            return [$c->guardarCatalogo($this->admin, 'servicios', null, ['nombre' => 'Proyector']), $c->guardarCatalogo($this->admin, 'servicios', null, ['nombre' => 'Bocinas'])];
        });
        auth()->forgetGuards();
        [$proyector, $bocinas] = $servicios;
        $this->reservar($this->rita, $sala, '10:00', '11:00', ['montaje_id' => $escuela->id, 'tipo_uso_id' => $tipoUsado->id,
            'servicios' => [$proyector->id => ['cantidad' => 1]]])->assertSessionHasNoErrors();

        // En la pantalla: lo nunca usado tiene «Eliminar» definitivo; lo usado, un bote que ofrece la baja
        $this->config()
            ->assertSee('action="'.route('reservas.configuracion.catalogo.eliminar', ['montajes', $coctel->id]).'"', false)
            ->assertDontSee('action="'.route('reservas.configuracion.catalogo.eliminar', ['montajes', $auditorio->id]).'"', false)
            ->assertSee('«Auditorio» ya se usó en reservas o en la configuración de un área, así que no se puede eliminar')
            ->assertSee('¿Eliminar «Bocinas» definitivamente? Nunca se ha usado en una reserva. No se puede deshacer.');

        // Nunca usados: se eliminan de verdad (con auditoría)
        foreach ([['montajes', $coctel], ['tipos', $tipoLibre], ['servicios', $bocinas]] as [$clave, $registro]) {
            $this->delete(route('reservas.configuracion.catalogo.eliminar', [$clave, $registro->id]))
                ->assertRedirect(route('reservas.configuracion').'#cat-'.$clave)->assertSessionHas('ok', fn ($t) => str_contains($t, 'eliminado'));
            $this->assertNull($this->enEmpresa(fn () => $registro::find($registro->id)));
        }
        $this->assertSame(3, $this->enEmpresa(fn () => Auditoria::where('evento', 'reservas.catalogo_eliminado')->count()));

        // Usados (en el área, en una reserva, como servicio): no se eliminan y se explica por qué
        $casos = [
            ['montajes', $auditorio, 'ya se usó en la configuración de 1 área'],
            ['montajes', $escuela, 'ya se usó en 1 reserva y en la configuración de 1 área'],
            ['tipos', $tipoUsado, 'ya se usó en 1 reserva'],
            ['servicios', $proyector, 'ya se usó en 1 reserva'],
        ];
        foreach ($casos as [$clave, $registro, $texto]) {
            $this->flushSession();
            $this->actingAs($this->admin)->delete(route('reservas.configuracion.catalogo.eliminar', [$clave, $registro->id]))
                ->assertSessionHas('error', fn ($t) => str_contains($t, $texto) && str_contains($t, 'dalo de baja'));
            $this->assertNotNull($this->enEmpresa(fn () => $registro::find($registro->id)));
        }
        // Aunque esté dada de baja, la reserva conserva su tipo de uso y su servicio
        $r = $this->enEmpresa(fn () => Reserva::firstOrFail());
        $this->assertSame($tipoUsado->id, $r->tipo_uso_id);
    }

    public function test_los_catalogos_iniciales_no_vuelven_si_se_eliminan(): void
    {
        $this->flushSession();
        $this->actingAs($this->admin);
        foreach ($this->enEmpresa(fn () => ReservaMontaje::all()) as $m) {
            $this->delete(route('reservas.configuracion.catalogo.eliminar', ['montajes', $m->id]))->assertSessionHas('ok');
        }
        $this->config()->assertDontSee('id="montajes-', false);
        $this->assertSame(0, $this->enEmpresa(fn () => ReservaMontaje::count()));
    }

    // ------------------------------------------------------------ Permisos y empresa

    public function test_permisos_y_otra_empresa(): void
    {
        $sala = $this->marcar($this->h101);
        $coctel = $this->montaje('Cóctel');

        // Sin «configurar»: ni botones ni acciones
        $this->flushSession();
        $this->actingAs($this->rita)->get('/reservas')->assertOk()->assertDontSee('title="Editar"', false)->assertDontSee('title="Eliminar de reservas"', false);
        $this->delete(route('reservas.configuracion.area.quitar', $sala->id))->assertForbidden();
        $this->delete(route('reservas.configuracion.catalogo.eliminar', ['montajes', $coctel->id]))->assertForbidden();
        $this->get('/reservas/configuracion')->assertForbidden();

        // Configura solo en su sede: no elimina áreas de otra sede ni catálogos (son de la empresa)
        $rol = $this->enEmpresa(fn () => Rol::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Eventos playa', 'nivel_jerarquia' => 70]));
        foreach (['ver', 'crear', 'configurar'] as $accion) {
            $id = DB::table('modulo_acciones as ma')->join('modulos as m', 'm.id', '=', 'ma.modulo_id')->join('acciones as a', 'a.id', '=', 'ma.accion_id')
                ->where('m.clave', 'reservas')->where('a.clave', $accion)->value('ma.id');
            RolPermiso::create(['rol_id' => $rol->id, 'modulo_accion_id' => $id, 'alcance' => Alcance::Sede]);
        }
        $eventos = $this->crearUsuario($this->empresa, 'Eventos playa', $this->playa);
        app(Autorizador::class)->olvidar();
        $this->flushSession();
        $this->actingAs($eventos)->delete(route('reservas.configuracion.area.quitar', $sala->id))->assertForbidden();
        $this->delete(route('reservas.configuracion.catalogo.eliminar', ['montajes', $coctel->id]))->assertForbidden();
        $this->get('/reservas/configuracion')->assertOk()->assertDontSee('Sala 101')->assertDontSee('action="'.route('reservas.configuracion.catalogo.eliminar', ['montajes', $coctel->id]).'"', false);

        // Otra empresa: ni sus áreas ni sus catálogos existen para ella
        $otra = $this->crearEmpresa('Hotel Otro');
        $ajeno = $this->crearUsuario($otra, 'Administrador');
        $this->flushSession();
        $this->actingAs($ajeno)->delete(route('reservas.configuracion.area.quitar', $sala->id))->assertNotFound();
        $this->delete(route('reservas.configuracion.catalogo.eliminar', ['montajes', $coctel->id]))->assertNotFound();
        $this->delete(route('reservas.configuracion.catalogo.eliminar', ['salones', $coctel->id]))->assertNotFound();
        $this->assertNotNull($this->enEmpresa(fn () => ReservaArea::find($sala->id)));
        $this->assertNotNull($this->enEmpresa(fn () => ReservaMontaje::find($coctel->id)));
        // La empresa ajena no ve nuestras áreas (sus catálogos son los suyos)
        $this->flushSession();
        $this->actingAs($ajeno)->get('/reservas/configuracion')->assertOk()->assertDontSee('Sala 101')
            ->assertDontSee('id="montajes-'.$coctel->id.'"', false);
    }
}
