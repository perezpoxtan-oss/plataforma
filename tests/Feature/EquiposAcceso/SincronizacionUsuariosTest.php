<?php

namespace Tests\Feature\EquiposAcceso;

use App\Models\Colaborador;
use App\Models\ComandoEquipoAcceso;
use App\Models\EquipoAcceso;
use App\Services\Credenciales\AdministradorCredenciales;
use App\Services\EquiposAcceso\SincronizacionUsuarios;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Decisión 10: al emitir, entregar, reponer o dar de baja una credencial, o
 * al dar de baja al colaborador, la cola del equipo ZKTeco recibe el alta o
 * la baja (DATA UPDATE / DATA DELETE USERINFO) y el equipo la recoge al
 * preguntar. Hikvision no recibe nada (puente local: fase siguiente).
 */
class SincronizacionUsuariosTest extends TestCase
{
    use CreaEquiposAcceso, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararEmpresa();
    }

    /** @return list<string> */
    private function cola(int $equipoId): array
    {
        return $this->enEmpresa(fn () => ComandoEquipoAcceso::where('equipo_acceso_id', $equipoId)->where('estado', 'pendiente')->orderBy('id')->pluck('comando')->all());
    }

    private function preguntar(EquipoAcceso $equipo): string
    {
        return $this->zk('GET', '/iclock/getrequest', ['SN' => $equipo->numero_serie])->assertOk()->getContent();
    }

    public function test_al_entregar_la_credencial_el_equipo_de_su_sede_recibe_el_alta_sin_su_nombre(): void
    {
        $zk = $this->equipo();
        $deOtraSede = $this->equipo(datos: ['numero_serie' => 'CQZ0000000002', 'nombre' => 'Checador playa'], sede: $this->playa);
        $hik = $this->equipo('hikvision');
        $juan = $this->colaborador('1001');

        $this->darCredencial($juan, '0012345678');

        $this->assertSame(["DATA UPDATE USERINFO PIN=1001\tName=\tPri=0\tPasswd=\tCard=12345678\tGrp=1"], $this->cola($zk->id));
        $this->assertSame([], $this->cola($deOtraSede->id));
        $this->assertSame([], $this->cola($hik->id));

        $texto = $this->preguntar($zk);
        $this->assertStringStartsWith('C:', $texto);
        $this->assertStringNotContainsString('Juan', $texto);
        $this->assertStringNotContainsString('Pérez', $texto);
        $id = (int) explode(':', $texto)[1];
        $this->zk('POST', '/iclock/devicecmd', ['SN' => $zk->numero_serie], "ID={$id}&Return=0&CMD=DATA\n")->assertOk();
        $this->assertSame('hecho', $this->enEmpresa(fn () => ComandoEquipoAcceso::find($id)->estado));
    }

    public function test_reponer_cambia_la_tarjeta_y_la_baja_del_colaborador_lo_quita(): void
    {
        $zk = $this->equipo();
        $juan = $this->colaborador('1001');
        $credencial = $this->darCredencial($juan, '0012345678');
        $this->preguntar($zk); // el alta ya se entregó

        // Extraviada: la vigente se da de baja, la tarjeta se quita y se emite otra (sin tarjeta todavía)
        $this->enEmpresa(fn () => app(AdministradorCredenciales::class)->reponer($this->admin, $credencial->refresh(),
            ['motivo' => 'extraviado', 'descripcion' => 'Se le perdió', 'aplica_cobro' => '0']));
        $this->assertSame(["DATA UPDATE USERINFO PIN=1001\tName=\tPri=0\tPasswd=\tCard=\tGrp=1"], $this->cola($zk->id), 'Solo lo último: la tarjeta perdida deja de abrir');

        $this->enEmpresa(fn () => $juan->refresh()->forceFill(['activo' => false])->save());
        $this->assertSame(['DATA DELETE USERINFO PIN=1001'], $this->cola($zk->id));
    }

    public function test_la_baja_solo_va_a_los_equipos_que_lo_tenian(): void
    {
        $zk = $this->equipo();
        $juan = $this->colaborador('1001'); // nunca tuvo credencial: el equipo nunca lo tuvo
        $this->enEmpresa(fn () => $juan->forceFill(['activo' => false])->save());
        $this->assertSame([], $this->cola($zk->id));
    }

    public function test_cambio_de_sede_lo_quita_del_equipo(): void
    {
        $zk = $this->equipo();
        $ana = $this->colaborador('1002', nombre: 'Ana');
        $this->darCredencial($ana, null);
        $this->preguntar($zk);
        $this->enEmpresa(fn () => $ana->refresh()->forceFill(['sede_id' => $this->playa->id])->save());
        $this->assertSame(['DATA DELETE USERINFO PIN=1002'], $this->cola($zk->id));
    }

    public function test_cambio_de_numero_de_empleado_quita_el_anterior(): void
    {
        $zk = $this->equipo();
        $juan = $this->colaborador('1001');
        $this->darCredencial($juan, null);
        $this->preguntar($zk);
        $this->enEmpresa(fn () => $juan->refresh()->forceFill(['num_empleado' => '2001'])->save());
        $this->assertSame(['DATA DELETE USERINFO PIN=1001', "DATA UPDATE USERINFO PIN=2001\tName=\tPri=0\tPasswd=\tCard=\tGrp=1"], $this->cola($zk->id));
    }

    public function test_equipos_de_otra_empresa_no_reciben_nada(): void
    {
        $otra = $this->crearEmpresa('Corporativo Norte');
        $this->crearSede($otra, 'NTE');
        $ajeno = $this->equipo(datos: ['numero_serie' => 'CQZAJENO0001'], empresa: $otra);
        $this->darCredencial($this->colaborador('1001'), '0012345678');
        $this->assertSame([], ComandoEquipoAcceso::withoutGlobalScopes()->where('equipo_acceso_id', $ajeno->id)->pluck('comando')->all());
    }

    public function test_sincronizar_carga_a_los_vigentes_y_quita_a_los_que_ya_no_estan(): void
    {
        $zk = $this->equipo();
        $juan = $this->colaborador('1001');
        $ana = $this->colaborador('1002', nombre: 'Ana');
        $this->colaborador('1003', nombre: 'Luis'); // sin credencial: no se carga
        $this->darCredencial($juan, '0012345678');
        $this->darCredencial($ana, null);
        $this->preguntar($zk);
        // Ana cambia de sede por un camino sin eventos (p. ej. una carga masiva): el equipo aún la tiene
        $this->enEmpresa(fn () => Colaborador::whereKey($ana->id)->update(['sede_id' => $this->playa->id]));
        $this->assertSame([], $this->cola($zk->id));

        $r = $this->enEmpresa(fn () => app(SincronizacionUsuarios::class)->equipo($zk, $this->admin));
        $this->assertSame(['altas' => 1, 'bajas' => 1], $r);
        $this->assertSame(["DATA UPDATE USERINFO PIN=1001\tName=\tPri=0\tPasswd=\tCard=12345678\tGrp=1", 'DATA DELETE USERINFO PIN=1002'], $this->cola($zk->id));
    }

    public function test_un_error_al_sincronizar_no_impide_guardar_al_colaborador(): void
    {
        $this->equipo();
        $juan = $this->colaborador('1001');
        $this->darCredencial($juan, null);
        Schema::drop('equipos_acceso_comandos');
        $this->enEmpresa(fn () => $juan->refresh()->forceFill(['activo' => false])->save());
        $this->assertFalse((bool) $juan->refresh()->activo);
    }
}
