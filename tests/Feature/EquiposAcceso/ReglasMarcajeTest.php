<?php

namespace Tests\Feature\EquiposAcceso;

use App\Models\Acceso;
use App\Models\Auditoria;
use App\Models\MarcajeEquipoAcceso;
use App\Models\Notificacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Decisión 10: qué hace la plataforma con cada marcaje (igual para todas
 * las marcas): sentido del equipo, alternar si no lo dice, antipassback =
 * solo aviso, sede del equipo, colaborador de baja y credencial no vigente.
 */
class ReglasMarcajeTest extends TestCase
{
    use CreaEquiposAcceso, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararEmpresa();
    }

    private function marca(string $pin, string $hora, int $estado = 0): string
    {
        return "{$pin}\t2026-11-03 {$hora}\t{$estado}\t1\t0\n";
    }

    private function resultados(): array
    {
        return $this->enEmpresa(fn () => MarcajeEquipoAcceso::orderBy('id')->pluck('resultado')->all());
    }

    public function test_sin_estado_del_equipo_se_alterna_entrada_y_salida(): void
    {
        $equipo = $this->equipo();
        $juan = $this->colaborador('1001');
        $this->attlog($equipo, $this->marca('1001', '07:00:00').$this->marca('1001', '12:00:00').$this->marca('1001', '13:00:00').$this->marca('1001', '17:00:00'));
        $accesos = $this->accesosDe($juan);
        $this->assertCount(2, $accesos, 'Comida incluida: dos entradas con su salida');
        $this->assertSame(['finalizado', 'finalizado'], $accesos->pluck('estado')->all());
        $this->assertSame(['entrada', 'salida', 'entrada', 'salida'], $this->resultados());
    }

    public function test_equipo_solo_de_entrada_o_solo_de_salida(): void
    {
        $entrada = $this->equipo(datos: ['sentido' => 'entrada']);
        $salida = $this->equipo(datos: ['sentido' => 'salida', 'numero_serie' => 'CQZ0000000002', 'nombre' => 'Salida personal']);
        $juan = $this->colaborador('1001');

        $this->attlog($entrada, $this->marca('1001', '07:00:00', 1)); // el estado del equipo no manda
        $this->attlog($salida, $this->marca('1001', '15:00:00', 0));

        $a = $this->accesosDe($juan)->sole();
        $this->assertSame([$entrada->id, $salida->id, 'finalizado'], [(int) $a->equipo_entrada_id, (int) $a->equipo_salida_id, $a->estado]);
    }

    public function test_antipassback_solo_avisa_y_la_entrada_se_registra(): void
    {
        $equipo = $this->equipo(datos: ['sentido' => 'entrada']);
        $juan = $this->colaborador('1001');
        $supervisor = $this->crearUsuario($this->empresa, 'Supervisor', $this->centro);

        $this->attlog($equipo, $this->marca('1001', '07:00:00').$this->marca('1001', '19:00:00'));

        $accesos = $this->accesosDe($juan);
        $this->assertCount(2, $accesos);
        $this->assertSame(['finalizado', 'en_sitio'], $accesos->pluck('estado')->all());
        $this->assertNull($accesos->first()->salida_at, 'La anterior se cierra sin inventar su hora de salida');
        $this->assertTrue(Auditoria::where('evento', 'accesos.sin_salida')->where('auditable_id', $accesos->first()->id)->exists());
        $aviso = Notificacion::where('user_id', $supervisor->id)->where('tipo', 'equipo_antipassback')->firstOrFail();
        $this->assertSame('Juan Pérez López no registró su salida', $aviso->titulo);
        // Gente en sitio: una sola vez
        $this->assertSame(1, $this->enEmpresa(fn () => Acceso::where('colaborador_id', $juan->id)->where('estado', 'en_sitio')->count()));
    }

    public function test_salida_sin_entrada_se_guarda_y_avisa(): void
    {
        $equipo = $this->equipo(datos: ['sentido' => 'salida']);
        $juan = $this->colaborador('1001');
        $supervisor = $this->crearUsuario($this->empresa, 'Supervisor', $this->centro);
        $this->attlog($equipo, $this->marca('1001', '15:00:00'));
        $this->assertCount(0, $this->accesosDe($juan));
        $this->assertSame(['sin_entrada'], $this->resultados());
        $this->assertTrue(Notificacion::where('user_id', $supervisor->id)->where('tipo', 'equipo_antipassback')->where('titulo', 'Juan Pérez López no registró su entrada')->exists());
    }

    public function test_la_entrada_de_caseta_se_cierra_con_el_equipo(): void
    {
        $equipo = $this->equipo();
        $juan = $this->colaborador('1001');
        $this->actingAs($this->admin)->post('/accesos', ['tipo' => 'colaborador', 'colaborador_id' => $juan->id, 'sede_id' => $this->centro->id, '_dialogo' => 'ingreso']);
        $this->attlog($equipo, "1001\t".now('America/Mexico_City')->addMinute()->format('Y-m-d H:i:s')."\t0\t1\t0\n");
        $a = $this->accesosDe($juan)->sole();
        $this->assertSame(['finalizado', $equipo->id, (int) $this->admin->id], [$a->estado, (int) $a->equipo_salida_id, (int) $a->creado_por]);
    }

    public function test_colaborador_de_otra_sede_no_pasa_y_el_corporativo_si(): void
    {
        $equipo = $this->equipo();
        $dePlaya = $this->colaborador('2001', $this->playa);
        $corporativo = $this->colaborador('2002');
        $this->enEmpresa(fn () => $corporativo->forceFill(['sede_id' => null])->save());
        $this->attlog($equipo, $this->marca('2001', '07:00:00').$this->marca('2002', '07:01:00'));
        $this->assertCount(0, $this->accesosDe($dePlaya));
        $this->assertCount(1, $this->accesosDe($corporativo));
        $this->assertSame(['otra_sede', 'entrada'], $this->resultados());
    }

    public function test_colaborador_de_baja_o_sin_credencial_vigente_se_rechaza_con_aviso(): void
    {
        $equipo = $this->equipo();
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $deBaja = $this->colaborador('3001');
        $this->enEmpresa(fn () => $deBaja->forceFill(['activo' => false])->save());
        $sinVigente = $this->colaborador('3002', nombre: 'Ana');
        $credencial = $this->darCredencial($sinVigente, null);
        $this->enEmpresa(fn () => $credencial->forceFill(['estado' => 'baja'])->save());
        $sinCredenciales = $this->colaborador('3003', nombre: 'Luis'); // la empresa no le emitió credencial: pasa

        $this->attlog($equipo, $this->marca('3001', '07:00:00').$this->marca('3002', '07:01:00').$this->marca('3003', '07:02:00'));

        $this->assertSame(['no_vigente', 'no_vigente', 'entrada'], $this->resultados());
        $this->assertCount(0, $this->accesosDe($deBaja));
        $this->assertCount(0, $this->accesosDe($sinVigente));
        $this->assertSame(2, Notificacion::where('user_id', $agente->id)->where('tipo', 'equipo_no_vigente')->count());
        $this->assertSame(2, Auditoria::where('evento', 'equipos_acceso.marcaje_rechazado')->whereNull('user_id')->count());
    }

    public function test_numero_de_empleado_con_ceros_a_la_izquierda(): void
    {
        $equipo = $this->equipo();
        $juan = $this->colaborador('1001');
        $this->attlog($equipo, $this->marca('0001001', '07:00:00'));
        $this->assertCount(1, $this->accesosDe($juan));
    }

    public function test_en_la_bitacora_dice_que_lo_registro_el_equipo(): void
    {
        $equipo = $this->equipo();
        $this->colaborador('1001');
        $this->attlog($equipo, "1001\t".now('America/Mexico_City')->format('Y-m-d H:i:s')."\t0\t1\t0\n");
        $this->actingAs($this->admin)->get(route('accesos.index'))->assertOk()->assertSee('(por Equipo: Checador personal)', false);
    }
}
