<?php

namespace Tests\Feature\Direccion;

use App\Models\Auditoria;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\DepartamentoResponsable;
use App\Models\Empresa;
use App\Models\Espacio;
use App\Models\Rubro;
use App\Models\User;
use App\Models\Vehiculo;
use App\Services\Arranque\AsistenteArranque;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * «Configura tu empresa» (docs/tecnico/configura-tu-empresa.md): la tarjeta
 * de Inicio para el Administrador de una empresa nueva, con pasos que se
 * marcan solos con los datos reales, «Ya terminé» y volver a mostrarla.
 */
class ConfiguraTuEmpresaTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
    }

    private function enEmpresa(Empresa $empresa, callable $fn): mixed
    {
        return app(Tenant::class)->conEmpresa($empresa->id, $fn);
    }

    public function test_empresa_nueva_ve_la_tarjeta_y_cada_paso_se_marca_solo(): void
    {
        $sa = $this->crearSuperadmin();
        $this->actingAs($sa)->post('/empresas', ['rubro_id' => Rubro::where('clave', 'hotel')->value('id'), 'nombre_comercial' => 'Hotel Nuevo', 'razon_social' => 'Hotel Nuevo SA de CV', 'rfc' => 'HNU010101AB1', 'zona_horaria' => 'America/Cancun', 'idioma' => 'es', 'moneda' => 'MXN'])
            ->assertSessionHasNoErrors();
        $empresa = Empresa::where('nombre_comercial', 'Hotel Nuevo')->firstOrFail();
        $this->assertSame(AsistenteArranque::VISIBLE, $empresa->preferencias['configura_tu_empresa']);
        $admin = $this->crearUsuario($empresa, 'Administrador');

        $this->actingAs($admin)->get('/')->assertOk()->assertSee('Configura tu empresa')->assertSee('0 de 7 pasos')
            ->assertSee('Identidad y logotipo')->assertSee('Importar colaboradores')->assertSee(route('importaciones.create', 'colaboradores'), false)
            ->assertSee('Ya terminé');

        // Los pasos se marcan con los datos reales, no con casillas
        $sede = $this->crearSede($empresa, 'S1');
        $this->actingAs($admin)->get('/')->assertSee('1 de 7 pasos');
        $this->crearUsuario($empresa, 'Agente', $sede);
        $this->enEmpresa($empresa, function () use ($admin, $sede) {
            $depto = Departamento::create(['nombre' => 'Recepción', 'todas_las_sedes' => true]);
            DepartamentoResponsable::create(['departamento_id' => $depto->id, 'user_id' => $admin->id]);
            Colaborador::create(['num_empleado' => 'E-1', 'nombre' => 'Ana', 'apellido_paterno' => 'Poot', 'sede_id' => $sede->id]);
            Espacio::create(['sede_id' => $sede->id, 'nivel' => Espacio::EDIFICIO, 'nombre' => 'Torre A']);
            Vehiculo::create(['placas' => 'ABC1', 'tipo' => 'sedan', 'marca' => 'X', 'color' => 'Y']);
        });
        $empresa->forceFill(['logo_ruta' => 'logos/x.png'])->save();
        $avance = app(AsistenteArranque::class)->avance($admin, $empresa->fresh());
        $this->assertSame(7, $avance['hechos']);
        $this->actingAs($admin)->get('/')->assertSee('7 de 7 pasos')->assertSee('¡Tu empresa está lista!');
    }

    public function test_ya_termine_la_oculta_y_desde_configuracion_se_vuelve_a_mostrar(): void
    {
        $empresa = $this->crearEmpresa('Hotel Vacío');
        $admin = $this->crearUsuario($empresa, 'Administrador');
        // Sin preferencia guardada y casi vacía: se ve
        $this->actingAs($admin)->get('/')->assertSee('Configura tu empresa');

        $this->actingAs($admin)->from('/')->put('/configura-tu-empresa', ['visible' => 0])->assertRedirect('/')
            ->assertSessionHas('ok', fn ($m) => str_contains($m, 'Configuración'));
        // Inicio muestra el aviso verde una vez; después ya no aparece nada de «Configura tu empresa»
        $this->actingAs($admin)->get('/')->assertSee('Ocultamos «Configura tu empresa» de Inicio.');
        $this->actingAs($admin)->get('/')->assertDontSee('Configura tu empresa');
        $this->assertSame(1, Auditoria::where('evento', 'configuracion.configura_tu_empresa')->count());

        $this->actingAs($admin)->get('/configuracion')->assertOk()->assertSee('Configura tu empresa')->assertSee('Mostrar en Inicio')->assertSee('no se ve');
        $this->actingAs($admin)->put('/configura-tu-empresa', ['visible' => 1])->assertRedirect(route('panel'));
        $this->actingAs($admin)->get('/')->assertSee('Configura tu empresa');
        $this->actingAs($admin)->get('/configuracion')->assertSee('Ocultar de Inicio');
    }

    public function test_quien_no_administra_la_configuracion_no_la_ve_ni_la_cambia(): void
    {
        $empresa = $this->crearEmpresa('Hotel Vacío');
        $sede = $this->crearSede($empresa, 'S1');
        $agente = $this->crearUsuario($empresa, 'Agente', $sede);
        $director = $this->crearUsuario($empresa, 'Director');

        foreach ([$agente, $director] as $usuario) {
            $this->actingAs($usuario)->get('/')->assertOk()->assertDontSee('Configura tu empresa');
            $this->actingAs($usuario)->put('/configura-tu-empresa', ['visible' => 0])->assertForbidden();
        }
        $this->assertArrayNotHasKey('configura_tu_empresa', $empresa->fresh()->preferencias ?? []);
    }

    public function test_la_empresa_de_ejemplo_con_datos_no_la_ve_salvo_que_la_abra(): void
    {
        $this->artisan('plataforma:demo', ['--password' => 'Demo1234!'])->assertSuccessful();
        $admin = User::where('username', 'admin.demo')->firstOrFail();

        $this->actingAs($admin)->get('/')->assertOk()->assertDontSee('Configura tu empresa');
        $this->actingAs($admin)->get('/configuracion')->assertSee('Mostrar en Inicio');
        $this->actingAs($admin)->put('/configura-tu-empresa', ['visible' => 1]);
        $this->actingAs($admin)->get('/')->assertSee('Configura tu empresa');
    }
}
