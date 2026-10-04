<?php

namespace Tests\Feature\Administracion;

use App\Models\Empresa;
use App\Models\Rol;
use App\Models\RolPermiso;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Desbloqueo manual de cuentas bloqueadas por intentos fallidos (QA A-03).
 */
class DesbloqueoUsuariosTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
        RateLimiter::clear('acceso-ip:127.0.0.1');
    }

    private function bloqueado(string $rol = 'Agente', $sede = null, ?Empresa $empresa = null): User
    {
        $usuario = $this->crearUsuario($empresa ?? $this->empresa, $rol, $sede);
        $usuario->forceFill([
            'username' => 'bloq'.$usuario->id,
            'password' => 'Clave1234',
            'intentos_fallidos' => 0,
            'bloqueado_hasta' => now()->addMinutes(15),
        ])->save();

        return $usuario;
    }

    /**
     * @return list<string>
     */
    private function permisosDe(Rol $rol): array
    {
        return RolPermiso::with('moduloAccion.modulo', 'moduloAccion.accion')->where('rol_id', $rol->id)->get()
            ->map(fn (RolPermiso $p) => $p->moduloAccion->clave())
            ->all();
    }

    public function test_el_catalogo_tiene_la_accion_y_las_plantillas_la_reciben(): void
    {
        $this->assertDatabaseHas('acciones', ['clave' => 'desbloquear', 'nombre' => 'Desbloquear']);

        $plantilla = fn (string $nombre) => Rol::plantillas()->where('nombre', $nombre)->firstOrFail();

        $this->assertContains('usuarios.desbloquear', $this->permisosDe($plantilla('Administrador')));
        $this->assertContains('usuarios.desbloquear', $this->permisosDe($plantilla('Jefe de seguridad')));
        $this->assertContains('usuarios.ver', $this->permisosDe($plantilla('Jefe de seguridad')));
        $this->assertNotContains('usuarios.editar', $this->permisosDe($plantilla('Jefe de seguridad')));
        $this->assertNotContains('usuarios.desbloquear', $this->permisosDe($plantilla('Supervisor')));
        $this->assertNotContains('usuarios.desbloquear', $this->permisosDe($plantilla('Agente')));

        // La matriz de permisos la muestra como acción adicional del módulo Usuarios
        $this->actingAs($this->crearSuperadmin())->get('/permisos')->assertOk()->assertSee('Desbloquear');
    }

    public function test_la_lista_muestra_el_bloqueo_y_el_candado(): void
    {
        $bloqueado = $this->bloqueado();
        $hora = $bloqueado->bloqueado_hasta->copy()->setTimezone($this->empresa->zona_horaria)->format('H:i');

        $this->actingAs($this->admin)->get('/usuarios')
            ->assertOk()
            ->assertSee("BLOQUEADO hasta {$hora}")
            ->assertSee(route('usuarios.desbloquear', $bloqueado->id))
            ->assertSee('data-confirmar="¿Desbloquear a', false);
    }

    public function test_desbloquea_reinicia_el_contador_audita_y_ya_puede_entrar(): void
    {
        $bloqueado = $this->bloqueado();
        $bloqueado->forceFill(['intentos_fallidos' => 3])->save();

        $this->actingAs($this->admin)->patch("/usuarios/{$bloqueado->id}/desbloquear")
            ->assertRedirect('/usuarios')
            ->assertSessionHas('ok', "«{$bloqueado->name}» desbloqueado; ya puede entrar.");

        $bloqueado->refresh();
        $this->assertNull($bloqueado->bloqueado_hasta);
        $this->assertSame(0, (int) $bloqueado->intentos_fallidos);
        $this->assertDatabaseHas('auditoria', [
            'evento' => 'usuarios.desbloqueado', 'auditable_id' => $bloqueado->id, 'user_id' => $this->admin->id,
        ]);

        auth()->logout();
        $this->post('/login', ['username' => $bloqueado->username, 'password' => 'Clave1234'])->assertRedirect('/');
    }

    public function test_sin_el_permiso_no_hay_candado_ni_desbloqueo(): void
    {
        $bloqueado = $this->bloqueado();

        $this->actingAs($this->crearUsuario($this->empresa, 'Supervisor'))
            ->patch("/usuarios/{$bloqueado->id}/desbloquear")->assertForbidden();

        // Un rol que ve y edita usuarios pero sin "desbloquear": ve el aviso, no el candado
        $sinDesbloqueo = Rol::where('empresa_id', $this->empresa->id)->where('nombre', 'Administrador')->firstOrFail();
        RolPermiso::where('rol_id', $sinDesbloqueo->id)
            ->whereHas('moduloAccion.accion', fn ($q) => $q->where('clave', 'desbloquear'))
            ->delete();

        $this->actingAs($this->admin)->get('/usuarios')
            ->assertSee('BLOQUEADO hasta')
            ->assertDontSee(route('usuarios.desbloquear', $bloqueado->id));
        $this->actingAs($this->admin)->patch("/usuarios/{$bloqueado->id}/desbloquear")->assertForbidden();

        $this->assertNotNull($bloqueado->fresh()->bloqueado_hasta);
    }

    public function test_el_jefe_de_seguridad_desbloquea_solo_en_su_sede_y_a_niveles_inferiores(): void
    {
        $centro = $this->crearSede($this->empresa, 'CEN');
        $playa = $this->crearSede($this->empresa, 'PLA');
        $jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $centro);

        $deCentro = $this->bloqueado('Agente', $centro);
        $dePlaya = $this->bloqueado('Agente', $playa);
        $adminCentro = $this->bloqueado('Administrador', $centro);

        $this->actingAs($jefe)->get('/usuarios')
            ->assertOk()
            ->assertSee(route('usuarios.desbloquear', $deCentro->id))
            ->assertDontSee($dePlaya->name)
            ->assertDontSee(route('usuarios.desbloquear', $adminCentro->id))
            ->assertDontSee('Nuevo Usuario');

        $this->actingAs($jefe)->patch("/usuarios/{$dePlaya->id}/desbloquear")->assertNotFound();
        $this->actingAs($jefe)->patch("/usuarios/{$adminCentro->id}/desbloquear")->assertSessionHas('error');
        $this->assertNotNull($adminCentro->fresh()->bloqueado_hasta);

        $this->actingAs($jefe)->patch("/usuarios/{$deCentro->id}/desbloquear")->assertSessionHas('ok');
        $this->assertNull($deCentro->fresh()->bloqueado_hasta);
    }

    public function test_no_desbloquea_usuarios_de_otra_empresa_ni_cuentas_sin_bloqueo(): void
    {
        $ajeno = $this->bloqueado('Agente', null, $this->crearEmpresa('Hotel Dos'));
        $this->actingAs($this->admin)->patch("/usuarios/{$ajeno->id}/desbloquear")->assertNotFound();
        $this->assertNotNull($ajeno->fresh()->bloqueado_hasta);

        $libre = $this->crearUsuario($this->empresa, 'Agente');
        $this->actingAs($this->admin)->patch("/usuarios/{$libre->id}/desbloquear")->assertSessionHas('aviso');
        $this->assertDatabaseMissing('auditoria', ['evento' => 'usuarios.desbloqueado', 'auditable_id' => $libre->id]);
    }

    public function test_la_migracion_agrega_el_permiso_a_bases_existentes(): void
    {
        // Base "anterior": sin la acción y con el Jefe sin acceso a usuarios
        $accion = DB::table('acciones')->where('clave', 'desbloquear')->value('id');
        DB::table('modulo_acciones')->where('accion_id', $accion)->delete();
        DB::table('acciones')->where('id', $accion)->delete();
        $verUsuarios = DB::table('modulo_acciones as ma')->join('modulos as m', 'm.id', '=', 'ma.modulo_id')
            ->join('acciones as a', 'a.id', '=', 'ma.accion_id')
            ->where('m.clave', 'usuarios')->where('a.clave', 'ver')->value('ma.id');
        DB::table('rol_permisos')->whereIn('rol_id', Rol::where('nombre', 'Jefe de seguridad')->pluck('id'))
            ->where('modulo_accion_id', $verUsuarios)->delete();

        (require database_path('migrations/2026_10_05_000100_agregar_accion_desbloquear_usuarios.php'))->up();

        $rol = fn (string $nombre) => Rol::where('empresa_id', $this->empresa->id)->where('nombre', $nombre)->firstOrFail();
        $this->assertContains('usuarios.desbloquear', $this->permisosDe($rol('Administrador')));
        $this->assertContains('usuarios.desbloquear', $this->permisosDe($rol('Jefe de seguridad')));
        $this->assertContains('usuarios.ver', $this->permisosDe($rol('Jefe de seguridad')));
        $this->assertNotContains('usuarios.desbloquear', $this->permisosDe($rol('Agente')));
        $this->assertContains('usuarios.desbloquear', $this->permisosDe(Rol::plantillas()->where('nombre', 'Administrador')->firstOrFail()));

        // Idempotente: correrla otra vez no duplica nada
        $total = RolPermiso::count();
        (require database_path('migrations/2026_10_05_000100_agregar_accion_desbloquear_usuarios.php'))->up();
        $this->assertSame($total, RolPermiso::count());
    }
}
