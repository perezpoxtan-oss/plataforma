<?php

namespace Tests\Feature\SeguridadAuditoria;

use App\Models\Empresa;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\User;
use App\Models\UsuarioRol;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Auditoría de autorización (AZ): escalada de privilegios en Usuarios,
 * Roles y Matriz de permisos.
 */
class EscaladaPrivilegiosTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private Sede $playa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->playa = $this->crearSede($this->empresa, 'PLA');
    }

    private function rol(string $nombre, ?Empresa $empresa = null): Rol
    {
        return Rol::where('empresa_id', ($empresa ?? $this->empresa)->id)->where('nombre', $nombre)->firstOrFail();
    }

    /**
     * Gerente de una sede: administra usuarios solo de su sede.
     */
    private function gerenteDeSede(): User
    {
        $sa = $this->crearSuperadmin();
        $gerente = app(AdministradorRoles::class)->crearRol($sa, $this->empresa->id, ['nombre' => 'Gerente de sede', 'nivel_jerarquia' => 35]);
        app(AdministradorRoles::class)->sincronizarPermisos($sa, $gerente, [
            'usuarios.ver' => Alcance::Sede,
            'usuarios.crear' => Alcance::Sede,
            'usuarios.editar' => Alcance::Sede,
        ]);

        return $this->crearUsuario($this->empresa, 'Gerente de sede', $this->centro);
    }

    /**
     * @return array<string, mixed>
     */
    private function datos(array $extra = []): array
    {
        return array_merge([
            'name' => 'Laura Gómez',
            'username' => 'laura.gomez',
            'email' => 'laura@ejemplo.mx',
            'password' => 'Clave1234',
            'rol_id' => $this->rol('Agente')->id,
            'sede_id' => $this->centro->id,
        ], $extra);
    }

    /**
     * AZ-01: con alcance de sede no se crea una cuenta para otra sede ni
     * para "todas las sedes" (la cuenta nueva tendría más alcance que quien
     * la crea).
     */
    public function test_az01_con_alcance_de_sede_no_crea_usuarios_de_otra_sede_ni_de_todas(): void
    {
        $gerente = $this->gerenteDeSede();

        // El formulario ya no ofrece "todas las sedes" ni otras sedes
        $this->actingAs($gerente)->get('/usuarios')->assertOk()
            ->assertDontSee('Todas las sedes de la empresa')
            ->assertDontSee('<option value="'.$this->playa->id.'" >', false);
        $this->actingAs($this->crearUsuario($this->empresa, 'Administrador'))->get('/usuarios')->assertSee('Todas las sedes de la empresa');

        $this->actingAs($gerente)->post('/usuarios', $this->datos(['sede_id' => $this->playa->id]));
        $this->actingAs($gerente)->post('/usuarios', $this->datos(['sede_id' => null, 'username' => 'todas', 'email' => 'todas@ejemplo.mx']));
        $this->assertFalse(User::whereIn('username', ['laura.gomez', 'todas'])->exists());

        // En su sede, sí
        $this->actingAs($gerente)->post('/usuarios', $this->datos())->assertSessionHas('ok');
        $this->assertSame($this->centro->id, (int) UsuarioRol::where('user_id', User::where('username', 'laura.gomez')->value('id'))->value('sede_id'));
    }

    /**
     * AZ-01: tampoco se mueve a un usuario de su sede a otra sede o a todas.
     */
    public function test_az01_con_alcance_de_sede_no_mueve_usuarios_a_otra_sede_ni_a_todas(): void
    {
        $gerente = $this->gerenteDeSede();
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $datos = ['name' => $agente->name, 'username' => 'agente.centro', 'email' => $agente->email, 'rol_id' => $this->rol('Agente')->id, 'activo' => '1'];

        $this->actingAs($gerente)->put("/usuarios/{$agente->id}", $datos + ['sede_id' => $this->playa->id]);
        $this->actingAs($gerente)->put("/usuarios/{$agente->id}", $datos + ['sede_id' => '']);

        $this->assertSame($this->centro->id, (int) UsuarioRol::where('user_id', $agente->id)->value('sede_id'));
    }

    /**
     * Campos extra en el alta o la edición (empresa, superadministrador,
     * autor) no se asignan.
     */
    public function test_campos_extra_no_se_asignan_en_usuarios(): void
    {
        $admin = $this->crearUsuario($this->empresa, 'Administrador');
        $otra = $this->crearEmpresa('Hotel Intruso');

        $this->actingAs($admin)->post('/usuarios', $this->datos([
            'empresa_id' => $otra->id, 'es_superadmin' => '1', 'creado_por' => 999, 'bloqueado_hasta' => null, 'intentos_fallidos' => 0,
        ]))->assertSessionHas('ok');

        $nuevo = User::where('username', 'laura.gomez')->firstOrFail();
        $this->assertSame($this->empresa->id, (int) $nuevo->empresa_id);
        $this->assertFalse((bool) $nuevo->es_superadmin);
        $this->assertSame($admin->id, (int) $nuevo->creado_por);

        // Con el rol o la sede de otra empresa no pasa
        $rolAjeno = $this->rol('Agente', $otra);
        $sedeAjena = $this->crearSede($otra, 'INT');
        $this->actingAs($admin)->post('/usuarios', $this->datos(['username' => 'x1', 'email' => 'x1@ejemplo.mx', 'rol_id' => $rolAjeno->id]))->assertStatus(422);
        $this->actingAs($admin)->post('/usuarios', $this->datos(['username' => 'x2', 'email' => 'x2@ejemplo.mx', 'sede_id' => $sedeAjena->id]))->assertStatus(422);
        $this->assertFalse(User::whereIn('username', ['x1', 'x2'])->exists());
    }

    /**
     * Nadie se cambia a sí mismo de rol ni toca la cuenta del Super
     * Administrador o de un superior.
     */
    public function test_nadie_cambia_su_propio_rol_ni_edita_superiores_ni_al_superadmin(): void
    {
        $jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad');
        $sa = $this->crearSuperadmin();
        app(AdministradorRoles::class)->sincronizarPermisos($sa, $this->rol('Jefe de seguridad'), [
            'usuarios.ver' => Alcance::Empresa, 'usuarios.editar' => Alcance::Empresa, 'usuarios.eliminar' => Alcance::Empresa,
        ]);
        $admin = $this->crearUsuario($this->empresa, 'Administrador');

        $datos = fn (User $u) => ['name' => $u->name, 'username' => 'u'.$u->id, 'email' => $u->email, 'rol_id' => $this->rol('Agente')->id, 'activo' => '1'];

        // Su propia cuenta
        $this->actingAs($jefe)->put("/usuarios/{$jefe->id}", $datos($jefe))->assertSessionHas('error');
        $this->assertSame('Jefe de seguridad', $jefe->fresh()->nombreRolPrincipal());
        // Un superior
        $this->actingAs($jefe)->put("/usuarios/{$admin->id}", $datos($admin));
        $this->actingAs($jefe)->patch("/usuarios/{$admin->id}/estado", ['activo' => '0']);
        $this->assertTrue($admin->fresh()->activo);
        $this->assertSame('Administrador', $admin->fresh()->nombreRolPrincipal());
        // El Super Administrador
        $this->actingAs($jefe)->put("/usuarios/{$sa->id}", $datos($sa))->assertNotFound();
        $this->actingAs($jefe)->patch("/usuarios/{$sa->id}/estado", ['activo' => '0'])->assertNotFound();
        $this->assertTrue($sa->fresh()->activo);
    }

    /**
     * Roles: nadie crea, sube ni edita un rol de su nivel o superior, y la
     * matriz no otorga lo que el actor no tiene.
     */
    public function test_roles_y_matriz_no_permiten_escalar(): void
    {
        $sa = $this->crearSuperadmin();
        $jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad'); // nivel 30
        app(AdministradorRoles::class)->sincronizarPermisos($sa, $this->rol('Jefe de seguridad'), [
            'roles.ver' => Alcance::Sede, 'roles.crear' => Alcance::Sede, 'roles.editar' => Alcance::Sede,
            'permisos.ver' => Alcance::Sede, 'permisos.editar' => Alcance::Sede, 'llaves.ver' => Alcance::Sede,
        ]);

        // Crear en su nivel o arriba
        $this->actingAs($jefe)->post('/roles', ['nombre' => 'Jefe 2', 'nivel_jerarquia' => 29])->assertSessionHas('error');
        $this->actingAs($jefe)->post('/roles', ['nombre' => 'Casi admin', 'nivel_jerarquia' => 31])->assertSessionHas('ok');
        $this->actingAs($jefe)->post('/roles', ['nombre' => 'Casi admin 2', 'nivel_jerarquia' => 5])->assertSessionHas('error');
        // Subir un rol inferior por encima de él
        $agente = $this->rol('Agente');
        $this->actingAs($jefe)->put("/roles/{$agente->id}", ['nombre' => 'Agente', 'nivel_jerarquia' => 1])->assertSessionHas('error');
        $this->assertSame(60, $agente->fresh()->nivel_jerarquia);
        // Editar su propio rol (o darle permisos)
        $propio = $this->rol('Jefe de seguridad');
        $this->actingAs($jefe)->put("/roles/{$propio->id}", ['nombre' => 'Jefe', 'nivel_jerarquia' => 32])->assertSessionHas('error');
        $this->actingAs($jefe)->put("/permisos/{$propio->id}", ['permisos' => ['usuarios' => ['acciones' => ['ver', 'crear'], 'alcance' => 'empresa']]])->assertSessionHas('error');
        // Otorgar a un rol inferior algo que no tiene, o con más alcance
        $this->actingAs($jefe)->put("/permisos/{$agente->id}", ['permisos' => ['usuarios' => ['acciones' => ['ver', 'crear'], 'alcance' => 'sede']]])->assertSessionHas('error');
        $this->actingAs($jefe)->put("/permisos/{$agente->id}", ['permisos' => ['llaves' => ['acciones' => ['ver'], 'alcance' => 'empresa']]])->assertSessionHas('error');
        $this->assertFalse(app(Autorizador::class)->puede($this->crearUsuario($this->empresa, 'Agente'), 'usuarios.crear'));
        // Las plantillas de la plataforma no se tocan desde una empresa
        $plantilla = Rol::plantillas()->where('nombre', 'Agente')->firstOrFail();
        $this->actingAs($jefe)->put("/roles/{$plantilla->id}", ['nombre' => 'Agente', 'nivel_jerarquia' => 61])->assertNotFound();
        $this->actingAs($jefe)->put("/permisos/{$plantilla->id}", ['permisos' => []])->assertNotFound();
    }

    /**
     * AZ-02: roles, matriz, avisos y datos de la empresa son de toda la
     * empresa: con alcance de una sede se consultan, no se cambian.
     */
    public function test_az02_lo_de_toda_la_empresa_no_se_cambia_con_alcance_de_sede(): void
    {
        $sa = $this->crearSuperadmin();
        $gerente = app(AdministradorRoles::class)->crearRol($sa, $this->empresa->id, ['nombre' => 'Gerente de sede', 'nivel_jerarquia' => 35]);
        app(AdministradorRoles::class)->sincronizarPermisos($sa, $gerente, [
            'roles.ver' => Alcance::Sede, 'roles.crear' => Alcance::Sede, 'roles.editar' => Alcance::Sede, 'roles.eliminar' => Alcance::Sede,
            'permisos.ver' => Alcance::Sede, 'permisos.editar' => Alcance::Sede, 'llaves.ver' => Alcance::Sede, 'llaves.crear' => Alcance::Sede,
            'configuracion.ver' => Alcance::Sede, 'configuracion.editar' => Alcance::Sede,
            'empresas.ver' => Alcance::Sede, 'empresas.editar' => Alcance::Sede,
        ]);
        $actor = $this->crearUsuario($this->empresa, 'Gerente de sede', $this->centro);
        $agente = $this->rol('Agente');
        $antes = $agente->permisos()->count();

        // Roles y matriz: el rol Agente también lo usan los agentes de Playa
        $this->actingAs($actor)->put("/permisos/{$agente->id}", ['permisos' => ['llaves' => ['acciones' => ['ver', 'crear'], 'alcance' => 'sede']]])->assertSessionHas('error');
        $this->actingAs($actor)->put("/roles/{$agente->id}", ['nombre' => 'Agente', 'nivel_jerarquia' => 61, 'activo' => '0'])->assertSessionHas('error');
        $this->actingAs($actor)->delete("/roles/{$agente->id}")->assertSessionHas('error');
        $this->actingAs($actor)->post('/roles', ['nombre' => 'Nuevo', 'nivel_jerarquia' => 75])->assertSessionHas('error');
        $this->assertSame($antes, $agente->permisos()->count());
        $this->assertTrue($agente->fresh()->activo);
        $this->assertFalse(Rol::where('nombre', 'Nuevo')->exists());

        // Avisos por correo y datos de la empresa
        $this->actingAs($actor)->get('/configuracion')->assertOk()->assertDontSee('Guardar avisos');
        $this->actingAs($actor)->put('/configuracion/avisos', ['avisos' => []])->assertForbidden();
        $this->actingAs($actor)->put("/empresas/{$this->empresa->id}", [
            'nombre_comercial' => 'Cambiada', 'razon_social' => 'X', 'rfc' => 'HUN200101AA1', 'zona_horaria' => 'America/Cancun',
        ])->assertForbidden();
        $this->assertSame('Hotel Uno', $this->empresa->fresh()->nombre_comercial);

        // Con alcance de empresa, sí
        $admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->actingAs($admin)->put("/permisos/{$agente->id}", ['permisos' => ['llaves' => ['acciones' => ['ver', 'crear'], 'alcance' => 'sede']]])->assertSessionHas('ok');
    }

    /**
     * AZ-03: el registro de otra empresa responde 404 aunque no se tenga el
     * permiso (un 403 revelaría que ese id existe).
     */
    public function test_az03_otra_empresa_recibe_404_sin_revelar_existencia(): void
    {
        $otra = $this->crearEmpresa('Hotel Intruso');
        $intruso = $this->crearUsuario($otra); // sin rol
        $usuario = $this->crearUsuario($this->empresa, 'Agente');
        $rol = $this->rol('Agente');

        foreach ([
            ['put', "/roles/{$rol->id}"], ['delete', "/roles/{$rol->id}"], ['put', "/permisos/{$rol->id}"],
            ['put', "/usuarios/{$usuario->id}"], ['patch', "/usuarios/{$usuario->id}/estado"], ['patch', "/usuarios/{$usuario->id}/desbloquear"],
            ['put', "/empresas/{$this->empresa->id}"], ['patch', "/empresas/{$this->empresa->id}/estado"],
        ] as [$metodo, $uri]) {
            $this->actingAs($intruso)->{$metodo}($uri)->assertNotFound();
        }
        // El mismo usuario de su empresa, sin permiso: 403
        $this->actingAs($this->crearUsuario($this->empresa))->put("/roles/{$rol->id}")->assertForbidden();
    }
}
