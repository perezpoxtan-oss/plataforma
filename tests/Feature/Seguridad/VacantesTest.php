<?php

namespace Tests\Feature\Seguridad;

use App\Models\Auditoria;
use App\Models\Candidato;
use App\Models\CandidatoDocumento;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Modulo;
use App\Models\Notificacion;
use App\Models\Puesto;
use App\Models\Rol;
use App\Models\RolPermiso;
use App\Models\Sede;
use App\Models\User;
use App\Models\UsuarioRol;
use App\Models\Vacante;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use App\Services\Vacantes\AdministradorVacantes;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Vacantes y bolsa de trabajo pública (lección 36).
 */
class VacantesTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private Sede $playa;

    private User $rh;

    private User $agente;

    private Departamento $cocina;

    private Puesto $cocinero;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa('Hotel Ébano');
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->playa = $this->crearSede($this->empresa, 'PLA');
        $this->rh = $this->crearUsuario($this->empresa, 'Recursos Humanos');
        $this->agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        [$this->cocina, $this->cocinero] = $this->enEmpresa(fn () => [Departamento::create(['nombre' => 'Cocina']), Puesto::create(['nombre' => 'Cocinero', 'tipo' => Puesto::OPERATIVO])]);
    }

    // ------------------------------------------------------------------ Ayudas

    private function enEmpresa(callable $fn, ?Empresa $empresa = null): mixed
    {
        return app(Tenant::class)->conEmpresa(($empresa ?? $this->empresa)->id, $fn);
    }

    /** @return array<string, mixed> */
    private function datos(array $extra = []): array
    {
        return $extra + [
            'titulo' => 'Cocinero de línea', 'puesto_id' => $this->cocinero->id, 'departamento_id' => $this->cocina->id, 'plazas' => '2',
            'todas_las_sedes' => '0', 'sedes' => [$this->centro->id], 'tipo_contrato' => 'indeterminado', 'jornada' => 'completa',
            'sueldo_min' => '$9,000', 'sueldo_max' => '11000', 'sueldo_periodo' => 'mensual', 'descripcion' => 'Preparar platillos.',
            'requisitos' => "- Secundaria\n\nExperiencia de 1 año\n", 'prestaciones' => "Prestaciones de ley\nComedor",
            'escolaridad_minima' => 'secundaria', 'experiencia' => '1 año', 'contacto_telefono' => '998-111-2233',
        ];
    }

    private function vacante(string $estado = 'publicada', array $datos = [], ?Empresa $empresa = null, array $sedes = []): Vacante
    {
        return $this->enEmpresa(function () use ($estado, $datos, $sedes) {
            $v = new Vacante($datos + ['titulo' => 'Camarista '.uniqid(), 'plazas' => 1]);
            $v->forceFill(['codigo' => strtolower(substr(str_replace('.', '', uniqid('', true)), -10)), 'estado' => $estado])->save();
            $v->sedes()->sync($sedes ?: [$this->centro->id]);

            return $v;
        }, $empresa);
    }

    private function encenderBolsa(?Empresa $empresa = null, ?User $quien = null, bool $indexar = false): string
    {
        $empresa ??= $this->empresa;
        $this->actingAs($quien ?? $this->rh)->put('/vacantes/bolsa', ['bolsa_activa' => '1', 'bolsa_presentacion' => 'Únete al equipo', 'bolsa_indexar' => $indexar ? '1' : '0'])
            ->assertSessionHas('ok');
        auth()->logout();

        return (string) $empresa->refresh()->bolsa_slug;
    }

    private function firma(): string
    {
        $img = imagecreatetruecolor(600, 200);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imageline($img, 40, 150, 560, 40, imagecolorallocate($img, 0, 0, 0));
        ob_start();
        imagejpeg($img, null, 70);

        return 'data:image/jpeg;base64,'.base64_encode((string) ob_get_clean());
    }

    /** @return array<string, mixed> */
    private function solicitud(array $extra = []): array
    {
        return $extra + [
            'nombre' => 'Daniela', 'apellido_paterno' => 'Mex', 'apellido_materno' => 'Pech', 'telefono' => '9981230001', 'curp' => 'MEPD970815MYNXCN05',
            'calle_numero' => 'Calle 20', 'codigo_postal' => '77517', 'emergencia_nombre' => 'Rosa Pech', 'emergencia_telefono' => '9984440000',
            'referencias' => [['nombre' => 'Teresa Can', 'telefono' => '9981110001'], ['nombre' => 'Pedro Dzul', 'telefono' => '9981110002']],
            'acepta_privacidad' => '1', 'declaracion' => '1', 'firma' => $this->firma(),
        ];
    }

    // ------------------------------------------------------------------ Alta, edición y unicidad

    public function test_rh_crea_y_edita_con_validaciones_en_espanol_y_titulo_unico(): void
    {
        $this->actingAs($this->rh)->post('/vacantes', ['_dialogo' => 'vacante', 'plazas' => '0', 'sedes' => [], 'sueldo_min' => 'mucho', 'contacto_telefono' => '12'])
            ->assertSessionHasErrors(['titulo' => 'Escribe el título de la vacante (ej. «Camarista»).', 'plazas', 'sueldo_min', 'contacto_telefono']);
        $this->actingAs($this->rh)->post('/vacantes', $this->datos(['sueldo_min' => '12000', 'sueldo_max' => '9000']))
            ->assertSessionHasErrors(['sueldo_max' => 'El sueldo máximo no puede ser menor que el mínimo.']);
        $this->actingAs($this->rh)->post('/vacantes', $this->datos(['sedes' => []]))->assertSessionHasErrors(['sedes' => 'Marca al menos una sede o «Todas las sedes».']);
        $this->actingAs($this->rh)->post('/vacantes', $this->datos(['fecha_publicacion' => '2026-11-10', 'fecha_cierre' => '2026-11-01']))
            ->assertSessionHasErrors(['fecha_cierre' => 'La fecha de cierre no puede ser antes de la de publicación.']);
        $ajena = $this->crearSede($this->crearEmpresa('Otro Hotel'), 'OTR');
        $this->actingAs($this->rh)->post('/vacantes', $this->datos(['sedes' => [$ajena->id]]))->assertSessionHasErrors(['sedes' => 'Elige sedes activas de la lista.']);
        // El error se muestra dentro del diálogo
        $this->actingAs($this->rh)->from('/vacantes')->post('/vacantes', ['_dialogo' => 'vacante'])->assertRedirect('/vacantes');
        $this->actingAs($this->rh)->get('/vacantes')->assertSee('data-abrir-al-cargar', false)->assertSee('Escribe el título de la vacante');
        $this->assertSame(0, $this->enEmpresa(fn () => Vacante::count()));

        $this->actingAs($this->rh)->post('/vacantes', $this->datos())->assertRedirect('/vacantes')->assertSessionHas('ok');
        $v = $this->enEmpresa(fn () => Vacante::sole());
        $this->assertSame('borrador', $v->estado);
        $this->assertSame(['Secundaria', 'Experiencia de 1 año'], $v->requisitos);
        $this->assertSame('9000.00', $v->sueldo_min);
        $this->assertSame('9981112233', $v->contacto_telefono);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{10}$/', $v->codigo);
        $this->assertSame([$this->centro->id], $v->sedes()->pluck('sedes.id')->all());
        $this->assertSame('$9,000 a $11,000 al mes', $v->sueldoTexto());
        $this->assertTrue(Auditoria::where('evento', 'vacantes.creado')->exists());

        // No hay dos vacantes abiertas con el mismo título (sin importar mayúsculas)
        $this->actingAs($this->rh)->post('/vacantes', $this->datos(['titulo' => 'COCINERO DE LÍNEA']))
            ->assertSessionHasErrors(['titulo' => 'Ya hay una vacante abierta con ese título. Edítala (por ejemplo, sube las plazas) o cierra la anterior.']);

        // Guardar y publicar de una vez
        $this->actingAs($this->rh)->post('/vacantes', $this->datos(['titulo' => 'Mesero', 'publicar' => '1']))->assertSessionHas('ok');
        $this->assertSame('publicada', $this->enEmpresa(fn () => Vacante::where('titulo', 'Mesero')->value('estado')));

        // Editar
        $this->actingAs($this->rh)->put("/vacantes/{$v->id}", $this->datos(['plazas' => '5', 'todas_las_sedes' => '1', 'sedes' => []]))->assertSessionHas('ok');
        $v->refresh();
        $this->assertSame(5, $v->plazas);
        $this->assertTrue($v->todas_las_sedes);
        $this->assertTrue(Auditoria::where('evento', 'vacantes.actualizado')->exists());
        $this->actingAs($this->rh)->get('/vacantes')->assertOk()->assertSee('Cocinero de línea')->assertSee('Creado por')->assertSee('Todas las sedes');
    }

    public function test_el_agente_consulta_lo_publicado_y_no_administra(): void
    {
        $publicada = $this->vacante('publicada', ['titulo' => 'Camarista']);
        $borrador = $this->vacante('borrador', ['titulo' => 'Jardinero']);
        $playa = $this->vacante('publicada', ['titulo' => 'Salvavidas'], null, [$this->playa->id]);

        $this->actingAs($this->agente)->get('/vacantes')->assertOk()->assertSee('Camarista')->assertDontSee('Jardinero')->assertDontSee('Salvavidas')
            ->assertDontSee('Nueva vacante')->assertDontSee('data-accion="editar-registro"', false);
        $this->actingAs($this->agente)->get("/vacantes/{$publicada->id}/cartel")->assertOk();
        $this->actingAs($this->agente)->get("/vacantes/{$borrador->id}/cartel")->assertNotFound();
        $this->actingAs($this->agente)->get("/vacantes/{$playa->id}/cartel")->assertNotFound();
        $this->actingAs($this->agente)->post('/vacantes', $this->datos())->assertForbidden();
        $this->actingAs($this->agente)->put("/vacantes/{$publicada->id}", $this->datos())->assertForbidden();
        $this->actingAs($this->agente)->patch("/vacantes/{$publicada->id}/estado", ['estado' => 'pausada'])->assertForbidden();
        $this->actingAs($this->agente)->delete("/vacantes/{$publicada->id}")->assertForbidden();
        $this->actingAs($this->agente)->put('/vacantes/bolsa', ['bolsa_activa' => '1'])->assertForbidden();
        $this->assertSame('publicada', $publicada->fresh()->estado);

        // El Director sí administra (sin configurar la bolsa)
        $director = $this->crearUsuario($this->empresa, 'Director');
        $this->actingAs($director)->post('/vacantes', $this->datos())->assertSessionHas('ok');
        $this->actingAs($director)->put('/vacantes/bolsa', ['bolsa_activa' => '1'])->assertForbidden();
    }

    // ------------------------------------------------------------------ Estados

    public function test_cada_transicion_de_estado_y_las_prohibidas(): void
    {
        $this->actingAs($this->rh)->post('/vacantes', $this->datos());
        $v = $this->enEmpresa(fn () => Vacante::sole());
        $estado = fn (array $d) => $this->actingAs($this->rh)->patch("/vacantes/{$v->id}/estado", $d);

        // Borrador → Pausada: no
        $estado(['estado' => 'pausada'])->assertSessionHas('error', 'Una vacante «Borrador» no puede pasar a «Pausada».');
        // Borrador → Cerrada solo como cancelada
        $estado(['estado' => 'cerrada', 'cierre_motivo' => 'cubierta'])->assertSessionHasErrors(['cierre_motivo' => 'Un borrador nunca se publicó: solo se puede cancelar.']);
        // Publicar con fecha de cierre vencida: no
        $v->forceFill(['fecha_cierre' => now()->subDays(2)])->save();
        $estado(['estado' => 'publicada'])->assertSessionHasErrors(['estado' => 'La fecha de cierre ya pasó: cámbiala en «Editar» antes de publicar.']);
        $v->forceFill(['fecha_cierre' => now()->addDays(10)])->save();

        $estado(['estado' => 'publicada'])->assertSessionHas('ok');
        $v->refresh();
        $this->assertSame('publicada', $v->estado);
        $this->assertNotNull($v->publicada_en);
        $this->assertNotNull($v->fecha_publicacion, 'Sin fecha de publicación se toma la de hoy');
        $this->assertTrue(Auditoria::where('evento', 'vacantes.publicada')->exists());

        $estado(['estado' => 'borrador'])->assertSessionHas('error');
        $estado(['estado' => 'pausada'])->assertSessionHas('ok');
        $this->assertSame('pausada', $v->fresh()->estado);
        $estado(['estado' => 'publicada'])->assertSessionHas('ok');
        $this->assertTrue(Auditoria::where('evento', 'vacantes.reanudada')->exists());

        // Cerrar pide el motivo
        $estado(['estado' => 'cerrada'])->assertSessionHasErrors(['cierre_motivo' => 'Indica si la vacante se cubrió o se canceló.']);
        $estado(['estado' => 'cerrada', 'cierre_motivo' => 'cubierta'])->assertSessionHas('ok');
        $v->refresh();
        $this->assertSame(['cerrada', 'cubierta'], [$v->estado, $v->cierre_motivo]);
        $this->assertNotNull($v->cerrada_en);
        $this->assertSame('Cerrada · cubierta', $v->etiquetaEstado());

        $estado(['estado' => 'publicada'])->assertSessionHas('error', 'Una vacante «Cerrada · cubierta» no puede pasar a «Publicada».');
        $estado(['estado' => 'pausada'])->assertSessionHas('error');
        $estado(['estado' => 'inventado'])->assertSessionHasErrors('estado');
        $estado(['estado' => 'borrador'])->assertSessionHas('ok');
        $v->refresh();
        $this->assertSame('borrador', $v->estado);
        $this->assertNull($v->cierre_motivo);
        $this->assertTrue(Auditoria::where('evento', 'vacantes.reabierta')->exists());

        // Dos personas a la vez: el segundo recibe aviso
        $vieja = $this->enEmpresa(fn () => Vacante::find($v->id));
        $estado(['estado' => 'publicada'])->assertSessionHas('ok');
        $this->expectExceptionMessage('Otra persona acaba de cambiar esta vacante');
        $this->enEmpresa(fn () => app(AdministradorVacantes::class)->cambiarEstado($this->rh, $vieja, 'publicada', null, now()->format('Y-m-d')));
    }

    public function test_eliminar_solo_sin_candidatos(): void
    {
        $v = $this->vacante('borrador');
        $con = $this->vacante('publicada');
        $this->enEmpresa(function () use ($con) {
            $c = new Candidato(['sede_id' => $this->centro->id, 'nombre_completo' => 'Ana Pool Canché', 'origen' => 'web']);
            $c->forceFill(['vacante_id' => $con->id])->save();
        });

        $this->actingAs($this->rh)->delete("/vacantes/{$con->id}")->assertSessionHas('error', 'Tiene 1 candidato(s): ciérrala en lugar de eliminarla (así se conserva su historia).');
        $this->actingAs($this->rh)->delete("/vacantes/{$v->id}")->assertSessionHas('ok');
        $this->assertNull(Vacante::withoutGlobalScopes()->find($v->id));
        $this->assertTrue(Auditoria::where('evento', 'vacantes.eliminado')->exists());
    }

    // ------------------------------------------------------------------ Aislamiento y alcance de sede

    public function test_aislamiento_y_alcance_de_sede(): void
    {
        $centro = $this->vacante('publicada', ['titulo' => 'Camarista Centro']);
        $playa = $this->vacante('publicada', ['titulo' => 'Salvavidas Playa'], null, [$this->playa->id]);
        $todas = $this->vacante('publicada', ['titulo' => 'Chofer General', 'todas_las_sedes' => true], null, [$this->centro->id]);

        // RR. HH. de la sede de playa
        $rhPlaya = $this->crearUsuario($this->empresa, 'Recursos Humanos');
        $rol = Rol::where('empresa_id', $this->empresa->id)->where('nombre', 'Recursos Humanos')->firstOrFail();
        RolPermiso::where('rol_id', $rol->id)->update(['alcance' => Alcance::Sede]);
        UsuarioRol::where('user_id', $rhPlaya->id)->update(['sede_id' => $this->playa->id]);
        app(Autorizador::class)->olvidar();

        $this->actingAs($rhPlaya)->get('/vacantes')->assertOk()->assertSee('Salvavidas Playa')->assertSee('Chofer General')->assertDontSee('Camarista Centro');
        $this->actingAs($rhPlaya)->put("/vacantes/{$centro->id}", $this->datos())->assertNotFound();
        $this->actingAs($rhPlaya)->patch("/vacantes/{$centro->id}/estado", ['estado' => 'pausada'])->assertNotFound();
        // «Todas las sedes» la ve, pero solo la modifica quien tiene alcance de empresa
        $this->actingAs($rhPlaya)->patch("/vacantes/{$todas->id}/estado", ['estado' => 'pausada'])
            ->assertSessionHas('error', 'Esta vacante también es de otras sedes: solo la modifica quien tiene alcance de empresa.');
        $this->actingAs($rhPlaya)->patch("/vacantes/{$playa->id}/estado", ['estado' => 'pausada'])->assertSessionHas('ok');
        // Crea solo en su sede y sin «todas»
        $this->actingAs($rhPlaya)->post('/vacantes', $this->datos(['todas_las_sedes' => '1']))->assertSessionHasErrors('sedes');
        $this->actingAs($rhPlaya)->post('/vacantes', $this->datos(['sedes' => [$this->centro->id]]))->assertSessionHasErrors(['sedes' => 'Elige sedes activas de la lista.']);
        $this->actingAs($rhPlaya)->post('/vacantes', $this->datos(['sedes' => [$this->playa->id]]))->assertSessionHas('ok');
        // Su sede viene preseleccionada en «Nueva vacante»
        $this->actingAs($rhPlaya)->get('/vacantes')->assertSee('name="sedes[]" value="'.$this->playa->id.'" checked', false);

        // Otra empresa → 404
        $adminAjeno = $this->crearUsuario($this->crearEmpresa('Otro Hotel'), 'Administrador');
        $this->actingAs($adminAjeno)->get('/vacantes')->assertOk()->assertDontSee('Camarista Centro');
        $this->actingAs($adminAjeno)->put("/vacantes/{$centro->id}", $this->datos())->assertNotFound();
        $this->actingAs($adminAjeno)->patch("/vacantes/{$centro->id}/estado", ['estado' => 'pausada'])->assertNotFound();
        $this->actingAs($adminAjeno)->delete("/vacantes/{$centro->id}")->assertNotFound();
        $this->actingAs($adminAjeno)->get("/vacantes/{$centro->id}/cartel")->assertNotFound();
        $this->assertSame('publicada', $centro->fresh()->estado);
    }

    // ------------------------------------------------------------------ Bolsa pública

    public function test_la_bolsa_publica_solo_muestra_lo_publicado_y_vigente_de_esa_empresa(): void
    {
        $publicada = $this->vacante('publicada', ['titulo' => 'Camarista', 'fecha_cierre' => now()->addDays(5)]);
        $this->vacante('borrador', ['titulo' => 'Jardinero']);
        $this->vacante('pausada', ['titulo' => 'Bartender']);
        $this->vacante('cerrada', ['titulo' => 'Valet']);
        $vencida = $this->vacante('publicada', ['titulo' => 'Botones', 'fecha_cierre' => now()->subDays(3)]);
        $futura = $this->vacante('publicada', ['titulo' => 'Animador', 'fecha_publicacion' => now()->addDays(3)]);
        $playa = $this->vacante('publicada', ['titulo' => 'Salvavidas'], null, [$this->playa->id]);
        $otra = $this->crearEmpresa('Otro Hotel');
        $this->crearSede($otra, 'OTR');
        $ajena = $this->vacante('publicada', ['titulo' => 'Vacante Ajena'], $otra, [Sede::withoutGlobalScopes()->where('empresa_id', $otra->id)->value('id')]);

        // Apagada: 404 (igual que una dirección inventada)
        $this->get('/empleos/hotel-ebano-abc123')->assertNotFound();
        $slug = $this->encenderBolsa();
        $this->assertMatchesRegularExpression('/^hotel-ebano-[a-z0-9]{6}$/', $slug, 'Nombre + 6 caracteres al azar: no se adivina');

        $this->get("/empleos/{$slug}")->assertOk()->assertSee('Únete al equipo')->assertSee('Camarista')->assertSee('Salvavidas')
            ->assertDontSee('Jardinero')->assertDontSee('Bartender')->assertDontSee('Valet')->assertDontSee('Botones')->assertDontSee('Animador')->assertDontSee('Vacante Ajena')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive')->assertSee('noindex', false)->assertDontSee('onclick', false);
        $this->get("/empleos/{$slug}?sede={$this->playa->id}")->assertOk()->assertSee('Salvavidas')->assertDontSee('Camarista');
        $this->get("/empleos/{$slug}?q=cama")->assertOk()->assertSee('Camarista')->assertDontSee('Salvavidas');

        $this->get("/empleos/{$slug}/{$publicada->codigo}")->assertOk()->assertSee('Camarista')->assertSee('Postularme');
        $this->get("/empleos/{$slug}/{$vencida->codigo}")->assertNotFound();
        $this->get("/empleos/{$slug}/{$futura->codigo}")->assertNotFound();
        $this->get("/empleos/{$slug}/{$ajena->codigo}")->assertNotFound();
        $this->get("/empleos/{$slug}/abcdefghij")->assertNotFound();
        $this->get('/empleos/hotel-ebano-zzzzzz')->assertNotFound();

        // Indexar es opcional por empresa (el formulario nunca se indexa)
        $this->encenderBolsa(indexar: true);
        $this->get("/empleos/{$slug}")->assertOk()->assertHeaderMissing('X-Robots-Tag')->assertDontSee('noindex', false);
        $this->get("/empleos/{$slug}/{$publicada->codigo}/postular")->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow, noarchive');

        // Apagarla la quita
        $this->actingAs($this->rh)->put('/vacantes/bolsa', ['bolsa_activa' => '0'])->assertSessionHas('ok');
        auth()->logout();
        $this->get("/empleos/{$slug}")->assertNotFound();
        $this->get("/empleos/{$slug}/{$publicada->codigo}")->assertNotFound();
        $this->assertTrue(Auditoria::where('evento', 'vacantes.bolsa_configurada')->exists());
    }

    public function test_postularse_crea_el_candidato_ligado_privado_y_avisa_a_rh(): void
    {
        $v = $this->vacante('publicada', ['titulo' => 'Cocinero', 'departamento_id' => $this->cocina->id, 'puesto_id' => $this->cocinero->id], null, [$this->centro->id, $this->playa->id]);
        $slug = $this->encenderBolsa();
        $url = "/empleos/{$slug}/{$v->codigo}/postular";
        $this->get($url)->assertOk()->assertSee('Declaro que la información es verdadera')->assertSee('name="sitio_web"', false)->assertSee('name="sede_id"', false);

        // Sin firma ni declaración ni sede: no se crea nada
        $this->post($url, $this->solicitud(['firma' => '', 'declaracion' => '']))->assertSessionHasErrors(['firma', 'declaracion', 'sede_id']);
        $this->assertSame(0, $this->enEmpresa(fn () => Candidato::count()));

        $cv = UploadedFile::fake()->createWithContent('mi cv.pdf', "%PDF-1.4\n%prueba\n");
        $this->post($url, $this->solicitud(['sede_id' => $this->playa->id, 'cv' => $cv]))->assertRedirect("/empleos/{$slug}/gracias");
        $this->get("/empleos/{$slug}/gracias")->assertOk()->assertSee('Recibimos tu solicitud');

        $c = $this->enEmpresa(fn () => Candidato::sole());
        $this->assertSame(['web', 'registrado', $v->id, $this->playa->id], [$c->origen, $c->etapa, $c->vacante_id, $c->sede_id]);
        $this->assertSame([$this->cocina->id, $this->cocinero->id], [$c->departamento_id, $c->puesto_id]);
        $this->assertTrue($c->autocaptura_pendiente);
        $this->assertSame('web', $c->privacidad_medio);
        $this->assertSame('web', $c->firma_medio);
        $this->assertSame('bolsa_web', $c->medio_vacante);
        $this->assertSame('Daniela Mex Pech', $c->nombre_completo);
        $this->assertNull($c->persona_id, 'Una postulación por internet no entra sola al Padrón de personas');
        // Firma y CV en el disco privado
        $this->assertStringStartsWith('firmas/'.$this->empresa->id.'/candidatos/', $c->firma_ruta);
        $doc = $this->enEmpresa(fn () => CandidatoDocumento::sole());
        $this->assertStringStartsWith('candidatos/'.$this->empresa->id.'/'.$c->id.'/', $doc->ruta);
        Storage::disk('local')->assertExists([$c->firma_ruta, $doc->ruta]);
        $this->assertFileDoesNotExist(public_path($doc->ruta));
        // Aviso a RR. HH. sin datos sensibles
        $aviso = $this->enEmpresa(fn () => Notificacion::where('tipo', 'candidato_postulacion')->where('user_id', $this->rh->id)->sole());
        $this->assertStringContainsString('Cocinero', $aviso->texto);
        $this->assertStringNotContainsString('MEPD970815', $aviso->titulo.$aviso->texto);
        $this->assertTrue(Auditoria::where('evento', 'candidatos.postulacion')->whereNull('user_id')->exists());

        // RR. HH. lo ve en Candidatos filtrado por la vacante y en su ficha
        $this->actingAs($this->rh)->get("/candidatos?vacante={$v->id}")->assertOk()->assertSee('Daniela Mex Pech')->assertSee('Vacante: Cocinero');
        $this->actingAs($this->rh)->get("/candidatos/{$c->id}")->assertOk()->assertSee('Bolsa de trabajo (internet)')->assertSee('Por revisar');
        $this->actingAs($this->rh)->get('/vacantes')->assertSee('1</strong> candidato', false);
    }

    public function test_campo_trampa_limite_de_peticiones_y_csrf(): void
    {
        $v = $this->vacante('publicada', ['titulo' => 'Cocinero']);
        $slug = $this->encenderBolsa();
        $url = "/empleos/{$slug}/{$v->codigo}/postular";

        // Robot: se le contesta «gracias» sin guardar nada
        $this->post($url, $this->solicitud(['sitio_web' => 'http://spam.example']))->assertRedirect("/empleos/{$slug}/gracias");
        $this->assertSame(0, $this->enEmpresa(fn () => Candidato::count()));

        // Archivo que no es PDF ni imagen
        $this->post($url, $this->solicitud(['cv' => UploadedFile::fake()->create('virus.exe', 10)]))->assertSessionHasErrors(['cv' => 'Sube tu CV en PDF o como foto (JPG o PNG).']);
        $this->post($url, $this->solicitud(['cv' => UploadedFile::fake()->createWithContent('cv.png', '<script>alert(1)</script>')]))->assertSessionHasErrors('cv');
        $this->assertSame(0, $this->enEmpresa(fn () => Candidato::count()));

        // Límite por equipo: 6 envíos por minuto (contador propio: ver la bolsa no lo gasta)
        for ($i = 0; $i < 10; $i++) {
            $this->get("/empleos/{$slug}");
        }
        for ($i = 0; $i < 3; $i++) {
            $this->post($url, ['nombre' => 'X']);
        }
        $this->post($url, ['nombre' => 'X'])->assertStatus(429);

        // Sin token CSRF (fuera del modo de pruebas) se rechaza
        $otra = $this->vacante('publicada', ['titulo' => 'Mesero']);
        $entorno = $this->app['env'];
        $this->app['env'] = 'qa';
        try {
            $this->post("/empleos/{$slug}/{$otra->codigo}/postular", $this->solicitud())->assertStatus(302)->assertRedirect('/login');
        } finally {
            $this->app['env'] = $entorno;
        }
        $this->assertSame(0, $this->enEmpresa(fn () => Candidato::count()));
    }

    // ------------------------------------------------------------------ Caseta y Candidatos

    public function test_la_caseta_elige_la_vacante_al_registrar_al_candidato(): void
    {
        $v = $this->vacante('publicada', ['titulo' => 'Cocinero', 'departamento_id' => $this->cocina->id, 'puesto_id' => $this->cocinero->id]);
        $playa = $this->vacante('publicada', ['titulo' => 'Salvavidas'], null, [$this->playa->id]);
        $borrador = $this->vacante('borrador', ['titulo' => 'Jardinero']);
        $registro = fn (array $extra) => $this->actingAs($this->agente)->post('/accesos', $extra + [
            'sede_id' => $this->centro->id, 'tipo' => 'visitante', 'nombre' => 'Karla Pérez Uc', 'motivo_visita' => 'rh',
            'viene_a' => 'busca_empleo', 'modo_arribo' => 'a_pie', 'identificacion' => 'ine',
        ]);

        // La lista del registro trae las publicadas y vigentes (la caseta las acota a su sede)
        $this->actingAs($this->agente)->get('/accesos')->assertOk()->assertSee('¿A qué viene?')->assertSee('¿A qué vacante?')->assertSee('Cocinero')->assertDontSee('Jardinero');

        $registro(['vacante_id' => $borrador->id])->assertSessionHasErrors(['vacante_id' => 'Elige una vacante publicada de esta sede.']);
        $registro(['vacante_id' => $playa->id])->assertSessionHasErrors('vacante_id');
        $registro(['vacante_id' => $v->id])->assertSessionHasNoErrors();
        $c = $this->enEmpresa(fn () => Candidato::sole());
        $this->assertSame([$v->id, $this->cocina->id, $this->cocinero->id, 'Cocinero'], [$c->vacante_id, $c->departamento_id, $c->puesto_id, $c->vacante]);
    }

    public function test_rh_liga_candidatos_a_vacantes_y_filtra_por_vacante(): void
    {
        $v = $this->vacante('publicada', ['titulo' => 'Cocinero']);
        $borrador = $this->vacante('borrador', ['titulo' => 'Jardinero']);
        [$ana, $luis] = $this->enEmpresa(fn () => [
            tap(new Candidato(['sede_id' => $this->centro->id, 'nombre_completo' => 'Ana Pool Canché', 'origen' => 'rh']))->save(),
            tap(new Candidato(['sede_id' => $this->centro->id, 'nombre_completo' => 'Luis Chi Canul', 'origen' => 'rh']))->save(),
        ]);
        $ana = $this->enEmpresa(fn () => Candidato::where('nombre_completo', 'Ana Pool Canché')->sole());
        $ana->forceFill(['privacidad_aceptada_en' => now()])->save();

        $this->actingAs($this->rh)->put("/candidatos/{$ana->id}", ['nombre_completo' => 'Ana Pool Canché', 'vacante_id' => $borrador->id])
            ->assertSessionHasErrors(['vacante_id' => 'Elige una vacante publicada (o en pausa) de la lista.']);
        $this->actingAs($this->rh)->put("/candidatos/{$ana->id}", ['nombre_completo' => 'Ana Pool Canché', 'vacante_id' => $v->id])->assertSessionHas('ok');
        $this->assertSame($v->id, $ana->fresh()->vacante_id);

        $this->actingAs($this->rh)->get("/candidatos?vacante={$v->id}")->assertOk()->assertSee('Ana Pool Canché')->assertDontSee('Luis Chi Canul');
        $this->actingAs($this->rh)->get("/candidatos/{$ana->id}")->assertSee('Vacante:')->assertSee(route('candidatos.index', ['vacante' => $v->id]), false);
        $csv = $this->actingAs($this->rh)->get('/candidatos/exportar')->assertOk()->streamedContent();
        $this->assertStringContainsString('Vacante', $csv);
        $this->assertStringContainsString('Cocinero', $csv);
        // La vacante cerrada se conserva en la ficha aunque ya no se pueda elegir
        $v->forceFill(['estado' => 'cerrada', 'cierre_motivo' => 'cubierta'])->save();
        $this->actingAs($this->rh)->put("/candidatos/{$ana->id}", ['nombre_completo' => 'Ana Pool Canché', 'vacante_id' => $v->id])->assertSessionHas('ok');
    }

    // ------------------------------------------------------------------ Cartel y compartir

    public function test_cartel_con_qr_y_enlaces_para_compartir(): void
    {
        $v = $this->vacante('publicada', ['titulo' => 'Camarista', 'sueldo_min' => 9000, 'requisitos' => ['Secundaria'], 'prestaciones' => ['Comedor']]);
        $borrador = $this->vacante('borrador', ['titulo' => 'Jardinero']);

        // Sin bolsa encendida: cartel sin QR
        $this->actingAs($this->rh)->get("/vacantes/{$v->id}/cartel")->assertOk()->assertSee('¡Estamos contratando!')->assertSee('Camarista')
            ->assertSee('el cartel sale sin QR')->assertDontSee('<svg', false);
        $slug = $this->encenderBolsa();
        $publica = route('empleos.show', [$slug, $v->codigo]);
        $this->actingAs($this->rh)->get("/vacantes/{$v->id}/cartel")->assertOk()->assertSee('<svg', false)->assertSee('Secundaria')->assertSee('Comedor')
            ->assertSee('Desde $9,000 al mes')->assertSee('data-accion="imprimir"', false);
        $this->actingAs($this->rh)->get("/vacantes/{$borrador->id}/cartel")->assertOk()->assertSee('La vacante no está publicada');

        $this->actingAs($this->rh)->get('/vacantes')->assertOk()->assertSee('data-copiar-enlace="'.$publica.'"', false)
            ->assertSee('https://wa.me/?text=', false)->assertSee(rawurlencode($publica), false)->assertSee('ENCENDIDA');
    }

    // ------------------------------------------------------------------ Plantillas y menú

    public function test_plantillas_de_rol_y_menu(): void
    {
        $permisos = fn (string $rol) => app(Autorizador::class)->permisosEfectivos($this->crearUsuario($this->empresa, $rol, in_array($rol, ['Agente', 'Supervisor'], true) ? $this->centro : null));
        $acciones = fn (string $rol) => collect($permisos($rol))->keys()->filter(fn ($k) => str_starts_with($k, 'vacantes.'))->sort()->values()->all();

        $this->assertSame(['vacantes.configurar', 'vacantes.crear', 'vacantes.editar', 'vacantes.eliminar', 'vacantes.ver'], $acciones('Recursos Humanos'));
        $this->assertSame(['vacantes.configurar', 'vacantes.crear', 'vacantes.editar', 'vacantes.eliminar', 'vacantes.ver'], $acciones('Administrador'));
        $this->assertSame(['vacantes.crear', 'vacantes.editar', 'vacantes.eliminar', 'vacantes.ver'], $acciones('Director'));
        $this->assertSame(['vacantes.ver'], $acciones('Agente'));
        $this->assertSame(['vacantes.ver'], $acciones('Jefe de seguridad'));
        $this->assertSame(Alcance::Sede, $permisos('Agente')['vacantes.ver']->alcance);

        // Menú Recursos Humanos → «Recepción y candidatos»: Vacantes primero
        $modulo = Modulo::where('clave', 'vacantes')->sole();
        $this->assertSame(['recursos_humanos', 'Recepción y candidatos', 'vacantes.index'], [$modulo->menu->clave, $modulo->seccion_menu, $modulo->ruta]);
        $this->assertLessThan(Modulo::where('clave', 'recepcion_rh')->value('orden_menu'), $modulo->orden_menu);
        $this->actingAs($this->rh)->get('/vacantes')->assertOk();
        $this->actingAs($this->crearUsuario($this->empresa, 'Administrador'))->get('/configuracion')->assertOk()->assertSee('Bolsa de trabajo en internet');
    }
}
