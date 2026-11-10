<?php

namespace Tests\Feature\Acceso;

use App\Models\Empresa;
use App\Models\Menu;
use App\Models\Modulo;
use App\Models\Sede;
use App\Models\User;
use App\Support\Menu\ConstructorMenu;
use Database\Seeders\MenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Mapa de procesos: «Vouchers de reposición» pasa de Operación → Activos a
 * Operación → Caseta, después de «Préstamo de llaves». Mismo permiso y ruta.
 */
class VouchersEnLaCasetaTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $sede;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->sede = $this->crearSede($this->empresa);
    }

    /** @return array<string, list<string>> sección => claves del menú Operación */
    private function operacion(User $usuario): array
    {
        $this->app->forgetScopedInstances();
        $menu = collect(app(ConstructorMenu::class)->para($usuario))->firstWhere('clave', 'operacion');

        return collect($menu['secciones'])->map(fn ($items) => collect($items)->pluck('clave')->all())->all();
    }

    /** @return list<string> orden del menú Operación: "sección|orden|clave" */
    private function acomodo(): array
    {
        return DB::table('modulos')->where('menu_id', Menu::where('clave', 'operacion')->value('id'))->orderBy('orden_menu')
            ->get(['seccion_menu', 'orden_menu', 'clave'])->map(fn ($m) => "{$m->seccion_menu}|{$m->orden_menu}|{$m->clave}")->all();
    }

    private function migracion(): object
    {
        return require database_path('migrations/2026_10_29_000320_vouchers_en_la_caseta.php');
    }

    public function test_el_agente_ve_vouchers_en_la_caseta_despues_de_prestamo_de_llaves(): void
    {
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->sede);
        $menu = $this->operacion($agente);

        $caseta = $menu['Caseta'];
        $this->assertSame(array_search('prestamo_llaves', $caseta, true) + 1, array_search('vouchers', $caseta, true));
        $this->assertNotContains('vouchers', $menu['Activos'] ?? []);

        // Mismo permiso y misma pantalla
        $this->assertTrue($agente->can('vouchers.ver'));
        $this->assertFalse($agente->can('vouchers.editar'));
        $this->actingAs($agente)->get(route('vouchers.index'))->assertOk();
    }

    public function test_la_migracion_mueve_una_instalacion_existente_se_repite_y_se_revierte(): void
    {
        $nuevo = $this->acomodo();
        $migracion = $this->migracion();

        // Como estaba antes: Activos → Responsivas, Vouchers
        $migracion->down();
        $antes = $this->acomodo();
        $this->assertNotSame($nuevo, $antes);
        $this->assertContains('Activos|'.(Modulo::where('clave', 'responsivas')->value('orden_menu') + 1).'|vouchers', $antes);
        $this->assertSame(range(1, count($antes)), DB::table('modulos')->where('menu_id', Menu::where('clave', 'operacion')->value('id'))->orderBy('orden_menu')->pluck('orden_menu')->all());

        $migracion->up();
        $this->assertSame($nuevo, $this->acomodo());
        $migracion->up(); // segunda vez: sin cambios
        $this->assertSame($nuevo, $this->acomodo());

        $migracion->down();
        $this->assertSame($antes, $this->acomodo());
        $migracion->down(); // segunda vez: sin cambios
        $this->assertSame($antes, $this->acomodo());

        // El MenuSeeder no mueve lo que ya tiene menú
        $migracion->up();
        $this->seed(MenuSeeder::class);
        $this->assertSame($nuevo, $this->acomodo());
    }

    public function test_si_alguien_ya_lo_movio_desde_la_interfaz_se_respeta(): void
    {
        $migracion = $this->migracion();
        $migracion->down();
        $padrones = Menu::where('clave', 'padrones')->value('id');
        Modulo::where('clave', 'vouchers')->update(['menu_id' => $padrones, 'seccion_menu' => 'Inventarios', 'orden_menu' => 99]);
        $antes = $this->acomodo();

        $migracion->up();
        $this->assertSame($antes, $this->acomodo());
        $this->assertSame([(int) $padrones, 'Inventarios'], [(int) Modulo::where('clave', 'vouchers')->value('menu_id'), Modulo::where('clave', 'vouchers')->value('seccion_menu')]);
    }
}
