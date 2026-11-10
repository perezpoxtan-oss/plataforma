<?php

namespace Tests\Feature\Nucleo;

use App\Models\User;
use App\Services\AppMovil\ApkInstalada;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Mi cuenta → App para tu celular (docs/tecnico/app-movil.md, «Descarga desde la
 * plataforma»): con y sin app instalada, descarga con sesión y por enlace firmado
 * (válido, vencido, alterado), límites, historial del Super Administrador y la
 * huella de la firma en App Links.
 */
class AppMovilDescargaTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private string $carpeta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->carpeta = sys_get_temp_dir().'/app-movil-prueba-'.bin2hex(random_bytes(5));
        mkdir($this->carpeta, 0700, true);
        config(['app_movil.carpeta' => $this->carpeta, 'app_movil.huellas_sha256' => '']);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->carpeta.'/{,.}*', GLOB_BRACE) ?: [] as $f) {
            if (is_file($f)) {
                unlink($f);
            }
        }
        @rmdir($this->carpeta);
        parent::tearDown();
    }

    private function agente(): User
    {
        $this->sembrarCatalogo();
        $empresa = $this->crearEmpresa();

        return $this->crearUsuario($empresa, 'Agente', $this->crearSede($empresa, 'CEN'));
    }

    /** Lo que deja desplegar.sh: el APK con su sha256 en el nombre y app-movil.json. */
    private function instalarApk(string $contenido = 'APK-DE-PRUEBA', string $version = 'v1.2.3', array $extra = []): array
    {
        $sha = hash('sha256', $contenido);
        file_put_contents($this->carpeta."/smart-centinel-{$sha}.apk", $contenido);
        $datos = $extra + [
            'app' => 'mx.smartcentinel.app', 'version' => $version, 'version_code' => 1234567, 'servidor' => 'produccion',
            'commit' => str_repeat('a', 40), 'fecha' => '2026-11-09T15:00:00Z', 'sha256' => $sha, 'tamano' => strlen($contenido),
            'huella_firma' => implode(':', array_fill(0, 32, 'AB')), 'firma' => 'publicacion', 'instalado_en' => '2026-11-09T16:00:00Z',
        ];
        file_put_contents($this->carpeta.'/app-movil.json', json_encode($datos));
        file_put_contents($this->carpeta.'/historial.jsonl', json_encode($datos)."\n", FILE_APPEND);

        return $datos;
    }

    public function test_sin_app_la_pantalla_lo_dice_sin_error(): void
    {
        $this->actingAs($this->agente());

        $this->get(route('mi-cuenta.app'))->assertOk()
            ->assertSee('La app todavía no está disponible en esta versión')
            ->assertSee('iPhone: próximamente')
            ->assertDontSee('Descargar app');
        $this->get(route('mi-cuenta.index'))->assertOk()->assertSee('App para tu celular')->assertSee('La app todavía no está disponible en esta versión');
        $this->get(route('mi-cuenta.app.descargar'))->assertNotFound();
    }

    public function test_con_app_muestra_version_qr_instrucciones_y_huella(): void
    {
        $datos = $this->instalarApk();
        $this->actingAs($this->agente());

        $r = $this->get(route('mi-cuenta.app'))->assertOk()
            ->assertSee('v1.2.3')
            ->assertSee('Descargar app')
            ->assertSee('Permitir de esta fuente')
            ->assertSee('Actualizar')
            ->assertSee('iPhone: próximamente')
            ->assertSee($datos['sha256'])
            ->assertSee('<svg', false)
            ->assertDontSee('Historial de la app');
        $this->assertStringNotContainsString('<script>', $r->getContent());
        $this->get(route('mi-cuenta.index'))->assertOk()->assertSee('versión v1.2.3')->assertSee(route('mi-cuenta.app.descargar'), false);
    }

    public function test_descarga_con_sesion_con_su_tipo_y_huella(): void
    {
        $datos = $this->instalarApk();
        $this->actingAs($this->agente());

        $r = $this->get(route('mi-cuenta.app.descargar'))->assertOk();

        $this->assertSame(ApkInstalada::TIPO, $r->headers->get('Content-Type'));
        $this->assertStringContainsString('attachment', (string) $r->headers->get('Content-Disposition'));
        $this->assertStringContainsString('smart-centinel-v1.2.3.apk', (string) $r->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $r->headers->get('Cache-Control'));
        $this->assertStringContainsString('private', (string) $r->headers->get('Cache-Control'));
        $this->assertSame('sha-256=:'.base64_encode(hex2bin($datos['sha256'])).':', $r->headers->get('Repr-Digest'));
        $this->assertSame('APK-DE-PRUEBA', file_get_contents($r->baseResponse->getFile()->getPathname()));
    }

    public function test_sin_sesion_no_se_ve_ni_se_descarga(): void
    {
        $this->instalarApk();

        $this->get(route('mi-cuenta.app'))->assertRedirect(route('login'));
        $this->get(route('mi-cuenta.app.descargar'))->assertRedirect(route('login'));
        // Sin firma tampoco
        $this->get('/app-movil/descargar')->assertForbidden()->assertSee('Este enlace ya no sirve');
    }

    public function test_enlace_firmado_valido_descarga_sin_sesion_ni_cookies(): void
    {
        $this->instalarApk();
        $enlace = URL::temporarySignedRoute('app-movil.enlace', now()->addMinutes(30));

        $r = $this->get($enlace)->assertOk();

        $this->assertSame(ApkInstalada::TIPO, $r->headers->get('Content-Type'));
        $this->assertEmpty($r->headers->getCookies(), 'El enlace del QR no abre sesión');
    }

    public function test_el_enlace_de_la_pantalla_es_firmado_y_dura_30_minutos(): void
    {
        $this->instalarApk();
        $this->actingAs($this->agente());
        $this->travelTo(now()->startOfMinute());

        $r = $this->get(route('mi-cuenta.app'))->assertOk();
        $html = $r->getContent();
        $vista = $r->viewData('enlace');

        $this->assertStringContainsString('/app-movil/descargar?expires=', $vista);
        parse_str((string) parse_url($vista, PHP_URL_QUERY), $q);
        $this->assertSame(now()->addMinutes(30)->getTimestamp(), (int) $q['expires']);
        $this->assertStringContainsString('30 minutos', $html);
    }

    public function test_enlace_vencido_o_alterado_no_descarga(): void
    {
        $this->instalarApk();
        $enlace = URL::temporarySignedRoute('app-movil.enlace', now()->addMinutes(30));

        $this->travel(31)->minutes();
        $this->get($enlace)->assertForbidden()->assertSee('Este enlace ya no sirve')->assertHeader('Cache-Control', 'no-store, private');
        $this->travelBack();

        $vigente = URL::temporarySignedRoute('app-movil.enlace', now()->addMinutes(30));
        $alterado = preg_replace('/expires=(\d+)/', 'expires='.(now()->addDays(30)->getTimestamp()), $vigente);
        $this->get($alterado)->assertForbidden();
        $this->get(preg_replace('/signature=[0-9a-f]{4}/', 'signature=0000', $vigente))->assertForbidden();
    }

    public function test_enlace_firmado_sin_app_dice_que_no_esta_disponible(): void
    {
        $this->get(URL::temporarySignedRoute('app-movil.enlace', now()->addMinutes(30)))
            ->assertNotFound()->assertSee('La app todavía no está disponible');
    }

    public function test_limite_de_descargas_con_sesion_y_por_enlace(): void
    {
        $this->instalarApk();
        $this->actingAs($this->agente());
        for ($i = 0; $i < 5; $i++) {
            $this->get(route('mi-cuenta.app.descargar'))->assertOk();
        }
        $this->get(route('mi-cuenta.app.descargar'))->assertStatus(429);

        $enlace = URL::temporarySignedRoute('app-movil.enlace', now()->addMinutes(30));
        for ($i = 0; $i < 10; $i++) {
            $this->get($enlace)->assertOk();
        }
        $this->get($enlace)->assertStatus(429);
    }

    public function test_json_alterado_o_apk_faltante_cuenta_como_sin_app(): void
    {
        $datos = $this->instalarApk();
        $this->actingAs($this->agente());

        // APK de otro tamaño (a medias)
        file_put_contents($this->carpeta.'/smart-centinel-'.$datos['sha256'].'.apk', 'APK');
        $this->get(route('mi-cuenta.app'))->assertOk()->assertSee('La app todavía no está disponible en esta versión');

        // Versión con ruta o sha256 inválido
        foreach ([['version' => '../../.env'], ['sha256' => '../x'], ['tamano' => '13']] as $malo) {
            file_put_contents($this->carpeta.'/app-movil.json', json_encode($malo + $datos));
            $this->get(route('mi-cuenta.app.descargar'))->assertNotFound();
        }
        file_put_contents($this->carpeta.'/app-movil.json', '{roto');
        $this->get(route('mi-cuenta.app'))->assertOk()->assertSee('La app todavía no está disponible en esta versión');
    }

    public function test_el_super_administrador_ve_el_historial_con_sha256(): void
    {
        $this->sembrarCatalogo();
        $viejo = $this->instalarApk('APK-VIEJO', 'qa-abc1234');
        $nuevo = $this->instalarApk('APK-NUEVO', 'v1.3.0');
        file_put_contents($this->carpeta.'/historial.jsonl', "renglón roto\n", FILE_APPEND);

        $this->actingAs($this->crearSuperadmin());
        $this->get(route('mi-cuenta.app'))->assertOk()
            ->assertSee('Historial de la app')
            ->assertSeeInOrder(['v1.3.0', $nuevo['sha256'], 'qa-abc1234', $viejo['sha256']]);
    }

    public function test_app_links_usan_la_huella_de_la_firma_de_la_app_instalada(): void
    {
        $this->get('/.well-known/assetlinks.json')->assertNotFound();

        $datos = $this->instalarApk();
        $json = $this->get('/.well-known/assetlinks.json')->assertOk()->json();
        $this->assertSame([$datos['huella_firma']], $json[0]['target']['sha256_cert_fingerprints']);
        $this->assertSame('mx.smartcentinel.app', $json[0]['target']['package_name']);

        // Con huella en el .env, se suman (sin repetir)
        config(['app_movil.huellas_sha256' => str_repeat('CD', 32).','.$datos['huella_firma']]);
        $json = $this->get('/.well-known/assetlinks.json')->assertOk()->json();
        $this->assertCount(2, $json[0]['target']['sha256_cert_fingerprints']);

        // Firma de depuración u otra app: no cuenta
        config(['app_movil.huellas_sha256' => '']);
        $this->instalarApk('OTRO', 'qa-1234567', ['firma' => 'depuracion']);
        $this->get('/.well-known/assetlinks.json')->assertNotFound();
        $this->instalarApk('OTRO2', 'qa-1234568', ['app' => 'com.otra.app']);
        $this->get('/.well-known/assetlinks.json')->assertNotFound();
    }
}
