<?php

namespace Tests\Feature\Seguridad;

use App\Mail\AvisoRecepcion;
use App\Models\Acceso;
use App\Models\Auditoria;
use App\Models\Autorizacion;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\DepartamentoResponsable;
use App\Models\Empresa;
use App\Models\Notificacion;
use App\Models\Sede;
use App\Models\User;
use App\Services\Recepcion\AjustesRecepcion;
use App\Support\CorreoPlataforma;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Proveedor y contratista esperan la autorización del departamento (como las
 * visitas): la caseta elige «¿A qué departamento viene?», el responsable
 * autoriza o rechaza desde la campana o el correo, y la caseta solo lo pasa
 * sin él «por excepción», con motivo, auditoría y aviso al responsable.
 * Configurable por empresa y por tipo (Visita, Proveedor, Contratista).
 */
class ProveedoresEsperanAlDepartamentoTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private Sede $playa;

    private User $admin;

    private User $agente;

    private User $supervisor;

    private User $jefeDepto;

    private Departamento $mantenimiento;

    private Colaborador $host;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa('Hotel Ébano');
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->playa = $this->crearSede($this->empresa, 'PLA');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->supervisor = $this->crearUsuario($this->empresa, 'Supervisor', $this->centro);
        $this->jefeDepto = $this->crearUsuario($this->empresa, 'Jefe de departamento');
        $this->mantenimiento = $this->enEmpresa(fn () => Departamento::create(['nombre' => 'Mantenimiento']));
        $this->host = $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => '501', 'nombre' => 'Carlos', 'apellido_paterno' => 'Hernández', 'sede_id' => $this->centro->id]));
        app(CorreoPlataforma::class)->guardar(['host' => 'mail.ejemplo.com', 'puerto' => 587, 'cifrado' => 'tls', 'usuario' => 'a@ejemplo.com',
            'remitente_correo' => 'avisos@ejemplo.com', 'remitente_nombre' => 'Avisos'], 'x');
    }

    // ------------------------------------------------------------------ Ayudas

    private function enEmpresa(callable $fn, ?Empresa $empresa = null): mixed
    {
        return app(Tenant::class)->conEmpresa(($empresa ?? $this->empresa)->id, $fn);
    }

    private function responsable(?Sede $sede = null): void
    {
        $this->enEmpresa(fn () => DepartamentoResponsable::create(['departamento_id' => $this->mantenimiento->id, 'user_id' => $this->jefeDepto->id,
            'es_suplente' => false, 'sede_id' => $sede?->id]));
    }

    /** @param array<string, mixed> $ajustes */
    private function ajustes(array $ajustes): void
    {
        $this->empresa->refresh()->forceFill(['preferencias' => array_merge($this->empresa->preferencias ?? [], ['recepcion' => $ajustes])])->save();
    }

    /** @param array<string, mixed> $extra */
    private function registrar(array $extra = [], ?User $quien = null): TestResponse
    {
        return $this->actingAs($quien ?? $this->agente)->post('/accesos', $extra + [
            'sede_id' => $this->centro->id, 'tipo' => 'proveedor', 'nombre' => 'Luis Chi', 'empresa_procedencia' => 'Gas del Sur',
            'host_colaborador_id' => $this->host->id, 'departamento_id' => $this->mantenimiento->id, 'actividad' => 'Recarga de tanque',
            'modo_arribo' => 'a_pie', 'identificacion' => 'ine',
        ]);
    }

    private function ultimo(): Acceso
    {
        return $this->enEmpresa(fn () => Acceso::latest('id')->firstOrFail());
    }

    private function solicitudDe(Acceso $a): ?Autorizacion
    {
        return $this->enEmpresa(fn () => Autorizacion::where('acceso_id', $a->id)->latest('id')->first());
    }

    private function avisos(User $u, string $tipo): int
    {
        return $this->enEmpresa(fn () => Notificacion::where('user_id', $u->id)->where('tipo', $tipo)->count());
    }

    private function excepcion(Acceso $a, ?string $motivo, string $detalle = '', ?User $quien = null): TestResponse
    {
        return $this->actingAs($quien ?? $this->supervisor)->patch("/accesos/{$a->id}/excepcion", ['motivo_excepcion' => $motivo, 'detalle_excepcion' => $detalle]);
    }

    // ------------------------------------------------------------------ Con responsable

    public function test_proveedor_con_responsable_espera_al_departamento_y_entra_cuando_autoriza_el_jefe(): void
    {
        $this->responsable();
        $this->registrar()->assertRedirect(route('accesos.index', ['pestana' => 'pendientes']))
            ->assertSessionHas('ok', 'Registrado: LUIS CHI espera la autorización de '.$this->jefeDepto->name.' (Mantenimiento). Ya se avisó; esta tarjeta cambia sola con su respuesta.');
        $acceso = $this->ultimo();
        $this->assertSame(['pendiente', 'esperando', $this->mantenimiento->id, $this->host->id], [$acceso->estado, $acceso->autorizacion, $acceso->departamento_id, $acceso->host_colaborador_id]);

        // Solicitud al departamento (tipo visita): campana con botones y correo
        $solicitud = $this->solicitudDe($acceso);
        $this->assertSame(['visita', 'pendiente', $this->mantenimiento->id, $this->centro->id], [$solicitud->tipo, $solicitud->estado, $solicitud->departamento_id, $solicitud->sede_id]);
        $aviso = $this->enEmpresa(fn () => Notificacion::where('user_id', $this->jefeDepto->id)->where('tipo', 'autorizacion_visita')->firstOrFail());
        $this->assertSame('Espera tu autorización: LUIS CHI', $aviso->titulo);
        $this->assertStringContainsString('Proveedor para Mantenimiento. Lo citó: Carlos Hernández. Actividad: Recarga de tanque. Viene de: GAS DEL SUR.', $aviso->texto);
        $this->assertSame(['Autorizar ingreso', 'Rechazar'], array_column($aviso->acciones, 'etiqueta'));
        Mail::assertSent(AvisoRecepcion::class, fn (AvisoRecepcion $m) => $m->hasTo($this->jefeDepto->email));

        // La caseta ve a quién espera y no tiene «Confirmar Autorización»
        $this->actingAs($this->supervisor)->get('/accesos?pestana=pendientes')->assertOk()
            ->assertSee('Espera a: '.$this->jefeDepto->name.' (Mantenimiento)')->assertSee('Autorizar por excepción')->assertDontSee('Confirmar Autorización');
        $this->actingAs($this->supervisor)->patch("/accesos/{$acceso->id}/autorizar")
            ->assertSessionHas('error', 'Este acceso espera la autorización del departamento. Si no puede esperar, toca «Autorizar por excepción» y elige el motivo.');
        $this->assertSame('pendiente', $this->ultimo()->estado);

        // Autoriza el jefe: entra y la caseta lo ve en vivo
        $this->actingAs($this->jefeDepto)->post("/autorizaciones/{$solicitud->id}/responder", ['respuesta' => 'autorizar'])->assertSessionHas('ok');
        $acceso->refresh();
        $this->assertSame(['en_sitio', 'autorizada', $this->jefeDepto->id], [$acceso->estado, $acceso->autorizacion, $acceso->autorizado_por]);
        $this->actingAs($this->agente)->getJson('/accesos/autorizaciones-estado?ids='.$acceso->id)->assertJsonPath('estados.'.$acceso->id, 'en_sitio|autorizada');
        $this->assertSame(1, $this->avisos($this->agente, 'autorizacion_respuesta'));
        $this->actingAs($this->agente)->get('/accesos')->assertSee('Autorizado por Mantenimiento');
    }

    public function test_si_el_jefe_rechaza_el_proveedor_no_entra(): void
    {
        $this->responsable();
        $this->registrar(['tipo' => 'contratista', 'nombre' => 'José Ek', 'empresa_procedencia' => 'Constructora Maya'])->assertSessionHas('ok');
        $acceso = $this->ultimo();
        $solicitud = $this->solicitudDe($acceso);
        $this->assertSame('visita', $solicitud->tipo);

        $this->actingAs($this->jefeDepto)->post("/autorizaciones/{$solicitud->id}/responder", ['respuesta' => 'rechazar', 'comentario' => 'No lo esperamos hoy'])->assertSessionHas('ok');
        $acceso->refresh();
        $this->assertSame(['finalizado', 'rechazada'], [$acceso->estado, $acceso->autorizacion]);
        $this->actingAs($this->agente)->get('/accesos?pestana=historial')->assertSee('No autorizado por Mantenimiento: no ingresó');
        // Ya no se puede pasar por excepción
        $this->excepcion($acceso, 'telefono')->assertSessionHas('error', 'Este acceso ya no espera la autorización del departamento. Revisa la lista.');
    }

    public function test_el_departamento_es_obligatorio_si_el_tipo_espera_al_departamento(): void
    {
        $this->registrar(['departamento_id' => ''])->assertSessionHasErrors(['departamento_id' => 'Indica a qué departamento viene.']);
        $this->registrar(['tipo' => 'contratista', 'departamento_id' => ''])->assertSessionHasErrors(['departamento_id' => 'Indica a qué departamento viene.']);
        $this->assertSame(0, $this->enEmpresa(fn () => Acceso::count()));
        // El formulario lo pide con su nombre nuevo y el aviso de qué pasará
        $this->actingAs($this->agente)->get('/accesos')->assertOk()->assertSee('¿A qué departamento viene?')
            ->assertSee('el responsable del departamento recibe el aviso y autoriza', false);
    }

    // ------------------------------------------------------------------ Excepciones

    public function test_sin_responsable_la_caseta_autoriza_por_excepcion_con_motivo(): void
    {
        $this->registrar()->assertSessionHas('ok', 'Registrado: LUIS CHI queda pendiente. Mantenimiento no tiene responsable en esta sede: confirma con el host; tu supervisor lo autoriza por excepción.');
        $acceso = $this->ultimo();
        $this->assertSame(['pendiente', Acceso::SIN_RESPONSABLE], [$acceso->estado, $acceso->autorizacion]);
        $this->assertNull($this->solicitudDe($acceso));
        $this->actingAs($this->supervisor)->get('/accesos?pestana=pendientes')
            ->assertSee('Sin responsable en Mantenimiento: se autoriza por excepción')->assertSee('El departamento no tiene responsable en esta sede');
        $this->actingAs($this->agente)->get('/accesos?pestana=pendientes')
            ->assertSee('si no puede esperar, tu supervisor lo autoriza por excepción')->assertDontSee('Autorizar por excepción</summary>', false);

        // El agente no tiene accesos.aprobar
        $this->excepcion($acceso, 'sin_responsable', '', $this->agente)->assertForbidden();
        // Siempre pide motivo
        $this->excepcion($acceso, null)->assertSessionHas('error', 'Elige el motivo de la excepción.');
        $this->excepcion($acceso, 'otro')->assertSessionHas('error', 'Con «Otro» escribe el motivo de la excepción.');
        $this->assertSame('pendiente', $this->ultimo()->estado);

        $this->excepcion($acceso, 'sin_responsable', 'Lo citó el gerente')
            ->assertSessionHas('ok', 'Autorizado por excepción — LUIS CHI ya puede ingresar (El departamento no tiene responsable en esta sede: Lo citó el gerente). Avisamos al responsable del departamento.');
        $acceso->refresh();
        $this->assertSame(['en_sitio', Acceso::POR_EXCEPCION, $this->supervisor->id, 'El departamento no tiene responsable en esta sede: Lo citó el gerente'],
            [$acceso->estado, $acceso->autorizacion, $acceso->autorizado_por, $acceso->motivo_excepcion]);
        $this->assertTrue(Auditoria::where('evento', 'accesos.autorizado_excepcion')->where('auditable_id', $acceso->id)
            ->whereJsonContains('despues->motivo', 'El departamento no tiene responsable en esta sede: Lo citó el gerente')->exists());
        $this->actingAs($this->agente)->get('/accesos')
            ->assertSee('Autorizado por excepción (El departamento no tiene responsable en esta sede: Lo citó el gerente, '.$this->supervisor->name.')');
        // Por segunda vez ya no aplica
        $this->excepcion($acceso, 'telefono')->assertSessionHas('error', 'Este acceso ya no espera la autorización del departamento. Revisa la lista.');
    }

    public function test_con_responsable_y_sin_escalar_solo_con_motivo_de_la_lista_y_queda_auditado(): void
    {
        $this->responsable();
        $this->registrar()->assertSessionHas('ok');
        $acceso = $this->ultimo();
        $solicitud = $this->solicitudDe($acceso);

        // Motivos que no aplican: hay responsable y no ha pasado el tiempo de espera
        $this->actingAs($this->supervisor)->get('/accesos?pestana=pendientes')->assertSee('El responsable autorizó por teléfono')->assertSee('Urgencia operativa')
            ->assertDontSee('El departamento no tiene responsable en esta sede')->assertDontSee('El responsable no contestó a tiempo');
        $this->excepcion($acceso, 'sin_responsable')->assertSessionHas('error', 'Ese motivo no aplica a este acceso: elige uno de la lista.');
        $this->excepcion($acceso, 'sin_respuesta')->assertSessionHas('error', 'Ese motivo no aplica a este acceso: elige uno de la lista.');
        $this->excepcion($acceso, 'inventado')->assertSessionHas('error', 'Elige el motivo de la excepción.');
        $this->assertSame('pendiente', $this->ultimo()->estado);

        $this->excepcion($acceso, 'telefono', 'Habló con él a las 10:15')->assertSessionHas('ok');
        $acceso->refresh();
        $this->assertSame(['en_sitio', Acceso::POR_EXCEPCION, 'El responsable autorizó por teléfono: Habló con él a las 10:15'], [$acceso->estado, $acceso->autorizacion, $acceso->motivo_excepcion]);
        // La solicitud se cierra por la caseta y sus avisos pierden los botones
        $solicitud->refresh();
        $this->assertSame(['autorizada', 'caseta', $this->supervisor->id], [$solicitud->estado, $solicitud->respuesta_medio, $solicitud->respondida_por]);
        $this->assertStringStartsWith('Por excepción: El responsable autorizó por teléfono', $solicitud->comentario);
        $this->assertNull($this->enEmpresa(fn () => Notificacion::where('user_id', $this->jefeDepto->id)->where('tipo', 'autorizacion_visita')->value('acciones')));
        // El responsable se entera por campana
        $aviso = $this->enEmpresa(fn () => Notificacion::where('user_id', $this->jefeDepto->id)->where('tipo', 'autorizacion_excepcion')->firstOrFail());
        $this->assertSame('Pasó por excepción: LUIS CHI', $aviso->titulo);
        $this->assertStringContainsString('Motivo: El responsable autorizó por teléfono: Habló con él a las 10:15. Autorizó en caseta: '.$this->supervisor->name, $aviso->texto);
        // Auditoría del acceso y de la solicitud
        $this->assertTrue(Auditoria::where('evento', 'accesos.autorizado_excepcion')->where('auditable_id', $acceso->id)->exists());
        $this->assertTrue(Auditoria::where('evento', 'autorizaciones.respondida')->where('auditable_id', $solicitud->id)->whereJsonContains('despues->medio', 'caseta')->exists());
        // El jefe ya no puede responder
        $this->actingAs($this->jefeDepto)->post("/autorizaciones/{$solicitud->id}/responder", ['respuesta' => 'rechazar'])->assertSessionHas('error');
        $this->assertSame('en_sitio', $this->ultimo()->estado);
    }

    public function test_si_no_hubo_respuesta_a_tiempo_se_ofrece_ese_motivo(): void
    {
        $this->responsable();
        $this->ajustes(['recordar_minutos' => 5, 'escalar_minutos' => 15]);
        $this->registrar()->assertSessionHas('ok');
        $acceso = $this->ultimo();
        $this->travel(16)->minutes();
        $this->actingAs($this->supervisor)->get('/accesos?pestana=pendientes')->assertSee('El responsable no contestó a tiempo');
        $this->excepcion($acceso, 'sin_respuesta')->assertSessionHas('ok');
        $this->assertSame(['en_sitio', 'El responsable no contestó a tiempo'], [$this->ultimo()->estado, $this->ultimo()->motivo_excepcion]);
    }

    // ------------------------------------------------------------------ Configuración

    public function test_tipo_desmarcado_sigue_como_hoy_y_las_casillas_se_guardan(): void
    {
        $this->responsable();
        // Por omisión las tres van marcadas
        $ajustes = app(AjustesRecepcion::class);
        $this->assertSame([true, true, true], [$ajustes->esperaAlDepartamento($this->empresa, 'visitante'), $ajustes->esperaAlDepartamento($this->empresa, 'proveedor'),
            $ajustes->esperaAlDepartamento($this->empresa, 'contratista')]);
        $this->actingAs($this->admin)->get('/autorizaciones/responsables')->assertOk()
            ->assertSee('Esperan la autorización del responsable del departamento')
            ->assertSee('name="proveedores_requieren_autorizacion" value="1" checked', false)
            ->assertSee('name="contratistas_requieren_autorizacion" value="1" checked', false);

        $this->actingAs($this->admin)->put('/rh/recepcion/ajustes', ['visitas_requieren_autorizacion' => '1', 'proveedores_requieren_autorizacion' => '0',
            'contratistas_requieren_autorizacion' => '1', 'rh_autoriza_paso' => '1', 'recordar_minutos' => 10, 'escalar_minutos' => 20])->assertSessionHas('ok');
        $this->empresa->refresh();
        $this->assertSame([true, false, true], [$ajustes->esperaAlDepartamento($this->empresa, 'visitante'), $ajustes->esperaAlDepartamento($this->empresa, 'proveedor'),
            $ajustes->esperaAlDepartamento($this->empresa, 'contratista')]);
        $this->assertTrue(Auditoria::where('evento', 'candidatos.configurado')->whereJsonContains('despues->proveedores_requieren_autorizacion', false)->exists());

        // Proveedor: como hoy (departamento opcional, se confirma con el host, sin solicitud)
        $this->registrar(['departamento_id' => ''], $this->supervisor)
            ->assertSessionHas('ok', 'Registrado: LUIS CHI queda pendiente de autorización. Confirma con el host (Carlos Hernández) y toca Confirmar Autorización.');
        $acceso = $this->ultimo();
        $this->assertSame(['pendiente', null], [$acceso->estado, $acceso->autorizacion]);
        $this->assertNull($this->solicitudDe($acceso));
        $this->actingAs($this->supervisor)->get('/accesos?pestana=pendientes')->assertSee('Confirma con el host: Carlos Hernández')->assertSee('Confirmar Autorización');
        $this->actingAs($this->supervisor)->patch("/accesos/{$acceso->id}/autorizar")->assertSessionHas('ok');
        $this->assertSame('en_sitio', $this->ultimo()->estado);
        $this->excepcion($this->ultimo(), 'telefono')->assertSessionHas('error', 'Este acceso ya no espera la autorización del departamento. Revisa la lista.');

        // Contratista sigue marcado: espera al departamento
        $this->registrar(['tipo' => 'contratista', 'nombre' => 'José Ek', 'empresa_procedencia' => 'Constructora Maya'])->assertSessionHas('ok');
        $this->assertSame('esperando', $this->ultimo()->autorizacion);
    }

    public function test_otra_empresa_u_otra_sede_no_ve_la_solicitud_ni_autoriza_por_excepcion(): void
    {
        $this->responsable($this->centro);
        $this->registrar()->assertSessionHas('ok');
        $acceso = $this->ultimo();
        $solicitud = $this->solicitudDe($acceso);

        // Supervisor de otra sede: no la ve en su caseta ni la puede pasar
        $supervisorPlaya = $this->crearUsuario($this->empresa, 'Supervisor', $this->playa);
        $this->actingAs($supervisorPlaya)->get('/accesos?pestana=pendientes')->assertOk()->assertDontSee('id="acceso-'.$acceso->id.'"', false);
        $this->excepcion($acceso, 'telefono', '', $supervisorPlaya)->assertNotFound();
        // Responsable del mismo departamento en otra sede: no la recibe ni la responde
        $jefePlaya = $this->crearUsuario($this->empresa, 'Jefe de departamento');
        $this->enEmpresa(fn () => DepartamentoResponsable::create(['departamento_id' => $this->mantenimiento->id, 'user_id' => $jefePlaya->id, 'es_suplente' => false, 'sede_id' => $this->playa->id]));
        $this->assertSame(0, $this->avisos($jefePlaya, 'autorizacion_visita'));
        $this->actingAs($jefePlaya)->post("/autorizaciones/{$solicitud->id}/responder", ['respuesta' => 'autorizar'])->assertNotFound();

        // Otra empresa: ni la solicitud ni el acceso existen para ella
        $otra = $this->crearEmpresa('Hotel Ceiba');
        $this->crearSede($otra, 'CEN');
        $adminOtra = $this->crearUsuario($otra, 'Administrador');
        $this->actingAs($adminOtra)->get("/autorizaciones/{$solicitud->id}")->assertNotFound();
        $this->excepcion($acceso, 'telefono', '', $adminOtra)->assertNotFound();
        $this->actingAs($adminOtra)->get('/accesos?pestana=pendientes')->assertOk()->assertDontSee('id="acceso-'.$acceso->id.'"', false);
        $this->assertSame(['pendiente', 'pendiente'], [$this->ultimo()->estado, $solicitud->refresh()->estado]);
    }

    public function test_la_migracion_conserva_el_valor_de_visitas_de_las_empresas_existentes(): void
    {
        $migracion = require database_path('migrations/2026_10_30_000700_proveedores_esperan_al_departamento.php');
        $ajustes = app(AjustesRecepcion::class);
        // Empresa que ya existía sin el dato (equivalía a «no»)
        $this->ajustes(['rh_autoriza_paso' => true]);
        $migracion->down();
        $this->assertFalse(Schema::hasColumn('accesos', 'motivo_excepcion'));
        $migracion->up();
        $this->empresa->refresh();
        $this->assertSame([false, true, true, true], [$ajustes->esperaAlDepartamento($this->empresa, 'visitante'), $ajustes->esperaAlDepartamento($this->empresa, 'proveedor'),
            $ajustes->esperaAlDepartamento($this->empresa, 'contratista'), $ajustes->rhAutorizaPaso($this->empresa)]);
        // Revertir deja la empresa como la leía la versión anterior (sin el dato = «no»)
        $migracion->down();
        $this->assertArrayNotHasKey('visitas_requieren_autorizacion', $this->empresa->refresh()->preferencias['recepcion']);
        $migracion->up();
        $this->assertTrue(Schema::hasColumn('accesos', 'motivo_excepcion'));
    }
}
