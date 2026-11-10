<?php

namespace Tests\Feature\Asistencia;

use App\Models\Acceso;
use App\Models\AsistenciaJustificacion;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Services\Asistencia\AjustesAsistencia;
use App\Services\Asistencia\CalculoAsistencia;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

/**
 * Asistencia (ADR-0024), cálculo: entrada, salida, retardo con tolerancia,
 * salida anticipada, falta, «no ha llegado», descanso, horas trabajadas
 * (con salida a comer), turnos que cruzan medianoche, alta reciente,
 * justificaciones en los totales y la misma fuente que la Bitácora de
 * accesos (lo que registra la caseta entra solo).
 */
class AsistenciaCalculoTest extends TestCase
{
    use EscenarioAsistencia, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararAsistencia();
    }

    /** @return array<string, array<string, mixed>> fecha => día */
    private function dias(Colaborador $c, string $desde, string $hasta): array
    {
        $c = $this->enEmpresa(fn () => Colaborador::with(['turno', 'sede'])->find($c->id));
        $dias = $this->enEmpresa(fn () => app(CalculoAsistencia::class)->dias(collect([$c]), $desde, $hasta, Empresa::find($this->empresa->id)));

        return collect($dias[$c->id])->keyBy('fecha')->all();
    }

    public function test_entrada_retardo_y_quien_no_ha_llegado_hoy(): void
    {
        $ana = $this->colaborador('Ana', $this->matutino);
        $beto = $this->colaborador('Beto', $this->matutino);
        $carla = $this->colaborador('Carla', $this->matutino);
        $this->marcaje($ana, '2026-11-04 07:55');
        $this->marcaje($beto, '2026-11-04 08:25');

        $a = $this->dias($ana, '2026-11-04', '2026-11-04')['2026-11-04'];
        $this->assertSame('asistio', $a['estado']);
        $this->assertSame('07:55', $a['entrada']->format('H:i'));
        $this->assertTrue($a['sin_salida']);
        $this->assertSame(0, $a['retardo']);
        $this->assertSame([], $a['por_justificar']);

        $b = $this->dias($beto, '2026-11-04', '2026-11-04')['2026-11-04'];
        $this->assertSame(25, $b['retardo']);
        $this->assertSame(['retardo'], $b['por_justificar']);

        // Dentro de la tolerancia (de fábrica 10 min) no es retardo
        $dani = $this->colaborador('Dani', $this->matutino);
        $this->marcaje($dani, '2026-11-04 08:10');
        $this->assertSame(0, $this->dias($dani, '2026-11-04', '2026-11-04')['2026-11-04']['retardo']);

        $c = $this->dias($carla, '2026-11-04', '2026-11-04')['2026-11-04'];
        $this->assertSame('sin_llegar', $c['estado']);
        $this->assertSame(['falta'], $c['por_justificar']);

        // Tolerancia configurable por empresa
        app(AjustesAsistencia::class)->guardar($this->rh, Empresa::find($this->empresa->id), ['tolerancia_minutos' => 30]);
        $this->assertSame(0, $this->dias($beto, '2026-11-04', '2026-11-04')['2026-11-04']['retardo']);
    }

    public function test_falta_salida_anticipada_horas_con_comida_descanso_y_alta(): void
    {
        $ana = $this->colaborador('Ana', $this->matutino);
        $carla = $this->colaborador('Carla', $this->matutino);
        $nueva = $this->colaborador('Nueva', $this->matutino, alta: '2026-11-03');
        // Ana salió a comer y volvió; se fue a las 15:30
        $this->marcaje($ana, '2026-11-03 08:00', '2026-11-03 12:00');
        $this->marcaje($ana, '2026-11-03 13:00', '2026-11-03 15:30');

        $dias = $this->dias($ana, '2026-11-01', '2026-11-03');
        $this->assertSame('descanso', $dias['2026-11-01']['estado'], 'El turno es de lunes a viernes: el domingo es descanso');
        $this->assertSame('falta', $dias['2026-11-02']['estado']);
        $m = $dias['2026-11-03'];
        $this->assertSame('asistio', $m['estado']);
        $this->assertSame('08:00', $m['entrada']->format('H:i'));
        $this->assertSame('15:30', $m['salida']->format('H:i'));
        $this->assertSame(390, $m['trabajados'], '4 h + 2 h 30 min: el rato de la comida no cuenta');
        $this->assertSame(30, $m['anticipada']);
        $this->assertSame(2, $m['marcajes']);

        $this->assertSame('falta', $this->dias($carla, '2026-11-03', '2026-11-03')['2026-11-03']['estado']);
        $deNueva = $this->dias($nueva, '2026-11-02', '2026-11-03');
        $this->assertSame('antes_de_alta', $deNueva['2026-11-02']['estado'], 'Antes de su alta no hay faltas');
        $this->assertSame('falta', $deNueva['2026-11-03']['estado']);
    }

    public function test_turno_nocturno_cuenta_el_dia_en_que_empieza(): void
    {
        $velador = $this->colaborador('Velador', $this->nocturno);
        $this->marcaje($velador, '2026-11-03 21:50', '2026-11-04 06:02');

        $dias = $this->dias($velador, '2026-11-03', '2026-11-04');
        $l = $dias['2026-11-03'];
        $this->assertSame('asistio', $l['estado']);
        $this->assertTrue($l['cruza']);
        $this->assertSame('2026-11-04 06:02', $l['salida']->format('Y-m-d H:i'));
        $this->assertSame(492, $l['trabajados']);
        $this->assertSame(0, $l['retardo']);
        $this->assertSame(0, $l['anticipada']);
        // El de hoy (22:00) todavía no empieza
        $this->assertSame('por_llegar', $dias['2026-11-04']['estado']);
    }

    public function test_sin_turno_no_hay_retardos_ni_faltas(): void
    {
        $libre = $this->colaborador('Libre', null);
        $this->marcaje($libre, '2026-11-04 09:40');
        $d = $this->dias($libre, '2026-11-03', '2026-11-04');
        $this->assertSame('sin_turno', $d['2026-11-03']['estado']);
        $this->assertSame('sin_turno', $d['2026-11-04']['estado']);
        $this->assertSame('09:40', $d['2026-11-04']['entrada']->format('H:i'));
        $this->assertSame([], $d['2026-11-04']['por_justificar']);
    }

    public function test_totales_del_periodo_con_justificaciones(): void
    {
        $ana = $this->colaborador('Ana', $this->matutino);
        $this->marcaje($ana, '2026-11-02 08:30', '2026-11-02 16:00');   // retardo de 30
        $this->marcaje($ana, '2026-11-03 07:58', '2026-11-03 16:01');
        $this->enEmpresa(fn () => AsistenciaJustificacion::create(['colaborador_id' => $ana->id, 'fecha' => '2026-11-02', 'tipo' => 'retardo', 'motivo' => 'permiso']));
        $this->enEmpresa(fn () => AsistenciaJustificacion::create(['colaborador_id' => $ana->id, 'fecha' => '2026-10-30', 'tipo' => 'falta', 'motivo' => 'vacaciones']));

        $r = CalculoAsistencia::resumen(array_values($this->dias($ana, '2026-10-29', '2026-11-03')));
        // Jue 29, Vie 30 (justificada), Sáb 31 y Dom 1 descanso, Lun 2 retardo justificado, Mar 3
        $this->assertSame(4, $r['programados']);
        $this->assertSame(2, $r['asistencias']);
        $this->assertSame(1, $r['faltas']);
        $this->assertSame(1, $r['faltas_justificadas']);
        $this->assertSame(0, $r['retardos']);
        $this->assertSame(1, $r['retardos_justificados']);
        $this->assertSame(2, $r['descansos']);
        $this->assertSame(450 + 483, $r['trabajados']);
        $this->assertSame('15:33', CalculoAsistencia::horas($r['trabajados']));
    }

    public function test_lo_que_registra_la_caseta_entra_solo_sin_pasos_nuevos(): void
    {
        $ana = $this->colaborador('Ana', $this->matutino);
        $agente = $this->crearUsuario($this->empresa, 'Administrador');
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-04 08:20', self::ZONA)->utc());
        $this->actingAs($agente)->post('/accesos', ['tipo' => 'colaborador', 'colaborador_id' => $ana->id, 'sede_id' => $this->sede->id, '_dialogo' => 'ingreso'])
            ->assertSessionHasNoErrors();
        $acceso = $this->enEmpresa(fn () => Acceso::where('colaborador_id', $ana->id)->firstOrFail());
        Carbon::setTestNow(CarbonImmutable::parse('2026-11-04 16:05', self::ZONA)->utc());
        $this->flushSession(); // ocho horas después: otra sesión de la caseta
        $this->actingAs($agente)->patch(route('accesos.salida', $acceso->id))->assertSessionHasNoErrors()->assertSessionHas('ok');

        $d = $this->dias($ana, '2026-11-04', '2026-11-04')['2026-11-04'];
        $this->assertSame('08:20', $d['entrada']->format('H:i'));
        $this->assertSame('16:05', $d['salida']->format('H:i'));
        $this->assertSame(20, $d['retardo']);
        $this->assertSame(465, $d['trabajados']);
    }
}
