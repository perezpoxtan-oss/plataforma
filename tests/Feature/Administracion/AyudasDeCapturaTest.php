<?php

namespace Tests\Feature\Administracion;

use App\Models\Empresa;
use App\Models\ModuloAccion;
use App\Models\Rol;
use App\Models\RolPermiso;
use App\Models\User;
use App\Support\Tenancy\EmpresaDeTrabajo;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Ajustes de pruebas QA, ronda 2: nivel de rol explicado (R-02) y empresa
 * visible en Usuarios y Roles (U-01, multi-empresa).
 */
class AyudasDeCapturaTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa('Hotel Demo');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
    }

    public function test_el_alta_de_rol_explica_el_nivel_minimo_con_el_nivel_real(): void
    {
        $this->actingAs($this->admin)->get('/roles')
            ->assertOk()
            ->assertSee('min="11"', false)
            ->assertSeeInOrder(['Tu nivel es', '10', 'Solo puedes crear roles de nivel', '11', 'en adelante'], false)
            ->assertSee('20 Director, 25 Recursos Humanos, 30 Jefe de seguridad, 40 Asistente, 50 Supervisor, 60 Agente')
            ->assertSee('data-mensaje-min="Tu nivel es 10: el nivel del rol debe ser 11 o mayor (número mayor = menos autoridad)."', false);

        // Con otro nivel, el texto cambia
        $jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad');
        $this->darPermisosDeRoles();

        $this->actingAs($jefe)->get('/roles')
            ->assertOk()
            ->assertSee('min="31"', false)
            ->assertSee('40 Asistente, 50 Supervisor, 60 Agente')
            ->assertDontSee('25 Recursos Humanos, 30');
    }

    public function test_el_servidor_explica_por_que_rechaza_un_nivel_superior(): void
    {
        $this->actingAs($this->admin)->post('/roles', ['nombre' => 'Jefe máximo', 'nivel_jerarquia' => 5])
            ->assertSessionHas('error', 'Tu nivel es 10: solo puedes crear o administrar roles de nivel 11 en adelante (número mayor = menos autoridad). El nivel 5 es igual o superior al tuyo.');
    }

    public function test_usuarios_muestra_la_empresa_en_el_encabezado_y_en_el_alta(): void
    {
        $this->actingAs($this->admin)->get('/usuarios')
            ->assertOk()
            ->assertSee('Usuarios de «Hotel Demo»', false)
            ->assertSeeInOrder(['Alta de Usuario', 'Empresa:', 'Hotel Demo', '1. Sede'], false);
    }

    public function test_el_superadmin_ve_la_empresa_elegida_en_usuarios_y_roles(): void
    {
        $otra = $this->crearEmpresa('Hotel Playa');
        $sa = $this->crearSuperadmin();

        $this->actingAs($sa)->withSession([EmpresaDeTrabajo::SESION => $otra->id])->get('/usuarios')
            ->assertOk()
            ->assertSee('Usuarios de «Hotel Playa»', false)
            ->assertDontSee('Usuarios de «Hotel Demo»', false);

        $this->actingAs($sa)->withSession([EmpresaDeTrabajo::SESION => $otra->id])->get('/roles')
            ->assertOk()
            ->assertSee('Roles de «Hotel Playa»', false);

        // En plantillas no hay empresa: se mantiene el texto de plantillas
        $this->actingAs($sa)->withSession([EmpresaDeTrabajo::SESION => null])->get('/roles')
            ->assertOk()
            ->assertSee('Plantillas que recibe cada empresa nueva')
            ->assertDontSee('Roles de «', false);
    }

    /**
     * El Jefe de seguridad no administra roles por plantilla: se le da
     * "roles.ver" y "roles.crear" para revisar su ayuda de nivel.
     */
    private function darPermisosDeRoles(): void
    {
        $rolId = Rol::where('empresa_id', $this->empresa->id)->where('nombre', 'Jefe de seguridad')->value('id');
        foreach (['ver', 'crear'] as $accion) {
            $ma = ModuloAccion::whereHas('modulo', fn ($q) => $q->where('clave', 'roles'))
                ->whereHas('accion', fn ($q) => $q->where('clave', $accion))->value('id');
            RolPermiso::create(['rol_id' => $rolId, 'modulo_accion_id' => $ma, 'alcance' => 'empresa']);
        }
    }
}
