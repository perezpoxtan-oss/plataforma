<?php

namespace Tests\Feature\Seguridad;

use App\Mail\VoucherConCobro;
use App\Models\Auditoria;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Espacio;
use App\Models\Llave;
use App\Models\Sede;
use App\Models\User;
use App\Models\Vehiculo;
use App\Models\VoucherReposicion;
use App\Support\CorreoPlataforma;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Ronda 5 de ajustes de QA, parte 1: Llaves (cascada de lugares, nombre por
 * sede, costo variable, firmas del voucher y copias por correo), diálogo
 * "Código e identificación", lector sin Enter y aviso de sesión.
 */
class AjustesRonda5Test extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private Sede $playa;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->playa = $this->crearSede($this->empresa, 'PLA');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
    }

    private function enEmpresa(callable $fn, ?Empresa $empresa = null): mixed
    {
        return app(Tenant::class)->conEmpresa(($empresa ?? $this->empresa)->id, $fn);
    }

    private function llave(string $nombre, ?Sede $sede = null, array $extra = []): Llave
    {
        return $this->enEmpresa(fn () => Llave::create(array_merge(['sede_id' => ($sede ?? $this->centro)->id, 'nomenclatura' => $nombre,
            'descripcion' => 'Prueba', 'tipo_dispositivo' => 'metalica', 'alcance' => 'global'], $extra)));
    }

    private function colaborador(string $num = '1009'): Colaborador
    {
        return $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => $num, 'nombre' => 'Javier', 'apellido_paterno' => 'Pech', 'sede_id' => $this->centro->id]));
    }

    private function espacio(string $nivel, string $nombre, ?Espacio $padre = null, ?Sede $sede = null): Espacio
    {
        return $this->enEmpresa(fn () => Espacio::create(['sede_id' => ($sede ?? $this->centro)->id, 'nivel' => $nivel, 'nombre' => $nombre, 'padre_id' => $padre?->id]));
    }

    private function firma(): string
    {
        $img = imagecreatetruecolor(300, 100);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        imageline($img, 10, 50, 290, 60, imagecolorallocate($img, 0, 0, 0));
        ob_start();
        imagejpeg($img, null, 70);

        return 'data:image/jpeg;base64,'.base64_encode((string) ob_get_clean());
    }

    private function configurarCorreo(): void
    {
        app(CorreoPlataforma::class)->guardar(['host' => 'mail.ejemplo.com', 'puerto' => 587, 'cifrado' => 'tls', 'usuario' => 'a@ejemplo.com',
            'remitente_correo' => 'avisos@ejemplo.com', 'remitente_nombre' => 'Avisos'], 'x');
    }

    // ------------------------------------------------------ LL-03: nombre por sede

    public function test_ll03_el_nombre_de_la_llave_es_unico_por_sede(): void
    {
        $this->llave('HDC-101');

        // Misma sede: no (con el error dentro del diálogo y lo capturado)
        $this->actingAs($this->admin)->from('/llaves')->post('/llaves', ['sede_id' => $this->centro->id, 'nomenclatura' => 'hdc-101', 'descripcion' => 'Habitación',
            'tipo_dispositivo' => 'metalica', 'alcance' => 'global', '_dialogo' => 'crear'])
            ->assertSessionHasErrors(['nomenclatura' => 'Ya existe una llave con el nombre «HDC-101» en la sede Sede CEN.'])
            ->assertSessionHasInput('nomenclatura', 'hdc-101');

        // Otra sede: sí
        $this->actingAs($this->admin)->post('/llaves', ['sede_id' => $this->playa->id, 'nomenclatura' => 'hdc-101', 'descripcion' => 'Habitación',
            'tipo_dispositivo' => 'metalica', 'alcance' => 'global'])->assertSessionHasNoErrors();
        $this->assertSame(2, $this->enEmpresa(fn () => Llave::where('nomenclatura', 'HDC-101')->count()));

        // Y al editar, tampoco se puede mover a una sede donde ya existe
        $playa = $this->enEmpresa(fn () => Llave::where('sede_id', $this->playa->id)->firstOrFail());
        $this->actingAs($this->admin)->put("/llaves/{$playa->id}", ['sede_id' => $this->centro->id, 'nomenclatura' => 'HDC-101', 'descripcion' => 'X',
            'tipo_dispositivo' => 'metalica', 'alcance' => 'global'])->assertSessionHasErrors('nomenclatura');

        // El aviso en vivo revisa la sede elegida (Ronda 7: aviso único de duplicados, ver AjustesRonda7Test)
        $this->actingAs($this->admin)->get('/llaves')->assertOk()->assertSee('Nombre de la Llave (Único en su sede)')
            ->assertSee('data-duplicado="'.route('llaves.duplicado').'"', false)->assertSee('data-duplicado-con="sede_id"', false);
        $this->actingAs($this->admin)->getJson('/llaves/duplicado?campo=nomenclatura&valor=hdc-101&sede_id='.$this->centro->id)->assertJsonPath('estado', 'existe');
    }

    // ------------------------------------------------- LL-02: cascada de lugares

    public function test_ll02_alta_con_piso_y_horario_y_lugares_en_cascada(): void
    {
        $torreA = $this->espacio(Espacio::EDIFICIO, 'Torre A');
        $torreB = $this->espacio(Espacio::EDIFICIO, 'Torre B');
        $piso2 = $this->espacio(Espacio::AREA, 'Piso 2', $torreA);
        $pisoB = $this->espacio(Espacio::AREA, 'Piso 1', $torreB);
        $hab = $this->espacio(Espacio::AREA_ESPECIFICA, 'A201', $piso2);

        $pagina = $this->actingAs($this->admin)->get('/llaves')->assertOk()
            ->assertSee('1. Zona / Edificio')->assertSee('2. Piso')->assertSee('3. Cuartos / áreas')
            ->getContent();
        // Píldoras: edificios de la sede y pisos con su edificio; cada lugar sabe su edificio y su piso
        $this->assertStringContainsString('data-pildora-edificio data-sede="'.$this->centro->id.'"', $pagina);
        $this->assertStringContainsString('data-pildora-piso data-sede="'.$this->centro->id.'" data-edificio="'.$torreB->id.'"', $pagina);
        $this->assertStringContainsString('data-edificio="'.$torreA->id.'" data-piso="'.$piso2->id.'"', $pagina);
        $this->assertStringContainsString('data-edificio="'.$torreA->id.'" data-piso=""', $pagina);

        $this->actingAs($this->admin)->post('/llaves', ['sede_id' => $this->centro->id, 'nomenclatura' => 'hdc-p2-ama', 'descripcion' => 'Ama de llaves piso 2',
            'tipo_dispositivo' => 'metalica', 'alcance' => 'piso', 'espacios' => [$piso2->id],
            'horario_nombre' => ['Limpieza'], 'horario_inicio' => ['08:00'], 'horario_fin' => ['16:00']])->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->get('/llaves')->assertSee('HDC-P2-AMA')->assertSee('Piso 2 (Torre A)')->assertSee('Limpieza 08:00-16:00');
        // Un piso de otra torre no se mezcla con el alcance de cuarto
        $this->actingAs($this->admin)->post('/llaves', ['sede_id' => $this->centro->id, 'nomenclatura' => 'x', 'descripcion' => 'X',
            'tipo_dispositivo' => 'metalica', 'alcance' => 'area', 'espacios' => [$pisoB->id]])->assertSessionHasErrors('espacios');
        $this->actingAs($this->admin)->post('/llaves', ['sede_id' => $this->centro->id, 'nomenclatura' => 'y', 'descripcion' => 'Y',
            'tipo_dispositivo' => 'metalica', 'alcance' => 'area', 'espacios' => [$hab->id]])->assertSessionHasNoErrors();
    }

    // ---------------------------------------------------- Sesión: aviso a tiempo

    public function test_sesion_avisa_antes_de_cerrar_y_despues_explica_por_que(): void
    {
        $this->actingAs($this->admin)->get('/')->assertOk()
            ->assertSee('Tu sesión está por cerrarse')->assertSee('Seguir conectado')->assertSee('data-sesion-cuenta', false)
            ->assertSee('data-aviso="120"', false);

        // La sesión guardada vive más que el cierre por inactividad: así siempre se puede avisar
        $this->assertGreaterThanOrEqual((int) config('plataforma.sesion.inactividad_minutos') + 10, (int) config('session.lifetime'));

        $js = File::get(public_path('js/plataforma.js'));
        foreach (["'plataforma_ultima_actividad'", "addEventListener('visibilitychange'", 'pintarCuenta(LIMITE - inactivo)', 'REVISAR_CADA_MS = 5 * 1000'] as $pieza) {
            $this->assertStringContainsString($pieza, $js);
        }

        // Inactividad vencida: el servidor cierra y la pantalla de acceso lo explica
        $this->withSession(['ultima_actividad' => now()->subMinutes(30)->getTimestamp()])->get('/llaves')
            ->assertRedirect('/login')->assertSessionHas('acceso', 'expirado');
        $this->assertGuest();
        $this->withSession(['acceso' => 'expirado'])->get('/login')->assertSee('Sesión finalizada por seguridad.')->assertSee('minutos sin actividad');

        // Si el navegador pide el cierre cuando la sesión ya no existe (token vencido), también avisa "expirado"
        $this->post('/sesion/expirada', ['_token' => 'vencido'])->assertRedirect('/login')->assertSessionHas('acceso', 'expirado');
    }

    // ------------------------------------------- LL-04 (a): lector sin Enter

    public function test_ll04_el_lector_busca_solo_al_escribir_y_sigue_aceptando_enter(): void
    {
        $js = File::get(public_path('js/plataforma.js'));
        $this->assertStringContainsString('var ESPERA_MS = 350;', $js);
        $this->assertStringContainsString('var MINIMO = 2;', $js);
        $this->assertStringContainsString('programarBusqueda(entrada.closest(\'[data-lector]\'))', $js);
        // El Enter de los lectores tipo teclado sigue buscando al momento
        $this->assertStringContainsString("e.key !== 'Enter'", $js);

        // Lo que la búsqueda automática consulta: el número de empleado exacto
        $c = $this->colaborador('1009');
        $this->actingAs($this->admin)->getJson('/lector/resolver?entrada=1009&tipos=colaborador')->assertOk()
            ->assertJsonPath('resultados.0.id', $c->id);
    }

    // ----------------------------------- LL-05: costo fijo o variable en la baja

    public function test_ll05_costo_fijo_se_cobra_tal_cual_y_el_variable_se_captura(): void
    {
        $javier = $this->colaborador();
        $fija = $this->llave('HDC-BOD-01', null, ['costo_reposicion' => 350, 'costo_variable' => false]);
        $variable = $this->llave('HDC-BOD-02', null, ['costo_reposicion' => 350, 'costo_variable' => true]);

        $this->actingAs($this->admin)->get('/llaves')->assertSee('data-costo-fijo="350.00"', false)->assertSee('Costo variable (se captura en cada baja)');

        // Fijo: aunque manden otro monto, se cobra el de la llave
        $this->actingAs($this->admin)->post("/llaves/{$fija->id}/baja", ['motivo' => 'danado', 'aplica_cobro' => 1, 'monto' => 1, 'colaborador_id' => $javier->id])
            ->assertSessionHasNoErrors();
        // Variable: se respeta lo capturado
        $this->actingAs($this->admin)->post("/llaves/{$variable->id}/baja", ['motivo' => 'danado', 'aplica_cobro' => 1, 'monto' => 420.5, 'colaborador_id' => $javier->id])
            ->assertSessionHasNoErrors();

        $montos = VoucherReposicion::withoutGlobalScopes()->orderBy('id')->pluck('monto')->map(fn ($m) => (string) $m)->all();
        $this->assertSame(['350.00', '420.50'], $montos);

        // Validación del costo en el formulario
        $this->actingAs($this->admin)->post('/llaves', ['sede_id' => $this->centro->id, 'nomenclatura' => 'C-1', 'descripcion' => 'X',
            'tipo_dispositivo' => 'metalica', 'alcance' => 'global', 'costo_reposicion' => -5])
            ->assertSessionHasErrors(['costo_reposicion' => 'El costo de reposición no puede ser negativo.']);
        $this->actingAs($this->admin)->post('/llaves', ['sede_id' => $this->centro->id, 'nomenclatura' => 'C-1', 'descripcion' => 'X',
            'tipo_dispositivo' => 'metalica', 'alcance' => 'global', 'costo_reposicion' => '275.5', 'costo_variable' => 1])->assertSessionHasNoErrors();
        $c1 = $this->enEmpresa(fn () => Llave::where('nomenclatura', 'C-1')->firstOrFail());
        $this->assertSame(['275.50', true, null], [(string) $c1->costo_reposicion, $c1->costo_variable, $c1->costoFijo()]);
        $this->assertSame('275.50', Auditoria::where('evento', 'llaves.creado')->where('auditable_id', $c1->id)->firstOrFail()->despues['costo_reposicion']);
    }

    // ------------------------- LL-04 (b): firmas digital / física y copias por correo

    public function test_ll04_baja_con_firma_digital_guarda_privado_y_solo_se_sirve_con_permiso(): void
    {
        $javier = $this->colaborador();
        $l = $this->llave('HDC-BOD-01');

        // Digital sin firmas: no
        $this->actingAs($this->admin)->post("/llaves/{$l->id}/baja", ['motivo' => 'danado', 'aplica_cobro' => 1, 'monto' => 100, 'colaborador_id' => $javier->id, 'firma_modo' => 'digital'])
            ->assertSessionHasErrors(['firma_seguridad' => 'Falta la firma de Seguridad: firma en el recuadro o elige «Firma física».']);
        $this->actingAs($this->admin)->post("/llaves/{$l->id}/baja", ['motivo' => 'danado', 'aplica_cobro' => 1, 'monto' => 100, 'colaborador_id' => $javier->id,
            'firma_modo' => 'digital', 'firma_seguridad' => $this->firma()])
            ->assertSessionHasErrors(['firma_responsable' => 'Falta la firma del responsable: que firme en el recuadro o elige «Firma física».']);
        $this->assertTrue($l->fresh()->activo);
        $this->assertSame([], Storage::disk('local')->allFiles());

        $this->actingAs($this->admin)->post("/llaves/{$l->id}/baja", ['motivo' => 'danado', 'aplica_cobro' => 1, 'monto' => 100, 'colaborador_id' => $javier->id,
            'firma_modo' => 'digital', 'firma_seguridad' => $this->firma(), 'firma_responsable' => $this->firma()])->assertSessionHasNoErrors();

        $v = VoucherReposicion::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(['digital', 'digital'], [$v->firma_modo, $v->estadoFirma()]);
        foreach ([$v->firma_seguridad, $v->firma_responsable] as $ruta) {
            $this->assertStringStartsWith("firmas/{$this->empresa->id}/vouchers/", $ruta);
            Storage::disk('local')->assertExists($ruta);
        }

        // Se ven en la impresión y solo por el controlador
        $this->actingAs($this->admin)->get("/vouchers/{$v->id}/imprimir")->assertOk()
            ->assertSee(route('vouchers.firma', [$v->id, 'seguridad']))->assertSee(route('vouchers.firma', [$v->id, 'responsable']))
            ->assertSee('Las firmas digitales ya van impresas.');
        $this->actingAs($this->admin)->get("/vouchers/{$v->id}/firma/seguridad")->assertOk();
        $this->actingAs($this->admin)->get("/vouchers/{$v->id}/firma/hoja")->assertNotFound();

        // Otra empresa: 404; agente de otra sede: 404; agente de la sede: sí la ve
        $otra = $this->crearEmpresa('Hotel Dos');
        $this->crearSede($otra, 'X1');
        $this->actingAs($this->crearUsuario($otra, 'Administrador'))->get("/vouchers/{$v->id}/firma/seguridad")->assertNotFound();
        $this->actingAs($this->crearUsuario($this->empresa, 'Agente', $this->playa))->get("/vouchers/{$v->id}/firma/seguridad")->assertNotFound();
        $this->actingAs($this->crearUsuario($this->empresa, 'Agente', $this->centro))->get("/vouchers/{$v->id}/firma/seguridad")->assertOk();

        // Firmado digitalmente: ya no se marca "en papel"
        $this->actingAs($this->admin)->post("/vouchers/{$v->id}/papel")->assertSessionHasErrors('hoja');
        $this->actingAs($this->admin)->get('/vouchers')->assertSee('Firmado digitalmente')->assertDontSee('Registrar firma en papel');
    }

    public function test_ll04_firma_fisica_pendiente_luego_firmado_en_papel_con_hoja(): void
    {
        $l = $this->llave('HDC-BOD-01');
        $this->actingAs($this->admin)->post("/llaves/{$l->id}/baja", ['motivo' => 'extraviado', 'firma_modo' => 'fisica'])
            ->assertSessionHasNoErrors()->assertSessionHas('aviso', fn ($t) => str_contains($t, 'Imprímelo en Vouchers de reposición para firmarlo a mano.'));
        $v = VoucherReposicion::withoutGlobalScopes()->firstOrFail();
        $this->assertSame(['fisica', 'pendiente', null], [$v->firma_modo, $v->estadoFirma(), $v->firma_seguridad]);

        $this->actingAs($this->admin)->get('/vouchers')->assertSee('Firma en papel pendiente')->assertSee('Registrar firma en papel');
        $this->actingAs($this->admin)->get("/vouchers/{$v->id}/imprimir")->assertSee('Seguridad y el responsable firman cada copia a mano.');

        // El agente consulta pero no registra la firma en papel (no imprime vouchers)
        $agente = $this->crearUsuario($this->empresa, 'Agente');
        $this->actingAs($agente)->get('/vouchers')->assertOk()->assertDontSee('Registrar firma en papel');
        $this->actingAs($agente)->post("/vouchers/{$v->id}/papel")->assertForbidden();

        // Un archivo que no es imagen se rechaza dentro del diálogo
        $this->actingAs($this->admin)->post("/vouchers/{$v->id}/papel", ['hoja' => UploadedFile::fake()->create('hoja.pdf', 10, 'application/pdf'), '_dialogo' => 'papel-'.$v->id])
            ->assertSessionHasErrors(['hoja' => 'Sube la hoja como foto o imagen (JPG, PNG o WEBP).']);

        $this->actingAs($this->admin)->post("/vouchers/{$v->id}/papel", ['hoja' => UploadedFile::fake()->image('hoja.jpg', 400, 300)])
            ->assertSessionHasNoErrors()->assertSessionHas('ok', "Voucher {$v->folio}: firma en papel registrada con la hoja escaneada.");
        $v->refresh();
        $this->assertSame(['papel', $this->admin->id], [$v->estadoFirma(), $v->firmado_papel_por]);
        $this->assertStringStartsWith("firmas/{$this->empresa->id}/vouchers-hojas/", $v->hoja_firmada);
        Storage::disk('local')->assertExists($v->hoja_firmada);
        $this->actingAs($this->admin)->get("/vouchers/{$v->id}/firma/hoja")->assertOk();
        $this->actingAs($this->admin)->get('/vouchers')->assertSee('Firmado en papel')->assertSee('Ver hoja')->assertSee('Cambiar hoja firmada');
        $this->assertSame(1, Auditoria::where('evento', 'vouchers.firmado_papel')->count());

        // Cambiar la hoja: conserva la fecha de firma y borra la hoja anterior
        $fecha = $v->firmado_papel_en;
        $anterior = $v->hoja_firmada;
        $this->travel(5)->minutes();
        $this->actingAs($this->admin)->post("/vouchers/{$v->id}/papel", ['hoja' => UploadedFile::fake()->image('hoja2.png', 400, 300)])->assertSessionHasNoErrors();
        $v->refresh();
        $this->assertTrue($fecha->equalTo($v->firmado_papel_en));
        Storage::disk('local')->assertMissing($anterior);
    }

    public function test_ll04_con_cobro_las_copias_van_por_correo_a_seguridad_recepcion_y_administracion(): void
    {
        Mail::fake();
        $this->configurarCorreo();
        $javier = $this->colaborador();
        $l = $this->llave('HDC-BOD-01');

        // Configuración: una lista por copia
        $this->actingAs($this->admin)->get('/configuracion')->assertOk()->assertSee('Copia Seguridad')->assertSee('Copia Recepción')->assertSee('Copia Administración')
            ->assertSee('El colaborador responsable no recibe copia.');
        $this->actingAs($this->admin)->put('/configuracion/avisos', ['avisos' => ['voucher_cobro' => 1], 'destinatarios' => [
            'voucher_seguridad' => 'seguridad@ejemplo.com', 'voucher_recepcion' => "recepcion@ejemplo.com\nfront@ejemplo.com", 'voucher_administracion' => 'no-es-correo',
        ]])->assertSessionHasErrors('destinatarios.voucher_administracion');
        $this->actingAs($this->admin)->put('/configuracion/avisos', ['avisos' => ['voucher_cobro' => 1], 'destinatarios' => [
            'voucher_seguridad' => 'seguridad@ejemplo.com', 'voucher_recepcion' => "recepcion@ejemplo.com\nfront@ejemplo.com", 'voucher_administracion' => 'admin@ejemplo.com',
        ]])->assertSessionHasNoErrors();

        // Sin cobro: no se envía nada
        $sin = $this->llave('HDC-BOD-02');
        $this->actingAs($this->admin)->post("/llaves/{$sin->id}/baja", ['motivo' => 'extraviado'])->assertSessionHasNoErrors();
        Mail::assertNothingSent();

        $this->actingAs($this->admin)->post("/llaves/{$l->id}/baja", ['motivo' => 'danado', 'aplica_cobro' => 1, 'monto' => 350, 'colaborador_id' => $javier->id])
            ->assertSessionHasNoErrors();
        $v = VoucherReposicion::withoutGlobalScopes()->where('aplica_cobro', true)->firstOrFail();

        Mail::assertSent(VoucherConCobro::class, 3);
        Mail::assertSent(VoucherConCobro::class, fn ($m) => $m->copia === 'Copia Seguridad' && $m->hasTo('seguridad@ejemplo.com') && $m->folio === $v->folio && $m->monto === 350.0);
        Mail::assertSent(VoucherConCobro::class, fn ($m) => $m->copia === 'Copia Recepción' && $m->hasTo('recepcion@ejemplo.com') && $m->hasTo('front@ejemplo.com'));
        Mail::assertSent(VoucherConCobro::class, fn ($m) => $m->copia === 'Copia Administración' && $m->hasTo('admin@ejemplo.com') && $m->enlace === route('vouchers.imprimir', $v->id));

        // El aviso se puede apagar
        $this->actingAs($this->admin)->put('/configuracion/avisos', ['avisos' => []])->assertSessionHasNoErrors();
        $this->assertFalse($this->empresa->fresh()->aviso('voucher_cobro'));
    }

    // ------------------------------- LL-05 / VE-04: "Código e identificación"

    public function test_codigo_e_identificacion_en_las_fichas_en_lugar_de_otra_pagina(): void
    {
        $l = $this->llave('HDC-101');
        $v = $this->enEmpresa(fn () => Vehiculo::create(['placas' => 'ABC123A', 'marca' => 'NISSAN', 'modelo' => 'VERSA', 'color' => 'BLANCO', 'tipo' => 'sedan', 'propiedad' => 'colaborador']));

        $this->actingAs($this->admin)->get('/vehiculos')->assertOk()
            ->assertSee('data-ver-identificacion', false)->assertSee('id="dialogoIdentificacion"', false)->assertSee('Código e identificación')
            ->assertSee(route('identificacion.qr', ['vehiculo', $v->id]))->assertSee(route('vehiculos.calcomania', $v->id))->assertSee('Imprimir calcomanía')
            ->assertSee('Asignar etiqueta NFC / RFID')->assertDontSee('onclick');
        $this->actingAs($this->admin)->get('/llaves')->assertSee(route('identificacion.qr', ['llave', $l->id]))
            ->assertSee(route('identificacion.etiqueta', ['llave', $l->id]));
        foreach (['/gafetes', '/equipos', '/equipos-pc', '/colaboradores', '/lost-found'] as $pantalla) {
            $this->actingAs($this->admin)->get($pantalla)->assertOk()->assertSee('id="dialogoIdentificacion"', false);
        }

        // El QR se dibuja aquí (SVG) con la dirección /e/{código}
        $svg = $this->actingAs($this->admin)->get("/identificacion/llave/{$l->id}/qr")->assertOk()
            ->assertHeader('Content-Type', 'image/svg+xml')->getContent();
        $this->assertStringContainsString('<svg', $svg);
        $this->actingAs($this->admin)->get("/identificacion/desconocido/{$l->id}/qr")->assertNotFound();
        $this->actingAs($this->admin)->get('/identificacion/llave/999999/qr')->assertNotFound();
    }

    public function test_reactivar_una_llave_abre_su_codigo_e_identificacion(): void
    {
        $l = $this->llave('HDP-MANT-02', $this->playa, ['activo' => false]);
        $this->actingAs($this->admin)->patch("/llaves/{$l->id}/reactivar")->assertSessionHas('identificacion', $l->id);
        $this->assertTrue($l->fresh()->activo);

        $this->actingAs($this->admin)->withSession(['identificacion' => $l->id])->get('/llaves')->assertSee('data-abrir-identificacion', false);
        $this->actingAs($this->admin)->get('/llaves')->assertDontSee('data-abrir-identificacion', false);
    }

    public function test_asignar_etiqueta_nfc_desde_el_dialogo_con_permisos_alcance_y_unicidad(): void
    {
        $l = $this->llave('HDC-101');
        $otra = $this->llave('HDC-102');
        $v = $this->enEmpresa(fn () => Vehiculo::create(['placas' => 'ABC123A', 'marca' => 'NISSAN', 'modelo' => 'VERSA', 'color' => 'BLANCO', 'tipo' => 'sedan', 'propiedad' => 'colaborador']));

        $this->actingAs($this->admin)->putJson("/identificacion/llave/{$l->id}/etiqueta", ['etiqueta_nfc' => '04:a2:3b:1c'])->assertOk()
            ->assertJson(['ok' => true, 'etiqueta' => '04A23B1C', 'mensaje' => 'Etiqueta 04A23B1C asignada. Ya se puede leer con el lector.']);
        $this->assertSame('04A23B1C', $l->fresh()->etiqueta_nfc);
        $this->assertSame(1, Auditoria::where('evento', 'llaves.etiqueta_asignada')->count());

        // Ocupada por otra llave o por un registro de otro tipo: no
        $this->actingAs($this->admin)->putJson("/identificacion/llave/{$otra->id}/etiqueta", ['etiqueta_nfc' => '04a23b1c'])->assertUnprocessable()
            ->assertJsonPath('errors.etiqueta_nfc.0', 'Esa tarjeta o etiqueta NFC/RFID ya está asignada a la llave «HDC-101». Quítala de ahí primero o usa otra.');
        $this->actingAs($this->admin)->putJson("/identificacion/vehiculo/{$v->id}/etiqueta", ['etiqueta_nfc' => '04A23B1C'])->assertUnprocessable()
            ->assertJsonPath('errors.etiqueta_nfc.0', 'Esa tarjeta o etiqueta NFC/RFID ya está asignada a la llave «HDC-101». Quítala de ahí primero o usa otra.');

        // El lector la encuentra
        $this->actingAs($this->admin)->getJson('/lector/resolver?entrada=04:A2:3B:1C&tipos=llave')->assertJsonPath('resultados.0.id', $l->id);

        // Quitar
        $this->actingAs($this->admin)->putJson("/identificacion/llave/{$l->id}/etiqueta", ['etiqueta_nfc' => ''])->assertOk()->assertJson(['etiqueta' => null]);
        $this->assertNull($l->fresh()->etiqueta_nfc);
        $this->assertSame(1, Auditoria::where('evento', 'llaves.etiqueta_quitada')->count());

        // Agente: ve el QR pero no asigna etiquetas
        $agente = $this->crearUsuario($this->empresa, 'Agente');
        $this->actingAs($agente)->get("/identificacion/llave/{$l->id}/qr")->assertOk();
        $this->actingAs($agente)->putJson("/identificacion/llave/{$l->id}/etiqueta", ['etiqueta_nfc' => 'AA11'])->assertForbidden();
        $this->actingAs($agente)->get('/llaves')->assertDontSee(route('identificacion.etiqueta', ['llave', $l->id]));

        // Alcance de sede y otra empresa: 404
        $playa = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->playa);
        $this->actingAs($playa)->get("/identificacion/llave/{$l->id}/qr")->assertNotFound();
        $this->actingAs($playa)->putJson("/identificacion/llave/{$l->id}/etiqueta", ['etiqueta_nfc' => 'AA11'])->assertNotFound();
        $empresa2 = $this->crearEmpresa('Hotel Dos');
        $this->crearSede($empresa2, 'X1');
        $this->actingAs($this->crearUsuario($empresa2, 'Administrador'))->get("/identificacion/llave/{$l->id}/qr")->assertNotFound();
    }

    // ------------------------------------------------------- Z-08: solo la nota

    public function test_z08_queda_la_nota_de_secciones_para_llaves(): void
    {
        $doc = File::get(base_path('docs/tecnico/zonas-y-areas.md'));
        $this->assertStringContainsString('Categoría de habitación', $doc);
        $this->assertStringContainsString('Sección de llaves', $doc);
    }
}
