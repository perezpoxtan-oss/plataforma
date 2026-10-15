<?php

namespace Tests\Feature\Nucleo;

use App\Http\Middleware\CabecerasSeguridad;
use App\Models\User;
use App\Support\AppMovil;
use App\Support\Identidad;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Plataforma instalable (PWA) y app de Android (docs/tecnico/app-movil.md):
 * manifest y service worker servidos sin sesión y con sus cabeceras, CSP sin
 * abrir de más, enlaces en las cabeceras HTML y lo que cambia dentro de la app.
 */
class AppMovilPwaTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private const AGENTE_APP = 'Mozilla/5.0 (Linux; Android 14; wv) AppleWebKit/537.36 Chrome/130.0 Mobile Safari/537.36 SmartCentinelApp/1';

    private function usuario(): User
    {
        $this->sembrarCatalogo();
        $empresa = $this->crearEmpresa();

        return $this->crearUsuario($empresa, 'Agente', $this->crearSede($empresa, 'CEN'));
    }

    public function test_el_manifest_es_valido_con_su_tipo_y_sin_sesion(): void
    {
        $r = $this->get('/manifest.webmanifest')->assertOk();

        $this->assertStringStartsWith('application/manifest+json', (string) $r->headers->get('Content-Type'));
        $this->assertEmpty($r->headers->getCookies(), 'El manifest no abre sesión ni envía cookies');
        $this->assertNotNull($r->headers->get('Content-Security-Policy'));

        $m = $r->json();
        $this->assertSame(AppMovil::NOMBRE, $m['name']);
        $this->assertSame(AppMovil::NOMBRE_CORTO, $m['short_name']);
        $this->assertSame('/', $m['start_url']);
        $this->assertSame('/', $m['scope']);
        $this->assertSame('standalone', $m['display']);
        $this->assertSame(Identidad::VALORES_POR_DEFECTO['color_primario'], $m['theme_color']);

        $porUso = collect($m['icons'])->groupBy('purpose');
        foreach (['any', 'maskable'] as $uso) {
            $this->assertEqualsCanonicalizing(['192x192', '512x512'], $porUso[$uso]->pluck('sizes')->all());
        }
        foreach ($m['icons'] as $icono) {
            $archivo = public_path(parse_url($icono['src'], PHP_URL_PATH));
            $this->assertFileExists($archivo);
            [$ancho, $alto] = getimagesize($archivo);
            $this->assertSame($icono['sizes'], $ancho.'x'.$alto);
        }
        $this->assertFileExists(public_path('iconos/apple-touch-icon.png'));
    }

    public function test_el_manifest_toma_el_nombre_y_color_de_identidad(): void
    {
        app(Identidad::class)->guardar(['nombre' => 'Seguridad Centro', 'nombre_corto' => 'Centro', 'color_primario' => '#112233']);

        $m = $this->get('/manifest.webmanifest')->assertOk()->json();
        $this->assertSame('Seguridad Centro', $m['name']);
        $this->assertSame('Centro', $m['short_name']);
        $this->assertSame('#112233', $m['theme_color']);
    }

    public function test_el_service_worker_se_sirve_en_la_raiz_sin_sesion_ni_cache(): void
    {
        $r = $this->get('/sw.js')->assertOk();

        $this->assertStringStartsWith('application/javascript', (string) $r->headers->get('Content-Type'));
        $this->assertSame('/', $r->headers->get('Service-Worker-Allowed'));
        $this->assertStringContainsString('no-cache', (string) $r->headers->get('Cache-Control'));
        $this->assertEmpty($r->headers->getCookies());

        $codigo = $r->getContent();
        $this->assertStringNotContainsString('__VERSION__', $codigo);
        $this->assertMatchesRegularExpression("/var VERSION = '[0-9a-f]{12}'/", $codigo);
        // Solo GET del propio sitio; las pantallas van siempre a la red (nunca se guardan)
        $this->assertStringContainsString("peticion.method !== 'GET'", $codigo);
        $this->assertStringContainsString('e.respondWith(fetch(peticion).catch(function () { return caches.match(SIN_CONEXION); }));', $codigo);
        $this->assertStringContainsString("var CARPETAS = ['/css/', '/js/', '/vendor/', '/iconos/'];", $codigo);
    }

    public function test_el_service_worker_no_cambia_con_sesion_iniciada(): void
    {
        $usuario = $this->usuario();
        $r = $this->actingAs($usuario)->get('/sw.js')->assertOk();

        $this->assertEmpty($r->headers->getCookies(), 'La ruta va sin el grupo web: no toca la sesión');
        $this->assertStringNotContainsString('no-store', (string) $r->headers->get('Cache-Control'));
    }

    public function test_la_pagina_sin_conexion_no_tiene_sesion_ni_datos(): void
    {
        $r = $this->get('/sin-conexion')->assertOk()->assertSee('Sin conexión')->assertSee('Reintentar');
        $this->assertEmpty($r->headers->getCookies());
        $this->assertStringNotContainsString('csrf-token', $r->getContent());
    }

    public function test_la_csp_permite_el_service_worker_y_el_manifest_sin_abrirse(): void
    {
        $csp = $this->get('/manifest.webmanifest')->headers->get('Content-Security-Policy');

        $this->assertSame(CabecerasSeguridad::CSP, $csp);
        $this->assertStringContainsString("worker-src 'self' blob:", $csp);
        // manifest-src no se declara: hereda default-src 'self'
        $this->assertStringContainsString("default-src 'self'", $csp);
        $this->assertStringContainsString("script-src 'self';", $csp);
        $this->assertStringNotContainsString("'unsafe-eval'", $csp);
        $this->assertStringNotContainsString('https:', $csp);
    }

    public function test_las_pantallas_enlazan_manifest_icono_y_registro(): void
    {
        foreach ([$this->get('/login'), $this->actingAs($this->usuario())->get('/mi-cuenta')] as $r) {
            $r->assertOk()
                ->assertSee('rel="manifest"', false)
                ->assertSee('/manifest.webmanifest', false)
                ->assertSee('rel="apple-touch-icon"', false)
                ->assertSee('name="theme-color"', false)
                ->assertSee('js/pwa.js', false);
        }
        $this->assertFileExists(public_path('js/pwa.js'));
        $this->assertStringNotContainsString('<script>', (string) $this->get('/login')->getContent(), 'Sin JavaScript en línea');
    }

    public function test_dentro_de_la_app_no_se_ofrecen_los_avisos_del_navegador(): void
    {
        $usuario = $this->usuario();

        $this->actingAs($usuario)->get('/mi-cuenta')->assertOk()
            ->assertSee('Activar avisos en este navegador');

        $this->actingAs($usuario)->withHeader('User-Agent', self::AGENTE_APP)->get('/mi-cuenta')->assertOk()
            ->assertDontSee('Avisos en este navegador')
            ->assertDontSee('Activar avisos en este navegador')
            ->assertDontSee('data-avisos-navegador', false);
    }

    public function test_la_app_no_cambia_el_cierre_por_inactividad(): void
    {
        $usuario = $this->usuario();
        $limite = (int) config('plataforma.sesion.inactividad_minutos');

        $this->actingAs($usuario)->withHeader('User-Agent', self::AGENTE_APP)
            ->withSession(['ultima_actividad' => now()->subMinutes($limite + 1)->getTimestamp()])
            ->get('/mi-cuenta')->assertRedirect(route('login'));
        $this->assertGuest();
    }

    public function test_sin_huella_no_se_publica_assetlinks(): void
    {
        config(['app_movil.huellas_sha256' => '']);
        $this->get('/.well-known/assetlinks.json')->assertNotFound();

        // Una huella mal escrita tampoco publica nada
        config(['app_movil.huellas_sha256' => 'no-es-una-huella, 12:34']);
        $this->get('/.well-known/assetlinks.json')->assertNotFound();
    }

    public function test_con_huella_assetlinks_publica_la_app_sin_sesion(): void
    {
        $huella = implode(':', str_split(str_repeat('ab12', 16), 2));
        config([
            'app_movil.paquete' => 'mx.smartcentinel.app',
            // Con o sin «:», minúsculas, varias separadas por coma, repetidas y una inválida
            'app_movil.huellas_sha256' => strtolower($huella).', '.str_repeat('AB12', 16).', '.str_repeat('0F', 32).', xyz',
        ]);

        $r = $this->get('/.well-known/assetlinks.json')->assertOk();

        $this->assertStringStartsWith('application/json', (string) $r->headers->get('Content-Type'));
        $this->assertEmpty($r->headers->getCookies(), 'assetlinks.json no abre sesión ni envía cookies');
        $this->assertSame([[
            'relation' => ['delegate_permission/common.handle_all_urls'],
            'target' => [
                'namespace' => 'android_app',
                'package_name' => 'mx.smartcentinel.app',
                'sha256_cert_fingerprints' => [strtoupper($huella), implode(':', str_split(str_repeat('0F', 32), 2))],
            ],
        ]], $r->json());
    }

    public function test_el_nfc_de_la_app_solo_se_carga_dentro_de_la_app(): void
    {
        $usuario = $this->usuario();

        $this->actingAs($usuario)->get('/mi-cuenta')->assertOk()->assertDontSee('js/nfc-app.js', false);

        $html = (string) $this->actingAs($usuario)->withHeader('User-Agent', self::AGENTE_APP)->get('/mi-cuenta')->assertOk()->getContent();
        $this->assertStringContainsString('js/nfc-app.js', $html);
        // Antes que plataforma.js y sin defer: el lector universal debe encontrar NDEFReader al cargar
        $this->assertMatchesRegularExpression('#<script src="[^"]*js/nfc-app\.js[^"]*"></script>#', $html);
        $this->assertLessThan(strpos($html, 'js/plataforma.js'), strpos($html, 'js/nfc-app.js'));
        $this->assertFileExists(public_path('js/nfc-app.js'));
    }

    /**
     * NDEFReader de la app (public/js/nfc-app.js) con el plugin nativo simulado: entrega las lecturas con la
     * misma forma que Web NFC (lo que usa el lector universal), avisa NFC apagado / sin NFC, write() rechaza y,
     * sin lectura activa, abre dentro de la app solo las direcciones de la propia plataforma.
     * (La prueba con el lector universal completo en Chromium está en docs/tecnico/app-movil.md.)
     */
    public function test_el_ndefreader_de_la_app_entrega_las_lecturas_como_web_nfc(): void
    {
        $node = (new ExecutableFinder)->find('node');
        if ($node === null) {
            $this->markTestSkipped('Node no está instalado: no se puede ejecutar nfc-app.js.');
        }

        $script = <<<'JS'
            const fs = require('fs'), vm = require('vm');
            const oyentes = {}, navegado = [];
            let estado = { nfc: true, encendido: true };
            const emitir = (ev, d) => (oyentes[ev] || []).forEach(cb => cb(d));
            const b64 = t => Buffer.from(t, 'utf8').toString('base64');
            const ctx = {
                navigator: { userAgent: 'Mozilla/5.0 (Linux; Android 14; wv) SmartCentinelApp/1' },
                document: { addEventListener() {} }, DOMException, Event, URL, atob,
                location: { href: 'https://plataforma.example/prestamo-llaves', origin: 'https://plataforma.example', assign: u => navegado.push(u) },
                Capacitor: { Plugins: { LectorNfc: {
                    addListener: (ev, cb) => { (oyentes[ev] = oyentes[ev] || []).push(cb); return { remove() {} }; },
                    disponible: () => Promise.resolve(estado),
                    abrirAjustesNfc: () => Promise.resolve(),
                } } },
            };
            ctx.window = ctx;
            vm.createContext(ctx);
            vm.runInContext(fs.readFileSync(process.argv[1], 'utf8'), ctx);
            const motivo = p => p.then(() => 'resolvió', e => e.name + '|' + (e.codigo || '') + '|' + e.message);
            (async () => {
                const salida = {};
                const url = 'https://plataforma.example/e/abc12345XYZ';
                const lector = new ctx.NDEFReader(), control = new AbortController();
                const leido = new Promise(fin => {
                    // Igual que el lector universal (plataforma.js): texto o dirección; si no, número de serie
                    lector.onreading = ev => {
                        const reg = ev.message.records.find(r => ['text', 'url', 'absolute-url'].includes(r.recordType));
                        fin({ serie: ev.serialNumber, tipo: reg.recordType, texto: new TextDecoder(reg.encoding || 'utf-8').decode(reg.data) });
                    };
                });
                await lector.scan({ signal: control.signal });
                emitir('lectura', { serialNumber: '04:a2:3b', records: [{ recordType: 'url', texto: url, datos: b64(url) }] });
                salida.lectura = await leido;
                control.abort();

                // Sin lectura activa: solo la propia plataforma se abre dentro de la app
                emitir('lectura', { serialNumber: '04:a2', records: [{ recordType: 'url', texto: 'https://otro.example/e/abc12345XYZ', datos: '' }] });
                emitir('lectura', { serialNumber: '04:a2', records: [{ recordType: 'url', texto: url, datos: b64(url) }] });
                salida.navegado = navegado;

                estado = { nfc: true, encendido: false };
                salida.apagado = await motivo(new ctx.NDEFReader().scan());
                estado = { nfc: false, encendido: false };
                salida.sinNfc = await motivo(new ctx.NDEFReader().scan());
                salida.escribir = await motivo(new ctx.NDEFReader().write('x'));
                salida.ajustes = typeof ctx.LectorNfcApp.abrirAjustes;
                process.stdout.write(JSON.stringify(salida));
            })();
            JS;
        $proceso = new Process([$node, '-e', $script, public_path('js/nfc-app.js')]);
        $proceso->mustRun();
        $r = json_decode($proceso->getOutput(), true);

        $this->assertSame(['serie' => '04:a2:3b', 'tipo' => 'url', 'texto' => 'https://plataforma.example/e/abc12345XYZ'], $r['lectura']);
        $this->assertSame(['https://plataforma.example/e/abc12345XYZ'], $r['navegado']);
        $this->assertSame('NotAllowedError|nfc-apagado|Enciende el NFC del teléfono.', $r['apagado']);
        $this->assertSame('NotSupportedError|sin-nfc|Este teléfono no tiene NFC.', $r['sinNfc']);
        $this->assertStringStartsWith('NotSupportedError|solo-lectura|Para grabar etiquetas', $r['escribir']);
        $this->assertSame('function', $r['ajustes']);
    }
}
