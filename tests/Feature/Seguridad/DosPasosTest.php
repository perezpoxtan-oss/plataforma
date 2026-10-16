<?php

namespace Tests\Feature\Seguridad;

use App\Mail\AvisoGeneral;
use App\Models\Auditoria;
use App\Models\ConfiguracionPlataforma;
use App\Models\Empresa;
use App\Models\Notificacion;
use App\Models\Sede;
use App\Models\User;
use App\Services\DosPasos\Totp;
use App\Services\DosPasos\VerificacionDosPasos;
use App\Services\Traslado\ExportacionEmpresa;
use App\Support\CorreoPlataforma;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Verificación en dos pasos (docs/tecnico/dos-pasos.md): TOTP con los
 * vectores del RFC 6238, activar desde Mi cuenta, códigos de respaldo,
 * inicio de sesión con código, límite de intentos, «Recordar este
 * dispositivo», política por empresa, Super Administrador obligatorio,
 * restablecer por el administrador y desactivar.
 */
class DosPasosTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private User $admin;

    private User $agente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->admin->forceFill(['username' => 'admin1', 'email' => 'admin@ejemplo.mx', 'password' => 'Admin-Clave-77'])->save();
        $this->agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->agente->forceFill(['username' => 'agente1', 'email' => 'agente@ejemplo.mx', 'password' => 'Secreta123!'])->save();
        RateLimiter::clear('acceso-ip:127.0.0.1');
        $this->travelTo(Carbon::parse('2026-11-10 15:00:00', 'UTC'));
    }

    /** Activa la verificación de una cuenta sin pasar por la pantalla; devuelve [clave, códigos de respaldo]. */
    private function activar(User $usuario): array
    {
        $secreto = Totp::nuevoSecreto();
        $codigos = app(VerificacionDosPasos::class)->activar($usuario, $secreto, Totp::codigo($secreto));
        // Lo siguiente pasa en el siguiente paso de 30 segundos (el código de activación ya no sirve)
        $this->travel(31)->seconds();

        return [$secreto, $codigos];
    }

    private function entrar(string $usuario, string $contrasena)
    {
        return $this->post('/login', ['username' => $usuario, 'password' => $contrasena]);
    }

    // ------------------------------------------------------------------- TOTP

    public function test_totp_cumple_los_vectores_del_rfc_6238_y_no_acepta_el_mismo_codigo_dos_veces(): void
    {
        // RFC 6238, apéndice B (SHA1, clave ASCII «12345678901234567890», 8 dígitos)
        $clave = '12345678901234567890';
        foreach ([59 => '94287082', 1111111109 => '07081804', 1111111111 => '14050471', 1234567890 => '89005924', 2000000000 => '69279037', 20000000000 => '65353130'] as $t => $esperado) {
            $this->assertSame($esperado, Totp::hotp($clave, intdiv($t, 30), 8), "T={$t}");
        }
        // Base32 de ida y vuelta, y el código de 6 dígitos es el final del de 8
        $b32 = Totp::base32($clave);
        $this->assertSame('GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ', $b32);
        $this->assertSame($clave, Totp::deBase32(strtolower(chunk_split($b32, 4, ' '))));
        $this->assertSame('287082', Totp::codigo($b32, 59));
        $this->assertSame('081804', Totp::codigo($b32, 1111111109));

        // Ventana de ±1 paso; fuera de ella no sirve
        $momento = 1111111111;
        $paso = Totp::paso($momento);
        $this->assertSame($paso, Totp::verificar($b32, Totp::codigo($b32, $momento), null, $momento));
        $this->assertSame($paso - 1, Totp::verificar($b32, Totp::codigo($b32, $momento - 30), null, $momento));
        $this->assertSame($paso + 1, Totp::verificar($b32, Totp::codigo($b32, $momento + 30), null, $momento));
        $this->assertNull(Totp::verificar($b32, Totp::codigo($b32, $momento - 90), null, $momento));
        // Reutilización: el mismo paso (o uno anterior) ya usado se rechaza
        $this->assertNull(Totp::verificar($b32, Totp::codigo($b32, $momento), $paso, $momento));
        $this->assertNull(Totp::verificar($b32, Totp::codigo($b32, $momento - 30), $paso, $momento));
        $this->assertNull(Totp::verificar($b32, 'abcdef', null, $momento));

        // La dirección del QR que leen Google y Microsoft Authenticator
        $uri = Totp::uri($b32, 'ana@ejemplo.mx', 'Plataforma Uno');
        $this->assertStringStartsWith('otpauth://totp/Plataforma%20Uno:ana%40ejemplo.mx?secret='.$b32, $uri);
        $this->assertStringContainsString('issuer=Plataforma%20Uno', $uri);
        $this->assertStringContainsString('digits=6', $uri);
        $this->assertStringContainsString('period=30', $uri);
    }

    // --------------------------------------------------------------- Activar

    public function test_activar_desde_mi_cuenta_con_qr_y_clave_y_ver_los_codigos_una_sola_vez(): void
    {
        $this->actingAs($this->agente)->get('/mi-cuenta')->assertOk()->assertSee('Verificación en dos pasos')->assertSee('Sin activar')
            ->assertSee(route('mi-cuenta.dos-pasos'), false);

        $r = $this->actingAs($this->agente)->get('/mi-cuenta/dos-pasos')->assertOk()
            ->assertSee('<svg', false)->assertSee('Ingresar una clave de configuración')->assertSee('Google Authenticator');
        $secreto = session('dos_pasos.nueva');
        $this->assertMatchesRegularExpression('/^[A-Z2-7]{32}$/', $secreto);
        $r->assertSee(trim(chunk_split($secreto, 4, ' ')));
        // Recargar la página conserva la misma clave (la app ya la escaneó)
        $this->actingAs($this->agente)->get('/mi-cuenta/dos-pasos')->assertSee(trim(chunk_split($secreto, 4, ' ')));

        // Código equivocado: no se activa
        $this->actingAs($this->agente)->from('/mi-cuenta/dos-pasos')->post('/mi-cuenta/dos-pasos', ['codigo' => '000000'])
            ->assertRedirect('/mi-cuenta/dos-pasos')->assertSessionHasErrors('codigo');
        $this->assertFalse($this->agente->refresh()->tieneDosPasos());

        $this->actingAs($this->agente)->post('/mi-cuenta/dos-pasos', ['codigo' => Totp::codigo($secreto)])
            ->assertRedirect('/mi-cuenta/dos-pasos')->assertSessionHas('ok');
        $this->agente->refresh();
        $this->assertTrue($this->agente->tieneDosPasos());
        // La clave se guarda cifrada; los códigos de respaldo, solo como huellas
        $fila = DB::table('users')->where('id', $this->agente->id)->first();
        $this->assertNotSame($secreto, $fila->two_factor_secret);
        $this->assertStringNotContainsString($secreto, (string) $fila->two_factor_secret);
        $this->assertSame($secreto, $this->agente->two_factor_secret);
        $codigos = session('dos_pasos.codigos');
        $this->assertCount(10, $codigos);
        foreach ($codigos as $c) {
            $this->assertMatchesRegularExpression('/^[A-Z2-9]{4}-[A-Z2-9]{4}$/', $c);
            $this->assertStringNotContainsString(str_replace('-', '', $c), (string) $fila->two_factor_recovery_codes);
        }
        // Se muestran una vez; al volver, ya no
        $this->actingAs($this->agente)->get('/mi-cuenta/dos-pasos')->assertOk()->assertSee($codigos[0])->assertSee('única vez');
        $this->actingAs($this->agente)->get('/mi-cuenta/dos-pasos')->assertRedirect(route('mi-cuenta.index').'#dos-pasos');
        $this->actingAs($this->agente)->get('/mi-cuenta')->assertSee('Activa')->assertSee('Te quedan')->assertDontSee($codigos[0]);

        $this->assertTrue(Auditoria::where('evento', 'cuenta.dos_pasos_activada')->where('auditable_id', $this->agente->id)->exists());
        $this->assertTrue(Notificacion::where('user_id', $this->agente->id)->where('tipo', 'cuenta_dos_pasos')->exists());
        // La exportación de empresa no lleva la clave ni los códigos
        $this->assertMatchesRegularExpression(ExportacionEmpresa::PATRON_COLUMNA_SENSIBLE, 'two_factor_secret');
        $this->assertMatchesRegularExpression(ExportacionEmpresa::PATRON_COLUMNA_SENSIBLE, 'two_factor_recovery_codes');
        $this->assertArrayNotHasKey('two_factor_secret', $this->agente->toArray());
    }

    // ------------------------------------------------------------ Al entrar

    public function test_al_entrar_pide_el_codigo_despues_de_la_contrasena_y_no_acepta_el_mismo_codigo_dos_veces(): void
    {
        [$secreto] = $this->activar($this->agente);

        $this->entrar('agente1', 'Secreta123!')->assertRedirect('/login/verificacion');
        $this->assertGuest();
        $this->get('/login/verificacion')->assertOk()->assertSee('Verificación en dos pasos')->assertSee('Recordar este dispositivo 30 días')->assertSee('códigos de respaldo');
        // Sin la contraseña antes, la pantalla del código no sirve
        $codigo = Totp::codigo($secreto);
        $this->post('/login/verificacion', ['codigo' => '123456'])->assertSessionHasErrors('codigo');
        $this->assertGuest();
        $this->post('/login/verificacion', ['codigo' => $codigo])->assertRedirect('/');
        $this->assertAuthenticatedAs($this->agente);

        // Otra vez en el mismo paso de 30 s: el mismo código ya no sirve
        $this->post('/logout');
        $this->flushSession();
        $this->entrar('agente1', 'Secreta123!')->assertRedirect('/login/verificacion');
        $this->post('/login/verificacion', ['codigo' => $codigo])->assertSessionHasErrors('codigo');
        $this->assertGuest();
        $this->travel(30)->seconds();
        $this->post('/login/verificacion', ['codigo' => Totp::codigo($secreto)])->assertRedirect('/');
        $this->assertAuthenticatedAs($this->agente);

        // Sin cuenta pendiente, la pantalla del código regresa al acceso
        $this->post('/logout');
        $this->flushSession();
        $this->get('/login/verificacion')->assertRedirect('/login');
        // La cuenta pendiente vence a los 10 minutos
        $this->entrar('agente1', 'Secreta123!')->assertRedirect('/login/verificacion');
        $this->travel(11)->minutes();
        $this->post('/login/verificacion', ['codigo' => Totp::codigo($secreto)])->assertRedirect('/login')->assertSessionHas('acceso', 'dos_pasos_vencido');
        $this->assertGuest();
    }

    public function test_limite_de_intentos_del_codigo_y_auditoria_de_fallos(): void
    {
        [$secreto] = $this->activar($this->agente);
        $this->entrar('agente1', 'Secreta123!');
        for ($i = 1; $i < VerificacionDosPasos::INTENTOS; $i++) {
            $this->post('/login/verificacion', ['codigo' => '000000'])->assertSessionHasErrors('codigo');
        }
        $this->post('/login/verificacion', ['codigo' => '000000'])->assertRedirect('/login')->assertSessionHas('acceso', 'dos_pasos_intentos');
        $this->assertSame(VerificacionDosPasos::INTENTOS, Auditoria::where('evento', 'cuenta.dos_pasos_fallido')->where('user_id', $this->agente->id)->count());
        // Aunque vuelva a poner la contraseña, sigue detenido hasta que pasen 15 minutos
        $this->flushSession();
        RateLimiter::clear('acceso-ip:127.0.0.1');
        $this->entrar('agente1', 'Secreta123!')->assertRedirect('/login/verificacion');
        $this->post('/login/verificacion', ['codigo' => Totp::codigo($secreto)])->assertRedirect('/login');
        $this->assertGuest();
        $this->get('/login')->assertSee('Demasiados códigos equivocados');
        $this->travel(16)->minutes();
        $this->flushSession();
        RateLimiter::clear('acceso-ip:127.0.0.1');
        $this->entrar('agente1', 'Secreta123!')->assertRedirect('/login/verificacion');
        $this->post('/login/verificacion', ['codigo' => Totp::codigo($secreto)])->assertRedirect('/');
        $this->assertAuthenticatedAs($this->agente);
    }

    public function test_codigos_de_respaldo_sirven_una_vez_y_se_pueden_restablecer(): void
    {
        [, $codigos] = $this->activar($this->agente);

        $this->entrar('agente1', 'Secreta123!');
        $this->post('/login/verificacion', ['codigo' => strtolower($codigos[0])])->assertRedirect('/')->assertSessionHas('aviso');
        $this->assertAuthenticatedAs($this->agente);
        $this->assertSame(9, app(VerificacionDosPasos::class)->codigosRestantes($this->agente->refresh()));
        $this->assertTrue(Auditoria::where('evento', 'cuenta.dos_pasos_respaldo_usado')->exists());

        $this->post('/logout');
        $this->flushSession();
        $this->entrar('agente1', 'Secreta123!');
        $this->post('/login/verificacion', ['codigo' => $codigos[0]])->assertSessionHasErrors('codigo');
        $this->post('/login/verificacion', ['codigo' => str_replace('-', '', $codigos[1])])->assertRedirect('/');

        // Restablecer los códigos pide la contraseña; los anteriores dejan de servir
        $this->actingAs($this->agente)->post('/mi-cuenta/dos-pasos/codigos', ['contrasena_actual' => 'mala'])->assertSessionHasErrors('contrasena_actual');
        $this->actingAs($this->agente)->post('/mi-cuenta/dos-pasos/codigos', ['contrasena_actual' => 'Secreta123!'])->assertRedirect('/mi-cuenta/dos-pasos');
        $nuevos = session('dos_pasos.codigos');
        $this->assertCount(10, $nuevos);
        $this->assertSame(10, app(VerificacionDosPasos::class)->codigosRestantes($this->agente->refresh()));
        $this->assertNull(app(VerificacionDosPasos::class)->verificarCodigo($this->agente, $codigos[2]));
        $this->assertSame('respaldo', app(VerificacionDosPasos::class)->verificarCodigo($this->agente, $nuevos[0]));
    }

    public function test_recordar_este_dispositivo_30_dias_con_cookie_firmada(): void
    {
        [$secreto] = $this->activar($this->agente);
        $nombre = 'recordar_dos_pasos_'.$this->agente->id;

        $this->entrar('agente1', 'Secreta123!');
        $r = $this->post('/login/verificacion', ['codigo' => Totp::codigo($secreto), 'recordar' => '1'])->assertRedirect('/');
        $cookie = $r->getCookie($nombre, false);
        $this->assertNotNull($cookie);
        $this->assertTrue($cookie->isHttpOnly());
        $valor = $r->getCookie($nombre)->getValue();

        // Con la cookie: entra solo con la contraseña
        $this->post('/logout');
        $this->flushSession();
        $this->withCookie($nombre, $valor)->post('/login', ['username' => 'agente1', 'password' => 'Secreta123!'])->assertRedirect('/');
        $this->assertAuthenticatedAs($this->agente);

        // Una cookie alterada o de otra cuenta no sirve
        $this->post('/logout');
        $this->flushSession();
        $this->withCookie($nombre, $valor.'x')->post('/login', ['username' => 'agente1', 'password' => 'Secreta123!'])->assertRedirect('/login/verificacion');
        $this->flushSession();
        $this->withCookie('recordar_dos_pasos_'.$this->admin->id, $valor)->post('/login', ['username' => 'agente1', 'password' => 'Secreta123!'])->assertRedirect('/login/verificacion');

        // Después de 30 días ya no sirve
        $this->flushSession();
        $this->travel(31)->days();
        RateLimiter::clear('acceso-ip:127.0.0.1');
        $this->withCookie($nombre, $valor)->post('/login', ['username' => 'agente1', 'password' => 'Secreta123!'])->assertRedirect('/login/verificacion');

        // Al cambiar la contraseña, el dispositivo recordado se olvida
        $this->travelBack();
        $this->travelTo(Carbon::parse('2026-11-10 15:10:00', 'UTC'));
        $this->flushSession();
        $this->agente->refresh()->forceFill(['password' => 'Otra-Clave-2026'])->save();
        $this->withCookie($nombre, $valor)->post('/login', ['username' => 'agente1', 'password' => 'Otra-Clave-2026'])->assertRedirect('/login/verificacion');
    }

    // ---------------------------------------------------- Política y obligatoria

    public function test_politica_por_empresa_nadie_administradores_y_todos(): void
    {
        // Original: nadie
        $this->entrar('agente1', 'Secreta123!')->assertRedirect('/');
        $this->post('/logout');
        $this->flushSession();

        // El agente no puede cambiarla; el administrador sí (auditado)
        $this->actingAs($this->agente)->put('/configuracion/dos-pasos', ['dos_pasos' => 'todos'])->assertForbidden();
        $this->actingAs($this->admin)->get('/configuracion')->assertOk()->assertSee('Exigir la verificación en dos pasos a:')->assertSee('0 de 2');
        $this->actingAs($this->admin)->put('/configuracion/dos-pasos', ['dos_pasos' => 'administradores'])->assertRedirect(route('configuracion.index').'#cfg-dos-pasos');
        $this->assertSame('administradores', $this->empresa->refresh()->preferencias['dos_pasos']);
        $this->assertTrue(Auditoria::where('evento', 'configuracion.dos_pasos')->exists());
        $this->actingAs($this->admin)->put('/configuracion/dos-pasos', ['dos_pasos' => 'cualquiera'])->assertSessionHasErrors('dos_pasos');
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        // Administradores: el administrador sin verificación es guiado a activarla; el agente entra igual
        $this->entrar('admin1', 'Admin-Clave-77')->assertRedirect('/mi-cuenta/dos-pasos');
        $this->assertAuthenticatedAs($this->admin);
        $this->get('/usuarios')->assertRedirect('/mi-cuenta/dos-pasos');
        $this->getJson('/usuarios')->assertForbidden();
        $this->get('/mi-cuenta/dos-pasos')->assertOk()->assertSee('Tu cuenta necesita la verificación en dos pasos');
        $secreto = session('dos_pasos.nueva');
        $this->post('/mi-cuenta/dos-pasos', ['codigo' => Totp::codigo($secreto)])->assertRedirect('/mi-cuenta/dos-pasos');
        $this->get('/usuarios')->assertOk();
        // Exigida: no se puede quitar
        $this->travel(31)->seconds();
        $this->actingAs($this->admin->refresh())->delete('/mi-cuenta/dos-pasos', ['contrasena_actual' => 'Admin-Clave-77', 'codigo' => Totp::codigo($secreto)])->assertSessionHasErrors('codigo');
        $this->assertTrue($this->admin->refresh()->tieneDosPasos());
        $this->post('/logout');
        $this->flushSession();
        $this->entrar('agente1', 'Secreta123!')->assertRedirect('/');
        $this->post('/logout');
        $this->flushSession();

        // Todos: también el agente
        $this->empresa->refresh()->forceFill(['preferencias' => array_merge($this->empresa->preferencias ?? [], ['dos_pasos' => 'todos'])])->save();
        $this->entrar('agente1', 'Secreta123!')->assertRedirect('/mi-cuenta/dos-pasos');
        $this->get('/accesos')->assertRedirect('/mi-cuenta/dos-pasos');
        // Mi cuenta, salir y el latido siguen abiertos
        $this->get('/mi-cuenta')->assertOk();
        $this->get('/sesion/latido')->assertOk();

        // Restablecer valor original: nadie
        $this->post('/logout');
        $this->flushSession();
        $this->actingAs($this->admin)->put('/configuracion/restablecer', ['ajuste' => 'dos_pasos'])->assertRedirect(route('configuracion.index').'#cfg-dos-pasos');
        $this->assertSame('nadie', app(VerificacionDosPasos::class)->politica($this->empresa->refresh()));
    }

    public function test_el_super_administrador_siempre_la_necesita(): void
    {
        $sa = $this->crearSuperadmin();
        $sa->forceFill(['username' => 'superadmin', 'password' => 'Super-Clave-99'])->save();
        RateLimiter::clear('acceso-ip:127.0.0.1');

        $this->entrar('superadmin', 'Super-Clave-99')->assertRedirect('/mi-cuenta/dos-pasos')->assertSessionHas('aviso');
        $this->get('/empresas')->assertRedirect('/mi-cuenta/dos-pasos');
        $this->get('/configuracion')->assertRedirect('/mi-cuenta/dos-pasos');
        $this->get('/mi-cuenta/dos-pasos')->assertOk()->assertSee('Tu cuenta necesita la verificación en dos pasos');
        $secreto = session('dos_pasos.nueva');
        $this->post('/mi-cuenta/dos-pasos', ['codigo' => Totp::codigo($secreto)])->assertRedirect('/mi-cuenta/dos-pasos');
        $this->get('/empresas')->assertOk();
        // No la puede quitar
        $this->travel(31)->seconds();
        $this->actingAs($sa->refresh())->delete('/mi-cuenta/dos-pasos', ['contrasena_actual' => 'Super-Clave-99', 'codigo' => Totp::codigo($secreto)])
            ->assertSessionHasErrors(['codigo' => 'El Super Administrador siempre necesita la verificación en dos pasos: no se puede quitar.']);
        $this->assertTrue($sa->refresh()->tieneDosPasos());

        // Siguiente entrada: pide el código
        $this->post('/logout');
        $this->flushSession();
        $this->entrar('superadmin', 'Super-Clave-99')->assertRedirect('/login/verificacion');

        // Consola: quien perdió todo se restablece desde ahí (auditado)
        $this->artisan('plataforma:dos-pasos', ['cuenta' => 'superadmin', '--forzar' => true])->assertSuccessful();
        $this->assertFalse($sa->refresh()->tieneDosPasos());
        $this->assertTrue(Auditoria::where('evento', 'usuarios.dos_pasos_restablecida')->where('auditable_id', $sa->id)->exists());
    }

    // ------------------------------------------------- Restablecer y desactivar

    public function test_el_administrador_restablece_la_verificacion_de_quien_perdio_su_telefono(): void
    {
        ConfiguracionPlataforma::updateOrCreate(['clave' => CorreoPlataforma::CLAVE], ['valor' => [
            'host' => '10.0.0.5', 'puerto' => 587, 'cifrado' => 'tls', 'usuario' => 'avisos', 'remitente_correo' => 'avisos@ejemplo.mx', 'remitente_nombre' => 'Avisos',
        ]]);
        Mail::fake();
        $this->activar($this->agente);
        $this->activar($this->admin);

        // La lista marca quién la tiene y ofrece el botón a quien desbloquea usuarios
        $this->actingAs($this->admin)->get('/usuarios')->assertOk()->assertSee('Verificación en dos pasos activa')
            ->assertSee(route('usuarios.dos-pasos', $this->agente->id), false);
        // El agente no puede (sin permiso)
        $this->actingAs($this->agente)->patch(route('usuarios.dos-pasos', $this->admin->id))->assertForbidden();
        // Otra empresa: 404
        $otra = $this->crearEmpresa('Hotel Dos');
        $ajeno = $this->crearUsuario($otra, 'Agente');
        $this->actingAs($this->admin)->patch(route('usuarios.dos-pasos', $ajeno->id))->assertNotFound();

        $this->actingAs($this->admin)->patch(route('usuarios.dos-pasos', $this->agente->id))->assertRedirect('/usuarios')->assertSessionHas('ok');
        $this->assertFalse($this->agente->refresh()->tieneDosPasos());
        $this->assertNull($this->agente->two_factor_recovery_codes);
        $this->assertTrue(Auditoria::where('evento', 'usuarios.dos_pasos_restablecida')->where('user_id', $this->admin->id)->where('auditable_id', $this->agente->id)->exists());
        $this->assertTrue(Notificacion::where('user_id', $this->agente->id)->where('tipo', 'cuenta_dos_pasos')->where('titulo', 'Restablecieron tu verificación en dos pasos')->exists());
        Mail::assertSent(AvisoGeneral::class, fn ($m) => $m->hasTo('agente@ejemplo.mx'));
        // Ya no tiene: el botón no vuelve a salir y otro intento avisa
        $this->actingAs($this->admin)->patch(route('usuarios.dos-pasos', $this->agente->id))->assertSessionHas('error');

        // Entra solo con la contraseña y puede activarla con su teléfono nuevo
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        $this->entrar('agente1', 'Secreta123!')->assertRedirect('/');
    }

    public function test_desactivar_pide_contrasena_y_codigo(): void
    {
        [$secreto] = $this->activar($this->agente);
        $this->actingAs($this->agente)->get('/mi-cuenta')->assertSee('Dar de baja la verificación');

        $this->actingAs($this->agente)->delete('/mi-cuenta/dos-pasos', ['contrasena_actual' => 'mala', 'codigo' => Totp::codigo($secreto)])->assertSessionHasErrors('contrasena_actual');
        $this->actingAs($this->agente)->delete('/mi-cuenta/dos-pasos', ['contrasena_actual' => 'Secreta123!', 'codigo' => '000000'])->assertSessionHasErrors('codigo');
        $this->assertTrue($this->agente->refresh()->tieneDosPasos());

        $this->actingAs($this->agente)->delete('/mi-cuenta/dos-pasos', ['contrasena_actual' => 'Secreta123!', 'codigo' => Totp::codigo($secreto)])
            ->assertRedirect(route('mi-cuenta.index').'#dos-pasos')->assertSessionHas('ok');
        $this->assertFalse($this->agente->refresh()->tieneDosPasos());
        $this->assertTrue(Auditoria::where('evento', 'cuenta.dos_pasos_desactivada')->exists());
        $this->assertTrue(Auditoria::where('evento', 'cuenta.dos_pasos_fallido')->exists());
        $this->app['auth']->forgetGuards();
        $this->entrar('agente1', 'Secreta123!')->assertRedirect('/');
    }

    public function test_la_app_movil_usa_el_mismo_camino(): void
    {
        [$secreto] = $this->activar($this->agente);
        $app = ['HTTP_USER_AGENT' => 'Mozilla/5.0 (Linux; Android 14; wv) AppleWebKit/537.36 SmartCentinelApp/1.0'];
        $this->withServerVariables($app)->post('/login', ['username' => 'agente1', 'password' => 'Secreta123!'])->assertRedirect('/login/verificacion');
        $this->withServerVariables($app)->get('/login/verificacion')->assertOk()->assertSee('autocomplete="one-time-code"', false);
        $this->withServerVariables($app)->post('/login/verificacion', ['codigo' => Totp::codigo($secreto)])->assertRedirect('/');
        $this->assertAuthenticatedAs($this->agente);
    }
}
