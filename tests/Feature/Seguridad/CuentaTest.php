<?php

namespace Tests\Feature\Seguridad;

use App\Mail\BienvenidaUsuario;
use App\Mail\CuentaBloqueada;
use App\Mail\RestablecerContrasena;
use App\Models\Auditoria;
use App\Models\Colaborador;
use App\Models\ConfiguracionPlataforma;
use App\Models\Empresa;
use App\Models\Notificacion;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\User;
use App\Support\CorreoPlataforma;
use App\Support\Menu\MisPendientes;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * La cuenta es de su dueño (docs/criterios-ux.md, regla 6): Mi cuenta,
 * cambio obligatorio al primer acceso, Olvidé mi contraseña, enlace de
 * bienvenida, Restablecer desde Usuarios, avisos de bloqueo, baja que
 * desactiva la cuenta y «Cuentas por revisar».
 */
class CuentaTest extends TestCase
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
    }

    private function conCorreo(): void
    {
        ConfiguracionPlataforma::updateOrCreate(['clave' => CorreoPlataforma::CLAVE], ['valor' => [
            'host' => '10.0.0.5', 'puerto' => 587, 'cifrado' => 'tls', 'usuario' => 'avisos', 'remitente_correo' => 'avisos@ejemplo.mx', 'remitente_nombre' => 'Avisos',
        ]]);
        Mail::fake();
    }

    private function rolAgente(): Rol
    {
        return Rol::where('empresa_id', $this->empresa->id)->where('nombre', 'Agente')->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function datosNuevo(array $extra = []): array
    {
        return array_merge([
            'name' => 'Laura Gómez', 'username' => 'laura.gomez', 'email' => 'laura@ejemplo.mx',
            'rol_id' => $this->rolAgente()->id, 'sede_id' => $this->centro->id,
        ], $extra);
    }

    private function enlaceDe(string $clase): string
    {
        $enlace = null;
        Mail::assertSent($clase, function ($m) use (&$enlace) {
            $enlace = $m->enlace;

            return true;
        });
        $this->assertNotNull($enlace);

        return $enlace;
    }

    // ------------------------------------------------------------------ Mi cuenta

    public function test_mi_cuenta_muestra_los_datos_y_no_permite_cambiar_rol_sedes_ni_correo(): void
    {
        $r = $this->actingAs($this->agente)->get('/mi-cuenta')->assertOk()
            ->assertSee('Mi cuenta')->assertSee('@agente1')->assertSee('agente@ejemplo.mx')->assertSee('Agente')->assertSee('Sede CEN')
            ->assertSee('Cambiar mi contraseña')->assertSee('pide a tu administrador');
        // Solo la contraseña se captura: ningún campo de rol, sede ni correo
        $html = $r->getContent();
        $this->assertStringNotContainsString('name="rol_id"', $html);
        $this->assertStringNotContainsString('name="sede_id"', $html);
        $this->assertStringNotContainsString('name="email"', $html);

        // Enlaces a Mi cuenta en el encabezado (PC) y en el menú lateral (celular)
        $this->actingAs($this->agente)->get('/')->assertOk()
            ->assertSee('href="'.url('/mi-cuenta').'"', false)->assertSee('Mi cuenta');

        // Un intento de colar rol o sede por el formulario de contraseña no cambia nada
        $rolAntes = $this->agente->nombreRolPrincipal();
        $this->actingAs($this->agente)->put('/mi-cuenta/contrasena', [
            'contrasena_actual' => 'Secreta123!', 'password' => 'Nueva-Clave-2026', 'password_confirmation' => 'Nueva-Clave-2026',
            'rol_id' => Rol::where('empresa_id', $this->empresa->id)->where('nombre', 'Administrador')->value('id'), 'sede_id' => '', 'email' => 'otro@ejemplo.mx',
        ])->assertRedirect('/mi-cuenta')->assertSessionHas('ok');
        $this->agente->refresh();
        $this->assertSame($rolAntes, $this->agente->nombreRolPrincipal());
        $this->assertSame('agente@ejemplo.mx', $this->agente->email);
        $this->assertSame($this->centro->id, $this->agente->sedeAsignadaId());
    }

    public function test_cambiar_la_contrasena_propia_cierra_las_demas_sesiones_audita_y_avisa(): void
    {
        config(['session.driver' => 'database']);
        DB::table('sessions')->insert([
            ['id' => 'sesion-otra', 'user_id' => $this->agente->id, 'payload' => '', 'last_activity' => time()],
            ['id' => 'sesion-admin', 'user_id' => $this->admin->id, 'payload' => '', 'last_activity' => time()],
        ]);

        $this->actingAs($this->agente)->put('/mi-cuenta/contrasena', [
            'contrasena_actual' => 'Secreta123!', 'password' => 'Nueva-Clave-2026', 'password_confirmation' => 'Nueva-Clave-2026',
        ])->assertRedirect('/mi-cuenta')->assertSessionHas('ok');

        $this->agente->refresh();
        $this->assertTrue(Hash::check('Nueva-Clave-2026', $this->agente->password));
        $this->assertFalse($this->agente->debe_cambiar_contrasena);
        $this->assertDatabaseMissing('sessions', ['id' => 'sesion-otra']);
        $this->assertDatabaseHas('sessions', ['id' => 'sesion-admin']);

        $fila = Auditoria::where('evento', 'usuarios.contrasena_cambiada')->where('auditable_id', $this->agente->id)->firstOrFail();
        $this->assertSame($this->agente->id, $fila->user_id);
        $texto = json_encode([$fila->antes, $fila->despues]);
        foreach (['Secreta123!', 'Nueva-Clave-2026', '$2y$'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $texto);
        }
        $this->assertSame('cambiada', $fila->despues['contrasena']);

        $aviso = Notificacion::withoutGlobalScopes()->where('user_id', $this->agente->id)->where('tipo', 'cuenta_contrasena')->firstOrFail();
        $this->assertSame('Cambiaste tu contraseña', $aviso->titulo);
    }

    public function test_contrasena_actual_equivocada_debil_o_igual_no_se_acepta(): void
    {
        $this->actingAs($this->agente)->from('/mi-cuenta')->put('/mi-cuenta/contrasena', [
            'contrasena_actual' => 'NoEsEsta1', 'password' => 'Nueva-Clave-2026', 'password_confirmation' => 'Nueva-Clave-2026',
        ])->assertRedirect('/mi-cuenta')->assertSessionHasErrors('contrasena_actual');

        foreach (['corta1', 'soloLetras', '12345678', 'Password123', 'agente1-clave9'] as $debil) {
            $this->actingAs($this->agente)->from('/mi-cuenta')->put('/mi-cuenta/contrasena', [
                'contrasena_actual' => 'Secreta123!', 'password' => $debil, 'password_confirmation' => $debil,
            ])->assertSessionHasErrors('password');
        }
        $this->actingAs($this->agente)->from('/mi-cuenta')->put('/mi-cuenta/contrasena', [
            'contrasena_actual' => 'Secreta123!', 'password' => 'Nueva-Clave-2026', 'password_confirmation' => 'Otra-Clave-2026',
        ])->assertSessionHasErrors('password');
        $this->actingAs($this->agente)->from('/mi-cuenta')->put('/mi-cuenta/contrasena', [
            'contrasena_actual' => 'Secreta123!', 'password' => 'Secreta123!', 'password_confirmation' => 'Secreta123!',
        ])->assertSessionHasErrors('password');

        $this->assertTrue(Hash::check('Secreta123!', $this->agente->fresh()->password));
        $this->assertSame(0, Auditoria::where('evento', 'usuarios.contrasena_cambiada')->count());
        // La contraseña nunca se guarda en la sesión al fallar
        $this->assertNull(session()->getOldInput('password'));
        $this->assertNull(session()->getOldInput('contrasena_actual'));
    }

    // --------------------------------------------------- Primer acceso obligatorio

    public function test_los_usuarios_existentes_no_quedan_marcados(): void
    {
        $this->assertFalse($this->agente->fresh()->debe_cambiar_contrasena);
        $this->actingAs($this->agente)->get('/')->assertOk();
    }

    public function test_con_la_marca_solo_se_puede_cambiar_la_contrasena(): void
    {
        $this->agente->forceFill(['debe_cambiar_contrasena' => true])->save();

        $this->post('/login', ['username' => 'agente1', 'password' => 'Secreta123!'])->assertRedirect('/');
        $this->get('/')->assertRedirect('/mi-cuenta');
        $this->get('/accesos')->assertRedirect('/mi-cuenta');
        $this->get('/manual')->assertRedirect('/mi-cuenta');
        $this->getJson('/colaboradores/buscar?q=a')->assertStatus(403);
        $this->get('/mi-cuenta')->assertOk()->assertSee('Crea tu propia contraseña para continuar');
        $this->getJson('/notificaciones/resumen')->assertOk();

        $this->put('/mi-cuenta/contrasena', [
            'contrasena_actual' => 'Secreta123!', 'password' => 'Mi-Propia-Clave-9', 'password_confirmation' => 'Mi-Propia-Clave-9',
        ])->assertRedirect('/mi-cuenta');
        $this->assertFalse($this->agente->fresh()->debe_cambiar_contrasena);
        $this->get('/')->assertOk();
        $this->assertTrue((bool) Auditoria::where('evento', 'usuarios.contrasena_cambiada')->value('despues')['primer_acceso'] ?? false);

        // Salir siempre se puede
        $this->agente->forceFill(['debe_cambiar_contrasena' => true])->save();
        $this->post('/logout')->assertRedirect('/login');
    }

    public function test_el_administrador_que_escribe_la_contrasena_deja_la_cuenta_marcada(): void
    {
        $this->actingAs($this->admin)->post('/usuarios', $this->datosNuevo(['password' => 'Inicial-Clave-55', 'contrasena_modo' => 'manual']))
            ->assertRedirect('/usuarios')->assertSessionHas('ok');
        $nuevo = User::where('username', 'laura.gomez')->firstOrFail();
        $this->assertTrue($nuevo->debe_cambiar_contrasena);
        $this->assertStringContainsString('cambiar la contraseña', session('ok'));

        // Sin correo configurado, la única opción es escribirla (y es obligatoria)
        $this->actingAs($this->admin)->from('/usuarios')->post('/usuarios', $this->datosNuevo(['username' => 'otro.mas', 'email' => 'otro@ejemplo.mx']))
            ->assertSessionHasErrors('password');

        // Editar con contraseña nueva también marca; sin contraseña, no
        $this->agente->forceFill(['debe_cambiar_contrasena' => false])->save();
        $base = ['name' => $this->agente->name, 'username' => 'agente1', 'email' => 'agente@ejemplo.mx', 'rol_id' => $this->rolAgente()->id, 'sede_id' => $this->centro->id, 'activo' => '1'];
        $this->actingAs($this->admin)->put("/usuarios/{$this->agente->id}", $base + ['password' => ''])->assertSessionHasNoErrors();
        $this->assertFalse($this->agente->fresh()->debe_cambiar_contrasena);
        $this->actingAs($this->admin)->put("/usuarios/{$this->agente->id}", $base + ['password' => 'Otra-Inicial-88'])->assertSessionHasNoErrors();
        $this->assertTrue($this->agente->fresh()->debe_cambiar_contrasena);
    }

    // --------------------------------------------------------- Olvidé mi contraseña

    public function test_la_pantalla_de_acceso_enlaza_a_olvide_mi_contrasena_y_sin_smtp_lo_dice(): void
    {
        $this->get('/login')->assertOk()->assertSee('Olvidé mi contraseña')->assertSee('href="'.url('/olvide-contrasena').'"', false);
        $this->get('/olvide-contrasena')->assertOk()
            ->assertSee('no tiene correo configurado')->assertDontSee('name="cuenta"', false);
        // Sin correo no se envía nada ni se revela nada
        $this->post('/olvide-contrasena', ['cuenta' => 'agente1'])->assertRedirect('/olvide-contrasena')->assertSessionHas('enviado');
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'agente@ejemplo.mx']);
    }

    public function test_la_respuesta_es_la_misma_exista_o_no_la_cuenta(): void
    {
        $this->conCorreo();
        $inactivo = $this->crearUsuario($this->empresa, 'Agente');
        $inactivo->forceFill(['username' => 'inactivo1', 'email' => 'inactivo@ejemplo.mx', 'activo' => false])->save();
        $sinCorreo = $this->crearUsuario($this->empresa, 'Agente');
        $sinCorreo->forceFill(['username' => 'sincorreo1', 'email' => ''])->save();

        $respuestas = [];
        foreach (['agente1', 'agente@ejemplo.mx', 'nadie.existe', 'nadie@ejemplo.mx', 'inactivo1', 'sincorreo1'] as $i => $cuenta) {
            // Las dos primeras son la misma cuenta en el mismo minuto; el resto se espacia para no topar el límite por equipo
            if ($i >= 2) {
                $this->travel(30)->seconds();
            }
            $r = $this->post('/olvide-contrasena', ['cuenta' => $cuenta])->assertRedirect('/olvide-contrasena');
            $respuestas[] = session('enviado');
            $this->followingRedirects()->get('/olvide-contrasena');
        }
        $this->assertCount(1, array_unique($respuestas));
        $this->assertStringContainsString('Si la cuenta existe y tiene correo', $respuestas[0]);

        // Solo la cuenta real recibió correo (y una sola vez: usuario y correo son la misma cuenta, con 1 min de espera)
        Mail::assertSent(RestablecerContrasena::class, 1);
        Mail::assertSent(RestablecerContrasena::class, fn ($m) => $m->hasTo('agente@ejemplo.mx') && ! $m->pedidoPorAdministrador);
        $this->assertDatabaseHas('password_reset_tokens', ['email' => 'agente@ejemplo.mx']);
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'inactivo@ejemplo.mx']);

        $fila = Auditoria::where('evento', 'usuarios.recuperacion_solicitada')->firstOrFail();
        $this->assertSame($this->agente->id, $fila->auditable_id);
        $this->assertTrue($fila->despues['correo_enviado']);
        $this->assertSame('127.0.x.x', $fila->despues['origen']);
        $this->assertSame(0, Auditoria::where('evento', 'usuarios.recuperacion_solicitada')->where('auditable_id', $inactivo->id)->count());
    }

    public function test_el_enlace_valido_crea_la_contrasena_y_queda_inutilizado(): void
    {
        $this->conCorreo();
        $this->post('/olvide-contrasena', ['cuenta' => 'agente1']);
        $enlace = $this->enlaceDe(RestablecerContrasena::class);
        $this->assertStringContainsString('/restablecer-contrasena/', $enlace);
        $this->assertStringContainsString('signature=', $enlace);
        $this->assertStringNotContainsString('agente@ejemplo.mx', $enlace);

        $this->get($enlace)->assertOk()->assertSee('Crea tu contraseña nueva')->assertSee('agente1');
        $token = (string) parse_url($enlace, PHP_URL_PATH);
        $token = substr($token, strrpos($token, '/') + 1);

        $this->post('/restablecer-contrasena', [
            'token' => $token, 'email' => 'agente@ejemplo.mx', 'password' => 'Recuperada-2026', 'password_confirmation' => 'Recuperada-2026',
        ])->assertRedirect('/login')->assertSessionHas('acceso', 'contrasena_creada');
        $this->assertGuest();
        $this->assertTrue(Hash::check('Recuperada-2026', $this->agente->fresh()->password));
        $this->assertDatabaseMissing('password_reset_tokens', ['email' => 'agente@ejemplo.mx']);
        $this->assertDatabaseHas('auditoria', ['evento' => 'usuarios.recuperacion_completada', 'auditable_id' => $this->agente->id]);
        $this->assertSame(1, Notificacion::withoutGlobalScopes()->where('user_id', $this->agente->id)->where('tipo', 'cuenta_contrasena')->count());
        $this->get('/login')->assertSee('Tu contraseña nueva quedó guardada');

        // El mismo enlace ya no sirve
        $this->get($enlace)->assertRedirect('/olvide-contrasena')->assertSessionHas('error');
        $this->post('/restablecer-contrasena', [
            'token' => $token, 'email' => 'agente@ejemplo.mx', 'password' => 'Otra-Vez-2026', 'password_confirmation' => 'Otra-Vez-2026',
        ])->assertRedirect('/olvide-contrasena')->assertSessionHas('error');
        $this->assertTrue(Hash::check('Recuperada-2026', $this->agente->fresh()->password));

        // Con la contraseña nueva se entra
        $this->post('/login', ['username' => 'agente1', 'password' => 'Recuperada-2026'])->assertRedirect('/');
    }

    public function test_el_enlace_vencido_o_alterado_no_sirve_y_la_contrasena_debil_se_rechaza(): void
    {
        $this->conCorreo();
        $this->post('/olvide-contrasena', ['cuenta' => 'agente1']);
        $enlace = $this->enlaceDe(RestablecerContrasena::class);
        $token = substr((string) parse_url($enlace, PHP_URL_PATH), strrpos((string) parse_url($enlace, PHP_URL_PATH), '/') + 1);

        // Alterado (otro usuario en la firma)
        $this->get(str_replace('u='.$this->agente->id, 'u='.$this->admin->id, $enlace))->assertRedirect('/olvide-contrasena');
        // Débil
        $this->get($enlace)->assertOk();
        $this->from($enlace)->post('/restablecer-contrasena', [
            'token' => $token, 'email' => 'agente@ejemplo.mx', 'password' => 'agente1-abc', 'password_confirmation' => 'agente1-abc',
        ])->assertSessionHasErrors('password');
        // Vencido (61 minutos)
        $this->travel(61)->minutes();
        $this->get($enlace)->assertRedirect('/olvide-contrasena')->assertSessionHas('error');
        $this->post('/restablecer-contrasena', [
            'token' => $token, 'email' => 'agente@ejemplo.mx', 'password' => 'Recuperada-2026', 'password_confirmation' => 'Recuperada-2026',
        ])->assertRedirect('/olvide-contrasena')->assertSessionHas('error');
        $this->assertTrue(Hash::check('Secreta123!', $this->agente->fresh()->password));
    }

    public function test_un_enlace_no_sirve_para_otra_cuenta_ni_para_otra_empresa(): void
    {
        $this->conCorreo();
        $otra = $this->crearEmpresa('Hotel Dos');
        $ajeno = $this->crearUsuario($otra, 'Agente');
        $ajeno->forceFill(['username' => 'ajeno1', 'email' => 'ajeno@ejemplo.mx', 'password' => 'Ajena-Clave-11'])->save();

        $this->post('/olvide-contrasena', ['cuenta' => 'agente1']);
        $enlace = $this->enlaceDe(RestablecerContrasena::class);
        $token = substr((string) parse_url($enlace, PHP_URL_PATH), strrpos((string) parse_url($enlace, PHP_URL_PATH), '/') + 1);

        foreach (['ajeno@ejemplo.mx', 'admin@ejemplo.mx'] as $correo) {
            $this->get($enlace)->assertOk();
            $this->post('/restablecer-contrasena', [
                'token' => $token, 'email' => $correo, 'password' => 'Robada-2026', 'password_confirmation' => 'Robada-2026',
            ])->assertRedirect('/olvide-contrasena')->assertSessionHas('error');
        }
        $this->assertTrue(Hash::check('Ajena-Clave-11', $ajeno->fresh()->password));
        $this->assertTrue(Hash::check('Admin-Clave-77', $this->admin->fresh()->password));
        $this->assertTrue(Hash::check('Secreta123!', $this->agente->fresh()->password));
    }

    public function test_limite_de_solicitudes_por_equipo_y_por_cuenta(): void
    {
        $this->conCorreo();
        // 5 por minuto por equipo
        foreach (range(1, 5) as $i) {
            $this->post('/olvide-contrasena', ['cuenta' => "nadie{$i}"])->assertRedirect('/olvide-contrasena');
        }
        $this->post('/olvide-contrasena', ['cuenta' => 'nadie6'])->assertStatus(429);

        // 3 por hora por cuenta: la cuarta ya no envía correo (misma respuesta)
        foreach (range(1, 4) as $i) {
            $this->travel(2)->minutes();
            $this->post('/olvide-contrasena', ['cuenta' => 'agente1'])->assertRedirect('/olvide-contrasena')->assertSessionHas('enviado');
        }
        Mail::assertSent(RestablecerContrasena::class, 3);
    }

    // ----------------------------------------------------------- Bienvenida por correo

    public function test_alta_con_enlace_de_bienvenida_sin_que_nadie_conozca_la_contrasena(): void
    {
        $this->conCorreo();
        $this->actingAs($this->admin)->get('/usuarios')->assertOk()->assertSee('Enviar enlace de bienvenida por correo');

        $this->actingAs($this->admin)->post('/usuarios', $this->datosNuevo(['contrasena_modo' => 'enlace']))
            ->assertRedirect('/usuarios')->assertSessionHas('ok');
        $this->assertStringContainsString('correo de bienvenida', session('ok'));
        $nuevo = User::where('username', 'laura.gomez')->firstOrFail();
        $this->assertTrue($nuevo->debe_cambiar_contrasena);
        $this->assertTrue($nuevo->activo);
        $this->assertDatabaseHas('auditoria', ['evento' => 'usuarios.bienvenida_enviada', 'auditable_id' => $nuevo->id, 'user_id' => $this->admin->id]);

        Mail::assertSent(BienvenidaUsuario::class, fn ($m) => $m->hasTo('laura@ejemplo.mx') && $m->usuario === 'laura.gomez' && $m->horas === 24);
        $enlace = $this->enlaceDe(BienvenidaUsuario::class);
        $token = substr((string) parse_url($enlace, PHP_URL_PATH), strrpos((string) parse_url($enlace, PHP_URL_PATH), '/') + 1);

        // Vale 24 horas (más que el de restablecer)
        $this->travel(20)->hours();
        auth()->logout();
        $this->get($enlace)->assertOk()->assertSee('Bienvenido, crea tu contraseña');
        $this->post('/restablecer-contrasena', [
            'token' => $token, 'email' => 'laura@ejemplo.mx', 'password' => 'Mi-Primera-Clave-1', 'password_confirmation' => 'Mi-Primera-Clave-1',
        ])->assertRedirect('/login');
        $nuevo->refresh();
        $this->assertFalse($nuevo->debe_cambiar_contrasena);
        $this->post('/login', ['username' => 'laura.gomez', 'password' => 'Mi-Primera-Clave-1'])->assertRedirect('/');
        $this->get('/')->assertOk();
    }

    public function test_el_enlace_de_bienvenida_vence_a_las_24_horas(): void
    {
        $this->conCorreo();
        $this->actingAs($this->admin)->post('/usuarios', $this->datosNuevo(['contrasena_modo' => 'enlace']))->assertSessionHas('ok');
        $enlace = $this->enlaceDe(BienvenidaUsuario::class);
        auth()->logout();
        $this->travel(25)->hours();
        $this->get($enlace)->assertRedirect('/olvide-contrasena');
    }

    public function test_sin_correo_configurado_el_alta_pide_la_contrasena(): void
    {
        $this->actingAs($this->admin)->get('/usuarios')->assertOk()
            ->assertDontSee('Enviar enlace de bienvenida por correo')->assertSee('no está configurado');
        $this->actingAs($this->admin)->from('/usuarios')->post('/usuarios', $this->datosNuevo(['contrasena_modo' => 'enlace']))
            ->assertSessionHasErrors('password');
        $this->assertDatabaseMissing('users', ['username' => 'laura.gomez']);
    }

    // ------------------------------------------------------------- Restablecer (Usuarios)

    public function test_restablecer_desde_usuarios_por_enlace_o_con_contrasena_escrita(): void
    {
        $this->conCorreo();
        $this->actingAs($this->admin)->get('/usuarios')->assertOk()->assertSee('Restablecer contraseña');

        // Por enlace: la contraseña anterior deja de servir
        $this->actingAs($this->admin)->post("/usuarios/{$this->agente->id}/restablecer", ['contrasena_modo' => 'enlace'])
            ->assertRedirect('/usuarios')->assertSessionHas('ok');
        $this->assertFalse(Hash::check('Secreta123!', $this->agente->fresh()->password));
        $this->assertTrue($this->agente->fresh()->debe_cambiar_contrasena);
        Mail::assertSent(RestablecerContrasena::class, fn ($m) => $m->hasTo('agente@ejemplo.mx') && $m->pedidoPorAdministrador && $m->minutos === 60);
        $this->assertDatabaseHas('auditoria', ['evento' => 'usuarios.enlace_restablecer_enviado', 'auditable_id' => $this->agente->id, 'user_id' => $this->admin->id]);

        // Con contraseña escrita: queda marcada para cambiarla al entrar
        $this->actingAs($this->admin)->post("/usuarios/{$this->agente->id}/restablecer", ['contrasena_modo' => 'manual', 'password' => 'Temporal-Clave-33'])
            ->assertRedirect('/usuarios')->assertSessionHas('ok');
        $this->assertTrue(Hash::check('Temporal-Clave-33', $this->agente->fresh()->password));
        auth()->logout();
        $this->post('/login', ['username' => 'agente1', 'password' => 'Temporal-Clave-33'])->assertRedirect('/');
        $this->get('/')->assertRedirect('/mi-cuenta');

        // Débil o vacía con «manual»
        $this->actingAs($this->admin)->from('/usuarios')->post("/usuarios/{$this->agente->id}/restablecer", ['contrasena_modo' => 'manual', 'password' => 'agente1x'])
            ->assertSessionHasErrors('password');
        $this->actingAs($this->admin)->from('/usuarios')->post("/usuarios/{$this->agente->id}/restablecer", ['contrasena_modo' => 'manual'])
            ->assertSessionHasErrors('password');
    }

    public function test_restablecer_respeta_empresa_nivel_y_alcance(): void
    {
        $this->conCorreo();
        $otra = $this->crearEmpresa('Hotel Dos');
        $ajeno = $this->crearUsuario($otra, 'Agente');
        $adminAjeno = $this->crearUsuario($otra, 'Administrador');

        $this->actingAs($adminAjeno)->post("/usuarios/{$this->agente->id}/restablecer", ['contrasena_modo' => 'enlace'])->assertNotFound();
        $this->actingAs($this->admin)->post("/usuarios/{$ajeno->id}/restablecer", ['contrasena_modo' => 'enlace'])->assertNotFound();
        // Ni la propia cuenta (se cambia en Mi cuenta) ni una de nivel igual o superior
        $this->actingAs($this->admin)->from('/usuarios')->post("/usuarios/{$this->admin->id}/restablecer", ['contrasena_modo' => 'enlace'])
            ->assertRedirect('/usuarios')->assertSessionHas('error');
        $this->assertTrue(Hash::check('Admin-Clave-77', $this->admin->fresh()->password));
        $otroAdmin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->actingAs($this->admin)->from('/usuarios')->post("/usuarios/{$otroAdmin->id}/restablecer", ['contrasena_modo' => 'enlace'])
            ->assertRedirect('/usuarios')->assertSessionHas('error');
        // Sin el permiso
        $this->actingAs($this->agente)->post("/usuarios/{$otroAdmin->id}/restablecer", ['contrasena_modo' => 'enlace'])->assertStatus(403);
        Mail::assertNothingSent();
    }

    // ------------------------------------------------------------ Avisos de seguridad

    public function test_el_bloqueo_por_intentos_avisa_una_vez_a_quien_administra_usuarios_y_al_afectado(): void
    {
        $this->conCorreo();
        $otra = $this->crearEmpresa('Hotel Dos');
        $adminAjeno = $this->crearUsuario($otra, 'Administrador');

        foreach (range(1, 5) as $i) {
            $this->post('/login', ['username' => 'agente1', 'password' => 'mala'.$i]);
        }
        $this->assertTrue($this->agente->fresh()->estaBloqueado());

        $aviso = Notificacion::withoutGlobalScopes()->where('user_id', $this->admin->id)->where('tipo', 'cuenta_bloqueada')->firstOrFail();
        $this->assertSame('Cuenta bloqueada: '.$this->agente->name, $aviso->titulo);
        $this->assertStringContainsString('se bloqueó 15 minutos por intentos fallidos desde 127.0.x.x', $aviso->texto);
        $this->assertStringNotContainsString('mala', $aviso->texto);
        $this->assertSame(0, Notificacion::withoutGlobalScopes()->where('user_id', $adminAjeno->id)->count());
        $this->assertSame(0, Notificacion::withoutGlobalScopes()->where('user_id', $this->agente->id)->where('tipo', 'cuenta_bloqueada')->count());

        Mail::assertSent(CuentaBloqueada::class, fn ($m) => $m->hasTo('admin@ejemplo.mx') && ! $m->paraAfectado);
        Mail::assertSent(CuentaBloqueada::class, fn ($m) => $m->hasTo('agente@ejemplo.mx') && $m->paraAfectado);
        Mail::assertSent(CuentaBloqueada::class, 2);

        // Un aviso por bloqueo, no por intento
        $this->post('/login', ['username' => 'agente1', 'password' => 'mala6']);
        $this->post('/login', ['username' => 'agente1', 'password' => 'mala7']);
        $this->assertSame(1, Notificacion::withoutGlobalScopes()->where('tipo', 'cuenta_bloqueada')->where('user_id', $this->admin->id)->count());
        Mail::assertSent(CuentaBloqueada::class, 2);
    }

    public function test_sin_el_aviso_encendido_el_bloqueo_solo_va_por_campana(): void
    {
        $this->conCorreo();
        $this->empresa->forceFill(['preferencias' => ['avisos' => ['cuenta_bloqueada' => false]]])->save();
        foreach (range(1, 5) as $i) {
            $this->post('/login', ['username' => 'agente1', 'password' => 'mala'.$i]);
        }
        $this->assertSame(1, Notificacion::withoutGlobalScopes()->where('tipo', 'cuenta_bloqueada')->where('user_id', $this->admin->id)->count());
        Mail::assertNothingSent();
        // Una cuenta que no existe nunca genera avisos
        foreach (range(1, 5) as $i) {
            $this->post('/login', ['username' => 'nadie.existe', 'password' => 'mala'.$i]);
        }
        $this->assertSame(1, Notificacion::withoutGlobalScopes()->where('tipo', 'cuenta_bloqueada')->count());
    }

    public function test_la_baja_del_colaborador_desactiva_su_cuenta_y_avisa(): void
    {
        $colaborador = app(Tenant::class)->conEmpresa($this->empresa->id, fn () => Colaborador::create([
            'num_empleado' => 'E-100', 'nombre' => 'Laura', 'apellido_paterno' => 'Gómez', 'sede_id' => $this->centro->id,
        ]));
        $this->agente->forceFill(['colaborador_id' => $colaborador->id])->save();
        config(['session.driver' => 'database']);
        DB::table('sessions')->insert(['id' => 'sesion-agente', 'user_id' => $this->agente->id, 'payload' => '', 'last_activity' => time()]);

        $this->actingAs($this->admin)->patch("/colaboradores/{$colaborador->id}/estado", ['activo' => 0])
            ->assertRedirect('/colaboradores')->assertSessionHas('aviso');
        // Con el driver «database» el store de la petición es otro: se lee el que usó la petición
        $this->assertStringContainsString('También se dio de baja su cuenta de usuario «@agente1»', (string) app('session.store')->get('aviso'));

        $this->agente->refresh();
        $this->assertFalse($this->agente->activo);
        $this->assertNotNull($this->agente->desactivado_por_baja_en);
        $this->assertDatabaseMissing('sessions', ['id' => 'sesion-agente']);
        $this->assertDatabaseHas('auditoria', ['evento' => 'usuarios.desactivado_por_baja', 'auditable_id' => $this->agente->id, 'user_id' => $this->admin->id]);
        $aviso = Notificacion::withoutGlobalScopes()->where('user_id', $this->admin->id)->where('tipo', 'cuenta_desactivada')->firstOrFail();
        $this->assertStringContainsString('Se dio de baja la cuenta de '.$this->agente->name.' (@agente1) junto con el colaborador Laura Gómez; reactívala en Usuarios si es necesario.', $aviso->texto);

        // Ya no entra
        auth()->logout();
        $this->post('/login', ['username' => 'agente1', 'password' => 'Secreta123!'])->assertSessionHas('acceso', 'inactiva'); // «Tu cuenta no está activa»

        // El reingreso del colaborador NO reactiva la cuenta sola; se hace en Usuarios y se limpia la marca
        $this->actingAs($this->admin)->patch("/colaboradores/{$colaborador->id}/estado", ['activo' => 1])->assertSessionHas('ok');
        $this->assertStringContainsString('Su cuenta de usuario sigue dada de baja', (string) app('session.store')->get('ok'));
        $this->assertFalse($this->agente->fresh()->activo);
        $this->actingAs($this->admin)->patch("/usuarios/{$this->agente->id}/estado", ['activo' => 1])->assertSessionHas('ok');
        $this->agente->refresh();
        $this->assertTrue($this->agente->activo);
        $this->assertNull($this->agente->desactivado_por_baja_en);
    }

    public function test_cuentas_por_revisar_en_mis_pendientes_y_en_usuarios(): void
    {
        $bloqueado = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $bloqueado->forceFill(['name' => 'Bruno Bloqueado', 'bloqueado_hasta' => now()->addMinutes(10)])->save();
        $porBaja = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $porBaja->forceFill(['name' => 'Berta Baja', 'activo' => false, 'desactivado_por_baja_en' => now()->subDays(3)])->save();
        $viejo = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $viejo->forceFill(['name' => 'Víctor Viejo', 'activo' => false, 'desactivado_por_baja_en' => now()->subDays(10)])->save();

        $pendientes = app(MisPendientes::class)->para($this->admin);
        $cuentas = collect($pendientes['items'])->firstWhere('clave', 'cuentas');
        $this->assertNotNull($cuentas);
        $this->assertSame('Cuentas por revisar', $cuentas['titulo']);
        $this->assertSame(2, $cuentas['total']);

        $this->assertNull(collect(app(MisPendientes::class)->para($this->agente)['items'])->firstWhere('clave', 'cuentas'));

        $this->actingAs($this->admin)->get('/usuarios?revisar=1')->assertOk()
            ->assertSee('Cuentas por revisar (2)')->assertSee('Bruno Bloqueado')->assertSee('Berta Baja')
            ->assertSee('Dada de baja junto con su colaborador');
    }

    public function test_el_super_administrador_tambien_recupera_su_contrasena_con_el_correo_de_la_plataforma(): void
    {
        $this->conCorreo();
        $super = $this->crearSuperadmin();
        $super->forceFill(['username' => 'super1', 'email' => 'super@ejemplo.mx', 'password' => 'Super-Clave-99'])->save();

        $this->post('/olvide-contrasena', ['cuenta' => 'super@ejemplo.mx'])->assertSessionHas('enviado');
        Mail::assertSent(RestablecerContrasena::class, fn ($m) => $m->hasTo('super@ejemplo.mx'));
        $enlace = $this->enlaceDe(RestablecerContrasena::class);
        $token = substr((string) parse_url($enlace, PHP_URL_PATH), strrpos((string) parse_url($enlace, PHP_URL_PATH), '/') + 1);
        $this->get($enlace)->assertOk();
        $this->post('/restablecer-contrasena', [
            'token' => $token, 'email' => 'super@ejemplo.mx', 'password' => 'Maestra-Nueva-2026', 'password_confirmation' => 'Maestra-Nueva-2026',
        ])->assertRedirect('/login');
        $this->assertTrue(Hash::check('Maestra-Nueva-2026', $super->fresh()->password));
        // Sin empresa no hay campana, pero sí bitácora
        $this->assertDatabaseHas('auditoria', ['evento' => 'usuarios.recuperacion_completada', 'auditable_id' => $super->id]);

        // Y Mi cuenta le funciona sin empresa
        $this->actingAs($super->fresh())->get('/mi-cuenta')->assertOk()->assertSee('Super Administrador');
    }
}
