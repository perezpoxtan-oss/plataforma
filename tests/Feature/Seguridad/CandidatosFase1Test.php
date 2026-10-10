<?php

namespace Tests\Feature\Seguridad;

use App\Console\Commands\CrearDatosDemo;
use App\Mail\AvisoRecepcion;
use App\Models\Acceso;
use App\Models\Auditoria;
use App\Models\Autorizacion;
use App\Models\Candidato;
use App\Models\Empresa;
use App\Models\EnlaceKiosco;
use App\Models\Notificacion;
use App\Models\Persona;
use App\Models\Postulacion;
use App\Models\Rol;
use App\Models\RolPermiso;
use App\Models\Sede;
use App\Models\User;
use App\Models\UsuarioRol;
use App\Models\Vacante;
use App\Services\Auditoria\LectorAuditoria;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use App\Services\Recepcion\AjustesRecepcion;
use App\Services\Vacantes\AdministradorVacantes;
use App\Services\Vacantes\BolsaTrabajo;
use App\Support\CorreoPlataforma;
use App\Support\Menu\MisPendientes;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Candidatos, fase 1: postulaciones, una ficha por persona, «¿A qué viene?»
 * en la caseta y el «Que pase» de Recursos Humanos.
 */
class CandidatosFase1Test extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private Sede $playa;

    private User $admin;

    private User $rh;

    private User $agente;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa('Hotel Ébano');
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->playa = $this->crearSede($this->empresa, 'PLA');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->rh = $this->crearUsuario($this->empresa, 'Recursos Humanos');
        $this->agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
    }

    // ------------------------------------------------------------------ Ayudas

    private function enEmpresa(callable $fn): mixed
    {
        return app(Tenant::class)->conEmpresa($this->empresa->id, $fn);
    }

    /** @param array<string, mixed> $extra */
    private function caseta(array $extra = [], ?User $quien = null): TestResponse
    {
        return $this->actingAs($quien ?? $this->agente)->post('/accesos', $extra + [
            'sede_id' => $this->centro->id, 'tipo' => 'visitante', 'nombre' => 'Karla Pérez Uc', 'motivo_visita' => 'rh',
            'viene_a' => 'busca_empleo', 'modo_arribo' => 'a_pie', 'identificacion' => 'ine',
        ]);
    }

    private function rhAutorizaPaso(bool $si): void
    {
        $e = $this->empresa->refresh();
        $recepcion = array_merge($e->preferencias['recepcion'] ?? [], ['rh_autoriza_paso' => $si]);
        $e->forceFill(['preferencias' => array_merge($e->preferencias ?? [], ['recepcion' => $recepcion])])->save();
    }

    private function vacante(string $titulo = 'Camarista'): Vacante
    {
        return $this->enEmpresa(function () use ($titulo) {
            $v = app(AdministradorVacantes::class)->crear($this->rh, ['titulo' => $titulo, 'plazas' => 1, 'todas_las_sedes' => '1', 'tipo_contrato' => 'indeterminado',
                'jornada' => 'completa', 'sueldo_a_tratar' => '1']);
            app(AdministradorVacantes::class)->cambiarEstado($this->rh, $v, 'publicada', null, AdministradorVacantes::hoy($this->empresa->refresh()));

            return $v->fresh();
        });
    }

    private function salida(Acceso $a): void
    {
        $this->enEmpresa(fn () => Acceso::whereKey($a->id)->update(['estado' => 'finalizado', 'salida_at' => now()]));
    }

    private function firmaImagen(): string
    {
        $img = imagecreatetruecolor(600, 200);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imageline($img, 40, 150, 560, 40, imagecolorallocate($img, 0, 0, 0));
        ob_start();
        imagejpeg($img, null, 70);

        return 'data:image/jpeg;base64,'.base64_encode((string) ob_get_clean());
    }

    // ------------------------------------------------------------------ Una ficha por persona

    public function test_dos_veces_en_caseta_es_una_ficha_con_dos_accesos_y_dos_postulaciones(): void
    {
        $this->rhAutorizaPaso(false);
        $this->caseta()->assertSessionHas('ok');
        $c = $this->enEmpresa(fn () => Candidato::sole());
        $primera = $this->enEmpresa(fn () => Acceso::sole());
        $this->assertSame('en_sitio', $primera->estado, 'Con el ajuste apagado pasa directo');
        $this->assertSame(1, $this->enEmpresa(fn () => Postulacion::where('candidato_id', $c->id)->count()));
        $this->salida($primera);

        // Vuelve: el padrón pregunta «¿Es la misma persona?» y la caseta dice que sí
        $this->caseta(['persona_decision' => 'misma'])->assertSessionHas('ok');
        $this->assertSame(1, $this->enEmpresa(fn () => Candidato::count()), 'No se crea otra ficha');
        $accesos = $this->enEmpresa(fn () => Acceso::orderBy('id')->get());
        $this->assertCount(2, $accesos);
        // Seguía en proceso (Registrado): la segunda visita se liga a la misma postulación
        $this->assertSame($accesos[0]->postulacion_id, $accesos[1]->postulacion_id);
        $this->assertSame($accesos[1]->id, $c->fresh()->acceso_id);
        $this->assertTrue($this->enEmpresa(fn () => $c->eventos()->where('comentario', 'like', 'Volvió a la caseta: Busca empleo%')->exists()));
        // Aviso a RR. HH.: «Volvió a la caseta»
        $this->assertTrue($this->enEmpresa(fn () => Notificacion::where('user_id', $this->rh->id)->where('titulo', 'Volvió a la caseta: Karla Pérez Uc')->exists()));

        // Lo descartan y vuelve otra vez: nueva postulación en la misma ficha
        $this->actingAs($this->rh)->patch("/candidatos/{$c->id}/etapa", ['etapa' => 'descartado', 'comentario' => 'No cubre el horario'])->assertSessionHas('ok');
        $this->salida($accesos[1]);
        $vacante = $this->vacante();
        $this->caseta(['persona_decision' => 'misma', 'vacante_id' => $vacante->id])->assertSessionHas('ok');
        $this->assertSame(1, $this->enEmpresa(fn () => Candidato::count()));
        $postulaciones = $this->enEmpresa(fn () => Postulacion::where('candidato_id', $c->id)->orderBy('id')->get());
        $this->assertCount(2, $postulaciones);
        $this->assertSame(['descartado', 'registrado'], $postulaciones->pluck('etapa')->all());
        $this->assertSame($vacante->id, $postulaciones[1]->vacante_id);
        // La ficha refleja la postulación activa (la nueva)
        $c->refresh();
        $this->assertSame('registrado', $c->etapa);
        $this->assertSame($vacante->id, $c->vacante_id);
        $this->assertNull($c->motivo_descarte);

        // La ficha muestra la activa y las anteriores; la lista, una tarjeta por persona
        $this->actingAs($this->rh)->get("/candidatos/{$c->id}")->assertOk()->assertSee('Postulaciones')->assertSee('Anteriores')
            ->assertSee('No cubre el horario');
        $this->assertSame(1, substr_count((string) $this->actingAs($this->rh)->get('/candidatos')->getContent(), 'class="ficha-card ficha-candidato"'));
        // La vacante cuenta su postulación
        $this->actingAs($this->rh)->get('/candidatos?vacante='.$vacante->id)->assertSee('Karla Pérez Uc');
    }

    public function test_internet_y_rh_son_la_misma_ficha_y_rh_ve_el_aviso_de_duplicado(): void
    {
        $this->rhAutorizaPaso(false);
        $vacante = $this->vacante();
        $this->enEmpresa(fn () => app(BolsaTrabajo::class)->postular($this->empresa->refresh(), $vacante->fresh('sedes'), [
            'nombre' => 'Karla', 'apellido_paterno' => 'Pérez', 'apellido_materno' => 'Uc', 'telefono' => '998 123 4567', 'sede_id' => $this->centro->id,
            'referencias' => [['nombre' => 'Martha Chablé', 'telefono' => '9981112233'], ['nombre' => 'Rosa Uc', 'telefono' => '9984445566']],
            'acepta_privacidad' => '1', 'declaracion' => '1', 'firma' => $this->firmaImagen(), 'curp' => 'PEUK970815MQRRCR09',
        ], null, '187.190.10.20'));
        $c = $this->enEmpresa(fn () => Candidato::sole());
        $this->assertSame('web', $c->origen);

        // Se vuelve a postular por internet (mismo teléfono): misma ficha y misma postulación abierta
        $this->enEmpresa(fn () => app(BolsaTrabajo::class)->postular($this->empresa->refresh(), $vacante->fresh('sedes'), [
            'nombre' => 'Karla', 'apellido_paterno' => 'Pérez', 'apellido_materno' => 'Uc', 'telefono' => '9981234567', 'sede_id' => $this->centro->id,
            'referencias' => [['nombre' => 'Martha Chablé', 'telefono' => '9981112233'], ['nombre' => 'Rosa Uc', 'telefono' => '9984445566']],
            'acepta_privacidad' => '1', 'declaracion' => '1', 'firma' => $this->firmaImagen(),
        ], null, '187.190.10.20'));
        $this->assertSame(1, $this->enEmpresa(fn () => Candidato::count()));
        $this->assertSame(1, $this->enEmpresa(fn () => Postulacion::count()));
        $this->assertTrue($this->enEmpresa(fn () => $c->eventos()->where('comentario', 'like', 'Se volvió a postular por internet%')->exists()));

        // «Nuevo candidato» de RR. HH.: aviso en vivo con «Abrir su ficha»
        $this->actingAs($this->rh)->getJson('/candidatos/duplicado?campo=telefono&valor=998-123-4567')->assertOk()
            ->assertJsonPath('estado', 'parecido')->assertJsonPath('coincidencias.0.titulo', 'Karla Pérez Uc')
            ->assertJsonPath('coincidencias.0.abrir', route('candidatos.show', $c->id));
        $this->actingAs($this->rh)->getJson('/candidatos/duplicado?campo=curp&valor=PEUK970815MQRRCR09')->assertJsonPath('estado', 'parecido');
        $this->actingAs($this->rh)->getJson('/candidatos/duplicado?campo=telefono&valor=9990000000')->assertJsonPath('estado', 'nada');
        $this->actingAs($this->agente)->getJson('/candidatos/duplicado?campo=telefono&valor=9981234567')->assertForbidden();
        $this->actingAs($this->rh)->get('/candidatos')->assertSee('data-duplicado="'.route('candidatos.duplicado').'"', false);

        // Y si guarda de todos modos: no se crea otra ficha
        $this->actingAs($this->rh)->post('/candidatos', ['sede_id' => $this->centro->id, 'nombre_completo' => 'Karla Pérez Uc', 'telefono' => '9981234567',
            'acepta_privacidad' => '1'])->assertRedirect("/candidatos/{$c->id}")->assertSessionHas('ok', fn ($m) => str_contains($m, 'ya tenía ficha'));
        $this->assertSame(1, $this->enEmpresa(fn () => Candidato::count()));
    }

    public function test_internet_y_caseta_son_la_misma_ficha(): void
    {
        $this->rhAutorizaPaso(false);
        $vacante = $this->vacante();
        $this->enEmpresa(fn () => app(BolsaTrabajo::class)->postular($this->empresa->refresh(), $vacante->fresh('sedes'), [
            'nombre' => 'Karla', 'apellido_paterno' => 'Pérez', 'apellido_materno' => 'Uc', 'telefono' => '998 123 4567', 'sede_id' => $this->centro->id,
            'referencias' => [['nombre' => 'Martha Chablé', 'telefono' => '9981112233'], ['nombre' => 'Rosa Uc', 'telefono' => '9984445566']],
            'acepta_privacidad' => '1', 'declaracion' => '1', 'firma' => $this->firmaImagen(),
        ], null, '187.190.10.20'));

        // Llega a la caseta: la persona del padrón (con su teléfono) lleva a la misma ficha y a su postulación abierta
        $this->enEmpresa(fn () => Persona::create(['tipo' => 'visitante', 'categoria' => 'general', 'nombre_completo' => 'KARLA PÉREZ UC', 'telefono' => '9981234567']));
        $persona = $this->enEmpresa(fn () => Persona::sole());
        $this->caseta(['persona_id' => $persona->id, 'viene_a' => 'entrevista'])->assertSessionHas('ok');
        $this->assertSame(1, $this->enEmpresa(fn () => Candidato::count()));
        $acceso = $this->enEmpresa(fn () => Acceso::sole());
        $this->assertSame($this->enEmpresa(fn () => Postulacion::sole()->id), $acceso->postulacion_id);
        $this->assertSame('entrevista', $acceso->viene_a);
        $c = $this->enEmpresa(fn () => Candidato::sole());
        $this->assertSame('web', $c->origen);
        $this->assertSame($acceso->id, $c->acceso_id);
        $this->assertTrue($this->enEmpresa(fn () => $c->eventos()->where('comentario', 'like', 'Volvió a la caseta: Entrevista%')->exists()));
    }

    // ------------------------------------------------------------------ ¿A qué viene?

    public function test_los_cuatro_viene_a_con_ficha_y_los_que_no_crean_ficha(): void
    {
        $this->rhAutorizaPaso(false);
        $this->actingAs($this->agente)->get('/accesos')->assertOk()->assertSee('¿A qué viene?')->assertSee('Firma de contrato')
            ->assertSee('Informes / ver vacantes')->assertDontSee('Viene como candidato')->assertDontSee('Vacante (si no está en la lista)');

        // Entrevista sin ficha: se registra como «Busca empleo» y queda anotado
        $this->caseta(['nombre' => 'Luis Chi Canul', 'viene_a' => 'entrevista'])->assertSessionHas('ok');
        $luis = $this->enEmpresa(fn () => Candidato::sole());
        $this->assertSame('busca_empleo', $this->enEmpresa(fn () => Acceso::sole()->viene_a));
        $this->assertTrue($this->enEmpresa(fn () => $luis->eventos()->where('comentario', 'like', '%no se encontró su ficha%')->exists()));

        // Documentos y firma: se encuentra por su nombre exacto y se liga a su postulación abierta
        foreach (['documentos', 'firma'] as $vieneA) {
            $this->enEmpresa(fn () => Acceso::query()->update(['estado' => 'finalizado']));
            $this->caseta(['nombre' => 'LUIS CHI CANUL', 'viene_a' => $vieneA, 'persona_decision' => 'distinta'])->assertSessionHas('ok');
            $acceso = $this->enEmpresa(fn () => Acceso::orderByDesc('id')->first());
            $this->assertSame($vieneA, $acceso->viene_a);
            $this->assertSame($this->enEmpresa(fn () => Postulacion::sole()->id), $acceso->postulacion_id);
        }
        $this->assertSame(1, $this->enEmpresa(fn () => Candidato::count()));

        // Informes y trámite: sin ficha
        $this->caseta(['nombre' => 'Rosa Pool', 'viene_a' => 'informes'])->assertSessionHas('ok');
        $this->caseta(['nombre' => 'Mario Ek', 'viene_a' => 'tramite'])->assertSessionHas('ok');
        $this->assertSame(1, $this->enEmpresa(fn () => Candidato::count()));
        $this->assertSame(['informes', 'tramite'], $this->enEmpresa(fn () => Acceso::whereIn('nombre', ['ROSA POOL', 'MARIO EK'])->orderBy('id')->pluck('viene_a')->all()));
        $this->assertNull($this->enEmpresa(fn () => Acceso::where('nombre', 'ROSA POOL')->value('postulacion_id')));
        // Opción inventada
        $this->caseta(['nombre' => 'X', 'viene_a' => 'otra_cosa', '_dialogo' => 'ingreso'])->assertSessionHasErrors('viene_a');

        // La caseta ve «Candidato · Firma» pero nunca etapas ni datos del CV
        $this->actingAs($this->rh)->patch("/candidatos/{$luis->id}/etapa", ['etapa' => 'revision'])->assertSessionHas('ok');
        $this->flushSession();
        $caseta = $this->actingAs($this->agente)->get('/accesos')->assertOk()->assertSee('Candidato · Firma de contrato')->assertSee('Informes / ver vacantes');
        $caseta->assertDontSee('En revisión RR. HH.')->assertDontSee('Aplica a:');
        $this->actingAs($this->agente)->get("/candidatos/{$luis->id}")->assertForbidden();
    }

    // ------------------------------------------------------------------ Que pase / Que espere / No

    public function test_rh_dice_que_pase_por_la_campana_y_la_caseta_lo_ve(): void
    {
        Mail::fake();
        app(CorreoPlataforma::class)->guardar(['host' => 'mail.ejemplo.com', 'puerto' => 587, 'cifrado' => 'tls', 'usuario' => 'a@ejemplo.com',
            'remitente_correo' => 'avisos@ejemplo.com', 'remitente_nombre' => 'Avisos'], 'x');

        $this->caseta()->assertRedirect('/accesos?pestana=pendientes')->assertSessionHas('ok', fn ($m) => str_contains($m, 'ESPERANDO A RR. HH.'));
        $acceso = $this->enEmpresa(fn () => Acceso::sole());
        $this->assertSame(['pendiente', 'esperando'], [$acceso->estado, $acceso->autorizacion]);
        $a = $this->enEmpresa(fn () => Autorizacion::sole());
        $this->assertSame('recepcion', $a->tipo);
        $this->assertNull($a->departamento_id);
        $this->assertEqualsCanonicalizing([$this->admin->id, $this->rh->id], $a->avisados, 'Avisa a quien edita candidatos o ve Recepción en esa sede');
        $this->assertFalse($this->enEmpresa(fn () => Notificacion::where('tipo', 'candidato_llegada')->exists()), 'Un solo aviso (el de los botones)');
        Mail::assertSent(AvisoRecepcion::class, fn ($m) => $m->hasTo($this->rh->email) && count($m->botones) === 3 && str_contains($m->botones[0][1], 'signature='));

        // La tarjeta de caseta
        $this->actingAs($this->agente)->get('/accesos?pestana=pendientes')->assertSee('ESPERANDO A RR. HH.')->assertSee('Candidato · Busca empleo');
        $this->actingAs($this->agente)->getJson('/accesos/autorizaciones-estado?ids='.$acceso->id)->assertJsonPath('estados.'.$acceso->id, 'pendiente|esperando');

        // Campana de RR. HH. con los tres botones
        $this->actingAs($this->rh)->getJson('/notificaciones/resumen')->assertJsonPath('lista.0.titulo', 'Llegó un candidato: Karla Pérez Uc')
            ->assertJsonPath('lista.0.acciones.0.etiqueta', 'Que pase')->assertJsonPath('lista.0.acciones.1.etiqueta', 'Que espere')
            ->assertJsonPath('lista.0.acciones.1.estilo', 'esperar')->assertJsonPath('lista.0.acciones.2.etiqueta', 'No puede pasar');
        // El agente no responde; la respuesta de una visita no aplica
        $this->actingAs($this->agente)->post("/autorizaciones/{$a->id}/responder", ['respuesta' => 'pase'])->assertForbidden();
        $this->actingAs($this->rh)->post("/autorizaciones/{$a->id}/responder", ['respuesta' => 'autorizar'])->assertSessionHas('error');

        // El botón del correo: dirección firmada que pide confirmar
        $this->actingAs($this->rh)->get(URL::temporarySignedRoute('autorizaciones.confirmar', now()->addHour(), ['autorizacion' => $a->id, 'respuesta' => 'pase']))
            ->assertOk()->assertSee('Confirmar: Que pase')->assertSee('Busca empleo');

        $this->actingAs($this->rh)->post("/autorizaciones/{$a->id}/responder", ['respuesta' => 'pase', 'medio' => 'correo'])->assertRedirect('/rh/recepcion')->assertSessionHas('ok');
        $acceso->refresh();
        $this->assertSame(['en_sitio', 'autorizada', $this->rh->id], [$acceso->estado, $acceso->autorizacion, $acceso->autorizado_por]);
        $this->assertSame(['autorizada', 'correo'], [$a->fresh()->estado, $a->fresh()->respuesta_medio]);
        $this->actingAs($this->agente)->get('/accesos')->assertSee('RR. HH. dijo que pase');
        $this->assertTrue($this->enEmpresa(fn () => Notificacion::where('user_id', $this->agente->id)->where('titulo', 'like', 'RR. HH. dice que pase%')->exists()));
        $this->actingAs($this->rh)->getJson('/notificaciones/resumen')->assertJsonPath('lista.0.acciones', []);
        $this->assertSame('Respuesta', app(LectorAuditoria::class)->accion('autorizaciones.respondida'));
    }

    public function test_que_espere_sigue_pendiente_y_no_puede_pasar_lo_cierra(): void
    {
        $this->caseta()->assertSessionHas('ok');
        $acceso = $this->enEmpresa(fn () => Acceso::sole());
        $a = $this->enEmpresa(fn () => Autorizacion::sole());

        $this->actingAs($this->rh)->post("/autorizaciones/{$a->id}/responder", ['respuesta' => 'espere', 'comentario' => 'Termino una entrevista'])->assertSessionHas('ok');
        $this->assertSame(['pendiente', 'espera'], [$acceso->fresh()->estado, $acceso->fresh()->autorizacion]);
        $this->assertSame('pendiente', $a->fresh()->estado);
        $this->actingAs($this->agente)->get('/accesos?pestana=pendientes')->assertSee('RR. HH. PIDE QUE ESPERE')->assertSee('data-estado-esperado="pendiente|espera"', false);
        $this->actingAs($this->agente)->getJson('/accesos/autorizaciones-estado?ids='.$acceso->id)->assertJsonPath('estados.'.$acceso->id, 'pendiente|espera');
        $this->assertTrue($this->enEmpresa(fn () => Notificacion::where('user_id', $this->agente->id)->where('titulo', 'like', 'RR. HH. pide que espere%')->exists()));
        // El aviso de RR. HH. sigue pendiente con sus botones
        $this->actingAs($this->rh)->getJson('/notificaciones/resumen')->assertJsonPath('lista.0.acciones.0.etiqueta', 'Que pase');
        $this->assertTrue(Auditoria::where('evento', 'autorizaciones.espera')->exists());
        $this->assertSame('Petición de espera', app(LectorAuditoria::class)->accion('autorizaciones.espera'));

        $this->actingAs($this->rh)->post("/autorizaciones/{$a->id}/responder", ['respuesta' => 'no_pasa'])->assertSessionHas('ok');
        $this->assertSame(['finalizado', 'rechazada'], [$acceso->fresh()->estado, $acceso->fresh()->autorizacion]);
        $this->assertSame('rechazada', $a->fresh()->estado);
        $this->actingAs($this->agente)->get('/accesos?pestana=historial')->assertSee('NO AUTORIZADO por Recursos Humanos');
        // Dos veces no
        $this->actingAs($this->rh)->post("/autorizaciones/{$a->id}/responder", ['respuesta' => 'pase'])->assertSessionHas('error');
    }

    public function test_el_supervisor_confirma_en_caseta_como_con_las_visitas(): void
    {
        $this->caseta()->assertSessionHas('ok');
        $acceso = $this->enEmpresa(fn () => Acceso::sole());
        $supervisor = $this->crearUsuario($this->empresa, 'Supervisor', $this->centro);
        $this->actingAs($supervisor)->get('/accesos?pestana=pendientes')->assertSee('¿Recursos Humanos ya autorizó');
        $this->actingAs($supervisor)->patch("/accesos/{$acceso->id}/autorizar")->assertSessionHas('ok');
        $a = $this->enEmpresa(fn () => Autorizacion::sole());
        $this->assertSame(['autorizada', 'caseta'], [$a->estado, $a->respuesta_medio]);
        $this->assertSame(['en_sitio', 'autorizada'], [$acceso->fresh()->estado, $acceso->fresh()->autorizacion]);
    }

    public function test_con_el_ajuste_apagado_o_sin_quien_atienda_pasa_directo(): void
    {
        // Ajuste de Recepción (lo guarda quien configura candidatos)
        $this->actingAs($this->rh)->get('/rh/recepcion/ajustes')->assertOk()->assertSee('La caseta espera a que RR. HH. diga «Que pase»');
        $this->actingAs($this->rh)->put('/rh/recepcion/ajustes', ['aviso_privacidad' => '', 'kiosco_horas' => 2, 'kiosco_usos' => 3, 'rh_autoriza_paso' => '0'])->assertSessionHas('ok');
        $this->assertFalse(app(AjustesRecepcion::class)->rhAutorizaPaso($this->empresa->refresh()));
        $this->caseta()->assertSessionHas('ok');
        $acceso = $this->enEmpresa(fn () => Acceso::sole());
        $this->assertSame('en_sitio', $acceso->estado);
        $this->assertNull($acceso->autorizacion);
        $this->assertSame(0, $this->enEmpresa(fn () => Autorizacion::count()));
        $this->assertTrue($this->enEmpresa(fn () => Notificacion::where('tipo', 'candidato_llegada')->where('user_id', $this->rh->id)->exists()));

        // Encendido, pero nadie atiende Recursos Humanos en esa sede: pasa directo
        $this->rhAutorizaPaso(true);
        foreach (Rol::where('empresa_id', $this->empresa->id)->get() as $rol) {
            RolPermiso::where('rol_id', $rol->id)->whereIn('modulo_accion_id', DB::table('modulo_acciones as ma')->join('modulos as m', 'm.id', '=', 'ma.modulo_id')
                ->whereIn('m.clave', ['candidatos', 'recepcion_rh'])->select('ma.id'))->delete();
        }
        app(Autorizador::class)->olvidar();
        $this->caseta(['nombre' => 'Rosa Pool', 'viene_a' => 'tramite'])->assertSessionHas('ok');
        $this->assertSame('en_sitio', $this->enEmpresa(fn () => Acceso::where('nombre', 'ROSA POOL')->value('estado')));
    }

    // ------------------------------------------------------------------ Recepción y Mis pendientes

    public function test_panel_de_recepcion_viene_a_que_pase_atender_y_sin_qr(): void
    {
        $this->caseta()->assertSessionHas('ok');
        $this->caseta(['nombre' => 'Mario Ek', 'viene_a' => 'tramite'])->assertSessionHas('ok');
        $a = $this->enEmpresa(fn () => Autorizacion::whereHas('acceso', fn ($q) => $q->where('nombre', 'KARLA PÉREZ UC'))->sole());

        $panel = $this->actingAs($this->rh)->get('/rh/recepcion')->assertOk()
            ->assertSee('Viene a: Busca empleo — primera vez')->assertSee('Viene a: Trámite')->assertSee('Que pase')->assertSee('Atender')->assertSee('Abrir ficha')
            ->assertSee('En caseta: espera que digas «Que pase»');
        $this->assertStringNotContainsString(route('recepcion.kiosco.generar', 1), (string) $panel->getContent());
        $this->assertStringNotContainsString('bi-qr-code me-1" aria-hidden="true"></i>QR</button>', (string) $panel->getContent());
        $this->actingAs($this->rh)->getJson('/rh/recepcion/datos')->assertJsonPath('total', 2)->assertJsonPath('contadores.esperando', 2);

        // «Que pase» desde el panel regresa al panel
        $this->actingAs($this->rh)->post("/autorizaciones/{$a->id}/responder", ['respuesta' => 'pase', 'volver' => 'recepcion'])->assertRedirect('/rh/recepcion');
        $c = $this->enEmpresa(fn () => Candidato::sole());
        $this->actingAs($this->rh)->patch("/candidatos/{$c->id}/etapa", ['etapa' => 'revision', 'volver' => 'recepcion'])->assertRedirect('/rh/recepcion');
        $this->actingAs($this->rh)->getJson('/rh/recepcion/datos')->assertJsonPath('contadores.esperando', 1);

        // La segunda visita de la misma persona dice «ya vino antes»
        $this->salida($this->enEmpresa(fn () => Acceso::where('nombre', 'KARLA PÉREZ UC')->sole()));
        $this->caseta(['persona_decision' => 'misma'])->assertSessionHas('ok');
        $this->actingAs($this->rh)->get('/rh/recepcion')->assertSee('Viene a: Busca empleo — ya vino antes');

        // En la ficha: «Atender» y un solo botón de QR
        $this->actingAs($this->rh)->get("/candidatos/{$c->id}")->assertSee('QR para que llene su solicitud')->assertDontSee('Generar QR del kiosco')
            ->assertDontSee('Mostrar QR')->assertDontSee('Tomar en revisión');
        $this->actingAs($this->rh)->post("/rh/recepcion/kiosco/{$c->id}", ['reusar' => '1'])->assertRedirect(route('recepcion.kiosco', ['candidato' => $c->id]));
        $enlace = $this->enEmpresa(fn () => EnlaceKiosco::sole());
        // Con uno vigente no se crea otro: se muestra el mismo
        $this->actingAs($this->rh)->post("/rh/recepcion/kiosco/{$c->id}", ['reusar' => '1'])->assertRedirect();
        $this->assertSame($enlace->id, $this->enEmpresa(fn () => EnlaceKiosco::sole()->id));
        $this->actingAs($this->rh)->get("/candidatos/{$c->id}")->assertSee('Anular enlace')->assertSee($enlace->codigo);
    }

    public function test_mis_pendientes_de_rh_esperando_en_recepcion_y_solicitudes_por_revisar(): void
    {
        $this->caseta()->assertSessionHas('ok');
        $this->caseta(['nombre' => 'Mario Ek', 'viene_a' => 'tramite'])->assertSessionHas('ok');
        // Una solicitud que llenó el candidato y RR. HH. no ha revisado
        $revisar = $this->enEmpresa(function () {
            $c = new Candidato(['sede_id' => $this->centro->id, 'nombre_completo' => 'Ana Pool', 'origen' => 'kiosco', 'llegada_en' => now()->subDays(2)]);
            $c->forceFill(['autocaptura_pendiente' => true, 'created_at' => now()->subDays(2)])->save();

            return $c;
        });

        Cache::flush();
        $mios = app(MisPendientes::class)->para($this->rh);
        $items = collect($mios['items'])->keyBy('clave');
        $this->assertSame(2, $items['recepcion']['total'], 'Dos accesos esperan el «Que pase» (el candidato se cuenta una vez)');
        $this->assertSame(route('recepcion.index'), $items['recepcion']['url']);
        $this->assertSame(1, $items['solicitudes']['total']);
        $this->assertSame(route('candidatos.index', ['revisar' => 1]), $items['solicitudes']['url']);
        // La campana suma lo mismo
        $this->actingAs($this->rh)->getJson('/notificaciones/resumen')->assertJsonPath('pendientes.total', $mios['total']);
        // La lista filtrada
        $this->actingAs($this->rh)->get('/candidatos?revisar=1')->assertOk()->assertSee('Ana Pool')->assertDontSee('Karla Pérez Uc')
            ->assertSee('Solicitudes por revisar (1)');

        // El agente no tiene esos renglones
        $this->assertFalse(collect(app(MisPendientes::class)->para($this->agente)['items'])->contains('clave', 'recepcion'));

        // RR. HH. limitado a Playa no cuenta lo de Centro ni puede responder
        $rhPlaya = $this->crearUsuario($this->empresa, 'Recursos Humanos');
        $rol = Rol::where('empresa_id', $this->empresa->id)->where('nombre', 'Recursos Humanos')->firstOrFail();
        RolPermiso::where('rol_id', $rol->id)->update(['alcance' => Alcance::Sede]);
        UsuarioRol::where('user_id', $rhPlaya->id)->update(['sede_id' => $this->playa->id]);
        app(Autorizador::class)->olvidar();
        $items = collect(app(MisPendientes::class)->para($rhPlaya)['items'])->keyBy('clave');
        $this->assertSame(0, $items['recepcion']['total']);
        $this->assertSame(0, $items['solicitudes']['total']);
        $a = $this->enEmpresa(fn () => Autorizacion::orderBy('id')->first());
        $this->assertNotContains($rhPlaya->id, $a->avisados);
        $this->actingAs($rhPlaya)->post("/autorizaciones/{$a->id}/responder", ['respuesta' => 'pase'])->assertNotFound();
        $this->actingAs($rhPlaya)->get("/autorizaciones/{$a->id}")->assertNotFound();
        $this->actingAs($rhPlaya)->get('/rh/recepcion')->assertOk()->assertDontSee('Karla Pérez Uc');
        $this->assertSame('pendiente', $a->fresh()->estado);
        $this->assertNotNull($revisar->id);
    }

    // ------------------------------------------------------------------ Migración, auditoría y demo

    public function test_la_migracion_crea_una_postulacion_por_ficha_y_es_idempotente(): void
    {
        $vacante = $this->vacante();
        [$ficha, $acceso] = $this->enEmpresa(function () use ($vacante) {
            $a = new Acceso(['sede_id' => $this->centro->id, 'tipo' => 'visitante', 'nombre' => 'PEDRO UICAB', 'motivo_visita' => 'rh', 'entrada_at' => now()]);
            $a->forceFill(['estado' => 'finalizado'])->save();
            $c = new Candidato(['sede_id' => $this->centro->id, 'nombre_completo' => 'Pedro Uicab', 'acceso_id' => $a->id, 'vacante' => 'Cocinero', 'origen' => 'caseta']);
            $c->forceFill(['etapa' => 'descartado', 'motivo_descarte' => 'Sin experiencia', 'vacante_id' => $vacante->id, 'revision_en' => now()->subDay(),
                'decision_en' => now(), 'decision_por' => $this->rh->id])->save();
            $tramite = new Acceso(['sede_id' => $this->centro->id, 'tipo' => 'visitante', 'nombre' => 'MARIO EK', 'motivo_visita' => 'rh', 'entrada_at' => now()]);
            $tramite->save();

            return [$c, $a];
        });
        // Una empresa que ya existía (sin el ajuste guardado)
        $this->empresa->refresh()->forceFill(['preferencias' => ['recepcion' => ['visitas_requieren_autorizacion' => true]]])->save();

        $migracion = require database_path('migrations/2026_10_19_000100_crear_postulaciones.php');
        $migracion->up();
        $migracion->up();

        $p = $this->enEmpresa(fn () => Postulacion::where('candidato_id', $ficha->id)->sole());
        $this->assertSame(['descartado', 'Sin experiencia', $vacante->id, 'Cocinero', 'caseta', $this->rh->id, $this->centro->id],
            [$p->etapa, $p->motivo_descarte, $p->vacante_id, $p->vacante, $p->origen, $p->decision_por, $p->sede_id]);
        $this->assertNotNull($p->revision_en);
        $acceso->refresh();
        $this->assertSame([$p->id, 'busca_empleo'], [$acceso->postulacion_id, $acceso->viene_a]);
        $this->assertSame('tramite', $this->enEmpresa(fn () => Acceso::where('nombre', 'MARIO EK')->value('viene_a')));
        // Empresas que ya existían: la caseta sigue igual (ajuste apagado) y no se pierde lo demás
        $this->empresa->refresh();
        $this->assertFalse(app(AjustesRecepcion::class)->rhAutorizaPaso($this->empresa));
        $this->assertTrue(app(AjustesRecepcion::class)->visitasRequierenAutorizacion($this->empresa));
        // Una empresa nueva lo trae encendido
        $this->assertTrue(app(AjustesRecepcion::class)->rhAutorizaPaso($this->crearEmpresa('Hotel Nuevo')));
    }

    public function test_auditoria_de_postulaciones_y_etiquetas(): void
    {
        $this->rhAutorizaPaso(false);
        $this->caseta()->assertSessionHas('ok');
        $c = $this->enEmpresa(fn () => Candidato::sole());
        $this->salida($this->enEmpresa(fn () => Acceso::sole()));
        $this->actingAs($this->rh)->patch("/candidatos/{$c->id}/etapa", ['etapa' => 'cartera'])->assertSessionHas('ok');
        $this->caseta(['persona_decision' => 'misma'])->assertSessionHas('ok');

        $nueva = Auditoria::where('evento', 'candidatos.postulacion_creada')->sole();
        $this->assertSame(Postulacion::class, $nueva->auditable_type);
        $this->assertTrue(Auditoria::where('evento', 'candidatos.visita_ligada')->exists());
        $lector = app(LectorAuditoria::class);
        $this->assertSame('Nueva postulación', $lector->accion('candidatos.postulacion_creada'));
        $this->assertSame('Visita ligada a su postulación', $lector->accion('candidatos.visita_ligada'));
        // La bitácora la muestra legible
        $this->actingAs($this->admin)->get('/auditoria')->assertOk()->assertSee('Nueva postulación')->assertSee('Postulación');
        // Sin datos personales en la auditoría de la postulación
        $this->assertArrayNotHasKey('telefono', (array) $nueva->despues);
    }

    public function test_el_demo_trae_un_candidato_con_dos_visitas_y_uno_esperando_a_rh(): void
    {
        $this->artisan('plataforma:demo', ['--password' => 'Demo1234!'])->assertSuccessful()->doesntExpectOutputToContain('No se pudo completar');
        $demo = Empresa::where('nombre_comercial', CrearDatosDemo::EMPRESA)->firstOrFail();
        app(Tenant::class)->conEmpresa($demo->id, function () {
            $jorge = Candidato::where('nombre_completo', 'Jorge Tun Pech')->firstOrFail();
            $this->assertSame(2, Acceso::whereIn('postulacion_id', Postulacion::where('candidato_id', $jorge->id)->select('id'))->count()
                + (Acceso::whereKey($jorge->getOriginal('acceso_id'))->whereNull('postulacion_id')->count()));
            $this->assertSame('entrevista', $jorge->etapa);
            $silvia = Candidato::where('nombre_completo', 'Silvia Mena Couoh')->firstOrFail();
            $this->assertSame(2, Postulacion::where('candidato_id', $silvia->id)->count());
            $martha = Candidato::where('nombre_completo', 'Martha Ek Chan')->firstOrFail();
            $this->assertSame(['pendiente', 'esperando'], [$martha->acceso->estado, $martha->acceso->autorizacion]);
            $this->assertTrue(Autorizacion::where('tipo', 'recepcion')->where('estado', 'pendiente')->where('acceso_id', $martha->acceso_id)->exists());
            // Cada ficha tiene su postulación y la refleja
            foreach (Candidato::all() as $c) {
                $p = Postulacion::where('candidato_id', $c->id)->orderByDesc('id')->first();
                $this->assertNotNull($p, "Sin postulación: {$c->nombre_completo}");
                $this->assertSame($p->etapa, $c->etapa, "Espejo de {$c->nombre_completo}");
            }
        });
        $rh = User::where('username', 'rh.demo')->firstOrFail();
        $this->actingAs($rh)->get('/rh/recepcion')->assertOk()->assertSee('Martha Ek Chan')->assertSee('Que pase');
    }
}
