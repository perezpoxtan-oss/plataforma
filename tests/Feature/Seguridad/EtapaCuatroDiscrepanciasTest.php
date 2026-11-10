<?php

namespace Tests\Feature\Seguridad;

use App\Console\Commands\CrearDatosDemo;
use App\Models\Delegacion;
use App\Models\Empresa;
use App\Models\Notificacion;
use App\Models\RelojEspera;
use App\Models\SolicitudCuenta;
use App\Models\User;
use App\Services\Relojes\MotorRelojes;
use App\Support\Menu\MisPendientes;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Revisión de la Etapa 4 · puntos que el dueño pidió cerrar sin discrepancias:
 *  - D-01: el suplente que recibe «Pasa a ti» lo tiene en Mis pendientes y
 *    puede atenderlo (la revisión de los 27 relojes está en EtapaCuatroRelojesTest).
 *  - D-02: Altas por verificar, Cuentas por revisar y Procedimientos por
 *    aprobar cuentan con la misma regla de delegación que sus recordatorios.
 *  - D-04: Inicio calcula Mis pendientes una sola vez por petición.
 */
class EtapaCuatroDiscrepanciasTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $hotel;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Mail::fake();
        $this->sembrarCatalogo();
        $this->artisan('plataforma:demo', ['--password' => 'Prueba123!'])->assertSuccessful();
        $this->hotel = Empresa::where('nombre_comercial', CrearDatosDemo::EMPRESA)->firstOrFail();
        $this->admin = User::where('username', 'admin.demo')->firstOrFail();
        // Sin delegaciones de la demo: cada prueba pone las suyas
        DB::table('delegaciones')->delete();
        RelojEspera::query()->insert(['empresa_id' => $this->hotel->id, 'reloj' => MotorRelojes::ARRANQUE, 'referencia_tipo' => 'empresa',
            'referencia_id' => $this->hotel->id, 'desde' => now()->subYear(), 'created_at' => now(), 'updated_at' => now()]);
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

    /** @return array<string, array<string, mixed>> */
    private function pendientes(User $u): array
    {
        Cache::flush();
        $this->app->forgetScopedInstances();
        $this->actingAs($u);

        return collect(app(MisPendientes::class)->para($u)['items'])->keyBy('clave')->all();
    }

    private function correr(): void
    {
        Cache::flush();
        $this->app->forgetScopedInstances();
        app(MotorRelojes::class)->correrEmpresa($this->hotel->id);
    }

    /** @return list<string> referencias de los recordatorios del motor que recibió esta persona */
    private function recordatoriosDe(User $u): array
    {
        return Notificacion::query()->withoutGlobalScopes()->where('user_id', $u->id)->where('tipo', MotorRelojes::TIPO_RECORDATORIO)
            ->pluck('referencia_tipo')->map(fn ($t) => preg_replace('/^alta_.*/', 'alta', $t))->unique()->sort()->values()->all();
    }

    public function test_altas_cuentas_y_procedimientos_cuentan_con_la_misma_regla_de_delegacion_que_su_recordatorio(): void
    {
        $agente = User::where('username', 'agente.demo')->firstOrFail();
        $this->enHotel(fn () => SolicitudCuenta::create(['user_id' => $agente->id, 'tipo' => SolicitudCuenta::DOS_PASOS]));
        $suplente = $this->crearUsuario($this->hotel, 'Administrador');
        $renglones = ['altas', 'cuentas', 'procedimientos_aprobar'];

        // Sin delegación: los dos administradores ven lo mismo
        $antes = $this->pendientes($this->admin);
        foreach ($renglones as $r) {
            $this->assertGreaterThan(0, $antes[$r]['total'] ?? 0, "La demo debe traer «{$r}»");
            $this->assertSame($antes[$r]['total'], $this->pendientes($suplente)[$r]['total'] ?? 0, "«{$r}» igual para los dos administradores");
        }

        // El administrador delega en el otro: sus tres renglones pasan completos al delegado, con el mismo número
        $this->enHotel(fn () => Delegacion::create(['user_id' => $this->admin->id, 'delegado_id' => $suplente->id, 'desde' => now()->subHour(), 'hasta' => now()->addDays(10)]));
        $titular = $this->pendientes($this->admin);
        $delegado = $this->pendientes($suplente);
        foreach ($renglones as $r) {
            $this->assertSame(0, $titular[$r]['total'] ?? 0, "«{$r}» ya no espera a quien delegó");
            $this->assertSame($antes[$r]['total'], $delegado[$r]['total'] ?? 0, "«{$r}» del delegado: lo suyo y lo del titular, sin repetir");
        }

        // El recordatorio del motor sigue la misma regla: al delegado, no al titular
        Carbon::setTestNow(now()->addHours(25));
        $this->correr();
        $this->assertSame([], array_values(array_intersect(['alta', 'procedimiento_revision', 'solicitud_cuenta'], $this->recordatoriosDe($this->admin))));
        $this->assertSame(['alta', 'procedimiento_revision', 'solicitud_cuenta'], array_values(array_intersect(['alta', 'procedimiento_revision', 'solicitud_cuenta'], $this->recordatoriosDe($suplente))));
        // Y el color sale del mismo conjunto
        $delegado = $this->pendientes($suplente);
        foreach ($renglones as $r) {
            $this->assertSame('ambar', $delegado[$r]['nivel'], "«{$r}» en ámbar para el delegado");
        }
    }

    public function test_si_el_delegado_no_puede_atenderlo_se_queda_con_el_titular(): void
    {
        // El agente no verifica altas ni administra cuentas: lo del administrador se queda con él (número y recordatorio)
        $agente = User::where('username', 'agente.demo')->firstOrFail();
        $antes = $this->pendientes($this->admin);
        $this->enHotel(fn () => Delegacion::create(['user_id' => $this->admin->id, 'delegado_id' => $agente->id, 'desde' => now()->subHour(), 'hasta' => now()->addDays(10)]));
        $despues = $this->pendientes($this->admin);
        foreach (['altas', 'procedimientos_aprobar'] as $r) {
            $this->assertSame($antes[$r]['total'], $despues[$r]['total'] ?? 0, "«{$r}» se queda con el titular");
            $this->assertArrayNotHasKey($r, $this->pendientes($agente));
        }
        Carbon::setTestNow(now()->addHours(25));
        $this->correr();
        $this->assertContains('alta', $this->recordatoriosDe($this->admin));
        $this->assertNotContains('alta', $this->recordatoriosDe($agente));
    }

    public function test_inicio_calcula_mis_pendientes_una_sola_vez_por_peticion(): void
    {
        Cache::flush();
        DB::enableQueryLog();
        $this->actingAs($this->admin)->get('/')->assertOk()->assertSee('Mis pendientes');
        $consultas = collect(DB::getQueryLog())->pluck('query');
        DB::disableQueryLog();
        // La consulta de «Tickets canalizados a mí» sale una vez por cálculo de Mis pendientes: Inicio y el encabezado comparten el mismo
        $this->assertSame(1, $consultas->filter(fn ($q) => str_contains($q, 'SUM(CASE WHEN asignado_a = ?'))->count());
    }
}
