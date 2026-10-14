<?php

namespace Tests\Feature\Seguridad;

use App\Mail\AvisoRecepcion;
use App\Models\Acceso;
use App\Models\Auditoria;
use App\Models\Autorizacion;
use App\Models\Colaborador;
use App\Models\Delegacion;
use App\Models\Departamento;
use App\Models\DepartamentoResponsable;
use App\Models\Empresa;
use App\Models\Notificacion;
use App\Models\Sede;
use App\Models\User;
use App\Services\Auditoria\LectorAuditoria;
use App\Services\Autorizaciones\Escalamiento;
use App\Services\Recepcion\AjustesRecepcion;
use App\Support\CorreoPlataforma;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Lo que espera tiene reloj, color y suplente (regla 3): «Espera a» en la
 * tarjeta pendiente de la caseta, ámbar y rojo con los minutos de la empresa,
 * recordatorio y escalamiento por la tarea programada y al refrescar la
 * bitácora, una sola vez, con auditoría e historial.
 */
class EscalamientoAutorizacionesTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private Sede $playa;

    private User $admin;

    private User $rh;

    private User $agente;

    private User $jefeDepto;

    private User $suplente;

    private User $jefeSeguridad;

    private User $jefeSeguridadPlaya;

    private Departamento $mantenimiento;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        Mail::fake();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa('Hotel Ébano');
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->playa = $this->crearSede($this->empresa, 'PLA');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->rh = $this->crearUsuario($this->empresa, 'Recursos Humanos', $this->centro);
        $this->agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->jefeDepto = $this->crearUsuario($this->empresa, 'Jefe de departamento');
        $this->suplente = $this->crearUsuario($this->empresa, 'Supervisor', $this->centro);
        $this->jefeSeguridad = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        $this->jefeSeguridadPlaya = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->playa);
        $this->mantenimiento = $this->enEmpresa(fn () => Departamento::create(['nombre' => 'Mantenimiento']));
        $this->empresa->refresh()->forceFill(['preferencias' => array_merge($this->empresa->preferencias ?? [], ['recepcion' => ['visitas_requieren_autorizacion' => true]])])->save();
        app(CorreoPlataforma::class)->guardar(['host' => 'mail.ejemplo.com', 'puerto' => 587, 'cifrado' => 'tls', 'usuario' => 'a@ejemplo.com',
            'remitente_correo' => 'avisos@ejemplo.com', 'remitente_nombre' => 'Avisos'], 'x');
    }

    // ------------------------------------------------------------------ Ayudas

    private function enEmpresa(callable $fn): mixed
    {
        return app(Tenant::class)->conEmpresa($this->empresa->id, $fn);
    }

    private function responsable(Departamento $d, User $u, bool $suplente = false): void
    {
        $this->enEmpresa(fn () => DepartamentoResponsable::create(['departamento_id' => $d->id, 'user_id' => $u->id, 'es_suplente' => $suplente, 'sede_id' => null]));
    }

    /** @param array<string, mixed> $extra */
    private function visitaAMantenimiento(array $extra = []): Acceso
    {
        // Tras avanzar el reloj, la sesión de prueba se renueva para no chocar con el cierre por inactividad
        $this->flushSession();
        $this->actingAs($this->agente)->post('/accesos', $extra + [
            'sede_id' => $this->centro->id, 'tipo' => 'visitante', 'nombre' => 'Ingrid Solís', 'motivo_visita' => 'departamento',
            'departamento_id' => $this->mantenimiento->id, 'modo_arribo' => 'a_pie', 'identificacion' => 'ine',
        ])->assertSessionHas('ok');

        return $this->enEmpresa(fn () => Acceso::latest('id')->firstOrFail());
    }

    private function candidatoEnCaseta(): Acceso
    {
        $this->actingAs($this->agente)->post('/accesos', [
            'sede_id' => $this->centro->id, 'tipo' => 'visitante', 'nombre' => 'Karla Pérez Uc', 'motivo_visita' => 'rh',
            'viene_a' => 'busca_empleo', 'modo_arribo' => 'a_pie', 'identificacion' => 'ine',
        ])->assertSessionHas('ok');

        return $this->enEmpresa(fn () => Acceso::latest('id')->firstOrFail());
    }

    private function autorizacionDe(Acceso $a): Autorizacion
    {
        return $this->enEmpresa(fn () => Autorizacion::where('acceso_id', $a->id)->firstOrFail());
    }

    private function notificaciones(User $u, string $tipo): int
    {
        return $this->enEmpresa(fn () => Notificacion::where('user_id', $u->id)->where('tipo', $tipo)->count());
    }

    private function pendientes(): TestResponse
    {
        return $this->actingAs($this->agente)->get('/accesos?pestana=pendientes');
    }

    // ------------------------------------------------------------------ Ajustes

    public function test_los_minutos_se_configuran_por_empresa_con_validacion_y_valores_por_omision(): void
    {
        $ajustes = app(AjustesRecepcion::class);
        $this->assertSame([10, 20], [$ajustes->recordarMinutos($this->empresa), $ajustes->escalarMinutos($this->empresa)]);
        // ADR-0010: los minutos viven en Responsables por departamento (una sola pantalla de responsables y esperas)
        $this->actingAs($this->rh)->get('/autorizaciones/responsables')->assertOk()->assertSee('Cuando la respuesta tarda')->assertSee('Guardar esperas')
            ->assertSee('name="recordar_minutos"', false);
        $this->actingAs($this->rh)->get('/rh/recepcion/ajustes')->assertOk()->assertDontSee('name="recordar_minutos"', false)->assertSee('Responsables por departamento y esperas');

        // El aviso al suplente debe ir después del recordatorio, y hay topes
        $this->actingAs($this->rh)->put('/rh/recepcion/ajustes', ['recordar_minutos' => 15, 'escalar_minutos' => 15])->assertSessionHasErrors('escalar_minutos');
        $this->actingAs($this->rh)->put('/rh/recepcion/ajustes', ['recordar_minutos' => 0, 'escalar_minutos' => 30])->assertSessionHasErrors('recordar_minutos');
        $this->actingAs($this->rh)->put('/rh/recepcion/ajustes', ['recordar_minutos' => 5, 'escalar_minutos' => 999])->assertSessionHasErrors('escalar_minutos');
        $this->actingAs($this->rh)->put('/rh/recepcion/ajustes', ['recordar_minutos' => 5, 'escalar_minutos' => 12])
            ->assertSessionHas('ok', 'Ajustes guardados: las autorizaciones que tarden se recordarán y escalarán con esos minutos.');
        $this->assertSame([5, 12], [$ajustes->recordarMinutos($this->empresa->fresh()), $ajustes->escalarMinutos($this->empresa->fresh())]);
        // Queda en la auditoría de configuración
        $this->assertTrue(Auditoria::where('evento', 'candidatos.configurado')->exists());
        // El agente no configura
        $this->actingAs($this->agente)->put('/rh/recepcion/ajustes', ['recordar_minutos' => 5, 'escalar_minutos' => 12])->assertForbidden();
        // La tarjeta lleva los umbrales de la empresa para pintar el reloj sin recargar
        $this->responsable($this->mantenimiento, $this->jefeDepto);
        $this->visitaAMantenimiento();
        $this->pendientes()->assertSee('data-espera-ambar="5"', false)->assertSee('data-espera-roja="12"', false);
    }

    // ------------------------------------------------------------------ Reloj y color

    public function test_la_tarjeta_pendiente_dice_a_quien_espera_los_minutos_y_cambia_de_color(): void
    {
        $this->responsable($this->mantenimiento, $this->jefeDepto);
        $acceso = $this->visitaAMantenimiento();
        $this->pendientes()->assertSee('PENDIENTE')->assertSee('Espera a: '.$this->jefeDepto->name.' (Mantenimiento)')
            ->assertSee('class="ficha-acceso-detalle espera-normal', false)->assertSee('Ya se avisó al responsable.')
            ->assertDontSee('Se escaló a');
        $this->actingAs($this->agente)->getJson('/accesos/autorizaciones-estado?ids='.$acceso->id)
            ->assertJsonPath('estados.'.$acceso->id, 'pendiente|esperando')->assertJsonPath('esperas.'.$acceso->id.'.nivel', 'normal')
            ->assertJsonPath('esperas.'.$acceso->id.'.escalado', null);

        $this->travel(11)->minutes();
        $this->pendientes()->assertSee('class="ficha-acceso-detalle espera-ambar', false)->assertSee('<span data-espera-minutos>11</span> min', false);
        $this->actingAs($this->agente)->getJson('/accesos/autorizaciones-estado?ids='.$acceso->id)->assertJsonPath('esperas.'.$acceso->id.'.nivel', 'ambar');
        Cache::flush();
        $this->travel(10)->minutes();
        $this->pendientes()->assertSee('class="ficha-acceso-detalle espera-roja', false);

        // Con delegación activa, espera al delegado
        $this->enEmpresa(fn () => Delegacion::create(['user_id' => $this->jefeDepto->id, 'delegado_id' => $this->suplente->id, 'desde' => now()->subDay(), 'hasta' => now()->addDay()]));
        $this->pendientes()->assertSee('Espera a: '.$this->suplente->name.' (Mantenimiento)');
    }

    public function test_el_candidato_espera_a_recursos_humanos_y_el_proveedor_al_host(): void
    {
        $acceso = $this->candidatoEnCaseta();
        $this->pendientes()->assertSee('Espera a: Recursos Humanos')->assertSee('Ya se avisó a Recursos Humanos.');
        $a = $this->autorizacionDe($acceso);
        $this->actingAs($this->rh)->post("/autorizaciones/{$a->id}/responder", ['respuesta' => 'espere'])->assertSessionHas('ok');
        $this->pendientes()->assertSee('RR. HH. pide que espere')->assertSee('Que espere en caseta');

        // Proveedor sin la casilla «Proveedor» de las esperas: se confirma con el host, como antes
        $this->empresa->refresh()->forceFill(['preferencias' => array_merge($this->empresa->preferencias ?? [], ['recepcion' => ['visitas_requieren_autorizacion' => true, 'proveedores_requieren_autorizacion' => false]])])->save();
        $host = $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => '501', 'nombre' => 'Carlos', 'apellido_paterno' => 'Hernández', 'sede_id' => $this->centro->id]));
        $this->actingAs($this->agente)->post('/accesos', ['sede_id' => $this->centro->id, 'tipo' => 'proveedor', 'nombre' => 'Luis Chi', 'empresa_procedencia' => 'Gas del Sur',
            'host_colaborador_id' => $host->id, 'modo_arribo' => 'a_pie', 'identificacion' => 'ine'])->assertSessionHas('ok');
        $this->pendientes()->assertSee('Confirma con el host: Carlos Hernández');
    }

    // ------------------------------------------------------------------ Escalamiento

    public function test_recuerda_al_responsable_y_escala_al_suplente_y_al_jefe_una_sola_vez(): void
    {
        $this->responsable($this->mantenimiento, $this->jefeDepto);
        $this->responsable($this->mantenimiento, $this->suplente, true);
        $acceso = $this->visitaAMantenimiento();
        $a = $this->autorizacionDe($acceso);
        // El aviso original va al responsable y al suplente
        $this->assertEqualsCanonicalizing([$this->jefeDepto->id, $this->suplente->id], $a->avisados);
        $this->assertSame([1, 1], [$this->notificaciones($this->jefeDepto, 'autorizacion_visita'), $this->notificaciones($this->suplente, 'autorizacion_visita')]);

        // Antes de los 10 minutos no pasa nada y «--si-toca» no dice nada
        $this->artisan('plataforma:escalar-autorizaciones --si-toca')->doesntExpectOutputToContain('Recordatorios')->assertSuccessful();
        $this->assertNull($a->fresh()->recordatorio_en);

        // A los 10: recordatorio a quienes ya fueron avisados (campana con botones y correo), auditado
        $this->travel(10)->minutes();
        $this->artisan('plataforma:escalar-autorizaciones')->expectsOutputToContain('Recordatorios enviados: 1. Escalamientos: 0.')->assertSuccessful();
        $a->refresh();
        $this->assertNotNull($a->recordatorio_en);
        $this->assertNull($a->escalado_en);
        $this->assertSame([2, 2], [$this->notificaciones($this->jefeDepto, 'autorizacion_visita'), $this->notificaciones($this->suplente, 'autorizacion_visita')]);
        $this->actingAs($this->jefeDepto)->getJson('/notificaciones/resumen')->assertJsonPath('lista.0.titulo', 'Recordatorio: Ingrid Solís lleva 10 min esperando tu respuesta')
            ->assertJsonPath('lista.0.acciones.0.etiqueta', 'Autorizar ingreso');
        Mail::assertSent(AvisoRecepcion::class, fn ($m) => $m->hasTo($this->jefeDepto->email) && str_starts_with($m->titulo, 'Recordatorio:'));
        $this->assertSame(0, $this->notificaciones($this->jefeSeguridad, 'autorizacion_escalada'));
        $this->assertTrue(Auditoria::where('evento', 'autorizaciones.recordatorio')->where('auditable_id', $a->id)->exists());
        // Otra corrida no repite
        $this->artisan('plataforma:escalar-autorizaciones --si-toca')->assertSuccessful();
        $this->assertSame(2, $this->notificaciones($this->jefeDepto, 'autorizacion_visita'));

        // A los 20: al suplente (con botones) y al Jefe de seguridad de la sede (sin botones: confirma en caseta); la caseta se entera
        $this->travel(10)->minutes();
        $this->artisan('plataforma:escalar-autorizaciones')->expectsOutputToContain('Recordatorios enviados: 0. Escalamientos: 1.')->assertSuccessful();
        $a->refresh();
        $this->assertNotNull($a->escalado_en);
        $this->assertEqualsCanonicalizing([$this->suplente->id, $this->jefeSeguridad->id], $a->escalado_a);
        $this->assertSame(3, $this->notificaciones($this->suplente, 'autorizacion_visita'));
        $this->actingAs($this->suplente)->getJson('/notificaciones/resumen')->assertJsonPath('lista.0.titulo', 'Nadie ha respondido: Ingrid Solís lleva 20 min en caseta')
            ->assertJsonPath('lista.0.acciones.0.etiqueta', 'Autorizar ingreso');
        Mail::assertSent(AvisoRecepcion::class, fn ($m) => $m->hasTo($this->suplente->email) && str_starts_with($m->titulo, 'Nadie ha respondido'));
        $this->assertSame([1, 0], [$this->notificaciones($this->jefeSeguridad, 'autorizacion_escalada'), $this->notificaciones($this->jefeSeguridad, 'autorizacion_visita')]);
        $this->actingAs($this->jefeSeguridad)->getJson('/notificaciones/resumen')->assertJsonPath('lista.0.acciones', [])
            ->assertJsonPath('lista.0.titulo', 'Nadie ha respondido: Ingrid Solís lleva 20 min en caseta');
        $this->assertSame(2, $this->notificaciones($this->jefeDepto, 'autorizacion_visita')); // el responsable ya no recibe más
        $this->assertSame(1, $this->notificaciones($this->agente, 'autorizacion_escalada'));
        $this->actingAs($this->agente)->getJson('/notificaciones/resumen')->assertJsonPath('lista.0.titulo', 'Se escaló a '.$this->suplente->name.' y '.$this->jefeSeguridad->name.': INGRID SOLÍS');
        $this->assertTrue(Auditoria::where('evento', 'autorizaciones.escalada')->where('auditable_id', $a->id)->exists());
        $this->assertSame('Escalamiento al suplente o jefe', app(LectorAuditoria::class)->accion('autorizaciones.escalada'));
        $this->assertSame('Recordatorio por tardanza', app(LectorAuditoria::class)->accion('autorizaciones.recordatorio'));

        // La tarjeta y el sondeo dicen a quién se escaló; el historial de la autorización también
        $this->pendientes()->assertSee('Se escaló a: '.$this->suplente->name.' y '.$this->jefeSeguridad->name);
        $this->actingAs($this->agente)->getJson('/accesos/autorizaciones-estado?ids='.$acceso->id)->assertJsonPath('esperas.'.$acceso->id.'.escalado', 'Se escaló a: '.$this->suplente->name.' y '.$this->jefeSeguridad->name);
        $this->actingAs($this->jefeDepto)->get("/autorizaciones/{$a->id}")->assertOk()->assertSee('Recordatorio enviado')->assertSee('Escalado')->assertSee($this->suplente->name);

        // Nunca dos veces: más corridas y más tiempo no vuelven a avisar
        $this->travel(60)->minutes();
        $this->artisan('plataforma:escalar-autorizaciones')->expectsOutputToContain('Recordatorios enviados: 0. Escalamientos: 0.')->assertSuccessful();
        $this->assertSame(3, $this->notificaciones($this->suplente, 'autorizacion_visita'));
        $this->assertSame(1, $this->notificaciones($this->agente, 'autorizacion_escalada'));
        $this->assertSame(1, $this->notificaciones($this->jefeSeguridad, 'autorizacion_escalada'));

        // El suplente responde y todo cierra
        $this->flushSession(); // la sesión anterior caducó por inactividad con el viaje en el tiempo
        $this->actingAs($this->suplente)->post("/autorizaciones/{$a->id}/responder", ['respuesta' => 'autorizar'])->assertSessionHas('ok');
        $this->assertSame('en_sitio', $acceso->fresh()->estado);
    }

    public function test_sin_suplente_escala_al_jefe_de_seguridad_de_la_sede_y_lo_dispara_el_refresco_de_la_bitacora(): void
    {
        $this->responsable($this->mantenimiento, $this->jefeDepto);
        $acceso = $this->visitaAMantenimiento();
        $a = $this->autorizacionDe($acceso);

        // Sin cron: al refrescar la bitácora ya tocaba el recordatorio
        $this->travel(10)->minutes();
        $this->pendientes()->assertOk();
        $this->assertNotNull($a->fresh()->recordatorio_en);
        $this->assertSame(2, $this->notificaciones($this->jefeDepto, 'autorizacion_visita'));

        // A los 20 lo dispara el sondeo de la tarjeta (una vez por minuto, no en cada sondeo)
        $this->travel(10)->minutes();
        $this->actingAs($this->agente)->getJson('/accesos/autorizaciones-estado?ids='.$acceso->id)
            ->assertJsonPath('esperas.'.$acceso->id.'.escalado', 'Se escaló a: '.$this->jefeSeguridad->name);
        $a->refresh();
        $this->assertSame([$this->jefeSeguridad->id], $a->escalado_a);
        // El Jefe de seguridad no responde la autorización: recibe el aviso para confirmar en caseta, no los botones
        $this->assertSame(1, $this->notificaciones($this->jefeSeguridad, 'autorizacion_escalada'));
        $this->assertSame(0, $this->notificaciones($this->jefeSeguridad, 'autorizacion_visita'));
        $this->actingAs($this->jefeSeguridad)->getJson('/notificaciones/resumen')->assertJsonPath('lista.0.acciones', [])
            ->assertJsonPath('lista.0.texto', fn ($t) => str_contains($t, 'Confirmar Autorización'));
        Mail::assertSent(AvisoRecepcion::class, fn ($m) => $m->hasTo($this->jefeSeguridad->email) && str_starts_with($m->titulo, 'Nadie ha respondido'));
        // Alcance por sede: el Jefe de seguridad de la otra sede no se entera
        $this->assertSame(0, $this->notificaciones($this->jefeSeguridadPlaya, 'autorizacion_escalada'));
        $this->assertFalse(Mail::sent(AvisoRecepcion::class, fn ($m) => $m->hasTo($this->jefeSeguridadPlaya->email))->isNotEmpty());
        // La caseta lo ve y el agente puede confirmar por su cuenta
        $this->pendientes()->assertSee('Se escaló a: '.$this->jefeSeguridad->name);
        $this->actingAs($this->jefeSeguridad)->patch("/accesos/{$acceso->id}/autorizar")->assertSessionHas('ok');
        $this->assertSame('autorizada', $a->fresh()->estado);
        // Ya respondida: nada más que escalar
        $this->travel(30)->minutes();
        $this->artisan('plataforma:escalar-autorizaciones')->expectsOutputToContain('Recordatorios enviados: 0. Escalamientos: 0.')->assertSuccessful();
    }

    public function test_el_suplente_delegado_recibe_el_escalamiento_y_sin_nadie_queda_anotado(): void
    {
        $this->responsable($this->mantenimiento, $this->jefeDepto);
        $this->responsable($this->mantenimiento, $this->suplente, true);
        $delegado = $this->crearUsuario($this->empresa, 'Supervisor', $this->centro);
        $this->enEmpresa(fn () => Delegacion::create(['user_id' => $this->suplente->id, 'delegado_id' => $delegado->id, 'desde' => now()->subDay(), 'hasta' => now()->addDay()]));
        $acceso = $this->visitaAMantenimiento();
        $this->travel(20)->minutes();
        $this->artisan('plataforma:escalar-autorizaciones')->expectsOutputToContain('Recordatorios enviados: 1. Escalamientos: 1.')->assertSuccessful();
        $this->assertEqualsCanonicalizing([$delegado->id, $this->jefeSeguridad->id], $this->autorizacionDe($acceso)->escalado_a);
        // El aviso original y el recordatorio ya iban al delegado; el suplente de vacaciones no recibe nada
        $this->assertSame([0, 3], [$this->notificaciones($this->suplente, 'autorizacion_visita'), $this->notificaciones($delegado, 'autorizacion_visita')]);

        // Empresa sin Jefe de seguridad ni suplentes ni nadie que apruebe en la sede: se anota «sin a quién»
        $this->enEmpresa(fn () => DepartamentoResponsable::where('es_suplente', true)->delete());
        foreach ([$this->jefeSeguridad, $this->jefeSeguridadPlaya, $this->admin, $this->suplente, $delegado] as $u) {
            $u->forceFill(['activo' => false])->save();
        }
        $otro = $this->visitaAMantenimiento(['nombre' => 'Mario Cab']);
        $this->travel(20)->minutes();
        $this->artisan('plataforma:escalar-autorizaciones')->expectsOutputToContain('Recordatorios enviados: 1. Escalamientos: 0.')->assertSuccessful();
        $b = $this->autorizacionDe($otro);
        $this->assertNotNull($b->escalado_en);
        $this->assertSame([], $b->escalado_a);
        $this->assertTrue(Auditoria::where('evento', 'autorizaciones.escalada')->where('auditable_id', $b->id)->whereJsonContains('despues->sin_a_quien', true)->exists());
    }

    public function test_el_candidato_que_espera_a_rh_escala_al_resto_de_rh_y_al_jefe_de_seguridad(): void
    {
        $rhPlaya = $this->crearUsuario($this->empresa, 'Recursos Humanos', $this->playa);
        $acceso = $this->candidatoEnCaseta();
        $a = $this->autorizacionDe($acceso);
        // Recursos Humanos de la empresa (y el Administrador, que atiende Recepción) reciben el aviso original
        $this->assertContains($this->rh->id, $a->avisados);
        $avisados = count($a->avisados);

        $this->travel(20)->minutes();
        $this->artisan('plataforma:escalar-autorizaciones')->expectsOutputToContain('Recordatorios enviados: 1. Escalamientos: 1.')->assertSuccessful();
        $a->refresh();
        // Recordatorio a quienes ya estaban avisados; escalamiento a quien falte de RR. HH. y al Jefe de seguridad de la sede
        $this->assertSame(2, $this->notificaciones($this->rh, 'autorizacion_recepcion'));
        // Pasaron 20 min: la sesión de prueba se renueva para no chocar con el cierre por inactividad (20 min)
        $this->flushSession();
        $this->actingAs($this->rh)->getJson('/notificaciones/resumen')->assertJsonPath('lista.0.titulo', 'Recordatorio: Karla Pérez Uc lleva 20 min esperando tu respuesta')
            ->assertJsonPath('lista.0.acciones.0.etiqueta', 'Que pase');
        $this->assertContains($this->jefeSeguridad->id, $a->escalado_a);
        $this->assertSame([], array_values(array_intersect($a->escalado_a, $a->avisados)));
        $this->assertSame(1, $this->notificaciones($this->jefeSeguridad, 'autorizacion_escalada'));
        $this->assertSame(0, $this->notificaciones($this->jefeSeguridadPlaya, 'autorizacion_escalada'));
        $this->assertSame($avisados, count($a->fresh()->avisados));
        $this->pendientes()->assertSee('Se escaló a: ');
        // El panel de Recepción pinta la espera con los minutos de la empresa (20 min → rojo con los valores por omisión; con 30 y 60, normal)
        $this->actingAs($this->rh)->getJson('/rh/recepcion/datos')->assertOk()->assertSee('espera-roja');
        $this->empresa->refresh()->forceFill(['preferencias' => array_merge($this->empresa->preferencias ?? [], ['recepcion' => ['recordar_minutos' => 30, 'escalar_minutos' => 60]])])->save();
        $this->actingAs($this->rh)->getJson('/rh/recepcion/datos')->assertOk()->assertSee('espera-normal')->assertDontSee('espera-roja');
        unset($rhPlaya);
    }

    public function test_el_servicio_respeta_el_candado_de_un_minuto_y_no_tira_la_pantalla(): void
    {
        $this->responsable($this->mantenimiento, $this->jefeDepto);
        $acceso = $this->visitaAMantenimiento();
        $this->travel(10)->minutes();
        $escalamiento = app(Escalamiento::class);
        $this->assertTrue($escalamiento->escalarSiToca($this->empresa->id));
        $this->assertFalse($escalamiento->escalarSiToca($this->empresa->id)); // candado
        $this->travel(2)->minutes();
        $this->assertFalse($escalamiento->escalarSiToca($this->empresa->id)); // ya no toca nada
        $this->assertNotNull($this->autorizacionDe($acceso)->recordatorio_en);
    }
}
