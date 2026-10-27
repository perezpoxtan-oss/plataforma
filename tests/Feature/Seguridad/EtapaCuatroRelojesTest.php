<?php

namespace Tests\Feature\Seguridad;

use App\Console\Commands\CrearDatosDemo;
use App\Models\Acceso;
use App\Models\Auditoria;
use App\Models\Empresa;
use App\Models\EquipoResponsiva;
use App\Models\Gafete;
use App\Models\Notificacion;
use App\Models\RelojEspera;
use App\Models\Responsiva;
use App\Models\SolicitudCuenta;
use App\Models\User;
use App\Models\Vacante;
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
 * Etapa 4 · C-22 · Un solo motor «Reloj y suplente», con tiempo simulado.
 *
 * Sobre los datos de demostración (hotel, corporativo, condominio, Esencial y
 * Profesional) más lo que la demo no trae (cuentas, caseta, vacantes,
 * activos que no regresaron, faltantes), se avanza el reloj: nada avisa el
 * día que arranca el motor, a las horas del recordatorio se recuerda, a las
 * del suplente pasa al suplente, y volver a correr no repite ningún aviso.
 */
class EtapaCuatroRelojesTest extends TestCase
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

    /** Corre el motor en todas las empresas y en la plataforma, como lo haría la campana de cada una. */
    private function correrTodo(): void
    {
        Cache::flush();
        $this->app->forgetScopedInstances();
        $motor = app(MotorRelojes::class);
        foreach (Empresa::where('activo', true)->pluck('id') as $id) {
            $motor->correrEmpresa((int) $id);
        }
        $motor->correrEmpresa(null);
    }

    private function avisosDelMotor(): int
    {
        return Notificacion::query()->withoutGlobalScopes()->whereIn('tipo', [MotorRelojes::TIPO_RECORDATORIO, MotorRelojes::TIPO_ESCALADO])->count();
    }

    /** Lo que la demo no trae: una petición de cuenta, la caseta de noche, vacantes, activos y un faltante. */
    private function prepararLoQueFalta(): void
    {
        $this->enHotel(function () {
            $agente = User::where('username', 'agente.demo')->where('empresa_id', $this->hotel->id)->firstOrFail();
            SolicitudCuenta::create(['user_id' => $agente->id, 'tipo' => SolicitudCuenta::DOS_PASOS]);

            $base = Acceso::where('estado', 'en_sitio')->where('movimiento', 'entrada')->firstOrFail();
            $pedido = $base->replicate();
            $pedido->forceFill(['estado' => 'pendiente', 'nombre' => 'PIPA DE GAS', 'entrada_at' => now(), 'supervisor_pedido_en' => now(), 'creado_por' => $agente->id])->save();
            $excepcion = $base->replicate();
            $excepcion->forceFill(['nombre' => 'TÉCNICO URGENTE', 'entrada_at' => now(), 'autorizado_at' => now(), 'autorizacion' => Acceso::POR_EXCEPCION, 'excepcion_por_revisar' => true])->save();
            $origen = $base->replicate();
            $origen->forceFill(['tipo' => 'proveedor', 'nombre' => 'PROVEEDOR FUERA', 'entrada_at' => now()])->save();
            $fuera = $origen->replicate();
            $fuera->forceFill(['movimiento' => 'salida_temporal', 'acceso_origen_id' => $origen->id, 'entrada_at' => now()])->save();

            $vacante = Vacante::firstOrFail();
            $propuesta = $vacante->replicate(['codigo']);
            $propuesta->forceFill(['codigo' => 'VAC-E4-1', 'titulo' => 'Vacante propuesta de prueba', 'estado' => 'borrador', 'propuesta_en' => now(),
                'propuesta_por' => User::where('username', 'jefedepto.demo')->value('id')])->save();
            $cubierta = $vacante->replicate(['codigo']);
            $cubierta->forceFill(['codigo' => 'VAC-E4-2', 'titulo' => 'Vacante cubierta de prueba', 'estado' => 'publicada', 'cubierta_en' => now()])->save();

            Gafete::where('estado', 'disponible')->firstOrFail()->forceFill(['estado' => 'no_regreso'])->save();
            $lote = Responsiva::where('estado', Responsiva::EN_CAMPO)->firstOrFail();
            EquipoResponsiva::where('responsiva_id', $lote->id)->whereNull('devuelto_en')->firstOrFail()
                ->forceFill(['faltante_reportado_en' => now(), 'faltante_reportado_por' => $agente->id])->save();
        });
    }

    public function test_los_relojes_recuerdan_y_pasan_al_suplente_una_sola_vez_con_tiempo_simulado(): void
    {
        $this->prepararLoQueFalta();
        $this->crearSuperadmin();
        $this->crearSuperadmin();
        $inicio = now()->startOfMinute();
        Carbon::setTestNow($inicio);

        // El día que arranca no sale nada de golpe (lo viejo cuenta desde hoy)
        $this->correrTodo();
        $this->assertSame(0, $this->avisosDelMotor());
        $this->assertTrue(RelojEspera::where('reloj', MotorRelojes::ARRANQUE)->where('referencia_id', $this->hotel->id)->exists());

        // Pasado el recordatorio (24 h de fábrica; minutos y horas propias en la caseta)
        Carbon::setTestNow($inicio->copy()->addHours(25));
        $this->correrTodo();
        $recordatorios = Notificacion::query()->withoutGlobalScopes()->where('tipo', MotorRelojes::TIPO_RECORDATORIO)->count();
        $this->assertGreaterThan(0, $recordatorios);
        $this->assertSame([], Notificacion::query()->withoutGlobalScopes()->where('tipo', MotorRelojes::TIPO_ESCALADO)
            ->whereNotIn('referencia_tipo', ['acceso', 'acceso_excepcion', 'acceso_permanencia', 'acceso_salida_temporal', 'lost_found_vencidos', 'candidato', 'pase_salida_vencido', 'novedad_canalizada'])->pluck('referencia_tipo')->all(),
            'A las 25 h solo pasa al suplente lo que tiene minutos u horas propias');

        // Pasado el suplente (72 h de fábrica): a los 4 días (la gracia de un cliente dura pocos días) y a los 11 (candidatos al doble de su plazo)
        Carbon::setTestNow($inicio->copy()->addDays(4));
        $this->correrTodo();
        Carbon::setTestNow($inicio->copy()->addDays(11));
        $this->correrTodo();
        $total = $this->avisosDelMotor();
        $this->assertGreaterThan($recordatorios, $total);

        // Volver a correr (otra pestaña, el cron y la campana a la vez) no repite nada
        $this->correrTodo();
        $this->correrTodo();
        $this->assertSame($total, $this->avisosDelMotor());

        // Cada reloj pasó algo al suplente, con su registro en la auditoría
        $escalados = Auditoria::query()->withoutGlobalScopes()->where('evento', 'relojes.suplente')->get()
            ->groupBy(fn (Auditoria $a) => $a->antes['reloj'] ?? '');
        $esperados = ['altas', 'procedimientos_aprobar', 'procedimientos_leer', 'novedades_canalizadas', 'cuentas', 'caseta_supervisor', 'excepciones_caseta',
            'permanencia', 'salida_temporal', 'vacantes_propuestas', 'vacantes_cubiertas', 'candidatos_solicitudes', 'candidatos_sin_atender', 'entrevistas',
            'candidatos_elegidos', 'vales_taxi', 'pases_vencidos', 'lost_found', 'activos_no_regreso', 'equipos_mantenimiento', 'vouchers',
            'faltantes_responsivas', 'hallazgos_recorrido', 'plataforma_solicitudes', 'plataforma_pruebas', 'plataforma_clientes', 'plataforma_gracia'];
        foreach ($esperados as $clave) {
            $this->assertTrue($escalados->has($clave), "El reloj «{$clave}» no pasó nada al suplente");
            $conQuien = $escalados[$clave]->filter(fn (Auditoria $a) => ($a->despues['avisados'] ?? []) !== []);
            $this->assertTrue($conQuien->isNotEmpty(), "El reloj «{$clave}» no encontró a quién pasarlo");
        }

        // Cada asunto, a lo mucho un aviso al suplente por persona
        $porAsunto = Notificacion::query()->withoutGlobalScopes()->where('tipo', MotorRelojes::TIPO_ESCALADO)
            ->get(['user_id', 'referencia_tipo', 'referencia_id'])->groupBy(fn ($n) => $n->user_id.':'.$n->referencia_tipo.':'.$n->referencia_id);
        $this->assertSame([], $porAsunto->filter(fn ($g) => $g->count() > 1)->keys()->all());
    }

    public function test_con_los_avisos_apagados_el_reloj_sigue_pero_no_avisa(): void
    {
        $this->hotel->refresh()->forceFill(['preferencias' => array_merge($this->hotel->preferencias ?? [], ['relojes' => ['encendido' => false]])])->save();
        $inicio = now()->startOfMinute();
        Carbon::setTestNow($inicio);
        $this->correrTodo();
        Carbon::setTestNow($inicio->copy()->addDays(11));
        $this->correrTodo();
        $this->assertSame(0, Notificacion::query()->withoutGlobalScopes()->where('empresa_id', $this->hotel->id)
            ->whereIn('tipo', [MotorRelojes::TIPO_RECORDATORIO, MotorRelojes::TIPO_ESCALADO])->count());

        // El color sí: el renglón de altas por verificar del administrador está en rojo
        $admin = User::where('username', 'admin.demo')->where('empresa_id', $this->hotel->id)->firstOrFail();
        $this->app->forgetScopedInstances();
        Cache::flush();
        $altas = collect(app(MisPendientes::class)->para($admin)['items'])->firstWhere('clave', 'altas');
        $this->assertSame('roja', $altas['nivel']);
    }

    public function test_el_cliente_en_gracia_ve_tu_suscripcion_con_reloj_y_pasa_al_super_administrador(): void
    {
        // Renglón 25 del inventario: Plaza Norte (demo) está en gracia; su suplente es el Super Administrador (SuscripcionEnGracia)
        $plaza = User::where('username', 'plaza.demo')->firstOrFail();
        $sa = $this->crearSuperadmin();
        Carbon::setTestNow(now()->startOfMinute());
        $this->correrTodo();
        $this->actingAs($plaza);
        Cache::flush();
        $this->app->forgetScopedInstances();
        $renglon = collect(app(Tenant::class)->conEmpresa((int) $plaza->empresa_id, fn () => app(MisPendientes::class)->para($plaza)['items']))->firstWhere('clave', 'suscripcion');
        $this->assertNotNull($renglon, 'El cliente ve «Tu suscripción» en Mis pendientes');
        $this->assertNotNull($renglon['nivel']);

        Carbon::setTestNow(now()->addMinutes(2));
        $this->correrTodo();
        $this->correrTodo();
        // El recordatorio al cliente ya lo manda Suscripciones (avisos de vencimiento): el motor no lo duplica
        $this->assertSame(0, Notificacion::query()->withoutGlobalScopes()->where('user_id', $plaza->id)->where('tipo', MotorRelojes::TIPO_RECORDATORIO)
            ->where('referencia_tipo', 'suscripcion')->count());
        Carbon::setTestNow(now()->addDays(4));
        $this->correrTodo();
        $this->assertGreaterThan(0, Notificacion::query()->withoutGlobalScopes()->where('user_id', $sa->id)->where('tipo', MotorRelojes::TIPO_ESCALADO)->count(),
            'La gracia pasa al Super Administrador');
    }

    public function test_un_modulo_con_horas_y_rol_propios_y_otro_apagado(): void
    {
        $jefe = User::where('username', 'jefe.demo')->where('empresa_id', $this->hotel->id)->firstOrFail();
        $rolJefe = $jefe->roles()->firstOrFail();
        $this->hotel->refresh()->forceFill(['preferencias' => array_merge($this->hotel->preferencias ?? [], ['relojes' => ['modulos' => [
            'vales_taxi' => ['ambar' => 2, 'suplente' => 4, 'rol_id' => $rolJefe->id],
            'altas' => ['encendido' => false],
        ]]])])->save();
        $inicio = now()->startOfMinute();
        Carbon::setTestNow($inicio);
        $this->correrTodo();
        Carbon::setTestNow($inicio->copy()->addHours(5));
        $this->correrTodo();
        $vales = Auditoria::query()->withoutGlobalScopes()->where('evento', 'relojes.suplente')->get()->filter(fn ($a) => ($a->antes['reloj'] ?? '') === 'vales_taxi');
        $this->assertNotEmpty($vales, 'Los vales pasan al suplente a sus 4 h propias');
        $jefes = DB::table('usuario_roles')->where('rol_id', $rolJefe->id)->pluck('user_id')->map(fn ($id) => (int) $id)->all();
        foreach ($vales as $a) {
            $this->assertSame([], array_diff($a->despues['avisados'], $jefes), 'Pasa al rol elegido');
        }
        $this->assertSame(0, Notificacion::query()->withoutGlobalScopes()->where('empresa_id', $this->hotel->id)->where('tipo', MotorRelojes::TIPO_RECORDATORIO)
            ->where('referencia_tipo', 'like', 'alta_%')->count(), 'Altas por verificar quedó apagado');
    }

    public function test_los_relojes_que_ya_tenian_motor_corren_desde_la_campana(): void
    {
        $antes = Notificacion::query()->withoutGlobalScopes()->pluck('id')->all();
        $inicio = now()->startOfMinute();
        foreach ([0, 25, 96, 24 * 11, 24 * 35] as $horas) { // el plazo ARCO de la demo vence en 20 días hábiles
            Carbon::setTestNow($inicio->copy()->addHours($horas));
            Cache::flush();
            $this->app->forgetScopedInstances();
            // Como la consulta de la campana de cualquier persona de la empresa
            app(MotorRelojes::class)->correrSiToca($this->hotel->id);
        }
        $tipos = Notificacion::query()->withoutGlobalScopes()->whereNotIn('id', $antes)->pluck('tipo')->unique()->all();
        // Renglones 1 y 2 (caseta y Recepción), 3 a 6 (plazos de candidatos), 9 (firmas de pases, ahora con 24 h / 72 h de
        // fábrica), 12 (pendiente de turno), 16 (llaves, semáforo encendido de fábrica), 18 (reservas) y 19 (plazo ARCO)
        foreach (['arco_plazo', 'autorizacion_visita', 'autorizacion_escalada', 'autorizacion_recepcion', 'plazo_candidato', 'pase_firma_recordatorio', 'pase_firma_escalada',
            'novedad_turno', 'novedad_turno_escalada', 'llave_fuera', 'reserva_recordatorio', 'reserva_escalada', MotorRelojes::TIPO_RECORDATORIO, MotorRelojes::TIPO_ESCALADO] as $tipo) {
            $this->assertContains($tipo, $tipos, "Falta el aviso «{$tipo}» al avanzar el reloj");
        }
    }
}
