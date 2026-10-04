<?php

namespace Tests\Feature\Organizacion;

use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Puesto;
use App\Models\Sede;
use App\Models\User;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

class DepartamentosYPuestosTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private Sede $playa;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->playa = $this->crearSede($this->empresa, 'PLA');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
    }

    private function enEmpresa(callable $fn): mixed
    {
        return app(Tenant::class)->conEmpresa($this->empresa->id, $fn);
    }

    private function depto(string $nombre): Departamento
    {
        return $this->enEmpresa(fn () => Departamento::where('nombre', $nombre)->firstOrFail());
    }

    private function puesto(string $nombre): Puesto
    {
        return $this->enEmpresa(fn () => Puesto::where('nombre', $nombre)->firstOrFail());
    }

    // ---------------------------------------------------------- Departamentos

    public function test_alta_en_todas_las_sedes_y_nombre_unico_sin_importar_mayusculas_ni_espacios(): void
    {
        $this->actingAs($this->admin)->post('/departamentos', ['nombre' => '  Recursos   Humanos ', 'todas_las_sedes' => '1'])
            ->assertRedirect('/departamentos')->assertSessionHas('ok', 'Departamento «Recursos Humanos» creado correctamente.');

        $rh = $this->depto('Recursos Humanos');
        $this->assertTrue($rh->todas_las_sedes);
        $this->assertDatabaseHas('auditoria', ['evento' => 'departamentos.creado', 'auditable_id' => $rh->id]);

        $this->actingAs($this->admin)->post('/departamentos', ['nombre' => 'recursos humanos'])->assertSessionHasErrors('nombre');
        $this->actingAs($this->admin)->get('/departamentos')->assertOk()->assertSee('Recursos Humanos')->assertSee('Todas las sedes')->assertSee('Nuevo Departamento');
    }

    public function test_solo_en_algunas_sedes_exige_al_menos_una_de_la_empresa(): void
    {
        $ajena = $this->crearSede($this->crearEmpresa('Hotel Dos'), 'OTR');

        $this->actingAs($this->admin)->post('/departamentos', ['nombre' => 'Club de Playa', 'todas_las_sedes' => '0'])->assertSessionHasErrors('sedes');
        $this->actingAs($this->admin)->post('/departamentos', ['nombre' => 'Club de Playa', 'todas_las_sedes' => '0', 'sedes' => [$ajena->id]])->assertSessionHasErrors('sedes');

        $this->actingAs($this->admin)->post('/departamentos', ['nombre' => 'Club de Playa', 'todas_las_sedes' => '0', 'sedes' => [$this->playa->id, $ajena->id]])->assertSessionHasNoErrors();
        $club = $this->depto('Club de Playa');
        $this->assertFalse($club->todas_las_sedes);
        $this->assertSame([$this->playa->id], $this->enEmpresa(fn () => $club->sedes()->pluck('sedes.id')->all()));
        $this->actingAs($this->admin)->get('/departamentos')->assertSee('Solo en: Sede PLA');
    }

    public function test_editar_cambia_sedes_y_desactivar_se_puede_revertir(): void
    {
        $this->actingAs($this->admin)->post('/departamentos', ['nombre' => 'Club de Playa', 'todas_las_sedes' => '0', 'sedes' => [$this->playa->id]]);
        $club = $this->depto('Club de Playa');

        $this->actingAs($this->admin)->put("/departamentos/{$club->id}", ['nombre' => 'Club de Playa y Albercas', 'todas_las_sedes' => '1'])
            ->assertSessionHas('ok');
        $club->refresh();
        $this->assertTrue($club->todas_las_sedes);
        $this->assertSame(0, $this->enEmpresa(fn () => $club->sedes()->count()));
        $this->assertSame('Club de Playa y Albercas', $club->nombre);

        $this->actingAs($this->admin)->patch("/departamentos/{$club->id}/estado", ['activo' => '0'])->assertSessionHas('aviso');
        $this->assertFalse($club->fresh()->activo);
        $this->actingAs($this->admin)->patch("/departamentos/{$club->id}/estado", ['activo' => '1'])->assertSessionHas('ok');
        $this->assertDatabaseHas('auditoria', ['evento' => 'departamentos.desactivado', 'auditable_id' => $club->id]);
    }

    // ---------------------------------------------------------------- Puestos

    public function test_puesto_con_tipo_y_departamentos_de_la_misma_empresa(): void
    {
        $this->actingAs($this->admin)->post('/departamentos', ['nombre' => 'Seguridad']);
        $seg = $this->depto('Seguridad');
        $otraEmpresa = $this->crearEmpresa('Hotel Dos');
        $ajeno = app(Tenant::class)->conEmpresa($otraEmpresa->id, fn () => Departamento::create(['nombre' => 'Ajeno']));

        $this->actingAs($this->admin)->post('/puestos', ['nombre' => 'Jefe de Seguridad', 'tipo' => 'administrativo', 'departamentos' => [$seg->id, $ajeno->id]])
            ->assertSessionHas('ok', 'Puesto «Jefe de Seguridad» creado correctamente.');
        $jefe = $this->puesto('Jefe de Seguridad');
        $this->assertSame('administrativo', $jefe->tipo);
        $this->assertSame([$seg->id], $this->enEmpresa(fn () => $jefe->departamentos()->pluck('departamentos.id')->all()));

        $this->actingAs($this->admin)->post('/puestos', ['nombre' => 'Gerente', 'tipo' => 'operativo'])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post('/puestos', ['nombre' => 'X', 'tipo' => 'directivo'])->assertSessionHasErrors('tipo');
        $this->actingAs($this->admin)->post('/puestos', ['nombre' => 'JEFE DE SEGURIDAD', 'tipo' => 'operativo'])->assertSessionHasErrors('nombre');

        $this->actingAs($this->admin)->get('/puestos')->assertOk()
            ->assertSee('Administrativos')->assertSee('ADMIN')->assertSee('Aplica en cualquier departamento')->assertSee('Seguridad');
    }

    public function test_editar_un_puesto_conserva_departamentos_desactivados(): void
    {
        $this->actingAs($this->admin)->post('/departamentos', ['nombre' => 'Seguridad']);
        $this->actingAs($this->admin)->post('/departamentos', ['nombre' => 'Bodega']);
        $seg = $this->depto('Seguridad');
        $bodega = $this->depto('Bodega');
        $this->actingAs($this->admin)->post('/puestos', ['nombre' => 'Vigilante', 'tipo' => 'operativo', 'departamentos' => [$seg->id, $bodega->id]]);
        $this->actingAs($this->admin)->patch("/departamentos/{$bodega->id}/estado", ['activo' => '0']);

        $vigilante = $this->puesto('Vigilante');
        // La lista del formulario ya no muestra Bodega: solo llega Seguridad
        $this->actingAs($this->admin)->put("/puestos/{$vigilante->id}", ['nombre' => 'Vigilante', 'tipo' => 'operativo', 'departamentos' => [$seg->id]])->assertSessionHas('ok');

        $ids = $this->enEmpresa(fn () => $vigilante->departamentos()->orderBy('departamentos.id')->pluck('departamentos.id')->all());
        $this->assertSame([$seg->id, $bodega->id], $ids);

        $this->actingAs($this->admin)->patch("/puestos/{$vigilante->id}/estado", ['activo' => '0'])->assertSessionHas('aviso');
        $this->assertDatabaseHas('auditoria', ['evento' => 'puestos.desactivado', 'auditable_id' => $vigilante->id]);
    }

    // ------------------------------------------------------- Permisos y empresa

    public function test_aislamiento_entre_empresas(): void
    {
        $this->actingAs($this->admin)->post('/departamentos', ['nombre' => 'Vigilancia Nocturna']);
        $this->actingAs($this->admin)->post('/puestos', ['nombre' => 'Agente', 'tipo' => 'operativo']);
        $ajeno = $this->crearUsuario($this->crearEmpresa('Hotel Dos'), 'Administrador');

        $this->actingAs($ajeno)->get('/departamentos')->assertOk()->assertDontSee('Vigilancia Nocturna');
        $this->actingAs($ajeno)->put('/departamentos/'.$this->depto('Vigilancia Nocturna')->id, ['nombre' => 'Hackeo'])->assertNotFound();
        $this->actingAs($ajeno)->patch('/puestos/'.$this->puesto('Agente')->id.'/estado', ['activo' => '0'])->assertNotFound();
        // El mismo nombre sí puede existir en otra empresa
        $this->actingAs($ajeno)->post('/departamentos', ['nombre' => 'Vigilancia Nocturna'])->assertSessionHasNoErrors();
    }

    public function test_con_alcance_de_sede_solo_consulta_lo_que_aplica_en_su_sede(): void
    {
        $this->actingAs($this->admin)->post('/departamentos', ['nombre' => 'Seguridad']);
        $this->actingAs($this->admin)->post('/departamentos', ['nombre' => 'Club de Playa', 'todas_las_sedes' => '0', 'sedes' => [$this->playa->id]]);

        $sa = $this->crearSuperadmin();
        $gerente = app(AdministradorRoles::class)->crearRol($sa, $this->empresa->id, ['nombre' => 'Gerente', 'nivel_jerarquia' => 35]);
        app(AdministradorRoles::class)->sincronizarPermisos($sa, $gerente, [
            'departamentos.ver' => Alcance::Sede, 'departamentos.crear' => Alcance::Sede, 'departamentos.editar' => Alcance::Sede,
        ]);
        $usuario = $this->crearUsuario($this->empresa, 'Gerente', $this->centro);
        $this->flushSession();

        $this->actingAs($usuario)->get('/departamentos')->assertOk()
            ->assertSee('Seguridad')->assertDontSee('Club de Playa')->assertDontSee('Nuevo Departamento')->assertDontSee('data-accion="editar-registro"', false);
        $this->actingAs($usuario)->post('/departamentos', ['nombre' => 'Otro'])->assertForbidden();
        $this->actingAs($usuario)->put('/departamentos/'.$this->depto('Seguridad')->id, ['nombre' => 'X'])->assertForbidden();
    }

    public function test_sin_permiso_no_entra_y_el_superadmin_elige_empresa(): void
    {
        $agente = $this->crearUsuario($this->empresa, 'Agente');
        $this->actingAs($agente)->get('/departamentos')->assertForbidden();
        $this->actingAs($agente)->get('/puestos')->assertForbidden();

        $sa = $this->crearSuperadmin();
        $this->actingAs($sa)->get('/puestos')->assertSee('Elige arriba la');
        $this->actingAs($sa)->withSession([EmpresaDeTrabajo::SESION => $this->empresa->id])->get('/puestos')->assertSee('Nuevo Puesto');
        $this->actingAs($sa)->withSession([EmpresaDeTrabajo::SESION => $this->empresa->id])->post('/departamentos', ['nombre' => 'Seguridad'])->assertSessionHasNoErrors();
    }
}
