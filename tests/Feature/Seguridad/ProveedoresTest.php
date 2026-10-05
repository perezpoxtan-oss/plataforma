<?php

namespace Tests\Feature\Seguridad;

use App\Models\Empresa;
use App\Models\Persona;
use App\Models\Proveedor;
use App\Models\Sede;
use App\Models\User;
use App\Models\Vehiculo;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

class ProveedoresTest extends TestCase
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

    private function proveedor(string $nombre): Proveedor
    {
        return $this->enEmpresa(fn () => Proveedor::where('nombre', $nombre)->firstOrFail());
    }

    /** @return list<int> */
    private function sedesDe(Proveedor $proveedor): array
    {
        return $this->enEmpresa(fn () => $proveedor->sedes()->orderBy('sedes.id')->pluck('sedes.id')->map(fn ($id) => (int) $id)->all());
    }

    /**
     * @param  list<int>|null  $sedes  null = todas las sedes
     */
    private function alta(string $nombre, ?array $sedes = null, array $extra = []): Proveedor
    {
        $this->actingAs($this->admin)->post('/proveedores', ['nombre' => $nombre, 'categoria' => 'proveedor']
            + ($sedes === null ? ['todas_las_sedes' => '1'] : ['todas_las_sedes' => '0', 'sedes' => $sedes]) + $extra)
            ->assertSessionHasNoErrors();

        return $this->proveedor($nombre);
    }

    private function usuarioDeSede(Sede $sede, array $permisos): User
    {
        $sa = $this->crearSuperadmin();
        $rol = app(AdministradorRoles::class)->crearRol($sa, $this->empresa->id, ['nombre' => 'Gerente de sede', 'nivel_jerarquia' => 40]);
        app(AdministradorRoles::class)->sincronizarPermisos($sa, $rol, array_fill_keys($permisos, Alcance::Sede));
        $usuario = $this->crearUsuario($this->empresa, 'Gerente de sede', $sede);
        $this->flushSession();

        return $usuario;
    }

    public function test_alta_en_todas_las_sedes_normaliza_rfc_y_telefono_y_audita(): void
    {
        $this->actingAs($this->admin)->post('/proveedores', [
            'nombre' => '  Abarrotes   del Caribe ', 'categoria' => 'proveedor', 'rfc' => 'aca-150312 kj8',
            'telefono' => '998 884-1020', 'direccion' => 'Av. Quintana Roo 45', 'todas_las_sedes' => '1',
        ])->assertRedirect('/proveedores')->assertSessionHas('ok', 'Empresa externa «Abarrotes del Caribe» registrada correctamente.');

        $p = $this->proveedor('Abarrotes del Caribe');
        $this->assertSame('ACA150312KJ8', $p->rfc);
        $this->assertSame('9988841020', $p->telefono);
        $this->assertTrue($p->todas_las_sedes);
        $this->assertSame($this->empresa->id, $p->empresa_id);
        $this->assertSame($this->admin->id, $p->creado_por);
        $this->assertDatabaseHas('auditoria', ['evento' => 'proveedores.creado', 'auditable_id' => $p->id]);

        $this->actingAs($this->admin)->get('/proveedores')->assertOk()
            ->assertSee('Empresas Externas')->assertSee('Registrar Empresa Externa')->assertSee('Abarrotes del Caribe')
            ->assertSee('Proveedor (Insumos)')->assertSee('Todas las sedes')->assertSee('0 personas · 0 vehículos')
            ->assertSee('ACTIVA')->assertSee('data-tipo="proveedor"', false)
            ->assertSee('data-sede="'.$this->centro->id.' '.$this->playa->id.'"', false);
    }

    public function test_validacion_de_rfc_telefono_categoria_y_sedes(): void
    {
        $base = ['nombre' => 'Prueba', 'categoria' => 'proveedor', 'todas_las_sedes' => '1'];
        $this->actingAs($this->admin)->post('/proveedores', ['rfc' => 'XYZ123'] + $base)->assertSessionHasErrors('rfc');
        $this->actingAs($this->admin)->post('/proveedores', ['rfc' => 'ACA151312KJ8'] + $base)->assertSessionHasErrors('rfc'); // mes 13
        $this->actingAs($this->admin)->post('/proveedores', ['telefono' => '998-ABC-1234'] + $base)->assertSessionHasErrors('telefono');
        $this->actingAs($this->admin)->post('/proveedores', ['telefono' => '12345'] + $base)->assertSessionHasErrors('telefono');
        $this->actingAs($this->admin)->post('/proveedores', ['categoria' => 'OTRA'] + $base)->assertSessionHasErrors('categoria');
        $this->actingAs($this->admin)->post('/proveedores', ['nombre' => '   '] + $base)->assertSessionHasErrors('nombre');
        $this->actingAs($this->admin)->post('/proveedores', ['todas_las_sedes' => '0'] + $base)->assertSessionHasErrors('sedes');
        $this->enEmpresa(fn () => $this->assertSame(0, Proveedor::count()));

        // Persona física (13), teléfono internacional y solo una sede
        $this->actingAs($this->admin)->post('/proveedores', ['rfc' => 'PEPJ800101AB1', 'telefono' => '+1 (305) 555-0100', 'todas_las_sedes' => '0', 'sedes' => [$this->playa->id]] + $base)
            ->assertSessionHasNoErrors();
        $p = $this->proveedor('Prueba');
        $this->assertSame('+13055550100', $p->telefono);
        $this->assertFalse($p->todas_las_sedes);
        $this->assertSame([$this->playa->id], $this->sedesDe($p));
        $this->actingAs($this->admin)->get('/proveedores')->assertSee('1 de 2 sedes');
    }

    public function test_nombre_repetido_con_alcance_de_empresa_no_se_duplica(): void
    {
        $this->alta('Abarrotes del Caribe');
        $otro = $this->alta('Transportes Kin-Ha');

        $this->actingAs($this->admin)->post('/proveedores', ['nombre' => ' abarrotes   DEL caribe', 'categoria' => 'proveedor', 'todas_las_sedes' => '1'])
            ->assertSessionHasErrors(['nombre' => 'Ya existe «Abarrotes del Caribe» en esta empresa. Búscalo en la lista para editarlo.']);
        $this->actingAs($this->admin)->put("/proveedores/{$otro->id}", ['nombre' => 'ABARROTES DEL CARIBE', 'categoria' => 'transporte_personal'])
            ->assertSessionHasErrors('nombre');
        $this->enEmpresa(fn () => $this->assertSame(2, Proveedor::count()));

        // Editar sus datos (las sedes no se tocan desde aquí)
        $this->actingAs($this->admin)->put("/proveedores/{$otro->id}", ['nombre' => 'Transportes Kin Ha', 'categoria' => 'transporte_personal', 'telefono' => '998 887 2233'])
            ->assertRedirect('/proveedores')->assertSessionHas('ok');
        $otro->refresh();
        $this->assertSame('Transportes Kin Ha', $otro->nombre);
        $this->assertSame('transporte_personal', $otro->categoria);
        $this->assertTrue($otro->todas_las_sedes);
        $this->assertDatabaseHas('auditoria', ['evento' => 'proveedores.actualizado', 'auditable_id' => $otro->id]);
    }

    public function test_sedes_y_desactivar_reactivar_con_alcance_de_empresa(): void
    {
        $p = $this->alta('Constructora Maya');
        $ajena = $this->crearSede($this->crearEmpresa('Hotel Dos'), 'OTR');

        $this->actingAs($this->admin)->put("/proveedores/{$p->id}/sedes", ['todas_las_sedes' => '0', 'sedes' => [$this->playa->id, $ajena->id]])
            ->assertSessionHas('ok', 'Sedes de «Constructora Maya» actualizadas.');
        $this->assertFalse($p->fresh()->todas_las_sedes);
        $this->assertSame([$this->playa->id], $this->sedesDe($p));
        $this->assertDatabaseHas('auditoria', ['evento' => 'proveedores.sedes_actualizadas', 'auditable_id' => $p->id]);

        // Desde la ficha se regresa a la ficha
        $this->actingAs($this->admin)->put("/proveedores/{$p->id}/sedes", ['todas_las_sedes' => '1', '_volver' => 'ficha'])->assertRedirect("/proveedores/{$p->id}");
        $this->assertTrue($p->fresh()->todas_las_sedes);
        $this->assertSame([], $this->sedesDe($p));

        $this->actingAs($this->admin)->patch("/proveedores/{$p->id}/estado", ['activo' => '0'])->assertSessionHas('aviso');
        $this->assertFalse($p->fresh()->activo);
        $this->assertDatabaseHas('auditoria', ['evento' => 'proveedores.desactivado', 'auditable_id' => $p->id]);
        $this->actingAs($this->admin)->get('/proveedores')->assertSee('BAJA / VETADA')->assertSee('data-estado="0"', false);
        $this->actingAs($this->admin)->patch("/proveedores/{$p->id}/estado", ['activo' => '1'])->assertSessionHas('ok');
        $this->assertTrue($p->fresh()->activo);
        $this->assertDatabaseHas('auditoria', ['evento' => 'proveedores.reactivado', 'auditable_id' => $p->id]);
    }

    public function test_usuario_de_sede_registra_solo_en_su_sede_y_si_ya_existe_se_agrega_su_sede(): void
    {
        $maya = $this->alta('Constructora Maya', [$this->playa->id]);
        $gerente = $this->usuarioDeSede($this->centro, ['proveedores.ver', 'proveedores.crear', 'proveedores.editar', 'proveedores.eliminar']);

        // No la ve (opera solo en Playa); al registrarla no se duplica: se agrega su sede
        $this->actingAs($gerente)->get('/proveedores')->assertOk()->assertDontSee('Constructora Maya')->assertSee('se agrega a la tuya');
        $this->actingAs($gerente)->post('/proveedores', ['nombre' => 'CONSTRUCTORA  MAYA', 'categoria' => 'contratista', 'todas_las_sedes' => '1', 'sedes' => [$this->playa->id]])
            ->assertSessionHas('ok', 'Ya existía «Constructora Maya»: se agregó a tu sede.');
        $this->assertSame([$this->centro->id, $this->playa->id], $this->sedesDe($maya));
        $this->assertSame('proveedor', $maya->fresh()->categoria);
        $this->assertDatabaseHas('auditoria', ['evento' => 'proveedores.sede_agregada', 'auditable_id' => $maya->id, 'user_id' => $gerente->id]);
        $this->actingAs($gerente)->post('/proveedores', ['nombre' => 'Constructora Maya', 'categoria' => 'contratista'])
            ->assertSessionHas('aviso', 'Ya existía «Constructora Maya» y ya opera en tu sede: no se creó un duplicado.');
        $this->enEmpresa(fn () => $this->assertSame(1, Proveedor::count()));

        // Uno nuevo: solo en su sede, aunque pida "todas" o la de Playa
        $this->actingAs($gerente)->post('/proveedores', ['nombre' => 'Plomería Centro', 'categoria' => 'contratista', 'todas_las_sedes' => '1', 'sedes' => [$this->playa->id]])
            ->assertSessionHas('ok', 'Empresa externa «Plomería Centro» registrada correctamente.');
        $plomeria = $this->proveedor('Plomería Centro');
        $this->assertFalse($plomeria->todas_las_sedes);
        $this->assertSame([$this->centro->id], $this->sedesDe($plomeria));
    }

    public function test_usuario_de_sede_ve_lo_de_su_sede_y_solo_modifica_lo_exclusivo(): void
    {
        $global = $this->alta('Taxis Aeropuerto');
        $soloPlaya = $this->alta('Tours Playa', [$this->playa->id]);
        $soloCentro = $this->alta('Renta Centro', [$this->centro->id]);
        $gerente = $this->usuarioDeSede($this->centro, ['proveedores.ver', 'proveedores.crear', 'proveedores.editar', 'proveedores.eliminar']);

        $this->actingAs($gerente)->get('/proveedores')->assertOk()
            ->assertSee('Taxis Aeropuerto')->assertSee('Renta Centro')->assertDontSee('Tours Playa')
            ->assertSee('data-url="'.route('proveedores.update', $soloCentro->id).'"', false)->assertDontSee('data-url="'.route('proveedores.update', $global->id).'"', false)
            ->assertSee(route('proveedores.estado', $soloCentro->id), false)->assertDontSee(route('proveedores.estado', $global->id), false)
            ->assertSee('Solo puedes agregar o quitar a la empresa externa en tu sede');
        $this->actingAs($gerente)->get("/proveedores/{$soloPlaya->id}")->assertNotFound();
        $this->actingAs($gerente)->put("/proveedores/{$soloPlaya->id}/sedes", ['sedes' => [$this->centro->id]])->assertNotFound();

        // Compartido con otras sedes: datos y estado son de la empresa
        $this->actingAs($gerente)->put("/proveedores/{$global->id}", ['nombre' => 'Hackeo', 'categoria' => 'taxi'])->assertForbidden();
        $this->actingAs($gerente)->patch("/proveedores/{$global->id}/estado", ['activo' => '0'])->assertForbidden();
        $this->assertSame('Taxis Aeropuerto', $global->fresh()->nombre);

        // Exclusivo de su sede: lo edita y lo desactiva
        $this->actingAs($gerente)->put("/proveedores/{$soloCentro->id}", ['nombre' => 'Renta Centro SA', 'categoria' => 'agencia_autos'])->assertSessionHas('ok');
        $this->assertSame('Renta Centro SA', $soloCentro->fresh()->nombre);
        $this->actingAs($gerente)->patch("/proveedores/{$soloCentro->id}/estado", ['activo' => '0'])->assertSessionHas('aviso');
        $this->assertFalse($soloCentro->fresh()->activo);

        // Solo cambia su casilla: la de Playa no se toca
        $this->actingAs($gerente)->put("/proveedores/{$global->id}/sedes", ['todas_las_sedes' => '1', 'sedes' => []])
            ->assertRedirect('/proveedores')->assertSessionHas('ok');
        $this->assertFalse($global->fresh()->todas_las_sedes);
        $this->assertSame([$this->playa->id], $this->sedesDe($global));
        // Ya no opera en su sede: deja de verlo
        $this->actingAs($gerente)->get("/proveedores/{$global->id}")->assertNotFound();
    }

    public function test_ficha_con_pestanas_de_personal_y_flotilla(): void
    {
        $kinha = $this->alta('Transportes Kin-Ha', null, ['categoria' => 'transporte_personal', 'rfc' => 'TKH0905217T3', 'direccion' => 'Av. Kabah Mz 3']);
        $this->enEmpresa(function () use ($kinha) {
            Persona::create(['tipo' => 'proveedor', 'proveedor_id' => $kinha->id, 'nombre_completo' => 'JUAN PÉREZ CHAN', 'tipo_identificacion' => 'ine', 'folio_identificacion' => 'PECJ800101']);
            Persona::create(['tipo' => 'proveedor', 'proveedor_id' => $kinha->id, 'nombre_completo' => 'LUIS BAJA', 'activo' => false]);
            Vehiculo::create(['placas' => 'URV1234', 'proveedor_id' => $kinha->id, 'propiedad' => 'transporte_personal', 'marca' => 'NISSAN', 'modelo' => 'URVAN', 'numero_economico' => 'KH-07']);
            Persona::create(['nombre_completo' => 'VISITANTE SIN PROVEEDOR']);
        });

        $this->actingAs($this->admin)->get('/proveedores')->assertSee('2 personas · 1 vehículo');
        $this->actingAs($this->admin)->get("/proveedores/{$kinha->id}")->assertOk()
            ->assertSee('Ficha de Proveedor')->assertSee('Transportes Kin-Ha')->assertSee('Transporte de Personal')->assertSee('TKH0905217T3')
            ->assertSee('Sedes donde opera')->assertSee('Todas las sedes')->assertSee('Personal (2)')->assertSee('Flotilla (1)')
            ->assertSee('Editar Datos Generales')->assertDontSee('JUAN PÉREZ CHAN');
        $this->actingAs($this->admin)->get("/proveedores/{$kinha->id}?tab=personal")->assertOk()
            ->assertSee('JUAN PÉREZ CHAN')->assertSee('INE: PECJ800101')->assertSee('LUIS BAJA')->assertDontSee('VISITANTE SIN PROVEEDOR');
        $this->actingAs($this->admin)->get("/proveedores/{$kinha->id}?tab=flotilla")->assertOk()
            ->assertSee('URV1234')->assertSee('NISSAN URVAN')->assertSee('Eco: KH-07');
        $this->actingAs($this->admin)->get("/proveedores/{$kinha->id}?tab=otra")->assertOk()->assertSee('Sedes donde opera');

        // "Agregar persona / vehículo" solo cuando existen esos padrones (contrato con sus pantallas)
        $personal = $this->actingAs($this->admin)->get("/proveedores/{$kinha->id}?tab=personal");
        Route::has('personas.index')
            ? $personal->assertSee(route('personas.index', ['nuevo' => 1, 'proveedor' => $kinha->id]), false)->assertSee('#persona-', false)
            : $personal->assertDontSee('Agregar persona');
        $flotilla = $this->actingAs($this->admin)->get("/proveedores/{$kinha->id}?tab=flotilla");
        Route::has('vehiculos.index')
            ? $flotilla->assertSee(route('vehiculos.index', ['nuevo' => 1, 'proveedor' => $kinha->id]), false)
            : $flotilla->assertDontSee('Agregar vehículo');

        // Editar desde la ficha regresa a la ficha
        $this->actingAs($this->admin)->put("/proveedores/{$kinha->id}", ['nombre' => 'Transportes Kin-Ha', 'categoria' => 'transporte_personal', '_volver' => 'ficha'])
            ->assertRedirect("/proveedores/{$kinha->id}");
    }

    public function test_busqueda_y_alta_rapida_en_json(): void
    {
        $this->alta('Abarrotes del Caribe', null, ['rfc' => 'ACA150312KJ8']);
        $this->alta('Abarrotes Playa', [$this->playa->id]);
        $vetado = $this->alta('Abarrotes Vetados');
        $this->actingAs($this->admin)->patch("/proveedores/{$vetado->id}/estado", ['activo' => '0']);

        $this->actingAs($this->admin)->getJson('/proveedores/buscar?q=a')->assertOk()->assertExactJson(['resultados' => []]);
        $this->actingAs($this->admin)->getJson('/proveedores/buscar?q=abarrotes')->assertOk()
            ->assertJsonCount(2, 'resultados')->assertJsonMissing(['nombre' => 'Abarrotes Vetados'])
            ->assertJsonFragment(['nombre' => 'Abarrotes del Caribe', 'categoria' => 'proveedor', 'categoria_etiqueta' => 'Proveedor de insumos / alimentos']);
        $this->actingAs($this->admin)->getJson('/proveedores/buscar?q=aca1503')->assertJsonCount(1, 'resultados');

        $gerente = $this->usuarioDeSede($this->centro, ['proveedores.ver', 'proveedores.crear']);
        $this->actingAs($gerente)->getJson('/proveedores/buscar?q=abarrotes')->assertJsonCount(1, 'resultados')->assertJsonMissing(['nombre' => 'Abarrotes Playa']);

        // Alta rápida: 201 nueva, 200 si ya existía, 422 con errores o si está vetada
        $this->actingAs($this->admin)->postJson('/proveedores/rapido', ['nombre' => 'Viajes Turquesa', 'categoria' => 'agencia_viajes'])
            ->assertCreated()->assertJsonPath('ok', true)->assertJsonPath('ya_existia', false)->assertJsonPath('proveedor.nombre', 'Viajes Turquesa');
        $this->assertTrue($this->proveedor('Viajes Turquesa')->todas_las_sedes);
        $this->actingAs($this->admin)->postJson('/proveedores/rapido', ['nombre' => 'viajes turquesa', 'categoria' => 'taxi'])
            ->assertOk()->assertJsonPath('ya_existia', true)->assertJsonPath('proveedor.categoria', 'agencia_viajes');
        $this->actingAs($this->admin)->postJson('/proveedores/rapido', ['nombre' => 'Mal RFC', 'categoria' => 'taxi', 'rfc' => '123'])
            ->assertUnprocessable()->assertJsonPath('ok', false)->assertJsonStructure(['errores' => ['rfc']]);
        $this->actingAs($this->admin)->postJson('/proveedores/rapido', ['nombre' => 'Abarrotes Vetados', 'categoria' => 'proveedor'])
            ->assertUnprocessable()->assertJsonPath('ok', false);

        // Con alcance de sede: si ya existe en otra sede, se agrega la suya
        $this->actingAs($gerente)->postJson('/proveedores/rapido', ['nombre' => 'ABARROTES PLAYA', 'categoria' => 'proveedor'])
            ->assertOk()->assertJsonPath('ya_existia', true);
        $this->assertSame([$this->centro->id, $this->playa->id], $this->sedesDe($this->proveedor('Abarrotes Playa')));
    }

    public function test_aislamiento_entre_empresas(): void
    {
        $p = $this->alta('Constructora Maya');
        $ajeno = $this->crearUsuario($this->crearEmpresa('Hotel Dos'), 'Administrador');
        $this->flushSession();

        $this->actingAs($ajeno)->get('/proveedores')->assertOk()->assertDontSee('Constructora Maya');
        $this->actingAs($ajeno)->get("/proveedores/{$p->id}")->assertNotFound();
        $this->actingAs($ajeno)->put("/proveedores/{$p->id}", ['nombre' => 'Hackeo', 'categoria' => 'taxi'])->assertNotFound();
        $this->actingAs($ajeno)->put("/proveedores/{$p->id}/sedes", ['todas_las_sedes' => '0'])->assertNotFound();
        $this->actingAs($ajeno)->patch("/proveedores/{$p->id}/estado", ['activo' => '0'])->assertNotFound();
        $this->actingAs($ajeno)->getJson('/proveedores/buscar?q=constructora')->assertJsonCount(0, 'resultados');
        $this->assertSame('Constructora Maya', $p->fresh()->nombre);
        $this->assertTrue($p->fresh()->activo);

        // El mismo nombre sí puede existir en otra empresa
        $this->actingAs($ajeno)->post('/proveedores', ['nombre' => 'Constructora Maya', 'categoria' => 'contratista'])->assertSessionHasNoErrors();
    }

    public function test_agente_solo_consulta_el_padron(): void
    {
        $p = $this->alta('Taxis Aeropuerto');
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->flushSession();

        $this->actingAs($agente)->get('/proveedores')->assertOk()->assertSee('Taxis Aeropuerto')
            ->assertDontSee('Registrar Empresa Externa')->assertDontSee('data-accion="editar-registro"', false)->assertDontSee('dialogoSedesProveedor');
        $this->actingAs($agente)->get("/proveedores/{$p->id}")->assertOk()->assertDontSee('Editar Datos Generales');
        $this->actingAs($agente)->getJson('/proveedores/buscar?q=taxis')->assertJsonCount(1, 'resultados');
        $this->actingAs($agente)->post('/proveedores', ['nombre' => 'Otro', 'categoria' => 'taxi'])->assertForbidden();
        $this->actingAs($agente)->postJson('/proveedores/rapido', ['nombre' => 'Otro', 'categoria' => 'taxi'])->assertForbidden();
        $this->actingAs($agente)->put("/proveedores/{$p->id}", ['nombre' => 'X', 'categoria' => 'taxi'])->assertForbidden();
        $this->actingAs($agente)->patch("/proveedores/{$p->id}/estado", ['activo' => '0'])->assertForbidden();
    }

    public function test_sin_permiso_no_entra_y_el_superadmin_elige_empresa(): void
    {
        $rh = $this->crearUsuario($this->empresa, 'Recursos Humanos');
        $this->flushSession();
        $this->actingAs($rh)->get('/proveedores')->assertForbidden();

        $sa = $this->crearSuperadmin();
        $this->actingAs($sa)->get('/proveedores')->assertOk()->assertSee('Elige arriba la');
        $this->actingAs($sa)->get('/proveedores/1')->assertNotFound();
        $this->actingAs($sa)->withSession([EmpresaDeTrabajo::SESION => $this->empresa->id])->get('/proveedores')->assertSee('Registrar Empresa Externa');
        $this->actingAs($sa)->withSession([EmpresaDeTrabajo::SESION => $this->empresa->id])
            ->post('/proveedores', ['nombre' => 'Viajes Turquesa', 'categoria' => 'agencia_viajes'])->assertSessionHasNoErrors();
        $this->assertSame($this->empresa->id, $this->proveedor('Viajes Turquesa')->empresa_id);
    }
}
