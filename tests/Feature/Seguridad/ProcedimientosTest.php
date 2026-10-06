<?php

namespace Tests\Feature\Seguridad;

use App\Mail\AvisoProcedimiento;
use App\Models\Auditoria;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\FirmaUsuario;
use App\Models\Procedimiento;
use App\Models\ProcedimientoAcuse;
use App\Models\ProcedimientoAdjunto;
use App\Models\ProcedimientoCategoria;
use App\Models\ProcedimientoVersion;
use App\Models\Puesto;
use App\Models\Sede;
use App\Models\User;
use App\Services\Procedimientos\AdministradorProcedimientos;
use App\Support\CorreoPlataforma;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Procedimientos: alta y borrador, circuito Borrador → En revisión →
 * Publicado (con firma, sin aprobar lo propio ni dos veces, rechazo con
 * comentario), versiones (la anterior rige hasta aprobar la nueva), retiro,
 * acuse «Leí y entendí» y a quién aplica, avisos, adjuntos privados,
 * aislamiento por empresa y alcance por sede.
 */
class ProcedimientosTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private Sede $playa;

    private User $admin;

    private User $director;

    private User $jefe;

    private User $supervisor;

    private User $agente;

    private User $agentePlaya;

    private int $seguridad;

    private int $recepcion;

    private int $agenteSeguridad;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->playa = $this->crearSede($this->empresa, 'PLA');
        [$this->seguridad, $this->recepcion, $this->agenteSeguridad] = $this->enEmpresa(fn () => [
            Departamento::create(['nombre' => 'Seguridad'])->id,
            Departamento::create(['nombre' => 'Recepción'])->id,
            Puesto::create(['nombre' => 'Agente de Seguridad'])->id,
        ]);
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->director = $this->crearUsuario($this->empresa, 'Director');
        $this->jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad');
        $this->supervisor = $this->conColaborador($this->crearUsuario($this->empresa, 'Supervisor', $this->centro), $this->centro, $this->seguridad);
        $this->agente = $this->conColaborador($this->crearUsuario($this->empresa, 'Agente', $this->centro), $this->centro, $this->seguridad, $this->agenteSeguridad);
        $this->agentePlaya = $this->conColaborador($this->crearUsuario($this->empresa, 'Agente', $this->playa), $this->playa, $this->seguridad, $this->agenteSeguridad);
    }

    private function enEmpresa(callable $fn, ?Empresa $empresa = null): mixed
    {
        return app(Tenant::class)->conEmpresa(($empresa ?? $this->empresa)->id, $fn);
    }

    private function conColaborador(User $u, ?Sede $sede, ?int $depto = null, ?int $puesto = null): User
    {
        $c = $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => 'N'.$u->id, 'nombre' => $u->name, 'apellido_paterno' => 'Prueba',
            'sede_id' => $sede?->id, 'departamento_id' => $depto, 'puesto_id' => $puesto]));
        $u->forceFill(['colaborador_id' => $c->id])->save();

        return $u->refresh();
    }

    private function firmaJpeg(): string
    {
        $img = imagecreatetruecolor(300, 100);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imageline($img, 10, 50, 290, 60, imagecolorallocate($img, 0, 0, 0));
        ob_start();
        imagejpeg($img, null, 70);

        return 'data:image/jpeg;base64,'.base64_encode((string) ob_get_clean());
    }

    private function categoria(string $nombre = 'Emergencias'): int
    {
        return $this->enEmpresa(function () use ($nombre) {
            app(AdministradorProcedimientos::class)->categorias();

            return ProcedimientoCategoria::where('nombre', $nombre)->value('id');
        });
    }

    /** @return array<string, mixed> */
    private function datos(array $extra = []): array
    {
        return array_merge([
            'clave' => ' pro-seg-001 ', 'titulo' => 'Robo en habitación', 'categoria_id' => $this->categoria(),
            'objetivo' => 'Atender el reporte de robo.', 'aplica' => 'todas',
            'pasos' => [
                ['texto' => 'Escuchar al huésped', 'responsable' => 'Agente', 'critico' => '0'],
                ['texto' => '', 'responsable' => ''],
                ['texto' => 'No tocar nada', 'critico' => '1'],
            ],
        ], $extra);
    }

    private function crear(array $extra = [], ?User $actor = null): Procedimiento
    {
        $this->actingAs($actor ?? $this->jefe)->post('/procedimientos', $this->datos($extra))->assertSessionHasNoErrors();

        return $this->recargar($this->enEmpresa(fn () => Procedimiento::orderByDesc('id')->firstOrFail()));
    }

    private function recargar(Procedimiento $p): Procedimiento
    {
        return $this->enEmpresa(fn () => Procedimiento::with('vigente', 'trabajo')->findOrFail($p->id));
    }

    private function aprobar(Procedimiento $p, ?User $actor = null, array $extra = []): TestResponse
    {
        $p = $this->recargar($p);

        return $this->actingAs($actor ?? $this->admin)->post("/procedimientos/{$p->id}/aprobar", array_merge([
            'version_id' => $p->trabajo?->id, 'firma_modo' => 'nueva', 'firma' => $this->firmaJpeg(),
        ], $extra));
    }

    private function publicado(array $extra = []): Procedimiento
    {
        $p = $this->crear($extra + ['enviar' => '1']);
        $this->aprobar($p)->assertSessionHasNoErrors();

        return $this->recargar($p);
    }

    private function acusar(Procedimiento $p, User $u, array $extra = []): TestResponse
    {
        $p = $this->recargar($p);

        return $this->actingAs($u)->post("/procedimientos/{$p->id}/acuse", array_merge([
            'version_id' => $p->vigente?->id, 'entendido' => '1', 'firma_modo' => 'nueva', 'firma' => $this->firmaJpeg(),
        ], $extra));
    }

    private function configurarCorreo(): void
    {
        app(CorreoPlataforma::class)->guardar(['host' => 'mail.ejemplo.com', 'puerto' => 587, 'cifrado' => 'tls', 'usuario' => 'a@ejemplo.com',
            'remitente_correo' => 'avisos@ejemplo.com', 'remitente_nombre' => 'Avisos'], 'x');
    }

    // ================================================================ Altas

    public function test_alta_como_borrador_con_pasos_ordenados_y_auditoria(): void
    {
        $p = $this->crear();

        $this->assertSame('PRO-SEG-001', $p->clave);
        $this->assertSame(Procedimiento::BORRADOR, $p->estado);
        $this->assertSame(1, $p->version_trabajo);
        $this->assertNotEmpty($p->codigo_qr);
        $pasos = $this->enEmpresa(fn () => $p->trabajo->pasos);
        $this->assertSame(['Escuchar al huésped', 'No tocar nada'], $pasos->pluck('texto')->all());
        $this->assertSame([1, 2], $pasos->pluck('orden')->all());
        $this->assertSame([false, true], $pasos->pluck('critico')->all());
        $this->assertTrue(Auditoria::where('evento', 'procedimientos.creado')->where('auditable_id', $p->id)->exists());

        $this->get('/procedimientos')->assertOk()->assertSee('PRO-SEG-001')->assertSee('Robo en habitación')->assertSee('Borrador');
        $this->get("/procedimientos/{$p->id}")->assertOk()->assertSee('Editar borrador')->assertSee('Enviar a revisión')->assertSee('Creado por');
    }

    public function test_validacion_en_espanol_dentro_del_dialogo_y_clave_unica(): void
    {
        $this->actingAs($this->jefe)->from('/procedimientos')->post('/procedimientos', $this->datos(['clave' => '', 'titulo' => '', 'pasos' => [['texto' => '']]]))
            ->assertRedirect('/procedimientos')
            ->assertSessionHasErrors(['clave' => 'Escribe la clave del procedimiento (por ejemplo PRO-SEG-001).', 'titulo', 'pasos' => 'Agrega al menos un paso.'])
            ->assertSessionHasInput('_dialogo', 'nuevo');
        $this->get('/procedimientos?nuevo=1')->assertSee('id="dialogoNuevoProcedimiento"', false)->assertSee('data-abrir-al-cargar', false);

        $this->crear();
        $this->post('/procedimientos', $this->datos(['clave' => 'PRO-SEG-001']))->assertSessionHasErrors(['clave' => 'Ya existe un procedimiento con esa clave.']);
        $this->post('/procedimientos', $this->datos(['clave' => 'PRO SEG#1']))->assertSessionHasErrors('clave');
        $this->post('/procedimientos', $this->datos(['clave' => 'PRO-2', 'aplica' => 'sedes', 'sedes' => []]))->assertSessionHasErrors(['sedes' => 'Elige al menos una sede.']);

        // La misma clave sí se puede en otra empresa
        $otra = $this->crearEmpresa('Hotel Dos');
        $this->crearSede($otra, 'OT');
        $adminOtra = $this->crearUsuario($otra, 'Administrador');
        $cat = $this->enEmpresa(fn () => app(AdministradorProcedimientos::class)->categorias()->first()->id, $otra);
        $this->actingAs($adminOtra)->post('/procedimientos', $this->datos(['categoria_id' => $cat]))->assertSessionHasNoErrors();
    }

    public function test_editar_borrador_y_la_version_publicada_no_se_edita(): void
    {
        $p = $this->crear();
        $this->actingAs($this->jefe)->put("/procedimientos/{$p->id}", $this->datos(['titulo' => 'Robo en habitación (revisado)',
            'pasos' => [['texto' => 'Uno'], ['texto' => 'Dos'], ['texto' => 'Tres', 'critico' => '1']]]))->assertSessionHasNoErrors();
        $p = $this->recargar($p);
        $this->assertSame('Robo en habitación (revisado)', $p->titulo);
        $this->assertSame(3, $this->enEmpresa(fn () => $p->trabajo->pasos()->count()));
        $this->assertTrue(Auditoria::where('evento', 'procedimientos.actualizado')->exists());

        $this->post("/procedimientos/{$p->id}/enviar")->assertSessionHasNoErrors();
        $this->aprobar($p)->assertSessionHasNoErrors();

        // Publicado: ya no se edita directamente
        $this->actingAs($this->jefe)->put("/procedimientos/{$p->id}", $this->datos(['titulo' => 'Cambio directo']))
            ->assertSessionHasErrors(['procedimiento' => 'La versión publicada no se edita: usa «Nueva versión» para proponer cambios.']);
        $this->assertSame('Robo en habitación (revisado)', $this->recargar($p)->vigente->titulo);
    }

    // ============================================================= Circuito

    public function test_circuito_completo_con_firma_privada(): void
    {
        $p = $this->crear();
        $this->actingAs($this->jefe)->post("/procedimientos/{$p->id}/enviar")->assertSessionHasNoErrors();
        $p = $this->recargar($p);
        $this->assertSame(Procedimiento::EN_REVISION, $p->estado);
        $this->assertSame(ProcedimientoVersion::EN_REVISION, $p->trabajo->estado);
        $this->assertSame($this->jefe->id, $p->trabajo->enviado_por);

        $this->aprobar($p, $this->director)->assertRedirect("/procedimientos/{$p->id}")->assertSessionHas('ok');
        $p = $this->recargar($p);
        $this->assertSame(Procedimiento::PUBLICADO, $p->estado);
        $this->assertSame(1, $p->version_vigente);
        $this->assertNull($p->version_trabajo);
        $v = $p->vigente;
        $this->assertSame($this->director->id, $v->aprobado_por);
        $this->assertSame('Director', $v->aprobador_cargo);
        $this->assertStringStartsWith("firmas/{$this->empresa->id}/procedimientos/", $v->firma_ruta);
        Storage::disk('local')->assertExists($v->firma_ruta);
        $this->assertTrue(Auditoria::where('evento', 'procedimientos.aprobado')->exists());

        // La firma solo sale por el controlador, con permiso y alcance
        $this->actingAs($this->agente)->get("/procedimientos/{$p->id}/versiones/{$v->id}/firma")->assertOk();
        $this->actingAs($this->crearUsuario($this->empresa))->get("/procedimientos/{$p->id}/versiones/{$v->id}/firma")->assertForbidden();
        $this->get('/storage/'.$v->firma_ruta)->assertNotFound();
    }

    public function test_el_autor_no_aprueba_lo_suyo_ni_se_aprueba_dos_veces(): void
    {
        $p = $this->crear(['enviar' => '1'], $this->admin);
        $this->aprobar($p, $this->admin)->assertForbidden();
        $this->assertSame(Procedimiento::EN_REVISION, $this->recargar($p)->estado);
        $this->actingAs($this->admin)->get("/procedimientos/{$p->id}")->assertSee('Tú escribiste o enviaste esta versión')->assertDontSee('dialogoAprobarProcedimiento', false);

        $versionId = $this->recargar($p)->trabajo->id;
        $this->aprobar($p, $this->director)->assertSessionHasNoErrors();
        // Segunda aprobación de la misma versión: ya no está en revisión
        $this->actingAs($this->jefe)->post("/procedimientos/{$p->id}/aprobar", ['version_id' => $versionId, 'firma_modo' => 'nueva', 'firma' => $this->firmaJpeg()])
            ->assertSessionHasErrors('procedimiento');
        $this->assertSame(1, $this->enEmpresa(fn () => ProcedimientoVersion::where('estado', ProcedimientoVersion::PUBLICADA)->count()));

        // Quien la envió (aunque no la escribiera) tampoco la aprueba
        $q = $this->crear(['clave' => 'PRO-2'], $this->jefe);
        $this->actingAs($this->director)->post("/procedimientos/{$q->id}/enviar")->assertSessionHasNoErrors();
        $this->aprobar($q, $this->director)->assertForbidden();
        $this->aprobar($q, $this->admin)->assertSessionHasNoErrors();
    }

    public function test_rechazo_con_comentario_obligatorio_regresa_a_borrador(): void
    {
        $p = $this->crear(['enviar' => '1']);
        $version = $this->recargar($p)->trabajo->id;
        $this->actingAs($this->admin)->post("/procedimientos/{$p->id}/rechazar", ['version_id' => $version, 'comentario' => ' '])
            ->assertSessionHasErrors(['comentario' => 'Escribe por qué lo rechazas y qué hay que corregir (es obligatorio).'])
            ->assertSessionHasInput('_dialogo', 'rechazar');
        $this->assertSame(Procedimiento::EN_REVISION, $this->recargar($p)->estado);

        $this->post("/procedimientos/{$p->id}/rechazar", ['version_id' => $version, 'comentario' => 'Falta el teléfono del gerente'])->assertSessionHas('aviso');
        $p = $this->recargar($p);
        $this->assertSame(Procedimiento::BORRADOR, $p->estado);
        $this->assertSame('Falta el teléfono del gerente', $p->trabajo->motivo_rechazo);
        $this->actingAs($this->jefe)->get("/procedimientos/{$p->id}")->assertSee('Falta el teléfono del gerente');
        // Rechazar algo que ya no está en revisión
        $this->actingAs($this->admin)->post("/procedimientos/{$p->id}/rechazar", ['version_id' => $version, 'comentario' => 'Otra vez'])->assertSessionHasErrors('procedimiento');
        // Se corrige y se reenvía
        $this->actingAs($this->jefe)->post("/procedimientos/{$p->id}/enviar")->assertSessionHasNoErrors();
        $this->assertSame(Procedimiento::EN_REVISION, $this->recargar($p)->estado);
        // No se envía dos veces
        $this->post("/procedimientos/{$p->id}/enviar")->assertSessionHasErrors('procedimiento');
    }

    public function test_versiones_la_anterior_rige_hasta_aprobar_la_nueva_y_pide_firmar_otra_vez(): void
    {
        $p = $this->publicado();
        $this->acusar($p, $this->agente)->assertSessionHasNoErrors();
        $v1 = $this->recargar($p)->vigente;

        $this->actingAs($this->jefe)->post("/procedimientos/{$p->id}/nueva-version")->assertSessionHas('ok');
        $p = $this->recargar($p);
        $this->assertSame(Procedimiento::PUBLICADO, $p->estado);
        $this->assertSame(1, $p->version_vigente);
        $this->assertSame(2, $p->version_trabajo);
        $this->assertSame(['Escuchar al huésped', 'No tocar nada'], $this->enEmpresa(fn () => $p->trabajo->pasos->pluck('texto')->all()));
        // Solo una versión en trabajo a la vez
        $this->post("/procedimientos/{$p->id}/nueva-version")->assertSessionHasErrors('procedimiento');

        // Desde la versión 2, el resumen de cambios es obligatorio
        $this->post("/procedimientos/{$p->id}/enviar")->assertSessionHasErrors('resumen_cambios');
        $this->post("/procedimientos/{$p->id}/enviar", ['resumen_cambios' => 'Se agregó llamar al 911.'])->assertSessionHasNoErrors();
        // Mientras tanto la versión 1 sigue vigente y firmada
        $this->assertSame(1, $this->recargar($p)->version_vigente);
        $this->assertCount(0, $this->enEmpresa(fn () => app(AdministradorProcedimientos::class)->pendientesDe($this->agente)));

        $this->aprobar($p, $this->director)->assertSessionHasNoErrors();
        $p = $this->recargar($p);
        $this->assertSame(2, $p->version_vigente);
        $this->assertSame(ProcedimientoVersion::REEMPLAZADA, $this->enEmpresa(fn () => $v1->refresh()->estado));
        // La versión nueva pide firmar otra vez
        $this->assertSame([$p->id], $this->enEmpresa(fn () => app(AdministradorProcedimientos::class)->pendientesDe($this->agente)->pluck('id')->all()));
        $this->actingAs($this->agente)->get("/procedimientos/{$p->id}/leer")->assertOk()->assertSee('Leí y entendí este procedimiento')->assertSee('Te toca');
        // Firmar con la versión anterior (la que estaba leyendo) no sirve
        $this->acusar($p, $this->agente, ['version_id' => $v1->id])->assertSessionHasErrors(['procedimiento' => 'Se publicó la versión 2 mientras leías: léela de nuevo antes de firmar.']);
        $this->acusar($p, $this->agente)->assertSessionHasNoErrors();
        $this->assertSame(2, $this->enEmpresa(fn () => ProcedimientoAcuse::where('user_id', $this->agente->id)->count()));

        // Historial: ver la versión 1 (solo quien edita); el agente solo ve la vigente
        $this->actingAs($this->jefe)->get("/procedimientos/{$p->id}?version=1")->assertOk()->assertSee('Estás viendo la')->assertSee('reemplazada');
        $this->actingAs($this->agente)->get("/procedimientos/{$p->id}?version=1")->assertOk()->assertDontSee('Estás viendo la')->assertDontSee('Versiones');
    }

    public function test_descartar_borrador_de_version_nueva_retirar_y_reactivar(): void
    {
        $p = $this->publicado();
        $this->actingAs($this->jefe)->post("/procedimientos/{$p->id}/nueva-version");
        $this->post("/procedimientos/{$p->id}/descartar")->assertSessionHas('aviso');
        $p = $this->recargar($p);
        $this->assertNull($p->version_trabajo);
        $this->assertSame(1, $p->version_vigente);
        $this->assertTrue($this->enEmpresa(fn () => ProcedimientoVersion::where('estado', ProcedimientoVersion::DESCARTADA)->exists()));

        // Un borrador nunca publicado no se descarta (se elimina definitivamente)
        $q = $this->crear(['clave' => 'PRO-9']);
        $this->post("/procedimientos/{$q->id}/descartar")->assertSessionHasErrors('procedimiento');

        // Retirar pide motivo y «Eliminar» (el Supervisor no lo tiene)
        $this->actingAs($this->supervisor)->post("/procedimientos/{$p->id}/retirar", ['motivo' => 'Obsoleto'])->assertForbidden();
        $this->actingAs($this->jefe)->post("/procedimientos/{$p->id}/retirar", ['motivo' => ''])->assertSessionHasErrors('motivo');
        $this->post("/procedimientos/{$p->id}/retirar", ['motivo' => 'Lo sustituye PRO-SEG-010'])->assertSessionHas('aviso');
        $p = $this->recargar($p);
        $this->assertSame(Procedimiento::RETIRADO, $p->estado);
        $this->assertTrue(Auditoria::where('evento', 'procedimientos.retirado')->exists());
        // Retirado: no se pide firmar, no se ve para quien solo consulta, no se retira dos veces
        $this->assertCount(0, $this->enEmpresa(fn () => app(AdministradorProcedimientos::class)->pendientesDe($this->agente)));
        $this->actingAs($this->agente)->get("/procedimientos/{$p->id}")->assertNotFound();
        $this->actingAs($this->jefe)->post("/procedimientos/{$p->id}/retirar", ['motivo' => 'Otra vez'])->assertSessionHasErrors('procedimiento');
        $this->post("/procedimientos/{$p->id}/nueva-version")->assertSessionHasErrors('procedimiento');

        $this->post("/procedimientos/{$p->id}/reactivar")->assertSessionHas('ok');
        $this->assertSame(Procedimiento::PUBLICADO, $this->recargar($p)->estado);
        $this->assertTrue(Auditoria::where('evento', 'procedimientos.reactivado')->exists());
        $this->post("/procedimientos/{$p->id}/reactivar")->assertSessionHasErrors('procedimiento');
    }

    // ================================================================ Acuses

    public function test_acuse_con_casilla_y_firma_una_sola_vez(): void
    {
        $p = $this->publicado();
        $this->acusar($p, $this->agente, ['entendido' => null])->assertSessionHasErrors(['entendido' => 'Marca la casilla «Leí y entendí este procedimiento».']);
        $this->acusar($p, $this->agente, ['firma' => ''])->assertSessionHasErrors('firma');
        $this->acusar($p, $this->agente, ['guardar_firma' => '1'])->assertSessionHasNoErrors()->assertSessionHas('ok');
        $acuse = $this->enEmpresa(fn () => ProcedimientoAcuse::sole());
        $this->assertSame($this->agente->colaborador_id, $acuse->colaborador_id);
        Storage::disk('local')->assertExists($acuse->firma_ruta);
        $this->acusar($p, $this->agente)->assertSessionHasErrors(['procedimiento' => 'Ya firmaste de enterado la versión 1.']);

        // Firma guardada (la misma de Pases de salida): se copia
        $this->assertTrue($this->enEmpresa(fn () => FirmaUsuario::where('user_id', $this->agente->id)->exists()));
        $q = $this->publicado(['clave' => 'PRO-2']);
        $this->acusar($q, $this->agente, ['firma_modo' => 'guardada', 'firma' => null])->assertSessionHasNoErrors();
        $this->actingAs($this->agente)->get('/procedimientos/mi-firma')->assertOk();

        // La firma del acuse: su dueño y quien ve los acuses; otro agente no
        $this->actingAs($this->agente)->get("/procedimientos/{$p->id}/acuses/{$acuse->id}/firma")->assertOk();
        $this->actingAs($this->jefe)->get("/procedimientos/{$p->id}/acuses/{$acuse->id}/firma")->assertOk();
        $this->actingAs($this->agentePlaya)->get("/procedimientos/{$p->id}/acuses/{$acuse->id}/firma")->assertForbidden();
    }

    public function test_a_quien_aplica_sedes_departamentos_y_puestos(): void
    {
        $srv = app(AdministradorProcedimientos::class);
        $recepcionista = $this->conColaborador($this->crearUsuario($this->empresa, 'Agente', $this->centro), $this->centro, $this->recepcion);
        $corporativo = $this->conColaborador($this->crearUsuario($this->empresa, 'Recursos Humanos'), null, $this->recepcion);
        $sinColaborador = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $inactivo = $this->conColaborador($this->crearUsuario($this->empresa, 'Agente', $this->centro), $this->centro, $this->seguridad);
        $inactivo->forceFill(['activo' => false])->save();
        // Servicio nuevo en cada consulta (el personal se guarda por petición)
        $obligados = fn (Procedimiento $p) => $this->enEmpresa(fn () => app(AdministradorProcedimientos::class)->obligados($this->recargar($p)->vigente)->pluck('id')->sort()->values()->all());

        // Todas las sedes y todo el personal (con colaborador, activo y con «Ver»)
        $todos = $this->publicado();
        $this->assertEqualsCanonicalizing([$this->supervisor->id, $this->agente->id, $this->agentePlaya->id, $recepcionista->id, $corporativo->id], $obligados($todos));

        // Solo Centro: el corporativo (sin sede) y Playa no
        $centro = $this->publicado(['clave' => 'PRO-C', 'aplica' => 'sedes', 'sedes' => [$this->centro->id]]);
        $this->assertEqualsCanonicalizing([$this->supervisor->id, $this->agente->id, $recepcionista->id], $obligados($centro));

        // Departamento Seguridad O puesto Agente de Seguridad
        $seguridad = $this->publicado(['clave' => 'PRO-S', 'departamentos' => [$this->seguridad]]);
        $this->assertEqualsCanonicalizing([$this->supervisor->id, $this->agente->id, $this->agentePlaya->id], $obligados($seguridad));
        $puesto = $this->publicado(['clave' => 'PRO-P', 'departamentos' => [$this->recepcion], 'puestos' => [$this->agenteSeguridad]]);
        $this->assertEqualsCanonicalizing([$this->agente->id, $this->agentePlaya->id, $recepcionista->id, $corporativo->id], $obligados($puesto));

        // Sede adicional cuenta
        $this->enEmpresa(fn () => Colaborador::find($this->agentePlaya->colaborador_id)->sedesAdicionales()->sync([$this->centro->id]));
        $this->assertContains($this->agentePlaya->id, $obligados($centro));

        // Pendientes del agente y cumplimiento en la pestaña Acuses
        $this->assertCount(4, $this->enEmpresa(fn () => app(AdministradorProcedimientos::class)->pendientesDe($this->agente)));
        $this->acusar($seguridad, $this->agente)->assertSessionHasNoErrors();
        $this->actingAs($this->jefe)->get("/procedimientos/{$seguridad->id}?pestana=acuses")->assertOk()->assertSee('1 de 3 · 33%');
        $this->get("/procedimientos/{$seguridad->id}?pestana=acuses&sede={$this->playa->id}")->assertOk()->assertSee('0 de 1 · 0%');
        $this->assertNotContains($sinColaborador->id, $obligados($todos));
        $this->assertNotContains($inactivo->id, $obligados($todos));
    }

    public function test_exportar_acuses_csv_a_prueba_de_formulas(): void
    {
        $this->supervisor->forceFill(['name' => '=HYPERLINK("x")'])->save();
        $p = $this->publicado();
        $this->acusar($p, $this->agente)->assertSessionHasNoErrors();

        $csv = $this->actingAs($this->jefe)->get("/procedimientos/{$p->id}/acuses/exportar")->assertOk()->streamedContent();
        $this->assertStringContainsString('Clave,Procedimiento,Versión,Nombre', $csv);
        $this->assertStringContainsString('Firmó', $csv);
        $this->assertStringContainsString('Falta', $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->actingAs($this->agente)->get("/procedimientos/{$p->id}/acuses/exportar")->assertForbidden();
    }

    // ================================================================ Avisos

    public function test_avisos_por_correo_al_publicar_y_recordatorio_en_proceso(): void
    {
        Mail::fake();
        $this->configurarCorreo();
        $p = $this->publicado(['departamentos' => [$this->seguridad]]);
        Mail::assertSent(AvisoProcedimiento::class, fn ($m) => $m->tipo === 'publicado' && $m->hasTo($this->agente->email)
            && $m->hasTo($this->agentePlaya->email) && ! $m->hasTo($this->director->email) && $m->procedimientos[0]['clave'] === 'PRO-SEG-001');

        // Recordatorio: solo de lo publicado antes de hoy y como máximo cada 3 días
        Mail::fake();
        $this->enEmpresa(fn () => Procedimiento::whereKey($p->id)->update(['publicado_en' => now()->subDays(2)]));
        $this->acusar($p, $this->agentePlaya);
        Mail::fake();
        $this->artisan('plataforma:procedimientos-pendientes')->expectsOutput('Recordatorios de procedimientos enviados: 2.')->assertSuccessful();
        Mail::assertSent(AvisoProcedimiento::class, fn ($m) => $m->tipo === 'recordatorio' && $m->hasTo($this->agente->email));
        Mail::assertNotSent(AvisoProcedimiento::class, fn ($m) => $m->hasTo($this->agentePlaya->email));
        Mail::fake();
        $this->artisan('plataforma:procedimientos-pendientes')->expectsOutput('Recordatorios de procedimientos enviados: 0.')->assertSuccessful();
        Mail::assertNothingSent();

        // Se apagan desde Configuración → Avisos por correo
        $this->actingAs($this->admin)->put('/configuracion/avisos', [])->assertSessionHas('ok');
        Mail::fake();
        $this->publicado(['clave' => 'PRO-2']);
        Mail::assertNothingSent();
    }

    public function test_aviso_en_inicio_por_leer_y_por_aprobar(): void
    {
        $this->publicado();
        $this->crear(['clave' => 'PRO-2', 'enviar' => '1']);
        $this->actingAs($this->agente)->get('/')->assertOk()->assertSee('Tienes 1 procedimiento por leer y firmar');
        $this->actingAs($this->admin)->get('/')->assertOk()->assertSee('1 procedimiento espera tu aprobación');
        $this->actingAs($this->jefe)->get('/')->assertOk()->assertDontSee('espera tu aprobación');
        $this->actingAs($this->agente)->get('/procedimientos/por-leer')->assertOk()->assertSee('Mis procedimientos por leer')->assertSee('Leer y firmar');
    }

    // ============================================================= Adjuntos

    public function test_adjuntos_privados_validados_y_servidos_con_permiso(): void
    {
        $pdf = UploadedFile::fake()->createWithContent('Directorio.pdf', "%PDF-1.4\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF");
        $png = UploadedFile::fake()->image('Plano.png', 120, 80);
        $p = $this->publicado(['adjuntos' => [$pdf, $png]]);
        $adjuntos = $this->enEmpresa(fn () => ProcedimientoAdjunto::orderBy('id')->get());
        $this->assertSame(['pdf', 'imagen'], $adjuntos->pluck('tipo')->all());
        foreach ($adjuntos as $a) {
            $this->assertStringStartsWith("procedimientos/{$this->empresa->id}/adjuntos/", $a->ruta);
            Storage::disk('local')->assertExists($a->ruta);
        }

        $this->actingAs($this->agente)->get("/procedimientos/{$p->id}/adjuntos/{$adjuntos[0]->id}")->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->actingAs($this->crearUsuario($this->empresa))->get("/procedimientos/{$p->id}/adjuntos/{$adjuntos[0]->id}")->assertForbidden();

        // Tipos no permitidos o falsos
        $html = UploadedFile::fake()->createWithContent('malo.pdf', '<script>alert(1)</script>');
        // (el navegador puede decir que es PDF: el servidor revisa el contenido real)
        $this->actingAs($this->jefe)->post('/procedimientos', $this->datos(['clave' => 'PRO-X', 'adjuntos' => [$html]]))->assertSessionHasErrors('adjuntos');
        $this->assertFalse($this->enEmpresa(fn () => Procedimiento::where('clave', 'PRO-X')->exists()));
        $exe = UploadedFile::fake()->create('programa.exe', 10, 'application/octet-stream');
        $this->post('/procedimientos', $this->datos(['clave' => 'PRO-Y', 'adjuntos' => [$exe]]))->assertSessionHasErrors('adjuntos.0');
        $grande = UploadedFile::fake()->create('grande.pdf', 6000, 'application/pdf');
        $this->post('/procedimientos', $this->datos(['clave' => 'PRO-Z', 'adjuntos' => [$grande]]))->assertSessionHasErrors('adjuntos.0');

        // Una versión nueva copia los archivos: quitarlos del borrador no toca la versión publicada
        $this->post("/procedimientos/{$p->id}/nueva-version");
        $copias = $this->enEmpresa(fn () => $this->recargar($p)->trabajo->adjuntos);
        $this->assertCount(2, $copias);
        $this->put("/procedimientos/{$p->id}", $this->datos(['quitar_adjuntos' => $copias->pluck('id')->all()]))->assertSessionHasNoErrors();
        foreach ($adjuntos as $a) {
            Storage::disk('local')->assertExists($a->ruta);
        }
        Storage::disk('local')->assertMissing($copias[0]->ruta);
    }

    // ===================================================== Permisos y alcance

    public function test_agente_lee_y_firma_pero_no_crea_ni_ve_borradores(): void
    {
        $publicado = $this->publicado();
        $borrador = $this->crear(['clave' => 'PRO-B', 'titulo' => 'Llave maestra perdida']);

        $this->actingAs($this->agente)->get('/procedimientos')->assertOk()->assertSee('PRO-SEG-001')->assertDontSee('Llave maestra perdida')
            ->assertDontSee('Nuevo Procedimiento')->assertDontSee('dialogoNuevoProcedimiento', false);
        $this->post('/procedimientos', $this->datos(['clave' => 'PRO-A']))->assertForbidden();
        $this->get("/procedimientos/{$borrador->id}")->assertNotFound();
        $this->get("/procedimientos/{$borrador->id}/leer")->assertNotFound();
        $this->put("/procedimientos/{$publicado->id}", $this->datos())->assertForbidden();
        $this->post("/procedimientos/{$publicado->id}/nueva-version")->assertForbidden();
        $this->post("/procedimientos/{$publicado->id}/aprobar")->assertForbidden();
        $this->post("/procedimientos/{$publicado->id}/retirar")->assertForbidden();
        $this->get("/procedimientos/{$publicado->id}")->assertOk()->assertDontSee('Nueva versión')->assertDontSee('Acuses');
        $this->get("/procedimientos/{$publicado->id}/leer")->assertOk()->assertSee('Firmar de enterado');
        $this->get("/procedimientos/{$publicado->id}/imprimir")->assertOk()->assertSee('PRO-SEG-001')->assertSee('PUNTO CRÍTICO');
        $this->acusar($publicado, $this->agente)->assertSessionHasNoErrors();

        // Permisos de las plantillas (decisión del dueño del proyecto)
        $this->assertTrue($this->director->can('procedimientos.crear'));
        $this->assertTrue($this->director->can('procedimientos.aprobar'));
        $this->assertTrue($this->jefe->can('procedimientos.aprobar'));
        $this->assertTrue($this->supervisor->can('procedimientos.editar'));
        $this->assertFalse($this->supervisor->can('procedimientos.aprobar'));
        $rh = $this->crearUsuario($this->empresa, 'Recursos Humanos');
        $asistente = $this->crearUsuario($this->empresa, 'Asistente', $this->centro);
        foreach ([$this->agente, $rh, $asistente] as $u) {
            $this->assertTrue($u->can('procedimientos.ver'));
            $this->assertFalse($u->can('procedimientos.crear'));
            $this->assertFalse($u->can('procedimientos.editar'));
        }
        $this->assertTrue($this->admin->can('procedimientos.borrar'));
        $this->assertFalse($this->jefe->can('procedimientos.borrar'));
    }

    public function test_alcance_de_sede(): void
    {
        $centro = $this->publicado(['clave' => 'PRO-C', 'aplica' => 'sedes', 'sedes' => [$this->centro->id]]);
        $todas = $this->publicado(['clave' => 'PRO-T']);
        $supervisorPlaya = $this->crearUsuario($this->empresa, 'Supervisor', $this->playa);

        // Ve lo de todas las sedes y lo de las suyas
        $this->actingAs($this->agentePlaya)->get('/procedimientos')->assertSee('PRO-T')->assertDontSee('PRO-C');
        $this->get("/procedimientos/{$centro->id}")->assertNotFound();
        $this->get("/procedimientos/{$centro->id}/leer")->assertNotFound();
        $this->acusar($centro, $this->agentePlaya)->assertNotFound();

        // Con alcance de sede no se publica para todas ni para otras sedes; sus sedes vienen marcadas
        $this->actingAs($supervisorPlaya)->get('/procedimientos')->assertOk()->assertDontSee('value="todas"', false);
        $this->post('/procedimientos', $this->datos(['clave' => 'PRO-1']))
            ->assertSessionHasErrors(['sedes' => 'Solo puedes hacer procedimientos para tus sedes: elige «Sedes elegidas» y marca las tuyas.']);
        $this->post('/procedimientos', $this->datos(['clave' => 'PRO-2', 'aplica' => 'sedes', 'sedes' => [$this->centro->id]]))
            ->assertSessionHasErrors(['sedes' => 'Solo puedes elegir las sedes en las que trabajas.']);
        $this->post('/procedimientos', $this->datos(['clave' => 'PRO-3', 'aplica' => 'sedes', 'sedes' => [$this->playa->id]]))->assertSessionHasNoErrors();

        // No hace versión nueva de lo que aplica a todas las sedes
        $this->post("/procedimientos/{$todas->id}/nueva-version")->assertForbidden();

        // Un jefe de Playa no aprueba lo que aplica a todas las sedes
        $jefePlaya = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->playa);
        $p = $this->crear(['clave' => 'PRO-4', 'enviar' => '1'], $this->admin);
        $this->aprobar($p, $jefePlaya)->assertForbidden();
        $q = $this->crear(['clave' => 'PRO-5', 'aplica' => 'sedes', 'sedes' => [$this->playa->id], 'enviar' => '1'], $this->admin);
        $this->aprobar($q, $jefePlaya)->assertSessionHasNoErrors();
    }

    public function test_otra_empresa_recibe_404(): void
    {
        $p = $this->publicado();
        $acuse = null;
        $this->acusar($p, $this->agente);
        $acuse = $this->enEmpresa(fn () => ProcedimientoAcuse::sole());
        $v = $this->recargar($p)->vigente;
        $cat = $this->categoria();

        $otra = $this->crearEmpresa('Hotel Ajeno');
        $this->crearSede($otra, 'AJ');
        $intruso = $this->crearUsuario($otra, 'Administrador');
        $this->actingAs($intruso);
        foreach (["/procedimientos/{$p->id}", "/procedimientos/{$p->id}/leer", "/procedimientos/{$p->id}/imprimir", "/procedimientos/{$p->id}/acuses/exportar",
            "/procedimientos/{$p->id}/versiones/{$v->id}/firma", "/procedimientos/{$p->id}/acuses/{$acuse->id}/firma"] as $url) {
            $this->get($url)->assertNotFound();
        }
        $this->post("/procedimientos/{$p->id}/retirar", ['motivo' => 'Intruso'])->assertNotFound();
        $this->post("/procedimientos/{$p->id}/nueva-version")->assertNotFound();
        $this->put("/procedimientos/categorias/{$cat}", ['nombre' => 'X', 'color' => 'rojo'])->assertNotFound();
        $this->get('/procedimientos')->assertOk()->assertDontSee('Atender el reporte de robo.');
        // Su categoría no sirve en otra empresa
        $this->post('/procedimientos', $this->datos(['categoria_id' => $cat]))->assertSessionHasErrors('categoria_id');
        $this->assertSame(Procedimiento::PUBLICADO, $this->recargar($p)->estado);
    }

    public function test_categorias_con_alcance_de_empresa(): void
    {
        $cats = $this->enEmpresa(fn () => app(AdministradorProcedimientos::class)->categorias());
        $this->assertSame(['Emergencias', 'Operación de caseta', 'Accesos', 'Protección civil', 'Administrativo'], $cats->pluck('nombre')->all());

        $this->actingAs($this->admin)->post('/procedimientos/categorias', ['nombre' => 'Mantenimiento', 'color' => 'verde'])->assertSessionHas('ok');
        $this->post('/procedimientos/categorias', ['nombre' => 'mantenimiento', 'color' => 'verde'])->assertSessionHasErrors('nombre');
        $id = $cats->first()->id;
        $this->put("/procedimientos/categorias/{$id}", ['nombre' => 'Emergencias mayores', 'color' => 'rojo', 'orden' => 1, 'activo' => '1'])->assertSessionHas('ok');
        $this->assertTrue(Auditoria::where('evento', 'procedimientos.categoria_actualizada')->exists());
        // El supervisor (alcance de sede) no cambia el catálogo de la empresa
        $this->actingAs($this->supervisor)->post('/procedimientos/categorias', ['nombre' => 'Otra', 'color' => 'azul'])->assertForbidden();
        $this->actingAs($this->agente)->post('/procedimientos/categorias', ['nombre' => 'Otra', 'color' => 'azul'])->assertForbidden();
    }

    // ===================================================== Lector y borrado

    public function test_el_qr_de_la_hoja_abre_el_modo_lectura_con_el_lector_universal(): void
    {
        $p = $this->publicado();
        $this->assertSame(Procedimiento::class, config('lector.tipos.procedimiento'));
        $this->actingAs($this->agente)->get('/e/'.$p->codigo_qr)->assertRedirect(route('procedimientos.leer', $p->id));
        $this->getJson('/lector/resolver?tipos=procedimiento&entrada=PRO-SEG-001')->assertOk()->assertJsonPath('resultados.0.id', $p->id);
        // Un lector que "escribe" la dirección en la búsqueda también la abre
        $this->get('/procedimientos?q='.urlencode(url('/e/'.$p->codigo_qr)))->assertRedirect(route('procedimientos.leer', $p->id));
        $this->get('/procedimientos')->assertSee('data-lector-procedimiento', false)->assertSee('data-tipos="procedimiento"', false);
    }

    public function test_eliminar_definitivamente_solo_un_borrador_nunca_publicado(): void
    {
        $borrador = $this->crear(['clave' => 'PRO-B']);
        $publicado = $this->publicado();

        $this->actingAs($this->admin)->getJson("/borrar/procedimientos/{$publicado->id}")->assertOk()->assertJsonPath('puede_eliminar', false)
            ->assertJsonPath('mensaje', 'Ya se publicó una versión (su historial y sus acuses se conservan): no se puede eliminar; puedes darlo de baja.');
        $this->getJson("/borrar/procedimientos/{$borrador->id}")->assertOk()->assertJsonPath('puede_eliminar', true)->assertJsonPath('confirmar', 'PRO-B');
        $this->deleteJson("/borrar/procedimientos/{$borrador->id}", ['confirmacion' => 'pro-b'])->assertOk();
        $this->assertDatabaseMissing('procedimientos', ['id' => $borrador->id]);
        $this->assertDatabaseMissing('procedimiento_versiones', ['procedimiento_id' => $borrador->id]);
        $this->assertTrue(Auditoria::where('evento', 'procedimientos.eliminado_definitivo')->exists());
        $this->actingAs($this->jefe)->getJson("/borrar/procedimientos/{$publicado->id}")->assertForbidden();
    }

    public function test_datos_demo(): void
    {
        $this->artisan('plataforma:demo', ['--password' => 'Demo1234!'])->assertSuccessful();
        $demo = Empresa::where('nombre_comercial', 'Hotel Demo')->firstOrFail();
        $estados = $this->enEmpresa(fn () => Procedimiento::orderBy('clave')->pluck('estado', 'clave')->all(), $demo);
        $this->assertSame(['PRO-ACC-004' => 'borrador', 'PRO-PC-002' => 'publicado', 'PRO-PC-005' => 'publicado', 'PRO-SEG-001' => 'publicado', 'PRO-SEG-003' => 'en_revision'], $estados);
        $this->assertSame(2, $this->enEmpresa(fn () => Procedimiento::where('clave', 'PRO-SEG-001')->value('version_vigente'), $demo));
        $agente = User::where('username', 'agente.demo')->firstOrFail();
        $admin = User::where('username', 'admin.demo')->firstOrFail();
        $srv = app(AdministradorProcedimientos::class);
        $this->assertEqualsCanonicalizing(['PRO-SEG-001', 'PRO-PC-002', 'PRO-PC-005'], $this->enEmpresa(fn () => $srv->pendientesDe($agente)->pluck('clave')->all(), $demo));
        $this->assertCount(1, $this->enEmpresa(fn () => $srv->idsPorAprobar($admin), $demo));
        $this->actingAs($agente)->get('/procedimientos/por-leer')->assertOk()->assertSee('Robo en habitación');
    }
}
