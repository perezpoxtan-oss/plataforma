<?php

namespace Tests\Feature\Acceso;

use App\Console\Commands\CrearDatosDemo;
use App\Models\Autorizacion;
use App\Models\Departamento;
use App\Models\DepartamentoResponsable;
use App\Models\Empresa;
use App\Models\Menu;
use App\Models\Modulo;
use App\Models\Sede;
use App\Models\User;
use App\Support\Menu\ConstructorMenu;
use App\Support\Menu\MisPendientes;
use App\Support\Tenancy\Tenant;
use Database\Seeders\MenuSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Lección 35: menús reorganizados (Operación, Padrones, Recursos Humanos,
 * Informes, Estructura), módulos sin pantalla ocultos y «Mis pendientes».
 */
class MenusReorganizadosTest extends TestCase
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

    /**
     * @return array<string, array<string, list<string>>> menu => sección => claves
     */
    private function estructura(User $usuario): array
    {
        $this->app->forgetScopedInstances();

        return collect(app(ConstructorMenu::class)->para($usuario))
            ->mapWithKeys(fn ($m) => [$m['clave'] => collect($m['secciones'])->map(fn ($items) => collect($items)->pluck('clave')->all())->all()])
            ->all();
    }

    /** @return array<string, array<string, mixed>> clave => renglón del menú */
    private function items(User $usuario): array
    {
        $this->app->forgetScopedInstances();

        return collect(app(ConstructorMenu::class)->para($usuario))
            ->flatMap(fn ($m) => collect($m['secciones'])->flatten(1))->keyBy('clave')->all();
    }

    /** @return list<string> foto del acomodo de menús y módulos */
    private function acomodo(): array
    {
        $menus = DB::table('menus')->orderBy('orden')->get()->map(fn ($m) => "{$m->clave}|{$m->nombre}|{$m->icono}|{$m->orden}|{$m->orden_movil}")->all();
        $modulos = DB::table('modulos as mo')->leftJoin('menus as me', 'me.id', '=', 'mo.menu_id')->whereNotNull('mo.menu_id')
            ->orderBy('me.orden')->orderBy('mo.orden_menu')
            ->get(['me.clave as menu', 'mo.seccion_menu', 'mo.orden_menu', 'mo.clave', 'mo.nombre_menu'])
            ->map(fn ($f) => "{$f->menu}|{$f->seccion_menu}|{$f->orden_menu}|{$f->clave}|{$f->nombre_menu}")->all();

        return [...$menus, ...$modulos];
    }

    public function test_el_administrador_ve_el_orden_y_las_secciones_aprobadas(): void
    {
        $menu = $this->estructura($this->crearUsuario($this->empresa, 'Administrador'));

        $this->assertSame(['operacion', 'padrones', 'recursos_humanos', 'estructura'], array_keys($menu));
        $this->assertSame([
            'Caseta' => ['accesos', 'prestamo_llaves', 'pases_salida', 'transporte'],
            'Incidentes' => ['novedades', 'lost_found', 'robo'],
            'Protección civil' => ['recorridos_pc'],
            'Activos' => ['responsivas', 'vouchers'],
            'Consulta' => ['procedimientos'],
        ], $menu['operacion']);
        $this->assertSame([
            'Personas y vehículos' => ['proveedores', 'visitantes', 'vehiculos'],
            'Inventarios' => ['llaves', 'gafetes', 'equipos', 'equipos_pc'],
            'Instalaciones' => ['estacionamientos', 'rutas'],
            'Herramientas' => ['etiquetas_qr'],
        ], $menu['padrones']);
        $this->assertSame([
            'Personal' => ['colaboradores'],
            'Recepción y candidatos' => ['recepcion_rh', 'candidatos'],
            'Catálogos' => ['departamentos', 'puestos', 'turnos'],
        ], $menu['recursos_humanos']);
        // Identidad de la plataforma es solo del superadministrador
        $this->assertSame([
            'Empresa' => ['empresas', 'sedes', 'espacios'],
            'Accesos y permisos' => ['usuarios', 'roles', 'permisos'],
            'Sistema' => ['configuracion', 'auditoria'],
        ], $menu['estructura']);

        $super = $this->estructura($this->crearSuperadmin());
        $this->assertSame(['configuracion', 'identidad', 'auditoria'], $super['estructura']['Sistema']);
        // El celular usa el mismo orden
        $this->assertSame([1, 2, 3, 4, 5], Menu::orderBy('orden')->pluck('orden_movil')->all());
    }

    public function test_los_nombres_del_menu_coinciden_con_las_pantallas(): void
    {
        $admin = $this->crearUsuario($this->empresa, 'Administrador');
        $items = $this->items($admin);

        $this->assertSame('Empresas externas', $items['proveedores']['nombre']);
        $this->assertSame('Novedades', $items['novedades']['nombre']);
        $this->assertSame('Robo', $items['robo']['nombre']);
        $this->assertSame('Bitácora de accesos', $items['accesos']['nombre']);
        $this->assertSame('Padrón de personas', $items['visitantes']['nombre']);
        // El nombre del módulo (matriz de permisos) no cambia
        $this->assertSame('Proveedores', Modulo::where('clave', 'proveedores')->value('nombre'));

        $this->actingAs($admin)->get('/')->assertOk()
            ->assertSeeInOrder(['Operación', 'Padrones', 'Recursos Humanos', 'Estructura'])
            ->assertSee('Empresas externas')->assertSee('Vouchers de reposición')
            ->assertDontSee('Informe ejecutivo')->assertDontSee('Autorizaciones departamentales');
    }

    public function test_el_agente_ve_operacion_y_padrones_y_su_barra_inferior_empieza_con_accesos(): void
    {
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->sede);
        $menu = $this->estructura($agente);

        $this->assertSame(['operacion', 'padrones'], array_slice(array_keys($menu), 0, 2));
        $this->assertArrayNotHasKey('estructura', $menu);
        $this->assertArrayNotHasKey('informes', $menu);
        $this->assertContains('vouchers', $menu['operacion']['Activos']);

        $html = $this->actingAs($agente)->get('/')->assertOk()->getContent();
        $barra = substr($html, strpos($html, 'class="barra-inferior"'));
        $this->assertLessThan(strpos($barra, '<span>Novedades</span>'), strpos($barra, '<span>Accesos</span>'));
    }

    public function test_vouchers_cambia_de_menu_pero_no_de_permisos(): void
    {
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->sede);

        // En la plantilla del Agente sigue siendo padrón: solo consulta
        $this->assertTrue($agente->can('vouchers.ver'));
        $this->assertFalse($agente->can('vouchers.crear'));
        $this->assertFalse($agente->can('vouchers.editar'));
    }

    public function test_recursos_humanos_ya_no_lleva_autorizaciones_pero_conserva_la_pantalla(): void
    {
        $rh = $this->crearUsuario($this->empresa, 'Recursos Humanos');
        $menu = $this->estructura($rh);

        $this->assertSame(['Personal', 'Recepción y candidatos', 'Catálogos'], array_keys($menu['recursos_humanos']));
        $this->assertArrayNotHasKey('autorizaciones', $this->items($rh));
        $this->assertNull(Modulo::where('clave', 'autorizaciones')->value('menu_id'));
        $this->actingAs($rh)->get('/autorizaciones')->assertOk();
    }

    public function test_el_jefe_de_seguridad_ve_la_operacion_de_su_sede(): void
    {
        $jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->sede);
        $menu = $this->estructura($jefe);

        $this->assertSame(['operacion', 'padrones', 'recursos_humanos', 'estructura'], array_keys($menu));
        $this->assertSame(['Personal' => ['colaboradores']], $menu['recursos_humanos']);
        $this->assertSame(['Accesos y permisos' => ['usuarios']], $menu['estructura']);
    }

    public function test_informes_y_los_modulos_sin_pantalla_no_aparecen_en_ningun_menu(): void
    {
        $super = $this->crearSuperadmin();
        $items = $this->items($super);

        $this->assertArrayNotHasKey('informes', $this->estructura($super));
        foreach (['dashboard', 'bitacora_dia', 'tendencias', 'informe_ejecutivo'] as $clave) {
            $this->assertArrayNotHasKey($clave, $items);
        }
        $this->assertTrue(collect($items)->every(fn ($i) => $i['disponible'] && ! str_contains($i['url'], '/modulos/')));

        // Un módulo cuya ruta todavía no existe tampoco se muestra
        Modulo::where('clave', 'accesos')->update(['ruta' => 'accesos.no_existe']);
        $this->assertArrayNotHasKey('accesos', $this->items($super));

        // Cuando una pantalla de Informes exista, el menú aparece solo con ella
        Modulo::where('clave', 'dashboard')->update(['ruta' => 'panel']);
        $this->assertSame(['Informes' => ['dashboard']], $this->estructura($super)['informes']);
        $this->assertSame(['operacion', 'padrones', 'recursos_humanos', 'informes', 'estructura'], array_keys($this->estructura($super)));
    }

    public function test_la_migracion_reacomoda_una_instalacion_existente_y_se_puede_repetir(): void
    {
        $esperado = $this->acomodo();

        // Como estaba antes (QA/Producción): Estructura primero, vouchers en Padrones, autorizaciones en RH, sin Informes
        Menu::where('clave', 'informes')->delete();
        Menu::where('clave', 'estructura')->update(['orden' => 1, 'orden_movil' => 3]);
        Menu::where('clave', 'operacion')->update(['orden' => 4, 'orden_movil' => 1]);
        $rh = Menu::where('clave', 'recursos_humanos')->value('id');
        Modulo::where('clave', 'autorizaciones')->update(['menu_id' => $rh, 'seccion_menu' => 'Recepción y candidatos', 'orden_menu' => 9]);
        Modulo::where('clave', 'vouchers')->update(['menu_id' => Menu::where('clave', 'padrones')->value('id'), 'seccion_menu' => 'Inventarios de Seguridad']);
        Modulo::where('clave', 'informe_ejecutivo')->update(['menu_id' => Menu::where('clave', 'estructura')->value('id'), 'seccion_menu' => 'Organización Interna']);
        Modulo::query()->update(['nombre_menu' => null]);
        $this->assertNotSame($esperado, $this->acomodo());

        $migracion = require database_path('migrations/2026_10_16_000100_reorganizar_menus.php');
        $migracion->up();
        $this->assertSame($esperado, $this->acomodo());

        $migracion->up(); // segunda vez: sin cambios ni duplicados
        $this->assertSame($esperado, $this->acomodo());
        $this->assertSame(1, Menu::where('clave', 'informes')->count());
        $this->assertNull(Modulo::where('clave', 'autorizaciones')->value('menu_id'));

        // El MenuSeeder después de la migración no mueve nada
        $this->seed(MenuSeeder::class);
        $this->assertSame($esperado, $this->acomodo());
    }

    // ------------------------------------------------------------ Mis pendientes

    private function autorizacionPendientePara(User $responsable): Autorizacion
    {
        return app(Tenant::class)->conEmpresa($this->empresa->id, function () use ($responsable) {
            $depto = Departamento::create(['nombre' => 'Ama de llaves', 'todas_las_sedes' => true, 'activo' => true]);
            DepartamentoResponsable::create(['departamento_id' => $depto->id, 'user_id' => $responsable->id, 'sede_id' => $this->sede->id]);

            return Autorizacion::create(['sede_id' => $this->sede->id, 'departamento_id' => $depto->id, 'tipo' => 'visita', 'solicitada_en' => now()]);
        });
    }

    public function test_mis_pendientes_cuenta_las_autorizaciones_del_responsable(): void
    {
        $jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->sede);
        $this->autorizacionPendientePara($jefe);

        $pendientes = app(MisPendientes::class)->para($jefe);
        $this->assertSame(1, $pendientes['total']);
        $autorizaciones = collect($pendientes['items'])->firstWhere('clave', 'autorizaciones');
        $this->assertSame(['Autorizaciones por responder', 1, route('autorizaciones.index')], [$autorizaciones['titulo'], $autorizaciones['total'], $autorizaciones['url']]);

        $html = $this->actingAs($jefe)->get('/')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-mis-pendientes\s*>/', $html); // visible (sin «hidden»)
        $this->assertSame(2, substr_count($html, 'data-mis-pendientes-boton')); // PC y celular
        $this->assertStringContainsString('Autorizaciones por responder', $html);

        // Viaja en la misma consulta de la campana
        $this->actingAs($jefe)->getJson('/notificaciones/resumen')->assertOk()
            ->assertJsonPath('pendientes.total', 1)
            ->assertJsonPath('pendientes.items.0.clave', 'autorizaciones');
    }

    public function test_mis_pendientes_se_oculta_en_cero_y_no_existe_si_nada_aplica(): void
    {
        // Responsable sin solicitudes: el botón existe pero oculto
        $jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->sede);
        app(Tenant::class)->conEmpresa($this->empresa->id, function () use ($jefe) {
            $depto = Departamento::create(['nombre' => 'Mantenimiento', 'todas_las_sedes' => true, 'activo' => true]);
            DepartamentoResponsable::create(['departamento_id' => $depto->id, 'user_id' => $jefe->id, 'sede_id' => $this->sede->id]);
        });
        $html = $this->actingAs($jefe)->get('/')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/data-mis-pendientes\s+hidden/', $html);

        // Recursos Humanos sin departamento a cargo ni nada que verificar: no aparece
        $rh = $this->crearUsuario($this->empresa, 'Recursos Humanos');
        $this->assertSame(['total' => 0, 'items' => []], app(MisPendientes::class)->para($rh));
        $this->actingAs($rh)->get('/')->assertOk()->assertDontSee('data-mis-pendientes', false);

        // Superadministrador sin empresa de trabajo
        $this->actingAs($this->crearSuperadmin())->getJson('/notificaciones/resumen')->assertOk()
            ->assertJsonPath('pendientes.total', 0);
    }

    public function test_mis_pendientes_no_cruza_empresas(): void
    {
        $jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->sede);
        $this->autorizacionPendientePara($jefe);

        $otra = $this->crearEmpresa('Hotel Dos');
        $otroJefe = $this->crearUsuario($otra, 'Jefe de seguridad', $this->crearSede($otra, 'S2'));

        $this->assertSame(0, app(MisPendientes::class)->para($otroJefe)['total']);
    }

    public function test_mis_pendientes_con_los_datos_demo(): void
    {
        $this->artisan('plataforma:demo', ['--password' => 'Prueba123!'])->assertSuccessful();
        $demo = Empresa::where('nombre_comercial', CrearDatosDemo::EMPRESA)->firstOrFail();

        $agente = User::where('username', 'agente.demo')->firstOrFail();
        $pendientes = collect(app(MisPendientes::class)->para($agente)['items'])->keyBy('clave');
        $this->assertSame(['pases_salida', 'procedimientos'], $pendientes->keys()->all());
        $this->assertGreaterThan(0, $pendientes['procedimientos']['total']);
        $this->assertSame(route('procedimientos.por-leer'), $pendientes['procedimientos']['url']);
        $this->assertSame(route('pases-salida.pendientes'), $pendientes['pases_salida']['url']);

        // El administrador verifica altas de varios padrones: el enlace lleva a Inicio (desglose por padrón)
        $admin = User::where('username', 'admin.demo')->where('empresa_id', $demo->id)->firstOrFail();
        $this->app->forgetScopedInstances();
        $altas = collect(app(MisPendientes::class)->para($admin)['items'])->firstWhere('clave', 'altas');
        $this->assertGreaterThan(0, $altas['total']);

        // Un número acotado de consultas (sin N+1)
        $this->app->forgetScopedInstances();
        DB::enableQueryLog();
        app(MisPendientes::class)->para($admin);
        $this->assertLessThan(60, count(DB::getQueryLog()));
    }
}
