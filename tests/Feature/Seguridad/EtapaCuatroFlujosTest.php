<?php

namespace Tests\Feature\Seguridad;

use App\Models\Acceso;
use App\Models\Candidato;
use App\Models\Delegacion;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\MovimientoTransporte;
use App\Models\Notificacion;
use App\Models\Novedad;
use App\Models\Postulacion;
use App\Models\Proveedor;
use App\Models\Puesto;
use App\Models\RelojEspera;
use App\Models\Ruta;
use App\Models\Sede;
use App\Models\SolicitudCompra;
use App\Models\User;
use App\Models\Vacante;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Services\Notificaciones\PreferenciasAvisos;
use App\Services\Relojes\AjustesRelojes;
use App\Services\Relojes\MotorRelojes;
use App\Services\Relojes\Relojes\SalidaTemporal;
use App\Support\Menu\MisPendientes;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Etapa 4 · C-22 a C-26 · Cada flujo nuevo con tiempo simulado y sus vínculos:
 * caseta de noche (Pedir al supervisor, aprobación del agente, revisión),
 * permanencia y salida temporal, vacantes y candidatos, vale de taxi,
 * faltante de responsiva, pendiente de turno sin turnos, Super
 * Administrador, «no se silencia lo que no está en Mis pendientes», Inicio y
 * Configuración.
 */
class EtapaCuatroFlujosTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private User $admin;

    private User $agente;

    private User $supervisor;

    private User $jefe;

    private User $rh;

    private User $jefeDepto;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Mail::fake();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa('Hotel Reloj');
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->supervisor = $this->crearUsuario($this->empresa, 'Supervisor', $this->centro);
        $this->jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        $this->rh = $this->crearUsuario($this->empresa, 'Recursos Humanos', $this->centro);
        $this->jefeDepto = $this->crearUsuario($this->empresa, 'Jefe de departamento', $this->centro);
        // El motor ya venía corriendo en esta empresa (lo de antes de arrancar cuenta desde el arranque)
        RelojEspera::query()->insert(['empresa_id' => $this->empresa->id, 'reloj' => MotorRelojes::ARRANQUE, 'referencia_tipo' => 'empresa',
            'referencia_id' => $this->empresa->id, 'desde' => now()->subYear(), 'created_at' => now(), 'updated_at' => now()]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function enEmpresa(callable $fn): mixed
    {
        return app(Tenant::class)->conEmpresa($this->empresa->id, $fn);
    }

    private function avanzar(int $minutos): void
    {
        Carbon::setTestNow(now()->addMinutes($minutos));
        $this->flushSession();
    }

    /** Lo que hace la campana al consultarse: correr el motor (con su candado de un minuto). */
    private function campana(): void
    {
        Cache::flush();
        $this->app->forgetScopedInstances();
        app(MotorRelojes::class)->correrSiToca($this->empresa->id);
    }

    private function avisos(User $u, string $tipo): int
    {
        return Notificacion::query()->withoutGlobalScopes()->where('user_id', $u->id)->where('tipo', $tipo)->count();
    }

    /** @return array<string, array<string, mixed>> */
    private function pendientes(User $u): array
    {
        Cache::flush();
        $this->app->forgetScopedInstances();

        return collect(app(MisPendientes::class)->para($u)['items'])->keyBy('clave')->all();
    }

    /** @param array<string, mixed> $caseta */
    private function ajustesCaseta(array $caseta): void
    {
        $this->empresa->refresh();
        $this->empresa->forceFill(['preferencias' => array_merge($this->empresa->preferencias ?? [], ['caseta' => $caseta])])->save();
    }

    private function proveedorPendiente(string $nombre = 'Pipa de gas'): Acceso
    {
        // Un proveedor que espera al host: la caseta no lo puede pasar sola
        $this->actingAs($this->agente)->post('/accesos', [
            'sede_id' => $this->centro->id, 'tipo' => 'proveedor', 'nombre' => $nombre, 'empresa_procedencia' => 'Gas del Sur',
            'modo_arribo' => 'a_pie', 'identificacion' => 'ine', 'host_colaborador_id' => null,
        ]);

        return $this->enEmpresa(fn () => Acceso::where('nombre', mb_strtoupper($nombre))->latest('id')->firstOrFail());
    }

    private function pendienteEnCaseta(string $nombre): Acceso
    {
        return $this->enEmpresa(function () use ($nombre) {
            $a = new Acceso(['sede_id' => $this->centro->id, 'tipo' => 'proveedor', 'nombre' => mb_strtoupper($nombre), 'modo_arribo' => 'a_pie', 'identificacion' => 'ine']);
            $a->forceFill(['estado' => 'pendiente', 'movimiento' => 'entrada', 'entrada_at' => now(), 'creado_por' => $this->agente->id])->save();

            return $a;
        });
    }

    // ============================================================ C-25 · Caseta de noche

    public function test_pedir_al_supervisor_recuerda_pasa_al_jefe_y_el_agente_aprueba_con_motivo_y_foto(): void
    {
        Carbon::setTestNow(now()->startOfMinute());
        $a = $this->pendienteEnCaseta('Pipa de gas');

        // La tarjeta del agente ofrece «Pedir al supervisor» (un toque, sin pasos en el flujo normal)
        $this->actingAs($this->agente)->get('/accesos?pestana=pendientes')->assertOk()->assertSee('Pedir al supervisor')->assertDontSee('Aprobar por excepción');
        $this->actingAs($this->agente)->post(route('accesos.pedir-supervisor', $a->id))->assertSessionHas('ok');
        $this->actingAs($this->agente)->post(route('accesos.pedir-supervisor', $a->id))->assertSessionHas('error'); // una sola vez
        $this->assertSame(1, $this->avisos($this->supervisor, 'excepcion_pedida'));
        $this->assertSame(1, $this->pendientes($this->supervisor)['esperan_supervisor']['total']);

        // Sin encender DD-13, el agente no puede aprobar aunque pase el tiempo
        $this->avanzar(25);
        $this->campana();
        $this->assertSame(1, $this->avisos($this->supervisor, MotorRelojes::TIPO_RECORDATORIO));
        $this->assertSame(1, $this->avisos($this->jefe, MotorRelojes::TIPO_ESCALADO), 'Pasa al jefe de seguridad a los 20 min de la caseta');
        $this->assertSame(1, $this->avisos($this->agente, 'autorizacion_escalada'), 'La caseta se entera de a quién se pasó');
        $this->actingAs($this->agente)->patch(route('accesos.excepcion-agente', $a->id), ['motivo_agente' => 'Fuga de gas'])->assertSessionHas('error');

        // Con DD-13 encendido: motivo y foto obligatorios
        $this->ajustesCaseta(['agente_excepcion' => 'si']);
        $this->actingAs($this->agente)->get('/accesos?pestana=pendientes')->assertSee('Aprobar por excepción');
        $this->actingAs($this->agente)->patch(route('accesos.excepcion-agente', $a->id), ['motivo_agente' => 'Fuga de gas'])
            ->assertSessionHas('error', 'Toma la foto de la persona: sin foto no se puede aprobar por excepción.');
        $this->actingAs($this->agente)->patch(route('accesos.excepcion-agente', $a->id), ['motivo_agente' => '', 'foto_agente' => UploadedFile::fake()->image('p.jpg')])
            ->assertSessionHas('error');
        $this->actingAs($this->agente)->patch(route('accesos.excepcion-agente', $a->id), ['motivo_agente' => 'Fuga de gas en cocina', 'foto_agente' => UploadedFile::fake()->image('p.jpg')])
            ->assertSessionHas('ok');
        $a = $this->enEmpresa(fn () => Acceso::findOrFail($a->id));
        $this->assertSame(['en_sitio', Acceso::POR_EXCEPCION, true], [$a->estado, $a->autorizacion, $a->excepcion_por_revisar]);
        $this->assertNotNull($a->foto_persona);
        $this->assertStringContainsString('Agente sin supervisor: Fuga de gas en cocina', $a->motivo_excepcion);
        // Aviso inmediato al supervisor y al jefe, que no se puede silenciar
        $this->assertSame(1, $this->avisos($this->supervisor, 'excepcion_agente'));
        $this->assertSame(1, $this->avisos($this->jefe, 'excepcion_agente'));
        $this->assertTrue(PreferenciasAvisos::noSilenciable('excepcion_agente'));
        // El pedido se apagó (sin botones) y queda para revisarlo en Mis pendientes
        $this->assertSame(0, Notificacion::query()->withoutGlobalScopes()->where('referencia_tipo', 'acceso')->where('referencia_id', $a->id)->whereNotNull('acciones')->count());
        $this->assertSame(1, $this->pendientes($this->supervisor)['excepciones_caseta']['total']);
        $this->actingAs($this->supervisor)->get('/accesos?excepciones=1')->assertOk()->assertSee('PIPA DE GAS')->assertSee('por revisar');

        // «Atender»: queda revisada, se va de Mis pendientes y el agente no puede hacerlo
        $this->actingAs($this->agente)->patch(route('accesos.revisar-excepcion', $a->id))->assertForbidden();
        $this->actingAs($this->supervisor)->patch(route('accesos.revisar-excepcion', $a->id))->assertSessionHas('ok');
        $this->assertArrayNotHasKey('excepciones_caseta', $this->pendientes($this->supervisor));
        $this->actingAs($this->supervisor)->patch(route('accesos.revisar-excepcion', $a->id))->assertSessionHas('error');
    }

    public function test_el_recordatorio_de_quien_delego_llega_al_ultimo_de_su_cadena_que_puede_atenderlo(): void
    {
        // C-12 (Etapa 3): supervisor → supervisor 2 → agente. El agente no puede autorizar: le llega al supervisor 2
        Carbon::setTestNow(now()->startOfMinute());
        $supervisor2 = $this->crearUsuario($this->empresa, 'Supervisor', $this->centro);
        $this->enEmpresa(function () use ($supervisor2) {
            Delegacion::create(['user_id' => $this->supervisor->id, 'delegado_id' => $supervisor2->id, 'desde' => now()->subHour(), 'hasta' => now()->addDays(2)]);
            Delegacion::create(['user_id' => $supervisor2->id, 'delegado_id' => $this->agente->id, 'desde' => now()->subHour(), 'hasta' => now()->addDays(2)]);
        });
        $a = $this->pendienteEnCaseta('Pipa de agua');
        $this->actingAs($this->agente)->post(route('accesos.pedir-supervisor', $a->id))->assertSessionHas('ok');

        $this->avanzar(25);
        $this->campana();
        $this->campana();
        $this->assertSame(0, $this->avisos($this->supervisor, MotorRelojes::TIPO_RECORDATORIO), 'Quien delegó no recibe el recordatorio');
        $this->assertSame(1, $this->avisos($supervisor2, MotorRelojes::TIPO_RECORDATORIO), 'Le llega una sola vez al último de la cadena que puede autorizar');
        $this->assertSame(0, $this->avisos($this->agente, MotorRelojes::TIPO_RECORDATORIO), 'El agente no puede autorizar: no le llega');
        $this->assertSame(1, $this->avisos($this->jefe, MotorRelojes::TIPO_ESCALADO));
    }

    public function test_el_agente_no_aprueba_antes_de_tiempo_y_la_foto_del_registro_sirve(): void
    {
        Carbon::setTestNow(now()->startOfMinute());
        $this->ajustesCaseta(['agente_excepcion' => 'si']);
        $a = $this->pendienteEnCaseta('Técnico de elevador');
        $this->enEmpresa(fn () => Acceso::whereKey($a->id)->update(['foto_persona' => 'privado/foto.jpg']));
        $this->actingAs($this->agente)->post(route('accesos.pedir-supervisor', $a->id));
        $this->avanzar(5);
        $this->actingAs($this->agente)->patch(route('accesos.excepcion-agente', $a->id), ['motivo_agente' => 'Gente atrapada'])
            ->assertSessionHas('error', 'Todavía no puedes aprobar por excepción: espera la respuesta del supervisor o del jefe de seguridad.');
        $this->actingAs($this->agente)->get('/accesos?pestana=pendientes')->assertSee('podrás aprobarlo por excepción');
        $this->avanzar(20);
        $this->actingAs($this->agente)->patch(route('accesos.excepcion-agente', $a->id), ['motivo_agente' => 'Gente atrapada'])->assertSessionHas('ok');
        // El supervisor que sí puede autorizar no ve los botones del agente
        $this->assertSame('privado/foto.jpg', $this->enEmpresa(fn () => Acceso::findOrFail($a->id)->foto_persona));
    }

    public function test_visita_sin_quien_autorice_entra_al_mismo_reloj_del_supervisor(): void
    {
        // C-08 / DD-11: el departamento no tiene responsable en la sede → espera al supervisor, con reloj y suplente
        Carbon::setTestNow(now()->startOfMinute());
        $this->empresa->refresh()->forceFill(['preferencias' => array_merge($this->empresa->preferencias ?? [], ['recepcion' => ['visitas_requieren_autorizacion' => true]])])->save();
        $depto = $this->enEmpresa(fn () => Departamento::create(['nombre' => 'Recepción']));
        $this->actingAs($this->agente)->post('/accesos', ['sede_id' => $this->centro->id, 'tipo' => 'visitante', 'nombre' => 'Ana Gómez', 'motivo_visita' => 'departamento',
            'departamento_id' => $depto->id, 'modo_arribo' => 'a_pie', 'identificacion' => 'ine'])->assertSessionHas('ok');
        $this->avanzar(25);
        $this->campana();
        $this->assertGreaterThan(0, $this->avisos($this->supervisor, MotorRelojes::TIPO_RECORDATORIO));
        $this->assertSame(1, $this->avisos($this->jefe, MotorRelojes::TIPO_ESCALADO));
        $this->campana();
        $this->assertSame(1, $this->avisos($this->jefe, MotorRelojes::TIPO_ESCALADO), 'Una sola vez');
    }

    public function test_permanencia_y_salida_temporal_avisan_y_se_filtran(): void
    {
        Carbon::setTestNow(now()->startOfMinute());
        [$visita, $proveedor, $fuera] = $this->enEmpresa(function () {
            $crear = function (array $datos) {
                $a = new Acceso(['sede_id' => $this->centro->id, 'modo_arribo' => 'a_pie', 'identificacion' => 'ine'] + $datos);
                $a->forceFill(array_merge(['estado' => 'en_sitio', 'movimiento' => 'entrada', 'entrada_at' => now(), 'creado_por' => $this->agente->id],
                    array_intersect_key($datos, array_flip(['movimiento', 'acceso_origen_id']))))->save();

                return $a;
            };
            $visita = $crear(['tipo' => 'visitante', 'nombre' => 'VISITA LARGA']);
            $proveedor = $crear(['tipo' => 'proveedor', 'nombre' => 'PROVEEDOR CORTO']);
            $fuera = $crear(['tipo' => 'proveedor', 'nombre' => 'PROVEEDOR CORTO', 'movimiento' => 'salida_temporal', 'acceso_origen_id' => $proveedor->id]);

            return [$visita, $proveedor, $fuera];
        });

        // A las 3 h nadie rebasa (visita 4 h, salida temporal 2 h → la salida sí)
        $this->avanzar(3 * 60);
        $this->campana();
        $this->actingAs($this->supervisor)->get('/accesos?rebasaron=1')->assertOk()->assertSee('PROVEEDOR CORTO')->assertDontSee('VISITA LARGA');
        $this->assertSame(1, $this->avisos($this->supervisor, MotorRelojes::TIPO_RECORDATORIO));

        // A las 5 h la visita rebasó sus 4 h: tarjeta con color, filtro y aviso al supervisor
        $this->avanzar(2 * 60);
        $this->campana();
        $this->actingAs($this->supervisor)->get('/accesos?rebasaron=1')->assertSee('VISITA LARGA')->assertSee('Rebasó su tiempo');
        $this->assertSame(1, $this->pendientes($this->supervisor)['permanencia']['total']);
        // A las 9 h (el doble) la visita pasa al jefe de seguridad
        $this->avanzar(4 * 60);
        $this->campana();
        $this->assertGreaterThanOrEqual(2, $this->avisos($this->jefe, MotorRelojes::TIPO_ESCALADO));

        // Al darle salida se va del reloj (y sus avisos pierden el botón)
        $this->actingAs($this->supervisor)->patch(route('accesos.salida', $visita->id))->assertSessionHas('ok');
        $this->campana();
        $this->assertSame(0, Notificacion::query()->withoutGlobalScopes()->where('referencia_tipo', 'acceso_permanencia')->where('referencia_id', $visita->id)->whereNotNull('acciones')->count());
        $this->assertSame(1, $this->pendientes($this->supervisor)['permanencia']['total'], 'Queda el proveedor, que también rebasó sus 8 h');

        // Apagado (0) ya no corre
        $this->ajustesCaseta(['salida_temporal_horas' => 0]);
        $this->app->forgetScopedInstances();
        $this->assertSame([], app(SalidaTemporal::class)->esperas($this->empresa->id)->all());
    }

    // ============================================================ C-23 · RR. HH.

    public function test_la_vacante_que_pide_un_jefe_espera_con_reloj_y_el_jefe_sabe_que_paso(): void
    {
        Carbon::setTestNow(now()->startOfMinute());
        [$depto, $puesto] = $this->enEmpresa(fn () => [Departamento::create(['nombre' => 'Cocina']), Puesto::create(['nombre' => 'Cocinero'])]);
        $datos = ['titulo' => 'Cocinero de línea', 'puesto_id' => $puesto->id, 'departamento_id' => $depto->id, 'plazas' => '1', 'todas_las_sedes' => '0',
            'sedes' => [$this->centro->id], 'tipo_contrato' => 'indeterminado', 'jornada' => 'completa', 'sueldo_periodo' => 'mensual', 'descripcion' => 'Preparar platillos.'];
        $this->actingAs($this->jefeDepto)->post('/vacantes', $datos)->assertSessionHas('ok');
        $v = $this->enEmpresa(fn () => Vacante::sole());
        $this->assertSame([(int) $this->jefeDepto->id, true], [(int) $v->propuesta_por, $v->propuesta_en !== null]);
        // Campana como alerta (E-17) y renglón en Mis pendientes de RR. HH.
        $this->assertSame('alerta', Notificacion::query()->withoutGlobalScopes()->where('tipo', 'vacante_propuesta')->value('nivel'));
        $this->assertSame(1, $this->pendientes($this->rh)['vacantes_propuestas']['total']);
        $this->assertFalse(PreferenciasAvisos::noSilenciable('vacante_propuesta'), 'Ya tiene renglón: se puede silenciar sin perderla');

        // Reloj: 25 h → recordatorio; 73 h → suplente (el administrador)
        $this->avanzar(25 * 60);
        $this->campana();
        $this->assertSame(1, $this->avisos($this->rh, MotorRelojes::TIPO_RECORDATORIO));
        $this->assertSame('ambar', $this->pendientes($this->rh)['vacantes_propuestas']['nivel']);
        $this->avanzar(48 * 60);
        $this->campana();
        $this->assertSame(1, $this->avisos($this->admin, MotorRelojes::TIPO_ESCALADO));

        // RR. HH. la publica: el jefe se entera y ya no espera
        $this->actingAs($this->rh)->patch(route('vacantes.estado', $v->id), ['estado' => 'publicada'])->assertSessionHas('ok');
        $this->assertSame(1, $this->avisos($this->jefeDepto, 'vacante_resultado'));
        $this->assertStringContainsString('se publicó', Notificacion::query()->withoutGlobalScopes()->where('tipo', 'vacante_resultado')->value('titulo'));
        $this->assertArrayNotHasKey('vacantes_propuestas', $this->pendientes($this->rh));

        // Otra propuesta que RR. HH. elimina: también se entera
        $this->actingAs($this->jefeDepto)->post('/vacantes', ['titulo' => 'Ayudante de cocina'] + $datos)->assertSessionHas('ok');
        $otra = $this->enEmpresa(fn () => Vacante::where('titulo', 'Ayudante de cocina')->firstOrFail());
        $this->actingAs($this->admin)->delete(route('vacantes.destroy', $otra->id));
        $this->assertSame(2, $this->avisos($this->jefeDepto, 'vacante_resultado'));
    }

    public function test_candidatos_sin_atender_de_dias_anteriores_y_lleva_n_dias_en_la_tarjeta(): void
    {
        Carbon::setTestNow(now()->startOfMinute());
        $c = $this->enEmpresa(function () {
            $c = new Candidato(['sede_id' => $this->centro->id, 'nombre_completo' => 'Silvia Mena', 'telefono' => '9981234567']);
            $c->forceFill(['etapa' => 'registrado', 'llegada_en' => now()->subDays(2), 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)])->save();
            $p = new Postulacion(['sede_id' => $this->centro->id, 'candidato_id' => $c->id]);
            $p->forceFill(['etapa' => 'registrado', 'created_at' => now()->subDays(2), 'updated_at' => now()->subDays(2)])->save();

            return $c;
        });
        // Ya no desaparece de Mis pendientes al día siguiente (E-07)
        $items = $this->pendientes($this->rh);
        $this->assertSame(0, $items['recepcion']['total']);
        $this->assertSame(1, $items['sin_atender']['total']);
        $this->assertSame('ambar', $items['sin_atender']['nivel']);
        $this->actingAs($this->rh)->get('/candidatos')->assertOk()->assertSee('Lleva 2 días');
        // Inicio lo agrupa en «Candidatos»
        $this->actingAs($this->rh)->get('/')->assertOk()->assertSee('Candidatos sin atender');
        // Al doble del plazo (4 días) pasa al suplente: el administrador (RR. HH. de toda la empresa no hay)
        Carbon::setTestNow(now()->addHours(1));
        $this->campana();
        $this->avanzar(4 * 24 * 60);
        $this->campana();
        $this->assertSame(1, $this->avisos($this->admin, MotorRelojes::TIPO_ESCALADO));
        $this->assertSame('Silvia Mena', $c->nombre_completo);
    }

    // ============================================================ C-24 · Operación

    public function test_el_vale_de_taxi_se_aprueba_o_rechaza_y_el_guardia_se_entera(): void
    {
        [$vale, $otro] = $this->enEmpresa(function () {
            $crear = function () {
                $proveedor = Proveedor::first() ?? tap(new Proveedor, fn ($p) => $p->forceFill(['nombre' => 'TRANSPORTES DEL SUR', 'activo' => true])->save());
                $ruta = Ruta::first() ?? tap(new Ruta, fn ($r) => $r->forceFill(['sede_id' => $this->centro->id, 'sentido' => 'llegada', 'nombre' => 'Ruta 1',
                    'proveedor_id' => $proveedor->id, 'hora_inicio' => '06:00', 'hora_fin' => '07:00', 'dias' => '1,2,3,4,5,6,7', 'activo' => true])->save());
                $m = new MovimientoTransporte(['sede_id' => $this->centro->id, 'tipo_movimiento' => 'llegada', 'fecha' => now()->toDateString(), 'monto' => 150]);
                $m->forceFill(['estatus' => 'no_llego', 'creado_por' => $this->agente->id, 'ruta_id' => $ruta->id])->save();

                return $m;
            };

            return [$crear(), $crear()];
        });
        $this->assertSame(2, $this->pendientes($this->supervisor)['vales_taxi']['total']);
        $this->actingAs($this->supervisor)->patch(route('transporte.rechazar', $vale->id), ['motivo_rechazo' => ''])->assertSessionHasErrors('motivo_rechazo');
        $this->actingAs($this->agente)->patch(route('transporte.rechazar', $vale->id), ['motivo_rechazo' => 'No'])->assertForbidden();
        $this->actingAs($this->supervisor)->patch(route('transporte.rechazar', $vale->id), ['motivo_rechazo' => 'La unidad sí llegó'])->assertSessionHas('ok');
        $this->actingAs($this->supervisor)->patch(route('transporte.autorizar', $vale->id))->assertSessionHasErrors('estado');
        $this->actingAs($this->supervisor)->patch(route('transporte.autorizar', $otro->id))->assertSessionHas('ok');
        $this->assertSame(2, $this->avisos($this->agente, 'vale_resultado'));
        $titulos = Notificacion::query()->withoutGlobalScopes()->where('tipo', 'vale_resultado')->pluck('titulo')->implode(' | ');
        $this->assertStringContainsString('rechazado', $titulos);
        $this->assertStringContainsString('aprobado', $titulos);
        $this->assertArrayNotHasKey('vales_taxi', array_filter($this->pendientes($this->supervisor), fn ($i) => $i['total'] > 0));
        $this->actingAs($this->supervisor)->get(route('transporte.show', $vale->id))->assertOk()->assertSee('Vo.Bo. rechazado')->assertSee('La unidad sí llegó');
    }

    public function test_pendiente_de_turno_sin_turnos_pasa_al_jefe_a_las_ocho_horas(): void
    {
        Carbon::setTestNow(now()->startOfMinute());
        $n = $this->enEmpresa(function () {
            $n = new Novedad(['sede_id' => $this->centro->id, 'categoria' => 'incidente_general', 'reportado_por' => 'GUARDIA', 'ubicacion' => 'LOBBY', 'descripcion' => 'Fuga']);
            $n->forceFill(['numero' => 1, 'estatus' => Novedad::PENDIENTE_TURNO, 'pendiente_turno_en' => now(), 'turno_avisado_en' => now()])->save();

            return $n;
        });
        $this->avanzar(7 * 60);
        $this->campana();
        $this->assertSame(0, $this->avisos($this->jefe, 'novedad_turno_escalada'));
        $this->avanzar(61);
        $this->campana();
        $this->assertSame(1, $this->avisos($this->jefe, 'novedad_turno_escalada'), 'D-05: sin turnos, plazo fijo de 8 h');
        $this->assertStringContainsString('Pasaron 8 horas', Notificacion::query()->withoutGlobalScopes()->where('tipo', 'novedad_turno_escalada')->value('texto'));
        $this->assertNotNull($n->fresh());
    }

    public function test_ticket_canalizado_lleva_reloj_por_categoria(): void
    {
        Carbon::setTestNow(now()->startOfMinute());
        $n = $this->enEmpresa(function () {
            $n = new Novedad(['sede_id' => $this->centro->id, 'categoria' => 'accidente', 'reportado_por' => 'GUARDIA', 'ubicacion' => 'ALBERCA', 'descripcion' => 'Caída']);
            $n->forceFill(['numero' => 1, 'estatus' => 'abierto', 'asignado_a' => $this->agente->id])->save();

            return $n;
        });
        $this->assertNotNull($n->canalizado_en);
        // Accidente: 2 h al canalizado, 6 h al jefe
        $this->avanzar(121);
        $this->campana();
        $this->assertSame(1, $this->avisos($this->agente, MotorRelojes::TIPO_RECORDATORIO));
        $this->assertSame('ambar', $this->pendientes($this->agente)['novedades_mias']['nivel']);
        $this->avanzar(4 * 60);
        $this->campana();
        $this->assertSame(1, $this->avisos($this->jefe, MotorRelojes::TIPO_ESCALADO));
        // Se canaliza a otra persona: el reloj vuelve a cero para ella
        $this->enEmpresa(fn () => $n->forceFill(['asignado_a' => $this->supervisor->id])->save());
        $this->avanzar(60);
        $this->campana();
        $this->assertSame(0, $this->avisos($this->supervisor, MotorRelojes::TIPO_RECORDATORIO));
        $this->avanzar(61);
        $this->campana();
        $this->assertSame(1, $this->avisos($this->supervisor, MotorRelojes::TIPO_RECORDATORIO));
    }

    // ============================================================ C-26 · Super Administrador

    public function test_el_super_administrador_tiene_mis_pendientes_y_sus_avisos_se_resuelven_con_empresa_de_trabajo(): void
    {
        $sa = $this->crearSuperadmin();
        $s = SolicitudCompra::query()->withoutGlobalScopes()->forceCreate(['folio' => 'SOL-0001', 'nombre' => 'Luis', 'empresa' => 'Hotel Nuevo',
            'correo' => 'luis@ejemplo.com', 'estado' => 'nueva', 'tipo' => 'compra', 'acepta_privacidad' => true]);
        app(Tenant::class)->conEmpresa(null, fn () => app(CentroNotificaciones::class)->avisarPlataforma([$sa->id], 'solicitud_compra',
            ['titulo' => 'Nueva solicitud', 'referencia_tipo' => 'solicitud_compra', 'referencia_id' => $s->id]));

        // Sin empresa de trabajo: la campana trae sus pendientes (E-05)
        $this->actingAs($sa)->getJson('/notificaciones/resumen')->assertOk()->assertJsonPath('pendientes.total', 1);
        $this->actingAs($sa)->get('/')->assertOk()->assertSee('1 solicitud de compra por atender')->assertDontSee('Estás al día');

        // Con una empresa de trabajo elegida, resolver apaga también el aviso de la plataforma
        app(Tenant::class)->conEmpresa($this->empresa->id, fn () => app(CentroNotificaciones::class)->resolver('solicitud_compra', $s->id));
        $this->assertNotNull(Notificacion::query()->withoutGlobalScopes()->where('tipo', 'solicitud_compra')->value('leida_en'));
    }

    // ============================================================ C-22 · Reglas

    public function test_no_se_puede_silenciar_lo_que_no_tiene_renglon_y_todo_aviso_que_espera_tiene_el_suyo(): void
    {
        foreach (CentroNotificaciones::TIPOS as $tipo => [, $nivel]) {
            if ($nivel === 'alerta') {
                $this->assertTrue(array_key_exists($tipo, PreferenciasAvisos::RENGLON_DE_TIPO) || in_array($tipo, PreferenciasAvisos::NO_SILENCIABLES, true)
                    || in_array($tipo, PreferenciasAvisos::SOLO_INFORMAN, true), "El aviso «{$tipo}» espera una acción y no tiene renglón en Mis pendientes");
            }
        }
        $this->assertTrue(PreferenciasAvisos::noSilenciable(MotorRelojes::TIPO_ESCALADO));
        $this->assertFalse(PreferenciasAvisos::noSilenciable(MotorRelojes::TIPO_RECORDATORIO));
        $this->assertTrue(PreferenciasAvisos::noSilenciable('un_aviso_nuevo_sin_renglon') === false, 'Un tipo desconocido es informativo');

        // Aunque llegue en el formulario, «reloj_escalado» no se guarda como silenciado
        app(PreferenciasAvisos::class)->guardar($this->supervisor, ['campana' => []]);
        $this->assertNotContains(MotorRelojes::TIPO_ESCALADO, PreferenciasAvisos::silenciados($this->supervisor->refresh())['campana']);
        $this->assertSame([$this->supervisor->id], PreferenciasAvisos::quienesLoReciben([$this->supervisor->id], MotorRelojes::TIPO_ESCALADO));
        $this->actingAs($this->supervisor)->get('/mi-cuenta')->assertOk()->assertSee('siempre llega');
    }

    public function test_configuracion_reloj_y_suplente_se_guarda_valida_y_restablece(): void
    {
        $this->actingAs($this->admin)->get('/configuracion')->assertOk()->assertSee('Reloj y suplente')->assertSee('Tiempo de permanencia en sitio')->assertSee('Noche sin supervisor');
        $this->actingAs($this->admin)->put(route('configuracion.relojes'), ['vobo_horas_amarillo' => 12, 'vobo_horas_rojo' => 6, 'encendido' => 1])
            ->assertSessionHasErrors('vobo_horas_rojo');
        $this->actingAs($this->admin)->put(route('configuracion.relojes'), ['vobo_horas_amarillo' => 12, 'vobo_horas_rojo' => 36, 'encendido' => 1,
            'modulos' => ['vales_taxi' => ['ambar' => 3, 'suplente' => 2, 'encendido' => 1]]])->assertSessionHasErrors('modulos.vales_taxi.suplente');
        $this->actingAs($this->admin)->put(route('configuracion.relojes'), ['vobo_horas_amarillo' => 12, 'vobo_horas_rojo' => 36,
            'modulos' => ['vales_taxi' => ['ambar' => 3, 'suplente' => 6, 'encendido' => 1], 'altas' => ['encendido' => 0]]])->assertSessionHas('ok');
        $this->empresa->refresh();
        $ajustes = app(AjustesRelojes::class);
        $motor = app(MotorRelojes::class);
        $this->assertSame(['encendido' => false, 'ambar' => 12, 'suplente' => 36], $ajustes->generales($this->empresa), 'Sin la casilla, apagado');
        $this->assertSame([180, 360], array_slice(array_values($ajustes->de($this->empresa, $motor->reloj('vales_taxi'))), 1, 2));
        // Restablecer regresa todo a 24 / 72 encendido
        $this->actingAs($this->admin)->put(route('configuracion.restablecer'), ['ajuste' => 'vobo'])->assertSessionHas('ok');
        $this->assertSame(['encendido' => true, 'ambar' => 24, 'suplente' => 72], $ajustes->generales($this->empresa->refresh()));
        // Solo con alcance de empresa
        $this->actingAs($this->supervisor)->put(route('configuracion.relojes'), ['vobo_horas_amarillo' => 1])->assertForbidden();
    }
}
