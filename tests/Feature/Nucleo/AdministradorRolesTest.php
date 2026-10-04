<?php

namespace Tests\Feature\Nucleo;

use App\Models\Auditoria;
use App\Models\Empresa;
use App\Models\Rol;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdministradorRolesTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private AdministradorRoles $servicio;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->servicio = app(AdministradorRoles::class);
    }

    private function rol(string $nombre): Rol
    {
        return Rol::where('empresa_id', $this->empresa->id)->where('nombre', $nombre)->firstOrFail();
    }

    public function test_el_administrador_cambia_permisos_de_un_rol_inferior(): void
    {
        $admin = $this->crearUsuario($this->empresa, 'Administrador');
        $agente = $this->crearUsuario($this->empresa, 'Agente');

        $this->servicio->sincronizarPermisos($admin, $this->rol('Agente'), [
            'accesos.ver' => Alcance::Sede,
            'accesos.eliminar' => Alcance::Sede,
        ]);

        $this->assertTrue($agente->fresh()->can('accesos.eliminar'));
        $this->assertFalse($agente->fresh()->can('novedades.ver'));
        $this->assertSame(1, Auditoria::where('evento', 'permisos.rol_actualizado')->count());
    }

    public function test_nadie_otorga_un_permiso_que_no_tiene(): void
    {
        $jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad');
        // El jefe no tiene permiso de editar permisos; se le da solo ese
        $this->servicio->sincronizarPermisos($this->crearSuperadmin(), $this->rol('Jefe de seguridad'), [
            'permisos.editar' => Alcance::Empresa,
            'accesos.ver' => Alcance::Sede,
        ]);

        $this->expectException(AuthorizationException::class);
        $this->servicio->sincronizarPermisos($jefe->fresh(), $this->rol('Agente'), [
            'usuarios.eliminar' => Alcance::Empresa,
        ]);
    }

    public function test_nadie_otorga_mas_alcance_del_que_tiene(): void
    {
        $jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad');
        $this->servicio->sincronizarPermisos($this->crearSuperadmin(), $this->rol('Jefe de seguridad'), [
            'permisos.editar' => Alcance::Empresa,
            'accesos.ver' => Alcance::Sede,
        ]);

        $this->expectException(AuthorizationException::class);
        $this->servicio->sincronizarPermisos($jefe->fresh(), $this->rol('Agente'), [
            'accesos.ver' => Alcance::Empresa,
        ]);
    }

    public function test_nadie_edita_un_rol_de_su_mismo_nivel_o_superior(): void
    {
        $director = $this->crearUsuario($this->empresa, 'Director');
        $this->servicio->sincronizarPermisos($this->crearSuperadmin(), $this->rol('Director'), [
            'permisos.editar' => Alcance::Empresa,
        ]);

        $this->expectException(AuthorizationException::class);
        $this->servicio->sincronizarPermisos($director->fresh(), $this->rol('Administrador'), []);
    }

    public function test_no_se_puede_asignar_un_rol_superior_al_propio(): void
    {
        $director = $this->crearUsuario($this->empresa, 'Director');
        $this->servicio->sincronizarPermisos($this->crearSuperadmin(), $this->rol('Director'), [
            'usuarios.editar' => Alcance::Empresa,
        ]);
        $nuevo = $this->crearUsuario($this->empresa);

        $this->expectException(AuthorizationException::class);
        $this->servicio->asignarRol($director->fresh(), $nuevo, $this->rol('Administrador'));
    }

    public function test_no_se_administran_usuarios_de_otra_empresa(): void
    {
        $admin = $this->crearUsuario($this->empresa, 'Administrador');
        $otra = $this->crearEmpresa('Otra');
        $ajeno = $this->crearUsuario($otra);

        $this->expectException(AuthorizationException::class);
        $this->servicio->asignarRol($admin, $ajeno, $this->rol('Agente'));
    }

    public function test_un_permiso_inexistente_se_rechaza(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->servicio->sincronizarPermisos($this->crearSuperadmin(), $this->rol('Agente'), [
            'modulo_inventado.ver' => Alcance::Sede,
        ]);
    }
}
