<?php

namespace Tests\Feature\Organizacion;

use App\Models\Area;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Menu;
use App\Models\Modulo;
use App\Models\Rol;
use App\Models\Sede;
use App\Models\User;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Áreas Dirección / Recursos Humanos / Seguridad y altas provisionales:
 * la caseta registra a quien aún no existe y Recursos Humanos lo valida o lo
 * une con su registro correcto.
 */
class ColaboradoresProvisionalesTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private Sede $playa;

    private User $agente;

    private User $rh;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->playa = $this->crearSede($this->empresa, 'PLA');
        $this->agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->rh = $this->crearUsuario($this->empresa, 'Recursos Humanos');
    }

    private function enEmpresa(callable $fn): mixed
    {
        return app(Tenant::class)->conEmpresa($this->empresa->id, $fn);
    }

    private function nuevo(array $datos): Colaborador
    {
        return $this->enEmpresa(fn () => Colaborador::create($datos + ['sede_id' => $this->centro->id]));
    }

    private function provisional(array $extra = []): array
    {
        return array_merge(['nombre' => 'Jorge', 'apellido_paterno' => 'Méndez', 'apellido_materno' => 'Tun', 'sede_id' => $this->centro->id, 'telefono' => '9981234567'], $extra);
    }

    // ------------------------------------------------------------------ Áreas

    public function test_tres_areas_y_recursos_humanos_con_su_menu_y_rol_base(): void
    {
        $this->assertSame(['direccion', 'recursos_humanos', 'seguridad'], Area::orderBy('orden')->pluck('clave')->all());

        $area = fn (string $modulo) => Modulo::with('area')->where('clave', $modulo)->firstOrFail()->area->clave;
        foreach (['empresas', 'sedes', 'espacios', 'departamentos', 'puestos', 'turnos', 'usuarios', 'roles', 'permisos'] as $m) {
            $this->assertSame('direccion', $area($m), $m);
        }
        $this->assertSame('recursos_humanos', $area('colaboradores'));
        foreach (['accesos', 'llaves', 'novedades', 'dashboard', 'informe_ejecutivo'] as $m) {
            $this->assertSame('seguridad', $area($m), $m);
        }

        $this->assertSame('recursos_humanos', Modulo::with('menu')->where('clave', 'colaboradores')->firstOrFail()->menu->clave);
        $this->assertSame(['estructura', 'recursos_humanos', 'padrones', 'operacion'], Menu::orderBy('orden')->pluck('clave')->all());

        // Rol base de Recursos Humanos (nivel 25): todo en su área y consulta de catálogos
        $rol = Rol::where('empresa_id', $this->empresa->id)->where('nombre', 'Recursos Humanos')->firstOrFail();
        $this->assertSame(25, $rol->nivel_jerarquia);
        foreach (['colaboradores.crear', 'colaboradores.aprobar', 'colaboradores.datos_personales', 'departamentos.ver', 'puestos.ver'] as $p) {
            $this->assertTrue($this->rh->can($p), $p);
        }
        $this->assertFalse($this->rh->can('departamentos.crear'));
        $this->assertFalse($this->rh->can('accesos.ver'));

        // La caseta consulta su sede y da altas provisionales, no altas completas
        $this->assertTrue($this->agente->can('colaboradores.provisional'));
        $this->assertFalse($this->agente->can('colaboradores.crear'));
        $this->assertFalse($this->agente->can('dashboard.ver'));
    }

    // ------------------------------------------------------- Alta provisional

    public function test_la_caseta_registra_un_provisional_sin_numero_y_en_su_sede(): void
    {
        $this->actingAs($this->agente)->postJson('/colaboradores/rapido', $this->provisional())
            ->assertCreated()->assertJsonPath('colaborador.provisional', true)->assertJsonPath('colaborador.num_empleado', null);

        $jorge = $this->enEmpresa(fn () => Colaborador::where('nombre', 'Jorge')->firstOrFail());
        $this->assertTrue($jorge->provisional);
        $this->assertDatabaseHas('auditoria', ['evento' => 'colaboradores.provisional', 'auditable_id' => $jorge->id]);

        // Solo en su sede
        $this->actingAs($this->agente)->postJson('/colaboradores/rapido', $this->provisional(['nombre' => 'Otro', 'sede_id' => $this->playa->id]))
            ->assertStatus(422);

        // Ya se puede buscar y usar, marcado como provisional
        $this->actingAs($this->agente)->getJson('/colaboradores/buscar?q=jorge')->assertOk()->assertJsonPath('resultados.0.provisional', true);
        $this->actingAs($this->agente)->get('/colaboradores')->assertSee('POR VALIDAR')->assertDontSee('Validar Colaborador');
    }

    public function test_si_ya_existe_alguien_con_ese_nombre_lo_propone_antes_de_duplicar(): void
    {
        $this->nuevo(['num_empleado' => '1005', 'nombre' => 'Jorge', 'apellido_paterno' => 'Mendez', 'apellido_materno' => 'Tun']);

        $this->actingAs($this->agente)->postJson('/colaboradores/rapido', $this->provisional())
            ->assertStatus(409)->assertJsonPath('parecidos.0.num_empleado', '1005');
        $this->assertSame(1, $this->enEmpresa(fn () => Colaborador::count()));

        // El guardia confirma que es otra persona
        $this->actingAs($this->agente)->postJson('/colaboradores/rapido', $this->provisional(['confirmar_nuevo' => '1']))->assertCreated();
        $this->assertSame(2, $this->enEmpresa(fn () => Colaborador::count()));
    }

    // ------------------------------------------------- Validación en RH

    public function test_recursos_humanos_valida_y_le_asigna_numero(): void
    {
        $this->actingAs($this->agente)->postJson('/colaboradores/rapido', $this->provisional())->assertCreated();
        $jorge = $this->enEmpresa(fn () => Colaborador::where('nombre', 'Jorge')->firstOrFail());
        $this->nuevo(['num_empleado' => '2000', 'nombre' => 'Ya', 'apellido_paterno' => 'Existe']);

        $this->actingAs($this->rh)->get('/colaboradores')->assertSee('alta provisional de la caseta por validar', false)->assertSee('Validar Colaborador');

        $datos = ['nombre' => 'Jorge Luis', 'apellido_paterno' => 'Méndez', 'apellido_materno' => 'Tun', 'sede_id' => $this->centro->id, '_dialogo' => "validar-{$jorge->id}"];
        $this->actingAs($this->rh)->put("/colaboradores/{$jorge->id}/validar", $datos)->assertSessionHasErrors('num_empleado');
        $this->actingAs($this->rh)->put("/colaboradores/{$jorge->id}/validar", $datos + ['num_empleado' => '2000'])->assertSessionHasErrors('num_empleado');

        // La caseta no valida
        $this->actingAs($this->agente)->put("/colaboradores/{$jorge->id}/validar", $datos + ['num_empleado' => '2001'])->assertForbidden();

        $this->actingAs($this->rh)->put("/colaboradores/{$jorge->id}/validar", $datos + ['num_empleado' => '2001'])
            ->assertSessionHas('ok', '«Jorge Luis Méndez Tun» validado con el número 2001.');
        $jorge->refresh();
        $this->assertFalse($jorge->provisional);
        $this->assertSame($this->rh->id, $jorge->validado_por);
        $this->assertDatabaseHas('auditoria', ['evento' => 'colaboradores.validado', 'auditable_id' => $jorge->id]);

        // Ya no está pendiente
        $this->actingAs($this->rh)->put("/colaboradores/{$jorge->id}/validar", $datos + ['num_empleado' => '2002'])->assertSessionHasErrors('num_empleado');
    }

    public function test_un_duplicado_se_une_con_su_registro_y_conserva_lo_capturado(): void
    {
        $roberto = $this->nuevo(['num_empleado' => '1005', 'nombre' => 'Roberto', 'apellido_paterno' => 'Hernández']);
        $this->actingAs($this->agente)->postJson('/colaboradores/rapido', $this->provisional(['nombre' => 'Beto', 'apellido_paterno' => 'Hernandes']))->assertCreated();
        $beto = $this->enEmpresa(fn () => Colaborador::where('nombre', 'Beto')->firstOrFail());

        // Algo ya registrado con el provisional (aquí: una cuenta de usuario)
        $cuenta = $this->crearUsuario($this->empresa);
        $cuenta->forceFill(['colaborador_id' => $beto->id])->save();

        // No se puede unir con otro provisional ni con uno de baja
        $this->actingAs($this->agente)->postJson('/colaboradores/rapido', $this->provisional(['nombre' => 'Otro', 'apellido_paterno' => 'Provisional']))->assertCreated();
        $otro = $this->enEmpresa(fn () => Colaborador::where('nombre', 'Otro')->firstOrFail());
        $this->actingAs($this->rh)->put("/colaboradores/{$beto->id}/fusionar", ['destino_id' => $otro->id])->assertSessionHasErrors('destino_id');

        $this->actingAs($this->rh)->put("/colaboradores/{$beto->id}/fusionar", ['destino_id' => $roberto->id])
            ->assertSessionHas('ok', '«Beto Hernandes Tun» se unió con «Roberto Hernández» (#1005).');

        $beto->refresh();
        $this->assertFalse($beto->activo);
        $this->assertSame($roberto->id, $beto->fusionado_en_id);
        $this->assertSame($roberto->id, $cuenta->fresh()->colaborador_id);
        $this->assertDatabaseHas('auditoria', ['evento' => 'colaboradores.fusionado', 'auditable_id' => $beto->id]);

        $this->actingAs($this->rh)->get('/colaboradores')->assertSee('Era un duplicado: se unió con #1005');
        $this->actingAs($this->rh)->put("/colaboradores/{$beto->id}/fusionar", ['destino_id' => $roberto->id])->assertSessionHasErrors('destino_id');
    }

    public function test_no_se_valida_ni_se_une_fuera_de_la_empresa(): void
    {
        $this->actingAs($this->agente)->postJson('/colaboradores/rapido', $this->provisional())->assertCreated();
        $jorge = $this->enEmpresa(fn () => Colaborador::where('nombre', 'Jorge')->firstOrFail());
        $ajeno = $this->crearUsuario($this->crearEmpresa('Hotel Dos'), 'Recursos Humanos');

        $this->actingAs($ajeno)->put("/colaboradores/{$jorge->id}/validar", ['num_empleado' => '9', 'nombre' => 'X', 'apellido_paterno' => 'Y'])->assertNotFound();
        $this->actingAs($ajeno)->put("/colaboradores/{$jorge->id}/fusionar", ['destino_id' => $jorge->id])->assertNotFound();
    }
}
