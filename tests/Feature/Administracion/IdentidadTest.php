<?php

namespace Tests\Feature\Administracion;

use App\Models\Modulo;
use App\Support\Identidad;
use App\Support\Menu\ConstructorMenu;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

class IdentidadTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        Storage::fake('public');
    }

    /**
     * @return array<string, mixed>
     */
    private function datos(array $extra = []): array
    {
        return array_merge([
            'nombre' => 'Portiq Seguridad',
            'nombre_corto' => 'Portiq',
            'eslogan' => 'Control de accesos',
            'titular' => 'Grupo Ejemplo',
            'color_primario' => '#0F766E',
            'color_acento' => '#14b8a6',
            'correo_soporte' => 'soporte@ejemplo.com.mx',
            'telefono_soporte' => '+52 998 123 4567',
        ], $extra);
    }

    public function test_el_superadmin_cambia_nombre_y_colores_y_se_ven_en_el_acceso(): void
    {
        $sa = $this->crearSuperadmin();

        $this->actingAs($sa)->get('/identidad')->assertOk()->assertSee('Identidad de la plataforma');
        $this->actingAs($sa)->put('/identidad', $this->datos())->assertRedirect('/identidad')->assertSessionHas('ok');

        $this->app->forgetScopedInstances();
        $this->assertSame('#0f766e', app(Identidad::class)->get('color_primario'));
        $this->assertDatabaseHas('auditoria', ['evento' => 'identidad.actualizada']);

        auth()->logout();
        $this->get('/login')->assertSee('Portiq Seguridad')->assertSee('Control de accesos')->assertSee('--color-primario: #0f766e', false);
    }

    public function test_sube_y_quita_el_simbolo(): void
    {
        $sa = $this->crearSuperadmin();

        $this->actingAs($sa)->put('/identidad', $this->datos(['simbolo' => UploadedFile::fake()->image('logo.png', 256, 256)]))
            ->assertSessionHas('ok');

        $this->app->forgetScopedInstances();
        $ruta = app(Identidad::class)->get('simbolo');
        $this->assertStringStartsWith('storage/identidad/', $ruta);
        Storage::disk('public')->assertExists(substr($ruta, 8));

        $this->actingAs($sa)->put('/identidad', $this->datos(['quitar_simbolo' => '1']))->assertSessionHas('ok');
        $this->app->forgetScopedInstances();
        $this->assertNull(app(Identidad::class)->get('simbolo'));
        Storage::disk('public')->assertMissing(substr($ruta, 8));
    }

    public function test_rechaza_svg_colores_invalidos_y_archivos_grandes(): void
    {
        $sa = $this->crearSuperadmin();

        $svg = UploadedFile::fake()->createWithContent('logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
        $this->actingAs($sa)->put('/identidad', $this->datos(['simbolo' => $svg]))->assertSessionHasErrors('simbolo');

        $this->actingAs($sa)->put('/identidad', $this->datos(['color_primario' => 'red;} body{display:none']))->assertSessionHasErrors('color_primario');

        $grande = UploadedFile::fake()->image('logo.png', 500, 500)->size(900);
        $this->actingAs($sa)->put('/identidad', $this->datos(['simbolo' => $grande]))->assertSessionHasErrors('simbolo');
    }

    public function test_es_solo_del_superadmin(): void
    {
        $empresa = $this->crearEmpresa();
        $admin = $this->crearUsuario($empresa, 'Administrador');

        $this->actingAs($admin)->get('/identidad')->assertForbidden();
        $this->actingAs($admin)->put('/identidad', $this->datos())->assertForbidden();

        // Ni se contrata a la empresa ni las plantillas la incluyen
        $identidad = Modulo::where('clave', 'identidad')->value('id');
        $this->assertFalse(DB::table('empresa_modulos')->where('modulo_id', $identidad)->exists());

        $items = collect(app(ConstructorMenu::class)->para($admin))->flatMap(fn ($m) => collect($m['secciones'])->flatten(1))->pluck('clave');
        $this->assertNotContains('identidad', $items);

        $this->actingAs($admin)->get('/permisos')->assertDontSee('Identidad de la plataforma');
    }

    public function test_el_superadmin_la_ve_en_el_menu(): void
    {
        $items = collect(app(ConstructorMenu::class)->para($this->crearSuperadmin()))
            ->flatMap(fn ($m) => collect($m['secciones'])->flatten(1))->keyBy('clave');

        $this->assertSame(route('identidad.edit'), $items['identidad']['url']);
    }

    public function test_sin_titular_el_pie_muestra_solo_el_nombre_de_la_plataforma(): void
    {
        $this->assertSame('', Identidad::VALORES_POR_DEFECTO['titular']);
        $admin = $this->crearUsuario($this->crearEmpresa(), 'Administrador');

        $this->get('/login')->assertOk()->assertSee('&copy; '.date('Y').' Plataforma', false)->assertDontSee('VDCP');
        $this->actingAs($admin)->get('/')->assertOk()->assertSee('&copy; '.date('Y').' Plataforma.', false)->assertDontSee('VDCP');
    }
}
