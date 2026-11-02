<?php

namespace Tests\Feature\Seguridad;

use App\Models\Acceso;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Persona;
use App\Models\Proveedor;
use App\Models\Sede;
use App\Models\User;
use App\Models\Vehiculo;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Etapa 8 · C-44 (parte 2): al escanear un colaborador, un vehículo, una
 * persona o una empresa externa fuera de un formulario (/e/{código}) se
 * ofrece la acción probable de la caseta: «Dar salida» si está en sitio o
 * «Nuevo ingreso» con lo leído ya elegido. Vínculos: lector, Bitácora de
 * accesos (salida a la vista y Registro Inteligente de Ingreso), Restringir
 * acceso y permisos por sede.
 */
class EtapaOchoAccionesLectorTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private Sede $playa;

    private User $agente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->playa = $this->crearSede($this->empresa, 'PLA');
        $this->agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
    }

    private function enEmpresa(callable $fn): mixed
    {
        return app(Tenant::class)->conEmpresa($this->empresa->id, $fn);
    }

    private function colaborador(): Colaborador
    {
        return $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => '700', 'nombre' => 'Luis', 'apellido_paterno' => 'Mora', 'sede_id' => $this->centro->id]));
    }

    private function enSitio(array $datos, ?Sede $sede = null): Acceso
    {
        return $this->enEmpresa(fn () => Acceso::create($datos + ['sede_id' => ($sede ?? $this->centro)->id, 'estado' => 'en_sitio', 'movimiento' => 'entrada', 'entrada_at' => now()]));
    }

    public function test_colaborador_fuera_ofrece_nuevo_ingreso_y_lo_abre_ya_elegido(): void
    {
        $c = $this->colaborador();
        $this->actingAs($this->agente)->get(route('lector.ir', $c->codigo_qr))->assertOk()
            ->assertSee('Nuevo ingreso')->assertSee('No está en sitio.')->assertSee('Solo ver la ficha')
            ->assertSee(route('accesos.ingreso-desde-lector', ['colaborador', $c->id]), false);

        $this->actingAs($this->agente)->get(route('accesos.ingreso-desde-lector', ['colaborador', $c->id]))
            ->assertRedirect(route('accesos.index'))->assertSessionHasInput('colaborador_id', $c->id)
            ->assertSessionHasInput('tipo', 'colaborador')->assertSessionHasInput('_dialogo', 'ingreso')->assertSessionHasInput('sede_id', $this->centro->id);

        // La bitácora abre el registro con el colaborador elegido (como tras un aviso)
        $this->actingAs($this->agente)->followingRedirects()->get(route('accesos.ingreso-desde-lector', ['colaborador', $c->id]))->assertOk()
            ->assertSee('data-abrir-al-cargar', false)->assertSee('Luis Mora · Núm. 700', false);
        // Nada se guardó: el agente revisa y guarda
        $this->assertSame(0, $this->enEmpresa(fn () => Acceso::count()));
    }

    public function test_en_sitio_ofrece_dar_salida_en_la_bitacora(): void
    {
        $c = $this->colaborador();
        $this->enSitio(['tipo' => 'colaborador', 'colaborador_id' => $c->id, 'nombre' => 'LUIS MORA']);
        $this->actingAs($this->agente)->get(route('lector.ir', $c->codigo_qr))->assertOk()
            ->assertSee('Dar salida')->assertSee('Está en sitio desde el')
            ->assertSee(route('accesos.index', ['salida' => 'LUIS MORA']), false)->assertDontSee('Nuevo ingreso');

        // La salida a la vista llega con la búsqueda lista
        $this->actingAs($this->agente)->get(route('accesos.index', ['salida' => 'LUIS MORA']))->assertOk()
            ->assertSee('data-salida-inicial="LUIS MORA"', false);

        // El vehículo dentro se busca por sus placas
        $v = $this->enEmpresa(fn () => Vehiculo::create(['placas' => 'ABC123A', 'tipo' => 'sedan', 'marca' => 'X', 'modelo' => 'Y', 'color' => 'ROJO', 'propiedad' => 'visitante']));
        $this->enSitio(['tipo' => 'visitante', 'motivo_visita' => 'general', 'persona_visita' => 'X', 'nombre' => 'ANA', 'modo_arribo' => 'auto', 'vehiculo_id' => $v->id, 'placas' => 'ABC123A']);
        $this->actingAs($this->agente)->get(route('lector.ir', $v->codigo_qr))->assertOk()
            ->assertSee(route('accesos.index', ['salida' => 'ABC123A']), false);
    }

    public function test_en_otra_sede_no_le_da_salida_quien_no_la_atiende(): void
    {
        $c = $this->colaborador();
        $this->enSitio(['tipo' => 'colaborador', 'colaborador_id' => $c->id, 'nombre' => 'LUIS MORA'], $this->playa);
        // El agente de Centro no ve ese acceso: se le ofrece el ingreso en su sede
        $this->actingAs($this->agente)->get(route('lector.ir', $c->codigo_qr))->assertOk()->assertDontSee('Dar salida')->assertSee('Nuevo ingreso');
    }

    public function test_vehiculo_persona_y_empresa_externa_llegan_con_sus_datos(): void
    {
        $c = $this->colaborador();
        $propio = $this->enEmpresa(fn () => Vehiculo::create(['placas' => 'XYZ987B', 'tipo' => 'sedan', 'marca' => 'X', 'modelo' => 'Y', 'color' => 'AZUL', 'propiedad' => 'colaborador', 'colaborador_id' => $c->id]));
        $this->actingAs($this->agente)->get(route('accesos.ingreso-desde-lector', ['vehiculo', $propio->id]))
            ->assertSessionHasInput('tipo', 'colaborador')->assertSessionHasInput('colaborador_id', $c->id)
            ->assertSessionHasInput('modo_arribo', 'auto')->assertSessionHasInput('vehiculo_id', $propio->id)->assertSessionHasInput('placas', 'XYZ987B');

        $empresa = $this->enEmpresa(fn () => Proveedor::create(['nombre' => 'Fumigaciones Sur', 'categoria' => 'contratista', 'todas_las_sedes' => true]));
        $tecnico = $this->enEmpresa(fn () => Persona::create(['tipo' => 'contratista', 'nombre_completo' => 'Pedro Gil', 'proveedor_id' => $empresa->id]));
        $this->actingAs($this->agente)->get(route('accesos.ingreso-desde-lector', ['persona', $tecnico->id]))
            ->assertSessionHasInput('tipo', 'contratista')->assertSessionHasInput('persona_id', $tecnico->id)->assertSessionHasInput('nombre', 'Pedro Gil')
            ->assertSessionHasInput('proveedor_id', $empresa->id)->assertSessionHasInput('empresa_procedencia', 'Fumigaciones Sur');
        $this->actingAs($this->agente)->get(route('accesos.ingreso-desde-lector', ['proveedor', $empresa->id]))
            ->assertSessionHasInput('tipo', 'contratista')->assertSessionHasInput('proveedor_id', $empresa->id);
        $this->actingAs($this->agente)->get(route('lector.ir', $tecnico->codigo_qr))->assertOk()->assertSee('Persona externa')->assertSee('Nuevo ingreso');
    }

    public function test_restringido_avisa_sin_ofrecer_ingreso_y_sin_permiso_abre_la_ficha(): void
    {
        $p = $this->enEmpresa(fn () => Persona::create(['tipo' => 'visitante', 'nombre_completo' => 'Mario Vetado']));
        $this->enEmpresa(fn () => $p->forceFill(['restringido_en' => now(), 'restringido_motivo' => 'Agredió al guardia'])->save());
        $this->actingAs($this->agente)->get(route('lector.ir', $p->codigo_qr))->assertOk()
            ->assertSee('Acceso restringido')->assertSee('Agredió al guardia')->assertDontSee('Nuevo ingreso')->assertSee('Solo ver la ficha');

        // Dado de baja: no se ofrece ingreso y la ruta lo rechaza
        $c = $this->colaborador();
        $this->enEmpresa(fn () => $c->forceFill(['activo' => false])->save());
        $this->actingAs($this->agente)->get(route('lector.ir', $c->codigo_qr))->assertRedirect($c->urlLector());
        $this->actingAs($this->agente)->get(route('accesos.ingreso-desde-lector', ['colaborador', $c->id]))->assertNotFound();

        // Sin permiso de registrar ingresos: abre la ficha como siempre
        $director = $this->crearUsuario($this->empresa, 'Director');
        $activo = $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => '701', 'nombre' => 'Eva', 'apellido_paterno' => 'Paz', 'sede_id' => $this->centro->id]));
        $this->actingAs($director)->get(route('lector.ir', $activo->codigo_qr))->assertRedirect($activo->urlLector());
        $this->actingAs($director)->get(route('accesos.ingreso-desde-lector', ['colaborador', $activo->id]))->assertForbidden();
    }
}
