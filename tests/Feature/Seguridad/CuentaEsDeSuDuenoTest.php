<?php

namespace Tests\Feature\Seguridad;

use App\Http\Controllers\Auth\RecuperacionController;
use App\Models\Auditoria;
use App\Models\Empresa;
use App\Models\Modulo;
use App\Models\ModuloAccion;
use App\Models\Notificacion;
use App\Models\Rol;
use App\Models\RolPermiso;
use App\Models\Sede;
use App\Models\SolicitudCuenta;
use App\Models\User;
use App\Services\Cuenta\SolicitudesCuenta;
use App\Services\DosPasos\Totp;
use App\Services\DosPasos\VerificacionDosPasos;
use App\Services\Permisos\Alcance;
use App\Services\Plataforma\SuperAdministradores;
use App\Services\Usuarios\AdministradorUsuarios;
use App\Support\Menu\MisPendientes;
use App\Support\Tenancy\EmpresaScope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * C-36 · La cuenta es de su dueño (DD-21, E-14, X-07; docs/tecnico/usuarios.md
 * y dos-pasos.md): correo opcional en las cuentas de caseta, «Pedir a mi
 * administrador» (contraseña y dos pasos) con «Cuentas por revisar», dos
 * Super Administradores como mínimo y uno restablece al otro sin terminal.
 * También los módulos vinculados: Mis pendientes, Inicio, campana y
 * Bitácora de auditoría.
 */
class CuentaEsDeSuDuenoTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

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
        $this->admin->forceFill(['username' => 'admin1', 'email' => 'admin@ejemplo.mx', 'password' => 'Admin-Clave-77'])->save();
        RateLimiter::clear('acceso-ip:127.0.0.1');
        $this->travelTo(Carbon::parse('2026-11-10 15:00:00', 'UTC'));
    }

    private function rol(string $nombre): Rol
    {
        return Rol::where('empresa_id', $this->empresa->id)->where('nombre', $nombre)->firstOrFail();
    }

    private function salir(): void
    {
        $this->app['auth']->forgetGuards();
        $this->flushSession();
        RateLimiter::clear('acceso-ip:127.0.0.1');
    }

    private function entrar(string $usuario, string $contrasena)
    {
        return $this->post('/login', ['username' => $usuario, 'password' => $contrasena]);
    }

    /** @return array{0: string, 1: list<string>} */
    private function activarDosPasos(User $usuario): array
    {
        $secreto = Totp::nuevoSecreto();
        $codigos = app(VerificacionDosPasos::class)->activar($usuario, $secreto, Totp::codigo($secreto));
        $this->travel(31)->seconds();

        return [$secreto, $codigos];
    }

    private function solicitudes(): Builder
    {
        return SolicitudCuenta::query()->withoutGlobalScope(EmpresaScope::class);
    }

    // ----------------------------------------------------- Cuentas de caseta

    public function test_la_cuenta_de_caseta_puede_no_tener_correo_y_la_de_administrador_si_lo_exige(): void
    {
        $alta = fn (string $rol, string $usuario, array $extra = []) => $this->actingAs($this->admin)->post('/usuarios', array_merge([
            'name' => 'Persona '.$usuario, 'username' => $usuario, 'email' => '', 'rol_id' => $this->rol($rol)->id, 'sede_id' => $this->centro->id,
            'contrasena_modo' => 'manual', 'password' => 'Caseta-Temporal-31',
        ], $extra));

        $alta('Agente', 'guardia.norte')->assertRedirect('/usuarios')->assertSessionHas('ok');
        $guardia = User::where('username', 'guardia.norte')->firstOrFail();
        $this->assertNull($guardia->email);
        $this->assertTrue($guardia->debe_cambiar_contrasena);
        // Dos cuentas de caseta sin correo no chocan
        $alta('Agente', 'guardia.sur')->assertSessionHas('ok');

        // Un rol que administra usuarios o la configuración sí necesita correo
        $alta('Administrador', 'otro.admin')->assertSessionHasErrors(['email' => 'Escribe el correo: este rol administra la plataforma y lo necesita para recuperar su cuenta y recibir avisos. Solo las cuentas de caseta pueden ir sin correo.']);
        // Sin correo no se puede mandar el enlace de bienvenida: hay que escribir la contraseña
        $alta('Agente', 'guardia.oeste', ['contrasena_modo' => 'enlace', 'password' => ''])->assertSessionHasErrors('password');

        $this->actingAs($this->admin)->get('/usuarios')->assertOk()->assertSee('Sin correo: recupera su cuenta contigo')
            ->assertSee('Opcional en cuentas de caseta');
    }

    public function test_recuperar_una_cuenta_de_caseta_sin_correo_con_una_solicitud_al_administrador(): void
    {
        $guardia = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $guardia->forceFill(['username' => 'guardia1', 'email' => null, 'password' => 'Vieja-Clave-11'])->save();

        // La pantalla ofrece pedírselo al administrador (con o sin correo de la plataforma)
        $this->get('/olvide-contrasena')->assertOk()->assertSee('Pedir a mi administrador');

        // Respuesta uniforme: una cuenta que no existe recibe el mismo mensaje y no crea nada
        $this->post('/olvide-contrasena/administrador', ['cuenta' => 'no.existe'])->assertRedirect('/olvide-contrasena')->assertSessionHas('pedido');
        $this->assertSame(0, $this->solicitudes()->count());

        $this->post('/olvide-contrasena/administrador', ['cuenta' => 'guardia1'])->assertRedirect('/olvide-contrasena')
            ->assertSessionHas('pedido', RecuperacionController::MENSAJE_ADMINISTRADOR);
        $s = $this->solicitudes()->firstOrFail();
        $this->assertSame([$this->empresa->id, $guardia->id, SolicitudCuenta::CONTRASENA, SolicitudCuenta::PENDIENTE], [$s->empresa_id, $s->user_id, $s->tipo, $s->estado]);
        // Pedirla otra vez no duplica
        $this->post('/olvide-contrasena/administrador', ['cuenta' => 'guardia1']);
        $this->assertSame(1, $this->solicitudes()->count());

        // Llega a la campana del administrador, a Mis pendientes («Cuentas por revisar») y a la lista de Usuarios
        $this->assertTrue(Notificacion::where('user_id', $this->admin->id)->where('tipo', 'cuenta_solicitud')->where('referencia_id', $s->id)->exists());
        Cache::flush();
        $cuentas = collect(app()->build(MisPendientes::class)->para($this->admin)['items'])->firstWhere('clave', 'cuentas');
        $this->assertSame(1, $cuentas['total']);
        $this->actingAs($this->admin)->get('/usuarios')->assertOk()->assertSee('Piden ayuda con su cuenta (1)')->assertSee('restablecer su contraseña');
        $this->actingAs($this->admin)->get('/')->assertOk()->assertSee('1 cuenta por revisar');
        $this->assertTrue(Auditoria::where('evento', 'usuarios.solicitud_cuenta')->where('auditable_id', $guardia->id)->exists());

        // El administrador confirma en persona y escribe una contraseña temporal: la solicitud se cierra sola
        $this->actingAs($this->admin)->post("/usuarios/{$guardia->id}/restablecer", ['contrasena_modo' => 'manual', 'password' => 'Temporal-Nueva-42'])
            ->assertRedirect('/usuarios')->assertSessionHas('ok');
        $this->assertSame(SolicitudCuenta::ATENDIDA, $s->refresh()->estado);
        $this->assertSame($this->admin->id, $s->atendida_por);
        $this->assertNotNull(Notificacion::where('referencia_tipo', 'solicitud_cuenta')->where('referencia_id', $s->id)->value('leida_en'));
        $this->assertTrue(Auditoria::where('evento', 'usuarios.solicitud_cuenta_atendida')->where('user_id', $this->admin->id)->exists());
        Cache::flush();
        $this->assertNull(collect(app()->build(MisPendientes::class)->para($this->admin)['items'])->firstWhere(fn ($i) => $i['clave'] === 'cuentas' && $i['total'] > 0));

        // La cuenta sigue siendo de su dueño: entra con la temporal y debe cambiarla por una propia
        $this->salir();
        $this->entrar('guardia1', 'Temporal-Nueva-42')->assertRedirect();
        $this->get('/usuarios')->assertRedirect();
        $this->assertTrue($guardia->refresh()->debe_cambiar_contrasena);
    }

    public function test_rechazar_una_peticion_avisa_a_la_persona_y_solo_la_atiende_quien_administra_esa_cuenta(): void
    {
        $guardia = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $guardia->forceFill(['username' => 'guardia2', 'email' => null])->save();
        $otroAgente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->post('/olvide-contrasena/administrador', ['cuenta' => 'guardia2']);
        $s = $this->solicitudes()->firstOrFail();

        // Un agente (sin permiso de usuarios) no la ve ni la rechaza
        $this->actingAs($otroAgente)->patch(route('solicitudes-cuenta.rechazar', $s->id))->assertForbidden();
        // Otra empresa: 404
        $otra = $this->crearEmpresa('Hotel Dos');
        $adminAjeno = $this->crearUsuario($otra, 'Administrador');
        $this->actingAs($adminAjeno)->patch(route('solicitudes-cuenta.rechazar', $s->id))->assertNotFound();

        $this->actingAs($this->admin)->patch(route('solicitudes-cuenta.rechazar', $s->id), ['motivo' => 'No fue la persona'])->assertRedirect('/usuarios')->assertSessionHas('ok');
        $this->assertSame([SolicitudCuenta::RECHAZADA, 'No fue la persona'], [$s->refresh()->estado, $s->motivo_rechazo]);
        $this->assertTrue(Notificacion::where('user_id', $guardia->id)->where('titulo', 'Tu petición sobre tu cuenta no se atendió')->exists());
        $this->assertTrue(Auditoria::where('evento', 'usuarios.solicitud_cuenta_rechazada')->exists());
    }

    public function test_perdi_mi_telefono_pide_al_administrador_que_restablezca_los_dos_pasos(): void
    {
        $guardia = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $guardia->forceFill(['username' => 'guardia3', 'email' => null, 'password' => 'Clave-Guardia-33'])->save();
        $this->activarDosPasos($guardia);

        // Sin la contraseña no se puede pedir (evita peticiones a nombre de otros)
        $this->post('/login/verificacion/perdi-mi-telefono')->assertRedirect('/login');
        $this->assertSame(0, $this->solicitudes()->count());

        $this->entrar('guardia3', 'Clave-Guardia-33')->assertRedirect('/login/verificacion');
        $this->get('/login/verificacion')->assertOk()->assertSee('Perdí mi teléfono: pedir ayuda');
        $this->post('/login/verificacion/perdi-mi-telefono')->assertRedirect('/login')->assertSessionHas('acceso', 'dos_pasos_pedido');
        $s = $this->solicitudes()->firstOrFail();
        $this->assertSame(SolicitudCuenta::DOS_PASOS, $s->tipo);
        $this->assertGuest();

        $this->actingAs($this->admin)->get('/usuarios')->assertSee('restablecer su verificación en dos pasos');
        $this->actingAs($this->admin)->patch(route('usuarios.dos-pasos', $guardia->id))->assertSessionHas('ok');
        $this->assertSame(SolicitudCuenta::ATENDIDA, $s->refresh()->estado);
        $this->assertFalse($guardia->refresh()->tieneDosPasos());
    }

    public function test_las_migraciones_se_revierten_y_se_vuelven_a_aplicar_sin_perder_cuentas(): void
    {
        $guardia = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $guardia->forceFill(['email' => null])->save();
        $cuentas = require database_path('migrations/2026_11_10_003610_cuentas_correo_opcional_y_solicitudes_de_cuenta.php');
        $privacidad = require database_path('migrations/2026_11_10_003710_privacidad_en_todos_los_modulos.php');

        $privacidad->down();
        $cuentas->down();
        $this->assertSame('sin-correo-'.$guardia->id.'@cuenta.invalid', DB::table('users')->where('id', $guardia->id)->value('email'));
        $this->assertFalse(Schema::hasTable('solicitudes_cuenta'));
        $this->assertFalse(Schema::hasColumn('reservas', 'datos_eliminados_en'));
        $this->assertArrayNotHasKey('plazos_ampliados', $this->empresa->refresh()->preferencias['privacidad'] ?? []);

        $cuentas->up();
        $privacidad->up();
        $this->assertNull(DB::table('users')->where('id', $guardia->id)->value('email'));
        $this->assertTrue(Schema::hasTable('solicitudes_cuenta'));
        foreach (['lost_found_reportes_perdida', 'lost_found_entregas', 'novedades', 'reservas', 'movimientos_transporte'] as $tabla) {
            $this->assertTrue(Schema::hasColumn($tabla, 'datos_eliminados_en'), $tabla);
        }
        // Empresa que ya existía: los plazos nuevos quedan por confirmar
        $this->assertFalse($this->empresa->refresh()->preferencias['privacidad']['plazos_ampliados']);
    }

    // ------------------------------------------------- Super Administradores

    public function test_con_un_solo_super_administrador_se_avisa_en_inicio_y_en_configuracion(): void
    {
        $sa = $this->crearSuperadmin();
        $this->activarDosPasos($sa);
        $this->assertTrue(app(SuperAdministradores::class)->faltan());

        $this->actingAs($sa)->get('/')->assertOk()->assertSee('Falta el segundo Super Administrador');
        $this->actingAs($sa)->get('/configuracion')->assertOk()->assertSee('Super Administradores')->assertSee('Solo hay 1 Super Administrador activo');
        // Un administrador de empresa no ve nada de esto
        $this->actingAs($this->admin)->get('/')->assertOk()->assertDontSee('Falta el segundo Super Administrador');
        $this->actingAs($this->admin)->post(route('superadmins.store'), ['name' => 'X'])->assertForbidden();
    }

    public function test_no_se_puede_dejar_un_solo_super_administrador(): void
    {
        $sa1 = $this->crearSuperadmin();
        $sa1->forceFill(['password' => 'Super-Uno-2026'])->save();
        $this->activarDosPasos($sa1);

        // Alta del segundo desde la pantalla (sin consola): sin correo de la plataforma, con contraseña temporal
        $this->actingAs($sa1)->post(route('superadmins.store'), ['name' => 'Laura Dueña', 'username' => 'laura.sa', 'email' => 'laura@ejemplo.mx',
            'password' => 'Temporal-Clave-55', 'contrasena_actual' => 'mala'])->assertSessionHasErrors('contrasena_actual');
        $this->actingAs($sa1)->post(route('superadmins.store'), ['name' => 'Laura Dueña', 'username' => 'laura.sa', 'email' => 'laura@ejemplo.mx',
            'password' => 'Temporal-Clave-55', 'contrasena_actual' => 'Super-Uno-2026'])->assertSessionHas('ok');
        $sa2 = User::where('username', 'laura.sa')->firstOrFail();
        $this->assertTrue($sa2->es_superadmin && $sa2->debe_cambiar_contrasena && $sa2->empresa_id === null);
        $this->assertTrue(Auditoria::where('evento', 'plataforma.superadmin_creado')->where('auditable_id', $sa2->id)->exists());
        $this->assertFalse(app(SuperAdministradores::class)->faltan());

        // Con dos, no se puede dar de baja a ninguno
        $this->actingAs($sa1)->patch(route('superadmins.estado', $sa2->id), ['activo' => 0, 'contrasena_actual' => 'Super-Uno-2026'])
            ->assertSessionHasErrors('superadmin');
        $this->assertTrue($sa2->refresh()->activo);
        $this->actingAs($sa1)->get('/configuracion')->assertSee('No se puede: quedaría menos de dos Super Administradores activos.');
        // Ni a sí mismo
        $this->actingAs($sa1)->patch(route('superadmins.estado', $sa1->id), ['activo' => 0, 'contrasena_actual' => 'Super-Uno-2026'])->assertSessionHasErrors('superadmin');

        // Con un tercero, sí (y queda auditado y avisado a los demás)
        $sa3 = $this->crearSuperadmin();
        $this->actingAs($sa1)->patch(route('superadmins.estado', $sa2->id), ['activo' => 0, 'contrasena_actual' => 'Super-Uno-2026'])->assertSessionHas('ok');
        $this->assertFalse($sa2->refresh()->activo);
        $this->assertTrue(Auditoria::where('evento', 'plataforma.superadmin_dado_de_baja')->where('auditable_id', $sa2->id)->exists());
        $this->assertTrue(Notificacion::withoutGlobalScopes()->where('user_id', $sa3->id)->where('tipo', 'plataforma_superadmin')->exists());
        // Y otra vez quedan dos: el siguiente ya no
        $this->actingAs($sa1)->patch(route('superadmins.estado', $sa3->id), ['activo' => 0, 'contrasena_actual' => 'Super-Uno-2026'])->assertSessionHasErrors('superadmin');
        $this->assertTrue($sa3->refresh()->activo);
    }

    public function test_recuperar_los_dos_pasos_de_un_super_administrador_sin_terminal(): void
    {
        $sa1 = $this->crearSuperadmin();
        $sa1->forceFill(['username' => 'sa.uno', 'password' => 'Super-Uno-2026'])->save();
        $this->activarDosPasos($sa1);
        $sa2 = $this->crearSuperadmin();
        $sa2->forceFill(['username' => 'sa.dos', 'password' => 'Super-Dos-2026'])->save();
        [, $codigos] = $this->activarDosPasos($sa2);

        // Primero, sus códigos de respaldo: cada uno sirve una vez
        $this->entrar('sa.dos', 'Super-Dos-2026')->assertRedirect('/login/verificacion');
        $this->post('/login/verificacion', ['codigo' => $codigos[0]])->assertRedirect('/');
        $this->salir();

        // Sin teléfono ni códigos: la petición llega a los OTROS Super Administradores
        $this->entrar('sa.dos', 'Super-Dos-2026')->assertRedirect('/login/verificacion');
        $this->post('/login/verificacion/perdi-mi-telefono')->assertRedirect('/login')->assertSessionHas('acceso', 'dos_pasos_pedido_superadmin');
        $s = $this->solicitudes()->firstOrFail();
        $this->assertNull($s->empresa_id);
        $this->assertTrue(Notificacion::withoutGlobalScopes()->where('user_id', $sa1->id)->where('tipo', 'cuenta_solicitud')->exists());
        $this->assertFalse(Notificacion::withoutGlobalScopes()->where('user_id', $sa2->id)->where('tipo', 'cuenta_solicitud')->exists());

        $this->actingAs($sa1)->get('/')->assertOk()->assertSee('Super Administradores que piden ayuda');
        $this->actingAs($sa1)->get('/configuracion')->assertOk()->assertSee('Piden ayuda:')->assertSee('Quitar sus dos pasos');

        // Con contraseña equivocada no; consigo mismo no; un administrador de empresa no
        $this->actingAs($sa1)->post(route('superadmins.dos-pasos', $sa2->id), ['contrasena_actual' => 'mala'])->assertSessionHasErrors('contrasena_actual');
        $this->actingAs($sa1)->post(route('superadmins.dos-pasos', $sa1->id), ['contrasena_actual' => 'Super-Uno-2026'])->assertSessionHasErrors('superadmin');
        $this->actingAs($this->admin)->post(route('superadmins.dos-pasos', $sa2->id), ['contrasena_actual' => 'Admin-Clave-77'])->assertForbidden();
        $this->assertTrue($sa2->refresh()->tieneDosPasos());

        $this->actingAs($sa1)->post(route('superadmins.dos-pasos', $sa2->id), ['contrasena_actual' => 'Super-Uno-2026'])->assertSessionHas('ok');
        $this->assertFalse($sa2->refresh()->tieneDosPasos());
        $this->assertSame(SolicitudCuenta::ATENDIDA, $s->refresh()->estado);
        $this->assertTrue(Auditoria::where('evento', 'usuarios.dos_pasos_restablecida')->where('user_id', $sa1->id)->where('auditable_id', $sa2->id)->exists());

        // Entra con su contraseña y la plataforma le pide activar sus dos pasos con el teléfono nuevo
        $this->salir();
        $this->entrar('sa.dos', 'Super-Dos-2026')->assertRedirect('/mi-cuenta/dos-pasos');

        // La auditoría muestra los eventos nuevos con su nombre
        $this->actingAs($sa1)->get('/auditoria')->assertOk();
    }

    public function test_un_super_administrador_restablece_la_contrasena_de_otro(): void
    {
        $sa1 = $this->crearSuperadmin();
        $sa1->forceFill(['password' => 'Super-Uno-2026'])->save();
        $this->activarDosPasos($sa1);
        $sa2 = $this->crearSuperadmin();
        $sa2->forceFill(['username' => 'sa.olvido'])->save();
        $this->post('/olvide-contrasena/administrador', ['cuenta' => 'sa.olvido'])->assertSessionHas('pedido');
        $s = $this->solicitudes()->firstOrFail();
        $this->assertNull($s->empresa_id);

        $this->actingAs($sa1)->post(route('superadmins.contrasena', $sa2->id), ['password' => 'Temporal-Dos-77', 'contrasena_actual' => 'Super-Uno-2026'])->assertSessionHas('ok');
        $this->assertTrue($sa2->refresh()->debe_cambiar_contrasena);
        $this->assertSame(SolicitudCuenta::ATENDIDA, $s->refresh()->estado);
        $this->assertTrue(Auditoria::where('evento', 'plataforma.superadmin_contrasena')->where('auditable_id', $sa2->id)->exists());
    }

    /** Revisión 3b: con una sesión robada no se puede adivinar la contraseña que confirma las acciones entre Super Administradores. */
    public function test_la_contrasena_que_confirma_se_bloquea_tras_cinco_errores(): void
    {
        $sa1 = $this->crearSuperadmin();
        $sa1->forceFill(['password' => 'Super-Uno-2026'])->save();
        $sa2 = $this->crearSuperadmin();
        $sa2->forceFill(['password' => 'Super-Dos-2026'])->save();
        $this->crearSuperadmin();

        for ($i = 0; $i < SuperAdministradores::INTENTOS_CONTRASENA; $i++) {
            $this->actingAs($sa1)->patch(route('superadmins.estado', $sa2->id), ['activo' => 0, 'contrasena_actual' => 'mala-'.$i])
                ->assertSessionHasErrors(['contrasena_actual' => 'Tu contraseña no es correcta.']);
        }
        // Ya con la correcta tampoco: espera
        $this->actingAs($sa1)->patch(route('superadmins.estado', $sa2->id), ['activo' => 0, 'contrasena_actual' => 'Super-Uno-2026'])
            ->assertSessionHasErrors('contrasena_actual');
        $this->assertTrue($sa2->refresh()->activo);
        $this->actingAs($sa1)->post(route('superadmins.contrasena', $sa2->id), ['password' => 'Temporal-Dos-77', 'contrasena_actual' => 'Super-Uno-2026'])
            ->assertSessionHasErrors('contrasena_actual');
        $this->assertFalse((bool) $sa2->refresh()->debe_cambiar_contrasena);

        // Pasada la espera, la correcta sí sirve
        $this->travel(SuperAdministradores::ESPERA_CONTRASENA + 1)->seconds();
        $this->actingAs($sa1)->patch(route('superadmins.estado', $sa2->id), ['activo' => 0, 'contrasena_actual' => 'Super-Uno-2026'])->assertSessionHas('ok');
        $this->assertFalse($sa2->refresh()->activo);
    }

    // ----------------------------------------------- Observaciones de la revisión (A-03 a A-09)

    /** Rol propio con solo esas acciones de Usuarios (alcance empresa). */
    private function rolConUsuarios(string $nombre, array $acciones, int $nivel): Rol
    {
        $rol = Rol::create(['empresa_id' => $this->empresa->id, 'nombre' => $nombre, 'nivel_jerarquia' => $nivel]);
        $modulo = Modulo::where('clave', 'usuarios')->firstOrFail();
        foreach ($modulo->moduloAcciones()->with('accion')->get() as $ma) {
            if (in_array($ma->accion->clave, $acciones, true)) {
                RolPermiso::create(['rol_id' => $rol->id, 'modulo_accion_id' => $ma->id, 'alcance' => Alcance::Empresa]);
            }
        }

        return $rol;
    }

    private function cuentasEnPendientes(User $quien): int
    {
        Cache::flush();
        app()->forgetScopedInstances();

        return (int) (collect(app()->build(MisPendientes::class)->para($quien)['items'])->firstWhere('clave', 'cuentas')['total'] ?? 0);
    }

    public function test_el_numero_de_cuentas_por_revisar_coincide_con_lo_que_cada_quien_puede_atender(): void
    {
        $guardia = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $guardia->forceFill(['username' => 'guardia.a03', 'email' => null, 'password' => 'Clave-Guardia-33'])->save();
        $this->activarDosPasos($guardia);
        $otro = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $otro->forceFill(['username' => 'guardia.b03', 'email' => null])->save();
        $otro->forceFill(['bloqueado_hasta' => now()->addMinutes(30)])->save();
        // Una petición de contraseña y otra de dos pasos
        $this->post('/olvide-contrasena/administrador', ['cuenta' => 'guardia.a03']);
        $this->entrar('guardia.a03', 'Clave-Guardia-33');
        $this->post('/login/verificacion/perdi-mi-telefono');
        $this->salir();
        $this->assertSame(2, $this->solicitudes()->where('estado', SolicitudCuenta::PENDIENTE)->count());

        $editor = $this->crearUsuario($this->empresa);
        $this->darRol($editor, $this->rolConUsuarios('Edita usuarios', ['ver', 'editar'], 20)->nombre);
        $desbloquea = $this->crearUsuario($this->empresa);
        $this->darRol($desbloquea, $this->rolConUsuarios('Desbloquea usuarios', ['ver', 'desbloquear'], 20)->nombre);

        foreach ([[$this->admin, 1, 2], [$editor, 1, 1], [$desbloquea, 0, 1]] as [$quien, $cuentas, $peticiones]) {
            $r = $this->actingAs($quien)->get('/usuarios')->assertOk();
            $this->assertSame($cuentas, $r->viewData('porRevisar')->count(), $quien->name);
            $this->assertSame($peticiones, $r->viewData('solicitudesCuenta')->count(), $quien->name);
            // Mis pendientes cuenta exactamente los renglones que esa persona ve y puede atender
            $this->assertSame($cuentas + $peticiones, $this->cuentasEnPendientes($quien), $quien->name);
        }
        // Quien solo puede editar no ve la de dos pasos (exige «Desbloquear»)
        $this->actingAs($editor)->get('/usuarios')->assertSee('restablecer su contraseña')->assertDontSee('restablecer su verificación en dos pasos');
    }

    public function test_pedir_a_mi_administrador_tarda_lo_mismo_exista_o_no_la_cuenta(): void
    {
        $guardia = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $guardia->forceFill(['username' => 'guardia.a04', 'email' => null])->save();

        $tiempos = [];
        foreach (['no.existe.a04' => 0, 'guardia.a04' => 1] as $cuenta => $esperadas) {
            RateLimiter::clear('acceso-ip:127.0.0.1');
            $inicio = microtime(true);
            $this->post('/olvide-contrasena/administrador', ['cuenta' => $cuenta])->assertSessionHas('pedido');
            $tiempos[$cuenta] = microtime(true) - $inicio;
            $this->assertSame($esperadas, $this->solicitudes()->count());
        }
        // Las dos respuestas duran al menos el tiempo fijo y casi lo mismo (el trabajo de avisar va después de responder)
        foreach ($tiempos as $cuenta => $t) {
            $this->assertGreaterThanOrEqual(SolicitudesCuenta::MICROSEGUNDOS_MINIMOS / 1e6, $t, $cuenta);
        }
        $this->assertLessThan(0.15, abs($tiempos['guardia.a04'] - $tiempos['no.existe.a04']));
    }

    public function test_el_limite_es_de_tres_por_hora_por_cuenta_con_usuario_o_correo(): void
    {
        $guardia = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $guardia->forceFill(['username' => 'guardia.a09', 'email' => 'guardia.a09@ejemplo.mx'])->save();
        $limite = SolicitudesCuenta::llaveDeCuenta($guardia->id);

        foreach (['guardia.a09', 'guardia.a09@ejemplo.mx', 'guardia.a09', 'guardia.a09@ejemplo.mx', 'guardia.a09'] as $cuenta) {
            RateLimiter::clear('acceso-ip:127.0.0.1');
            $this->withoutMiddleware(ThrottleRequests::class)
                ->post('/olvide-contrasena/administrador', ['cuenta' => $cuenta])->assertSessionHas('pedido');
        }
        // Cinco intentos alternando usuario y correo: la cuenta solo cuenta 3 (no 6)
        $this->assertSame(SolicitudesCuenta::POR_CUENTA_HORA, RateLimiter::attempts($limite));
        $this->assertTrue(RateLimiter::tooManyAttempts($limite, SolicitudesCuenta::POR_CUENTA_HORA));
        // Igual en «Olvidé mi contraseña» (enlace por correo)
        $this->assertSame(0, RateLimiter::attempts('recuperacion-cuenta-usuario:'.$guardia->id)); // sin correo de la plataforma no cuenta
    }

    public function test_mi_cuenta_sin_correo_dice_como_recuperarla(): void
    {
        $guardia = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $guardia->forceFill(['email' => null])->save();
        $this->actingAs($guardia)->get('/mi-cuenta')->assertOk()
            ->assertSee('Sin correo: si olvidas tu contraseña, pide ayuda a tu administrador.')
            ->assertDontSee('Aquí llegan los enlaces para recuperar tu contraseña');
        $this->actingAs($this->admin)->get('/mi-cuenta')->assertOk()->assertSee('admin@ejemplo.mx')->assertSee('Aquí llegan los enlaces para recuperar tu contraseña');
    }

    public function test_si_un_rol_pasa_a_administrar_se_avisa_que_cuentas_no_tienen_correo(): void
    {
        $rol = $this->rolConUsuarios('Caseta norte', ['ver'], 40);
        $guardia = $this->crearUsuario($this->empresa);
        $guardia->forceFill(['name' => 'Pedro Sin Correo', 'email' => null])->save();
        $this->darRol($guardia, 'Caseta norte');
        $this->actingAs($this->admin)->get('/usuarios')->assertDontSee('Administran sin correo');

        // Se le da «Editar» de Usuarios: el rol ahora administra
        $editar = ModuloAccion::whereHas('modulo', fn ($q) => $q->where('clave', 'usuarios'))->whereHas('accion', fn ($q) => $q->where('clave', 'editar'))->firstOrFail();
        RolPermiso::create(['rol_id' => $rol->id, 'modulo_accion_id' => $editar->id, 'alcance' => Alcance::Empresa]);
        $this->assertSame(['Pedro Sin Correo'], AdministradorUsuarios::administrativasSinCorreo($this->empresa->id, $rol)->pluck('name')->all());
        $this->actingAs($this->admin)->get('/usuarios')->assertSee('Administran sin correo (1)')->assertSee('Pedro Sin Correo');

        // La Matriz avisa al guardar los permisos de ese rol
        $this->actingAs($this->admin)->put('/permisos/'.$rol->id, ['permisos' => ['usuarios' => ['acciones' => ['ver', 'editar'], 'alcance' => 'empresa']]])
            ->assertSessionHas('aviso', fn ($m) => str_contains($m, 'Pedro Sin Correo') && str_contains($m, 'no tienen correo'));
    }

    public function test_un_super_administrador_rechaza_la_peticion_de_otro(): void
    {
        $sa1 = $this->crearSuperadmin();
        $this->activarDosPasos($sa1);
        $sa2 = $this->crearSuperadmin();
        $sa2->forceFill(['username' => 'sa.a08'])->save();
        $this->post('/olvide-contrasena/administrador', ['cuenta' => 'sa.a08']);
        $s = $this->solicitudes()->firstOrFail();

        $this->actingAs($sa1)->get('/configuracion')->assertSee(route('superadmins.solicitudes.rechazar', $s->id), false);
        $this->actingAs($this->admin)->patch(route('superadmins.solicitudes.rechazar', $s->id))->assertForbidden();
        $this->actingAs($sa2)->patch(route('superadmins.solicitudes.rechazar', $s->id))->assertSessionHas('error'); // la propia no
        $this->actingAs($sa1)->patch(route('superadmins.solicitudes.rechazar', $s->id), ['motivo' => 'No fue la persona'])->assertSessionHas('ok');
        $this->assertSame(SolicitudCuenta::RECHAZADA, $s->refresh()->estado);
        $this->assertTrue(Notificacion::withoutGlobalScopes()->where('user_id', $sa2->id)->where('titulo', 'Tu petición sobre tu cuenta no se atendió')->exists());
        $this->assertTrue(Auditoria::where('evento', 'usuarios.solicitud_cuenta_rechazada')->where('user_id', $sa1->id)->exists());
        // Una petición de empresa no se rechaza por esta ruta
        $guardia = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $guardia->forceFill(['username' => 'guardia.a08'])->save();
        $this->salir();
        $this->post('/olvide-contrasena/administrador', ['cuenta' => 'guardia.a08']);
        $deEmpresa = $this->solicitudes()->whereNotNull('empresa_id')->firstOrFail();
        $this->actingAs($sa1)->patch(route('superadmins.solicitudes.rechazar', $deEmpresa->id))->assertNotFound();
    }
}
