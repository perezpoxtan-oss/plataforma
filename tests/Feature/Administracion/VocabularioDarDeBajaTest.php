<?php

namespace Tests\Feature\Administracion;

use App\Models\Empresa;
use App\Models\Rol;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Criterio 4 (diez verbos): en Empresas, Sedes, Usuarios y Roles la acción
 * de desactivar se llama «Dar de baja» / «Reactivar», como en el resto de la
 * plataforma. Solo cambian los textos; la lógica es la misma.
 */
class VocabularioDarDeBajaTest extends TestCase
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

    public function test_empresas_dice_dar_de_baja_y_reactivar(): void
    {
        $sa = $this->crearSuperadmin();
        $otra = $this->crearEmpresa('Hotel Dos');

        $this->actingAs($sa)->get('/empresas')->assertOk()
            ->assertSee('title="Dar de baja"', false)->assertSee('aria-label="Dar de baja Hotel Dos"', false)
            ->assertSee('¿Dar de baja la empresa «Hotel Dos»?')
            ->assertSee('>De baja</button>', false)
            ->assertDontSee('Desactivar')->assertDontSee('Inactivas');

        $this->actingAs($sa)->patch("/empresas/{$otra->id}/estado", ['activo' => '0'])
            ->assertSessionHas('aviso', 'Empresa dada de baja: sus usuarios ya no pueden entrar. Puedes reactivarla con el mismo botón.');
        $this->assertFalse($otra->fresh()->activo);

        $this->actingAs($sa)->get('/empresas')
            ->assertSee('DE BAJA')->assertDontSee('INACTIVA')
            ->assertSee('title="Reactivar"', false)->assertSee('¿Reactivar la empresa «Hotel Dos»?');

        $this->actingAs($sa)->patch("/empresas/{$otra->id}/estado", ['activo' => '1'])->assertSessionHas('ok', 'Empresa reactivada correctamente.');
        $this->assertDatabaseHas('auditoria', ['evento' => 'empresas.desactivada', 'auditable_id' => $otra->id]);
    }

    public function test_sedes_dice_dar_de_baja_y_reactivar(): void
    {
        $sede = $this->crearSede($this->empresa, 'PLA');
        $this->crearSede($this->empresa, 'CEN'); // los filtros salen con más de una sede

        $this->actingAs($this->admin)->get('/sedes')->assertOk()
            ->assertSee('title="Dar de baja"', false)->assertSee('aria-label="Dar de baja '.$sede->nombre.'"', false)
            ->assertSee('¿Dar de baja «'.$sede->nombre.'»?')
            ->assertSee('>De baja</button>', false)
            ->assertDontSee('Desactivar')->assertDontSee('Inactivos');

        $this->actingAs($this->admin)->patch("/sedes/{$sede->id}/estado", ['activo' => '0'])
            ->assertSessionHas('aviso', "«{$sede->nombre}» dada de baja: ya no aparecerá para nuevas capturas. Su historial se conserva. Puedes reactivarla con el mismo botón.");
        $this->assertFalse($sede->fresh()->activo);

        $this->actingAs($this->admin)->get('/sedes')->assertSee('DE BAJA')->assertDontSee('INACTIVO')->assertSee('title="Reactivar"', false);
    }

    public function test_usuarios_dice_dar_de_baja_y_reactivar(): void
    {
        $agente = $this->crearUsuario($this->empresa, 'Agente');

        $this->actingAs($this->admin)->get('/usuarios')->assertOk()
            ->assertSee('title="Dar de baja"', false)->assertSee('aria-label="Dar de baja a '.$agente->name.'"', false)
            ->assertSee('¿Dar de baja la cuenta de '.$agente->name.'?')
            ->assertSee('Cuenta activa (si la das de baja, no podrá iniciar sesión)')
            ->assertDontSee('Desactivar');

        $this->actingAs($this->admin)->patch("/usuarios/{$agente->id}/estado", ['activo' => '0'])
            ->assertSessionHas('aviso', 'Usuario dado de baja: ya no puede iniciar sesión y su sesión abierta se cerró. Puedes reactivarlo con el mismo botón.');
        $this->assertFalse($agente->fresh()->activo);

        $this->actingAs($this->admin)->get('/usuarios')->assertSee('DE BAJA')->assertDontSee('INACTIVO')
            ->assertSee('¿Reactivar la cuenta de '.$agente->name.'?');
    }

    public function test_roles_dice_de_baja(): void
    {
        Rol::where('empresa_id', $this->empresa->id)->where('nombre', 'Solicitante')->update(['activo' => false]);

        $this->actingAs($this->admin)->get('/roles')->assertOk()
            ->assertSee('>De baja</span>', false)
            ->assertSee('Rol activo (si lo das de baja, quienes lo tienen pierden sus permisos)')
            ->assertDontSee('>Inactivo</span>', false)->assertDontSee('si lo desactivas');
    }
}
