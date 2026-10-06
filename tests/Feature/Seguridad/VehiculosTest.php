<?php

namespace Tests\Feature\Seguridad;

use App\Models\Auditoria;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Proveedor;
use App\Models\Rol;
use App\Models\User;
use App\Models\UsuarioRol;
use App\Models\Vehiculo;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

class VehiculosTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->crearSede($this->empresa, 'CEN');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
    }

    private function enEmpresa(callable $fn, ?Empresa $empresa = null): mixed
    {
        return app(Tenant::class)->conEmpresa(($empresa ?? $this->empresa)->id, $fn);
    }

    /**
     * @return array<string, mixed>
     */
    private function datos(array $extra = []): array
    {
        return array_merge([
            'placas' => 'abc-123 a',
            'propiedad' => 'propio_huesped',
            'tipo' => 'sedan',
            'marca' => 'nissan',
            'modelo' => 'versa',
            'color' => 'blanco',
        ], $extra);
    }

    private function vehiculo(string $placas, array $extra = [], ?Empresa $empresa = null, ?User $autor = null): Vehiculo
    {
        return $this->enEmpresa(function () use ($placas, $extra, $autor) {
            $v = new Vehiculo(array_merge(['placas' => Vehiculo::normalizarPlacas($placas), 'tipo' => 'sedan', 'marca' => 'NISSAN', 'modelo' => 'VERSA', 'color' => 'BLANCO'], $extra));
            $v->forceFill(['creado_por' => $autor?->id])->save();

            return $v;
        }, $empresa);
    }

    private function proveedor(string $nombre, string $categoria = 'proveedor', ?Empresa $empresa = null, bool $activo = true): Proveedor
    {
        return $this->enEmpresa(fn () => Proveedor::create(['nombre' => $nombre, 'categoria' => $categoria, 'activo' => $activo]), $empresa);
    }

    private function colaborador(string $num, ?Empresa $empresa = null): Colaborador
    {
        return $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => $num, 'nombre' => 'Andrea', 'apellido_paterno' => 'Agente']), $empresa);
    }

    private function buscarPlacas(string $placas, ?Empresa $empresa = null): ?Vehiculo
    {
        return $this->enEmpresa(fn () => Vehiculo::where('placas', $placas)->first(), $empresa);
    }

    /**
     * @param  array<string, Alcance>  $permisos
     */
    private function usuarioCon(array $permisos): User
    {
        $sa = $this->crearSuperadmin();
        $roles = app(AdministradorRoles::class);
        $rol = $roles->crearRol($sa, $this->empresa->id, ['nombre' => 'Rol vehículos', 'nivel_jerarquia' => 40]);
        $roles->sincronizarPermisos($sa, $rol, $permisos);
        $usuario = User::factory()->create(['empresa_id' => $this->empresa->id]);
        UsuarioRol::create(['user_id' => $usuario->id, 'rol_id' => Rol::findOrFail($rol->id)->id, 'sede_id' => null]);

        return $usuario;
    }

    // ---------------------------------------------------------------- Lista

    public function test_lista_con_fichas_textos_de_segcat_y_filtros(): void
    {
        $taxi = $this->vehiculo('TX-1', ['propiedad' => 'taxi_app', 'numero_economico' => 'T-045']);
        $this->vehiculo('ABC123A');

        $this->actingAs($this->admin)->get('/vehiculos')->assertOk()
            ->assertSee('Padrón Vehicular')->assertSee('Catálogo de autos, flotillas y unidades registradas.')
            ->assertSee('Registrar Vehículo')->assertSee('Padrón de:')
            ->assertSee('id="vehiculo-'.$taxi->id.'"', false)
            ->assertSee('data-grupo="taxis"', false)->assertSee('data-grupo="propios"', false)
            ->assertSee('data-filtro-tipo="vehiculos"', false)
            ->assertSee('TX1')->assertSee('T-045')->assertSee('Taxi / App')
            ->assertSee(route('vehiculos.calcomania', $taxi->id));
    }

    // ---------------------------------------------------------------- Alta

    public function test_alta_normaliza_placas_y_textos_y_audita(): void
    {
        $respuesta = $this->actingAs($this->admin)->post('/vehiculos', $this->datos());

        $v = $this->buscarPlacas('ABC123A');
        $this->assertNotNull($v);
        $respuesta->assertRedirect(route('vehiculos.index').'#vehiculo-'.$v->id)
            ->assertSessionHas('ok', 'Vehículo ABC123A registrado correctamente. Ya puedes imprimir su calcomanía.');
        $this->assertSame(['NISSAN', 'VERSA', 'BLANCO'], [$v->marca, $v->modelo, $v->color]);
        $this->assertSame($this->empresa->id, $v->empresa_id);
        $this->assertSame($this->admin->id, $v->creado_por);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{24}$/', $v->codigo_qr);

        $auditoria = Auditoria::where('evento', 'vehiculos.creado')->where('auditable_id', $v->id)->firstOrFail();
        $this->assertSame('ABC123A', $auditoria->despues['placas']);
    }

    public function test_placas_unicas_por_empresa_sin_importar_guiones_espacios_ni_mayusculas(): void
    {
        $this->actingAs($this->admin)->post('/vehiculos', $this->datos())->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post('/vehiculos', $this->datos(['placas' => ' Abc 123-a ', 'marca' => 'kia']))
            ->assertSessionHasErrors(['placas' => 'Las placas «ABC123A» ya están registradas en esta empresa (NISSAN VERSA · BLANCO).']);

        // Una dada de baja también cuenta: se pide reactivarla
        $this->vehiculo('ZZZ999', ['activo' => false]);
        $this->actingAs($this->admin)->post('/vehiculos', $this->datos(['placas' => 'zzz-999']))
            ->assertSessionHasErrors(['placas' => 'Las placas «ZZZ999» ya están registradas en esta empresa (NISSAN VERSA · BLANCO), dado de baja: reactívalo en lugar de registrarlo otra vez.']);

        $this->actingAs($this->admin)->post('/vehiculos', $this->datos(['placas' => '#$%']))->assertSessionHasErrors('placas');

        // Las mismas placas en otra empresa: permitido (SEGCAT decía "en cualquier empresa")
        $otra = $this->crearEmpresa('Hotel Dos');
        $adminOtra = $this->crearUsuario($otra, 'Administrador');
        $this->actingAs($adminOtra)->post('/vehiculos', $this->datos())->assertSessionHasNoErrors();
        $this->assertSame(2, Vehiculo::withoutGlobalScopes()->where('placas', 'ABC123A')->count());
    }

    public function test_campos_que_dependen_de_la_categoria_y_del_tipo(): void
    {
        // Número económico y capacidad no aplican a un auto propio: se guardan vacíos aunque lleguen
        $this->actingAs($this->admin)->post('/vehiculos', $this->datos(['numero_economico' => 'T-1', 'capacidad' => 4, 'descripcion_otro' => 'x']))->assertSessionHasNoErrors();
        $v = $this->buscarPlacas('ABC123A');
        $this->assertNull($v->numero_economico);
        $this->assertNull($v->capacidad);
        $this->assertNull($v->descripcion_otro);

        // "Otro" exige describirlo
        $this->actingAs($this->admin)->post('/vehiculos', $this->datos(['placas' => 'OTR1', 'tipo' => 'otro']))
            ->assertSessionHasErrors(['descripcion_otro' => 'Describe el tipo de vehículo (por ejemplo: montacargas, carrito de golf).']);
        $this->actingAs($this->admin)->post('/vehiculos', $this->datos(['placas' => 'OTR1', 'tipo' => 'otro', 'descripcion_otro' => 'Carrito de golf']))->assertSessionHasNoErrors();
        $this->assertSame('Carrito de golf', $this->buscarPlacas('OTR1')->descripcion_otro);

        // Marca y color obligatorios; capacidad de 1 a 99
        $this->actingAs($this->admin)->post('/vehiculos', $this->datos(['placas' => 'X1', 'marca' => '', 'color' => '']))->assertSessionHasErrors(['marca', 'color']);
        $this->actingAs($this->admin)->post('/vehiculos', $this->datos(['placas' => 'BUS1', 'tipo' => 'autobus', 'capacidad' => 120]))
            ->assertSessionHasErrors(['capacidad' => 'La capacidad debe ser de 1 a 99 personas.']);

        // Flotilla de un proveedor: proveedor obligatorio; número económico y capacidad se guardan
        $this->actingAs($this->admin)->post('/vehiculos', $this->datos(['placas' => 'FLT1', 'propiedad' => 'empresa_proveedor']))
            ->assertSessionHasErrors(['proveedor_id' => 'Elige la empresa propietaria de la flotilla.']);
        $proveedor = $this->proveedor('Transportes del Caribe', 'transporte_personal');
        $this->actingAs($this->admin)->post('/vehiculos', $this->datos([
            'placas' => 'FLT1', 'propiedad' => 'empresa_proveedor', 'tipo' => 'autobus', 'capacidad' => 20, 'numero_economico' => 'u-03', 'proveedor_id' => $proveedor->id,
        ]))->assertSessionHasNoErrors();
        $flotilla = $this->buscarPlacas('FLT1');
        $this->assertSame([$proveedor->id, 'U-03', 20], [$flotilla->proveedor_id, $flotilla->numero_economico, $flotilla->capacidad]);

        // Taxi: el proveedor es opcional
        $this->actingAs($this->admin)->post('/vehiculos', $this->datos(['placas' => 'TX1', 'propiedad' => 'taxi_app']))->assertSessionHasNoErrors();

        // Un auto propio nunca queda ligado a un proveedor
        $this->actingAs($this->admin)->post('/vehiculos', $this->datos(['placas' => 'PRO2', 'proveedor_id' => $proveedor->id]))->assertSessionHasNoErrors();
        $this->assertNull($this->buscarPlacas('PRO2')->proveedor_id);
    }

    public function test_proveedor_y_colaborador_deben_ser_de_la_misma_empresa(): void
    {
        $otra = $this->crearEmpresa('Hotel Dos');
        $ajeno = $this->proveedor('Ajeno', 'taxi', $otra);
        $inactivo = $this->proveedor('Inactivo', 'taxi', null, false);
        $colabAjeno = $this->colaborador('9001', $otra);
        $colab = $this->colaborador('1003');

        $this->actingAs($this->admin)->post('/vehiculos', $this->datos(['propiedad' => 'empresa_proveedor', 'proveedor_id' => $ajeno->id]))
            ->assertSessionHasErrors(['proveedor_id' => 'La empresa propietaria no existe en esta empresa o está desactivada.']);
        $this->actingAs($this->admin)->post('/vehiculos', $this->datos(['propiedad' => 'empresa_proveedor', 'proveedor_id' => $inactivo->id]))
            ->assertSessionHasErrors('proveedor_id');
        $this->actingAs($this->admin)->post('/vehiculos', $this->datos(['propiedad' => 'propio_colaborador', 'colaborador_id' => $colabAjeno->id]))
            ->assertSessionHasErrors(['colaborador_id' => 'El colaborador no existe en esta empresa o está dado de baja.']);

        $this->actingAs($this->admin)->post('/vehiculos', $this->datos(['propiedad' => 'propio_colaborador', 'colaborador_id' => $colab->id]))->assertSessionHasNoErrors();
        $v = $this->buscarPlacas('ABC123A');
        $this->assertSame($colab->id, $v->colaborador_id);
        $this->actingAs($this->admin)->get('/vehiculos')->assertSee('Andrea Agente');

        // Si deja de ser de un colaborador, el vínculo se quita
        $this->actingAs($this->admin)->put("/vehiculos/{$v->id}", $this->datos(['propiedad' => 'propio_visitante', 'colaborador_id' => $colab->id]))->assertSessionHasNoErrors();
        $this->assertNull($v->fresh()->colaborador_id);
    }

    // ------------------------------------------------------- Edición y estado

    public function test_edicion_baja_y_reactivacion_con_auditoria(): void
    {
        $v = $this->vehiculo('ABC123A', [], null, $this->admin);

        $this->actingAs($this->admin)->put("/vehiculos/{$v->id}", $this->datos(['placas' => 'ABC123A', 'color' => 'rojo']))
            ->assertRedirect(route('vehiculos.index').'#vehiculo-'.$v->id)->assertSessionHas('ok', 'Vehículo ABC123A actualizado correctamente.');
        $this->assertSame('ROJO', $v->fresh()->color);
        $auditoria = Auditoria::where('evento', 'vehiculos.actualizado')->firstOrFail();
        $this->assertSame(['BLANCO', 'ROJO'], [$auditoria->antes['color'], $auditoria->despues['color']]);

        // Las mismas placas al editarse a sí mismo no chocan; con las de otro, sí
        $this->vehiculo('OTRO1');
        $this->actingAs($this->admin)->put("/vehiculos/{$v->id}", $this->datos(['placas' => 'otro-1']))->assertSessionHasErrors('placas');

        $this->actingAs($this->admin)->patch("/vehiculos/{$v->id}/estado", ['activo' => 0])
            ->assertSessionHas('aviso', 'Vehículo ABC123A dado de baja. Puedes reactivarlo con un clic cuando quieras.');
        $this->assertFalse($v->fresh()->activo);
        $this->actingAs($this->admin)->get('/vehiculos')->assertSee('BAJA');
        $this->actingAs($this->admin)->patch("/vehiculos/{$v->id}/estado", ['activo' => 1])->assertSessionHas('ok');
        $this->assertTrue($v->fresh()->activo);
        $this->assertSame(1, Auditoria::where('evento', 'vehiculos.desactivado')->count());
        $this->assertSame(1, Auditoria::where('evento', 'vehiculos.reactivado')->count());
    }

    // ------------------------------------------------------------ Calcomanía

    public function test_calcomania_con_qr_generado_localmente(): void
    {
        $proveedor = $this->proveedor('Taxis Cancún', 'taxi');
        $v = $this->vehiculo('TX-45', ['propiedad' => 'taxi_app', 'numero_economico' => 'T-045', 'proveedor_id' => $proveedor->id]);

        $respuesta = $this->actingAs($this->admin)->get("/vehiculos/{$v->id}/calcomania")->assertOk()
            ->assertSee('TX45')->assertSee('Hotel Uno')->assertSee('NISSAN VERSA')->assertSee('Taxis Cancún')
            ->assertSee('Imprimir Calcomanía')->assertSee('<svg', false)
            ->assertDontSee('qrserver')->assertDontSee('api.qrserver.com');
        $html = $respuesta->getContent();
        $this->assertStringNotContainsString('<?xml', $html);
        // Sin recursos externos: el QR va dibujado en la página
        $this->assertDoesNotMatchRegularExpression('/<img[^>]+src="https?:/i', $html);
        $this->assertStringNotContainsString('onclick', $html);
    }

    public function test_qr_lleva_a_la_ficha_y_no_sirve_en_otra_empresa(): void
    {
        $v = $this->vehiculo('ABC123A');

        $this->actingAs($this->admin)->get(route('vehiculos.qr', $v->codigo_qr))->assertRedirect(route('vehiculos.index').'#vehiculo-'.$v->id);
        $this->actingAs($this->admin)->get('/vehiculos/qr/noexiste12345678')->assertNotFound();

        $otra = $this->crearEmpresa('Hotel Dos');
        $adminOtra = $this->crearUsuario($otra, 'Administrador');
        $this->actingAs($adminOtra)->get(route('vehiculos.qr', $v->codigo_qr))->assertNotFound();
        $this->actingAs($adminOtra)->get("/vehiculos/{$v->id}/calcomania")->assertNotFound();
    }

    // ------------------------------------------- Contrato con la ficha de Proveedor

    public function test_alta_desde_la_ficha_del_proveedor(): void
    {
        $taxis = $this->proveedor('Taxis Cancún', 'taxi');
        $agencia = $this->proveedor('Renta Fácil', 'agencia_autos');
        $insumos = $this->proveedor('Abarrotes del Sureste', 'proveedor');
        $huespedes = $this->proveedor('Shuttle Hotel', 'transporte_huespedes');

        $html = fn (Proveedor $p) => $this->actingAs($this->admin)->get("/vehiculos?nuevo=1&proveedor={$p->id}")->assertOk()->getContent();

        $pagina = $html($taxis);
        $this->assertMatchesRegularExpression('/id="dialogoNuevoVehiculo"[^>]*data-abrir-al-cargar/', $pagina);
        $this->assertMatchesRegularExpression('/<option value="taxi_app"\s+selected/', $pagina);
        $this->assertMatchesRegularExpression('/<option value="'.$taxis->id.'"\s+selected/', $pagina);
        $this->assertStringContainsString('name="volver" value="proveedor"', $pagina);

        $this->assertMatchesRegularExpression('/<option value="agencia_renta"\s+selected/', $html($agencia));
        $this->assertMatchesRegularExpression('/<option value="empresa_proveedor"\s+selected/', $html($insumos));
        $this->assertMatchesRegularExpression('/<option value="transporte_personal"\s+selected/', $html($huespedes));

        // Sin el parámetro, el alta no se abre sola
        $this->assertDoesNotMatchRegularExpression('/id="dialogoNuevoVehiculo"[^>]*data-abrir-al-cargar/', $this->actingAs($this->admin)->get('/vehiculos')->getContent());

        // Al guardar regresa a la ficha del proveedor (si existe la pantalla) o al padrón
        $datos = $this->datos(['propiedad' => 'taxi_app', 'proveedor_id' => $taxis->id, 'volver' => 'proveedor']);
        if (! Route::has('proveedores.show')) {
            $this->actingAs($this->admin)->post('/vehiculos', $datos)->assertRedirect(route('vehiculos.index').'#vehiculo-'.$this->buscarPlacas('ABC123A')->id);
            Route::get('/proveedores/{proveedor}', fn () => 'ficha')->name('proveedores.show');
            app('router')->getRoutes()->refreshNameLookups();
            $datos['placas'] = 'XYZ987';
        }
        $this->actingAs($this->admin)->post('/vehiculos', $datos)->assertRedirect(route('proveedores.show', $taxis->id));

        // Al editar también; sin proveedor (auto propio) vuelve al padrón
        $v = $this->buscarPlacas('ABC123A');
        $this->actingAs($this->admin)->put("/vehiculos/{$v->id}", array_merge($datos, ['placas' => 'ABC123A']))->assertRedirect(route('proveedores.show', $taxis->id));
        $this->actingAs($this->admin)->post('/vehiculos', $this->datos(['placas' => 'PROPIO1', 'volver' => 'proveedor']))->assertRedirect(route('vehiculos.index').'#vehiculo-'.$this->buscarPlacas('PROPIO1')->id);
    }

    // -------------------------------------------------- JSON para otros módulos

    public function test_buscar_por_placas_marca_o_color(): void
    {
        $proveedor = $this->proveedor('Taxis Cancún', 'taxi');
        $this->vehiculo('ABC-123-A', ['propiedad' => 'taxi_app', 'proveedor_id' => $proveedor->id]);
        $this->vehiculo('ABD-999', ['marca' => 'MAZDA', 'modelo' => 'CX-5', 'color' => 'ROJO']);
        $this->vehiculo('ABE-111', ['activo' => false]);
        $otra = $this->crearEmpresa('Hotel Dos');
        $this->vehiculo('ABC-777', [], $otra);

        $r = $this->actingAs($this->admin)->getJson('/vehiculos/buscar?q=ab')->assertOk()->json('resultados');
        $this->assertSame(['ABC123A', 'ABD999'], array_column($r, 'placas'));

        $r = $this->actingAs($this->admin)->getJson('/vehiculos/buscar?q=abc-12')->assertOk()->json('resultados');
        $this->assertCount(1, $r);
        $this->assertSame('NISSAN VERSA · BLANCO', $r[0]['descripcion']);
        $this->assertSame('Taxi / App', $r[0]['propiedad_etiqueta']);
        $this->assertSame('Taxis Cancún', $r[0]['empresa']);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{24}$/', $r[0]['codigo_qr']);

        $this->assertSame(['ABD999'], array_column($this->actingAs($this->admin)->getJson('/vehiculos/buscar?q=mazda rojo')->json('resultados'), 'placas'));
        $this->assertSame([], $this->actingAs($this->admin)->getJson('/vehiculos/buscar?q=a')->json('resultados'));

        for ($i = 0; $i < 20; $i++) {
            $this->vehiculo('ZZ'.$i);
        }
        $this->assertCount(15, $this->actingAs($this->admin)->getJson('/vehiculos/buscar?q=zz')->json('resultados'));
    }

    public function test_registro_rapido_201_422_y_409_con_el_existente(): void
    {
        $this->actingAs($this->admin)->postJson('/vehiculos/rapido', $this->datos())->assertCreated()
            ->assertJsonPath('ok', true)->assertJsonPath('vehiculo.placas', 'ABC123A')->assertJsonPath('vehiculo.descripcion', 'NISSAN VERSA · BLANCO');

        $this->actingAs($this->admin)->postJson('/vehiculos/rapido', $this->datos(['placas' => 'abc 123-A', 'marca' => 'otra']))
            ->assertStatus(409)->assertJsonPath('ok', false)->assertJsonPath('vehiculo.placas', 'ABC123A')
            ->assertJsonPath('mensaje', 'Las placas ABC123A ya están en el padrón.');

        $this->actingAs($this->admin)->postJson('/vehiculos/rapido', $this->datos(['placas' => 'NUEVA1', 'marca' => '']))
            ->assertStatus(422)->assertJsonPath('ok', false)->assertJsonStructure(['errores' => ['marca']]);

        $this->assertSame(1, Auditoria::where('evento', 'vehiculos.creado')->count());
    }

    public function test_el_registro_rapido_se_puede_incluir_en_otra_pantalla(): void
    {
        $this->proveedor('Taxis Cancún', 'taxi');
        $this->actingAs($this->admin);

        $html = $this->enEmpresa(fn () => view('seguridad.vehiculos._registro-rapido')->render());
        $this->assertStringContainsString('id="dialogoRegistroRapidoVehiculo"', $html);
        $this->assertStringContainsString('data-registro-rapido-vehiculo', $html);
        $this->assertStringContainsString(route('vehiculos.rapido'), $html);
        $this->assertStringContainsString('Taxis Cancún', $html);

        $agente = $this->crearUsuario($this->empresa, 'Agente');
        $this->actingAs($agente);
        $this->assertStringNotContainsString('dialogoRegistroRapidoVehiculo', $this->enEmpresa(fn () => view('seguridad.vehiculos._registro-rapido')->render()));
    }

    // ------------------------------------------------- Permisos y aislamiento

    public function test_cada_empresa_ve_y_toca_solo_sus_vehiculos(): void
    {
        $propio = $this->vehiculo('MIO1');
        $otra = $this->crearEmpresa('Hotel Dos');
        $ajeno = $this->vehiculo('AJENO1', [], $otra);

        $this->actingAs($this->admin)->get('/vehiculos')->assertSee('MIO1')->assertDontSee('AJENO1');
        $this->actingAs($this->admin)->put("/vehiculos/{$ajeno->id}", $this->datos(['placas' => 'AJENO1']))->assertNotFound();
        $this->actingAs($this->admin)->patch("/vehiculos/{$ajeno->id}/estado", ['activo' => 0])->assertNotFound();
        $this->actingAs($this->admin)->get("/vehiculos/{$ajeno->id}/calcomania")->assertNotFound();
        $this->assertTrue(Vehiculo::withoutGlobalScopes()->findOrFail($ajeno->id)->activo);
        $this->assertSame([], $this->actingAs($this->admin)->getJson('/vehiculos/buscar?q=ajeno')->json('resultados'));
        $this->assertNotNull($propio->id);
    }

    public function test_el_agente_consulta_pero_no_registra_ni_imprime(): void
    {
        $v = $this->vehiculo('ABC123A');
        $agente = $this->crearUsuario($this->empresa, 'Agente');

        $this->actingAs($agente)->get('/vehiculos')->assertOk()->assertSee('ABC123A')
            ->assertDontSee('Registrar Vehículo')->assertDontSee('dialogoEditarVehiculo')->assertDontSee(route('vehiculos.calcomania', $v->id));
        $this->actingAs($agente)->post('/vehiculos', $this->datos(['placas' => 'NUEVO1']))->assertForbidden();
        $this->actingAs($agente)->put("/vehiculos/{$v->id}", $this->datos())->assertForbidden();
        $this->actingAs($agente)->patch("/vehiculos/{$v->id}/estado", ['activo' => 0])->assertForbidden();
        $this->actingAs($agente)->get("/vehiculos/{$v->id}/calcomania")->assertForbidden();
        // Altas por verificar (ADR-0006): desde la caseta sí registra, pero queda pendiente de verificar
        $this->actingAs($agente)->postJson('/vehiculos/rapido', $this->datos(['placas' => 'NUEVO1']))
            ->assertCreated()->assertJsonPath('vehiculo.verificacion', 'pendiente');
        $this->actingAs($agente)->getJson('/vehiculos/buscar?q=abc')->assertOk()->assertJsonCount(1, 'resultados');
        $this->actingAs($agente)->get(route('vehiculos.qr', $v->codigo_qr))->assertRedirect();

        $sinPermiso = $this->crearUsuario($this->empresa);
        $this->actingAs($sinPermiso)->get('/vehiculos')->assertForbidden();
        $this->actingAs($sinPermiso)->getJson('/vehiculos/buscar?q=abc')->assertForbidden();
    }

    public function test_alcance_propios_solo_modifica_lo_que_dio_de_alta(): void
    {
        $usuario = $this->usuarioCon([
            'vehiculos.ver' => Alcance::Propios,
            'vehiculos.crear' => Alcance::Propios,
            'vehiculos.editar' => Alcance::Propios,
            'vehiculos.eliminar' => Alcance::Propios,
        ]);
        $deOtro = $this->vehiculo('OTRO1', [], null, $this->admin);

        $this->actingAs($usuario)->post('/vehiculos', $this->datos(['placas' => 'MIO1']))->assertSessionHasNoErrors();
        $mio = $this->buscarPlacas('MIO1');
        $this->assertSame($usuario->id, $mio->creado_por);

        // Ve todo el padrón, pero solo edita y da de baja lo suyo
        $pagina = $this->actingAs($usuario)->get('/vehiculos')->assertOk()->assertSee('OTRO1')->getContent();
        $this->assertStringContainsString(route('vehiculos.update', $mio->id), $pagina);
        $this->assertStringNotContainsString(route('vehiculos.update', $deOtro->id), $pagina);

        $this->actingAs($usuario)->put("/vehiculos/{$mio->id}", $this->datos(['placas' => 'MIO1', 'color' => 'negro']))->assertSessionHasNoErrors();
        $this->actingAs($usuario)->put("/vehiculos/{$deOtro->id}", $this->datos(['placas' => 'OTRO1']))->assertNotFound();
        $this->actingAs($usuario)->patch("/vehiculos/{$deOtro->id}/estado", ['activo' => 0])->assertNotFound();
        $this->assertTrue($deOtro->fresh()->activo);
    }

    public function test_menu_enlaza_al_padron_vehicular(): void
    {
        $this->actingAs($this->admin)->get('/')->assertOk()->assertSee(route('vehiculos.index'));
    }
}
