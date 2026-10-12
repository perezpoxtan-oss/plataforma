<?php

namespace Tests\Feature\Seguridad;

use App\Models\Empresa;
use App\Models\Novedad;
use App\Models\Rol;
use App\Models\RolPermiso;
use App\Models\Sede;
use App\Models\User;
use App\Services\Informes\Indicadores;
use App\Services\Informes\Tendencias;
use App\Services\Permisos\Autorizador;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Decisiones del mapa de procesos (dueño del proyecto):
 *  1. Jefe de seguridad con la Bitácora de auditoría («Su sede») y avisos de
 *     movimientos sensibles encendidos por omisión.
 *  2. «Imprimir» como permiso propio en los cuatro informes.
 *  3. Tendencias: verde si mejora y rojo si empeora, con flecha y texto.
 */
class DecisionesInformesYAuditoriaTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private User $director;

    private User $jefe;

    private int $numero = 0;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-07 15:00:00', 'UTC'));
        Carbon::setTestNow(Carbon::parse('2026-10-07 15:00:00', 'UTC'));
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->director = $this->crearUsuario($this->empresa, 'Director');
        $this->jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function moduloAccion(string $permiso): int
    {
        [$modulo, $accion] = explode('.', $permiso);

        return (int) DB::table('modulo_acciones as ma')->join('modulos as m', 'm.id', '=', 'ma.modulo_id')->join('acciones as a', 'a.id', '=', 'ma.accion_id')
            ->where('m.clave', $modulo)->where('a.clave', $accion)->value('ma.id');
    }

    private function alcance(Rol $rol, string $permiso): ?string
    {
        return DB::table('rol_permisos')->where('rol_id', $rol->id)->where('modulo_accion_id', $this->moduloAccion($permiso))->value('alcance');
    }

    private function rolEmpresa(string $nombre): Rol
    {
        return Rol::where('empresa_id', $this->empresa->id)->where('nombre', $nombre)->firstOrFail();
    }

    private function quitar(string $rol, string $permiso): void
    {
        RolPermiso::where('rol_id', $this->rolEmpresa($rol)->id)->where('modulo_accion_id', $this->moduloAccion($permiso))->delete();
        app(Autorizador::class)->olvidar();
    }

    private function migracion(string $archivo): object
    {
        return require database_path('migrations/'.$archivo);
    }

    // ------------------------------------------------- 1. Bitácora de auditoría

    public function test_el_jefe_de_seguridad_de_una_empresa_nueva_ve_la_bitacora_con_alcance_su_sede(): void
    {
        $this->assertSame('sede', $this->alcance(Rol::plantillas()->where('nombre', 'Jefe de seguridad')->firstOrFail(), 'auditoria.ver'));
        $this->assertSame('sede', $this->alcance($this->rolEmpresa('Jefe de seguridad'), 'auditoria.ver'));
        $this->assertNull($this->alcance($this->rolEmpresa('Jefe de seguridad'), 'auditoria.exportar'));

        // «Su sede» respeta lo que eligió la empresa (por omisión, sus propios movimientos)
        $this->actingAs($this->jefe)->get('/auditoria')->assertOk()->assertSee('Ves solo tus propios movimientos.');
    }

    public function test_la_migracion_solo_cambia_la_plantilla_y_no_los_roles_de_empresas_existentes(): void
    {
        $plantilla = Rol::plantillas()->where('nombre', 'Jefe de seguridad')->firstOrFail();
        $jefeEmpresa = $this->rolEmpresa('Jefe de seguridad');
        $ma = $this->moduloAccion('auditoria.ver');
        RolPermiso::whereIn('rol_id', [$plantilla->id, $jefeEmpresa->id])->where('modulo_accion_id', $ma)->delete();

        $m = $this->migracion('2026_10_29_000510_auditoria_jefe_de_seguridad_por_omision.php');
        $m->up();
        $this->assertSame('sede', $this->alcance($plantilla, 'auditoria.ver'));
        $this->assertNull($this->alcance($jefeEmpresa, 'auditoria.ver'), 'La empresa existente conserva lo que configuró');

        $m->down();
        $this->assertNull($this->alcance($plantilla, 'auditoria.ver'));
        $m->up();
        $this->assertSame('sede', $this->alcance($plantilla, 'auditoria.ver'));
    }

    public function test_los_avisos_de_movimientos_sensibles_vienen_encendidos_salvo_que_la_empresa_los_haya_guardado_apagados(): void
    {
        $this->assertTrue($this->empresa->fresh()->aviso('movimientos_sensibles'));

        $admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->actingAs($admin)->get('/configuracion')->assertOk()
            ->assertSee('name="avisos[movimientos_sensibles]" value="1" form="formAvisos" checked', false);

        $this->empresa->forceFill(['preferencias' => ['avisos' => ['movimientos_sensibles' => false]]])->save();
        $this->assertFalse($this->empresa->fresh()->aviso('movimientos_sensibles'));
    }

    // ------------------------------------------------------ 2. Permiso Imprimir

    public function test_las_plantillas_traen_imprimir_en_los_cuatro_informes_donde_ven(): void
    {
        foreach (['dashboard', 'informe_ejecutivo', 'tendencias', 'bitacora_dia'] as $modulo) {
            $this->assertNotSame(0, $this->moduloAccion("{$modulo}.imprimir"), "{$modulo}.imprimir existe en el catálogo");
            $this->assertSame('empresa', $this->alcance($this->rolEmpresa('Director'), "{$modulo}.imprimir"));
            $this->assertSame('sede', $this->alcance($this->rolEmpresa('Jefe de seguridad'), "{$modulo}.imprimir"));
            $this->assertNull($this->alcance($this->rolEmpresa('Agente'), "{$modulo}.imprimir"));
        }
    }

    public function test_los_botones_y_las_hojas_de_imprimir_piden_el_permiso(): void
    {
        $hojas = [
            'dashboard' => ['tablero.index', 'tablero.imprimir', 'Consola de Monitoreo'],
            'tendencias' => ['tendencias.index', 'tendencias.imprimir', 'Tendencias'],
            'informe_ejecutivo' => ['informe_ejecutivo.index', 'informe_ejecutivo.imprimir', 'Informe ejecutivo'],
            'bitacora_dia' => ['bitacora_dia.index', 'bitacora_dia.imprimir', 'Bitácora general del día'],
        ];

        foreach ($hojas as $modulo => [$pantalla, $hoja, $titulo]) {
            $this->actingAs($this->jefe)->get(route($pantalla))->assertOk()->assertSee(route($hoja), false);
            $this->actingAs($this->jefe)->get(route($hoja))->assertOk()->assertSee($titulo)->assertSee('Imprimir o guardar como PDF');

            $this->quitar('Jefe de seguridad', "{$modulo}.imprimir");
            $this->actingAs($this->jefe)->get(route($pantalla))->assertOk()->assertDontSee(route($hoja), false);
            $this->actingAs($this->jefe)->get(route($hoja))->assertForbidden();
        }

        // Sin «ver» tampoco se imprime
        $this->quitar('Director', 'tendencias.ver');
        $this->actingAs($this->director)->get(route('tendencias.imprimir'))->assertForbidden();

        // Super Administrador sin empresa elegida: las hojas no existen
        $super = $this->crearSuperadmin();
        $this->actingAs($super)->get(route('tablero.imprimir'))->assertNotFound();
        $this->actingAs($super)->get(route('tendencias.imprimir'))->assertNotFound();
    }

    public function test_la_migracion_da_imprimir_a_quien_ya_ve_con_su_mismo_alcance_y_su_reversa_lo_quita(): void
    {
        $m = $this->migracion('2026_10_29_000520_permiso_imprimir_informes.php');
        $m->down();
        $this->assertSame(0, $this->moduloAccion('tendencias.imprimir'));
        $this->assertNotSame(0, $this->moduloAccion('bitacora_dia.imprimir'), 'La Bitácora del día ya tenía su acción Imprimir');

        // Un rol personalizado que ve Tendencias en su sede y uno que no las ve
        $propio = Rol::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Gerente de noche', 'nivel_jerarquia' => 55]);
        RolPermiso::create(['rol_id' => $propio->id, 'modulo_accion_id' => $this->moduloAccion('tendencias.ver'), 'alcance' => 'propios']);
        $sinVer = Rol::create(['empresa_id' => $this->empresa->id, 'nombre' => 'Sin informes', 'nivel_jerarquia' => 65]);

        $m->up();
        $this->assertSame('propios', $this->alcance($propio, 'tendencias.imprimir'));
        $this->assertNull($this->alcance($propio, 'dashboard.imprimir'));
        $this->assertNull($this->alcance($sinVer, 'tendencias.imprimir'));
        $this->assertSame('empresa', $this->alcance($this->rolEmpresa('Director'), 'informe_ejecutivo.imprimir'));
        $this->assertSame('sede', $this->alcance($this->rolEmpresa('Jefe de seguridad'), 'dashboard.imprimir'));
        $this->assertNull($this->alcance($this->rolEmpresa('Agente'), 'dashboard.imprimir'));

        // La Matriz muestra la acción en los tres módulos
        $admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->actingAs($admin)->get(route('permisos.index', ['rol' => $propio->id, 'avanzado' => 1]))->assertOk()
            ->assertSee('name="permisos[tendencias][acciones][]" value="imprimir"', false)
            ->assertSee('name="permisos[dashboard][acciones][]" value="imprimir"', false)
            ->assertSee('name="permisos[informe_ejecutivo][acciones][]" value="imprimir"', false);
    }

    // ------------------------------------------------- 3. Tendencias con color

    public function test_valorar_dice_si_mejoro_o_empeoro_segun_el_sentido_del_indicador(): void
    {
        $malo = Indicadores::valorar(11, 10, Indicadores::SUBIR_ES_MALO);
        $this->assertSame(['sube', 'empeora', 'empeoró 10 %'], [$malo['direccion'], $malo['estado'], $malo['texto']]);
        $bueno = Indicadores::valorar(112, 100, Indicadores::SUBIR_ES_BUENO);
        $this->assertSame(['sube', 'mejora', 'mejoró 12 %'], [$bueno['direccion'], $bueno['estado'], $bueno['texto']]);
        $bajaMalo = Indicadores::valorar(92, 100, Indicadores::SUBIR_ES_MALO);
        $this->assertSame(['baja', 'mejora', 'mejoró 8 %'], [$bajaMalo['direccion'], $bajaMalo['estado'], $bajaMalo['texto']]);
        $this->assertSame('bajó 12.5 %', Indicadores::valorar(7, 8, Indicadores::NEUTRO)['texto']);
        $this->assertSame(['igual', 'Sin cambio'], array_values(array_intersect_key(Indicadores::valorar(3, 3, Indicadores::SUBIR_ES_MALO), array_flip(['estado', 'texto']))));
        $this->assertSame('empeoró', Indicadores::valorar(2, 0, Indicadores::SUBIR_ES_MALO)['texto'], 'Sin base no hay porcentaje');
    }

    public function test_tendencias_pinta_verde_si_mejora_y_rojo_si_empeora(): void
    {
        $novedad = function (string $utc) {
            app(Tenant::class)->conEmpresa($this->empresa->id, function () use ($utc) {
                $n = new Novedad(['sede_id' => $this->centro->id, 'categoria' => 'incidente_general', 'descripcion' => 'Prueba', 'reportado_por' => 'Caseta', 'ubicacion' => 'Cocina']);
                $n->forceFill(['numero' => ++$this->numero, 'created_at' => CarbonImmutable::parse($utc, 'UTC')])->save();
            });
        };
        // Esta semana 3 tickets, la anterior equivalente 1: empeoró
        foreach (['2026-10-05 15:00:00', '2026-10-06 15:00:00', '2026-10-07 14:00:00', '2026-10-03 15:00:00'] as $f) {
            $novedad($f);
        }

        $r = $this->actingAs($this->director)->get(route('tendencias.index', ['seccion' => 'novedades']))->assertOk();
        $s = collect($r->viewData('secciones'))->first();
        $this->assertSame(['Tickets abiertos en el periodo', 3, 1, '', Indicadores::SUBIR_ES_MALO], $s['cifras'][0]);
        $r->assertSee('cambio-informe sube empeora', false)->assertSee('empeoró 200 %');

        $hoja = $this->actingAs($this->director)->get(route('tendencias.imprimir', ['seccion' => 'novedades']))->assertOk();
        $hoja->assertSee('empeoró 200 %')->assertSee('<svg', false)->assertDontSee(route('tendencias.exportar', ['seccion' => 'novedades']), false);
    }

    public function test_cada_cifra_comparable_de_tendencias_declara_si_subir_es_bueno_o_malo(): void
    {
        $admin = $this->crearUsuario($this->empresa, 'Administrador');
        $secciones = $this->actingAs($admin)->get(route('tendencias.index'))->assertOk()->viewData('secciones');
        $this->assertCount(count(Tendencias::SECCIONES), $secciones);

        foreach ($secciones as $s) {
            foreach ($s['cifras'] as [$titulo, , $anterior, $unidad]) {
                if ($anterior !== null && $unidad !== 'texto') {
                    $this->assertArrayHasKey($titulo, Tendencias::SENTIDOS[$s['clave']] ?? [], "«{$titulo}» ({$s['clave']}) no dice si subir es bueno o malo");
                }
            }
        }
        $this->assertSame(Indicadores::SUBIR_ES_MALO, Tendencias::SENTIDOS['accidentes']['Accidentes y lesiones']);
        $this->assertSame(Indicadores::SUBIR_ES_BUENO, Tendencias::SENTIDOS['candidatos']['Contratados']);
    }
}
