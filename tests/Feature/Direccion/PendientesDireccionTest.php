<?php

namespace Tests\Feature\Direccion;

use App\Models\Auditoria;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Rol;
use App\Models\Rubro;
use App\Models\Sede;
use App\Models\User;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Support\HoraLocal;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Hora local, pantallas de error, Bitácora de auditoría y aviso de pendientes en el Inicio.
 */
class PendientesDireccionTest extends TestCase
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
        $this->empresa->forceFill(['zona_horaria' => 'America/Cancun'])->save();
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
    }

    // ------------------------------------------------------------- Hora local

    public function test_las_fechas_se_muestran_en_la_hora_de_la_empresa_o_de_la_sede(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-04 22:30:00', 'UTC'));
        $this->actingAs($this->admin)->post('/departamentos', ['nombre' => 'Seguridad']);

        // 22:30 UTC = 17:30 en Cancún
        $this->actingAs($this->admin)->get('/departamentos')->assertSee('04/10/2026 17:30')->assertDontSee('22:30');

        // Quien trabaja en una sola sede con zona propia la ve en esa zona
        app(Tenant::class)->conEmpresa($this->empresa->id, fn () => $this->centro->forceFill(['zona_horaria' => 'America/Tijuana'])->save());
        $agente = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        $this->actingAs($agente);
        $this->app->forgetScopedInstances();
        $this->assertSame('America/Tijuana', app(HoraLocal::class)->zona());
        $this->assertSame('15:30', app(HoraLocal::class)->formatear(now(), 'H:i'));
        Carbon::setTestNow();
    }

    // -------------------------------------------------------- Errores en español

    public function test_las_pantallas_de_error_estan_en_espanol(): void
    {
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->actingAs($agente)->get('/roles')->assertForbidden()->assertSee('No tienes permiso para ver esto')->assertDontSee('unauthorized');
        $this->actingAs($agente)->get('/una-pagina-que-no-existe')->assertNotFound()->assertSee('No encontramos lo que buscas');
    }

    // ------------------------------------------------------ Bitácora de auditoría

    public function test_la_bitacora_muestra_lo_de_la_empresa_legible_y_filtrable(): void
    {
        $this->actingAs($this->admin)->post('/departamentos', ['nombre' => 'Seguridad']);
        $this->actingAs($this->admin)->post('/puestos', ['nombre' => 'Vigilante', 'tipo' => 'operativo']);
        // Algo de otra empresa no debe verse
        $otro = $this->crearUsuario($this->crearEmpresa('Hotel Dos'), 'Administrador');
        $this->actingAs($otro)->post('/departamentos', ['nombre' => 'Secreto de otra empresa']);
        $this->flushSession();

        $this->actingAs($this->admin)->get('/auditoria')->assertOk()
            ->assertSee('Bitácora de auditoría')->assertSee('Departamento · Seguridad')->assertSee('Puesto · Vigilante')
            ->assertSee('Alta')->assertDontSee('Secreto de otra empresa');

        $this->actingAs($this->admin)->get('/auditoria?modulo=puestos')->assertSee('Puesto · Vigilante')->assertDontSee('Departamento · Seguridad');
        $this->actingAs($this->admin)->get('/auditoria?texto=Vigil')->assertSee('Puesto · Vigilante')->assertDontSee('Departamento · Seguridad');
        $this->actingAs($this->admin)->get('/auditoria?desde=2000-01-01&hasta=2000-01-02')->assertSee('No hay movimientos');

        $csv = $this->actingAs($this->admin)->get('/auditoria/exportar?modulo=departamentos');
        $csv->assertOk();
        $contenido = $csv->streamedContent();
        $this->assertStringContainsString('Departamento · Seguridad', $contenido);
        $this->assertStringNotContainsString('Vigilante', $contenido);
    }

    public function test_sin_alcance_de_empresa_solo_ve_sus_movimientos_y_sin_permiso_no_entra(): void
    {
        $this->actingAs($this->admin)->post('/departamentos', ['nombre' => 'Seguridad']);

        $sa = $this->crearSuperadmin();
        $gerente = app(AdministradorRoles::class)->crearRol($sa, $this->empresa->id, ['nombre' => 'Gerente', 'nivel_jerarquia' => 35]);
        app(AdministradorRoles::class)->sincronizarPermisos($sa, $gerente, ['auditoria.ver' => Alcance::Sede]);
        $usuario = $this->crearUsuario($this->empresa, 'Gerente', $this->centro);
        $this->flushSession();

        $this->actingAs($usuario)->get('/auditoria')->assertOk()->assertSee('Ves solo tus propios movimientos')->assertDontSee('Departamento · Seguridad');
        $this->actingAs($usuario)->get('/auditoria/exportar')->assertForbidden();
        $this->actingAs($this->crearUsuario($this->empresa, 'Agente', $this->centro))->get('/auditoria')->assertForbidden();

        // El Super Administrador sin empresa ve lo de la plataforma; con empresa, lo de esa empresa
        $this->actingAs($sa)->get('/auditoria')->assertOk()->assertDontSee('Departamento · Seguridad');
        $this->actingAs($sa)->withSession([EmpresaDeTrabajo::SESION => $this->empresa->id])->get('/auditoria')->assertSee('Departamento · Seguridad');
        $this->assertGreaterThan(0, Auditoria::where('empresa_id', $this->empresa->id)->count());
    }

    // ---------------------------------------------------- Aviso en el Inicio

    public function test_recursos_humanos_ve_en_el_inicio_las_altas_provisionales_por_validar(): void
    {
        $rh = $this->crearUsuario($this->empresa, 'Recursos Humanos');
        $this->actingAs($rh)->get('/')->assertOk()->assertDontSee('por validar');

        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->actingAs($agente)->postJson('/colaboradores/rapido', ['nombre' => 'Jorge', 'apellido_paterno' => 'Méndez', 'sede_id' => $this->centro->id])->assertCreated();
        $this->actingAs($agente)->get('/')->assertDontSee('por validar');

        $this->actingAs($rh)->get('/')->assertSee('1 alta provisional por validar')->assertSee('registro=provisional', false);
        $this->assertSame(1, app(Tenant::class)->conEmpresa($this->empresa->id, fn () => Colaborador::where('provisional', true)->count()));
    }

    // ------------------------------------------- Sedes y Matriz contraíble

    public function test_las_sedes_se_llaman_sedes_en_todos_los_rubros(): void
    {
        foreach (Rubro::all() as $rubro) {
            $this->assertSame('Sede', $rubro->terminologia['sede'], $rubro->clave);
            $this->assertSame('Sedes', $rubro->terminologia['sedes'], $rubro->clave);
        }
        $this->actingAs($this->admin)->get('/sedes')->assertSee('Alta Sedes')->assertSee('Nueva Sede')->assertDontSee('Hoteles');
    }

    public function test_la_matriz_agrupa_por_area_contraible_con_resumen(): void
    {
        $agente = Rol::where('empresa_id', $this->empresa->id)->where('nombre', 'Agente')->firstOrFail();

        $this->actingAs($this->admin)->get("/permisos?rol={$agente->id}")->assertOk()
            ->assertSee('data-alternar-area="area-direccion"', false)
            ->assertSee('data-alternar-area="area-recursos_humanos"', false)
            ->assertSee('data-alternar-area="area-seguridad"', false)
            ->assertSee('id="area-seguridad" class="modulos-area" hidden', false)
            ->assertSee('permiso(s) otorgado(s)')
            ->assertSee('Expandir todo');
    }
}
