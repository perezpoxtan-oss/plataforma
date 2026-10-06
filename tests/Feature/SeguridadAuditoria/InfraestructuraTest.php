<?php

namespace Tests\Feature\SeguridadAuditoria;

use App\Mail\AltaProvisionalRegistrada;
use App\Models\Empresa;
use App\Models\Sede;
use App\Models\User;
use App\Providers\AppServiceProvider;
use App\Services\Respaldos\Respaldos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Auditoría de seguridad 2026-10-06 — Infraestructura (INF-xx): dominio de los
 * enlaces, encabezados HTTP, candados de Producción, respaldos y .htaccess.
 */
class InfraestructuraTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private User $sa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->sa = $this->crearSuperadmin();
        $this->limpiarRespaldos();
    }

    protected function tearDown(): void
    {
        $this->limpiarRespaldos();
        DB::prohibitDestructiveCommands(false);
        parent::tearDown();
    }

    // ------------------------------------------------- INF-04 Host falso en enlaces

    public function test_host_falso_no_llega_a_los_enlaces_de_los_correos(): void
    {
        config(['plataforma.hosts.verificar' => true, 'plataforma.hosts.permitidos' => ['qa.vdcp.com.mx']]);
        Mail::fake();
        $this->actingAs($this->sa)->put('http://qa.vdcp.com.mx/configuracion/correo', [
            'host' => 'mail.vdcp.com.mx', 'puerto' => 587, 'cifrado' => 'tls', 'usuario' => 'avisos@vdcp.com.mx',
            'contrasena' => 'x', 'remitente_correo' => 'avisos@vdcp.com.mx', 'remitente_nombre' => 'Avisos',
        ])->assertRedirect();
        $this->crearUsuario($this->empresa, 'Recursos Humanos');
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);

        // Ataque: un agente registra un alta provisional con Host de un sitio ajeno;
        // antes el aviso a RH llevaba el enlace http://sitio-falso.example/colaboradores...
        $this->actingAs($agente)->postJson('http://sitio-falso.example/colaboradores/rapido', ['nombre' => 'Jorge', 'apellido_paterno' => 'Méndez', 'sede_id' => $this->centro->id])
            ->assertStatus(400);
        Mail::assertNothingSent();
        $this->get('http://sitio-falso.example/login')->assertStatus(400);
        $this->get('http://sitio-falso.example/up')->assertStatus(400);

        // El dominio real funciona y sus enlaces apuntan a él
        $this->actingAs($agente)->postJson('http://qa.vdcp.com.mx/colaboradores/rapido', ['nombre' => 'Jorge', 'apellido_paterno' => 'Méndez', 'sede_id' => $this->centro->id])
            ->assertCreated();
        Mail::assertSent(AltaProvisionalRegistrada::class, fn ($m) => str_starts_with($m->enlace, 'http://qa.vdcp.com.mx/'));
        $this->get('http://QA.vdcp.com.mx:443/up')->assertOk();
    }

    public function test_dominios_permitidos_salen_de_app_url_y_plataforma_hosts(): void
    {
        $config = $this->evaluarConfig('plataforma.php', ['APP_ENV' => 'qa', 'APP_URL' => 'https://qa.vdcp.com.mx', 'PLATAFORMA_HOSTS' => 'www.qa.vdcp.com.mx, otra.test']);
        $this->assertTrue($config['hosts']['verificar']);
        $this->assertSame(['qa.vdcp.com.mx', 'www.qa.vdcp.com.mx', 'otra.test'], $config['hosts']['permitidos']);

        $local = $this->evaluarConfig('plataforma.php', ['APP_ENV' => 'local', 'APP_URL' => 'http://localhost:8000']);
        $this->assertFalse($local['hosts']['verificar']);
    }

    // ---------------------------------------------------- INF-05 encabezados HTTP

    public function test_encabezados_de_seguridad_en_las_respuestas(): void
    {
        $respuesta = $this->get('/login')->assertOk();
        $respuesta->assertHeader('X-Content-Type-Options', 'nosniff');
        $respuesta->assertHeader('X-Frame-Options', 'SAMEORIGIN');
        $respuesta->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertStringContainsString('camera=(self)', $respuesta->headers->get('Permissions-Policy'));
        $this->assertStringContainsString('microphone=()', $respuesta->headers->get('Permissions-Policy'));
        $respuesta->assertHeaderMissing('Strict-Transport-Security'); // por HTTP no se anuncia HSTS

        $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
        $this->actingAs($this->sa)->get('/')->assertHeader('X-Frame-Options', 'SAMEORIGIN');
    }

    // ------------------------------------------- INF-11 comandos destructivos en Producción

    public function test_en_produccion_no_corren_comandos_que_borran_la_base(): void
    {
        $this->app['env'] = 'production';
        (new AppServiceProvider($this->app))->boot();

        $this->artisan('db:wipe', ['--force' => true])->assertFailed();
        $this->artisan('migrate:fresh', ['--force' => true])->assertFailed();
        $this->artisan('migrate:reset', ['--force' => true])->assertFailed();
        $this->assertTrue(Schema::hasTable('users'));
        $this->assertSame(1, User::whereKey($this->sa->id)->count());
    }

    // ---------------------------------------------- INF-12 demo solo en ambientes de prueba

    public function test_datos_demo_solo_en_local_testing_y_qa(): void
    {
        foreach (['prod', 'produccion', 'Production', 'staging'] as $ambiente) {
            $this->app['env'] = $ambiente;
            $this->artisan('plataforma:demo', ['--password' => 'Prueba123!'])->assertFailed();
        }
        $this->assertDatabaseMissing('empresas', ['nombre_comercial' => 'Hotel Demo']);
        $this->assertDatabaseMissing('users', ['username' => 'admin.demo']);
    }

    // ---------------------------------------------------------------- INF-13 respaldos

    public function test_respaldo_sin_sesiones_ni_cache_y_con_permisos_privados(): void
    {
        DB::table('sessions')->insert(['id' => 'SESION-SECRETA-123', 'user_id' => $this->sa->id, 'payload' => 'cGF5bG9hZA==', 'last_activity' => time()]);
        DB::table('cache')->insert(['key' => 'CLAVE-CACHE-SECRETA', 'value' => 's:1:"x";', 'expiration' => time() + 60]);

        $respaldos = app(Respaldos::class);
        $r = $respaldos->crear('manual');
        $contenido = gzdecode(file_get_contents($r['ruta']));

        $this->assertStringNotContainsString('SESION-SECRETA-123', $contenido);
        $this->assertStringNotContainsString('CLAVE-CACHE-SECRETA', $contenido);
        $this->assertStringContainsString('sessions: solo estructura', $contenido);
        $this->assertMatchesRegularExpression('/INSERT INTO .users./', $contenido);
        clearstatcache();
        $this->assertSame('600', substr(sprintf('%o', fileperms($r['ruta'])), -3));
        $this->assertSame('700', substr(sprintf('%o', fileperms($respaldos->carpeta())), -3));
    }

    public function test_respaldos_solo_los_descarga_el_superadmin(): void
    {
        $r = app(Respaldos::class)->crear('manual');
        $ruta = '/configuracion/respaldos/'.$r['archivo'];

        $this->get($ruta)->assertRedirect('/login');
        foreach (['Administrador', 'Director', 'Agente'] as $rol) {
            $this->actingAs($this->crearUsuario($this->empresa, $rol, $rol === 'Agente' ? $this->centro : null))
                ->get($ruta)->assertForbidden();
        }
        foreach (['..%2F..%2F.env', '....%2F%2F.env', 'respaldo_x_20260101_000000_manual.sql.gz%00.txt', '.env'] as $malo) {
            $this->actingAs($this->sa)->get('/configuracion/respaldos/'.$malo)->assertNotFound();
        }
        $this->actingAs($this->sa)->get($ruta)->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_el_disco_privado_no_se_publica_por_url(): void
    {
        // INF-16: Laravel registraba GET y PUT /storage/{path} sobre storage/app/private
        // (respaldos y firmas), protegidos solo por una firma con la APP_KEY
        $this->assertFalse(app('router')->has('storage.local'));
        $this->assertFalse(app('router')->has('storage.local.upload'));
        $r = app(Respaldos::class)->crear('manual');
        $this->actingAs($this->sa)->get('/storage/respaldos/'.$r['archivo'])->assertNotFound();
        $this->actingAs($this->sa)->put('/storage/respaldos/x.php', [])->assertNotFound();
    }

    // ------------------------------------------------- INF-14 cookie de sesión segura

    public function test_cookie_de_sesion_solo_https_si_app_url_es_https(): void
    {
        $this->assertTrue($this->evaluarConfig('session.php', ['APP_URL' => 'https://app.vdcp.com.mx'])['secure']);
        $this->assertFalse($this->evaluarConfig('session.php', ['APP_URL' => 'http://localhost:8000'])['secure']);
        $this->assertFalse($this->evaluarConfig('session.php', ['APP_URL' => 'https://app.vdcp.com.mx', 'SESSION_SECURE_COOKIE' => 'false'])['secure']);
    }

    // ------------------------------------------------------- INF-15 .htaccess público

    public function test_htaccess_niega_archivos_sensibles_y_php_ajeno(): void
    {
        $htaccess = file_get_contents(base_path('public/.htaccess'));

        // Sin listado de carpetas aunque mod_negotiation no esté cargado
        $this->assertMatchesRegularExpression('/<IfModule mod_autoindex\.c>\s*Options -Indexes\s*<\/IfModule>/', $htaccess);

        $negar = $this->reglasQueNiegan($htaccess);
        $this->assertNotEmpty($negar);
        $niega = fn (string $ruta) => collect($negar)->contains(fn ($r) => preg_match($r, $ruta) === 1);

        foreach (['.env', '.git/config', '.htaccess', '.user.ini', 'storage/.env', 'respaldo_qa_20261006_031500_diario.sql.gz', 'backup.sql',
            'despliegue.log', 'error_log', 'composer.json', 'composer.lock', 'storage/empresas/logos/shell.php', 'storage/x.phtml', 'x.php5',
            'artisan', 'phpunit.xml', 'config.yml', 'clave.pem', 'web.config', 'db.sqlite'] as $ruta) {
            $this->assertTrue($niega($ruta), "Debería negarse: {$ruta}");
        }
        foreach (['css/plataforma.css', 'js/plataforma.js', 'vendor/jsqr/jsQR.js', 'vendor/bootstrap/bootstrap.min.css',
            'vendor/bootstrap-icons/fonts/bootstrap-icons.woff2', 'storage/empresas/logos/logo.png', 'favicon.ico', 'robots.txt',
            '.well-known/acme-challenge/abc', '.well-known/pki-validation/x.txt'] as $ruta) {
            $this->assertFalse($niega($ruta), "No debería negarse: {$ruta}");
        }

        // index.php es la única excepción del bloqueo de PHP; la validación del certificado no se redirige
        $this->assertStringContainsString('RewriteCond %{REQUEST_URI} !^/index\.php$', $htaccess);
        $this->assertStringContainsString('RewriteCond %{REQUEST_URI} !^/\.well-known/', $htaccess);
    }

    // ---------------------------------------------------------------- auxiliares

    /**
     * Patrones de las reglas RewriteRule ... - [F] del .htaccess, como regex de PHP.
     *
     * @return list<string>
     */
    private function reglasQueNiegan(string $htaccess): array
    {
        preg_match_all('/^\s*RewriteRule\s+(\S+)\s+-\s+\[([^\]]*)\]/m', $htaccess, $m, PREG_SET_ORDER);

        return collect($m)
            ->filter(fn ($r) => in_array('F', explode(',', $r[2]), true))
            ->map(fn ($r) => '#'.$r[1].'#'.(in_array('NC', explode(',', $r[2]), true) ? 'i' : ''))
            ->values()->all();
    }

    /**
     * Evalúa un archivo de config/ con variables de entorno de prueba.
     *
     * @param  array<string, string>  $variables
     */
    private function evaluarConfig(string $archivo, array $variables): array
    {
        $nombres = array_unique([...array_keys($variables), 'SESSION_SECURE_COOKIE', 'PLATAFORMA_HOSTS', 'PLATAFORMA_VERIFICAR_HOST', 'APP_URL', 'APP_ENV']);
        $antes = [];
        foreach ($nombres as $n) {
            $antes[$n] = [$_SERVER[$n] ?? null, $_ENV[$n] ?? null, getenv($n)];
            unset($_SERVER[$n], $_ENV[$n]);
            putenv($n);
            if (isset($variables[$n])) {
                $_SERVER[$n] = $_ENV[$n] = $variables[$n];
                putenv("{$n}={$variables[$n]}");
            }
        }
        try {
            return require config_path($archivo);
        } finally {
            foreach ($antes as $n => [$server, $env, $put]) {
                unset($_SERVER[$n], $_ENV[$n]);
                putenv($n);
                if ($server !== null) {
                    $_SERVER[$n] = $server;
                }
                if ($env !== null) {
                    $_ENV[$n] = $env;
                }
                if ($put !== false) {
                    putenv("{$n}={$put}");
                }
            }
        }
    }

    private function limpiarRespaldos(): void
    {
        foreach (glob(storage_path('app/private/respaldos/respaldo_testing_*')) ?: [] as $f) {
            @unlink($f);
        }
    }
}
