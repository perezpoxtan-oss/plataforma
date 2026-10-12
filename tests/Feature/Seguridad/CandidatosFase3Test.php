<?php

namespace Tests\Feature\Seguridad;

use App\Console\Commands\CrearDatosDemo;
use App\Mail\AvisoRecepcion;
use App\Models\Auditoria;
use App\Models\Candidato;
use App\Models\CandidatoDocumento;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\DepartamentoResponsable;
use App\Models\DocumentoParaContratar;
use App\Models\Empresa;
use App\Models\Notificacion;
use App\Models\Postulacion;
use App\Models\Sede;
use App\Models\User;
use App\Services\Auditoria\LectorAuditoria;
use App\Services\Candidatos\CaducidadCandidatos;
use App\Services\Candidatos\DocumentosParaContratar;
use App\Services\Candidatos\PlazosCandidatos;
use App\Services\Candidatos\Postulaciones;
use App\Support\CorreoPlataforma;
use App\Support\HoraLocal;
use App\Support\Menu\MisPendientes;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Candidatos, fase 3 (ADR-0011): lista de documentos para contratar por
 * empresa, «Recibido en papel», contrato firmado, Contratar que avisa y se
 * fuerza con motivo, plazos con recordatorio único y color en Mis
 * pendientes, caducidad (archivar, avisar, conservar y borrar), expediente
 * del colaborador, la migración y el demo.
 */
class CandidatosFase3Test extends TestCase
{
    use AyudasEntrevistas, CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private Sede $playa;

    private User $admin;

    private User $rh;

    private User $agente;

    private User $jefa;

