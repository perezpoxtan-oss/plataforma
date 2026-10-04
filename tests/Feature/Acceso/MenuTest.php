<?php

namespace Tests\Feature\Acceso;

use App\Models\Empresa;
use App\Models\Modulo;
use App\Support\Menu\ConstructorMenu;
use Database\Seeders\MenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

class MenuTest extends TestCase
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
     * @return array<string, list<string>> menu => claves de modulos visibles
     */
    private function menuDe($usuario): array
    {
        return collect(app(ConstructorMenu::class)->para($usuario))
            ->mapWithKeys(fn ($menu) => [$menu['clave'] => collect($menu['secciones'])->flatten(1)->pluck('clave')->all()])
            ->all();
    }

    public function test_el_superadmin_ve_los_tres_menus_de_segcat(): void
    {
        $menu = $this->menuDe($this->crearSuperadmin());

        $this->assertSame(['estructura', 'recursos_humanos', 'padrones', 'operacion'], array_keys($menu));
        $this->assertContains('empresas', $menu['estructura']);
        $this->assertContains('llaves', $menu['padrones']);
        $this->assertContains('accesos', $menu['operacion']);
    }

    public function test_cada_usuario_ve_solo_lo_que_tiene_permitido(): void
    {
        $menu = $this->menuDe($this->crearUsuario($this->empresa, 'Agente'));

        $this->assertContains('accesos', $menu['operacion']);
        $this->assertNotContains('usuarios', $menu['estructura'] ?? []);
        $this->assertNotContains('permisos', $menu['estructura'] ?? []);
    }

    public function test_un_usuario_sin_roles_no_ve_menus(): void
    {
        $this->assertSame([], $this->menuDe($this->crearUsuario($this->empresa)));
    }

    public function test_un_modulo_no_contratado_desaparece_del_menu(): void
    {
        $agente = $this->crearUsuario($this->empresa, 'Agente');
        $accesos = Modulo::where('clave', 'accesos')->value('id');

        DB::table('empresa_modulos')->where('empresa_id', $this->empresa->id)->where('modulo_id', $accesos)->update(['activo' => false]);

        $this->assertNotContains('accesos', $this->menuDe($agente)['operacion'] ?? []);
    }

    public function test_el_nombre_cambia_segun_el_rubro_y_la_empresa(): void
    {
        $admin = $this->crearUsuario($this->empresa, 'Administrador');
        $items = collect(app(ConstructorMenu::class)->para($admin))
            ->flatMap(fn ($m) => collect($m['secciones'])->flatten(1))->keyBy('clave');

        // Rubro hotel: "Sedes" se llama "Hoteles"
        $this->assertSame('Hoteles', $items['sedes']['nombre']);

        DB::table('empresa_modulos')->where('empresa_id', $this->empresa->id)
            ->where('modulo_id', Modulo::where('clave', 'llaves')->value('id'))
            ->update(['nombre_visible' => 'Llaves maestras']);
        $this->app->forgetScopedInstances(); // nueva peticion

        $items = collect(app(ConstructorMenu::class)->para($admin->fresh()))
            ->flatMap(fn ($m) => collect($m['secciones'])->flatten(1))->keyBy('clave');
        $this->assertSame('Llaves maestras', $items['llaves']['nombre']);
    }

    public function test_el_panel_muestra_menu_usuario_y_rol(): void
    {
        $usuario = $this->crearUsuario($this->empresa, 'Agente');
        $usuario->forceFill(['name' => 'Juan Pérez'])->save();

        $this->actingAs($usuario)->get('/')
            ->assertOk()
            ->assertSee('Consola de Monitoreo Central')
            ->assertSee('Juan Pérez')
            ->assertSee('JP')
            ->assertSee('Agente')
            ->assertSee('Operación')
            ->assertSee('Bitácora de accesos')
            ->assertDontSee('Matriz de permisos');
    }

    public function test_un_modulo_en_migracion_respeta_el_permiso(): void
    {
        $agente = $this->crearUsuario($this->empresa, 'Agente');

        $this->actingAs($agente)->get('/modulos/accesos')->assertOk()->assertSee('se está migrando');
        $this->actingAs($agente)->get('/modulos/usuarios')->assertForbidden();
        $this->actingAs($agente)->get('/modulos/no_existe')->assertNotFound();
    }

    public function test_el_menu_respeta_cambios_hechos_desde_la_interfaz(): void
    {
        Modulo::where('clave', 'llaves')->update(['seccion_menu' => 'Mi sección']);

        $this->seed(MenuSeeder::class);

        $this->assertSame('Mi sección', Modulo::where('clave', 'llaves')->value('seccion_menu'));
    }
}
