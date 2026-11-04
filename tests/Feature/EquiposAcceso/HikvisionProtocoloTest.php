<?php

namespace Tests\Feature\EquiposAcceso;

use App\Models\Acceso;
use App\Models\MarcajeEquipoAcceso;
use App\Models\Notificacion;
use App\Services\Credenciales\AdministradorCredenciales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Decisión 10: un equipo Hikvision simulado con eventos reales de la
 * notificación ISAPI («AccessControllerEvent» en JSON y en multipart):
 * token en la ruta o en el encabezado, latido, eventos que no son un paso,
 * rostro (no se usa: decisión del dueño) e idempotencia por serialNo.
 */
class HikvisionProtocoloTest extends TestCase
{
    use CreaEquiposAcceso, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararEmpresa();
    }

    public function test_evento_json_registra_la_entrada_sin_guardar_el_nombre_que_manda_el_equipo(): void
    {
        $equipo = $this->equipo('hikvision');
        $juan = $this->colaborador('1001');

        $r = $this->hik($equipo, $this->fixture('hikvision-evento.json'))->assertOk()->assertSeeText('OK: 1');
        $this->assertStringNotContainsString('Juan', $r->getContent());

        $a = $this->accesosDe($juan)->sole();
        $this->assertSame(['entrada', 'en_sitio'], [$a->movimiento, $a->estado]);
        $this->assertSame('2026-11-03 14:05:30', $a->entrada_at->utc()->format('Y-m-d H:i:s'));
        $this->assertSame($equipo->id, (int) $a->equipo_entrada_id);
        $marcaje = $this->enEmpresa(fn () => MarcajeEquipoAcceso::sole());
        $this->assertSame('••••5678', $marcaje->identificador, 'La tarjeta se guarda enmascarada');
        $this->assertStringNotContainsString('nombre que el equipo manda', json_encode($marcaje->toArray()));
    }

    public function test_el_mismo_evento_repetido_no_duplica(): void
    {
        $equipo = $this->equipo('hikvision');
        $juan = $this->colaborador('1001');
        $this->hik($equipo, $this->fixture('hikvision-evento.json'))->assertOk();
        $this->hik($equipo, $this->fixture('hikvision-evento.json'))->assertOk()->assertSeeText('OK: 1');
        $this->assertCount(1, $this->accesosDe($juan));
        $this->assertSame(1, $this->enEmpresa(fn () => MarcajeEquipoAcceso::count()));
    }

    public function test_token_en_el_encabezado_y_tokens_invalidos(): void
    {
        $equipo = $this->equipo('hikvision');
        $juan = $this->colaborador('1001');
        $this->sinSesion();
        $this->call('POST', '/equipos-acceso/hikvision', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_X_EQUIPO_TOKEN' => $this->tokens[$equipo->id]],
            $this->fixture('hikvision-evento.json'))->assertOk();
        $this->assertCount(1, $this->accesosDe($juan));

        $this->hik($equipo, $this->fixture('hikvision-evento.json'), str_repeat('a', 40))->assertStatus(401)->assertSeeText('ERROR');
        $this->call('POST', '/equipos-acceso/hikvision', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertStatus(401);
        $this->call('POST', '/equipos-acceso/hikvision/corto', [], [], [], ['CONTENT_TYPE' => 'application/json'], '{}')->assertStatus(404);
        // Un ZKTeco no puede usar la ruta de Hikvision con su token
        $zk = $this->equipo();
        $this->hik($zk, $this->fixture('hikvision-evento.json'))->assertStatus(401);
    }

    public function test_cuerpo_que_no_es_un_evento_se_rechaza(): void
    {
        $equipo = $this->equipo('hikvision');
        $this->hik($equipo, '{"hola": "mundo"}')->assertStatus(400);
        $this->hik($equipo, 'no es json')->assertStatus(400);
        $this->hik($equipo, json_encode(['eventType' => 'AccessControllerEvent', 'a' => ['b' => ['c' => ['d' => ['e' => ['f' => ['g' => ['h' => 1]]]]]]]]))->assertStatus(400);
        $this->assertNull($equipo->refresh()->ultimo_contacto_at, 'Lo que no se entiende no cuenta como contacto');
        $this->call('POST', '/equipos-acceso/hikvision/'.$this->tokens[$equipo->id], [], [], [], ['CONTENT_TYPE' => 'application/json', 'CONTENT_LENGTH' => '5000000'], '{}')->assertStatus(413);
    }

    public function test_latido_anota_el_contacto_sin_marcaje(): void
    {
        $equipo = $this->equipo('hikvision');
        $this->hik($equipo, $this->fixture('hikvision-latido.json'))->assertOk()->assertSeeText('OK: 0');
        $this->assertNotNull($equipo->refresh()->ultimo_contacto_at);
        $this->assertNull($equipo->ultimo_marcaje_at);
        $this->assertSame(0, $this->enEmpresa(fn () => MarcajeEquipoAcceso::count()));
    }

    public function test_rostro_y_eventos_que_no_son_un_paso_se_ignoran(): void
    {
        $equipo = $this->equipo('hikvision');
        $juan = $this->colaborador('1001');
        $this->hik($equipo, $this->fixture('hikvision-rostro.json'))->assertOk();
        $this->hik($equipo, $this->fixture('hikvision-boton.json'))->assertOk();

        $this->assertCount(0, $this->accesosDe($juan));
        $marcajes = $this->enEmpresa(fn () => MarcajeEquipoAcceso::orderBy('id')->get());
        $this->assertSame(['ignorado', 'ignorado'], $marcajes->pluck('resultado')->all());
        $this->assertSame('Evento 5/75 (no es un paso válido)', $marcajes->first()->detalle);
    }

    public function test_multipart_con_foto_registra_el_paso_y_no_guarda_la_foto(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        $equipo = $this->equipo('hikvision');
        $juan = $this->colaborador('1001');
        $this->sinSesion();

        $this->post('/equipos-acceso/hikvision/'.$this->tokens[$equipo->id], [
            'event_log' => $this->fixture('hikvision-evento.json'),
            'Picture' => UploadedFile::fake()->image('rostro.jpg', 200, 200),
        ], ['Content-Type' => 'multipart/form-data'])->assertOk()->assertSeeText('OK: 1');

        $this->assertCount(1, $this->accesosDe($juan));
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_multipart_con_el_json_como_archivo(): void
    {
        $equipo = $this->equipo('hikvision');
        $juan = $this->colaborador('1001');
        $this->sinSesion();
        $this->post('/equipos-acceso/hikvision/'.$this->tokens[$equipo->id], [
            'AccessControllerEvent' => UploadedFile::fake()->createWithContent('evento.json', $this->fixture('hikvision-evento.json'))->mimeType('application/json'),
        ], ['Content-Type' => 'multipart/form-data'])->assertOk();
        $this->assertCount(1, $this->accesosDe($juan));
    }

    public function test_check_out_da_la_salida_y_la_tarjeta_identifica_aunque_falte_el_numero_de_empleado(): void
    {
        $equipo = $this->equipo('hikvision');
        $juan = $this->colaborador('1001');
        $this->darCredencial($juan, '0012345678');
        $this->hik($equipo, $this->fixture('hikvision-evento.json'))->assertOk();

        $evento = json_decode($this->fixture('hikvision-evento.json'), true);
        $evento['dateTime'] = '2026-11-03T17:30:00-06:00';
        $evento['AccessControllerEvent']['serialNo'] = 4600;
        $evento['AccessControllerEvent']['attendanceStatus'] = 'checkOut';
        unset($evento['AccessControllerEvent']['employeeNoString']);
        $evento['AccessControllerEvent']['cardNo'] = '00BC614E'; // la misma tarjeta, en hexadecimal
        $this->hik($equipo, json_encode($evento))->assertOk();

        $a = $this->accesosDe($juan)->sole();
        $this->assertSame('finalizado', $a->estado);
        $this->assertSame($equipo->id, (int) $a->equipo_salida_id);
        $this->assertSame('2026-11-03 23:30:00', $a->salida_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_tarjeta_de_una_credencial_dada_de_baja_se_rechaza_y_avisa_a_la_caseta(): void
    {
        $equipo = $this->equipo('hikvision');
        $juan = $this->colaborador('1001');
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $otraSede = $this->crearUsuario($this->empresa, 'Agente', $this->playa);
        $credencial = $this->darCredencial($juan, '0012345678');
        // Reposición por extravío: la vigente se da de baja (con voucher), la tarjeta deja de identificarlo y se emite otra
        $this->enEmpresa(fn () => app(AdministradorCredenciales::class)->reponer($this->admin, $credencial->refresh(),
            ['motivo' => 'extraviado', 'descripcion' => 'Se le perdió', 'aplica_cobro' => '0']));

        $evento = json_decode($this->fixture('hikvision-evento.json'), true);
        unset($evento['AccessControllerEvent']['employeeNoString']);
        $this->hik($equipo, json_encode($evento))->assertOk();

        $this->assertCount(0, $this->accesosDe($juan));
        $marcaje = $this->enEmpresa(fn () => MarcajeEquipoAcceso::sole());
        $this->assertSame(['no_vigente', $juan->id], [$marcaje->resultado, (int) $marcaje->colaborador_id]);
        $aviso = Notificacion::where('user_id', $agente->id)->where('tipo', 'equipo_no_vigente')->firstOrFail();
        $this->assertSame('Credencial no vigente usada en Acceso de personal', $aviso->titulo);
        $this->assertFalse(Notificacion::where('user_id', $otraSede->id)->where('tipo', 'equipo_no_vigente')->exists(), 'Solo la caseta de esa sede');
    }

    public function test_el_equipo_de_otra_empresa_jamas_escribe_en_esta(): void
    {
        $otraEmpresa = $this->crearEmpresa('Corporativo Norte');
        $this->crearSede($otraEmpresa, 'NTE');
        $ajeno = $this->equipo('hikvision', ['numero_serie' => 'DS-AJENO-01'], $otraEmpresa);
        $juan = $this->colaborador('1001'); // mismo número de empleado que pasa en el equipo ajeno

        $this->hik($ajeno, $this->fixture('hikvision-evento.json'))->assertOk();

        $this->assertCount(0, $this->accesosDe($juan));
        $this->assertSame(0, Acceso::withoutGlobalScopes()->count());
        $marcaje = MarcajeEquipoAcceso::withoutGlobalScopes()->sole();
        $this->assertSame([$otraEmpresa->id, 'desconocido'], [(int) $marcaje->empresa_id, $marcaje->resultado]);
    }

    public function test_limite_de_peticiones_por_token(): void
    {
        $equipo = $this->equipo('hikvision');
        for ($i = 0; $i < 240; $i++) {
            $this->hik($equipo, $this->fixture('hikvision-latido.json'));
        }
        $this->hik($equipo, $this->fixture('hikvision-latido.json'))->assertStatus(429);
    }
}
