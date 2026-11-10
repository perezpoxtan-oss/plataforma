<?php

namespace Tests\Feature\Organizacion;

use App\Models\Empresa;
use App\Models\Espacio;
use App\Models\GrupoEspacio;
use App\Models\Sede;
use App\Models\User;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Zonas y edificios por sede: primero se elige la sede (tarjetas) y luego se
 * trabaja en la pantalla de esa sede, sin mezclar zonas ni secciones de otras.
 */
class EspaciosPorSedeTest extends TestCase
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

    private function espacio(string $nombre): Espacio
    {
        return app(Tenant::class)->conEmpresa($this->empresa->id, fn () => Espacio::where('nombre', $nombre)->firstOrFail());
    }

    private function zona(Sede $sede, string $nombre): Espacio
    {
        $this->actingAs($this->admin)->post('/espacios', ['nivel' => 'edificio', 'sede_id' => $sede->id, 'nombre' => $nombre])->assertSessionHasNoErrors();

        return $this->espacio($nombre);
    }

    public function test_con_varias_sedes_se_eligen_por_tarjeta_con_sus_conteos(): void
    {
        $torre = $this->zona($this->centro, 'Torre A');
        $this->actingAs($this->admin)->post('/espacios', ['nivel' => 'area', 'padre_id' => $torre->id, 'nombre' => 'Piso 1']);
        $this->actingAs($this->admin)->post('/espacios/'.$this->espacio('Piso 1')->id.'/lote', ['modo' => 'lista', 'lista_nombres' => '101,102']);
        $this->actingAs($this->admin)->patch(route('espacios.estado', $this->espacio('102')->id), ['activo' => 0]);
        $this->actingAs($this->admin)->post('/espacios/secciones', ['sede_id' => $this->centro->id, 'nombre' => 'Alberca']);
        $this->zona($this->playa, 'Villas');
        $this->flushSession();

        $this->actingAs($this->admin)->get('/espacios')->assertOk()
            ->assertSee('Elige una sede para ver sus zonas, edificios y secciones.')
            ->assertSee('data-filtro-texto="espacios-sedes"', false)
            ->assertSee('Sede CEN')->assertSee('Sede PLA')
            ->assertSee(route('espacios.sede', $this->centro->id))->assertSee(route('espacios.sede', $this->playa->id))
            ->assertSeeInOrder(['Sede CEN', '1 Zona / Edificio', '1 Piso', '1 Habitación en uso', '1 Sección', 'Sede PLA', '1 Zona / Edificio', '0 Pisos', '0 Habitaciones en uso', '0 Secciones'])
            // Las zonas ya no se mezclan en la primera pantalla
            ->assertDontSee('Torre A')->assertDontSee('Villas')->assertDontSee('Nueva Zona / Edificio');
    }

    public function test_con_una_sola_sede_entra_directo_incluso_desde_enlaces_viejos(): void
    {
        $gerente = $this->gerenteDe($this->centro);

        $this->actingAs($gerente)->get('/espacios')->assertRedirect(route('espacios.sede', $this->centro->id));
        $this->actingAs($gerente)->get('/espacios?pestana=secciones')->assertRedirect(route('espacios.sede', ['sede' => $this->centro->id, 'pestana' => 'secciones']));
    }

    public function test_la_pantalla_de_la_sede_solo_muestra_lo_suyo_y_la_alta_trae_la_sede_fija(): void
    {
        $this->zona($this->centro, 'Torre A');
        $villas = $this->zona($this->playa, 'Villas');
        // Nombre con «ñ» a propósito: un texto corto en ASCII como «V1» aparece al azar
        // en el token CSRF (40 caracteres aleatorios, ~1 de cada 100 corridas) y hacía
        // fallar el assertDontSee de la pestaña Secciones de la otra sede.
        $this->actingAs($this->admin)->post('/espacios', ['nivel' => 'area_especifica', 'padre_id' => $villas->id, 'nombre' => 'Cabaña 1']);
        $this->actingAs($this->admin)->post('/espacios/secciones', ['sede_id' => $this->centro->id, 'nombre' => 'Alberca']);
        $this->actingAs($this->admin)->post('/espacios/secciones', ['sede_id' => $this->playa->id, 'nombre' => 'Jardín']);
        $this->flushSession();

        $this->actingAs($this->admin)->get(route('espacios.sede', $this->centro->id))->assertOk()
            ->assertSee('Torre A')->assertDontSee('Villas')
            ->assertSeeInOrder(['Zonas y edificios', 'Sede', 'Sede CEN'])
            ->assertSee('<input type="hidden" name="sede_id" value="'.$this->centro->id.'">', false)
            ->assertDontSee('name="sede_id" class="campo"', false)
            ->assertDontSee('data-filtro-sede="zonas"', false);

        $this->actingAs($this->admin)->get(route('espacios.sede', ['sede' => $this->centro->id, 'pestana' => 'secciones']))->assertOk()
            ->assertSee('Alberca')->assertDontSee('Jardín')->assertDontSee('Cabaña 1');
        $this->actingAs($this->admin)->get(route('espacios.sede', ['sede' => $this->playa->id, 'pestana' => 'secciones']))->assertOk()
            ->assertSee('Jardín')->assertSee('Cabaña 1')->assertDontSee('Alberca');

        // La ruta de arriba: «Sede» lleva a su pantalla y «Zonas y edificios» a las tarjetas
        $this->actingAs($this->admin)->get("/espacios/{$villas->id}")->assertOk()
            ->assertSee('href="'.route('espacios.sede', $this->playa->id).'"', false)
            ->assertSee('href="'.route('espacios.index').'"', false);
    }

    public function test_una_sede_no_visible_o_ajena_queda_bloqueada(): void
    {
        $gerente = $this->gerenteDe($this->centro);
        $this->actingAs($gerente)->get(route('espacios.sede', $this->playa->id))->assertNotFound();
        $this->actingAs($gerente)->get(route('espacios.sede', $this->centro->id))->assertOk();

        $otra = $this->crearEmpresa('Hotel Dos');
        $ajena = $this->crearSede($otra, 'OTR');
        $this->actingAs($this->admin)->get(route('espacios.sede', $ajena->id))->assertNotFound();
        $this->actingAs($this->admin)->get(route('espacios.sede', 999999))->assertNotFound();
        $this->actingAs($this->crearUsuario($this->empresa, 'Agente'))->get(route('espacios.sede', $this->centro->id))->assertForbidden();
    }

    public function test_tras_crear_editar_baja_y_secciones_se_regresa_a_la_sede(): void
    {
        $alSede = route('espacios.sede', $this->playa->id);
        $this->actingAs($this->admin)->post('/espacios', ['nivel' => 'edificio', 'sede_id' => $this->playa->id, 'nombre' => 'Villas'])->assertRedirect($alSede);
        $villas = $this->espacio('Villas');
        $this->actingAs($this->admin)->put("/espacios/{$villas->id}", ['nombre' => 'Villas Mar'])->assertRedirect($alSede);
        $this->actingAs($this->admin)->patch(route('espacios.estado', $villas->id), ['activo' => 0])->assertRedirect($alSede);
        $this->actingAs($this->admin)->patch(route('espacios.estado', $villas->id), ['activo' => 1])->assertRedirect($alSede);

        $secciones = route('espacios.sede', ['sede' => $this->playa->id, 'pestana' => 'secciones']);
        $this->actingAs($this->admin)->post('/espacios/secciones', ['sede_id' => $this->playa->id, 'nombre' => 'Jardín'])->assertRedirect($secciones);
        $seccion = GrupoEspacio::withoutGlobalScopes()->where('nombre', 'Jardín')->firstOrFail();
        $this->actingAs($this->admin)->put("/espacios/secciones/{$seccion->id}", ['espacios' => []])->assertRedirect($secciones);

        // Eliminar definitivamente: una zona vuelve a su sede; un piso, a su zona
        $this->actingAs($this->admin)->post('/espacios', ['nivel' => 'area', 'padre_id' => $villas->id, 'nombre' => 'Piso 1']);
        $piso = $this->espacio('Piso 1');
        $this->actingAs($this->admin)->delete("/borrar/espacios/{$piso->id}", ['confirmacion' => 'Piso 1'])->assertRedirect(route('espacios.show', $villas->id));
        $this->actingAs($this->admin)->delete("/borrar/espacios/{$villas->id}", ['confirmacion' => 'Villas Mar'])->assertRedirect($alSede);
    }

    private function gerenteDe(Sede $sede): User
    {
        $sa = $this->crearSuperadmin();
        $rol = app(AdministradorRoles::class)->crearRol($sa, $this->empresa->id, ['nombre' => 'Gerente', 'nivel_jerarquia' => 35]);
        app(AdministradorRoles::class)->sincronizarPermisos($sa, $rol, ['espacios.ver' => Alcance::Sede]);

        return $this->crearUsuario($this->empresa, 'Gerente', $sede);
    }
}
