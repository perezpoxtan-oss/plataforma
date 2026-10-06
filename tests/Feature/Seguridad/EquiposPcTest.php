<?php

namespace Tests\Feature\Seguridad;

use App\Models\Empresa;
use App\Models\EquipoPc;
use App\Models\Modulo;
use App\Models\ModuloAccion;
use App\Models\Rol;
use App\Models\RolPermiso;
use App\Models\Sede;
use App\Models\User;
use App\Services\Permisos\Alcance;
use App\Services\Permisos\Autorizador;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Catálogo de Equipos de Protección Civil como módulo propio «equipos_pc»
 * (Padrones → Inventarios de Seguridad). Las pruebas del catálogo en sí
 * (alta, edición, baja, alcance, lector) están en RecorridosPcTest.
 */
class EquiposPcTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
    }

    private function equipo(string $serie): EquipoPc
    {
        return app(Tenant::class)->conEmpresa($this->empresa->id, function () use ($serie) {
            $e = new EquipoPc(['sede_id' => $this->centro->id, 'categoria' => 'EXTINTOR', 'numero_serie' => $serie]);
            $e->save();

            return $e;
        });
    }

    /** @param array<string, Alcance> $permisos */
    private function rolCon(string $nombre, array $permisos): Rol
    {
        $rol = Rol::create(['empresa_id' => $this->empresa->id, 'nombre' => $nombre, 'nivel_jerarquia' => 70]);
        foreach ($permisos as $clave => $alcance) {
            [$modulo, $accion] = explode('.', $clave);
            $ma = ModuloAccion::whereHas('modulo', fn ($q) => $q->where('clave', $modulo))->whereHas('accion', fn ($q) => $q->where('clave', $accion))->firstOrFail();
            RolPermiso::create(['rol_id' => $rol->id, 'modulo_accion_id' => $ma->id, 'alcance' => $alcance]);
        }

        return $rol;
    }

    public function test_es_un_modulo_de_padrones_en_inventarios_de_seguridad(): void
    {
        $modulo = Modulo::with('menu', 'area')->where('clave', 'equipos_pc')->firstOrFail();
        $this->assertSame(['Equipos de Protección Civil', 'seguridad', 'padrones', 'Inventarios de Seguridad', 'equipos_pc.index'],
            [$modulo->nombre, $modulo->area->clave, $modulo->menu->clave, $modulo->seccion_menu, $modulo->ruta]);
        $this->assertEqualsCanonicalizing(['ver', 'crear', 'editar', 'eliminar', 'imprimir', 'borrar'], $modulo->acciones()->pluck('clave')->all());

        // En el menú, justo después de Equipos de seguridad
        $orden = Modulo::where('menu_id', $modulo->menu_id)->orderBy('orden_menu')->pluck('clave')->all();
        $this->assertSame(array_search('equipos', $orden, true) + 1, array_search('equipos_pc', $orden, true));
        $this->actingAs($this->admin)->get('/')->assertSee('Equipos de Protección Civil')->assertSee(route('equipos_pc.index'), false);
    }

    public function test_las_plantillas_dan_los_mismos_permisos_que_antes(): void
    {
        $esperado = [
            // rol => [recorridos_pc.ver, equipos.crear, equipos.editar, equipos.eliminar, equipos.imprimir] copiados a equipos_pc.*
            'Administrador', 'Director', 'Jefe de seguridad', 'Asistente', 'Supervisor', 'Agente',
        ];
        foreach ($esperado as $nombre) {
            $usuario = $this->crearUsuario($this->empresa, $nombre, $nombre === 'Administrador' || $nombre === 'Director' ? null : $this->centro);
            $permisos = app(Autorizador::class)->permisosEfectivos($usuario);
            $this->assertSame(isset($permisos['recorridos_pc.ver']), isset($permisos['equipos_pc.ver']), "{$nombre}: ver");
            foreach (['crear', 'editar', 'eliminar', 'imprimir'] as $accion) {
                $this->assertSame($permisos["equipos.{$accion}"]->alcance ?? null, $permisos["equipos_pc.{$accion}"]->alcance ?? null, "{$nombre}: {$accion}");
            }
        }
        // El Agente consulta pero no administra
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->assertTrue($agente->can('equipos_pc.ver'));
        $this->assertFalse($agente->can('equipos_pc.crear'));
    }

    public function test_la_migracion_crea_el_modulo_y_copia_los_permisos_en_una_instalacion_existente(): void
    {
        // Rol propio de la empresa: consulta recorridos en su sede y crea equipos solo los propios
        $this->rolCon('Brigadista', ['recorridos_pc.ver' => Alcance::Sede, 'equipos.crear' => Alcance::Propios, 'equipos.imprimir' => Alcance::Empresa]);
        $brigadista = $this->crearUsuario($this->empresa, 'Brigadista', $this->centro);
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);

        // Base anterior: sin el módulo (lo quitamos con sus permisos y su contratación)
        $id = Modulo::where('clave', 'equipos_pc')->value('id');
        DB::table('empresa_modulos')->where('modulo_id', $id)->delete();
        DB::table('modulo_acciones')->where('modulo_id', $id)->delete();
        DB::table('modulos')->where('id', $id)->delete();
        app(Autorizador::class)->olvidar();
        $this->assertFalse($brigadista->can('equipos_pc.ver'));

        $migracion = require database_path('migrations/2026_10_10_000360_catalogo_equipos_pc_en_padrones.php');
        $migracion->up();
        $migracion->up(); // dos veces no duplica nada
        app(Autorizador::class)->olvidar();

        $modulo = Modulo::with('menu')->where('clave', 'equipos_pc')->firstOrFail();
        $this->assertSame(['padrones', 'Inventarios de Seguridad', 'equipos_pc.index'], [$modulo->menu->clave, $modulo->seccion_menu, $modulo->ruta]);
        $this->assertDatabaseHas('empresa_modulos', ['empresa_id' => $this->empresa->id, 'modulo_id' => $modulo->id, 'activo' => true]);

        $efectivos = app(Autorizador::class)->permisosEfectivos($brigadista);
        $this->assertSame(Alcance::Sede, $efectivos['equipos_pc.ver']->alcance);
        $this->assertSame(Alcance::Propios, $efectivos['equipos_pc.crear']->alcance);
        $this->assertSame(Alcance::Empresa, $efectivos['equipos_pc.imprimir']->alcance);
        $this->assertArrayNotHasKey('equipos_pc.editar', $efectivos);
        $this->assertTrue($agente->can('equipos_pc.ver'));
        $this->assertFalse($agente->can('equipos_pc.crear'));
        foreach (['ver', 'crear', 'editar', 'eliminar', 'imprimir'] as $accion) {
            $this->assertTrue($jefe->can("equipos_pc.{$accion}"), "Jefe: {$accion}");
        }
        // Las plantillas también (para las empresas nuevas)
        $plantilla = Rol::whereNull('empresa_id')->where('nombre', 'Agente')->firstOrFail();
        $this->assertTrue(RolPermiso::where('rol_id', $plantilla->id)->whereHas('moduloAccion', fn ($q) => $q->where('modulo_id', $modulo->id))->exists());
        $this->assertSame(1, Modulo::where('clave', 'equipos_pc')->count());

        $migracion->down();
        $this->assertFalse(Modulo::where('clave', 'equipos_pc')->exists());
    }

    public function test_las_direcciones_anteriores_redirigen_de_forma_permanente(): void
    {
        $e = $this->equipo('EXT-01');

        $this->actingAs($this->admin)->get('/recorridos-pc/equipos')->assertStatus(301)->assertRedirect(route('equipos_pc.index'));
        $this->actingAs($this->admin)->get('/recorridos-pc/equipos?sede=1')->assertStatus(301)->assertRedirect(route('equipos_pc.index', ['sede' => 1]));
        foreach (['etiqueta', 'qr', 'ir'] as $pantalla) {
            $this->actingAs($this->admin)->get("/recorridos-pc/equipos/{$e->id}/{$pantalla}")->assertStatus(301)->assertRedirect(route('equipos_pc.'.$pantalla, $e->id));
        }
        $this->actingAs($this->admin)->get('/recorridos-pc/equipos/999999/etiqueta')->assertNotFound();

        // Otra empresa: 404 sin revelar que existe; sin permiso: 403
        $intruso = $this->crearUsuario($this->crearEmpresa('Hotel Dos'), 'Administrador');
        $this->actingAs($intruso)->get("/recorridos-pc/equipos/{$e->id}/etiqueta")->assertNotFound();
        $sinRol = $this->crearUsuario($this->empresa);
        $this->actingAs($sinRol)->get('/recorridos-pc/equipos')->assertForbidden();
        $this->actingAs($sinRol)->get("/recorridos-pc/equipos/{$e->id}/qr")->assertForbidden();
    }

    public function test_se_administra_con_equipos_pc_y_no_con_equipos_ni_recorridos(): void
    {
        $e = $this->equipo('EXT-01');
        // Antes bastaba «recorridos_pc.ver» + «equipos.*»; ahora el catálogo tiene sus propios permisos
        $this->rolCon('Solo recorridos', ['recorridos_pc.ver' => Alcance::Empresa, 'recorridos_pc.crear' => Alcance::Empresa, 'equipos.ver' => Alcance::Empresa,
            'equipos.crear' => Alcance::Empresa, 'equipos.editar' => Alcance::Empresa, 'equipos.eliminar' => Alcance::Empresa, 'equipos.imprimir' => Alcance::Empresa]);
        $usuario = $this->crearUsuario($this->empresa, 'Solo recorridos');
        $this->actingAs($usuario)->get('/equipos-pc')->assertForbidden();
        $this->actingAs($usuario)->post('/equipos-pc', ['sede_id' => $this->centro->id, 'categoria' => 'EXTINTOR', 'numero_serie' => 'X'])->assertForbidden();
        $this->actingAs($usuario)->get("/equipos-pc/{$e->id}/etiqueta")->assertForbidden();
        // El recorrido no ofrece el enlace al catálogo a quien no lo ve, y su lector no encuentra equipos
        $this->actingAs($usuario)->get('/recorridos-pc')->assertOk()->assertDontSee(route('equipos_pc.index'), false);
        $this->actingAs($usuario)->get('/lector/resolver?entrada=EXT-01&tipos=equipo_pc')->assertJson(['resultados' => []]);

        // Con «equipos_pc.*» sí
        $this->rolCon('Brigada PC', ['equipos_pc.ver' => Alcance::Empresa, 'equipos_pc.crear' => Alcance::Empresa, 'equipos_pc.imprimir' => Alcance::Empresa]);
        $brigada = $this->crearUsuario($this->empresa, 'Brigada PC');
        $this->actingAs($brigada)->get('/equipos-pc')->assertOk()->assertSee('EXT-01')->assertSee('Nuevo Equipo')
            ->assertDontSee('Recorridos PC')->assertDontSee('dialogoEditarEquipoPc');
        $this->actingAs($brigada)->post('/equipos-pc', ['sede_id' => $this->centro->id, 'categoria' => 'HIDRANTE', 'numero_serie' => 'hid-01'])
            ->assertSessionHasNoErrors()->assertSessionHas('ok');
        $this->actingAs($brigada)->get("/equipos-pc/{$e->id}/etiqueta")->assertOk();
        $this->actingAs($brigada)->patch("/equipos-pc/{$e->id}/desactivar")->assertForbidden();
        $this->actingAs($brigada)->get('/lector/resolver?entrada=EXT-01&tipos=equipo_pc')->assertJsonPath('resultados.0.url', route('equipos_pc.ir', $e->id));
        $this->assertSame('equipos_pc.ver', EquipoPc::permisoLector());
        $this->assertDatabaseHas('auditoria', ['evento' => 'equipos_pc.creado']);
    }

    public function test_el_recorrido_muestra_un_enlace_pequeno_al_catalogo_y_ya_no_el_boton(): void
    {
        $this->actingAs($this->admin)->get('/recorridos-pc')->assertOk()
            ->assertDontSee('Catálogo de Equipos</a>', false)
            ->assertSee('Padrones → Equipos de Protección Civil')->assertSee(route('equipos_pc.index'), false);
        $this->actingAs($this->admin)->get('/equipos-pc')->assertOk()->assertSee('Catálogo de Equipos de Protección Civil')->assertSee('Recorridos PC');
    }
}
