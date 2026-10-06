<?php

namespace Tests\Feature\SeguridadAuditoria;

use App\Http\Middleware\CabecerasSeguridad;
use App\Mail\CorreoDePrueba;
use App\Models\Auditoria;
use App\Models\Empresa;
use App\Models\Rol;
use App\Models\User;
use App\Support\CorreoPlataforma;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Auditoría de seguridad 2026-10-06 — Autenticación, sesión y cabeceras.
 * Cada prueba lleva el ID del hallazgo de docs/seguridad/auditoria-2026-10-06-autenticacion.md.
 */
class AutenticacionSesionTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        RateLimiter::clear('acceso-ip:127.0.0.1');
    }

    private function agente(string $username = 'agente1'): User
    {
        $usuario = $this->crearUsuario($this->empresa, 'Agente');
        $usuario->forceFill(['username' => $username, 'email' => "{$username}@ejemplo.mx", 'password' => 'Secreta123!'])->save();

        return $usuario;
    }

    /** Ejecuta la petición con la verificación CSRF activa (en pruebas Laravel la apaga). */
    private function conCsrf(callable $peticion): mixed
    {
        $entorno = $this->app['env'];
        $this->app['env'] = 'qa';

        try {
            return $peticion();
        } finally {
            $this->app['env'] = $entorno;
        }
    }

    // ------------------------------------------------------------------ AUT-01

    public function test_aut01_el_bloqueo_no_revela_si_la_cuenta_existe(): void
    {
        $this->agente();
        $inactivo = $this->agente('inactivo1');
        $inactivo->forceFill(['activo' => false])->save();

        foreach (['agente1', 'no.existe', 'inactivo1'] as $cuenta) {
            for ($i = 0; $i < 4; $i++) {
                $this->post('/login', ['username' => $cuenta, 'password' => "mal{$i}"])->assertSessionHas('acceso', 'credenciales');
            }
            $this->post('/login', ['username' => $cuenta, 'password' => 'mal5'])
                ->assertSessionHas('acceso', 'bloqueado')->assertSessionHas('minutos', 15);
            RateLimiter::clear('acceso-ip:127.0.0.1');
        }

        // Diez minutos después las tres siguen igual de bloqueadas (mismo tiempo restante)
        $this->travel(10)->minutes();
        foreach (['agente1', 'no.existe', 'inactivo1'] as $cuenta) {
            $this->post('/login', ['username' => $cuenta, 'password' => 'x'])
                ->assertSessionHas('acceso', 'bloqueado')->assertSessionHas('minutos', 5);
        }

        // Pasado el bloqueo, las tres vuelven a "datos incorrectos"
        $this->travel(6)->minutes();
        RateLimiter::clear('acceso-ip:127.0.0.1');
        foreach (['agente1', 'no.existe', 'inactivo1'] as $cuenta) {
            $this->post('/login', ['username' => $cuenta, 'password' => 'x'])->assertSessionHas('acceso', 'credenciales');
        }
        $this->assertGuest();
    }

    public function test_aut01_el_bloqueo_de_una_cuenta_inexistente_no_depende_de_mayusculas(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['username' => $i % 2 ? 'Fantasma' : 'fantasma', 'password' => 'x']);
        }

        $this->post('/login', ['username' => 'FANTASMA', 'password' => 'x'])->assertSessionHas('acceso', 'bloqueado');
    }

    // ------------------------------------------------------------------ AUT-02

    public function test_aut02_cerrar_por_inactividad_ya_no_se_hace_con_un_get(): void
    {
        $usuario = $this->agente();
        $this->actingAs($usuario);

        // Un enlace o imagen de otro sitio (GET) ya no saca al usuario
        $this->get('/sesion/expirada')->assertRedirect('/');
        $this->assertAuthenticatedAs($usuario);

        // Sin token CSRF tampoco
        $this->conCsrf(fn () => $this->post('/sesion/expirada'))->assertRedirect('/login');
        $this->assertAuthenticatedAs($usuario);

        // El aviso del navegador (POST con token) sí cierra y avisa
        $this->post('/sesion/expirada')->assertRedirect('/login')->assertSessionHas('acceso', 'expirado');
        $this->assertGuest();

        // Sin sesión, el GET solo muestra el aviso
        $this->get('/sesion/expirada')->assertRedirect('/login')->assertSessionHas('acceso', 'expirado');
    }

    public function test_aut02_el_navegador_cierra_por_post_con_token(): void
    {
        $js = File::get(public_path('js/plataforma.js'));

        $this->assertStringContainsString('function cerrarPorInactividad()', $js);
        $this->assertStringContainsString("form.method = 'POST'", $js);
        $this->assertStringContainsString('meta[name="csrf-token"]', $js);
    }

    // ------------------------------------------------------------------ AUT-03

    public function test_aut03_cabeceras_de_seguridad_en_toda_respuesta(): void
    {
        $respuesta = $this->get('/login')->assertOk();

        $csp = (string) $respuesta->headers->get('Content-Security-Policy');
        $this->assertSame(CabecerasSeguridad::CSP, $csp);
        $this->assertStringContainsString("script-src 'self';", $csp);
        $this->assertStringNotContainsString("'unsafe-eval'", $csp);
        $this->assertStringContainsString("frame-ancestors 'self'", $csp);
        $this->assertStringContainsString("object-src 'none'", $csp);
        $respuesta->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'same-origin')
            ->assertHeader('Permissions-Policy', CabecerasSeguridad::PERMISOS)
            ->assertHeaderMissing('X-Powered-By')
            ->assertHeaderMissing('Strict-Transport-Security');
        $this->assertStringContainsString('camera=(self)', CabecerasSeguridad::PERMISOS);
        $this->assertStringContainsString('microphone=()', CabecerasSeguridad::PERMISOS);

        // Páginas de error también
        $this->get('/no-existe-esta-ruta')->assertNotFound()->assertHeader('X-Frame-Options', 'SAMEORIGIN');

        // HSTS solo por HTTPS
        $this->get('https://localhost/login')->assertHeader('Strict-Transport-Security', 'max-age=31536000');
    }

    public function test_aut03_con_sesion_las_pantallas_no_se_guardan_en_cache(): void
    {
        $this->get('/login')->assertOk();
        $this->assertStringNotContainsString('no-store', (string) $this->get('/login')->headers->get('Cache-Control'));

        $cache = (string) $this->actingAs($this->agente())->get('/')->assertOk()->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cache);
    }

    public function test_aut03_las_vistas_no_tienen_javascript_en_linea(): void
    {
        // La CSP (script-src 'self') solo funciona si nadie agrega <script> en línea,
        // atributos on*="" ni enlaces javascript:
        $hallazgos = [];
        foreach (File::allFiles(resource_path('views')) as $archivo) {
            $html = $archivo->getContents();
            preg_match_all('/<script\b(?![^>]*\bsrc=)(?![^>]*type="application\/json")[^>]*>/i', $html, $scripts);
            preg_match_all('/<[a-z][^>]*\son[a-z]+\s*=\s*["\']/i', $html, $eventos);
            preg_match_all('/(href|src|action)\s*=\s*["\']\s*javascript:/i', $html, $enlaces);
            foreach ([...$scripts[0], ...$eventos[0], ...$enlaces[0]] as $encontrado) {
                $hallazgos[] = $archivo->getRelativePathname().': '.$encontrado;
            }
        }

        $this->assertSame([], $hallazgos);
    }

    // ------------------------------------------------------------------ AUT-04

    public function test_aut04_la_contrasena_smtp_no_se_guarda_en_la_sesion_al_fallar_la_validacion(): void
    {
        $this->actingAs($this->crearSuperadmin())
            ->from('/configuracion')
            ->put('/configuracion/correo', [
                'host' => 'https://no-valido', 'puerto' => 587, 'cifrado' => 'tls', 'usuario' => 'avisos',
                'contrasena' => 'SmtpSuperSecreta1', 'remitente_correo' => 'avisos@ejemplo.mx',
            ])
            ->assertSessionHasErrors('host');

        $viejo = session('_old_input', []);
        $this->assertSame('avisos', $viejo['usuario'] ?? null);
        $this->assertArrayNotHasKey('contrasena', $viejo);
    }

    // ------------------------------------------------------------------ AUT-05

    public function test_aut05_politica_de_contrasena(): void
    {
        $admin = $this->crearUsuario($this->empresa, 'Administrador');
        $rol = Rol::where('empresa_id', $this->empresa->id)->where('nombre', 'Agente')->firstOrFail();
        $datos = fn (string $clave) => [
            'name' => 'Laura Gómez', 'username' => 'laura.gomez', 'email' => 'laura@ejemplo.mx',
            'password' => $clave, 'rol_id' => $rol->id, 'sede_id' => null,
        ];

        foreach (['Password123', 'Hotel123', 'Laura.Gomez2026', 'x9'.str_repeat('a', 72)] as $debil) {
            $this->actingAs($admin)->from('/usuarios')->post('/usuarios', $datos($debil))->assertSessionHasErrors('password');
        }
        $this->assertDatabaseMissing('users', ['username' => 'laura.gomez']);

        $this->actingAs($admin)->post('/usuarios', $datos('Turno-Noche-47'))->assertSessionHasNoErrors();
        $this->assertDatabaseHas('users', ['username' => 'laura.gomez']);
    }

    public function test_aut05_la_contrasena_se_recifra_si_cambia_el_costo(): void
    {
        $usuario = $this->agente();
        $this->assertStringStartsWith('$2y$04$', $usuario->fresh()->password);

        config(['hashing.bcrypt.rounds' => 5]);
        Hash::forgetDrivers();

        $this->post('/login', ['username' => 'agente1', 'password' => 'Secreta123!'])->assertRedirect('/');
        $this->assertStringStartsWith('$2y$05$', $usuario->fresh()->password);
        $this->assertTrue(Hash::check('Secreta123!', $usuario->fresh()->password));
    }

    // ------------------------------------------------------------------ AUT-06

    public function test_aut06_produccion_nunca_muestra_el_detalle_tecnico(): void
    {
        $leer = function (array $variables): array {
            $previas = [];
            foreach ($variables as $nombre => $valor) {
                $previas[$nombre] = [$_ENV[$nombre] ?? null, $_SERVER[$nombre] ?? null];
                $_ENV[$nombre] = $_SERVER[$nombre] = $valor;
            }
            try {
                return ['app' => require config_path('app.php'), 'session' => require config_path('session.php')];
            } finally {
                foreach ($previas as $nombre => [$env, $server]) {
                    if ($env === null) {
                        unset($_ENV[$nombre]);
                    } else {
                        $_ENV[$nombre] = $env;
                    }
                    if ($server === null) {
                        unset($_SERVER[$nombre]);
                    } else {
                        $_SERVER[$nombre] = $server;
                    }
                }
            }
        };

        $prod = $leer(['APP_ENV' => 'production', 'APP_DEBUG' => 'true', 'APP_URL' => 'https://seg.ejemplo.mx']);
        $this->assertFalse($prod['app']['debug']);
        $this->assertTrue($prod['session']['secure']);
        $this->assertTrue($prod['session']['http_only']);
        $this->assertSame('lax', $prod['session']['same_site']);

        $qa = $leer(['APP_ENV' => 'qa', 'APP_DEBUG' => 'true', 'APP_URL' => 'http://localhost']);
        $this->assertTrue($qa['app']['debug']);
        $this->assertFalse($qa['session']['secure']);
    }

    public function test_aut06_un_error_no_muestra_rutas_ni_mensajes_internos(): void
    {
        config(['app.debug' => false]);
        Route::middleware('web')->get('/_prueba_error', fn () => throw new RuntimeException('detalle-interno /home/vdcpcomm/app'));

        $this->get('/_prueba_error')->assertStatus(500)->assertSee('Algo salió mal')
            ->assertDontSee('detalle-interno')->assertDontSee('/home/vdcpcomm')->assertDontSee('RuntimeException');
        $this->getJson('/_prueba_error')->assertStatus(500)->assertExactJson(['message' => 'Server Error']);
    }

    // ------------------------------------------------------------- Sin hallazgo

    public function test_sesion_nueva_y_token_nuevo_al_entrar_y_al_salir(): void
    {
        $this->agente();
        $this->get('/login')->assertOk();
        $tokenAnonimo = session()->token();

        $this->post('/login', ['username' => 'agente1', 'password' => 'Secreta123!'])->assertRedirect('/');
        $tokenSesion = session()->token();
        $this->assertNotSame($tokenAnonimo, $tokenSesion);

        $this->post('/logout')->assertRedirect('/login');
        $this->assertNotSame($tokenSesion, session()->token());
        $this->assertGuest();
    }

    public function test_toda_ruta_que_cambia_datos_exige_token_csrf(): void
    {
        $sinWeb = [];
        foreach (Route::getRoutes() as $ruta) {
            $cambia = array_diff($ruta->methods(), ['GET', 'HEAD', 'OPTIONS']) !== [];
            if ($cambia && ! in_array('web', $ruta->gatherMiddleware(), true)) {
                $sinWeb[] = $ruta->uri();
            }
        }
        $this->assertSame([], $sinWeb, 'Rutas que cambian datos fuera del grupo web (sin CSRF)');

        // AUT-07: el disco privado (respaldos de la base) no se publica en /storage/{ruta}
        $this->assertFalse(Route::has('storage.local'));
        $this->assertFalse(Route::has('storage.local.upload'));
        $this->get('/storage/respaldos/plataforma.sql.gz')->assertNotFound();

        // Ninguna excepción al CSRF
        $middleware = app(ValidateCsrfToken::class);
        $this->assertSame([], $middleware->getExcludedPaths());

        // Prueba real: endpoints JSON (registro rápido, lector, firmas) sin token se rechazan
        $usuario = $this->crearUsuario($this->empresa, 'Administrador');
        $this->actingAs($usuario);
        foreach (['/colaboradores/rapido', '/personas/rapido', '/vehiculos/rapido', '/pases-salida/1/firmas', '/empresa-activa', '/logout'] as $uri) {
            $this->conCsrf(fn () => $this->postJson($uri, ['nombre' => 'X']))->assertStatus(419);
        }
        $this->assertAuthenticatedAs($usuario);

        // El login también exige token (CSRF de inicio de sesión)
        auth()->logout();
        $this->agente();
        $this->conCsrf(fn () => $this->post('/login', ['username' => 'agente1', 'password' => 'Secreta123!']))
            ->assertRedirect('/login')->assertSessionHas('acceso', 'pagina_vencida');
        $this->assertGuest();
    }

    public function test_cors_no_abre_la_plataforma_a_otros_sitios(): void
    {
        $this->actingAs($this->agente());

        $this->call('OPTIONS', '/colaboradores/rapido', [], [], [], [
            'HTTP_ORIGIN' => 'https://sitio-malicioso.example', 'HTTP_ACCESS_CONTROL_REQUEST_METHOD' => 'POST',
        ])->assertHeaderMissing('Access-Control-Allow-Origin')->assertHeaderMissing('Access-Control-Allow-Credentials');

        $this->get('/', ['Origin' => 'https://sitio-malicioso.example'])->assertHeaderMissing('Access-Control-Allow-Origin');
    }

    public function test_x_forwarded_for_no_evade_el_limite_por_equipo(): void
    {
        config(['plataforma.sesion.intentos_por_ip' => 3]);

        for ($i = 0; $i < 3; $i++) {
            $this->post('/login', ['username' => "nadie{$i}", 'password' => 'x'], ['X-Forwarded-For' => "10.0.0.{$i}"]);
        }

        $this->post('/login', ['username' => 'nadie', 'password' => 'x'], ['X-Forwarded-For' => '10.9.9.9'])
            ->assertSessionHas('acceso', 'equipo');
    }

    public function test_solo_el_super_administrador_cambia_la_empresa_de_trabajo(): void
    {
        $otra = $this->crearEmpresa('Hotel Ajeno');
        $agente = $this->agente();

        $this->actingAs($agente)->post('/empresa-activa', ['empresa_id' => $otra->id])->assertForbidden();
        $this->assertNull(session('empresa_activa_id'));

        // Aunque la sesión trajera otra empresa, un usuario normal trabaja siempre con la suya
        $this->withSession(['empresa_activa_id' => $otra->id])->get('/')->assertOk();
        $this->assertSame($this->empresa->id, app(Tenant::class)->empresaId());
    }

    public function test_empresa_desactivada_corta_las_sesiones_abiertas(): void
    {
        $agente = $this->agente();
        $this->actingAs($agente)->get('/')->assertOk();

        $this->empresa->forceFill(['activo' => false])->save();
        $agente->unsetRelation('empresa'); // en producción cada petición carga al usuario de nuevo

        $this->get('/')->assertRedirect('/login')->assertSessionHas('acceso', 'inactiva');
        $this->assertGuest();
    }

    public function test_inactividad_en_peticiones_json_responde_401(): void
    {
        $this->post('/login', ['username' => $this->agente()->username, 'password' => 'Secreta123!']);
        $this->travel(21)->minutes();

        $this->getJson('/sesion/latido')->assertStatus(401)->assertJson(['ok' => false]);
        $this->assertGuest();
    }

    public function test_bloqueo_como_ataque_se_deshace_con_el_desbloqueo_manual_auditado(): void
    {
        $admin = $this->crearUsuario($this->empresa, 'Administrador');
        $victima = $this->agente();

        // El atacante bloquea la cuenta con 5 intentos
        for ($i = 0; $i < 5; $i++) {
            $this->post('/login', ['username' => 'agente1', 'password' => 'adivina']);
        }
        $this->assertTrue($victima->fresh()->estaBloqueado());

        $this->actingAs($admin)->patch("/usuarios/{$victima->id}/desbloquear")->assertSessionHas('ok');
        $auditoria = Auditoria::where('evento', 'usuarios.desbloqueado')->where('auditable_id', $victima->id)->firstOrFail();
        $this->assertSame($admin->id, $auditoria->user_id);
        auth()->logout();

        RateLimiter::clear('acceso-ip:127.0.0.1');
        $this->post('/login', ['username' => 'agente1', 'password' => 'Secreta123!'])->assertRedirect('/');
        $this->assertAuthenticatedAs($victima);
    }

    public function test_la_bitacora_de_usuarios_nunca_guarda_contrasenas_ni_hashes(): void
    {
        $admin = $this->crearUsuario($this->empresa, 'Administrador');
        $rol = Rol::where('empresa_id', $this->empresa->id)->where('nombre', 'Agente')->firstOrFail();
        $datos = ['name' => 'Laura Gómez', 'username' => 'laura.g', 'email' => 'laura@ejemplo.mx', 'rol_id' => $rol->id, 'sede_id' => null];

        $this->actingAs($admin)->post('/usuarios', $datos + ['password' => 'Primera-Clave-81'])->assertSessionHasNoErrors();
        $usuario = User::where('username', 'laura.g')->firstOrFail();
        $this->actingAs($admin)->put("/usuarios/{$usuario->id}", $datos + ['password' => 'Segunda-Clave-82', 'activo' => '1'])->assertSessionHasNoErrors();
        $this->actingAs($admin)->put("/usuarios/{$usuario->id}", $datos + ['password' => '', 'activo' => '1'])->assertSessionHasNoErrors();

        $filas = Auditoria::where('auditable_type', User::class)->where('auditable_id', $usuario->id)->orderBy('id')->get();
        $this->assertCount(3, $filas);
        $texto = $filas->map(fn ($f) => json_encode([$f->antes, $f->despues]))->implode("\n");
        foreach (['Primera-Clave-81', 'Segunda-Clave-82', '$2y$', 'remember_token', 'password'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $texto);
        }
        // Solo se anota que cambió
        $this->assertSame('cambiada', $filas[1]->despues['contrasena'] ?? null);
        $this->assertArrayNotHasKey('contrasena', $filas[2]->despues);
    }

    public function test_el_nombre_del_remitente_no_permite_inyectar_cabeceras_de_correo(): void
    {
        $correo = app(CorreoPlataforma::class);
        $correo->guardar([
            'host' => 'mail.ejemplo.mx', 'puerto' => 587, 'cifrado' => 'tls', 'usuario' => 'avisos',
            'remitente_correo' => 'avisos@ejemplo.mx', 'remitente_nombre' => "Avisos\r\nBcc: espia@malicioso.example",
        ], 'SmtpClave1');
        $correo->aplicar();

        // La contraseña SMTP se guarda cifrada (nunca en claro)
        $this->assertStringNotContainsString('SmtpClave1', json_encode($correo->datos()));

        config(['mail.mailers.'.CorreoPlataforma::MAILER.'.transport' => 'array']);
        Mail::purge(CorreoPlataforma::MAILER);
        Mail::mailer(CorreoPlataforma::MAILER)->to('destino@ejemplo.mx')->send(new CorreoDePrueba('Prueba'));

        $enviado = Mail::mailer(CorreoPlataforma::MAILER)->getSymfonyTransport()->messages()->first();
        $this->assertDoesNotMatchRegularExpression('/^Bcc:/mi', $enviado->toString());
        $destinatarios = array_map(fn ($d) => $d->getAddress(), $enviado->getEnvelope()->getRecipients());
        $this->assertSame(['destino@ejemplo.mx'], $destinatarios);
    }
}
