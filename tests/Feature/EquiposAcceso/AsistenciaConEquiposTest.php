<?php

namespace Tests\Feature\EquiposAcceso;

use App\Models\Acceso;
use App\Models\Colaborador;
use App\Models\EquipoAcceso;
use App\Services\EquiposAcceso\AdministradorEquipos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Asistencia\EscenarioAsistencia;
use Tests\TestCase;

/**
 * Integración de las decisiones 10 y 13: un marcaje de un equipo ZKTeco crea
 * la entrada del colaborador y Asistencia la cuenta (asistencia, retardo,
 * salida y horas) en «Asistencia del día» y en el reporte por periodo, sin
 * tocar el cálculo de Asistencia (lee las mismas filas de accesos).
 */
class AsistenciaConEquiposTest extends TestCase
{
    use EscenarioAsistencia, RefreshDatabase;

    private EquipoAcceso $equipo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararAsistencia();
        $admin = $this->crearUsuario($this->empresa, 'Administrador');
        [$this->equipo] = $this->enEmpresa(fn () => app(AdministradorEquipos::class)->crear($admin, [
            'sede_id' => $this->sede->id, 'nombre' => 'Checador personal', 'marca' => 'zkteco', 'numero_serie' => 'CQZ7232460321', 'sentido' => 'ambos',
        ]));
        $this->equipo->forceFill(['created_at' => now()->subDays(30)])->saveQuietly();
    }

    private function conPin(Colaborador $c, string $pin): Colaborador
    {
        $this->enEmpresa(fn () => $c->forceFill(['num_empleado' => $pin])->saveQuietly());

        return $c->refresh();
    }

    private function attlog(string $renglones): void
    {
        $this->app['auth']->forgetGuards();
        $this->call('POST', '/iclock/cdata?'.http_build_query(['SN' => $this->equipo->numero_serie, 'table' => 'ATTLOG', 'Stamp' => '1']),
            [], [], [], ['CONTENT_TYPE' => 'text/plain'], $renglones)->assertOk();
    }

    public function test_el_marcaje_del_equipo_cuenta_como_asistencia_y_retardo(): void
    {
        $ana = $this->conPin($this->colaborador('Ana', $this->matutino), '1001');
        $beto = $this->conPin($this->colaborador('Beto', $this->matutino), '1002');
        $this->colaborador('Rita', $this->matutino, $this->recepcion);

        // Hora local de la sede (México centro), como la manda el equipo
        $this->attlog("1001\t2026-11-04 07:55:00\t0\t1\t0\n1002\t2026-11-04 08:40:00\t0\t1\t0\n");

        $this->assertSame($this->equipo->id, (int) $this->enEmpresa(fn () => Acceso::where('colaborador_id', $beto->id)->value('equipo_entrada_id')));
        $this->actingAs($this->rh)->get('/asistencia')->assertOk()
            ->assertSee('Llegaron (2)')->assertSee('Retardos (1)')->assertSee('Faltan (1)')
            ->assertSee('retardo de 40 min')->assertSee('07:55');
        $this->assertNotNull($ana);
    }

    public function test_entrada_y_salida_del_equipo_en_el_reporte_por_periodo(): void
    {
        $ana = $this->conPin($this->colaborador('Ana', $this->matutino), '1001');
        // Sin estado del equipo: la segunda marca del día es la salida
        $this->attlog("1001\t2026-11-02 08:30:00\t0\t1\t0\n1001\t2026-11-02 16:00:00\t0\t1\t0\n1001\t2026-11-03 07:58:00\t0\t1\t0\n1001\t2026-11-03 15:20:00\t0\t1\t0\n");

        $this->actingAs($this->rh)->get('/asistencia/periodo?desde=2026-11-02&hasta=2026-11-03&colaborador='.$ana->id)->assertOk()
            ->assertSee('Asistencias: <strong>2</strong> de 2', false)->assertSee('retardo de 30 min')->assertSee('salió 40 min antes')->assertSee('7:30');
        $csv = $this->actingAs($this->rh)->get('/asistencia/periodo/exportar?desde=2026-11-02&hasta=2026-11-03')->assertOk()->streamedContent();
        $this->assertStringContainsString('02/11/2026,Lunes,Matutino,08:00,16:00,"02/11/2026 08:30","02/11/2026 16:00",30,,7:30', $csv);
    }
}
