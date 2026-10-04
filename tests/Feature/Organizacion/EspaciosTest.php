<?php

namespace Tests\Feature\Organizacion;

use App\Models\Empresa;
use App\Models\Espacio;
use App\Models\GrupoEspacio;
use App\Models\Sede;
use App\Models\TipoEspacio;
use App\Models\User;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

class EspaciosTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $sede;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->sede = $this->crearSede($this->empresa, 'CEN');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
    }

    private function espacio(string $nombre, ?int $padreId = null): Espacio
    {
        return app(Tenant::class)->conEmpresa($this->empresa->id, fn () => Espacio::where('nombre', $nombre)
            ->when($padreId, fn ($q) => $q->where('padre_id', $padreId))->firstOrFail());
    }

    private function tipo(string $nivel, string $nombre): int
    {
        return (int) TipoEspacio::whereNull('empresa_id')->where('nivel', $nivel)->where('nombre', $nombre)->value('id');
    }

    /**
     * Torre A > Piso 1 > Habitación 101.
     *
     * @return array{0: Espacio, 1: Espacio, 2: Espacio}
     */
    private function arbolBasico(): array
    {
        $this->actingAs($this->admin)->post('/espacios', ['nivel' => 'edificio', 'sede_id' => $this->sede->id, 'nombre' => 'Torre A', 'codigo' => 'ta-1']);
        $torre = $this->espacio('Torre A');
        $this->actingAs($this->admin)->post('/espacios', ['nivel' => 'area', 'padre_id' => $torre->id, 'nombre' => 'Piso 1']);
        $piso = $this->espacio('Piso 1');
        $this->actingAs($this->admin)->post('/espacios', ['nivel' => 'area_especifica', 'padre_id' => $piso->id, 'nombre' => '101']);

        return [$torre, $piso, $this->espacio('101')];
    }

    public function test_navega_las_cuatro_pantallas_con_la_terminologia_del_rubro(): void
    {
        [$torre, $piso, $hab] = $this->arbolBasico();

        $this->assertSame('TA-1', $torre->codigo);
        $this->assertSame("/{$torre->id}/{$piso->id}/{$hab->id}/", $hab->ruta);
        $this->assertSame(2, $hab->profundidad);

        $this->actingAs($this->admin)->get('/espacios')->assertOk()->assertSee('Torre A')->assertSee('1 Piso(s) registrado(s)', false);
        $this->actingAs($this->admin)->get("/espacios/{$torre->id}")->assertOk()->assertSee('Piso 1');
        $this->actingAs($this->admin)->get("/espacios/{$piso->id}")->assertOk()->assertSee('Habitaciones')->assertSee('Crear por Lote');
        $this->actingAs($this->admin)->get("/espacios/{$hab->id}")->assertOk()->assertSee('Áreas de esta Habitación')->assertSee('Agregar Área');
    }

    public function test_no_permite_niveles_fuera_de_lugar_ni_nombres_repetidos(): void
    {
        [$torre, $piso] = $this->arbolBasico();

        // Un piso no cuelga de otro piso; un área no cuelga de un piso
        $this->actingAs($this->admin)->post('/espacios', ['nivel' => 'area', 'padre_id' => $piso->id, 'nombre' => 'X'])->assertSessionHasErrors('nivel');
        $this->actingAs($this->admin)->post('/espacios', ['nivel' => 'subarea', 'padre_id' => $piso->id, 'nombre' => 'X'])->assertSessionHasErrors('nivel');
        // Mismo nombre en el mismo lugar (sin importar mayúsculas)
        $this->actingAs($this->admin)->post('/espacios', ['nivel' => 'area', 'padre_id' => $torre->id, 'nombre' => 'piso 1'])->assertSessionHasErrors('nombre');
        // Código con espacios
        $this->actingAs($this->admin)->post('/espacios', ['nivel' => 'edificio', 'sede_id' => $this->sede->id, 'nombre' => 'Torre B', 'codigo' => 'T B'])->assertSessionHasErrors('codigo');
        // Pero en otro edificio sí puede existir "Piso 1"
        $this->actingAs($this->admin)->post('/espacios', ['nivel' => 'edificio', 'sede_id' => $this->sede->id, 'nombre' => 'Torre B']);
        $this->actingAs($this->admin)->post('/espacios', ['nivel' => 'area', 'padre_id' => $this->espacio('Torre B')->id, 'nombre' => 'Piso 1'])->assertSessionHasNoErrors();
    }

    public function test_crea_habitaciones_por_rango_y_por_lista_sin_duplicar(): void
    {
        [, $piso] = $this->arbolBasico();

        $this->actingAs($this->admin)->post("/espacios/{$piso->id}/lote", [
            'modo' => 'rango', 'prefijo' => '10', 'rango_desde' => 1, 'rango_hasta' => 3, 'relleno_ceros' => '1',
        ])->assertRedirect("/espacios/{$piso->id}")->assertSessionHas('ok', 'Se crearon 3.');

        $this->actingAs($this->admin)->post("/espacios/{$piso->id}/lote", [
            'modo' => 'lista', 'lista_nombres' => "101\nSuite A, Suite B\n\nsuite a",
        ])->assertSessionHas('ok', 'Se crearon 2. Se omitieron 2 que ya existían.');

        $nombres = app(Tenant::class)->conEmpresa($this->empresa->id, fn () => Espacio::where('padre_id', $piso->id)->orderBy('id')->pluck('nombre')->all());
        $this->assertSame(['101', '1001', '1002', '1003', 'Suite A', 'Suite B'], $nombres);
    }

    public function test_detalle_con_areas_y_elementos_nombrados_por_tipo(): void
    {
        [, , $hab] = $this->arbolBasico();
        $bano = $this->tipo('subarea', 'Baño');
        $lavabo = $this->tipo('elemento', 'Lavabo');
        $this->assertGreaterThan(0, $bano);
        $this->assertGreaterThan(0, $lavabo);

        // Sin etiqueta se usa el nombre del tipo y se numera si se repite
        $this->actingAs($this->admin)->post('/espacios', ['nivel' => 'subarea', 'padre_id' => $hab->id, 'tipo_espacio_id' => $bano, 'nombre' => ''])->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post('/espacios', ['nivel' => 'subarea', 'padre_id' => $hab->id, 'tipo_espacio_id' => $bano])->assertSessionHasNoErrors();
        $area = $this->espacio('Baño', $hab->id);
        $this->espacio('Baño 2', $hab->id);

        $this->actingAs($this->admin)->post('/espacios', ['nivel' => 'elemento', 'padre_id' => $area->id, 'tipo_espacio_id' => $lavabo, 'nombre' => 'Lavabo doble'])
            ->assertRedirect("/espacios/{$hab->id}");
        $this->actingAs($this->admin)->get("/espacios/{$area->id}")->assertRedirect("/espacios/{$hab->id}");
        $this->actingAs($this->admin)->get("/espacios/{$hab->id}")->assertSee('Baño 2')->assertSee('Lavabo doble')->assertSee('Agregar Elemento');

        // Un tipo de área no sirve como elemento, y sin nombre ni tipo no se guarda
        $this->actingAs($this->admin)->post('/espacios', ['nivel' => 'elemento', 'padre_id' => $area->id, 'tipo_espacio_id' => $bano])->assertSessionHasErrors('tipo_espacio_id');
        $this->actingAs($this->admin)->post('/espacios', ['nivel' => 'elemento', 'padre_id' => $area->id])->assertSessionHasErrors('nombre');
    }

    public function test_desactivar_arrastra_a_lo_que_cuelga_y_reactivar_respeta_al_padre(): void
    {
        [$torre, $piso, $hab] = $this->arbolBasico();

        $this->actingAs($this->admin)->patch("/espacios/{$piso->id}/estado", ['activo' => '0'])
            ->assertSessionHas('aviso', fn ($m) => str_contains($m, 'junto con 1 espacio(s)'));
        $this->assertFalse($hab->fresh()->activo);
        $this->assertTrue($torre->fresh()->activo);

        // No se agregan espacios dentro de algo inactivo
        $this->actingAs($this->admin)->post('/espacios', ['nivel' => 'area_especifica', 'padre_id' => $piso->id, 'nombre' => '102'])->assertSessionHasErrors('padre_id');

        // La habitación no se reactiva mientras su piso siga inactivo
        $this->actingAs($this->admin)->patch("/espacios/{$hab->id}/estado", ['activo' => '1'])->assertSessionHasErrors('activo');
        $this->actingAs($this->admin)->patch("/espacios/{$piso->id}/estado", ['activo' => '1'])->assertSessionHas('ok');
        $this->assertTrue($hab->fresh()->activo);
        $this->assertDatabaseHas('auditoria', ['evento' => 'espacios.desactivado', 'auditable_id' => $piso->id]);
    }

    public function test_copia_pisos_entre_edificios_sin_repetir(): void
    {
        [$torre] = $this->arbolBasico();
        $this->actingAs($this->admin)->post('/espacios', ['nivel' => 'area', 'padre_id' => $torre->id, 'nombre' => 'Piso 2']);
        $this->actingAs($this->admin)->post('/espacios', ['nivel' => 'edificio', 'sede_id' => $this->sede->id, 'nombre' => 'Torre B']);
        $torreB = $this->espacio('Torre B');

        $this->actingAs($this->admin)->post("/espacios/{$torreB->id}/copiar-pisos", ['origen_id' => $torre->id])->assertSessionHas('ok', 'Se copiaron 2 piso(s) de «Torre A».');
        $this->actingAs($this->admin)->post("/espacios/{$torreB->id}/copiar-pisos", ['origen_id' => $torre->id])->assertSessionHas('aviso');
    }

    public function test_secciones_y_tipos_propios_por_empresa(): void
    {
        [, $piso, $hab] = $this->arbolBasico();

        $this->actingAs($this->admin)->post('/espacios/secciones', ['sede_id' => $this->sede->id, 'nombre' => 'Torre Norte'])->assertSessionHas('ok');
        $this->actingAs($this->admin)->post('/espacios/secciones', ['sede_id' => $this->sede->id, 'nombre' => 'torre norte'])->assertSessionHasErrors('nombre');
        $seccion = GrupoEspacio::withoutGlobalScopes()->where('nombre', 'Torre Norte')->firstOrFail();
        // Con secciones dadas de alta, la pestaña de zonas sigue abriendo (antes daba error 500)
        $this->actingAs($this->admin)->get('/espacios')->assertOk()->assertSee('Torre A');

        $this->actingAs($this->admin)->put("/espacios/{$hab->id}", ['nombre' => '101', 'grupo_espacio_id' => $seccion->id, 'desde_detalle' => '1'])
            ->assertRedirect("/espacios/{$hab->id}");
        $this->assertSame($seccion->id, $hab->fresh()->grupo_espacio_id);
        $this->actingAs($this->admin)->get('/espacios?pestana=secciones')->assertSee('Torre Norte')->assertSee('1 Habitación');

        // Asignar varias de golpe: las desmarcadas salen y las de otra sede se ignoran
        $this->actingAs($this->admin)->post("/espacios/{$piso->id}/lote", ['modo' => 'lista', 'lista_nombres' => '102,103']);
        $h102 = $this->espacio('102');
        $otraSede = $this->crearSede($this->empresa, 'PLA');
        $this->actingAs($this->admin)->post('/espacios', ['nivel' => 'edificio', 'sede_id' => $otraSede->id, 'nombre' => 'Villas']);
        $this->actingAs($this->admin)->post('/espacios', ['nivel' => 'area_especifica', 'padre_id' => $this->espacio('Villas')->id, 'nombre' => 'V1']);
        $this->actingAs($this->admin)->get('/espacios?pestana=secciones')->assertSee('Asignar Habitaciones')->assertSee('Torre A · Piso 1');
        $this->actingAs($this->admin)->put("/espacios/secciones/{$seccion->id}", ['espacios' => [$h102->id, $this->espacio('V1')->id]])
            ->assertSessionHas('ok', 'Sección «Torre Norte»: 1 asignada(s).');
        $this->assertNull($hab->fresh()->grupo_espacio_id);
        $this->assertSame($seccion->id, $h102->fresh()->grupo_espacio_id);
        $this->assertNull($this->espacio('V1')->grupo_espacio_id);

        // La sección no aplica a pisos
        $this->actingAs($this->admin)->put("/espacios/{$piso->id}", ['nombre' => 'Piso 1', 'grupo_espacio_id' => $seccion->id])->assertSessionHasErrors('grupo_espacio_id');

        // Tipo propio: se reutiliza si ya existe y no lo ve otra empresa
        $this->actingAs($this->admin)->post('/espacios/tipos', ['nivel' => 'elemento', 'nombre' => 'Jacuzzi'])->assertSessionHas('ok');
        $this->actingAs($this->admin)->post('/espacios/tipos', ['nivel' => 'elemento', 'nombre' => 'jacuzzi']);
        $this->assertSame(1, TipoEspacio::where('nombre', 'Jacuzzi')->count());
        $this->assertSame(0, TipoEspacio::disponiblesPara($this->crearEmpresa('Hotel Dos')->id, 'elemento')->where('nombre', 'Jacuzzi')->count());
    }

    public function test_aislamiento_entre_empresas_y_alcance_por_sede(): void
    {
        [$torre] = $this->arbolBasico();
        $otraEmpresa = $this->crearEmpresa('Hotel Dos');
        $this->crearSede($otraEmpresa, 'OTR');
        $ajeno = $this->crearUsuario($otraEmpresa, 'Administrador');

        $this->actingAs($ajeno)->get("/espacios/{$torre->id}")->assertNotFound();
        $this->actingAs($ajeno)->put("/espacios/{$torre->id}", ['nombre' => 'Hackeo'])->assertNotFound();
        $this->actingAs($ajeno)->post('/espacios', ['nivel' => 'area', 'padre_id' => $torre->id, 'nombre' => 'Intruso'])->assertNotFound();
        $this->actingAs($ajeno)->get('/espacios')->assertDontSee('Torre A');

        // Gerente con alcance de sede: solo ve su sede
        $playa = $this->crearSede($this->empresa, 'PLA');
        $this->actingAs($this->admin)->post('/espacios', ['nivel' => 'edificio', 'sede_id' => $playa->id, 'nombre' => 'Villas Playa']);
        $sa = $this->crearSuperadmin();
        $gerente = app(AdministradorRoles::class)->crearRol($sa, $this->empresa->id, ['nombre' => 'Gerente', 'nivel_jerarquia' => 40]);
        app(AdministradorRoles::class)->sincronizarPermisos($sa, $gerente, ['espacios.ver' => Alcance::Sede]);
        $usuario = $this->crearUsuario($this->empresa, 'Gerente', $this->sede);
        $this->flushSession(); // sin el aviso «Villas Playa agregado» del alta anterior

        $this->actingAs($usuario)->get('/espacios')->assertSee('Torre A')->assertDontSee('Villas Playa')->assertDontSee('Nueva Zona / Edificio');
        $this->actingAs($usuario)->get('/espacios/'.$this->espacio('Villas Playa')->id)->assertNotFound();
        $this->actingAs($usuario)->post('/espacios', ['nivel' => 'edificio', 'sede_id' => $this->sede->id, 'nombre' => 'X'])->assertForbidden();
    }

    public function test_el_superadmin_elige_empresa_y_un_agente_no_entra(): void
    {
        $this->actingAs($this->crearUsuario($this->empresa, 'Agente'))->get('/espacios')->assertForbidden();

        $sa = $this->crearSuperadmin();
        $this->actingAs($sa)->get('/espacios')->assertSee('Elige arriba la');
        $this->actingAs($sa)->withSession([EmpresaDeTrabajo::SESION => $this->empresa->id])->get('/espacios')->assertSee('Nueva Zona / Edificio');
    }
}
