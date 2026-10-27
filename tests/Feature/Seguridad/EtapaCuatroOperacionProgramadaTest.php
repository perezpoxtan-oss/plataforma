<?php

namespace Tests\Feature\Seguridad;

use App\Console\Commands\CrearDatosDemo;
use App\Models\Empresa;
use App\Models\EquipoPc;
use App\Models\MovimientoTransporte;
use App\Models\Notificacion;
use App\Models\RelojEspera;
use App\Models\Reserva;
use App\Models\ReservaOrden;
use App\Models\RutaHorario;
use App\Models\Sede;
use App\Models\User;
use App\Services\RecorridosPc\AjustesEquiposPc;
use App\Services\Relojes\MotorRelojes;
use App\Services\Transporte\BitacoraTransporte;
use App\Support\Menu\MisPendientes;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Etapa 4 · DD-17 · Operación programada, con tiempo simulado: rutas sin
 * registro, órdenes de servicio antes del montaje, recargas y frecuencia de
 * revisión de los equipos de Protección Civil. Sobre los datos de demostración.
 */
class EtapaCuatroOperacionProgramadaTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $hotel;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Mail::fake();
        $this->sembrarCatalogo();
        $this->artisan('plataforma:demo', ['--password' => 'Prueba123!'])->assertSuccessful();
        $this->hotel = Empresa::where('nombre_comercial', CrearDatosDemo::EMPRESA)->firstOrFail();
        // El motor ya venía corriendo en esta empresa
        RelojEspera::query()->insert(['empresa_id' => $this->hotel->id, 'reloj' => MotorRelojes::ARRANQUE, 'referencia_tipo' => 'empresa',
            'referencia_id' => $this->hotel->id, 'desde' => now()->subYears(3), 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function enHotel(callable $fn): mixed
    {
        return app(Tenant::class)->conEmpresa($this->hotel->id, $fn);
    }

    private function correr(): void
    {
        Cache::flush();
        $this->app->forgetScopedInstances();
        app(MotorRelojes::class)->correrEmpresa($this->hotel->id);
    }

    /** @return array{0: int, 1: int} recordatorios y pasos al suplente del asunto (sin resolver) */
    private function avisos(string $referencia, int $id, bool $abiertos = false): array
    {
        $q = fn (string $tipo) => Notificacion::query()->withoutGlobalScopes()->where('tipo', $tipo)
            ->where('referencia_tipo', $referencia)->where('referencia_id', $id)->when($abiertos, fn ($q) => $q->whereNull('leida_en'))->count();

        return [$q(MotorRelojes::TIPO_RECORDATORIO), $q(MotorRelojes::TIPO_ESCALADO)];
    }

    private function renglon(User $u, string $clave): ?array
    {
        Cache::flush();
        $this->app->forgetScopedInstances();

        $this->actingAs($u);

        return collect($this->enHotel(fn () => app(MisPendientes::class)->para($u)['items']))->firstWhere('clave', $clave);
    }

    public function test_ruta_sin_registro_avisa_a_los_15_minutos_pasa_al_suplente_y_se_apaga_al_registrarla(): void
    {
        [$h, $sede] = $this->enHotel(function () {
            $h = RutaHorario::with('ruta')->get()->first(fn (RutaHorario $h) => $h->aplicaEn(1) && $h->aplicaEn(3));

            return [$h, Sede::findOrFail($h->ruta->sede_id)];
        });
        $hora = $this->enHotel(fn () => app(BitacoraTransporte::class)->horaDeCaseta($h));
        [$hh, $mm] = array_map('intval', explode(':', $hora));
        // Un miércoles futuro (sin movimientos de la demo), a la hora de la sede
        $programada = Carbon::parse('2026-12-02 00:00', $sede->zonaHoraria())->setTime($hh, $mm);

        Carbon::setTestNow($programada->copy()->addMinutes(10)->utc());
        $this->correr();
        $this->assertSame([0, 0], $this->avisos('ruta_sin_registro', $h->id), 'Antes de la tolerancia no avisa');

        Carbon::setTestNow($programada->copy()->addMinutes(16)->utc());
        $this->correr();
        [$recordatorios, $suplentes] = $this->avisos('ruta_sin_registro', $h->id);
        $this->assertGreaterThan(0, $recordatorios, 'A los 15 minutos avisa a la caseta');
        $this->assertSame(0, $suplentes);
        $guardia = User::findOrFail(Notificacion::query()->withoutGlobalScopes()->where('referencia_tipo', 'ruta_sin_registro')->value('user_id'));
        $this->assertNotNull($this->renglon($guardia, 'rutas_sin_registro'), 'Llega a Mis pendientes');

        $this->correr();
        $this->assertSame([$recordatorios, 0], $this->avisos('ruta_sin_registro', $h->id), 'No repite');

        Carbon::setTestNow($programada->copy()->addMinutes(16 + 61)->utc());
        $this->correr();
        $this->assertGreaterThan(0, $this->avisos('ruta_sin_registro', $h->id)[1], 'Una hora después pasa al suplente');

        // La caseta la registra: se apaga (otras rutas del día pueden seguir esperando)
        $antes = $this->renglon($guardia, 'rutas_sin_registro')['total'];
        $this->enHotel(fn () => MovimientoTransporte::create(['empresa_id' => $this->hotel->id, 'sede_id' => $sede->id, 'ruta_id' => $h->ruta_id,
            'ruta_horario_id' => $h->id, 'tipo_movimiento' => $h->ruta->sentido, 'estatus' => 'retraso', 'fecha' => $programada->toDateString()]));
        $this->correr();
        $this->assertSame([0, 0], $this->avisos('ruta_sin_registro', $h->id, abiertos: true));
        $this->assertSame($antes - 1, $this->renglon($guardia, 'rutas_sin_registro')['total'] ?? 0);
    }

    public function test_orden_de_servicio_un_dia_antes_y_al_montaje_pasa_al_general(): void
    {
        $orden = $this->enHotel(fn () => ReservaOrden::with('reserva')->where('estado', ReservaOrden::PENDIENTE)->firstOrFail());
        $inicio = Carbon::parse('2026-12-10 18:00:00');
        $this->enHotel(fn () => $orden->reserva->forceFill(['estado' => Reserva::CONFIRMADA, 'inicio' => $inicio, 'fin' => $inicio->copy()->addHours(4), 'montaje_minutos' => 120])->save());
        $montaje = $inicio->copy()->subMinutes(120);

        Carbon::setTestNow($montaje->copy()->subHours(25));
        $this->correr();
        $this->assertSame([0, 0], $this->avisos('reserva_orden', $orden->id));

        Carbon::setTestNow($montaje->copy()->subHours(23));
        $this->correr();
        $this->correr();
        $this->assertSame(0, $this->avisos('reserva_orden', $orden->id)[1]);
        $recordatorios = $this->avisos('reserva_orden', $orden->id)[0];
        $this->assertGreaterThan(0, $recordatorios, 'Un día antes del montaje se recuerda al departamento');

        Carbon::setTestNow($montaje->copy()->addMinutes(5));
        $this->correr();
        $this->assertSame($recordatorios, $this->avisos('reserva_orden', $orden->id)[0]);
        $this->assertGreaterThan(0, $this->avisos('reserva_orden', $orden->id)[1], 'Al empezar el montaje pasa al responsable general');

        $this->enHotel(fn () => $orden->forceFill(['estado' => ReservaOrden::LISTA])->save());
        $this->correr();
        $this->assertSame([0, 0], $this->avisos('reserva_orden', $orden->id, abiertos: true));
    }

    public function test_recarga_15_dias_antes_y_vencida_pasa_al_suplente(): void
    {
        $equipo = $this->enHotel(fn () => EquipoPc::where('categoria', 'EXTINTOR')->where('activo', true)->firstOrFail());
        $this->enHotel(fn () => $equipo->forceFill(['proxima_recarga' => '2026-12-20'])->save());

        Carbon::setTestNow('2026-12-04 12:00:00');
        $this->correr();
        $this->assertSame([0, 0], $this->avisos('equipo_pc_recarga', $equipo->id));

        Carbon::setTestNow('2026-12-06 12:00:00');
        $this->correr();
        $this->correr();
        $this->assertSame(0, $this->avisos('equipo_pc_recarga', $equipo->id)[1]);
        $this->assertGreaterThan(0, $this->avisos('equipo_pc_recarga', $equipo->id)[0], '15 días antes se avisa');
        $admin = User::findOrFail(Notificacion::query()->withoutGlobalScopes()->where('referencia_tipo', 'equipo_pc_recarga')->value('user_id'));
        $this->assertNotNull($this->renglon($admin, 'recargas_pc'));

        Carbon::setTestNow('2026-12-21 08:00:00');
        $this->correr();
        $this->assertGreaterThan(0, $this->avisos('equipo_pc_recarga', $equipo->id)[1], 'Vencida pasa al suplente');

        // Se recargó: nueva fecha a un año y se apaga
        $this->enHotel(fn () => $equipo->forceFill(['proxima_recarga' => '2027-12-20'])->save());
        $this->correr();
        $this->assertSame([0, 0], $this->avisos('equipo_pc_recarga', $equipo->id, abiertos: true));
    }

    public function test_frecuencia_de_revision_extintor_30_dias_y_configurable(): void
    {
        $extintores = $this->enHotel(fn () => EquipoPc::where('categoria', 'EXTINTOR')->where('activo', true)->pluck('id'));
        $botiquin = $this->enHotel(fn () => EquipoPc::where('categoria', 'BOTIQUIN')->where('activo', true)->value('id'));
        $this->assertNotEmpty($extintores);

        Carbon::setTestNow(now()->addDays(10));
        $this->correr();
        $this->assertSame(0, Notificacion::query()->withoutGlobalScopes()->where('referencia_tipo', 'equipo_pc_revision')->count(), 'Recién revisados no avisa');

        Carbon::setTestNow(now()->addDays(25));
        $this->correr();
        $avisados = Notificacion::query()->withoutGlobalScopes()->where('referencia_tipo', 'equipo_pc_revision')->where('tipo', MotorRelojes::TIPO_RECORDATORIO)->pluck('referencia_id')->unique();
        $this->assertEqualsCanonicalizing($extintores->all(), $avisados->values()->all(), 'De fábrica solo los extintores, a los 30 días');
        $this->assertSame(0, $this->avisos('equipo_pc_revision', $extintores->first())[1]);

        Carbon::setTestNow(now()->addDays(8));
        $this->correr();
        $this->assertGreaterThan(0, $this->avisos('equipo_pc_revision', $extintores->first())[1], 'Una semana después pasa al suplente');

        // Configuración: el botiquín cada 15 días; el extintor sin frecuencia
        $admin = User::where('username', 'admin.demo')->firstOrFail();
        $this->actingAs($admin)->put(route('configuracion.equipos_pc'), ['frecuencia_dias' => ['EXTINTOR' => 0, 'BOTIQUIN' => 15]])
            ->assertRedirect()->assertSessionHasNoErrors();
        $this->assertEquals(['BOTIQUIN' => 15, 'EXTINTOR' => 0], array_intersect_key(app(AjustesEquiposPc::class)->frecuencias($this->hotel->refresh()), ['BOTIQUIN' => 1, 'EXTINTOR' => 1]));
        $this->actingAs($admin)->put(route('configuracion.equipos_pc'), ['frecuencia_dias' => ['BOTIQUIN' => 'mucho']])->assertSessionHasErrors('frecuencia_dias.BOTIQUIN');
        $this->correr();
        $this->assertSame([0, 0], $this->avisos('equipo_pc_revision', $extintores->first(), abiertos: true), 'Sin frecuencia, se apaga');
        $this->assertGreaterThan(0, $this->avisos('equipo_pc_revision', $botiquin)[0]);

        $this->actingAs($admin)->get(route('configuracion.index'))->assertOk()->assertSee('frecuencia de revisión', false)
            ->assertSee('Valor original: extintores cada 30 días', false);
        $this->actingAs($admin)->put(route('configuracion.restablecer'), ['ajuste' => 'equipos_pc'])->assertSessionHas('ok');
        $this->assertSame(30, app(AjustesEquiposPc::class)->frecuencias($this->hotel->refresh())['EXTINTOR']);
    }
}
