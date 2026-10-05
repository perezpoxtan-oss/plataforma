<?php

namespace Tests\Feature\Administracion;

use App\Models\Empresa;
use App\Models\Rol;
use App\Models\Rubro;
use App\Models\Sede;
use App\Services\Permisos\AdministradorRoles;
use App\Services\Permisos\Alcance;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

class EmpresasYSedesTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
    }

    /**
     * @return array<string, mixed>
     */
    private function empresaDatos(array $extra = []): array
    {
        return array_merge([
            'nombre_comercial' => 'Grupo Caribe',
            'razon_social' => 'Grupo Caribe S.A. de C.V.',
            'rfc' => 'gca200101ab1',
            'rubro_id' => Rubro::where('clave', 'hotel')->value('id'),
            'zona_horaria' => 'America/Cancun',
        ], $extra);
    }

    /**
     * @return array<string, mixed>
     */
    private function sedeDatos(array $extra = []): array
    {
        return array_merge([
            'nombre' => 'Hotel Caribe Centro', 'codigo' => 'hcc-01', 'ciudad' => 'Cancún', 'entidad' => 'Quintana Roo',
            'direccion' => 'Av. Tulum 100', 'colonia' => 'Centro', 'codigo_postal' => '77500', 'telefono' => '998 123 4567', 'zona_horaria' => '',
        ], $extra);
    }

    // ---------------------------------------------------------------- Empresas

    public function test_el_superadmin_da_de_alta_una_empresa_lista_para_usarse(): void
    {
        $sa = $this->crearSuperadmin();

        $this->actingAs($sa)->post('/empresas', $this->empresaDatos())->assertRedirect('/empresas')->assertSessionHas('ok');

        $nueva = Empresa::where('rfc', 'GCA200101AB1')->firstOrFail();
        $this->assertSame('America/Cancun', $nueva->zona_horaria);
        $this->assertTrue(DB::table('empresa_modulos')->where('empresa_id', $nueva->id)->exists());
        $this->assertTrue(Rol::where('empresa_id', $nueva->id)->where('nombre', 'Administrador')->exists());
        $this->assertDatabaseHas('auditoria', ['evento' => 'empresas.creada', 'auditable_id' => $nueva->id]);

        $this->actingAs($sa)->get('/empresas')->assertSee('Grupo Caribe')->assertSee('Nueva Empresa');
    }

    public function test_valida_rfc_y_que_no_se_repita(): void
    {
        $sa = $this->crearSuperadmin();
        $this->actingAs($sa)->post('/empresas', $this->empresaDatos());

        $this->actingAs($sa)->post('/empresas', $this->empresaDatos(['nombre_comercial' => 'Otra']))->assertSessionHasErrors('rfc');
        $this->actingAs($sa)->post('/empresas', $this->empresaDatos(['rfc' => '123']))->assertSessionHasErrors('rfc');
        $this->actingAs($sa)->post('/empresas', $this->empresaDatos(['rfc' => 'XAXX010101000', 'zona_horaria' => 'Marte/Base']))->assertSessionHasErrors('zona_horaria');
    }

    public function test_el_administrador_solo_ve_y_edita_su_empresa(): void
    {
        $admin = $this->crearUsuario($this->empresa, 'Administrador');
        $otra = $this->crearEmpresa('Hotel Dos');
        $rubroOriginal = $this->empresa->rubro_id;

        $this->actingAs($admin)->get('/empresas')->assertOk()->assertSee('Mi Empresa')->assertSee('Hotel Uno')->assertDontSee('Hotel Dos')->assertDontSee('Nueva Empresa');

        $this->actingAs($admin)->put("/empresas/{$this->empresa->id}", $this->empresaDatos([
            'nombre_comercial' => 'Hotel Uno Renovado', 'rubro_id' => Rubro::where('clave', 'condominio')->value('id'),
        ]))->assertSessionHas('ok');

        $this->empresa->refresh();
        $this->assertSame('Hotel Uno Renovado', $this->empresa->nombre_comercial);
        $this->assertSame($rubroOriginal, $this->empresa->rubro_id, 'el rubro solo lo cambia la plataforma');

        $this->actingAs($admin)->put("/empresas/{$otra->id}", $this->empresaDatos(['rfc' => 'XAXX010101000']))->assertNotFound();
        $this->actingAs($admin)->post('/empresas', $this->empresaDatos())->assertForbidden();
        $this->actingAs($admin)->patch("/empresas/{$this->empresa->id}/estado", ['activo' => '0'])->assertForbidden();
    }

    public function test_el_administrador_sube_cambia_y_quita_el_logo(): void
    {
        Storage::fake('public');
        $admin = $this->crearUsuario($this->empresa, 'Administrador');

        $this->actingAs($admin)->put("/empresas/{$this->empresa->id}", $this->empresaDatos(['logo' => UploadedFile::fake()->image('logo.png', 300, 120)]))
            ->assertSessionHasNoErrors();
        $primero = $this->empresa->fresh()->logo_ruta;
        $this->assertStringStartsWith('storage/empresas/logos/', $primero);
        Storage::disk('public')->assertExists(substr($primero, 8));
        $this->actingAs($admin)->get('/empresas')->assertSee($primero);

        // Reemplazar borra el anterior; quitar lo deja sin logo
        $this->actingAs($admin)->put("/empresas/{$this->empresa->id}", $this->empresaDatos(['logo' => UploadedFile::fake()->image('nuevo.jpg', 200, 200)]));
        Storage::disk('public')->assertMissing(substr($primero, 8));
        $this->actingAs($admin)->put("/empresas/{$this->empresa->id}", $this->empresaDatos(['quitar_logo' => '1']));
        $this->assertNull($this->empresa->fresh()->logo_ruta);
        $this->assertDatabaseHas('auditoria', ['evento' => 'empresas.logo_actualizado', 'auditable_id' => $this->empresa->id]);

        // Nada de SVG ni archivos que no sean imagen
        $this->actingAs($admin)->put("/empresas/{$this->empresa->id}", $this->empresaDatos(['logo' => UploadedFile::fake()->createWithContent('x.svg', '<svg xmlns="http://www.w3.org/2000/svg"/>')]))
            ->assertSessionHasErrors('logo');
    }

    public function test_desactivar_la_empresa_saca_a_sus_usuarios(): void
    {
        $agente = $this->crearUsuario($this->empresa, 'Agente');
        $this->actingAs($this->crearSuperadmin())->patch("/empresas/{$this->empresa->id}/estado", ['activo' => '0'])->assertSessionHas('aviso');

        $this->actingAs($agente)->get('/')->assertRedirect('/login');
    }

    // ------------------------------------------------------------------- Sedes

    public function test_el_administrador_registra_sedes_con_la_terminologia_del_rubro(): void
    {
        $admin = $this->crearUsuario($this->empresa, 'Administrador');

        $this->actingAs($admin)->get('/sedes')->assertOk()->assertSee('Alta Sedes')->assertSee('Nueva Sede');

        $this->actingAs($admin)->post('/sedes', $this->sedeDatos())->assertRedirect('/sedes')->assertSessionHas('ok');

        $sede = app(Tenant::class)->conEmpresa($this->empresa->id, fn () => Sede::where('codigo', 'HCC-01')->firstOrFail());
        $this->assertSame($this->empresa->id, $sede->empresa_id);
        $this->assertNull($sede->zona_horaria, 'vacía = hora de la empresa');
        $this->assertSame('Cancún', $sede->ciudad);
        $this->assertDatabaseHas('auditoria', ['evento' => 'sedes.creada', 'auditable_id' => $sede->id]);
    }

    public function test_el_codigo_es_unico_dentro_de_la_empresa_pero_no_entre_empresas(): void
    {
        $admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->actingAs($admin)->post('/sedes', $this->sedeDatos());

        $this->actingAs($admin)->post('/sedes', $this->sedeDatos(['nombre' => 'Otro']))->assertSessionHasErrors('codigo');
        $this->actingAs($admin)->post('/sedes', $this->sedeDatos(['codigo' => 'con espacio', 'codigo_postal' => '12']))->assertSessionHasErrors(['codigo', 'codigo_postal']);

        $otraAdmin = $this->crearUsuario($this->crearEmpresa('Hotel Dos'), 'Administrador');
        $this->actingAs($otraAdmin)->post('/sedes', $this->sedeDatos())->assertSessionHas('ok');
    }

    public function test_edita_desactiva_y_no_toca_sedes_ajenas(): void
    {
        $admin = $this->crearUsuario($this->empresa, 'Administrador');
        $sede = $this->crearSede($this->empresa, 'S1');
        $ajena = $this->crearSede($this->crearEmpresa('Hotel Dos'), 'S9');

        $this->actingAs($admin)->put("/sedes/{$sede->id}", $this->sedeDatos(['nombre' => 'Renombrada', 'codigo' => 'S1', 'zona_horaria' => 'America/Tijuana']))->assertSessionHas('ok');
        $sede = app(Tenant::class)->conEmpresa($this->empresa->id, fn () => Sede::find($sede->id));
        $this->assertSame('Renombrada', $sede->nombre);
        $this->assertSame('America/Tijuana', $sede->zonaHoraria());

        $this->actingAs($admin)->patch("/sedes/{$sede->id}/estado", ['activo' => '0'])->assertSessionHas('aviso');
        $this->assertFalse(app(Tenant::class)->conEmpresa($this->empresa->id, fn () => Sede::find($sede->id))->activo);

        $this->actingAs($admin)->put("/sedes/{$ajena->id}", $this->sedeDatos(['codigo' => 'S9']))->assertNotFound();
        $this->actingAs($admin)->patch("/sedes/{$ajena->id}/estado", ['activo' => '0'])->assertNotFound();
    }

    public function test_con_alcance_de_sede_solo_ve_la_suya(): void
    {
        $centro = $this->crearSede($this->empresa, 'CEN');
        $playa = $this->crearSede($this->empresa, 'PLA');

        $sa = $this->crearSuperadmin();
        $gerente = app(AdministradorRoles::class)->crearRol($sa, $this->empresa->id, ['nombre' => 'Gerente', 'nivel_jerarquia' => 35]);
        app(AdministradorRoles::class)->sincronizarPermisos($sa, $gerente, ['sedes.ver' => Alcance::Sede]);
        $usuario = $this->crearUsuario($this->empresa, 'Gerente', $centro);

        $this->actingAs($usuario)->get('/sedes')->assertSee('Sede CEN')->assertDontSee('Sede PLA')->assertDontSee('Nueva Sede');
    }

    public function test_un_agente_no_entra_y_el_superadmin_elige_empresa(): void
    {
        $this->actingAs($this->crearUsuario($this->empresa, 'Agente'))->get('/sedes')->assertForbidden();
        $this->actingAs($this->crearUsuario($this->empresa, 'Agente'))->get('/empresas')->assertForbidden();

        $sa = $this->crearSuperadmin();
        $this->actingAs($sa)->get('/sedes')->assertSee('Elige arriba la');
        $this->actingAs($sa)->withSession([EmpresaDeTrabajo::SESION => $this->empresa->id])->get('/sedes')->assertSee('Alta Sedes');
    }
}
