<?php

namespace Tests\Feature\EquiposAcceso;

use App\Models\Acceso;
use App\Models\ComandoEquipoAcceso;
use App\Models\EquipoAcceso;
use App\Models\MarcajeEquipoAcceso;
use App\Services\EquiposAcceso\ConectorZkteco;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Decisión 10: un equipo ZKTeco simulado con peticiones reales del protocolo
 * PUSH (fixtures de tests/fixtures/equipos-acceso): saludo, marcajes ATTLOG,
 * preguntas y respuestas de comandos; SN desconocidos o con formato
 * inválido, IP no permitida, tamaño máximo e idempotencia.
 */
class ZktecoProtocoloTest extends TestCase
{
    use CreaEquiposAcceso, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararEmpresa();
    }

    public function test_saludo_responde_las_opciones_del_protocolo_y_anota_el_contacto(): void
    {
        $equipo = $this->equipo();
        $r = $this->zk('GET', '/iclock/cdata', ['SN' => $equipo->numero_serie, 'options' => 'all', 'pushver' => '2.4.1', 'language' => '69'])->assertOk();

        $this->assertStringStartsWith('text/plain', $r->headers->get('Content-Type'));
        $texto = $r->getContent();
        $this->assertStringStartsWith('GET OPTION FROM: CQZ7232460321', $texto);
        foreach (['ATTLOGStamp=None', 'Delay=30', 'TransFlag=TransData AttLog', 'Realtime=1', 'Encrypt=None'] as $opcion) {
            $this->assertStringContainsString($opcion, $texto);
        }
        $equipo->refresh();
        $this->assertNotNull($equipo->ultimo_contacto_at);
        $this->assertSame('2.4.1', $equipo->version_equipo);
        $this->assertNull($r->headers->getCookies()[0] ?? null, 'El equipo no recibe sesión ni cookies');
    }

    public function test_marcajes_attlog_crean_entrada_y_salida_en_la_bitacora(): void
    {
        $equipo = $this->equipo();
        $juan = $this->colaborador('1001');

        $this->attlog($equipo, $this->fixture('zkteco-attlog.txt'))->assertOk()->assertSeeText('OK: 2');

        $accesos = $this->accesosDe($juan);
        $this->assertCount(1, $accesos);
        $a = $accesos->first();
        $this->assertSame(['colaborador', 'entrada', 'finalizado', 'JUAN PÉREZ LÓPEZ'], [$a->tipo, $a->movimiento, $a->estado, $a->nombre]);
        $this->assertSame($equipo->id, (int) $a->equipo_entrada_id);
        $this->assertSame($equipo->id, (int) $a->equipo_salida_id);
        // Hora local de la sede (México centro, UTC-6) → UTC
        $this->assertSame('2026-11-03 13:58:12', $a->entrada_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame('2026-11-03 22:02:40', $a->salida_at->utc()->format('Y-m-d H:i:s'));
        $this->assertNull($a->creado_por);
        $this->assertSame('9999', $equipo->refresh()->estampa_marcajes);
        $this->assertNotNull($equipo->ultimo_marcaje_at);
        // La bitácora dice quién lo registró
        $this->actingAs($this->admin)->get(route('accesos.index', ['vista' => 'historial']))->assertOk();
    }

    public function test_el_mismo_paquete_dos_veces_no_duplica(): void
    {
        $equipo = $this->equipo();
        $juan = $this->colaborador('1001');
        $this->attlog($equipo, $this->fixture('zkteco-attlog.txt'))->assertSeeText('OK: 2');
        $this->attlog($equipo, $this->fixture('zkteco-attlog.txt'))->assertOk()->assertSeeText('OK: 2');

        $this->assertCount(1, $this->accesosDe($juan));
        $this->assertSame(2, $this->enEmpresa(fn () => MarcajeEquipoAcceso::count()));
    }

    public function test_renglones_invalidos_se_descartan_sin_tocar_nada(): void
    {
        $equipo = $this->equipo();
        $juan = $this->colaborador('1001');

        $this->attlog($equipo, $this->fixture('zkteco-attlog-mezclado.txt'))->assertOk();

        $this->assertCount(1, $this->accesosDe($juan), 'Solo el renglón bien formado');
        $marcajes = $this->enEmpresa(fn () => MarcajeEquipoAcceso::orderBy('id')->get());
        $this->assertSame(['entrada', 'desconocido'], $marcajes->pluck('resultado')->all(), 'El PIN 9999 no es de nadie');
        $this->assertSame('••9999', $marcajes->last()->identificador);
    }

    public function test_lo_marcado_antes_del_alta_del_equipo_no_se_registra(): void
    {
        $equipo = $this->equipo();
        $equipo->forceFill(['created_at' => '2026-11-03 20:00:00'])->saveQuietly(); // alta: 14:00 hora local
        $juan = $this->colaborador('1001');

        $this->attlog($equipo, $this->fixture('zkteco-attlog.txt'))->assertOk();

        $accesos = $this->accesosDe($juan);
        $this->assertCount(0, $accesos, 'La entrada de las 7:58 es anterior al alta y la salida no tiene entrada');
        $this->assertSame(['sin_entrada'], $this->enEmpresa(fn () => MarcajeEquipoAcceso::pluck('resultado')->all()));
    }

    public function test_otras_tablas_se_reciben_sin_registrar_nada(): void
    {
        $equipo = $this->equipo();
        $this->colaborador('1001');
        $this->zk('POST', '/iclock/cdata', ['SN' => $equipo->numero_serie, 'table' => 'OPERLOG', 'Stamp' => '1'], "OPLOG 4\t0\t2026-11-03 08:00:00\t0\t0\t0\t0\n")
            ->assertOk()->assertSeeText('OK: 0');
        $this->zk('POST', '/iclock/cdata', ['SN' => $equipo->numero_serie, 'table' => '../../etc'], 'x')->assertStatus(400);
        $this->assertSame(0, $this->enEmpresa(fn () => Acceso::count() + MarcajeEquipoAcceso::count()));
    }

    public function test_sn_desconocido_o_invalido_y_equipo_de_baja_se_rechazan(): void
    {
        $equipo = $this->equipo();
        $juan = $this->colaborador('1001');

        $this->zk('GET', '/iclock/cdata', ['SN' => 'NOEXISTE123', 'options' => 'all'])->assertStatus(401)->assertSeeText('ERROR');
        $this->zk('GET', '/iclock/getrequest', ['SN' => "x' OR 1=1"])->assertStatus(400);
        $this->zk('GET', '/iclock/getrequest', [])->assertStatus(400);
        $this->zk('POST', '/iclock/cdata', ['SN' => 'NOEXISTE123', 'table' => 'ATTLOG'], $this->fixture('zkteco-attlog.txt'))->assertStatus(401);
        $this->assertCount(0, $this->accesosDe($juan));

        $equipo->forceFill(['activo' => false])->saveQuietly();
        $this->attlog($equipo, $this->fixture('zkteco-attlog.txt'))->assertStatus(401);
        $this->assertCount(0, $this->accesosDe($juan));
        $this->assertNull($equipo->refresh()->ultimo_contacto_at);
    }

    public function test_ip_no_permitida_se_rechaza(): void
    {
        $equipo = $this->equipo(datos: ['ips_permitidas' => '201.150.10.20']);
        $juan = $this->colaborador('1001');

        $this->attlog($equipo, $this->fixture('zkteco-attlog.txt'), ['REMOTE_ADDR' => '8.8.8.8'])->assertStatus(403);
        $this->assertCount(0, $this->accesosDe($juan));
        $this->attlog($equipo, $this->fixture('zkteco-attlog.txt'), ['REMOTE_ADDR' => '201.150.10.20'])->assertOk();
        $this->assertCount(1, $this->accesosDe($juan));
    }

    public function test_cuerpo_demasiado_grande_se_rechaza(): void
    {
        $equipo = $this->equipo();
        $this->colaborador('1001');
        $this->attlog($equipo, str_repeat("1001\t2026-11-03 07:58:12\t0\t1\t0\n", 20000))->assertStatus(413);
        $this->assertSame(0, $this->enEmpresa(fn () => MarcajeEquipoAcceso::count()));
    }

    public function test_getrequest_entrega_la_cola_y_devicecmd_la_cierra(): void
    {
        $equipo = $this->equipo();
        $this->zk('GET', '/iclock/getrequest', ['SN' => $equipo->numero_serie])->assertOk()->assertSeeText('OK');

        $cmd = $this->enEmpresa(fn () => ComandoEquipoAcceso::create(['equipo_acceso_id' => $equipo->id, 'tipo' => 'alta', 'pin' => '1001',
            'comando' => app(ConectorZkteco::class)->comandoAlta('1001', '0012345678')]));
        $texto = $this->zk('GET', '/iclock/getrequest', ['SN' => $equipo->numero_serie])->assertOk()->getContent();
        $this->assertSame("C:{$cmd->id}:DATA UPDATE USERINFO PIN=1001\tName=\tPri=0\tPasswd=\tCard=12345678\tGrp=1\n", $texto);
        $this->assertSame('enviado', $cmd->refresh()->estado);
        // Ya enviado: no se repite en la siguiente pregunta
        $this->zk('GET', '/iclock/getrequest', ['SN' => $equipo->numero_serie])->assertSeeText('OK');

        $cuerpo = str_replace('{ID}', (string) $cmd->id, $this->fixture('zkteco-devicecmd.txt'))."ID=999999&Return=0&CMD=DATA\nbasura\n";
        $this->zk('POST', '/iclock/devicecmd', ['SN' => $equipo->numero_serie], $cuerpo)->assertOk()->assertSeeText('OK');
        $cmd->refresh();
        $this->assertSame(['hecho', 0], [$cmd->estado, $cmd->resultado]);
    }

    public function test_un_comando_sin_respuesta_se_reenvia_y_al_quinto_intento_queda_en_error(): void
    {
        $equipo = $this->equipo();
        $cmd = $this->enEmpresa(fn () => ComandoEquipoAcceso::create(['equipo_acceso_id' => $equipo->id, 'tipo' => 'baja', 'pin' => '1001', 'comando' => 'DATA DELETE USERINFO PIN=1001']));
        for ($i = 1; $i <= ComandoEquipoAcceso::MAX_INTENTOS; $i++) {
            $this->zk('GET', '/iclock/getrequest', ['SN' => $equipo->numero_serie])->assertSeeText("C:{$cmd->id}:");
            $cmd->refresh()->forceFill(['enviado_at' => now()->subMinutes(10)])->saveQuietly();
        }
        $this->zk('GET', '/iclock/getrequest', ['SN' => $equipo->numero_serie])->assertSeeText('OK');
        $this->assertSame('error', $cmd->refresh()->estado);
    }

    public function test_la_respuesta_de_un_comando_de_otro_equipo_no_lo_cierra(): void
    {
        $uno = $this->equipo();
        $otro = $this->equipo(datos: ['numero_serie' => 'CQZ0000000002', 'nombre' => 'Checador cocina']);
        $cmd = $this->enEmpresa(fn () => ComandoEquipoAcceso::create(['equipo_acceso_id' => $uno->id, 'tipo' => 'baja', 'pin' => '1001', 'comando' => 'DATA DELETE USERINFO PIN=1001']));
        $this->zk('POST', '/iclock/devicecmd', ['SN' => $otro->numero_serie], "ID={$cmd->id}&Return=0&CMD=DATA\n")->assertOk();
        $this->assertSame('pendiente', $cmd->refresh()->estado);
    }

    public function test_la_estampa_guardada_vuelve_en_el_saludo(): void
    {
        $equipo = $this->equipo();
        $this->colaborador('1001');
        $this->zk('POST', '/iclock/cdata', ['SN' => $equipo->numero_serie, 'table' => 'ATTLOG', 'Stamp' => '815732410'], $this->fixture('zkteco-attlog.txt'))->assertOk();
        $this->zk('GET', '/iclock/cdata', ['SN' => $equipo->numero_serie, 'options' => 'all'])->assertSeeText('ATTLOGStamp=815732410');
    }

    public function test_limite_de_peticiones_por_equipo(): void
    {
        $equipo = $this->equipo();
        for ($i = 0; $i < 60; $i++) {
            $this->zk('GET', '/iclock/getrequest', ['SN' => $equipo->numero_serie])->assertOk();
        }
        $this->zk('GET', '/iclock/getrequest', ['SN' => $equipo->numero_serie])->assertStatus(429);
        // Otro equipo del mismo cliente (misma IP) sigue
        $otro = $this->equipo(datos: ['numero_serie' => 'CQZ0000000002', 'nombre' => 'Checador cocina']);
        $this->zk('GET', '/iclock/getrequest', ['SN' => $otro->numero_serie])->assertOk();
        // Y sus marcajes no gastan el límite de las preguntas
        $this->zk('GET', '/iclock/cdata', ['SN' => $equipo->numero_serie, 'options' => 'all'])->assertOk();
    }

    public function test_conversion_de_tarjeta_y_pin_para_el_equipo(): void
    {
        $this->assertSame('12345678', ConectorZkteco::tarjeta('0012345678'));
        $this->assertSame((string) hexdec('04A23B1C'), ConectorZkteco::tarjeta('04:a2:3b:1c'));
        $this->assertSame('', ConectorZkteco::tarjeta(null));
        $this->assertSame('1001', ConectorZkteco::pin('10-01'));
        $this->assertNull(ConectorZkteco::pin(''));
        $this->assertSame('DATA DELETE USERINFO PIN=1001', app(ConectorZkteco::class)->comandoBaja('1001'));
        $this->assertNull(EquipoAcceso::first()?->token_hash);
    }
}
