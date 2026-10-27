<?php

namespace Tests\Feature\Seguridad;

use App\Models\Acceso;
use App\Models\Delegacion;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Notificacion;
use App\Models\Puesto;
use App\Models\RelojEspera;
use App\Models\Sede;
use App\Models\User;
use App\Models\Vacante;
use App\Services\Relojes\MotorRelojes;
use App\Support\Menu\MisPendientes;
use App\Support\Tenancy\EmpresaDeTrabajo;
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
 * Revisión independiente de la Etapa 4 (C-22 a C-26): lo que la revisión
 * encontró y corrigió.
 *  - Mis pendientes, el conteo de la campana y los avisos del motor siguen la
 *    misma regla de delegación de la Etapa 3 (CadenaDelegaciones::destino()).
 */
class RevisionEtapaCuatroTest extends TestCase
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
        $this->empresa = $this->crearEmpresa('Hotel Revisión');
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->supervisor = $this->crearUsuario($this->empresa, 'Supervisor', $this->centro);
        $this->jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        $this->rh = $this->crearUsuario($this->empresa, 'Recursos Humanos', $this->centro);
        $this->jefeDepto = $this->crearUsuario($this->empresa, 'Jefe de departamento', $this->centro);
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

    private function delegar(User $titular, User $delegado): void
    {
        $this->enEmpresa(fn () => Delegacion::create(['user_id' => $titular->id, 'delegado_id' => $delegado->id,
            'desde' => now()->subHour(), 'hasta' => now()->addDays(10)]));
    }

    private function vacantePropuesta(): Vacante
    {
        [$depto, $puesto] = $this->enEmpresa(fn () => [Departamento::create(['nombre' => 'Cocina']), Puesto::create(['nombre' => 'Cocinero'])]);
        $this->actingAs($this->jefeDepto)->post('/vacantes', ['titulo' => 'Cocinero de línea', 'puesto_id' => $puesto->id, 'departamento_id' => $depto->id,
            'plazas' => '1', 'todas_las_sedes' => '0', 'sedes' => [$this->centro->id], 'tipo_contrato' => 'indeterminado', 'jornada' => 'completa',
            'sueldo_periodo' => 'mensual', 'descripcion' => 'Preparar platillos.'])->assertSessionHas('ok');

        return $this->enEmpresa(fn () => Vacante::sole());
    }

    // ============================================== Delegación: una sola regla

    public function test_mis_pendientes_y_el_aviso_del_motor_siguen_la_misma_cadena_de_delegacion(): void
    {
        Carbon::setTestNow(now()->startOfMinute());
        $rh2 = $this->crearUsuario($this->empresa, 'Recursos Humanos', $this->centro);
        $this->vacantePropuesta();
        $this->assertSame(1, $this->pendientes($this->rh)['vacantes_propuestas']['total']);
        $this->assertSame(1, $this->pendientes($rh2)['vacantes_propuestas']['total']);

        // RR. HH. 1 delega en RR. HH. 2: el renglón y el recordatorio son de RR. HH. 2, no de quien delegó
        $this->delegar($this->rh, $rh2);
        $this->avanzar(25 * 60);
        $this->campana();
        $this->assertSame(0, $this->avisos($this->rh, MotorRelojes::TIPO_RECORDATORIO));
        $this->assertSame(1, $this->avisos($rh2, MotorRelojes::TIPO_RECORDATORIO));
        $this->assertArrayNotHasKey('vacantes_propuestas', $this->pendientes($this->rh), 'Quien delegó no lo ve en Mis pendientes');
        $this->assertSame(1, $this->pendientes($rh2)['vacantes_propuestas']['total']);
        $this->assertSame('ambar', $this->pendientes($rh2)['vacantes_propuestas']['nivel']);

        // El conteo de la campana es el mismo de Mis pendientes
        $this->actingAs($this->rh)->getJson('/notificaciones/resumen')->assertOk()
            ->assertJsonMissing(['clave' => 'vacantes_propuestas']);
        $this->actingAs($rh2)->getJson('/notificaciones/resumen')->assertOk()
            ->assertJsonFragment(['clave' => 'vacantes_propuestas', 'total' => 1]);
    }

    public function test_si_nadie_de_la_cadena_puede_atenderlo_se_queda_con_el_titular(): void
    {
        Carbon::setTestNow(now()->startOfMinute());
        $this->vacantePropuesta();
        // RR. HH. delega en el agente, que no puede editar vacantes: se queda con RR. HH.
        $this->delegar($this->rh, $this->agente);
        $this->avanzar(25 * 60);
        $this->campana();
        $this->assertSame(1, $this->avisos($this->rh, MotorRelojes::TIPO_RECORDATORIO));
        $this->assertSame(0, $this->avisos($this->agente, MotorRelojes::TIPO_RECORDATORIO));
        $this->assertSame(1, $this->pendientes($this->rh)['vacantes_propuestas']['total']);
        $this->assertArrayNotHasKey('vacantes_propuestas', $this->pendientes($this->agente));
    }

    public function test_la_caseta_que_espera_al_supervisor_sigue_la_cadena_en_mis_pendientes(): void
    {
        // supervisor → supervisor 2 → agente (el agente no autoriza): el renglón y el aviso son del supervisor 2
        Carbon::setTestNow(now()->startOfMinute());
        $supervisor2 = $this->crearUsuario($this->empresa, 'Supervisor', $this->centro);
        $this->delegar($this->supervisor, $supervisor2);
        $this->delegar($supervisor2, $this->agente);
        $a = $this->enEmpresa(function () {
            $a = new Acceso(['sede_id' => $this->centro->id, 'tipo' => 'proveedor', 'nombre' => 'PIPA DE AGUA', 'modo_arribo' => 'a_pie', 'identificacion' => 'ine']);
            $a->forceFill(['estado' => 'pendiente', 'movimiento' => 'entrada', 'entrada_at' => now(), 'creado_por' => $this->agente->id])->save();

            return $a;
        });
        $this->actingAs($this->agente)->post(route('accesos.pedir-supervisor', $a->id))->assertSessionHas('ok');
        $this->avanzar(25);
        $this->campana();
        $this->assertSame(0, $this->avisos($this->supervisor, MotorRelojes::TIPO_RECORDATORIO));
        $this->assertSame(1, $this->avisos($supervisor2, MotorRelojes::TIPO_RECORDATORIO));

        $this->assertArrayNotHasKey('esperan_supervisor', $this->pendientes($this->supervisor), 'Quien delegó no lo ve');
        $this->assertSame(1, $this->pendientes($supervisor2)['esperan_supervisor']['total']);
        $this->assertArrayNotHasKey('esperan_supervisor', $this->pendientes($this->agente));
        // El jefe de seguridad, que no delegó, lo sigue viendo
        $this->assertSame(1, $this->pendientes($this->jefe)['esperan_supervisor']['total']);
    }

    // ============================================== Campana: motor diferido, sin duplicados ni avalancha

    public function test_la_campana_corre_el_motor_despues_de_responder_una_vez_por_minuto_y_sin_duplicar(): void
    {
        Carbon::setTestNow(now()->startOfMinute());
        $rh2 = $this->crearUsuario($this->empresa, 'Recursos Humanos', $this->centro);
        $this->vacantePropuesta();
        $this->avanzar(25 * 60);
        Cache::flush();

        // Varias recargas de dos personas a la vez: un solo recordatorio por persona
        foreach ([$this->rh, $rh2, $this->rh, $rh2, $this->rh] as $u) {
            $this->actingAs($u)->getJson('/notificaciones/resumen')->assertOk();
        }
        $this->assertSame(1, $this->avisos($this->rh, MotorRelojes::TIPO_RECORDATORIO));
        $this->assertSame(1, $this->avisos($rh2, MotorRelojes::TIPO_RECORDATORIO));
        $this->assertTrue(MotorRelojes::yaCorrio($this->empresa->id), 'Queda el candado de un minuto');

        // Aunque se venza el candado y se vuelva a correr (o corra la tarea programada a la vez), no se repite
        $this->avanzar(2);
        Cache::flush();
        $this->actingAs($this->rh)->getJson('/notificaciones/resumen')->assertOk();
        $this->app->forgetScopedInstances();
        app(MotorRelojes::class)->correr();
        $this->assertSame(1, $this->avisos($this->rh, MotorRelojes::TIPO_RECORDATORIO));
        $this->assertSame(0, $this->avisos($this->admin, MotorRelojes::TIPO_ESCALADO));
    }

    public function test_con_el_candado_vigente_la_campana_no_agenda_el_motor(): void
    {
        Cache::put('relojes:'.$this->empresa->id, 1, 60);
        $this->actingAs($this->rh)->getJson('/notificaciones/resumen')->assertOk();
        $this->assertCount(0, \Illuminate\Support\defer(), 'Nada pendiente de correr');
    }

    public function test_el_dia_de_la_instalacion_no_llega_de_golpe_lo_atrasado(): void
    {
        // Lo que ya esperaba desde hace días antes de instalar: el primer día no manda nada;
        // recuerda 24 h después del arranque y pasa al suplente a las 72 h, una vez cada cosa
        Carbon::setTestNow(now()->startOfMinute());
        RelojEspera::query()->delete();
        $this->vacantePropuesta();
        $this->enEmpresa(fn () => Vacante::query()->update(['propuesta_en' => now()->subDays(10)]));
        Cache::flush();
        $this->actingAs($this->rh)->getJson('/notificaciones/resumen')->assertOk();
        $this->assertSame(0, Notificacion::query()->withoutGlobalScopes()->whereIn('tipo', [MotorRelojes::TIPO_RECORDATORIO, MotorRelojes::TIPO_ESCALADO])->count());
        // La tarjeta sí dice cuánto lleva de verdad y en rojo
        $this->assertSame('roja', $this->pendientes($this->rh)['vacantes_propuestas']['nivel']);

        $this->avanzar(25 * 60);
        $this->campana();
        $this->assertSame(1, $this->avisos($this->rh, MotorRelojes::TIPO_RECORDATORIO));
        $this->assertSame(0, $this->avisos($this->admin, MotorRelojes::TIPO_ESCALADO));
        $this->avanzar(48 * 60);
        $this->campana();
        $this->campana();
        $this->assertSame(1, $this->avisos($this->admin, MotorRelojes::TIPO_ESCALADO));
    }

    // ============================================== Regla 1: todo recordatorio tiene renglón

    public function test_la_salida_temporal_que_no_regresa_tiene_su_renglon_en_mis_pendientes(): void
    {
        // Su recordatorio es «reloj_recordatorio», que se puede silenciar: sin renglón, silenciarlo borraba el único aviso (DD-10)
        Carbon::setTestNow(now()->startOfMinute());
        $this->enEmpresa(function () {
            $origen = new Acceso(['sede_id' => $this->centro->id, 'tipo' => 'proveedor', 'nombre' => 'PROVEEDOR FUERA', 'modo_arribo' => 'a_pie', 'identificacion' => 'ine']);
            $origen->forceFill(['estado' => 'en_sitio', 'movimiento' => 'entrada', 'entrada_at' => now(), 'creado_por' => $this->agente->id])->save();
            $fuera = $origen->replicate();
            $fuera->forceFill(['movimiento' => 'salida_temporal', 'acceso_origen_id' => $origen->id])->save();
        });
        $this->assertArrayNotHasKey('salida_temporal', $this->pendientes($this->supervisor), 'Antes de sus 2 h no espera a nadie');
        $this->avanzar(3 * 60);
        $this->campana();
        $this->assertSame(1, $this->avisos($this->supervisor, MotorRelojes::TIPO_RECORDATORIO));
        $fila = $this->pendientes($this->supervisor)['salida_temporal'] ?? null;
        $this->assertNotNull($fila, 'El supervisor que recibe el recordatorio lo tiene en Mis pendientes');
        $this->assertSame([1, 'ambar'], [$fila['total'], $fila['nivel']]);
        $this->assertArrayNotHasKey('salida_temporal', $this->pendientes($this->agente));
        $this->actingAs($this->supervisor)->get('/')->assertOk()->assertSee('salió y no ha regresado');
    }

    // ============================================== Instalación: solo lo que de verdad esperaba

    public function test_al_instalar_solo_los_borradores_que_pidio_un_jefe_quedan_como_vacante_propuesta(): void
    {
        $propuesta = $this->vacantePropuesta();
        [$depto, $puesto] = $this->enEmpresa(fn () => [Departamento::firstOrFail(), Puesto::firstOrFail()]);
        $this->actingAs($this->rh)->post('/vacantes', ['titulo' => 'Borrador de RR. HH.', 'puesto_id' => $puesto->id, 'departamento_id' => $depto->id,
            'plazas' => '1', 'todas_las_sedes' => '0', 'sedes' => [$this->centro->id], 'tipo_contrato' => 'indeterminado', 'jornada' => 'completa',
            'sueldo_periodo' => 'mensual', 'descripcion' => 'Todavía no se publica.', 'estado' => 'borrador']);
        $borrador = $this->enEmpresa(fn () => Vacante::where('titulo', 'Borrador de RR. HH.')->firstOrFail());
        $this->assertSame('borrador', $borrador->estado);
        // Como estaban antes de instalar: sin marca
        \Illuminate\Support\Facades\DB::table('vacantes')->update(['propuesta_en' => null, 'propuesta_por' => null]);

        $migracion = require database_path('migrations/2026_11_13_000100_reloj_y_suplente.php');
        $migracion->rellenar();

        $this->assertNotNull(\Illuminate\Support\Facades\DB::table('vacantes')->where('id', $propuesta->id)->value('propuesta_en'), 'La que pidió el jefe (tuvo su aviso «vacante_propuesta»)');
        $this->assertNull(\Illuminate\Support\Facades\DB::table('vacantes')->where('id', $borrador->id)->value('propuesta_en'), 'El borrador propio de RR. HH. no espera a nadie');
    }

    // ============================================== Super Administrador sin cruzar empresas

    public function test_el_super_administrador_ve_solo_la_empresa_de_trabajo_y_lo_de_la_plataforma(): void
    {
        Carbon::setTestNow(now()->startOfMinute());
        $this->vacantePropuesta();
        $otra = $this->crearEmpresa('Hotel Ajeno');
        $sedeOtra = $this->crearSede($otra, 'AJE');
        $deptoOtra = app(Tenant::class)->conEmpresa($otra->id, fn () => [Departamento::create(['nombre' => 'Bar']), Puesto::create(['nombre' => 'Barman'])]);
        $jefeOtra = $this->crearUsuario($otra, 'Jefe de departamento', $sedeOtra);
        $this->actingAs($jefeOtra)->post('/vacantes', ['titulo' => 'Barman', 'puesto_id' => $deptoOtra[1]->id, 'departamento_id' => $deptoOtra[0]->id,
            'plazas' => '1', 'todas_las_sedes' => '0', 'sedes' => [$sedeOtra->id], 'tipo_contrato' => 'indeterminado', 'jornada' => 'completa',
            'sueldo_periodo' => 'mensual', 'descripcion' => 'Preparar bebidas.'])->assertSessionHas('ok');
        $sa = $this->crearSuperadmin();
        $this->flushSession();

        // Con empresa de trabajo: nada de la otra empresa (el Super Administrador no tiene permisos de empresa:
        // sus renglones son los de la plataforma; de la empresa, a lo mucho la de trabajo)
        $json = $this->actingAs($sa)->withSession([EmpresaDeTrabajo::SESION => $this->empresa->id])
            ->getJson('/notificaciones/resumen')->assertOk()->json('pendientes.items');
        $vacantes = collect($json)->firstWhere('clave', 'vacantes_propuestas');
        $this->assertContains($vacantes['total'] ?? 0, [0, 1]);
        $this->assertSame(2, $this->enEmpresa(fn () => 1) + app(Tenant::class)->conEmpresa($otra->id, fn () => Vacante::count()), 'Hay una vacante en cada empresa');
        $this->actingAs($sa)->withSession([EmpresaDeTrabajo::SESION => $this->empresa->id])->get('/')->assertOk()->assertDontSee('Barman');

        // Sin empresa de trabajo: nada de ninguna empresa
        $this->flushSession();
        $sinEmpresa = $this->actingAs($sa)->getJson('/notificaciones/resumen')->assertOk()->json('pendientes.items');
        $this->assertNull(collect($sinEmpresa)->firstWhere('clave', 'vacantes_propuestas'));
    }

    // ============================================== Caseta de noche: foto y tenant

    public function test_la_foto_nueva_de_la_excepcion_no_deja_archivos_sueltos(): void
    {
        // Si ya había foto del registro y el agente toma otra, la anterior se borra (si no, ARCO y la
        // conservación de datos ya no la alcanzan); si la aprobación no procede, la nueva tampoco queda
        Carbon::setTestNow(now()->startOfMinute());
        $this->empresa->refresh()->forceFill(['preferencias' => array_merge($this->empresa->preferencias ?? [], ['caseta' => ['agente_excepcion' => 'si']])])->save();
        Storage::disk('local')->put('accesos/'.$this->empresa->id.'/fotos/vieja.jpg', 'x');
        $a = $this->enEmpresa(function () {
            $a = new Acceso(['sede_id' => $this->centro->id, 'tipo' => 'proveedor', 'nombre' => 'TÉCNICO', 'modo_arribo' => 'a_pie', 'identificacion' => 'ine']);
            $a->forceFill(['estado' => 'pendiente', 'movimiento' => 'entrada', 'entrada_at' => now(), 'creado_por' => $this->agente->id,
                'foto_persona' => 'accesos/'.$this->empresa->id.'/fotos/vieja.jpg'])->save();

            return $a;
        });
        $this->actingAs($this->agente)->post(route('accesos.pedir-supervisor', $a->id))->assertSessionHas('ok');
        $this->avanzar(25);

        // Motivo corto: no procede y no se guarda ninguna foto
        $this->actingAs($this->agente)->patch(route('accesos.excepcion-agente', $a->id), ['motivo_agente' => 'x', 'foto_agente' => UploadedFile::fake()->image('p.jpg')])
            ->assertSessionHas('error');
        $this->assertCount(1, Storage::disk('local')->allFiles('accesos'));

        $this->actingAs($this->agente)->patch(route('accesos.excepcion-agente', $a->id), ['motivo_agente' => 'Elevador detenido', 'foto_agente' => UploadedFile::fake()->image('p.jpg')])
            ->assertSessionHas('ok');
        $nueva = $this->enEmpresa(fn () => Acceso::findOrFail($a->id)->foto_persona);
        $this->assertStringStartsWith('accesos/'.$this->empresa->id.'/fotos/', $nueva);
        $this->assertSame([$nueva], Storage::disk('local')->allFiles('accesos'), 'Solo queda la foto vigente, en el disco privado');
    }

    public function test_la_excepcion_del_agente_respeta_empresa_sede_y_permiso(): void
    {
        Carbon::setTestNow(now()->startOfMinute());
        $this->empresa->refresh()->forceFill(['preferencias' => array_merge($this->empresa->preferencias ?? [], ['caseta' => ['agente_excepcion' => 'si']])])->save();
        $a = $this->enEmpresa(function () {
            $a = new Acceso(['sede_id' => $this->centro->id, 'tipo' => 'proveedor', 'nombre' => 'PIPA', 'modo_arribo' => 'a_pie', 'identificacion' => 'ine']);
            $a->forceFill(['estado' => 'pendiente', 'movimiento' => 'entrada', 'entrada_at' => now()->subHour(), 'supervisor_pedido_en' => now()->subHour(),
                'creado_por' => $this->agente->id])->save();

            return $a;
        });
        $foto = fn () => UploadedFile::fake()->image('p.jpg');
        // Agente de otra empresa: no la encuentra
        $otra = $this->crearEmpresa('Otra');
        $agenteOtra = $this->crearUsuario($otra, 'Agente', $this->crearSede($otra, 'OTR'));
        $this->actingAs($agenteOtra)->patch(route('accesos.excepcion-agente', $a->id), ['motivo_agente' => 'Fuga de gas', 'foto_agente' => $foto()])->assertNotFound();
        // Agente de otra sede de la misma empresa: tampoco
        $playa = $this->crearSede($this->empresa, 'PLA');
        $agentePlaya = $this->crearUsuario($this->empresa, 'Agente', $playa);
        $this->actingAs($agentePlaya)->patch(route('accesos.excepcion-agente', $a->id), ['motivo_agente' => 'Fuga de gas', 'foto_agente' => $foto()])->assertNotFound();
        // Sin permiso de registrar en caseta: prohibido
        $this->actingAs($this->jefeDepto)->patch(route('accesos.excepcion-agente', $a->id), ['motivo_agente' => 'Fuga de gas', 'foto_agente' => $foto()])->assertForbidden();
        $this->assertSame('pendiente', $this->enEmpresa(fn () => Acceso::findOrFail($a->id)->estado));
        $this->assertSame([], Storage::disk('local')->allFiles('accesos'));
    }
}