    private Departamento $cocina;

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
        $this->jefa = $this->crearUsuario($this->empresa, 'Jefe de departamento', $this->centro);
        $this->cocina = $this->enEmpresa(fn () => Departamento::create(['nombre' => 'Cocina']));
        $this->enEmpresa(fn () => DepartamentoResponsable::create(['departamento_id' => $this->cocina->id, 'user_id' => $this->jefa->id, 'es_suplente' => false, 'sede_id' => null]));
    }

    // ------------------------------------------------------------------ Ayudas

    private function enEmpresa(callable $fn, ?Empresa $empresa = null): mixed
    {
        return app(Tenant::class)->conEmpresa(($empresa ?? $this->empresa)->id, $fn);
    }

    /** Un candidato en la etapa pedida (la etapa vive en la postulación; la ficha es su espejo). */
    private function candidato(string $etapa = 'elegido', array $datos = [], ?Sede $sede = null): Candidato
    {
        return $this->enEmpresa(function () use ($etapa, $datos, $sede) {
            $c = new Candidato($datos + ['sede_id' => ($sede ?? $this->centro)->id, 'nombre_completo' => 'Luis Chi Canul', 'departamento_id' => $this->cocina->id,
                'origen' => 'rh', 'llegada_en' => now(), 'telefono' => '9981234567']);
            $c->forceFill(['etapa' => 'registrado', 'privacidad_aceptada_en' => now()])->save();
            $p = app(Postulaciones::class)->asegurar($c);
            $fechas = ['elegido' => ['elegido_en' => now()], 'canalizado' => ['canalizado_en' => now()], 'considerar' => ['decision_en' => now()], 'rechazado' => ['decision_en' => now()]];
            app(Postulaciones::class)->cambiar($p, null, ['etapa' => $etapa] + ($fechas[$etapa] ?? []), null);

            return $c->fresh();
        });
    }

    private function postulacion(Candidato $c): Postulacion
    {
        return $this->enEmpresa(fn () => Postulacion::where('candidato_id', $c->id)->orderByDesc('id')->firstOrFail());
    }

    private function configurarCorreo(): void
    {
        app(CorreoPlataforma::class)->guardar(['host' => 'mail.ejemplo.com', 'puerto' => 587, 'cifrado' => 'tls', 'usuario' => 'a@ejemplo.com',
            'remitente_correo' => 'avisos@ejemplo.com', 'remitente_nombre' => 'Avisos'], 'x');
    }

    private function pdf(string $nombre = 'documento.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nombre, "%PDF-1.4\n1 0 obj << /Type /Catalog >> endobj\n%%EOF\n");
    }

    /** Marca en papel todos los documentos de la lista estándar. */
    private function todoEnPapel(Candidato $c): void
    {
        foreach (array_keys(DocumentosParaContratar::DEFECTO) as $tipo) {
            $this->actingAs($this->rh)->post("/candidatos/{$c->id}/documentos/papel", ['tipo' => $tipo])->assertSessionHas('ok');
        }
    }

    /** @return array<string, string> */
    private function plazos(array $cambios = []): array
    {
        return array_map('strval', $cambios + PlazosCandidatos::DEFECTO);
    }

    // ------------------------------------------------------------------ Configuración

    public function test_la_lista_de_documentos_y_los_plazos_se_configuran_por_empresa(): void
    {
        // Sin configurar, la ficha usa la lista estándar y el botón solo lo ve quien configura
        $c = $this->candidato();
        $this->actingAs($this->rh)->get('/candidatos')->assertOk()->assertSee('Documentos y plazos');
        $this->actingAs($this->rh)->get("/candidatos/{$c->id}")->assertOk()->assertSee('Documentos: 0 de 8 obligatorios')->assertSee('Acta de nacimiento');
        $this->actingAs($this->rh)->get('/candidatos/documentos-para-contratar')->assertOk()->assertSee('lista estándar')->assertSee('Cartas de recomendación');
        $this->actingAs($this->agente)->get('/candidatos/documentos-para-contratar')->assertForbidden();

        // Lista propia: dos obligatorios y uno opcional; los renglones vacíos no cuentan; cv/otro no se admiten
        $lista = fn (array $filas) => $this->actingAs($this->rh)->put('/candidatos/documentos-para-contratar', ['documentos' => $filas]);
        $lista([['nombre' => 'Currículum (CV)', 'clave' => 'cv']])->assertSessionHasErrors('documentos');
        $lista([['nombre' => 'INE', 'clave' => 'ine'], ['nombre' => 'INE', 'clave' => 'ine']])->assertSessionHasErrors('documentos');
        $lista([['nombre' => '', 'clave' => '']])->assertSessionHasErrors('documentos');
        $lista([['nombre' => 'Identificación oficial', 'clave' => 'ine', 'obligatorio' => '1'], ['nombre' => 'Carta de antecedentes', 'obligatorio' => '1'],
            ['nombre' => 'Cartilla militar', 'obligatorio' => '0'], ['nombre' => '', 'clave' => '']])->assertSessionHas('ok');
        $filas = $this->enEmpresa(fn () => DocumentoParaContratar::orderBy('orden')->get());
        $this->assertSame(['ine', 'carta_de_antecedentes', 'cartilla_militar'], $filas->pluck('clave')->all());
        $this->assertSame([true, true, false], $filas->pluck('obligatorio')->all());
        $this->assertTrue(Auditoria::where('evento', 'candidatos.documentos_configurados')->exists());
        $this->actingAs($this->rh)->get("/candidatos/{$c->id}")->assertSee('Documentos: 0 de 2 obligatorios')->assertSee('Cartilla militar')->assertDontSee('Acta de nacimiento');
        $this->actingAs($this->agente)->put('/candidatos/documentos-para-contratar', ['documentos' => [['nombre' => 'X']]])->assertForbidden();

        // Quitar un renglón lo deja inactivo (los documentos ya subidos conservan su nombre)
        $lista([['nombre' => 'Identificación oficial', 'clave' => 'ine', 'obligatorio' => '1']])->assertSessionHas('ok');
        $this->assertFalse($this->enEmpresa(fn () => DocumentoParaContratar::where('clave', 'cartilla_militar')->value('activo')));
        $this->actingAs($this->rh)->get("/candidatos/{$c->id}")->assertSee('Documentos: 0 de 1 obligatorios');

        // Plazos: límites y «borrar» después de «archivar»
        $this->actingAs($this->rh)->put('/candidatos/plazos', $this->plazos(['solicitud_dias' => '0']))->assertSessionHasErrors('solicitud_dias');
        $this->actingAs($this->rh)->put('/candidatos/plazos', $this->plazos(['archivar_meses' => '12', 'borrar_meses' => '12']))->assertSessionHasErrors('borrar_meses');
        $this->actingAs($this->rh)->put('/candidatos/plazos', $this->plazos(['solicitud_dias' => '3', 'documentos_dias' => '10']))->assertSessionHas('ok');
        $plazos = app(PlazosCandidatos::class)->plazos($this->empresa->fresh());
        $this->assertSame([3, 10, 6, 12], [$plazos['solicitud_dias'], $plazos['documentos_dias'], $plazos['archivar_meses'], $plazos['borrar_meses']]);
        $this->assertTrue(Auditoria::where('evento', 'candidatos.plazos_configurados')->exists());
        $this->actingAs($this->agente)->put('/candidatos/plazos', $this->plazos())->assertForbidden();

        // Otra empresa no ve esta lista
        $otra = $this->crearEmpresa('Otro Hotel');
        $rhOtra = $this->crearUsuario($otra, 'Recursos Humanos');
        $this->actingAs($rhOtra)->get('/candidatos/documentos-para-contratar')->assertOk()->assertSee('lista estándar')->assertDontSee('Identificación oficial<');
    }

    // ------------------------------------------------------------------ Documentos y contrato

    public function test_checklist_recibido_en_papel_y_documentos_por_tipo(): void
    {
        $c = $this->candidato();

        // Subir un documento de la lista conserva su tipo (antes todo lo que no era cv/ine/comprobante quedaba como «otro»)
        $this->actingAs($this->rh)->post("/candidatos/{$c->id}/documentos", ['tipo' => 'curp', 'documento' => $this->pdf('curp.pdf')])
            ->assertSessionHas('ok', fn ($m) => str_contains($m, 'Documentos: 1 de 8 obligatorios') && str_contains($m, 'falta: '));
        $this->assertSame('curp', $this->enEmpresa(fn () => CandidatoDocumento::where('candidato_id', $c->id)->value('tipo')));
        // El contrato no se sube por «Agregar documento»; un tipo inventado tampoco
        $this->actingAs($this->rh)->post("/candidatos/{$c->id}/documentos", ['tipo' => 'contrato', 'documento' => $this->pdf()])->assertSessionHasErrors('tipo');
        $this->actingAs($this->rh)->post("/candidatos/{$c->id}/documentos", ['tipo' => 'pasaporte', 'documento' => $this->pdf()])->assertSessionHasErrors('tipo');

        // Recibido en papel: queda quién y cuándo, sin archivo; dos veces no; fecha futura no; tipo fuera de la lista no
        $this->actingAs($this->rh)->post("/candidatos/{$c->id}/documentos/papel", ['tipo' => 'ine', 'fecha' => now()->subDay()->toDateString()])
            ->assertSessionHas('ok', fn ($m) => str_contains($m, 'Documentos: 2 de 8 obligatorios'));
        $papel = $this->enEmpresa(fn () => CandidatoDocumento::where('candidato_id', $c->id)->where('tipo', 'ine')->sole());
        $this->assertTrue($papel->enPapel());
        $this->assertNull($papel->ruta);
        $this->assertSame($this->rh->id, $papel->creado_por);
        $this->assertSame(now()->subDay()->toDateString(), $papel->recibido_en->toDateString());
        $this->actingAs($this->rh)->post("/candidatos/{$c->id}/documentos/papel", ['tipo' => 'ine'])->assertSessionHasErrors('tipo');
        $this->actingAs($this->rh)->post("/candidatos/{$c->id}/documentos/papel", ['tipo' => 'nss', 'fecha' => now()->addDays(2)->toDateString()])->assertSessionHasErrors('fecha');
        $this->actingAs($this->rh)->post("/candidatos/{$c->id}/documentos/papel", ['tipo' => 'cv'])->assertSessionHasErrors('tipo');
        $this->actingAs($this->agente)->post("/candidatos/{$c->id}/documentos/papel", ['tipo' => 'nss'])->assertForbidden();
        $this->assertTrue(Auditoria::where('evento', 'candidatos.documento_papel')->exists());

        // La ficha muestra el checklist con lo recibido y lo que falta; el de papel no se abre como archivo
        $ficha = $this->actingAs($this->rh)->get("/candidatos/{$c->id}")->assertOk();
        $ficha->assertSee('Documentos: 2 de 8 obligatorios')->assertSee('en papel el '.now()->subDay()->format('d/m/Y'))->assertSee($this->rh->name)
            ->assertSee('Recibido en papel')->assertSee('Contrato: pendiente de firma');
        $this->actingAs($this->rh)->get("/candidatos/{$c->id}/documentos/{$papel->id}")->assertNotFound();
        $this->assertSame('Documento recibido en papel', app(LectorAuditoria::class)->accion('candidatos.documento_papel'));

        // Eliminar el de papel vuelve a dejarlo pendiente
        $this->actingAs($this->rh)->delete("/candidatos/{$c->id}/documentos/{$papel->id}")->assertSessionHas('ok');
        $this->actingAs($this->rh)->get("/candidatos/{$c->id}")->assertSee('Documentos: 1 de 8 obligatorios');
    }

    public function test_contrato_firmado_y_contratar_avisa_lo_que_falta_o_se_fuerza_con_motivo(): void
    {
        $c = $this->candidato();
        $contratar = fn (array $extra = []) => $this->actingAs($this->rh)->post("/candidatos/{$c->id}/contratar", $extra + ['num_empleado' => '7001', 'nombre' => 'Luis', 'apellido_paterno' => 'Chi', 'apellido_materno' => 'Canul', '_dialogo' => 'contratar']);

        // Sin expediente: no se contrata; forzar sin motivo tampoco
        $contratar()->assertSessionHasErrors('forzar');
        $contratar(['forzar' => '1'])->assertSessionHasErrors('motivo_forzar');
        $this->assertSame('elegido', $c->fresh()->etapa);
        $this->actingAs($this->rh)->get("/candidatos/{$c->id}")->assertOk()->assertSee('Todavía falta en su expediente')->assertSee('Contratar de todos modos')->assertSee('Contrato firmado</button>', false);

        // Contrato firmado: solo desde Elegido, fecha no futura, archivo opcional que queda privado con tipo contrato
        $this->actingAs($this->rh)->post("/candidatos/{$c->id}/contrato", ['fecha' => now()->addDay()->toDateString(), '_dialogo' => 'contrato'])->assertSessionHasErrors('fecha');
        $this->actingAs($this->rh)->post("/candidatos/{$c->id}/contrato", ['fecha' => now()->toDateString(), 'archivo' => $this->pdf('contrato.pdf'), '_dialogo' => 'contrato'])
            ->assertSessionHas('ok', fn ($m) => str_contains($m, 'Contrato firmado registrado') && str_contains($m, 'Todavía falta'));
        $p = $this->postulacion($c);
        $this->assertNotNull($p->contrato_firmado_en);
        $this->assertSame($this->rh->id, $p->contrato_recibido_por);
        $doc = $this->enEmpresa(fn () => CandidatoDocumento::find($p->contrato_documento_id));
        $this->assertSame(CandidatoDocumento::TIPO_CONTRATO, $doc->tipo);
        Storage::disk('local')->assertExists($doc->ruta);
        $this->actingAs($this->rh)->get("/candidatos/{$c->id}")->assertSee('Contrato firmado</strong> el '.now()->format('d/m/Y'), false)->assertDontSee('Contrato firmado</button>', false);
        $this->assertTrue(Auditoria::where('evento', 'candidatos.contrato_firmado')->exists());
        $this->actingAs($this->agente)->post("/candidatos/{$c->id}/contrato", [])->assertForbidden();

        // Forzar con motivo: contrata, queda en auditoría y en el historial, y los documentos pasan al expediente del colaborador
        $contratar(['forzar' => '1', 'motivo_forzar' => 'Trae el acta de nacimiento el lunes'])->assertSessionHas('ok');
        $c->refresh();
        $this->assertSame('contratado', $c->etapa);
        $auditoria = Auditoria::where('evento', 'candidatos.contratado')->latest('id')->first();
        $this->assertTrue($auditoria->despues['forzado']);
        $this->assertSame('Trae el acta de nacimiento el lunes', $auditoria->despues['motivo']);
        $this->assertContains('Falta: Acta de nacimiento', $auditoria->despues['faltantes']);
        $this->assertTrue(DB::table('candidato_eventos')->where('candidato_id', $c->id)->where('comentario', 'like', '%Motivo: Trae el acta%')->exists());
        $this->assertSame($c->colaborador_id, $doc->fresh()->colaborador_id);
        $this->actingAs($this->rh)->get("/candidatos/{$c->id}")->assertSee('expediente del colaborador');

        // Con todo entregado y contrato firmado no hace falta forzar
        $otro = $this->candidato('elegido', ['nombre_completo' => 'Ana Pool May']);
        $this->todoEnPapel($otro);
        $this->actingAs($this->rh)->post("/candidatos/{$otro->id}/contrato", ['_dialogo' => 'contrato'])->assertSessionHas('ok', fn ($m) => str_contains($m, 'ya puedes Contratar'));
        $this->actingAs($this->rh)->get("/candidatos/{$otro->id}")->assertSee('Expediente completo y contrato firmado');
        $this->actingAs($this->rh)->post("/candidatos/{$otro->id}/contratar", ['num_empleado' => '7002', 'nombre' => 'Ana', 'apellido_paterno' => 'Pool'])->assertSessionHas('ok');
        $this->assertFalse(Auditoria::where('evento', 'candidatos.contratado')->latest('id')->first()->despues['forzado'] ?? false);
        // El contrato de un contratado no se registra dos veces (ya no está Elegido)
        $this->actingAs($this->rh)->post("/candidatos/{$otro->id}/contrato", [])->assertSessionHas('error');
    }

    // ------------------------------------------------------------------ Plazos

    public function test_los_plazos_recuerdan_una_sola_vez_y_colorean_mis_pendientes(): void
    {
        Mail::fake();
        $this->configurarCorreo();
        // Solicitud sin revisar hace 3 días (plazo 1: roja), elegido hace 6 días (plazo 5: ámbar), cita vencida hace 2 días sin registro en caseta
        $solicitud = $this->candidato('con_rh', ['nombre_completo' => 'Karla Pérez Uc']);
        $this->enEmpresa(fn () => Candidato::whereKey($solicitud->id)->update(['autocaptura_pendiente' => true, 'autocaptura_en' => now()->subDays(3)]));
        $elegido = $this->candidato('elegido', ['nombre_completo' => 'Mariela Canché Dzib']);
        $this->enEmpresa(fn () => Postulacion::where('candidato_id', $elegido->id)->update(['elegido_en' => now()->subDays(6)]));
        $cita = $this->candidato('canalizado', ['nombre_completo' => 'Jorge Tun Pech']);
        $this->enEmpresa(fn () => Postulacion::where('candidato_id', $cita->id)->update(['entrevistador_id' => $this->jefa->id, 'cita_ahora' => false, 'cita_en' => now()->subDays(2)]));
        // Un elegido de hoy no se recuerda
        $this->candidato('elegido', ['nombre_completo' => 'Reciente Sin Plazo']);

        $this->artisan('plataforma:candidatos-pendientes')->assertSuccessful()->expectsOutputToContain('Recordatorios: 2. No se presentó propuestos: 1.');

        // Cada aviso llega a quien edita candidatos en esa sede (RR. HH. y el administrador), una vez a cada uno
        $avisos = $this->enEmpresa(fn () => Notificacion::where('user_id', $this->rh->id)->where('tipo', 'plazo_candidato')->get());
        $this->assertSame(3, $avisos->count());
        $this->assertSame(3, $this->enEmpresa(fn () => Notificacion::where('user_id', $this->admin->id)->where('tipo', 'plazo_candidato')->count()));
        $this->assertTrue($avisos->contains(fn ($n) => str_starts_with($n->titulo, 'Solicitud sin revisar: Karla Pérez Uc lleva 3 días')));
        $this->assertTrue($avisos->contains(fn ($n) => str_starts_with($n->titulo, 'Elegido sin contratar: Mariela Canché Dzib lleva 6 días') && str_contains($n->texto, 'El contrato no está firmado')));
        $propuesta = $avisos->first(fn ($n) => str_starts_with($n->titulo, '¿No se presentó? Jorge Tun Pech'));
        $this->assertSame('Marcar como no se presentó', $propuesta->acciones[0]['etiqueta']);
        $this->assertSame('canalizado', $cita->fresh()->etapa, 'No se marca solo: se propone');
        // El entrevistador recibe el recordatorio de su evaluación (2 días hábiles desde la cita)
        $this->enEmpresa(fn () => Postulacion::where('candidato_id', $cita->id)->update(['cita_en' => now()->subDays(5)]));
        $this->artisan('plataforma:candidatos-pendientes')->assertSuccessful();
        $this->assertTrue($this->enEmpresa(fn () => Notificacion::where('user_id', $this->jefa->id)->where('tipo', 'plazo_candidato')->where('titulo', 'like', 'Falta tu evaluación: Jorge Tun Pech%')->exists()));
        defer()->invoke(); // En la consola real los correos diferidos salen al terminar el comando
        Mail::assertSent(AvisoRecepcion::class, fn ($m) => $m->hasTo($this->rh->email));
        $this->assertSame(4, Auditoria::where('evento', 'candidatos.plazo')->count());

        // Una sola vez por día: volver a correr hoy no repite nada
        $this->artisan('plataforma:candidatos-pendientes --si-toca')->assertSuccessful()->doesntExpectOutputToContain('Recordatorios');
        $this->assertSame(3, $this->enEmpresa(fn () => Notificacion::where('user_id', $this->rh->id)->where('tipo', 'plazo_candidato')->count()));
        // Mañana se recuerda otra vez lo que siga pendiente, y el aviso de ayer queda leído (en la campana solo vive el de hoy)
        $this->travel(25)->hours();
        $this->artisan('plataforma:candidatos-pendientes')->assertSuccessful()->expectsOutputToContain('Recordatorios: 3. No se presentó propuestos: 1.');
        $hoy = $this->enEmpresa(fn () => Notificacion::where('user_id', $this->rh->id)->where('tipo', 'plazo_candidato')->get());
        $this->assertSame(6, $hoy->count());
        $this->assertSame(3, $hoy->whereNull('leida_en')->count());
        $this->assertTrue($hoy->whereNull('leida_en')->contains(fn ($n) => str_starts_with($n->titulo, 'Solicitud sin revisar: Karla Pérez Uc lleva 4 días')));
        $this->assertSame(1, $hoy->whereNull('leida_en')->filter(fn ($n) => str_starts_with($n->titulo, '¿No se presentó?') && $n->acciones !== null)->count());
        $this->travelBack();

        // Mis pendientes: color según el plazo (roja al doble, ámbar al pasarlo)
        Cache::flush();
        app()->forgetInstance(MisPendientes::class);
        $items = collect(app(MisPendientes::class)->para($this->rh)['items'])->keyBy('clave');
        $this->assertSame('roja', $items['solicitudes']['nivel']);
        $this->assertSame('ambar', $items['elegidos']['nivel']);
        $this->assertNull($items['evaluaciones_departamento']['nivel']);
        $this->actingAs($this->rh)->get('/candidatos')->assertOk()->assertSee('mis-pendientes-item nivel-roja');
        // La ficha muestra el reloj con su color
        $this->actingAs($this->rh)->get("/candidatos/{$elegido->id}")->assertSee('reloj-etapa nivel-ambar')->assertSee('Lleva 6 días en esta etapa');

        // Plazos propios de la empresa: con 10 días para documentos, el elegido de 6 ya no está fuera de plazo
        $this->actingAs($this->rh)->put('/candidatos/plazos', $this->plazos(['documentos_dias' => '10']))->assertSessionHas('ok');
        Cache::flush();
        app()->forgetInstance(MisPendientes::class);
        $this->assertNull(collect(app(MisPendientes::class)->para($this->rh)['items'])->firstWhere('clave', 'elegidos')['nivel']);
    }

    public function test_el_plazo_de_rr_hh_usa_las_etapas_por_atender_y_con_rr_hh(): void
    {
        // ADR-0010 + fase 3: «Por atender» (registrado) y «Con RR. HH.» (con_rh) comparten el plazo por_atender_dias (2 días)
        $porAtender = $this->candidato('registrado', ['nombre_completo' => 'Ana Por Atender']);
        $conRh = $this->candidato('con_rh', ['nombre_completo' => 'Beto Con Rh']);
        $reciente = $this->candidato('con_rh', ['nombre_completo' => 'Carla Reciente']);
        $this->enEmpresa(fn () => Postulacion::whereIn('candidato_id', [$porAtender->id, $conRh->id])->update(['updated_at' => now()->subDays(3)]));

        $this->artisan('plataforma:candidatos-pendientes')->assertSuccessful();
        $avisos = $this->enEmpresa(fn () => Notificacion::where('user_id', $this->rh->id)->where('tipo', 'plazo_candidato')->pluck('titulo'));
        $this->assertTrue($avisos->contains(fn ($t) => str_starts_with($t, 'Sin atender: Ana Por Atender lleva 3 días en «Por atender»')));
        $this->assertTrue($avisos->contains(fn ($t) => str_starts_with($t, 'Sin canalizar: Beto Con Rh lleva 3 días en «Con RR. HH.»')));
        $this->assertFalse($avisos->contains(fn ($t) => str_contains($t, 'Carla Reciente')));

        // Mis pendientes: «Esperando en Recepción» y «Por entrevistar (RR. HH.)» en ámbar (pasaron el plazo, sin llegar al doble)
        Cache::flush();
        app()->forgetInstance(MisPendientes::class);
        $items = collect(app(MisPendientes::class)->para($this->rh)['items'])->keyBy('clave');
        $this->assertSame('ambar', $items['por_entrevistar']['nivel']);
        // La ficha muestra el plazo de RR. HH.
        $this->actingAs($this->rh)->get("/candidatos/{$conRh->id}")->assertOk()->assertSee('Con RR. HH.')->assertSee('Plazo de con Recursos Humanos', false);
    }

    public function test_abrir_candidatos_corre_los_recordatorios_si_toca_con_candado(): void
    {
        $c = $this->candidato('elegido');
        $this->enEmpresa(fn () => Postulacion::where('candidato_id', $c->id)->update(['elegido_en' => now()->subDays(8)]));
        $avisos = fn () => $this->enEmpresa(fn () => Notificacion::where('user_id', $this->rh->id)->where('tipo', 'plazo_candidato')->count());
        $this->actingAs($this->rh)->get('/candidatos')->assertOk();
        $this->assertSame(1, $avisos());
        // Otro plazo vencido dentro del minuto espera al candado (a lo mucho una corrida por minuto y por empresa)
        $otro = $this->candidato('elegido', ['nombre_completo' => 'Otro Elegido']);
        $this->enEmpresa(fn () => Postulacion::where('candidato_id', $otro->id)->update(['elegido_en' => now()->subDays(8)]));
        $this->actingAs($this->rh)->get('/candidatos')->assertOk();
        $this->assertSame(1, $avisos());
        Cache::flush();
        app()->forgetInstance(MisPendientes::class);
        // También en la consulta periódica de la campana y Mis pendientes (el pintado de cada pantalla no la dispara)
        $this->actingAs($this->rh)->get('/')->assertOk();
        $this->assertSame(1, $avisos());
        $this->actingAs($this->rh)->getJson('/notificaciones/resumen')->assertOk();
        $this->assertSame(2, $avisos());
        // El aviso por correo se puede apagar en Configuración
        $this->assertArrayHasKey('plazo_candidato', Empresa::AVISOS);
    }

    // ------------------------------------------------------------------ Caducidad

    public function test_la_caducidad_archiva_avisa_conserva_y_borra(): void
    {
        Mail::fake();
        $this->configurarCorreo();
        // Considerar hace 7 meses → se archiva; rechazado hace 2 meses → sigue
        $vieja = $this->candidato('considerar', ['nombre_completo' => 'Silvia Mena Couoh']);
        $this->enEmpresa(fn () => Postulacion::where('candidato_id', $vieja->id)->update(['decision_en' => now()->subMonths(7)]));
        $reciente = $this->candidato('rechazado', ['nombre_completo' => 'Pedro Uicab Noh']);
        $this->enEmpresa(fn () => Postulacion::where('candidato_id', $reciente->id)->update(['decision_en' => now()->subMonths(2)]));
        // Ficha sin movimiento desde hace 12 meses → aviso 15 días antes de borrarla
        $olvidada = $this->candidato('rechazado', ['nombre_completo' => 'Hugo Balam Poot']);
        $this->actingAs($this->rh)->post("/candidatos/{$olvidada->id}/documentos", ['tipo' => 'cv', 'documento' => $this->pdf('cv.pdf')])->assertSessionHas('ok');
        $hace = now()->subMonths(12)->subDay();
        $this->enEmpresa(function () use ($olvidada, $hace) {
            Candidato::whereKey($olvidada->id)->update(['updated_at' => $hace]);
            Postulacion::where('candidato_id', $olvidada->id)->update(['updated_at' => $hace, 'decision_en' => $hace]);
            CandidatoDocumento::where('candidato_id', $olvidada->id)->update(['created_at' => $hace]);
        });
        // Un contratado viejo nunca se borra
        $contratado = $this->candidato('elegido', ['nombre_completo' => 'Ramón Ek Balam']);
        $this->todoEnPapel($contratado);
        $this->actingAs($this->rh)->post("/candidatos/{$contratado->id}/contrato", [])->assertSessionHas('ok');
        $this->actingAs($this->rh)->post("/candidatos/{$contratado->id}/contratar", ['num_empleado' => '7003', 'nombre' => 'Ramón', 'apellido_paterno' => 'Ek'])->assertSessionHas('ok');
        $this->enEmpresa(fn () => Candidato::whereKey($contratado->id)->update(['updated_at' => $hace]) && Postulacion::where('candidato_id', $contratado->id)->update(['updated_at' => $hace]));

        $this->artisan('plataforma:candidatos-pendientes')->assertSuccessful()->expectsOutputToContain('Postulaciones archivadas: 2. Avisos de borrado: 1. Fichas borradas: 0.');
        defer()->invoke();

        // Archivadas: salen de la lista y sus contadores; el filtro «Archivadas» las muestra; la ficha lo dice
        $this->assertNotNull($this->postulacion($vieja)->archivada_en);
        $this->assertNotNull($vieja->fresh()->archivada_en, 'Espejo en la ficha');
        $this->assertNull($this->postulacion($reciente)->archivada_en);
        $this->actingAs($this->rh)->get('/candidatos')->assertOk()->assertDontSee('Silvia Mena Couoh')->assertSee('Pedro Uicab Noh')->assertSee('Archivadas (2)')->assertSee('Por caducar (1)');
        $this->actingAs($this->rh)->get('/candidatos?archivadas=1')->assertOk()->assertSee('Silvia Mena Couoh')->assertDontSee('Pedro Uicab Noh');
        $this->actingAs($this->rh)->get("/candidatos/{$vieja->id}")->assertOk()->assertSee('Postulación archivada');
        $this->assertTrue(Auditoria::where('evento', 'candidatos.archivada')->exists());

        // Aviso de borrado: fecha a 15 días, campana con «Conservar 6 meses más», lista «Por caducar»
        $olvidada->refresh();
        $this->assertSame(now()->addDays(CaducidadCandidatos::DIAS_AVISO)->toDateString(), $olvidada->borrar_en->toDateString());
        $aviso = $this->enEmpresa(fn () => Notificacion::where('user_id', $this->rh->id)->where('tipo', 'caducidad_candidato')->sole());
        $this->assertSame('Conservar 6 meses más', $aviso->acciones[0]['etiqueta']);
        Mail::assertSent(AvisoRecepcion::class, fn ($m) => $m->hasTo($this->rh->email));
        $this->actingAs($this->rh)->get('/candidatos?caducar=1')->assertOk()->assertSee('Hugo Balam Poot')->assertSee('Se borra el '.$olvidada->borrar_en->format('d/m/Y'))->assertDontSee('Pedro Uicab Noh');
        $this->actingAs($this->rh)->get("/candidatos/{$olvidada->id}")->assertOk()->assertSee('Esta ficha se borrará el')->assertSee('Conservar 6 meses más');
        $this->assertNull($contratado->fresh()->borrar_en, 'El contratado no caduca');

        // Conservar: corre la fecha 6 meses, resuelve el aviso y queda en la bitácora; el agente no puede
        $this->actingAs($this->agente)->post("/candidatos/{$olvidada->id}/conservar")->assertForbidden();
        $this->actingAs($this->rh)->post("/candidatos/{$olvidada->id}/conservar")->assertSessionHas('ok', fn ($m) => str_contains($m, 'se conservará hasta el'));
        $olvidada->refresh();
        $this->assertNull($olvidada->borrar_en);
        $this->assertSame(now()->addDays(CaducidadCandidatos::DIAS_AVISO)->addMonths(6)->toDateString(), $olvidada->conservar_hasta->toDateString());
        $this->assertNotNull($aviso->fresh()->leida_en);
        $this->assertTrue(Auditoria::where('evento', 'candidatos.conservada')->where('user_id', $this->rh->id)->exists());
        $this->actingAs($this->rh)->post("/candidatos/{$contratado->id}/conservar")->assertSessionHas('error');
        $this->artisan('plataforma:candidatos-pendientes')->assertSuccessful()->expectsOutputToContain('Avisos de borrado: 0. Fichas borradas: 0.');

        // Cuando llega el día (aviso de hace 16 días y fecha ayer) se borra la ficha con sus documentos, auditado sin datos personales
        $ruta = $this->enEmpresa(fn () => CandidatoDocumento::where('candidato_id', $olvidada->id)->value('ruta'));
        $this->enEmpresa(fn () => Candidato::whereKey($olvidada->id)->update(['conservar_hasta' => null, 'aviso_borrado_en' => now()->subDays(16), 'borrar_en' => now()->subDay()->toDateString()]));
        $this->artisan('plataforma:candidatos-pendientes')->assertSuccessful()->expectsOutputToContain('Fichas borradas: 1.');
        $this->assertNull($this->enEmpresa(fn () => Candidato::find($olvidada->id)));
        $this->assertSame(0, $this->enEmpresa(fn () => CandidatoDocumento::where('candidato_id', $olvidada->id)->count()));
        Storage::disk('local')->assertMissing($ruta);
        $borrado = Auditoria::where('evento', 'candidatos.borrado_por_caducidad')->sole();
        $this->assertNull($borrado->user_id);
        $this->assertSame(1, $borrado->antes['documentos']);
        $this->assertStringNotContainsString('Hugo', json_encode($borrado->antes));
        $this->assertNotNull($this->enEmpresa(fn () => Candidato::find($contratado->id)));
        $this->assertSame('Borrado por caducidad', app(LectorAuditoria::class)->accion('candidatos.borrado_por_caducidad'));
    }

    // ------------------------------------------------------------------ Expediente

    public function test_el_expediente_del_colaborador_reune_lo_que_cada_usuario_puede_ver(): void
    {
        // A mediodía local: la fecha en hora universal y la local coinciden (la prueba no falla cerca de la medianoche)
        $this->travelTo(now(HoraLocal::ZONA_PLATAFORMA)->setTime(12, 0)->utc());
        $c = $this->candidato('elegido', ['nombre_completo' => 'Luis Chi Canul']);
        $this->todoEnPapel($c);
        $this->actingAs($this->rh)->post("/candidatos/{$c->id}/documentos", ['tipo' => 'ine', 'documento' => $this->pdf('ine.pdf')])->assertSessionHas('ok');
        $this->actingAs($this->rh)->post("/candidatos/{$c->id}/contrato", ['archivo' => $this->pdf('contrato.pdf')])->assertSessionHas('ok');
        $this->actingAs($this->rh)->post("/candidatos/{$c->id}/contratar", ['num_empleado' => '7010', 'nombre' => 'Luis', 'apellido_paterno' => 'Chi', 'apellido_materno' => 'Canul'])->assertSessionHas('ok');
        $colaborador = $this->enEmpresa(fn () => Colaborador::where('num_empleado', '7010')->sole());

        // Botón en la lista de Colaboradores y en la ficha del candidato
        $this->actingAs($this->rh)->get('/colaboradores')->assertOk()->assertSee("/colaboradores/{$colaborador->id}/expediente");
        $this->actingAs($this->rh)->get("/candidatos/{$c->id}")->assertSee("/colaboradores/{$colaborador->id}/expediente");

        // RR. HH.: datos, documentos (archivo y papel), su paso por Candidatos; sin accesos porque no ve ese módulo
        $expediente = $this->actingAs($this->rh)->get("/colaboradores/{$colaborador->id}/expediente")->assertOk();
        $expediente->assertSee('<h1>Luis Chi Canul</h1>', false)->assertSee('id="pestana-expediente"', false)->assertSee('Documentos de contratación')->assertSee('Contrato firmado')->assertSee('Recibido en papel el')
            ->assertSee('Su paso por Candidatos')->assertSee('contratado el '.now()->format('d/m/Y'))->assertSee('su ficha de candidato')->assertDontSee('Accesos recientes');
        $ine = $this->enEmpresa(fn () => CandidatoDocumento::where('colaborador_id', $colaborador->id)->where('tipo', 'ine')->whereNotNull('ruta')->sole());
        $this->actingAs($this->rh)->get("/colaboradores/{$colaborador->id}/expediente/{$ine->id}")->assertOk()->assertDownload('ine.pdf');
        $papel = $this->enEmpresa(fn () => CandidatoDocumento::where('colaborador_id', $colaborador->id)->where('tipo', 'curp')->sole());
        $this->actingAs($this->rh)->get("/colaboradores/{$colaborador->id}/expediente/{$papel->id}")->assertNotFound();

        // El administrador ve además los resúmenes operativos; el agente (consulta de Colaboradores) no ve los documentos ni la ficha de candidato
        $this->actingAs($this->admin)->get("/colaboradores/{$colaborador->id}/expediente")->assertOk()->assertSee('Accesos recientes')->assertSee('Llaves en uso')->assertSee('Pases de salida')->assertSee('Resguardos en campo');
        // Sin el permiso de datos personales la pestaña Expediente no aparece
        $this->actingAs($this->agente)->get("/colaboradores/{$colaborador->id}/expediente")->assertOk()->assertSee('id="pestana-datos"', false)
            ->assertDontSee('id="pestana-expediente"', false)->assertDontSee('Documentos de contratación')->assertDontSee('Recibido en papel el')->assertDontSee('Su paso por Candidatos');
        $this->actingAs($this->agente)->get("/colaboradores/{$colaborador->id}/expediente/{$ine->id}")->assertForbidden();

        // Otra sede (alcance) y otra empresa: 404 sin revelar que existe
        $agentePlaya = $this->crearUsuario($this->empresa, 'Agente', $this->playa);
        $this->actingAs($agentePlaya)->get("/colaboradores/{$colaborador->id}/expediente")->assertNotFound();
        $otra = $this->crearEmpresa('Otro Hotel');
        $this->actingAs($this->crearUsuario($otra, 'Administrador'))->get("/colaboradores/{$colaborador->id}/expediente")->assertNotFound();
        // Un colaborador que no llegó por Candidatos también tiene expediente
        $directo = $this->enEmpresa(fn () => Colaborador::create(['sede_id' => $this->centro->id, 'num_empleado' => '7011', 'nombre' => 'Ana', 'apellido_paterno' => 'Pool']));
        $this->actingAs($this->rh)->get("/colaboradores/{$directo->id}/expediente")->assertOk()->assertSee('Sin documentos en su expediente')->assertDontSee('Su paso por Candidatos');
    }

    // ------------------------------------------------------------------ Migración y demo

    public function test_la_migracion_se_revierte_y_vuelve_a_aplicar(): void
    {
        $c = $this->candidato('elegido');
        $this->todoEnPapel($c);
        $this->actingAs($this->rh)->post("/candidatos/{$c->id}/documentos", ['tipo' => 'certificado_estudios', 'documento' => $this->pdf()])->assertSessionHas('ok');
        $migracion = require database_path('migrations/2026_10_22_000300_candidatos_fase_3_documentos_plazos_y_caducidad.php');

        $migracion->down();
        $this->assertFalse(Schema::hasTable('documentos_para_contratar'));
        foreach (['colaborador_id', 'recibido_en'] as $columna) {
            $this->assertFalse(Schema::hasColumn('candidato_documentos', $columna), $columna);
        }
        foreach (['contrato_firmado_en', 'contrato_recibido_por', 'contrato_documento_id', 'aviso_documentos_en', 'archivada_en'] as $columna) {
            $this->assertFalse(Schema::hasColumn('postulaciones', $columna), $columna);
        }
        foreach (['archivada_en', 'borrar_en', 'conservar_hasta', 'aviso_borrado_en', 'aviso_solicitud_en'] as $columna) {
            $this->assertFalse(Schema::hasColumn('candidatos', $columna), $columna);
        }
        // Los «en papel» no caben en la versión anterior (sin archivo); las claves de la lista estándar caben en 20 caracteres
        $this->assertSame(['certificado_estudios'], DB::table('candidato_documentos')->where('candidato_id', $c->id)->pluck('tipo')->all());

        $migracion->up();
        $migracion->up();
        $this->assertTrue(Schema::hasTable('documentos_para_contratar'));
        $this->assertTrue(Schema::hasColumn('candidato_documentos', 'recibido_en'));
        $this->assertTrue(Schema::hasColumn('postulaciones', 'contrato_documento_id'));
        $this->assertTrue(Schema::hasColumn('candidatos', 'conservar_hasta'));
        $this->actingAs($this->rh)->get("/candidatos/{$c->id}")->assertOk()->assertSee('Documentos: 1 de 8 obligatorios');
    }

    public function test_el_demo_trae_un_elegido_con_documentos_a_medias_y_uno_por_caducar(): void
    {
        $this->artisan('plataforma:demo', ['--password' => 'Demo1234!'])->assertSuccessful()->doesntExpectOutputToContain('No se pudo completar');
        $demo = Empresa::where('nombre_comercial', CrearDatosDemo::EMPRESA)->firstOrFail();
        $rh = User::where('username', 'rh.demo')->firstOrFail();
        [$mariela, $hugo, $ramon] = app(Tenant::class)->conEmpresa($demo->id, function () {
            $mariela = Candidato::where('nombre_completo', 'Mariela Canché Dzib')->sole();
            $this->assertSame('elegido', $mariela->etapa);
            $this->assertSame(['curp', 'cv', 'ine', 'nss'], CandidatoDocumento::where('candidato_id', $mariela->id)->orderBy('tipo')->pluck('tipo')->all());
            $hugo = Candidato::where('nombre_completo', 'Hugo Balam Poot')->sole();
            $this->assertNotNull($hugo->borrar_en);
            $this->assertNotNull($hugo->archivada_en);
            $ramon = Candidato::where('nombre_completo', 'Ramón Ek Balam')->sole();
            $this->assertNotNull(Postulacion::where('candidato_id', $ramon->id)->value('contrato_firmado_en'));
            $this->assertSame(8, CandidatoDocumento::where('colaborador_id', $ramon->colaborador_id)->count());

            return [$mariela, $hugo, $ramon];
        });
        $this->actingAs($rh)->get("/candidatos/{$mariela->id}")->assertOk()->assertSee('Documentos: 3 de 8 obligatorios')->assertSee('Contrato: pendiente de firma');
        $this->actingAs($rh)->get('/candidatos?caducar=1')->assertOk()->assertSee('Hugo Balam Poot');
        $this->actingAs($rh)->get("/colaboradores/{$ramon->colaborador_id}/expediente")->assertOk()->assertSee('contrato firmado el')->assertSee('Recibido en papel el');
        $this->actingAs($rh)->get('/candidatos/documentos-para-contratar')->assertOk();
    }
}
