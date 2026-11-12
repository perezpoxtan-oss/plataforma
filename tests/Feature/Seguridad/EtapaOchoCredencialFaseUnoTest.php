<?php

namespace Tests\Feature\Seguridad;

use App\Models\Acceso;
use App\Models\Colaborador;
use App\Models\Credencial;
use App\Models\Empresa;
use App\Models\Sede;
use App\Models\User;
use App\Models\VoucherReposicion;
use App\Support\AjustesCredenciales;
use App\Support\EstadoActivo;
use App\Support\Lector\Etiqueta;
use App\Support\Menu\MisPendientes;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Etapa 8 · C-44, fase 1 de la propuesta de credencialización (12 oct):
 * foto (cámara o archivo), Emitir / Imprimir (CR80 frente y reverso) /
 * Reponer / Dar de baja, una sola vigente, vigencia configurable (sin
 * vencimiento de fábrica), verificación pública por QR sin datos personales y
 * con límite, aviso «por vencer» en Mis pendientes y lista «Credenciales».
 * Fase 2 (lo barato): la caseta ve si la credencial que trae ya no vale.
 */
class EtapaOchoCredencialFaseUnoTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private User $rh;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->rh = $this->crearUsuario($this->empresa, 'Recursos Humanos');
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

    private function colaborador(string $num = '1200', array $extra = []): Colaborador
    {
        return $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => $num, 'nombre' => 'Diana', 'apellido_paterno' => 'Vega', 'sede_id' => $this->centro->id] + $extra));
    }

    private function vigenteDe(Colaborador $c): ?Credencial
    {
        return $this->enEmpresa(fn () => Credencial::where('colaborador_id', $c->id)->where('estado', '!=', 'baja')->latest('id')->first());
    }

    private function ajustes(array $valores): void
    {
        $this->actingAs($this->crearUsuario($this->empresa, 'Administrador'))
            ->put(route('configuracion.credenciales'), array_merge(AjustesCredenciales::OMISION, $valores))->assertSessionHasNoErrors();
    }

    public function test_foto_por_archivo_o_camara_se_recorta_y_queda_privada(): void
    {
        $c = $this->colaborador();
        $this->actingAs($this->rh)->post(route('credenciales.foto', $c->id), ['foto' => UploadedFile::fake()->image('yo.jpg', 1200, 900)])
            ->assertSessionHasNoErrors()->assertSessionHas('ok');
        $ruta = $c->fresh()->foto_credencial_ruta;
        $this->assertStringStartsWith('credenciales/'.$this->empresa->id.'/fotos/', $ruta);
        [$ancho, $alto] = getimagesizefromstring(Storage::disk('local')->get($ruta));
        $this->assertSame([600, 800], [$ancho, $alto]);

        // Desde la cámara (data URL): reemplaza y borra la anterior
        $img = imagecreatetruecolor(640, 480);
        ob_start();
        imagejpeg($img);
        $dato = 'data:image/jpeg;base64,'.base64_encode((string) ob_get_clean());
        $this->actingAs($this->rh)->post(route('credenciales.foto', $c->id), ['foto_camara' => $dato])->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing($ruta);
        $this->assertNotSame($ruta, $c->fresh()->foto_credencial_ruta);

        $this->actingAs($this->rh)->post(route('credenciales.foto', $c->id), ['foto' => UploadedFile::fake()->create('virus.pdf', 10, 'application/pdf')])
            ->assertSessionHasErrors('foto');
        $this->actingAs($this->rh)->post(route('credenciales.foto', $c->id), [])->assertSessionHasErrors(['foto' => 'Toma la foto con la cámara o elige un archivo JPG o PNG.']);
    }

    public function test_emitir_imprimir_cr80_y_una_sola_vigente(): void
    {
        $c = $this->colaborador();
        $this->actingAs($this->rh)->post(route('credenciales.emitir', $c->id))->assertSessionHasNoErrors()->assertSessionHas('siguientes');
        $cr = $this->vigenteDe($c);
        $this->assertSame([EstadoActivo::DISPONIBLE, 'CRED-000001', null, $c->fresh()->codigo_qr], [$cr->estado, $cr->folio, $cr->vence_en, $cr->codigo_qr]);
        $this->actingAs($this->rh)->post(route('credenciales.emitir', $c->id))->assertSessionHasErrors('credencial');

        // Hoja CR80: frente y reverso, QR con la verificación pública, sin datos personales
        $this->enEmpresa(fn () => $c->forceFill(['curp' => 'VEGD900101MDFXXX01'])->save());
        $hoja = $this->actingAs($this->rh)->get(route('credenciales.imprimir', ['credenciales' => [$cr->id]]))->assertOk()
            ->assertSee('size: 85.6mm 54mm', false)->assertSee('Frente de la credencial CRED-000001', false)->assertSee('Reverso de la credencial CRED-000001', false)
            ->assertSee('Diana Vega')->assertSee('Sin vencimiento')->assertSee('Esta credencial es propiedad de')->getContent();
        $this->assertStringNotContainsString('VEGD900101', $hoja);
        $this->assertStringContainsString('<svg', $hoja);
        $this->assertDatabaseHas('auditoria', ['evento' => 'colaboradores.credencial_impresa']);

        // Dar salida entrega la misma (no emite otra)
        $this->actingAs($this->rh)->post(route('credenciales.salida', $c->id))->assertSessionHasNoErrors();
        $this->assertSame([EstadoActivo::ASIGNADO, 1], [$cr->fresh()->estado, $this->enEmpresa(fn () => Credencial::count())]);
    }

    public function test_reponer_invalida_la_anterior_al_instante(): void
    {
        $c = $this->colaborador();
        $this->actingAs($this->rh)->post(route('credenciales.salida', $c->id))->assertSessionHasNoErrors();
        $vieja = $this->vigenteDe($c);
        $codigoViejo = $vieja->codigo_qr;
        auth()->logout();
        $this->get(route('credenciales.verificar', $codigoViejo))->assertOk()->assertSee('Vigente');

        // Vencida o cambio de foto: sin voucher
        $this->actingAs($this->rh)->post(route('credenciales.reponer', $vieja->id), ['motivo' => 'actualizacion'])->assertSessionHasNoErrors();
        $nueva = $this->vigenteDe($c);
        $this->assertSame([EstadoActivo::BAJA, 'actualizacion'], [$vieja->fresh()->estado, $vieja->fresh()->baja_motivo]);
        $this->assertSame([EstadoActivo::DISPONIBLE, 'CRED-000002'], [$nueva->estado, $nueva->folio]);
        $this->assertNotSame($codigoViejo, $nueva->codigo_qr);
        $this->assertSame(0, $this->enEmpresa(fn () => VoucherReposicion::count()));
        auth()->logout();
        $this->get(route('credenciales.verificar', $codigoViejo))->assertOk()->assertSee('No vigente')->assertDontSee('Diana');
        $this->get(route('credenciales.verificar', $nueva->codigo_qr))->assertOk()->assertSee('Vigente')->assertSee('Diana Vega');

        // Perdida: con voucher y cobro
        $this->actingAs($this->rh)->post(route('credenciales.reponer', $nueva->id), ['motivo' => 'extraviado', 'aplica_cobro' => 1, 'monto' => '50'])->assertSessionHasNoErrors();
        $this->assertSame('CRED-000003', $this->vigenteDe($c)->folio);
        $this->assertSame(['credencial', '50.00'], $this->enEmpresa(fn () => [VoucherReposicion::first()->origen_tipo, VoucherReposicion::first()->monto]));
        $this->actingAs($this->rh)->post(route('credenciales.reponer', $this->vigenteDe($c)->id), ['motivo' => 'otro'])->assertSessionHasErrors('motivo');
    }

    public function test_verificacion_publica_sin_datos_personales_con_limite_y_con_sesion_lleva_a_la_caseta(): void
    {
        $c = $this->colaborador('1201', ['curp' => 'VEGD900101MDFXXX01', 'telefono' => '9981234567']);
        $this->actingAs($this->rh)->post(route('credenciales.emitir', $c->id));
        $codigo = $this->vigenteDe($c)->codigo_qr;
        auth()->logout();

        $pagina = $this->get(route('credenciales.verificar', $codigo))->assertOk()->assertSee('Vigente')->assertSee('Diana Vega')
            ->assertSee('Sin fecha de vencimiento')->assertHeader('X-Robots-Tag', 'noindex, nofollow')->getContent();
        foreach (['VEGD900101', '9981234567', '1201'] as $personal) {
            $this->assertStringNotContainsString($personal, $pagina);
        }
        // Código inexistente: No vigente (sin decir por qué)
        $this->get(route('credenciales.verificar', 'zzzzzzzzzzzzzzzzzzzzzzzz'))->assertOk()->assertSee('No vigente');
        // El lector de la caseta entiende la dirección impresa
        $this->assertSame($codigo, Etiqueta::codigoDeUrl(route('credenciales.verificar', $codigo)));
        // Con sesión: a la acción de la caseta
        $this->actingAs($this->rh)->get(route('credenciales.verificar', $codigo))->assertRedirect(route('lector.ir', $codigo));

        // Límite por IP
        auth()->logout();
        for ($i = 0; $i < 30; $i++) {
            $this->get(route('credenciales.verificar', 'aaaaaaaa'.$i.'bbbbbbbb'));
        }
        $this->get(route('credenciales.verificar', $codigo))->assertStatus(429);
    }

    public function test_verificacion_publica_sin_foto_ni_forma_de_pedirla(): void
    {
        // Decisión del dueño (3 nov): solo nombre y «Vigente / No vigente», sin la foto
        $c = $this->colaborador('1210');
        $this->actingAs($this->rh)->post(route('credenciales.foto', $c->id), ['foto' => UploadedFile::fake()->image('yo.jpg', 900, 1200)])->assertSessionHasNoErrors();
        $ruta = $c->fresh()->foto_credencial_ruta;
        $this->assertNotNull($ruta);
        $this->actingAs($this->rh)->post(route('credenciales.emitir', $c->id));
        $codigo = $this->vigenteDe($c)->codigo_qr;
        // Dentro de la plataforma la foto sí se ve (ficha e impresión)
        $this->actingAs($this->rh)->get(route('colaboradores.expediente', $c->id))->assertSee('data:image/jpeg;base64,', false);
        auth()->logout();

        $resp = $this->get(route('credenciales.verificar', $codigo))->assertOk()->assertSee('Vigente')->assertSee('Diana Vega')
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Referrer-Policy', 'no-referrer');
        $this->assertStringContainsString('no-store', (string) $resp->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $resp->headers->get('Cache-Control'));
        $pagina = (string) $resp->getContent();
        foreach (['data:image', 'base64', 'Foto de', 'verificacion-foto', 'og:image', basename($ruta)] as $nada) {
            $this->assertStringNotContainsString($nada, $pagina, "La verificación pública no debe llevar «{$nada}»");
        }
        $this->assertStringContainsString('<meta name="robots" content="noindex, nofollow, noarchive">', $pagina);
        // Sin la foto incrustada la respuesta es ligera (antes llevaba la foto en base64)
        $this->assertLessThan(20 * 1024, strlen($pagina));
        $this->assertArrayNotHasKey('foto', $resp->original->getData());

        // Ninguna dirección pública entrega la foto: el disco es privado y no se publica en /storage
        $this->get('/storage/'.$ruta)->assertNotFound();
        $this->get('/storage/app/private/'.$ruta)->assertNotFound();
        $this->get(route('colaboradores.expediente', $c->id))->assertRedirect();
        $this->get(route('credenciales.imprimir', ['credenciales' => [$this->vigenteDe($c)->id]]))->assertRedirect();
        $this->assertFalse((bool) config('filesystems.disks.local.serve'));
        foreach (app('router')->getRoutes() as $r) {
            if (str_contains($r->uri(), 'foto') && in_array('GET', $r->methods(), true)) {
                $this->assertContains('auth', $r->gatherMiddleware(), "{$r->uri()} entrega una foto sin sesión");
            }
        }

        // No vigente: tampoco foto
        $this->actingAs($this->rh)->post(route('credenciales.reponer', $this->vigenteDe($c)->id), ['motivo' => 'actualizacion'])->assertSessionHasNoErrors();
        auth()->logout();
        $viejo = (string) $this->get(route('credenciales.verificar', $codigo))->assertOk()->assertSee('No vigente')->getContent();
        $this->assertStringNotContainsString('data:image', $viejo);
    }

    public function test_vigencia_configurable_por_vencer_en_mis_pendientes_y_vencida_en_la_caseta(): void
    {
        Carbon::setTestNow('2026-11-02 10:00:00');
        $this->ajustes(['vigencia' => 'meses', 'vigencia_meses' => 1, 'aviso_dias' => 30]);
        $c = $this->colaborador();
        $this->actingAs($this->rh)->post(route('credenciales.salida', $c->id))->assertSessionHasNoErrors();
        $cr = $this->vigenteDe($c);
        $this->assertSame('2026-12-02', $cr->vence_en->toDateString());

        $items = collect(app(MisPendientes::class)->para($this->rh)['items'])->keyBy('clave');
        $this->assertSame([1, 'ambar', 'Credenciales por vencer'], [$items['credenciales_por_vencer']['total'], $items['credenciales_por_vencer']['nivel'], $items['credenciales_por_vencer']['titulo']]);

        // Pasa la fecha (sin mover el reloj: la sesión expiraría): vencida (rojo), «No vigente» y la caseta lo ve
        $this->enEmpresa(fn () => $cr->forceFill(['vence_en' => '2026-11-01'])->save());
        Cache::flush(); // Mis pendientes se guarda unos segundos (y se memoriza por petición)
        app()->forgetInstance(MisPendientes::class);
        $items = collect(app()->make(MisPendientes::class)->para($this->rh)['items'])->keyBy('clave');
        $this->assertSame('roja', $items['credenciales_por_vencer']['nivel']);
        $codigo = $cr->codigo_qr;
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->actingAs($agente)->get(route('lector.ir', $codigo))->assertOk()->assertSee('ya no es vigente (vencida)');
        auth()->logout();
        $this->get(route('credenciales.verificar', $codigo))->assertSee('No vigente');
        $this->actingAs($this->rh)->get(route('credenciales.index', ['estado' => 'vencidas']))->assertOk()->assertSee('Diana Vega')->assertSee('VENCIDA');

        // Anual: vence en la fecha del año (o la del siguiente si ya pasó)
        $this->ajustes(['vigencia' => 'anual', 'vigencia_fecha' => '06-30']);
        $this->assertSame('2027-06-30', AjustesCredenciales::venceEn($this->empresa->id)->toDateString());
        $this->actingAs($this->crearUsuario($this->empresa, 'Administrador'))->put(route('configuracion.credenciales'), array_merge(AjustesCredenciales::OMISION, ['vigencia_fecha' => '02-30']))
            ->assertSessionHasErrors('vigencia_fecha');
    }

    public function test_lista_con_filtros_e_imprimir_seleccionadas_emite_la_que_falta(): void
    {
        $con = $this->colaborador('1300');
        $sin = $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => '1301', 'nombre' => 'Tomás', 'apellido_paterno' => 'Rey', 'sede_id' => $this->centro->id]));
        $this->actingAs($this->rh)->post(route('credenciales.emitir', $con->id));

        $this->actingAs($this->rh)->get(route('credenciales.index'))->assertOk()->assertSee('Diana Vega')->assertSee('Tomás Rey')->assertSee('Sin credencial (1)');
        $this->actingAs($this->rh)->get(route('credenciales.index', ['estado' => 'sin']))->assertSee('Tomás Rey')->assertDontSee('Diana Vega');
        $this->actingAs($this->rh)->get(route('credenciales.index', ['q' => 'tomas']))->assertSee('Tomás Rey')->assertDontSee('Diana Vega');
        $this->actingAs($this->rh)->get(route('colaboradores.index'))->assertSee(route('credenciales.index'), false);

        $resp = $this->actingAs($this->rh)->post(route('credenciales.imprimir-seleccion'), ['colaboradores' => [$con->id, $sin->id]]);
        $nueva = $this->vigenteDe($sin);
        $this->assertNotNull($nueva);
        $resp->assertRedirect(route('credenciales.imprimir', ['credenciales' => [$this->vigenteDe($con)->id, $nueva->id]]));

        // Sin permiso (Agente): 403
        $this->actingAs($this->crearUsuario($this->empresa, 'Agente', $this->centro))->get(route('credenciales.index'))->assertForbidden();
    }

    public function test_configuracion_de_credenciales_y_tecnologia(): void
    {
        $admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->actingAs($admin)->get(route('configuracion.tema', 'estructura'))->assertOk()->assertSee('Credenciales del personal')->assertSee('Sin vencimiento (de fábrica)');
        $c = $this->colaborador();
        $this->actingAs($this->rh)->post(route('credenciales.emitir', $c->id));
        // Solo QR (de fábrica): no pide tarjeta al dar salida
        $this->actingAs($this->rh)->get(route('colaboradores.expediente', $c->id))->assertDontSee('Avanzado: tarjeta NFC / RFID del plástico');
        $this->ajustes(['tarjetas' => ['', 'proximidad_125', 'mifare_1356'], 'telefono' => '998 000 0000', 'reverso_texto' => 'Uso obligatorio dentro de las instalaciones.']);
        $this->actingAs($this->rh)->get(route('colaboradores.expediente', $c->id))->assertSee('Avanzado: tarjeta NFC / RFID del plástico');
        $this->actingAs($this->rh)->get(route('credenciales.imprimir', ['credenciales' => [$this->vigenteDe($c)->id]]))
            ->assertSee('llama al 998 000 0000')->assertSee('Uso obligatorio dentro de las instalaciones.');
        // Solo quien edita Configuración en toda la empresa
        $this->actingAs($this->rh)->put(route('configuracion.credenciales'), AjustesCredenciales::OMISION)->assertForbidden();
    }

    public function test_residente_o_provisional_no_reciben_credencial(): void
    {
        $prov = $this->colaborador('1400');
        $this->enEmpresa(fn () => $prov->forceFill(['provisional' => true])->save());
        $this->actingAs($this->rh)->post(route('credenciales.emitir', $prov->id))->assertSessionHasErrors('credencial');
        $this->assertSame(0, $this->enEmpresa(fn () => Credencial::count()));
        $this->assertSame(0, $this->enEmpresa(fn () => Acceso::count()));
    }
}
