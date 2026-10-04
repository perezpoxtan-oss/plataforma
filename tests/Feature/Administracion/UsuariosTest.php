<?php

namespace Tests\Feature\Administracion;

use App\Models\Empresa;
use App\Models\Rol;
use App\Models\User;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Support\Tenancy\EmpresaDeTrabajo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

class UsuariosTest extends TestCase
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
    }

    private function rol(string $nombre, ?Empresa $empresa = null): Rol
    {
        return Rol::where('empresa_id', ($empresa ?? $this->empresa)->id)->where('nombre', $nombre)->firstOrFail();
    }

    /**
     * @return array<string, mixed>
     */
    private function datos(array $extra = []): array
    {
        return array_merge([
            'name' => 'Laura Gómez',
            'numero_colaborador' => 'E-100',
            'username' => 'laura.gomez',
            'email' => 'Laura@Ejemplo.mx',
            'password' => 'Clave1234',
            'rol_id' => $this->rol('Agente')->id,
            'sede_id' => null,
        ], $extra);
    }

    public function test_lista_los_usuarios_de_la_empresa(): void
    {
        $otro = $this->crearUsuario($this->empresa, 'Agente');
        $ajeno = $this->crearUsuario($this->crearEmpresa('Hotel Dos'), 'Agente');

        $this->actingAs($this->admin)->get('/usuarios')
            ->assertOk()
            ->assertSee('Usuarios Operativos')
            ->assertSee($otro->name)
            ->assertDontSee($ajeno->name)
            ->assertSee('Nuevo Usuario');
    }

    public function test_un_agente_no_entra(): void
    {
        $this->actingAs($this->crearUsuario($this->empresa, 'Agente'))->get('/usuarios')->assertForbidden();
    }

    public function test_da_de_alta_un_usuario_que_puede_entrar(): void
    {
        $sede = $this->crearSede($this->empresa);

        $this->actingAs($this->admin)->post('/usuarios', $this->datos(['sede_id' => $sede->id]))
            ->assertRedirect('/usuarios')->assertSessionHas('ok');

        $nuevo = User::where('username', 'laura.gomez')->firstOrFail();
        $this->assertSame('laura@ejemplo.mx', $nuevo->email);
        $this->assertSame($this->empresa->id, $nuevo->empresa_id);
        $this->assertSame($this->admin->id, (int) $nuevo->creado_por);
        $this->assertTrue(Hash::check('Clave1234', $nuevo->password));
        $this->assertSame($sede->id, (int) $nuevo->roles()->first()->pivot->sede_id);
        $this->assertTrue($nuevo->can('accesos.ver'));
        $this->assertDatabaseHas('auditoria', ['evento' => 'usuarios.creado', 'auditable_id' => $nuevo->id]);

        auth()->logout();
        $this->post('/login', ['username' => 'laura.gomez', 'password' => 'Clave1234'])->assertRedirect('/');
    }

    public function test_valida_duplicados_y_contrasena(): void
    {
        $this->actingAs($this->admin)->post('/usuarios', $this->datos());

        $this->actingAs($this->admin)->post('/usuarios', $this->datos(['email' => 'otro@ejemplo.mx']))
            ->assertSessionHasErrors(['username', 'numero_colaborador']);

        $this->actingAs($this->admin)->post('/usuarios', $this->datos(['username' => 'otro', 'numero_colaborador' => 'E-2', 'email' => 'laura@ejemplo.mx']))
            ->assertSessionHasErrors('email');

        $this->actingAs($this->admin)->post('/usuarios', $this->datos(['username' => 'x y', 'password' => 'corta']))
            ->assertSessionHasErrors(['username', 'password']);
    }

    public function test_el_numero_de_colaborador_se_repite_entre_empresas(): void
    {
        $otra = $this->crearEmpresa('Hotel Dos');
        User::factory()->create(['empresa_id' => $otra->id, 'numero_colaborador' => 'E-100']);

        $this->actingAs($this->admin)->post('/usuarios', $this->datos())->assertSessionHas('ok');
    }

    public function test_no_asigna_roles_de_su_nivel_o_superior_ni_de_otra_empresa(): void
    {
        $this->actingAs($this->admin)->post('/usuarios', $this->datos(['rol_id' => $this->rol('Administrador')->id]))
            ->assertSessionHas('error');

        $ajeno = $this->rol('Agente', $this->crearEmpresa('Hotel Dos'));
        $this->actingAs($this->admin)->post('/usuarios', $this->datos(['rol_id' => $ajeno->id]))->assertStatus(422);

        $this->assertDatabaseMissing('users', ['username' => 'laura.gomez']);
    }

    public function test_edita_sin_cambiar_la_contrasena_si_va_vacia(): void
    {
        $usuario = $this->crearUsuario($this->empresa, 'Agente');
        $hash = $usuario->password;

        $this->actingAs($this->admin)->put("/usuarios/{$usuario->id}", $this->datos([
            'name' => 'Nombre Nuevo', 'username' => 'nuevo', 'email' => 'nuevo@ejemplo.mx',
            'password' => '', 'rol_id' => $this->rol('Supervisor')->id, 'activo' => '1',
        ]))->assertSessionHas('ok');

        $usuario->refresh();
        $this->assertSame('Nombre Nuevo', $usuario->name);
        $this->assertSame($hash, $usuario->password);
        $this->assertSame(['Supervisor'], $usuario->roles()->pluck('nombre')->all());
    }

    public function test_no_edita_su_propia_cuenta_ni_superiores(): void
    {
        $this->actingAs($this->admin)->put("/usuarios/{$this->admin->id}", $this->datos(['activo' => '1']))
            ->assertSessionHas('error');

        $otroAdmin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->actingAs($this->admin)->patch("/usuarios/{$otroAdmin->id}/estado", ['activo' => '0'])
            ->assertSessionHas('error');
        $this->assertTrue($otroAdmin->fresh()->activo);
    }

    public function test_desactivar_impide_entrar_y_reactivar_lo_permite(): void
    {
        $usuario = $this->crearUsuario($this->empresa, 'Agente');
        $usuario->forceFill(['username' => 'agente.x', 'password' => 'Clave1234'])->save();

        $this->actingAs($this->admin)->patch("/usuarios/{$usuario->id}/estado", ['activo' => '0'])->assertSessionHas('aviso');
        $this->assertFalse($usuario->fresh()->activo);
        $this->assertDatabaseHas('auditoria', ['evento' => 'usuarios.desactivado', 'auditable_id' => $usuario->id]);

        auth()->logout();
        $this->post('/login', ['username' => 'agente.x', 'password' => 'Clave1234'])->assertSessionHas('acceso', 'credenciales');

        $this->actingAs($this->admin)->patch("/usuarios/{$usuario->id}/estado", ['activo' => '1'])->assertSessionHas('ok');
        auth()->logout();
        $this->post('/login', ['username' => 'agente.x', 'password' => 'Clave1234'])->assertRedirect('/');
    }

    public function test_no_toca_usuarios_de_otra_empresa(): void
    {
        $ajeno = $this->crearUsuario($this->crearEmpresa('Hotel Dos'), 'Agente');

        $this->actingAs($this->admin)->put("/usuarios/{$ajeno->id}", $this->datos(['activo' => '1']))->assertNotFound();
        $this->actingAs($this->admin)->patch("/usuarios/{$ajeno->id}/estado", ['activo' => '0'])->assertNotFound();
    }

    public function test_con_alcance_de_sede_solo_ve_usuarios_de_su_sede(): void
    {
        $centro = $this->crearSede($this->empresa, 'CEN');
        $playa = $this->crearSede($this->empresa, 'PLA');

        $sa = $this->crearSuperadmin();
        $gerente = app(AdministradorRoles::class)->crearRol($sa, $this->empresa->id, ['nombre' => 'Gerente de sede', 'nivel_jerarquia' => 35]);
        app(AdministradorRoles::class)->sincronizarPermisos($sa, $gerente, ['usuarios.ver' => Alcance::Sede]);

        $actor = $this->crearUsuario($this->empresa, 'Gerente de sede', $centro);
        $deCentro = $this->crearUsuario($this->empresa, 'Agente', $centro);
        $dePlaya = $this->crearUsuario($this->empresa, 'Agente', $playa);

        $this->actingAs($actor)->get('/usuarios')
            ->assertSee($deCentro->name)
            ->assertDontSee($dePlaya->name)
            ->assertDontSee('Nuevo Usuario');
    }

    public function test_el_superadmin_elige_empresa_para_ver_usuarios(): void
    {
        $sa = $this->crearSuperadmin();

        $this->actingAs($sa)->get('/usuarios')->assertOk()->assertSee('Elige arriba la');

        $this->actingAs($sa)->withSession([EmpresaDeTrabajo::SESION => $this->empresa->id])
            ->get('/usuarios')->assertSee($this->admin->name);
    }
}
