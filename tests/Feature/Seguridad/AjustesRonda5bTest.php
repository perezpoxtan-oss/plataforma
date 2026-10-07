<?php

namespace Tests\Feature\Seguridad;

use App\Models\Empresa;
use App\Models\Espacio;
use App\Models\GrupoEspacio;
use App\Models\Persona;
use App\Models\Proveedor;
use App\Models\Sede;
use App\Models\TipoEspacio;
use App\Models\User;
use App\Models\Vehiculo;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Services\Vehiculos\AdministradorVehiculos;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Ronda 5 de ajustes de QA, parte 2: avisos de duplicado en vivo (Personas,
 * Zonas y áreas), empresas según el tipo de persona (PE-02), alta desde la
 * ficha de una empresa externa (PV-05/06), filtros de Vehículos (VE-03) y
 * "¿Es alguno de estos?" en vivo en Transporte.
 */
class AjustesRonda5bTest extends TestCase
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

    private function enEmpresa(callable $fn, ?Empresa $empresa = null): mixed
    {
        return app(Tenant::class)->conEmpresa(($empresa ?? $this->empresa)->id, $fn);
    }

    private function proveedor(string $nombre, string $categoria = 'proveedor', ?Empresa $empresa = null): Proveedor
    {
        return $this->enEmpresa(fn () => Proveedor::create(['nombre' => $nombre, 'categoria' => $categoria]), $empresa);
    }

    private function persona(string $nombre, array $extra = [], ?Empresa $empresa = null): Persona
    {
        return $this->enEmpresa(fn () => Persona::create(array_merge(['nombre_completo' => $nombre, 'tipo' => 'visitante'], $extra)), $empresa);
    }

    private function espacio(string $nivel, string $nombre, ?Espacio $padre = null, ?Sede $sede = null, array $extra = []): Espacio
    {
        return $this->enEmpresa(fn () => Espacio::create(array_merge(['sede_id' => ($sede ?? $this->centro)->id, 'nivel' => $nivel,
            'nombre' => $nombre, 'padre_id' => $padre?->id], $extra)));
    }

    /** @param array<string, mixed> $extra */
    private function datosPersona(array $extra = []): array
    {
        return array_merge(['tipo' => 'visitante', 'categoria' => 'general', 'nombre_completo' => 'Rosa Canul Pech', 'tipo_identificacion' => 'ine'], $extra);
    }

    // ------------------------------------------------- Personas: folio y nombre

    public function test_personas_avisa_en_vivo_el_folio_repetido_sin_espacios_ni_guiones(): void
    {
        $laura = $this->persona('Laura Méndez Ríos', ['tipo_identificacion' => 'ine', 'folio_identificacion' => 'MERL850314']);

        $this->actingAs($this->admin)->getJson('/personas/duplicado?campo=folio_identificacion&valor='.urlencode('mer-l 850 314'))
            ->assertOk()->assertJsonPath('estado', 'existe')
            ->assertJsonPath('coincidencias.0.titulo', 'Laura Méndez Ríos')
            ->assertJsonPath('coincidencias.0.reactivar', null);
        // El folio nunca viaja completo
        $this->assertStringNotContainsString('MERL850314', $this->actingAs($this->admin)->get('/personas/duplicado?campo=folio_identificacion&valor=MERL850314')->getContent());

        // La propia persona (edición) no cuenta; uno nuevo está libre; muy corto no se revisa
        $this->actingAs($this->admin)->getJson("/personas/duplicado?campo=folio_identificacion&valor=MERL850314&excluir={$laura->id}")->assertJsonPath('estado', 'libre');
        $this->actingAs($this->admin)->getJson('/personas/duplicado?campo=folio_identificacion&valor=ZZZZ9999')->assertJsonPath('estado', 'libre');
        $this->actingAs($this->admin)->getJson('/personas/duplicado?campo=folio_identificacion&valor=AB')->assertJsonPath('estado', 'nada');

        // Dada de baja: se ofrece reactivarla en lugar de duplicarla
        $laura->forceFill(['activo' => false])->save();
        $this->actingAs($this->admin)->getJson('/personas/duplicado?campo=folio_identificacion&valor=MERL850314')
            ->assertJsonPath('coincidencias.0.inactivo', true)
            ->assertJsonPath('coincidencias.0.reactivar', route('personas.estado', $laura->id));

        // Al guardar también se rechaza, con el error dentro del diálogo
        $this->actingAs($this->admin)->from('/personas')->post('/personas', $this->datosPersona(['folio_identificacion' => 'MERL 850-314', '_dialogo' => 'crear']))
            ->assertSessionHasErrors('folio_identificacion');
    }

    public function test_personas_avisa_nombres_parecidos_y_respeta_empresa_y_permisos(): void
    {
        $this->persona('Laura Méndez Ríos');
        $this->persona('Folio Ajeno', ['tipo_identificacion' => 'ine', 'folio_identificacion' => 'AJENO12345'], $this->crearEmpresa('Hotel Dos'));

        $this->actingAs($this->admin)->getJson('/personas/duplicado?campo=nombre_completo&valor='.urlencode('laura mendez rios'))
            ->assertJsonPath('estado', 'parecido')->assertJsonPath('coincidencias.0.titulo', 'Laura Méndez Ríos');
        $this->actingAs($this->admin)->getJson('/personas/duplicado?campo=nombre_completo&valor='.urlencode('Pedro Uc Chan'))->assertJsonPath('estado', 'nada');

        // Otra empresa: su folio no existe aquí
        $this->actingAs($this->admin)->getJson('/personas/duplicado?campo=folio_identificacion&valor=AJENO12345')->assertJsonPath('estado', 'libre');

        // El agente (Padrones solo consulta) no captura personas: no pregunta
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->actingAs($agente)->getJson('/personas/duplicado?campo=folio_identificacion&valor=MERL850314')->assertForbidden();
        $this->actingAs($agente)->get('/personas')->assertOk()->assertDontSee('data-duplicado=', false);

        // La pantalla conecta los dos campos al aviso en vivo
        $this->actingAs($this->admin)->get('/personas')->assertSee('data-duplicado="'.route('personas.duplicado').'" data-duplicado-min="4"', false);
    }

    // --------------------------------------------- Personas: empresa según tipo

    public function test_empresa_que_representa_segun_el_tipo_de_persona(): void
    {
        $maya = $this->proveedor('Constructora Maya', 'contratista');
        $bimbo = $this->proveedor('Bimbo', 'proveedor');
        $taxis = $this->proveedor('Taxis del Centro', 'taxi');

        // La lista sabe qué categorías van con cada tipo
        $this->actingAs($this->admin)->get('/personas')->assertOk()
            ->assertSee('data-categorias-por-tipo', false)->assertSee('data-categoria="contratista"', false);

        // Contratista solo con empresas contratistas; proveedor con el resto
        $this->actingAs($this->admin)->post('/personas', $this->datosPersona(['tipo' => 'contratista', 'proveedor_id' => $bimbo->id]))
            ->assertSessionHasErrors(['proveedor_id' => '«Bimbo» no está registrada como Contratista: elige el tipo Proveedor o una empresa contratista.']);
        $this->actingAs($this->admin)->post('/personas', $this->datosPersona(['tipo' => 'proveedor', 'proveedor_id' => $maya->id]))
            ->assertSessionHasErrors(['proveedor_id' => '«Constructora Maya» está registrada como Contratista: elige el tipo Contratista.']);
        $this->actingAs($this->admin)->post('/personas', $this->datosPersona(['tipo' => 'contratista', 'proveedor_id' => $maya->id]))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post('/personas', $this->datosPersona(['tipo' => 'proveedor', 'nombre_completo' => 'Juan Taxista', 'proveedor_id' => $taxis->id]))->assertSessionHasNoErrors();

        // Lo ya guardado de antes se respeta mientras no cambien tipo ni empresa
        $vieja = $this->persona('Registro Viejo', ['tipo' => 'proveedor', 'proveedor_id' => $maya->id]);
        $this->actingAs($this->admin)->put("/personas/{$vieja->id}", $this->datosPersona(['nombre_completo' => 'Registro Viejo', 'tipo' => 'proveedor', 'proveedor_id' => $maya->id]))
            ->assertSessionHasNoErrors();
    }

    // ------------------------------------------ Desde la ficha del proveedor

    public function test_agregar_persona_y_vehiculo_desde_la_ficha_fija_la_empresa_y_regresa_al_cerrar(): void
    {
        $maya = $this->proveedor('Constructora Maya', 'contratista');
        $this->proveedor('Otra Empresa', 'contratista');

        $html = $this->actingAs($this->admin)->get("/personas?nuevo=1&proveedor={$maya->id}")->assertOk()->getContent();
        $this->assertStringContainsString('data-al-cerrar-ir="'.e(route('proveedores.show', ['proveedor' => $maya->id, 'tab' => 'personal'])).'"', $html);
        $this->assertStringContainsString('<input type="hidden" name="proveedor_id" value="'.$maya->id.'" data-persona-proveedor>', $html);
        $this->assertStringContainsString('Al guardar o cerrar regresarás a su ficha.', $html);
        // En el alta fija solo se ofrece el tipo que corresponde (Contratista)
        $inicio = strpos($html, 'id="dialogoNuevaPersona"');
        $alta = substr($html, $inicio, strpos($html, '</dialog>', $inicio) - $inicio);
        $this->assertStringContainsString('value="contratista"', $alta);
        $this->assertStringNotContainsString('value="visitante"', substr($alta, 0, strpos($alta, 'data-solo-visitante')));

        // Al guardar regresa a la ficha con la persona en su personal
        $this->actingAs($this->admin)->post('/personas', $this->datosPersona(['tipo' => 'contratista', 'proveedor_id' => $maya->id, 'volver' => 'proveedor']))
            ->assertRedirect(route('proveedores.show', $maya->id));

        $html = $this->actingAs($this->admin)->get("/vehiculos?nuevo=1&proveedor={$maya->id}")->assertOk()->getContent();
        $this->assertStringContainsString('data-al-cerrar-ir="'.e(route('proveedores.show', ['proveedor' => $maya->id, 'tab' => 'flotilla'])).'"', $html);
        $this->assertStringContainsString('Registrando unidad de <strong>Constructora Maya</strong>', $html);
        $inicio = strpos($html, 'id="dialogoNuevoVehiculo"');
        $alta = substr($html, $inicio, strpos($html, '</dialog>', $inicio) - $inicio);
        $this->assertStringContainsString('<input type="hidden" name="proveedor_id" value="'.$maya->id.'">', $alta);
        $this->assertStringNotContainsString('Otra Empresa', $alta);
        // Solo categorías que llevan empresa propietaria
        $this->assertStringNotContainsString('value="propio_huesped"', $alta);
        $this->assertStringContainsString('value="empresa_proveedor"', $alta);

        // Sin venir de la ficha: la lista completa y sin regreso
        $normal = $this->actingAs($this->admin)->get('/vehiculos')->assertOk()->getContent();
        $this->assertStringNotContainsString('data-al-cerrar-ir', $normal);
        $this->assertStringContainsString('Otra Empresa', $normal);
    }

    // ------------------------------------------------------------- Vehículos

    public function test_vehiculos_filtra_categoria_y_tipo_estilo_en_la_misma_linea(): void
    {
        $this->enEmpresa(fn () => Vehiculo::create(['placas' => 'GOLF-01', 'tipo' => 'otro', 'descripcion_otro' => 'Carrito de golf', 'marca' => 'Club Car', 'color' => 'Blanco', 'propiedad' => 'propio_visitante']));

        $html = $this->actingAs($this->admin)->get('/vehiculos')->assertOk()->getContent();
        $this->assertStringContainsString('data-filtro-estilo-vehiculo', $html);
        $this->assertStringContainsString('data-estilo="otro"', $html);
        $this->assertStringContainsString('Categoría:', $html);
        $this->assertStringContainsString('Tipo / Estilo:', $html);
        // "Otro" es un Tipo / Estilo, no una Categoría
        $this->assertArrayHasKey('otro', Vehiculo::TIPOS);
        $this->assertArrayNotHasKey('otro', AdministradorVehiculos::OPCIONES_PROPIEDAD);
    }

    // ------------------------------------------------------- Zonas y áreas

    public function test_zonas_avisa_codigo_parecido_nombre_repetido_e_inactivo(): void
    {
        $torre = $this->espacio(Espacio::EDIFICIO, 'Torre B', null, null, ['codigo' => 'TB']);
        $this->espacio(Espacio::EDIFICIO, 'Torre Playa', null, $this->playa, ['codigo' => 'TP']);
        $base = '/espacios/duplicado?nivel=edificio&sede_id='.$this->centro->id;

        // TB = T-B = T B: se avisa que se parece
        foreach (['T-B', 'T B', 'tb'] as $codigo) {
            $this->actingAs($this->admin)->getJson($base.'&campo=codigo&valor='.urlencode($codigo))
                ->assertJsonPath('estado', 'parecido')
                ->assertJsonPath('mensaje', 'Se parece a «Torre B» (TB): los espacios y guiones no cuentan, revisa que no sea el mismo.');
        }
        $this->actingAs($this->admin)->getJson($base.'&campo=codigo&valor=TC')->assertJsonPath('estado', 'libre');
        // El código de otra sede no cuenta
        $this->actingAs($this->admin)->getJson($base.'&campo=codigo&valor=TP')->assertJsonPath('estado', 'libre');
        // Al editar la propia zona no se avisa de sí misma
        $this->actingAs($this->admin)->getJson("/espacios/duplicado?campo=codigo&valor=TB&excluir={$torre->id}")->assertJsonPath('estado', 'libre');

        // Nombre igual = ya existe; parecido sin espacios ni guiones = aviso
        $this->actingAs($this->admin)->getJson($base.'&campo=nombre&valor='.urlencode('torre b'))
            ->assertJsonPath('estado', 'existe')->assertJsonPath('mensaje', 'Ya existe «Torre B» en este mismo lugar.');
        $this->actingAs($this->admin)->getJson($base.'&campo=nombre&valor='.urlencode('Torre-B'))
            ->assertJsonPath('estado', 'parecido')->assertJsonPath('coincidencias.0.titulo', 'Torre B (TB)');
        $this->actingAs($this->admin)->getJson($base.'&campo=nombre&valor='.urlencode('Villas del Mar'))->assertJsonPath('estado', 'libre');

        // Desactivada: lo dice y ofrece reactivarla (también al guardar)
        $torre->forceFill(['activo' => false])->save();
        $this->actingAs($this->admin)->getJson($base.'&campo=nombre&valor='.urlencode('Torre B'))
            ->assertJsonPath('estado', 'existe')
            ->assertJsonPath('coincidencias.0.inactivo', true)
            ->assertJsonPath('coincidencias.0.reactivar', route('espacios.estado', $torre->id));
        $this->actingAs($this->admin)->post('/espacios', ['nivel' => 'edificio', 'sede_id' => $this->centro->id, 'nombre' => 'Torre B', '_dialogo' => 'crear-dialogoNuevaZona'])
            ->assertSessionHasErrors(['nombre' => 'Ya existe «Torre B» en este mismo lugar, pero está desactivado: reactívalo (flecha verde) en lugar de crearlo de nuevo.']);
        // Reactivar con el botón del aviso (PATCH estado)
        $this->actingAs($this->admin)->patch(route('espacios.estado', $torre->id), ['activo' => 1])->assertRedirect();
        $this->assertTrue($torre->fresh()->activo);

        // El formulario conecta nombre y código al aviso
        $this->actingAs($this->admin)->get('/espacios')->assertSee('data-duplicado="'.route('espacios.duplicado').'" data-duplicado-con="nivel,padre_id,sede_id"', false);
    }

    public function test_zonas_dentro_de_un_contenedor_tipos_propios_y_secciones(): void
    {
        $torre = $this->espacio(Espacio::EDIFICIO, 'Torre A');
        $piso = $this->espacio(Espacio::AREA, 'Piso 1', $torre);
        $this->espacio(Espacio::AREA_ESPECIFICA, '101', $piso);

        $this->actingAs($this->admin)->getJson("/espacios/duplicado?campo=nombre&valor=101&nivel=area_especifica&padre_id={$piso->id}")
            ->assertJsonPath('estado', 'existe');
        // En otro piso el mismo número es válido
        $otro = $this->espacio(Espacio::AREA, 'Piso 2', $torre);
        $this->actingAs($this->admin)->getJson("/espacios/duplicado?campo=nombre&valor=101&nivel=area_especifica&padre_id={$otro->id}")
            ->assertJsonPath('estado', 'libre');

        // Tipo propio: si ya existe se avisa en vivo y al guardar no se duplica
        $this->actingAs($this->admin)->getJson('/espacios/duplicado?campo=tipo&nivel=elemento&valor=Jacuzzi')->assertJsonPath('estado', 'libre');
        $this->actingAs($this->admin)->post('/espacios/tipos', ['nivel' => 'elemento', 'nombre' => 'Jacuzzi'])->assertSessionHas('ok');
        $this->actingAs($this->admin)->getJson('/espacios/duplicado?campo=tipo&nivel=elemento&valor=jacuzzi')
            ->assertJsonPath('estado', 'existe')->assertJsonPath('mensaje', '«Jacuzzi» ya existe en la lista: no hace falta agregarlo, ya puedes elegirlo.');
        $this->actingAs($this->admin)->post('/espacios/tipos', ['nivel' => 'elemento', 'nombre' => 'JACUZZI'])
            ->assertSessionHas('aviso', 'El tipo «Jacuzzi» ya existía en la lista: no se duplicó y ya puedes elegirlo.');
        $this->assertSame(1, TipoEspacio::where('empresa_id', $this->empresa->id)->whereRaw('LOWER(nombre) = ?', ['jacuzzi'])->count());

        // Sección repetida en la misma sede
        $this->enEmpresa(fn () => GrupoEspacio::forceCreate(['empresa_id' => $this->empresa->id, 'sede_id' => $this->centro->id, 'nombre' => 'Torre B frente']));
        $this->actingAs($this->admin)->getJson("/espacios/duplicado?campo=seccion&sede_id={$this->centro->id}&valor=".urlencode('torre b frente'))
            ->assertJsonPath('estado', 'existe');
        $this->actingAs($this->admin)->getJson("/espacios/duplicado?campo=seccion&sede_id={$this->playa->id}&valor=".urlencode('torre b frente'))
            ->assertJsonPath('estado', 'libre');
    }

    public function test_zonas_duplicado_respeta_empresa_sede_y_permisos(): void
    {
        $this->espacio(Espacio::EDIFICIO, 'Torre Playa', null, $this->playa, ['codigo' => 'TP']);
        $otraEmpresa = $this->crearEmpresa('Hotel Dos');
        $otraSede = $this->crearSede($otraEmpresa, 'OTR');
        app(Tenant::class)->conEmpresa($otraEmpresa->id, fn () => Espacio::create(['sede_id' => $otraSede->id, 'nivel' => 'edificio', 'nombre' => 'Torre Ajena', 'codigo' => 'TA']));

        // Otra empresa: no existe aquí
        $this->actingAs($this->admin)->getJson("/espacios/duplicado?campo=codigo&valor=TA&nivel=edificio&sede_id={$this->centro->id}")->assertJsonPath('estado', 'libre');
        $this->actingAs($this->admin)->getJson("/espacios/duplicado?campo=nombre&valor=Torre%20Ajena&nivel=edificio&sede_id={$otraSede->id}")->assertJsonPath('estado', 'nada');

        // Quien solo administra Centro no consulta Playa
        $sa = $this->crearSuperadmin();
        $roles = app(AdministradorRoles::class);
        $rol = $roles->crearRol($sa, $this->empresa->id, ['nombre' => 'Gerente', 'nivel_jerarquia' => 35]);
        $roles->sincronizarPermisos($sa, $rol, ['espacios.ver' => Alcance::Sede, 'espacios.crear' => Alcance::Sede]);
        $soloCentro = $this->crearUsuario($this->empresa, 'Gerente', $this->centro);
        $this->actingAs($soloCentro)->getJson("/espacios/duplicado?campo=nombre&valor=Torre%20Playa&nivel=edificio&sede_id={$this->playa->id}")->assertJsonPath('estado', 'nada');
        $this->actingAs($this->admin)->getJson("/espacios/duplicado?campo=nombre&valor=Torre%20Playa&nivel=edificio&sede_id={$this->playa->id}")->assertJsonPath('estado', 'existe');

        // El agente no da de alta zonas: no pregunta
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->actingAs($agente)->getJson('/espacios/duplicado?campo=codigo&valor=TB&nivel=edificio')->assertForbidden();
    }

    // ------------------------------------------------------------- Transporte

    public function test_transporte_sugiere_parecidos_en_vivo_al_escribir_placas_y_chofer(): void
    {
        $html = $this->actingAs($this->admin)->get('/transporte')->assertOk()->getContent();
        $this->assertStringContainsString('data-parecidos-vivo="vehiculos" data-parecidos-url="'.route('altas_por_verificar.parecidos').'" data-parecidos-origen="transporte"', $html);
        $this->assertStringContainsString('data-parecidos-vivo="personas"', $html);

        // El agente (registra en Transporte) recibe los parecidos con origen=transporte
        $this->enEmpresa(fn () => Vehiculo::create(['placas' => 'ABC-123-A', 'tipo' => 'autobus', 'marca' => 'Volvo', 'color' => 'Blanco', 'propiedad' => 'transporte_personal']));
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->actingAs($agente)->getJson('/altas-por-verificar/parecidos?padron=vehiculos&origen=transporte&placas=ABC128A')
            ->assertOk()->assertJsonPath('resultados.0.titulo', 'ABC-123-A');
    }

    public function test_al_cerrar_un_dialogo_solo_se_quitan_los_avisos_de_error(): void
    {
        $js = (string) file_get_contents(public_path('js/plataforma.js'));
        $this->assertStringContainsString("if (n.classList.contains('alert-danger') || n.matches('.alert-success[role=\"status\"]')) { n.remove(); }", $js);
        $this->assertStringContainsString('/* Fin Ronda 5 de ajustes (parte 2) */', $js);
    }
}
