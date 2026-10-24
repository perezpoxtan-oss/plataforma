<?php

namespace Tests\Feature\Seguridad;

use App\Console\Commands\CrearDatosDemo;
use App\Models\Acceso;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Espacio;
use App\Models\Novedad;
use App\Models\ReservaArea;
use App\Models\Rubro;
use App\Models\Sede;
use App\Models\User;
use App\Services\Manual\Manual;
use App\Services\Plataforma\ProvisionarEmpresa;
use App\Support\Tenancy\Tenant;
use App\Support\Terminos;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Giros (docs/tecnico/giros.md): tipos de acceso, palabras de la plataforma,
 * manual y datos demo según el rubro de la empresa. Hotel queda igual.
 */
class AdaptacionPorGiroTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
    }

    // ------------------------------------------------------------------ Ayudas

    /**
     * @return array{0: Empresa, 1: Sede, 2: User}
     */
    private function empresaDeGiro(string $rubro, string $nombre = 'Empresa de prueba'): array
    {
        $empresa = app(ProvisionarEmpresa::class)->crear(Rubro::where('clave', $rubro)->firstOrFail(), ['nombre_comercial' => $nombre]);
        $empresa->forceFill(['preferencias' => ['recepcion' => ['rh_autoriza_paso' => false, 'visitas_requieren_autorizacion' => false,
            'proveedores_requieren_autorizacion' => false, 'contratistas_requieren_autorizacion' => false]]])->save();
        $sede = $this->crearSede($empresa, 'S1');

        return [$empresa, $sede, $this->crearUsuario($empresa, 'Administrador')];
    }

    private function enEmpresa(Empresa $empresa, callable $fn): mixed
    {
        return app(Tenant::class)->conEmpresa($empresa->id, $fn);
    }

    private function colaborador(Empresa $empresa, Sede $sede, string $num = '100'): Colaborador
    {
        return $this->enEmpresa($empresa, fn () => Colaborador::create(['num_empleado' => $num, 'nombre' => 'Alejandra', 'apellido_paterno' => 'Fuentes', 'sede_id' => $sede->id]));
    }

    private function registrar(User $actor, Sede $sede, array $datos): TestResponse
    {
        return $this->actingAs($actor)->post('/accesos', $datos + ['sede_id' => $sede->id, '_dialogo' => 'ingreso']);
    }

    private function ultimo(Empresa $empresa): Acceso
    {
        return $this->enEmpresa($empresa, fn () => Acceso::orderByDesc('id')->firstOrFail());
    }

    // ------------------------------------------------------------------- Hotel

    public function test_hotel_y_empresa_sin_giro_quedan_exactamente_igual(): void
    {
        $hotel = Terminos::deRubro('hotel', ['visitante' => 'Huésped']);
        $this->assertSame(Acceso::OPCIONES_TIPO, $hotel->opcionesTipo());
        $this->assertSame(Acceso::TIPOS, $hotel->tiposAcceso());
        $this->assertSame(Acceso::MOTIVOS, $hotel->motivos());
        $texto = '<p title="El huésped">El huésped de la habitación 204 y el Colaborador · Lost &amp; Found</p>';
        $this->assertSame($texto, $hotel->html($texto));
        $this->assertSame($texto, Terminos::para(null)->html($texto));
        $this->assertTrue(Terminos::deRubro(null)->esHotel());

        [$empresa, $sede, $admin] = $this->empresaDeGiro('hotel', 'Hotel Ébano');
        $this->actingAs($admin)->get('/accesos')->assertOk()
            ->assertSee('HUÉSPED')->assertSee('PERSONAL EXTERNO / VISITA')->assertSee('Número de Habitación')->assertSee('¿Tiene Reserva?')
            ->assertSee('Salida a Tour')->assertSee('Visita a Departamento')->assertSee('Recursos Humanos')
            ->assertDontSee('terminos:no', false);

        $this->registrar($admin, $sede, ['tipo' => 'huesped', 'nombre' => 'Leticia Vázquez', 'habitacion' => '204', 'tiene_reserva' => '1', 'numero_reserva' => 'R1'])
            ->assertSessionHasNoErrors();
        $acceso = $this->ultimo($empresa);
        $this->assertSame('huesped', $acceso->tipo);
        $this->assertSame('Huésped', $acceso->etiquetaTipo());
        $this->actingAs($admin)->get('/accesos')->assertSee('Hab. 204');
    }

    // ------------------------------------------------------------- Corporativo

    public function test_corporativo_ofrece_visitante_y_no_huesped(): void
    {
        [, , $admin] = $this->empresaDeGiro('corporativo');

        $r = $this->actingAs($admin)->get('/accesos')->assertOk()
            ->assertSee('VISITANTE')->assertSee('COLABORADOR')->assertSee('CONTRATISTA (OBRA)')
            ->assertDontSee('HUÉSPED')->assertDontSee('Huésped')->assertDontSee('Número de Habitación')->assertDontSee('¿Tiene Reserva?')
            ->assertDontSee('Agencia Vinculada')->assertDontSee('Salida a Tour')->assertDontSee('PERSONAL EXTERNO');
        $this->assertStringNotContainsString('value="huesped"', $r->getContent());
        // El filtro también lista solo los tipos del giro
        $this->assertStringContainsString('<option value="visitante" >Visitante</option>', preg_replace('/\s+/', ' ', $r->getContent()));
    }

    public function test_corporativo_no_puede_registrar_huesped_aunque_mande_la_clave(): void
    {
        [$empresa, $sede, $admin] = $this->empresaDeGiro('corporativo');

        $this->registrar($admin, $sede, ['tipo' => 'huesped', 'nombre' => 'Intento', 'habitacion' => '204'])
            ->assertSessionHasErrors(['tipo' => 'Ese tipo de persona no se registra en esta empresa. Elige uno de la lista.']);
        $this->assertSame(0, $this->enEmpresa($empresa, fn () => Acceso::count()));

        $host = $this->colaborador($empresa, $sede);
        $this->registrar($admin, $sede, ['tipo' => 'visitante', 'nombre' => 'Patricia Gómez', 'motivo_visita' => 'colaborador', 'visita_colaborador_id' => $host->id, 'habitacion' => '1001'])
            ->assertSessionHasNoErrors();
        $acceso = $this->ultimo($empresa);
        $this->assertSame('Visitante', $acceso->etiquetaTipo());
        $this->assertNull($acceso->habitacion, 'En corporativo la visita no guarda habitación');
    }

    public function test_corporativo_exporta_con_sus_palabras(): void
    {
        [, , $admin] = $this->empresaDeGiro('corporativo');

        $csv = $this->actingAs($admin)->get('/accesos/exportar?pestana=historial')->assertOk()->streamedContent();
        $this->assertStringContainsString('Oficina', $csv);
        $this->assertStringNotContainsString('Habitación', $csv);
    }

    public function test_un_registro_de_antes_conserva_su_nombre(): void
    {
        [$empresa, $sede, $admin] = $this->empresaDeGiro('corporativo');
        $this->enEmpresa($empresa, fn () => Acceso::create(['sede_id' => $sede->id, 'tipo' => 'huesped', 'nombre' => 'HUÉSPED DE ANTES', 'entrada_at' => now()]));

        $this->actingAs($admin)->get('/accesos')->assertOk()->assertSee('HUÉSPED DE ANTES')->assertSee('>HUÉSPED<', false);
    }

    // -------------------------------------------------------------- Condominio

    public function test_condominio_ofrece_residente_visita_y_servicio(): void
    {
        [$empresa, $sede, $admin] = $this->empresaDeGiro('condominio');
        $this->enEmpresa($empresa, function () use ($sede) {
            $torre = Espacio::create(['sede_id' => $sede->id, 'nivel' => Espacio::EDIFICIO, 'nombre' => 'Torre A']);
            $piso = Espacio::create(['sede_id' => $sede->id, 'padre_id' => $torre->id, 'nivel' => Espacio::AREA, 'nombre' => 'Piso 1']);
            Espacio::create(['sede_id' => $sede->id, 'padre_id' => $piso->id, 'nivel' => Espacio::AREA_ESPECIFICA, 'nombre' => '101']);
        });

        $this->actingAs($admin)->get('/accesos')->assertOk()
            ->assertSee('RESIDENTE')->assertSee('VISITA')->assertSee('SERVICIO / PROVEEDOR')
            ->assertSee('Visita a Residente')->assertSee('Visita a Administración')->assertSee('¿A qué departamento va?')
            ->assertSee('<option value="101">', false)
            ->assertDontSee('HUÉSPED')->assertDontSee('Número de Reserva')->assertDontSee('Salida a Tour')
            ->assertDontSee('value="rh"', false)->assertDontSee('COLABORADOR');
    }

    public function test_condominio_registra_la_visita_con_su_departamento_y_valida_el_motivo(): void
    {
        [$empresa, $sede, $admin] = $this->empresaDeGiro('condominio');
        $residente = $this->colaborador($empresa, $sede);

        $this->registrar($admin, $sede, ['tipo' => 'visitante', 'nombre' => 'Daniel Romero', 'motivo_visita' => 'rh'])->assertSessionHasErrors('motivo_visita');
        $this->registrar($admin, $sede, ['tipo' => 'huesped', 'nombre' => 'Daniel Romero'])->assertSessionHasErrors('tipo');

        $this->registrar($admin, $sede, ['tipo' => 'visitante', 'nombre' => 'Daniel Romero', 'visita_colaborador_id' => $residente->id, 'habitacion' => '101'])
            ->assertSessionHasNoErrors();
        $acceso = $this->ultimo($empresa);
        $this->assertSame('colaborador', $acceso->motivo_visita, 'Sin motivo, la visita es a un residente');
        $this->assertSame('101', $acceso->habitacion);
        $this->assertSame('Visita', $acceso->etiquetaTipo());

        $this->actingAs($admin)->get('/accesos')->assertOk()
            ->assertSee('Depto. 101')->assertSee('Visita a Residente')->assertSee('>VISITA<', false)->assertDontSee('Hab. 101');

        $this->registrar($admin, $sede, ['tipo' => 'colaborador', 'colaborador_id' => $residente->id])->assertSessionHasNoErrors();
        $this->assertSame('Residente', $this->ultimo($empresa)->etiquetaTipo());
    }

    public function test_condominio_dice_residente_en_menus_y_pantallas(): void
    {
        [$empresa, , $admin] = $this->empresaDeGiro('condominio');

        $inicio = $this->actingAs($admin)->get('/')->assertOk();
        $this->assertStringContainsString('Residentes', $inicio->getContent());
        $this->actingAs($admin)->get('/colaboradores')->assertOk()->assertSee('Residentes');
        $this->actingAs($admin)->get('/lost-found')->assertOk()->assertSee('Objetos olvidados')->assertDontSee('Lost &amp; Found', false);

        // Fraccionamiento: casas
        $frac = Terminos::deRubro('fraccionamiento', Rubro::where('clave', 'fraccionamiento')->value('terminologia'));
        $this->assertSame('Casa 12', $frac->abreviaturaEspacio().' 12');
        $this->assertSame('La visita llegó a la casa 12 del residente.', $frac->texto('El huésped llegó a la habitación 12 del colaborador.'));
    }

    public function test_sustitucion_cuida_articulos_mayusculas_y_no_toca_codigo_ni_valores(): void
    {
        $condo = Terminos::deRubro('condominio', Rubro::where('clave', 'condominio')->value('terminologia'));
        $this->assertSame('Las visitas del departamento 101 y los RESIDENTES', $condo->texto('Los huéspedes de la habitación 101 y los COLABORADORES'));
        $html = $condo->html('<p title="Un huésped">Colaborador</p><input value="Colaborador" name="colaborador_id"><script>var t="Huésped";</script><textarea>huésped</textarea><!--terminos:no-->Huésped<!--terminos:si-->');
        $this->assertSame('<p title="Una visita">Residente</p><input value="Colaborador" name="colaborador_id"><script>var t="Huésped";</script><textarea>huésped</textarea><!--terminos:no-->Huésped<!--terminos:si-->', $html);
    }

    public function test_el_formato_de_accidente_no_cambia_en_otros_giros(): void
    {
        [$empresa, $sede, $admin] = $this->empresaDeGiro('condominio');
        $n = $this->enEmpresa($empresa, function () use ($sede) {
            $n = new Novedad(['sede_id' => $sede->id, 'categoria' => 'accidente', 'reportado_por' => 'GUARDIA', 'ubicacion' => 'ALBERCA', 'descripcion' => 'Prueba']);
            $n->numero = 1;
            $n->estatus = Novedad::ABIERTO;
            $n->save();

            return $n;
        });

        $html = $this->actingAs($admin)->get(route('novedades.index', ['abrir' => $n->id]))->assertOk()->getContent();
        // Dentro del formato de Accidente todo sigue como en SEGCAT
        $this->assertStringContainsString('Nombre del Huésped', $html);
        $this->assertStringContainsString('Huésped / Cliente', $html);
        $this->assertStringContainsString('Colaborador', $html);
    }

    // ------------------------------------------------------------------ Manual

    public function test_el_manual_usa_los_terminos_del_giro_sin_romper_anclas_ni_busqueda(): void
    {
        Cache::flush();
        [$empresa, , $admin] = $this->empresaDeGiro('condominio');
        $manual = app(Manual::class);
        $pagina = $manual->pagina('accesos');
        $this->assertNotNull($pagina);
        $anclasHotel = array_column(app(Tenant::class)->conEmpresa(null, fn () => $manual->apartados($pagina)), 'id');

        $html = $this->actingAs($admin)->get(route('manual.ver', 'accesos'))->assertOk()->getContent();
        $this->assertStringContainsString('Residente', $html);
        $this->assertStringNotContainsString('Huésped', $html);
        $this->assertStringNotContainsString('huésped', $html);
        $this->assertStringNotContainsString('Número de Reserva', $html);

        $anclasCondo = $this->enEmpresa($empresa, fn () => array_column($manual->apartados($pagina), 'id'));
        foreach ($anclasCondo as $id) {
            $this->assertContains($id, $anclasHotel, 'Los apartados del giro conservan el ancla del manual');
        }

        $this->actingAs($admin)->get(route('manual.index', ['q' => 'residente']))->assertOk()->assertSee('Bitácora de accesos');

        // Lo que compara giros se queda como está
        $this->actingAs($admin)->get(route('manual.ver', 'empresas-y-sedes'))->assertOk()
            ->assertSee('Huésped (con número de cuarto, reserva y tour)')->assertSee('<td>Habitaciones</td>', false)->assertDontSee('§TERMINOS');
    }

    public function test_el_manual_de_hotel_no_cambia(): void
    {
        Cache::flush();
        [, , $admin] = $this->empresaDeGiro('hotel', 'Hotel Ébano');
        $this->actingAs($admin)->get(route('manual.ver', 'accesos'))->assertOk()->assertSee('Huésped')->assertSee('Número de Reserva');
        $this->assertSame("a\nb\n", Manual::segunGiro("a\n<!-- solo: hotel -->\nb\n<!-- fin -->\n", 'hotel'));
        $this->assertSame("a\n", Manual::segunGiro("a\n<!-- solo: hotel -->\nb\n<!-- fin -->\n", 'condominio'));
    }

    // ----------------------------------------------------------------- Reservas

    public function test_lista_sugerida_de_hotel_solo_para_hoteles(): void
    {
        [, , $admin] = $this->empresaDeGiro('corporativo');
        $this->actingAs($admin)->get(route('reservas.configuracion'))->assertOk()
            ->assertDontSee('Cargar servicios sugeridos de hotel')->assertSee('Nuevo servicio');
        $this->actingAs($admin)->post(route('reservas.configuracion.sugeridos'))->assertNotFound();

        [, , $adminHotel] = $this->empresaDeGiro('hotel', 'Hotel Ébano');
        $this->actingAs($adminHotel)->get(route('reservas.configuracion'))->assertOk()->assertSee('Cargar servicios sugeridos de hotel');
    }

    // --------------------------------------------------------------------- Demo

    public function test_demo_crea_corporativo_y_condominio_sin_tocar_hotel_y_es_repetible(): void
    {
        $this->artisan('plataforma:demo', ['--password' => 'Prueba123!'])->assertSuccessful();
        $hotel = Empresa::where('nombre_comercial', CrearDatosDemo::EMPRESA)->firstOrFail();
        $conteoHotel = $this->enEmpresa($hotel, fn () => [Acceso::count(), ReservaArea::count(), User::where('empresa_id', $hotel->id)->count()]);
        $this->artisan('plataforma:demo', ['--password' => 'Prueba123!'])->assertSuccessful()->expectsOutputToContain('Demos por giro');

        $this->assertSame($conteoHotel, $this->enEmpresa($hotel, fn () => [Acceso::count(), ReservaArea::count(), User::where('empresa_id', $hotel->id)->count()]));
        $this->assertSame('hotel', $hotel->rubro->clave);

        foreach ([[CrearDatosDemo::EMPRESA_CORPORATIVO, 'corporativo', CrearDatosDemo::USUARIOS_CORPORATIVO], [CrearDatosDemo::EMPRESA_CONDOMINIO, 'condominio', CrearDatosDemo::USUARIOS_CONDOMINIO]] as [$nombre, $rubro, $usuarios]) {
            $empresa = Empresa::where('nombre_comercial', $nombre)->firstOrFail();
            $this->assertSame($rubro, $empresa->rubro->clave);
            $this->assertSame(2, $this->enEmpresa($empresa, fn () => Sede::count()));
            $this->assertSame(5, $this->enEmpresa($empresa, fn () => Acceso::count()), "Accesos de {$nombre} (una sola vez)");
            $this->assertGreaterThan(0, $this->enEmpresa($empresa, fn () => ReservaArea::where('reservable', true)->count()));
            $this->assertSame(0, $this->enEmpresa($empresa, fn () => Acceso::where('tipo', 'huesped')->count()));
            foreach (array_keys($usuarios) as $u) {
                $cuenta = User::where('username', $u)->firstOrFail();
                $this->assertSame($empresa->id, $cuenta->empresa_id);
                $this->assertSame(1, $cuenta->roles()->count());
            }
        }
        $condo = Empresa::where('nombre_comercial', CrearDatosDemo::EMPRESA_CONDOMINIO)->firstOrFail();
        $this->assertSame('101', $this->enEmpresa($condo, fn () => Acceso::where('tipo', 'visitante')->whereNotNull('habitacion')->orderBy('id')->value('habitacion')));
        $this->actingAs(User::where('username', 'condo.agente.demo')->firstOrFail())->get('/accesos')->assertOk()->assertSee('Depto. 101')->assertSee('RESIDENTE');
        $this->actingAs(User::where('username', 'corp.agente.demo')->firstOrFail())->get('/accesos')->assertOk()->assertSee('VISITANTE')->assertDontSee('HUÉSPED');
    }
}
