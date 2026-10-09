<?php

namespace Tests\Feature\Seguridad;

use App\Mail\AvisoRecepcion;
use App\Models\Acceso;
use App\Models\Auditoria;
use App\Models\Autorizacion;
use App\Models\Candidato;
use App\Models\CandidatoDocumento;
use App\Models\Colaborador;
use App\Models\Delegacion;
use App\Models\Departamento;
use App\Models\DepartamentoResponsable;
use App\Models\Empresa;
use App\Models\EnlaceKiosco;
use App\Models\Notificacion;
use App\Models\Persona;
use App\Models\Puesto;
use App\Models\Rol;
use App\Models\RolPermiso;
use App\Models\Sede;
use App\Models\User;
use App\Models\UsuarioRol;
use App\Services\Candidatos\Kiosco;
use App\Services\Notificaciones\CentroNotificaciones;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use App\Services\Recepcion\AjustesRecepcion;
use App\Support\CorreoPlataforma;
use App\Support\HoraLocal;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Recepción de candidatos y visitas + Autorizaciones departamentales (ADR-0007).
 */
class CandidatosYAutorizacionesTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private Sede $playa;

    private User $admin;

    private User $rh;

    private User $agente;

    private User $jefe;

    private Departamento $seguridad;

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
        $this->jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad');
        [$this->seguridad, $this->cocina] = $this->enEmpresa(fn () => [Departamento::create(['nombre' => 'Seguridad']), Departamento::create(['nombre' => 'Cocina'])]);
    }

    // ------------------------------------------------------------------ Ayudas

    private function enEmpresa(callable $fn, ?Empresa $empresa = null): mixed
    {
        return app(Tenant::class)->conEmpresa(($empresa ?? $this->empresa)->id, $fn);
    }

    private function configurarCorreo(): void
    {
        app(CorreoPlataforma::class)->guardar(['host' => 'mail.ejemplo.com', 'puerto' => 587, 'cifrado' => 'tls', 'usuario' => 'a@ejemplo.com',
            'remitente_correo' => 'avisos@ejemplo.com', 'remitente_nombre' => 'Avisos'], 'x');
    }

    /** @param array<string, mixed> $extra */
    private function registrarEnCaseta(array $extra = [], ?User $quien = null): TestResponse
    {
        return $this->actingAs($quien ?? $this->agente)->post('/accesos', $extra + [
            'sede_id' => $this->centro->id, 'tipo' => 'visitante', 'nombre' => 'Karla Pérez Uc', 'motivo_visita' => 'rh',
            'es_candidato' => '1', 'departamento_id' => $this->cocina->id, 'vacante' => 'Camarista', 'modo_arribo' => 'a_pie', 'identificacion' => 'ine',
        ]);
    }

    private function candidato(string $etapa = 'registrado', array $datos = []): Candidato
    {
        return $this->enEmpresa(function () use ($etapa, $datos) {
            $c = new Candidato($datos + ['sede_id' => $this->centro->id, 'nombre_completo' => 'Luis Chi Canul', 'departamento_id' => $this->cocina->id,
                'origen' => 'rh', 'llegada_en' => now()]);
            $c->forceFill(['etapa' => $etapa])->save();

            return $c;
        });
    }

    private function responsable(Departamento $d, User $u, bool $suplente = false, ?Sede $sede = null): void
    {
        $this->enEmpresa(fn () => DepartamentoResponsable::create(['departamento_id' => $d->id, 'user_id' => $u->id, 'es_suplente' => $suplente, 'sede_id' => $sede?->id]));
    }

    private function visitasConAutorizacion(bool $si = true): void
    {
        $this->empresa->refresh()->forceFill(['preferencias' => array_merge($this->empresa->preferencias ?? [], ['recepcion' => ['visitas_requieren_autorizacion' => $si]])])->save();
    }

    /** @return array<string, mixed> */
    private function cv(array $extra = []): array
    {
        return $extra + [
            'nombre_completo' => 'Ana Pool Canché', 'telefono' => '998-123-4567', 'correo' => 'ana@correo.mx',
            'escolaridad' => [['nivel' => 'licenciatura', 'institucion' => 'UT Cancún', 'titulo' => 'Gastronomía', 'concluido' => '1'], ['nivel' => '', 'institucion' => '']],
            'experiencia' => [['empresa' => 'Hotel Sol', 'puesto' => 'Cocinera', 'anos' => '3']],
            'referencias' => [['nombre' => 'Martha Chablé', 'telefono' => '9981112233', 'relacion' => 'Jefa']],
            'disponibilidad' => 'inmediata', 'pretension' => '$9,500',
        ];
    }

    /**
     * Lección 36: lo que envía el propio candidato (kiosco) es la solicitud
     * completa: nombre y apellidos, 2 referencias personales, declaración y firma.
     *
     * @return array<string, mixed>
     */
    private function cvKiosco(array $extra = []): array
    {
        return $this->cv($extra + [
            'nombre' => 'Ana', 'apellido_paterno' => 'Pool', 'apellido_materno' => 'Canché',
            'referencias' => [['nombre' => 'Martha Chablé', 'telefono' => '9981112233', 'relacion' => 'Jefa'], ['nombre' => 'Rosa Uc', 'telefono' => '9984445566', 'relacion' => 'Vecina']],
            'declaracion' => '1', 'firma' => $this->firmaImagen(),
        ]);
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

    // ------------------------------------------------------------------ Caseta → RR. HH.

    public function test_la_caseta_registra_un_candidato_con_fotos_y_rh_recibe_el_aviso_y_el_correo(): void
    {
        Mail::fake();
        $this->configurarCorreo();

        $this->registrarEnCaseta([
            'foto_persona' => UploadedFile::fake()->image('persona.jpg', 1800, 1200),
            'foto_identificacion' => UploadedFile::fake()->image('ine.png', 600, 400),
        ])->assertRedirect()->assertSessionHas('ok');

        $c = $this->enEmpresa(fn () => Candidato::with('acceso', 'persona')->sole());
        $this->assertSame('registrado', $c->etapa);
        $this->assertSame('caseta', $c->origen);
        $this->assertSame($this->cocina->id, $c->departamento_id);
        $this->assertSame('Camarista', $c->vacante);
        $this->assertSame('prospecto_rrhh', $c->persona->categoria);
        $this->assertNotNull($c->avisado_rh_en);
        $this->assertSame('en_sitio', $c->acceso->estado);

        // Fotos en el disco privado, re-dibujadas y reducidas
        $this->assertStringStartsWith('accesos/'.$this->empresa->id.'/fotos/', $c->acceso->foto_persona);
        Storage::disk('local')->assertExists($c->acceso->foto_persona);
        $this->assertLessThanOrEqual(1280, getimagesizefromstring(Storage::disk('local')->get($c->acceso->foto_persona))[0]);
        $this->assertFalse(str_starts_with((string) $c->acceso->foto_persona, 'public'));

        // Aviso en la campana de RR. HH. (no al agente) y correo
        $avisos = $this->enEmpresa(fn () => Notificacion::where('tipo', 'candidato_llegada')->pluck('user_id')->all());
        $this->assertContains($this->rh->id, $avisos);
        $this->assertNotContains($this->agente->id, $avisos);
        Mail::assertSent(AvisoRecepcion::class, fn ($m) => $m->hasTo($this->rh->email) && str_contains($m->titulo, 'Karla Pérez Uc'));

        // La campana lo muestra
        $this->actingAs($this->rh)->getJson('/notificaciones/resumen')->assertOk()->assertJsonPath('no_leidas', 1)
            ->assertJsonPath('lista.0.titulo', 'Llegó un candidato: Karla Pérez Uc');
        $this->actingAs($this->agente)->getJson('/notificaciones/resumen')->assertJsonPath('no_leidas', 0);
    }

    public function test_el_agente_registra_y_toma_fotos_pero_no_ve_los_cv(): void
    {
        $this->registrarEnCaseta(['foto_persona' => UploadedFile::fake()->image('p.jpg', 200, 200)])->assertSessionHas('ok');
        $c = $this->enEmpresa(fn () => Candidato::sole());

        $this->actingAs($this->agente)->get('/candidatos')->assertForbidden();
        $this->actingAs($this->agente)->get("/candidatos/{$c->id}")->assertForbidden();
        $this->actingAs($this->agente)->get('/candidatos/exportar')->assertForbidden();
        $this->actingAs($this->agente)->get('/rh/recepcion')->assertForbidden();
        // La foto sí la ve en su bitácora (es de su sede)
        $this->actingAs($this->agente)->get("/accesos/{$c->acceso_id}/foto-persona")->assertOk();
        // El agente de otra sede no
        $playa = $this->crearUsuario($this->empresa, 'Agente', $this->playa);
        $this->actingAs($playa)->get("/accesos/{$c->acceso_id}/foto-persona")->assertNotFound();
        // RR. HH. sí ve la ficha, la foto y la identificación
        $this->actingAs($this->rh)->get("/candidatos/{$c->id}")->assertOk()->assertSee('Karla Pérez Uc');
        $this->actingAs($this->rh)->get("/accesos/{$c->acceso_id}/foto-persona")->assertOk();
        // Sin foto de identificación → 404
        $this->actingAs($this->rh)->get("/accesos/{$c->acceso_id}/foto-identificacion")->assertNotFound();
    }

    public function test_una_foto_que_no_es_imagen_se_rechaza_dentro_del_dialogo(): void
    {
        $falsa = UploadedFile::fake()->createWithContent('foto.jpg', '<?php echo 1; ?>');
        $this->registrarEnCaseta(['foto_persona' => $falsa, '_dialogo' => 'ingreso'])->assertSessionHasErrors('foto_persona');
        $this->assertSame(0, $this->enEmpresa(fn () => Candidato::count()));
    }

    public function test_panel_de_recepcion_muestra_a_quien_espera_y_su_json(): void
    {
        $this->registrarEnCaseta()->assertSessionHas('ok');
        $this->actingAs($this->rh)->get('/rh/recepcion')->assertOk()->assertSee('Karla Pérez Uc')->assertSee('Atender');
        $this->actingAs($this->rh)->getJson('/rh/recepcion/datos')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('contadores.esperando', 1);

        // «Atender» pasa a En revisión y deja de contar como esperando
        $c = $this->enEmpresa(fn () => Candidato::sole());
        $this->actingAs($this->rh)->patch("/candidatos/{$c->id}/etapa", ['etapa' => 'revision', 'volver' => 'recepcion'])->assertRedirect('/rh/recepcion');
        $this->actingAs($this->rh)->getJson('/rh/recepcion/datos')->assertJsonPath('contadores.esperando', 0);
        $this->assertNotNull($c->fresh()->revision_en);
    }

    // ------------------------------------------------------------------ Ficha y aviso de privacidad

    public function test_rh_captura_un_candidato_solo_si_acepta_el_aviso_de_privacidad(): void
    {
        $this->actingAs($this->rh)->post('/candidatos', $this->cv(['sede_id' => $this->centro->id, '_dialogo' => 'candidato']))
            ->assertSessionHasErrors('acepta_privacidad');
        $this->assertSame(0, $this->enEmpresa(fn () => Candidato::count()));

        $this->actingAs($this->rh)->post('/candidatos', $this->cv(['sede_id' => $this->centro->id, 'acepta_privacidad' => '1']))->assertRedirect();
        $c = $this->enEmpresa(fn () => Candidato::sole());
        $this->assertSame('9981234567', $c->telefono);
        $this->assertSame('9500.00', $c->pretension);
        $this->assertCount(1, $c->escolaridad, 'El renglón vacío se omite');
        $this->assertSame('Licenciatura', $c->escolaridadMaxima());
        $this->assertSame(3, $c->anosExperiencia());
        $this->assertNotNull($c->privacidad_aceptada_en);
        $this->assertSame('127.0.0.1', $c->privacidad_ip);
        $this->assertSame('rh', $c->privacidad_medio);
        $this->assertSame(app(AjustesRecepcion::class)->versionPrivacidad($this->empresa->refresh()), $c->privacidad_version);
        // Queda en el Padrón de personas como prospecto
        $this->assertTrue($this->enEmpresa(fn () => Persona::where('nombre_completo', 'Ana Pool Canché')->where('categoria', 'prospecto_rrhh')->exists()));

        // El CV nunca va a la bitácora de auditoría (dato personal)
        $foto = json_encode(Auditoria::where('evento', 'candidatos.creado')->value('despues'));
        $this->assertStringNotContainsString('9981234567', (string) $foto);
        $this->assertStringNotContainsString('ana@correo.mx', (string) $foto);
    }

    public function test_validaciones_del_cv_en_espanol_y_sede_fuera_de_alcance(): void
    {
        $this->actingAs($this->rh)->post('/candidatos', $this->cv(['sede_id' => $this->centro->id, 'acepta_privacidad' => '1', 'telefono' => '12',
            'correo' => 'mal', 'escolaridad' => [['nivel' => 'doctorado']]]))
            ->assertSessionHasErrors(['telefono' => 'El teléfono lleva de 10 a 15 números.', 'correo', 'escolaridad.0.nivel']);

        // Otra empresa: su sede no se puede elegir
        $otra = $this->crearEmpresa('Otro Hotel');
        $sedeAjena = $this->crearSede($otra, 'OTR');
        $this->actingAs($this->rh)->post('/candidatos', $this->cv(['sede_id' => $sedeAjena->id, 'acepta_privacidad' => '1']))->assertSessionHasErrors('sede_id');
    }

    public function test_aviso_de_privacidad_configurable_y_la_version_cambia(): void
    {
        $antes = app(AjustesRecepcion::class)->versionPrivacidad($this->empresa);
        $this->assertTrue(app(AjustesRecepcion::class)->esBorrador($this->empresa));
        $this->actingAs($this->agente)->put('/rh/recepcion/ajustes', ['aviso_privacidad' => 'X'])->assertForbidden();
        $this->actingAs($this->rh)->put('/rh/recepcion/ajustes', ['aviso_privacidad' => 'Aviso definitivo de Hotel Ébano.', 'kiosco_horas' => 3, 'kiosco_usos' => 2])
            ->assertSessionHas('ok');
        $empresa = $this->empresa->refresh();
        $this->assertNotSame($antes, app(AjustesRecepcion::class)->versionPrivacidad($empresa));
        $this->assertFalse(app(AjustesRecepcion::class)->esBorrador($empresa));
        $this->assertSame(3, app(AjustesRecepcion::class)->horasKiosco($empresa));
        $this->actingAs($this->rh)->get('/configuracion')->assertForbidden(); // RR. HH. lo ajusta en Recepción → Ajustes
        $this->actingAs($this->rh)->get('/rh/recepcion/ajustes')->assertOk()->assertSee('Aviso definitivo de Hotel Ébano.');
        $this->actingAs($this->admin)->get('/configuracion')->assertOk()->assertSee('Recepción de candidatos');
    }

    // ------------------------------------------------------------------ Etapas

    public function test_cada_transicion_de_etapa_y_las_prohibidas(): void
    {
        $c = $this->candidato();
        $ir = fn (string $etapa, ?string $comentario = null) => $this->actingAs($this->rh)->patch("/candidatos/{$c->id}/etapa", ['etapa' => $etapa, 'comentario' => $comentario]);

        // Prohibidas desde Registrado
        foreach (['aprobado_rh', 'entrevista', 'seleccionado'] as $prohibida) {
            $ir($prohibida)->assertSessionHas('error');
            $this->assertSame('registrado', $c->fresh()->etapa);
        }
        $ir('contratado')->assertSessionHas('error');
        $ir('registrado')->assertSessionHas('error');

        $ir('revision')->assertSessionHas('ok');
        $ir('aprobado_rh')->assertSessionHas('ok'); // sin responsable: se deja anotado en el historial
        $this->assertSame('aprobado_rh', $c->fresh()->etapa);
        $this->assertTrue($this->enEmpresa(fn () => $c->eventos()->where('evento', 'sin_responsable')->exists()));
        $ir('entrevista')->assertSessionHas('ok');
        $ir('seleccionado')->assertSessionHas('ok');
        $this->assertNotNull($c->fresh()->decision_en);
        $ir('cartera')->assertSessionHas('ok');
        $ir('entrevista')->assertSessionHas('error'); // de cartera solo a revisión o descartado
        $ir('descartado')->assertSessionHasErrors('comentario'); // el motivo es obligatorio
        $ir('descartado', 'No cubre el horario')->assertSessionHas('ok');
        $this->assertSame('No cubre el horario', $c->fresh()->motivo_descarte);
        $ir('seleccionado')->assertSessionHas('error');
        $ir('revision')->assertSessionHas('ok'); // se puede reabrir

        // Aprobar sin departamento no se puede
        $sinDepto = $this->candidato('revision', ['departamento_id' => null]);
        $this->actingAs($this->rh)->patch("/candidatos/{$sinDepto->id}/etapa", ['etapa' => 'aprobado_rh'])->assertSessionHasErrors('comentario');

        // Contratado: ya no cambia
        $contratado = $this->candidato('contratado');
        foreach (array_keys(Candidato::ETAPAS) as $e) {
            $this->actingAs($this->rh)->patch("/candidatos/{$contratado->id}/etapa", ['etapa' => $e]);
            $this->assertSame('contratado', $contratado->fresh()->etapa);
        }
        $this->assertSame(7, Auditoria::where('evento', 'candidatos.etapa')->where('auditable_id', $c->id)->count());
    }

    public function test_contratar_crea_el_colaborador_con_sus_datos(): void
    {
        $puesto = $this->enEmpresa(fn () => Puesto::create(['nombre' => 'Cocinero', 'tipo' => Puesto::OPERATIVO]));
        $c = $this->candidato('entrevista', ['telefono' => '9987654321', 'puesto_id' => $puesto->id, 'nombre_completo' => 'Ramón Ek Balam']);

        // Solo desde Seleccionado
        $this->actingAs($this->rh)->post("/candidatos/{$c->id}/contratar", ['num_empleado' => '2001', 'nombre' => 'Ramón', 'apellido_paterno' => 'Ek'])
            ->assertSessionHas('error');
        $c->forceFill(['etapa' => 'seleccionado'])->save();

        // El agente y el director no contratan
        $this->actingAs($this->agente)->post("/candidatos/{$c->id}/contratar", ['num_empleado' => '2001'])->assertForbidden();
        $director = $this->crearUsuario($this->empresa, 'Director');
        $this->actingAs($director)->post("/candidatos/{$c->id}/contratar", ['num_empleado' => '2001'])->assertForbidden();

        // Validación de Colaboradores (número obligatorio)
        $this->actingAs($this->rh)->post("/candidatos/{$c->id}/contratar", ['nombre' => 'Ramón', 'apellido_paterno' => 'Ek', '_dialogo' => 'contratar'])
            ->assertSessionHasErrors('num_empleado');

        $this->actingAs($this->rh)->post("/candidatos/{$c->id}/contratar", ['num_empleado' => '2001', 'nombre' => 'Ramón', 'apellido_paterno' => 'Ek', 'apellido_materno' => 'Balam'])
            ->assertSessionHas('ok');
        $col = $this->enEmpresa(fn () => Colaborador::where('num_empleado', '2001')->sole());
        $this->assertSame('Ramón', $col->nombre);
        $this->assertSame($this->centro->id, $col->sede_id);
        $this->assertSame($this->cocina->id, $col->departamento_id);
        $this->assertSame($puesto->id, $col->puesto_id);
        $this->assertSame('9987654321', $col->telefono);
        $c->refresh();
        $this->assertSame('contratado', $c->etapa);
        $this->assertSame($col->id, $c->colaborador_id);
        $this->assertNotNull($c->contratado_en);
        $this->assertTrue(Auditoria::where('evento', 'candidatos.contratado')->exists());
        // Dos veces no
        $this->actingAs($this->rh)->post("/candidatos/{$c->id}/contratar", ['num_empleado' => '2002', 'nombre' => 'R', 'apellido_paterno' => 'E'])->assertSessionHas('error');
    }

    // ------------------------------------------------------------------ Documentos

    public function test_documentos_privados_validados_y_solo_con_permiso(): void
    {
        $c = $this->candidato();
        $pdf = UploadedFile::fake()->createWithContent('cv.pdf', "%PDF-1.4\n%prueba\n");
        $this->actingAs($this->rh)->post("/candidatos/{$c->id}/documentos", ['tipo' => 'cv', 'documento' => $pdf])->assertSessionHas('ok');
        $doc = $this->enEmpresa(fn () => CandidatoDocumento::sole());
        $this->assertStringStartsWith('candidatos/'.$this->empresa->id.'/'.$c->id.'/', $doc->ruta);
        Storage::disk('local')->assertExists($doc->ruta);
        $this->actingAs($this->rh)->get("/candidatos/{$c->id}")->assertOk()->assertSee('cv.pdf');

        // Un ejecutable o un HTML disfrazado no pasa
        $this->actingAs($this->rh)->post("/candidatos/{$c->id}/documentos", ['tipo' => 'ine', 'documento' => UploadedFile::fake()->createWithContent('ine.png', '<script>alert(1)</script>')])
            ->assertSessionHasErrors('documento');
        $this->actingAs($this->rh)->post("/candidatos/{$c->id}/documentos", ['tipo' => 'cv', 'documento' => UploadedFile::fake()->create('cv.exe', 10)])
            ->assertSessionHasErrors('documento');
        $this->actingAs($this->rh)->post("/candidatos/{$c->id}/documentos", ['tipo' => 'cv', 'documento' => UploadedFile::fake()->create('cv.pdf', 6000, 'application/pdf')])
            ->assertSessionHasErrors('documento');

        // Se descarga solo con permiso y dentro de la empresa
        $this->actingAs($this->rh)->get("/candidatos/{$c->id}/documentos/{$doc->id}")->assertOk()->assertDownload('cv.pdf');
        $this->actingAs($this->agente)->get("/candidatos/{$c->id}/documentos/{$doc->id}")->assertForbidden();
        $otra = $this->crearEmpresa('Otro Hotel');
        $adminAjeno = $this->crearUsuario($otra, 'Administrador');
        $this->actingAs($adminAjeno)->get("/candidatos/{$c->id}/documentos/{$doc->id}")->assertNotFound();
        $this->actingAs($adminAjeno)->get("/candidatos/{$c->id}")->assertNotFound();

        // Eliminar la ficha borra los archivos
        $this->actingAs($this->rh)->delete("/candidatos/{$c->id}")->assertRedirect('/candidatos');
        Storage::disk('local')->assertMissing($doc->ruta);
        $this->assertSame(0, $this->enEmpresa(fn () => Candidato::count()));
    }

    public function test_alcance_de_sede_y_aislamiento(): void
    {
        $centro = $this->candidato();
        $playa = $this->candidato('registrado', ['sede_id' => $this->playa->id, 'nombre_completo' => 'Pedro Uicab']);
        $rhPlaya = $this->crearUsuario($this->empresa, 'Recursos Humanos');
        // Rol de RR. HH. limitado a la sede de playa
        $rol = Rol::where('empresa_id', $this->empresa->id)->where('nombre', 'Recursos Humanos')->firstOrFail();
        RolPermiso::where('rol_id', $rol->id)->update(['alcance' => Alcance::Sede]);
        UsuarioRol::where('user_id', $rhPlaya->id)->update(['sede_id' => $this->playa->id]);
        app(Autorizador::class)->olvidar();

        $this->actingAs($rhPlaya)->get('/candidatos')->assertOk()->assertSee('Pedro Uicab')->assertDontSee('Luis Chi Canul');
        $this->actingAs($rhPlaya)->get("/candidatos/{$centro->id}")->assertNotFound();
        $this->actingAs($rhPlaya)->patch("/candidatos/{$centro->id}/etapa", ['etapa' => 'revision'])->assertNotFound();
        $this->actingAs($rhPlaya)->get("/candidatos/{$playa->id}")->assertOk();

        $otra = $this->crearEmpresa('Otro Hotel');
        $adminAjeno = $this->crearUsuario($otra, 'Administrador');
        $this->actingAs($adminAjeno)->get('/candidatos')->assertOk()->assertDontSee('Luis Chi Canul');
        $this->actingAs($adminAjeno)->patch("/candidatos/{$centro->id}/etapa", ['etapa' => 'revision'])->assertNotFound();
        $this->assertSame('registrado', $centro->fresh()->etapa);
    }

    public function test_exportar_csv_con_permiso_y_sin_formulas(): void
    {
        $this->candidato('registrado', ['nombre_completo' => '=HYPERLINK("http://malo")']);
        $csv = $this->actingAs($this->rh)->get('/candidatos/exportar')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString('9981', $csv);
        $director = $this->crearUsuario($this->empresa, 'Director');
        $this->actingAs($director)->get('/candidatos/exportar')->assertForbidden();
        $this->actingAs($director)->get('/candidatos')->assertForbidden();
        $this->actingAs($director)->get('/rh/recepcion/metricas')->assertOk();
    }

    // ------------------------------------------------------------------ Kiosco

    public function test_kiosco_enlace_de_un_solo_candidato_con_privacidad_y_documentos(): void
    {
        $c = $this->candidato();
        $otro = $this->candidato('registrado', ['nombre_completo' => 'Otro Candidato']);
        $this->actingAs($this->rh)->post("/rh/recepcion/kiosco/{$c->id}")->assertRedirect("/rh/recepcion/kiosco?candidato={$c->id}");
        $enlace = $this->enEmpresa(fn () => EnlaceKiosco::sole());
        $this->assertSame(64, strlen($enlace->token_hash));
        $this->actingAs($this->rh)->get("/rh/recepcion/kiosco?candidato={$c->id}")->assertOk()->assertSee($enlace->codigo)->assertSee('<svg', false);

        // El candidato canjea el código (sin sesión)
        auth()->logout();
        $r = $this->post('/k', ['codigo' => strtolower($enlace->codigo)]);
        $r->assertRedirect();
        $url = $r->headers->get('Location');
        $this->get($url)->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')->assertSee('Luis Chi Canul')->assertDontSee('Otro Candidato')
            ->assertSee('noindex', false);

        // Sin aceptar el aviso no se guarda
        $this->post($url, $this->cvKiosco())->assertSessionHasErrors('acepta_privacidad');
        $this->assertNull($c->fresh()->privacidad_aceptada_en);

        $this->post($url, $this->cvKiosco(['acepta_privacidad' => '1', 'departamento_id' => $this->seguridad->id,
            'ine' => UploadedFile::fake()->image('ine.jpg', 500, 300), 'cv' => UploadedFile::fake()->createWithContent('cv.pdf', "%PDF-1.4\n")]))
            ->assertRedirect($url)->assertSessionHas('kiosco_enviado');
        $c->refresh();
        $this->assertTrue($c->autocaptura_pendiente);
        $this->assertSame('kiosco', $c->privacidad_medio);
        $this->assertSame('Ana Pool Canché', $c->nombre_completo);
        $this->assertSame($this->cocina->id, $c->departamento_id, 'El kiosco no cambia el departamento');
        $this->assertSame(2, $this->enEmpresa(fn () => $c->documentos()->where('origen', 'kiosco')->count()));
        $this->assertSame('Otro Candidato', $otro->fresh()->nombre_completo);
        $this->assertSame(1, $enlace->fresh()->usos);
        $this->assertTrue($this->enEmpresa(fn () => Notificacion::where('tipo', 'candidato_autocaptura')->where('user_id', $this->rh->id)->exists()));
        $this->assertTrue(Auditoria::where('evento', 'candidatos.autocaptura')->whereNull('user_id')->exists());

        // RR. HH. lo marca como revisado
        $this->actingAs($this->rh)->post("/candidatos/{$c->id}/revisado")->assertSessionHas('ok');
        $this->assertFalse($c->fresh()->autocaptura_pendiente);
    }

    public function test_kiosco_vencido_usado_revocado_o_inventado_no_sirve(): void
    {
        $c = $this->candidato();
        $this->empresa->forceFill(['preferencias' => ['recepcion' => ['kiosco_usos' => 1]]])->save();
        $r = $this->enEmpresa(fn () => app(Kiosco::class)->generar($this->rh, $c));
        $url = parse_url($r['url'], PHP_URL_PATH);

        $this->post($url, $this->cvKiosco(['acepta_privacidad' => '1']))->assertSessionHas('kiosco_enviado');
        // Ya se usó (máximo 1)
        $this->post($url, $this->cvKiosco(['acepta_privacidad' => '1', 'nombre' => 'Cambiado', 'apellido_paterno' => 'Otra Vez']))->assertNotFound();
        $this->assertSame('Ana Pool Canché', $c->fresh()->nombre_completo);

        // Vencido
        $r2 = $this->enEmpresa(fn () => app(Kiosco::class)->generar($this->rh, $c));
        $this->enEmpresa(fn () => EnlaceKiosco::whereKey($r2['enlace']->id)->update(['expira_en' => now()->subMinute()]));
        $this->get(parse_url($r2['url'], PHP_URL_PATH))->assertNotFound()->assertSee('venció');
        $this->post('/k', ['codigo' => $r2['enlace']->codigo])->assertRedirect('/k')->assertSessionHasErrors('codigo');

        // Generar otro anula el anterior
        $r3 = $this->enEmpresa(fn () => app(Kiosco::class)->generar($this->rh, $c));
        $r4 = $this->enEmpresa(fn () => app(Kiosco::class)->generar($this->rh, $c));
        $this->get(parse_url($r3['url'], PHP_URL_PATH))->assertNotFound();
        $this->get(parse_url($r4['url'], PHP_URL_PATH))->assertOk();
        // Anulado por RR. HH.
        $this->actingAs($this->rh)->post("/candidatos/{$c->id}/enlace/revocar")->assertSessionHas('ok');
        auth()->logout();
        $this->get(parse_url($r4['url'], PHP_URL_PATH))->assertNotFound();

        // Inventado
        $this->get('/k/'.str_repeat('Z', 48))->assertNotFound();
        $this->get('/k/abc')->assertNotFound();

        // Contratado o descartado: sin enlace
        $c->forceFill(['etapa' => 'descartado'])->save();
        $this->actingAs($this->rh)->post("/rh/recepcion/kiosco/{$c->id}")->assertSessionHas('error');
        // Otra empresa no genera enlaces de esta
        $adminAjeno = $this->crearUsuario($this->crearEmpresa('Otro Hotel'), 'Administrador');
        $this->actingAs($adminAjeno)->post("/rh/recepcion/kiosco/{$c->id}")->assertNotFound();
    }

    public function test_kiosco_limite_de_peticiones_y_csrf(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->post('/k', ['codigo' => 'ZZZZZ'.$i]);
        }
        $this->post('/k', ['codigo' => 'ZZZZZZ'])->assertStatus(429);

        // Sin token CSRF (fuera del modo de pruebas) se rechaza
        $c = $this->candidato();
        $r = $this->enEmpresa(fn () => app(Kiosco::class)->generar($this->rh, $c));
        $entorno = $this->app['env'];
        $this->app['env'] = 'qa';
        try {
            $this->post(parse_url($r['url'], PHP_URL_PATH), $this->cvKiosco(['acepta_privacidad' => '1']))->assertStatus(302)->assertRedirect('/login');
        } finally {
            $this->app['env'] = $entorno;
        }
        $this->assertNull($c->fresh()->privacidad_aceptada_en);
    }

    public function test_kiosco_cada_accion_lleva_su_propio_contador(): void
    {
        // En recepción todas las tabletas salen por la misma IP
        $uno = parse_url($this->enEmpresa(fn () => app(Kiosco::class)->generar($this->rh, $this->candidato()))['url'], PHP_URL_PATH);
        $dos = parse_url($this->enEmpresa(fn () => app(Kiosco::class)->generar($this->rh, $this->candidato()))['url'], PHP_URL_PATH);

        // Agotar «canjear código» no bloquea abrir ni guardar
        for ($i = 0; $i < 10; $i++) {
            $this->post('/k', ['codigo' => 'ZZZZZ'.$i])->assertStatus(302);
        }
        $this->post('/k', ['codigo' => 'ZZZZZZ'])->assertStatus(429);
        $this->get('/k')->assertOk();
        $this->get($uno)->assertOk();
        $this->post($uno, [])->assertStatus(302);

        // Agotar «guardar» en una tableta no bloquea la de otro candidato ni abrir
        for ($i = 0; $i < 9; $i++) {
            $this->post($uno, [])->assertStatus(302);
        }
        $this->post($uno, [])->assertStatus(429);
        $this->post($dos, [])->assertStatus(302);
        $this->get($dos)->assertOk();
        $this->get('/k')->assertOk();
    }

    // ------------------------------------------------------------------ Autorizaciones

    public function test_visita_espera_autorizacion_del_responsable_y_la_caseta_ve_la_respuesta(): void
    {
        Mail::fake();
        $this->configurarCorreo();
        $this->visitasConAutorizacion();
        $this->responsable($this->seguridad, $this->jefe);

        $this->registrarEnCaseta(['nombre' => 'Ingrid Solís', 'motivo_visita' => 'departamento', 'departamento_id' => $this->seguridad->id, 'es_candidato' => null])
            ->assertRedirect('/accesos?pestana=pendientes')->assertSessionHas('ok', fn ($m) => str_contains($m, 'ESPERANDO AUTORIZACIÓN'));
        $acceso = $this->enEmpresa(fn () => Acceso::sole());
        $this->assertSame('pendiente', $acceso->estado);
        $this->assertSame('esperando', $acceso->autorizacion);
        $a = $this->enEmpresa(fn () => Autorizacion::sole());
        $this->assertSame([$this->jefe->id], $a->avisados);
        Mail::assertSent(AvisoRecepcion::class, fn ($m) => $m->hasTo($this->jefe->email) && count($m->botones) === 2 && str_contains($m->botones[0][1], 'signature='));

        // La caseta ve «ESPERANDO AUTORIZACIÓN» y consulta el estado en vivo
        $this->actingAs($this->agente)->get('/accesos?pestana=pendientes')->assertSee('ESPERANDO AUTORIZACIÓN');
        $this->actingAs($this->agente)->getJson('/accesos/autorizaciones-estado?ids='.$acceso->id)->assertJsonPath('estados.'.$acceso->id, 'pendiente|esperando');

        // La notificación del jefe trae los botones
        $this->actingAs($this->jefe)->getJson('/notificaciones/resumen')->assertJsonPath('lista.0.acciones.0.etiqueta', 'Autorizar ingreso')
            ->assertJsonPath('lista.0.acciones.1.etiqueta', 'Rechazar');
        // Otro usuario (agente) no puede responder
        $this->actingAs($this->agente)->post("/autorizaciones/{$a->id}/responder", ['respuesta' => 'autorizar'])->assertForbidden();
        // Respuesta que no aplica a una visita
        $this->actingAs($this->jefe)->post("/autorizaciones/{$a->id}/responder", ['respuesta' => 'entrevistar'])->assertSessionHas('error');

        $this->actingAs($this->jefe)->post("/autorizaciones/{$a->id}/responder", ['respuesta' => 'autorizar', 'comentario' => 'Que pase'])->assertSessionHas('ok');
        $acceso->refresh();
        $this->assertSame('en_sitio', $acceso->estado);
        $this->assertSame('autorizada', $acceso->autorizacion);
        $this->assertSame($this->jefe->id, $acceso->autorizado_por);
        $a->refresh();
        $this->assertSame('autorizada', $a->estado);
        $this->assertSame('plataforma', $a->respuesta_medio);
        $this->assertNotNull($a->respondida_en);
        $this->actingAs($this->agente)->getJson('/accesos/autorizaciones-estado?ids='.$acceso->id)->assertJsonPath('estados.'.$acceso->id, 'en_sitio|autorizada');
        // La caseta recibe el aviso y los botones del jefe desaparecen
        $this->assertTrue($this->enEmpresa(fn () => Notificacion::where('user_id', $this->agente->id)->where('titulo', 'like', 'Ingreso autorizado%')->exists()));
        $this->actingAs($this->jefe)->getJson('/notificaciones/resumen')->assertJsonPath('lista.0.acciones', []);
        // Dos veces no
        $this->actingAs($this->jefe)->post("/autorizaciones/{$a->id}/responder", ['respuesta' => 'rechazar'])->assertSessionHas('error');
    }

    public function test_visita_rechazada_libera_y_cierra_y_sin_ajuste_o_sin_responsable_entra_directo(): void
    {
        $this->visitasConAutorizacion();
        $this->responsable($this->seguridad, $this->jefe);
        $this->registrarEnCaseta(['nombre' => 'Iván Torres', 'motivo_visita' => 'departamento', 'departamento_id' => $this->seguridad->id, 'es_candidato' => null]);
        $a = $this->enEmpresa(fn () => Autorizacion::sole());
        $this->actingAs($this->jefe)->post("/autorizaciones/{$a->id}/responder", ['respuesta' => 'rechazar'])->assertSessionHas('ok');
        $acceso = $this->enEmpresa(fn () => Acceso::sole());
        $this->assertSame('finalizado', $acceso->estado);
        $this->assertSame('rechazada', $acceso->autorizacion);
        $this->actingAs($this->agente)->get('/accesos?pestana=historial')->assertSee('NO AUTORIZADO');

        // Departamento sin responsable: entra como siempre
        $this->registrarEnCaseta(['nombre' => 'Elena Vargas', 'motivo_visita' => 'departamento', 'departamento_id' => $this->cocina->id, 'es_candidato' => null]);
        $this->assertSame('en_sitio', $this->enEmpresa(fn () => Acceso::where('nombre', 'ELENA VARGAS')->value('estado')));
        // Ajuste apagado: entra como siempre
        $this->visitasConAutorizacion(false);
        $this->registrarEnCaseta(['nombre' => 'Óscar Méndez', 'motivo_visita' => 'departamento', 'departamento_id' => $this->seguridad->id, 'es_candidato' => null]);
        $this->assertSame('en_sitio', $this->enEmpresa(fn () => Acceso::where('nombre', 'ÓSCAR MÉNDEZ')->value('estado')));
        // Visita a departamento sin elegirlo
        $this->registrarEnCaseta(['nombre' => 'Sin Depto', 'motivo_visita' => 'departamento', 'departamento_id' => null, 'es_candidato' => null, '_dialogo' => 'ingreso'])
            ->assertSessionHasErrors('departamento_id');
    }

    public function test_la_caseta_puede_confirmar_por_su_cuenta_y_la_solicitud_queda_respondida(): void
    {
        $this->visitasConAutorizacion();
        $this->responsable($this->seguridad, $this->jefe);
        $this->registrarEnCaseta(['nombre' => 'Raúl Herrera', 'motivo_visita' => 'departamento', 'departamento_id' => $this->seguridad->id, 'es_candidato' => null]);
        $acceso = $this->enEmpresa(fn () => Acceso::sole());
        $supervisor = $this->crearUsuario($this->empresa, 'Supervisor', $this->centro);
        $this->actingAs($supervisor)->patch("/accesos/{$acceso->id}/autorizar")->assertSessionHas('ok');
        $a = $this->enEmpresa(fn () => Autorizacion::sole());
        $this->assertSame('autorizada', $a->estado);
        $this->assertSame('caseta', $a->respuesta_medio);
        $this->assertSame('autorizada', $acceso->fresh()->autorizacion);
    }

    public function test_candidato_aprobado_avisa_al_departamento_que_responde_con_resumen(): void
    {
        Mail::fake();
        $this->configurarCorreo();
        $chef = $this->crearUsuario($this->empresa, 'Supervisor', $this->centro);
        $this->responsable($this->cocina, $chef);
        $c = $this->candidato('revision', ['escolaridad' => [['nivel' => 'tecnico']], 'experiencia' => [['empresa' => 'Hotel Sol', 'anos' => 4]], 'telefono' => '9980000000']);

        $this->actingAs($this->rh)->patch("/candidatos/{$c->id}/etapa", ['etapa' => 'aprobado_rh'])->assertSessionHas('ok');
        $a = $this->enEmpresa(fn () => Autorizacion::sole());
        $this->assertSame('candidato', $a->tipo);
        $this->assertNotNull($c->fresh()->enviado_departamento_en);
        Mail::assertSent(AvisoRecepcion::class, fn ($m) => $m->hasTo($chef->email) && str_contains(implode(' ', $m->lineas), 'Carrera técnica'));

        // El responsable ve el resumen, sin teléfono ni documentos, y no la ficha completa
        $this->actingAs($chef)->get("/autorizaciones/{$a->id}")->assertOk()->assertSee('Carrera técnica')->assertSee('Bajar a entrevistar')->assertDontSee('9980000000');
        $this->actingAs($chef)->get("/candidatos/{$c->id}")->assertForbidden();

        // Botón del correo: dirección firmada que pide sesión y confirmación
        $firmada = URL::temporarySignedRoute('autorizaciones.confirmar', now()->addDay(), ['autorizacion' => $a->id, 'respuesta' => 'entrevistar']);
        auth()->logout();
        $this->get($firmada)->assertRedirect('/login');
        $this->actingAs($chef)->get($firmada)->assertOk()->assertSee('Confirmar: Bajar a entrevistar');
        $this->actingAs($chef)->get(str_replace('entrevistar', 'rechazar', $firmada))->assertForbidden(); // alterada
        $this->actingAs($chef)->get("/autorizaciones/{$a->id}/confirmar?respuesta=entrevistar")->assertForbidden(); // sin firma

        $this->actingAs($chef)->post("/autorizaciones/{$a->id}/responder", ['respuesta' => 'entrevistar', 'medio' => 'correo', 'comentario' => 'Mañana 10:00'])->assertSessionHas('ok');
        $c->refresh();
        $this->assertSame('entrevista', $c->etapa);
        $this->assertNotNull($c->respuesta_departamento_en);
        $this->assertNotNull($c->entrevista_en);
        $this->assertSame('correo', $a->fresh()->respuesta_medio);
        $this->assertTrue($this->enEmpresa(fn () => Notificacion::where('user_id', $this->rh->id)->where('titulo', 'like', 'Bajar a entrevistar%')->exists()));

        // Rechazo del departamento: queda en cartera
        $otro = $this->candidato('revision', ['nombre_completo' => 'Otro Candidato']);
        $this->actingAs($this->rh)->patch("/candidatos/{$otro->id}/etapa", ['etapa' => 'aprobado_rh']);
        $a2 = $this->enEmpresa(fn () => Autorizacion::where('candidato_id', $otro->id)->sole());
        $this->actingAs($chef)->post("/autorizaciones/{$a2->id}/responder", ['respuesta' => 'rechazar'])->assertSessionHas('ok');
        $this->assertSame('cartera', $otro->fresh()->etapa);

        // Si RR. HH. decide otra cosa antes de la respuesta, la solicitud se cancela
        $tercero = $this->candidato('revision', ['nombre_completo' => 'Tercer Candidato']);
        $this->actingAs($this->rh)->patch("/candidatos/{$tercero->id}/etapa", ['etapa' => 'aprobado_rh']);
        $this->actingAs($this->rh)->patch("/candidatos/{$tercero->id}/etapa", ['etapa' => 'descartado', 'comentario' => 'Retiró su solicitud']);
        $a3 = $this->enEmpresa(fn () => Autorizacion::where('candidato_id', $tercero->id)->sole());
        $this->assertSame('cancelada', $a3->estado);
        $this->actingAs($chef)->post("/autorizaciones/{$a3->id}/responder", ['respuesta' => 'entrevistar'])->assertSessionHas('error');
    }

    public function test_delegacion_desvia_los_avisos_y_el_delegado_responde(): void
    {
        $this->visitasConAutorizacion();
        $this->responsable($this->seguridad, $this->jefe);
        $supervisor = $this->crearUsuario($this->empresa, 'Supervisor', $this->centro);

        // El jefe delega (fechas en hora local)
        $zona = app(HoraLocal::class);
        $this->actingAs($this->jefe)->post('/autorizaciones/delegaciones', ['delegado_id' => $this->jefe->id, 'desde' => now()->format('Y-m-d\TH:i'), 'hasta' => now()->addDay()->format('Y-m-d\TH:i')])
            ->assertSessionHasErrors('delegado_id');
        $this->actingAs($this->jefe)->post('/autorizaciones/delegaciones', ['delegado_id' => $supervisor->id, 'desde' => '2026-01-01T10:00', 'hasta' => '2026-01-02T10:00'])
            ->assertSessionHasErrors('hasta');
        $this->actingAs($this->agente)->post('/autorizaciones/delegaciones', ['delegado_id' => $supervisor->id])->assertForbidden();
        $this->actingAs($this->jefe)->post('/autorizaciones/delegaciones', ['delegado_id' => $supervisor->id, 'motivo' => 'Vacaciones',
            'desde' => now()->setTimezone('America/Mexico_City')->subHour()->format('Y-m-d\TH:i'), 'hasta' => now()->setTimezone('America/Mexico_City')->addDays(2)->format('Y-m-d\TH:i')])
            ->assertSessionHas('ok');
        $d = $this->enEmpresa(fn () => Delegacion::sole());
        $this->assertSame('activa', $d->estado());
        $this->assertTrue(Auditoria::where('evento', 'autorizaciones.delegacion_creada')->exists());
        $this->assertTrue($this->enEmpresa(fn () => Notificacion::where('user_id', $supervisor->id)->where('tipo', 'delegacion')->exists()));

        // El aviso va al delegado, no al jefe
        $this->registrarEnCaseta(['nombre' => 'Lucía Gamboa', 'motivo_visita' => 'departamento', 'departamento_id' => $this->seguridad->id, 'es_candidato' => null]);
        $a = $this->enEmpresa(fn () => Autorizacion::sole());
        $this->assertSame([$supervisor->id], $a->avisados);
        $this->actingAs($supervisor)->get('/autorizaciones')->assertSee('LUCÍA GAMBOA');
        $this->actingAs($supervisor)->post("/autorizaciones/{$a->id}/responder", ['respuesta' => 'autorizar'])->assertSessionHas('ok');
        $this->assertSame($supervisor->id, $a->fresh()->respondida_por);

        // Cancelada: el siguiente aviso vuelve al jefe
        $this->actingAs($supervisor)->patch("/autorizaciones/delegaciones/{$d->id}/cancelar")->assertNotFound(); // no es suya
        $this->actingAs($this->jefe)->patch("/autorizaciones/delegaciones/{$d->id}/cancelar")->assertSessionHas('ok');
        $this->assertTrue(Auditoria::where('evento', 'autorizaciones.delegacion_cancelada')->exists());
        $this->registrarEnCaseta(['nombre' => 'Raúl Pool', 'motivo_visita' => 'departamento', 'departamento_id' => $this->seguridad->id, 'es_candidato' => null]);
        $this->assertSame([$this->jefe->id], $this->enEmpresa(fn () => Autorizacion::latest('id')->first()->avisados));
        $this->actingAs($this->jefe)->patch("/autorizaciones/delegaciones/{$d->id}/cancelar")->assertSessionHas('error');
    }

    public function test_responsables_por_departamento_solo_con_configurar_y_usuarios_de_la_empresa(): void
    {
        $ajeno = $this->crearUsuario($this->crearEmpresa('Otro Hotel'), 'Administrador');
        $this->actingAs($this->jefe)->put("/autorizaciones/responsables/{$this->seguridad->id}", ['responsables' => [['user_id' => $this->jefe->id]]])->assertForbidden();
        $this->actingAs($this->rh)->put("/autorizaciones/responsables/{$this->seguridad->id}", ['responsables' => [['user_id' => $ajeno->id]], '_dialogo' => 'depto-1'])
            ->assertSessionHasErrors('responsables.0.user_id');
        $this->actingAs($this->rh)->put("/autorizaciones/responsables/{$this->seguridad->id}", ['responsables' => [['user_id' => $this->agente->id]]])
            ->assertSessionHasErrors('responsables.0.user_id'); // el agente no puede responder
        $this->actingAs($this->rh)->put("/autorizaciones/responsables/{$this->seguridad->id}", ['responsables' => [
            ['user_id' => $this->jefe->id, 'sede_id' => '', 'es_suplente' => '0'], ['user_id' => $this->admin->id, 'sede_id' => $this->centro->id, 'es_suplente' => '1'], ['user_id' => ''],
        ]])->assertSessionHas('ok');
        $this->assertSame(2, $this->enEmpresa(fn () => DepartamentoResponsable::count()));
        $this->actingAs($this->rh)->get('/autorizaciones/responsables')->assertOk()->assertSee('Suplente');
        $this->actingAs($ajeno)->put("/autorizaciones/responsables/{$this->seguridad->id}", ['responsables' => []])->assertNotFound();
        $this->assertSame(2, $this->enEmpresa(fn () => DepartamentoResponsable::count()));
        $this->assertTrue(Auditoria::where('evento', 'autorizaciones.responsables')->exists());
    }

    public function test_metricas_de_espera_por_departamento_y_tiempos_sla(): void
    {
        $this->responsable($this->seguridad, $this->jefe);
        $this->visitasConAutorizacion();
        $this->registrarEnCaseta(['nombre' => 'Elena Mex', 'motivo_visita' => 'departamento', 'departamento_id' => $this->seguridad->id, 'es_candidato' => null]);
        $a = $this->enEmpresa(fn () => Autorizacion::sole());
        $this->enEmpresa(fn () => Autorizacion::whereKey($a->id)->update(['solicitada_en' => now()->subMinutes(12)]));
        $this->actingAs($this->jefe)->post("/autorizaciones/{$a->id}/responder", ['respuesta' => 'autorizar']);
        $this->assertSame(12, $a->fresh()->minutosEspera());

        $this->actingAs($this->rh)->get('/rh/recepcion/metricas')->assertOk()->assertSee('Seguridad')->assertSee('12 min');
        $this->actingAs($this->agente)->get('/rh/recepcion/metricas')->assertForbidden();
    }

    public function test_notificaciones_son_de_cada_usuario(): void
    {
        $n = $this->enEmpresa(fn () => app(CentroNotificaciones::class)->avisar($this->empresa->id, [$this->rh->id], 'candidato_llegada',
            ['titulo' => 'Hola', 'url' => url('/candidatos')]));
        $id = $this->enEmpresa(fn () => Notificacion::sole()->id);
        $this->actingAs($this->agente)->post("/notificaciones/{$id}/abrir")->assertNotFound();
        $this->actingAs($this->rh)->get('/notificaciones')->assertOk()->assertSee('Hola');
        $this->actingAs($this->rh)->post("/notificaciones/{$id}/abrir")->assertRedirect(url('/candidatos'));
        $this->assertNotNull($this->enEmpresa(fn () => Notificacion::find($id)->leida_en));
        $this->enEmpresa(fn () => app(CentroNotificaciones::class)->avisar($this->empresa->id, [$this->rh->id], 'candidato_llegada', ['titulo' => 'Otra', 'url' => 'https://malo.example/x']));
        $otra = $this->enEmpresa(fn () => Notificacion::latest('id')->first()->id);
        $this->actingAs($this->rh)->post("/notificaciones/{$otra}/abrir")->assertRedirect('/notificaciones'); // nunca a otro sitio
        $this->actingAs($this->rh)->post('/notificaciones/leer-todas')->assertSessionHas('ok');
        $this->actingAs($this->rh)->getJson('/notificaciones/resumen')->assertJsonPath('no_leidas', 0);
        $this->assertSame([$this->rh->id], $n);
    }

    public function test_plantillas_de_rol(): void
    {
        $permisos = fn (string $rol) => RolPermiso::whereHas('rol', fn ($q) => $q->whereNull('empresa_id')->where('nombre', $rol))
            ->with('moduloAccion.modulo', 'moduloAccion.accion')->get()->map(fn ($p) => $p->moduloAccion->modulo->clave.'.'.$p->moduloAccion->accion->clave)->all();
        $rh = $permisos('Recursos Humanos');
        foreach (['candidatos.ver', 'candidatos.contratar', 'candidatos.configurar', 'recepcion_rh.ver', 'autorizaciones.configurar', 'autorizaciones.responder'] as $p) {
            $this->assertContains($p, $rh);
        }
        $this->assertNotContains('candidatos.ver', $permisos('Director'));
        $this->assertContains('recepcion_rh.ver', $permisos('Director'));
        $this->assertContains('autorizaciones.responder', $permisos('Jefe de seguridad'));
        $this->assertNotContains('candidatos.ver', $permisos('Jefe de seguridad'));
        $agente = $permisos('Agente');
        $this->assertSame([], array_values(array_filter($agente, fn ($p) => str_starts_with($p, 'candidatos.') || str_starts_with($p, 'autorizaciones.') || str_starts_with($p, 'recepcion_rh.'))));
        $this->assertContains('accesos.crear', $agente);
    }
}
