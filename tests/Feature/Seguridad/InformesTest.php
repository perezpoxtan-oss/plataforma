<?php

namespace Tests\Feature\Seguridad;

use App\Http\Controllers\Administracion\PermisoController;
use App\Models\Acceso;
use App\Models\Autorizacion;
use App\Models\Candidato;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\MovimientoTransporte;
use App\Models\Novedad;
use App\Models\Proveedor;
use App\Models\Rol;
use App\Models\RolPermiso;
use App\Models\Sede;
use App\Models\User;
use App\Services\Informes\Periodo;
use App\Services\Permisos\Autorizador;
use App\Support\Menu\ConstructorMenu;
use App\Support\Tenancy\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Informes (ADR-0017): Tablero, Bitácora general del día, Tendencias e
 * Informe ejecutivo. Alcance por empresa y sede (del informe y del módulo de
 * origen), fichas que se ocultan sin permiso o si su consulta falla,
 * comparación con el periodo anterior, archivos de Excel, impresión, fechas
 * en la hora de la sede y nada de datos personales sensibles.
 */
class InformesTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private Sede $playa;

    private User $director;

    private User $jefeCentro;

    protected function setUp(): void
    {
        parent::setUp();
        // Miércoles 7 de octubre de 2026, 09:00 en Ciudad de México (zona de la empresa)
        $this->viajar('2026-10-07 15:00:00');
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->playa = $this->crearSede($this->empresa, 'PLA');
        $this->director = $this->crearUsuario($this->empresa, 'Director');
        $this->jefeCentro = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ------------------------------------------------------------------ Apoyo

    private function viajar(string $utc): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse($utc, 'UTC'));
        Carbon::setTestNow(Carbon::parse($utc, 'UTC'));
    }

    private function enEmpresa(callable $fn, ?Empresa $empresa = null): mixed
    {
        return app(Tenant::class)->conEmpresa(($empresa ?? $this->empresa)->id, $fn);
    }

    private function acceso(Sede $sede, string $entradaUtc, array $extra = [], ?Empresa $empresa = null): Acceso
    {
        return $this->enEmpresa(function () use ($sede, $entradaUtc, $extra) {
            $a = new Acceso(['sede_id' => $sede->id, 'tipo' => 'visitante', 'movimiento' => 'entrada', 'nombre' => 'VISITA DE PRUEBA']);
            $a->forceFill($extra + ['estado' => 'en_sitio', 'entrada_at' => CarbonImmutable::parse($entradaUtc, 'UTC')])->save();

            return $a;
        }, $empresa);
    }

    private function colaborador(Sede $sede, array $extra = []): Colaborador
    {
        return $this->enEmpresa(fn () => Colaborador::create($extra + ['sede_id' => $sede->id, 'num_empleado' => (string) random_int(1000, 9999),
            'nombre' => 'Juan', 'apellido_paterno' => 'Pérez', 'activo' => true]));
    }

    /** Quita una acción de un rol de la empresa (simula lo que hace la Matriz). */
    private function quitar(string $rol, string $permiso): void
    {
        [$modulo, $accion] = explode('.', $permiso);
        RolPermiso::where('rol_id', Rol::where('empresa_id', $this->empresa->id)->where('nombre', $rol)->value('id'))
            ->whereIn('modulo_accion_id', DB::table('modulo_acciones as ma')->join('modulos as m', 'm.id', '=', 'ma.modulo_id')->join('acciones as a', 'a.id', '=', 'ma.accion_id')
                ->where('m.clave', $modulo)->where('a.clave', $accion)->select('ma.id'))
            ->delete();
        app(Autorizador::class)->olvidar();
    }

    private function contenido(TestResponse $r): string
    {
        return (string) $r->streamedContent();
    }

    // ------------------------------------------------------- Menú y Matriz

    public function test_if01_menu_informes_visible_para_los_roles_de_consulta_y_no_para_el_agente(): void
    {
        $menu = fn (User $u) => collect(app(ConstructorMenu::class)->para($u))->firstWhere('clave', 'informes');
        foreach (['Administrador', 'Director', 'Asistente', 'Supervisor'] as $rol) {
            $u = $this->crearUsuario($this->empresa, $rol, in_array($rol, ['Administrador', 'Director'], true) ? null : $this->centro);
            $this->assertSame(['dashboard', 'bitacora_dia', 'tendencias', 'informe_ejecutivo'], collect($menu($u)['secciones'])->flatten(1)->pluck('clave')->all(), $rol);
        }
        $this->assertNotNull($menu($this->jefeCentro));

        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->assertNull($menu($agente));
        foreach (['/informes/tablero', '/informes/tendencias', '/informes/bitacora-del-dia', '/informes/ejecutivo', '/informes/ejecutivo/imprimir'] as $url) {
            $this->actingAs($agente)->get($url)->assertForbidden();
        }
        // Inicio enlaza al Tablero solo a quien lo puede ver
        $this->actingAs($this->director)->get('/')->assertOk()->assertSee(route('tablero.index'), false);
        $this->actingAs($agente)->get('/')->assertOk()->assertDontSee(route('tablero.index'), false);
    }

    public function test_if02_la_matriz_ya_no_marca_proximamente_a_los_cuatro_informes(): void
    {
        foreach (['dashboard', 'bitacora_dia', 'tendencias', 'informe_ejecutivo'] as $clave) {
            $this->assertNotContains($clave, PermisoController::sinPantalla());
        }
        $admin = $this->crearUsuario($this->empresa, 'Administrador');
        $rol = Rol::where('empresa_id', $this->empresa->id)->where('nombre', 'Supervisor')->value('id');
        $html = $this->actingAs($admin)->get("/permisos?rol={$rol}&avanzado=1")->assertOk()->getContent();
        foreach (['dashboard', 'tendencias', 'bitacora_dia', 'informe_ejecutivo'] as $clave) {
            $this->assertMatchesRegularExpression('/<tr class="[^"]*"\s+data-fila-permisos data-modulo="'.$clave.'"/', $html);
            $this->assertDoesNotMatchRegularExpression('/fila-proximamente"\s+data-fila-permisos data-modulo="'.$clave.'"/', $html);
        }
        // Y sus permisos ya se pueden dar desde la Matriz
        $agente = Rol::where('empresa_id', $this->empresa->id)->where('nombre', 'Agente')->firstOrFail();
        $this->actingAs($admin)->put("/permisos/{$agente->id}", ['permisos' => ['dashboard' => ['acciones' => ['ver'], 'alcance' => 'sede']]])->assertSessionHas('ok');
        $this->assertTrue(RolPermiso::where('rol_id', $agente->id)->exists());
    }

    // ---------------------------------------------------------------- Tablero

    public function test_if03_tablero_respeta_empresa_y_sede(): void
    {
        $this->acceso($this->centro, '2026-10-07 14:00:00');
        $this->acceso($this->centro, '2026-10-07 14:10:00');
        $this->acceso($this->playa, '2026-10-07 14:20:00');
        $otra = $this->crearEmpresa('Hotel Ajeno');
        $this->acceso($this->crearSede($otra, 'AJE'), '2026-10-07 14:30:00', ['nombre' => 'PERSONA AJENA'], $otra);

        $fichaEnSitio = fn (User $u, array $q = []) => collect($this->actingAs($u)->get(route('tablero.index', $q))->assertOk()->viewData('fichas'))->firstWhere('clave', 'en_sitio');
        $this->assertSame('3', $fichaEnSitio($this->director)['valor']);
        $this->assertSame('2', $fichaEnSitio($this->jefeCentro)['valor']);
        $this->assertSame('1', $fichaEnSitio($this->director, ['sede' => $this->playa->id])['valor']);
        // El jefe de Centro no puede pedir Playa: se ignora y ve lo suyo
        $this->assertSame('2', $fichaEnSitio($this->jefeCentro, ['sede' => $this->playa->id])['valor']);

        $detalle = $this->actingAs($this->jefeCentro)->get(route('tablero.detalle', ['ficha' => 'en_sitio', 'parcial' => 1]))->assertOk();
        $detalle->assertSee('VISITA DE PRUEBA')->assertDontSee('PERSONA AJENA')->assertDontSee('<html', false);
        $this->assertSame(2, $detalle->viewData('detalle')['total']);
        $this->actingAs($this->director)->get(route('tablero.detalle', ['ficha' => 'en_sitio']))->assertOk()->assertSee('Volver al Tablero');
        $this->actingAs($this->director)->get(route('tablero.detalle', ['ficha' => 'no_existe']))->assertNotFound();
    }

    public function test_if04_cada_ficha_se_oculta_sin_el_permiso_de_su_modulo(): void
    {
        $claves = fn () => collect($this->actingAs($this->jefeCentro)->get('/informes/tablero')->assertOk()->viewData('fichas'))->pluck('clave')->all();
        $this->assertContains('en_sitio', $claves());
        $this->assertContains('colaboradores', $claves());

        $this->quitar('Jefe de seguridad', 'accesos.ver');
        $this->assertNotContains('en_sitio', $claves());
        $this->actingAs($this->jefeCentro)->get(route('tablero.detalle', ['ficha' => 'en_sitio']))->assertNotFound();
        $this->actingAs($this->jefeCentro)->get(route('tendencias.index'))->assertOk()->assertDontSee('id="seccion-accesos"', false);
        $this->actingAs($this->jefeCentro)->get(route('bitacora_dia.index'))->assertOk()->assertDontSee('<option value="accesos"', false);

        // Sin el permiso del informe, nada (aunque tenga el del módulo)
        $this->quitar('Jefe de seguridad', 'dashboard.ver');
        $this->actingAs($this->jefeCentro)->get('/informes/tablero')->assertForbidden();
    }

    public function test_if05_una_ficha_que_falla_se_oculta_sin_tirar_la_pantalla(): void
    {
        Schema::rename('equipos', 'equipos_fuera');
        try {
            $fichas = collect($this->actingAs($this->director)->get('/informes/tablero')->assertOk()->viewData('fichas'))->pluck('clave');
            $this->assertNotContains('equipos', $fichas);
            $this->assertContains('colaboradores', $fichas);
            $this->actingAs($this->director)->get(route('tablero.detalle', ['ficha' => 'equipos']))->assertNotFound();
            $this->actingAs($this->director)->get('/informes/ejecutivo')->assertOk()->assertDontSee('Equipos asignados')->assertSee('Colaboradores activos');
        } finally {
            Schema::rename('equipos_fuera', 'equipos');
        }
    }

    public function test_if06_el_tablero_hace_pocas_consultas(): void
    {
        foreach (range(1, 30) as $i) {
            $this->acceso($i % 2 ? $this->centro : $this->playa, '2026-10-07 1'.($i % 5).':00:00');
        }
        DB::enableQueryLog();
        $this->actingAs($this->director)->get('/informes/tablero')->assertOk();
        $this->assertLessThan(70, count(DB::getQueryLog()));
        DB::flushQueryLog();
        $this->actingAs($this->director)->get(route('tablero.detalle', ['ficha' => 'en_sitio', 'parcial' => 1]))->assertOk();
        $this->assertLessThan(40, count(DB::getQueryLog()));
    }

    // -------------------------------------------------------------- Tendencias

    public function test_if07_tendencias_compara_contra_el_periodo_anterior(): void
    {
        // Esta semana (lun 5 – mié 7): 4 ingresos; la anterior equivalente (vie 2 – dom 4): 2
        foreach (['2026-10-05 15:00:00', '2026-10-06 15:00:00', '2026-10-06 18:00:00', '2026-10-07 14:00:00'] as $f) {
            $this->acceso($this->centro, $f, ['estado' => 'finalizado', 'salida_at' => CarbonImmutable::parse($f, 'UTC')->addHour()]);
        }
        foreach (['2026-10-02 15:00:00', '2026-10-04 15:00:00', '2026-09-20 15:00:00'] as $f) {
            $this->acceso($this->centro, $f, ['estado' => 'finalizado']);
        }

        $r = $this->actingAs($this->director)->get(route('tendencias.index', ['seccion' => 'accesos']))->assertOk();
        $accesos = collect($r->viewData('secciones'))->firstWhere('clave', 'accesos');
        $this->assertSame(['Ingresos registrados', 4, 2, ''], $accesos['cifras'][0]);
        $this->assertEquals(60, $accesos['desgloses'][2][1]['Personal Externo']); // duración promedio en minutos
        $r->assertSee('▲', false)->assertSee('+100.0 %')->assertSee('02/10/2026 – 04/10/2026');

        // Periodo personalizado: un día sin movimientos contra el anterior con 1
        $s = collect($this->actingAs($this->director)->get(route('tendencias.index', ['periodo' => 'personalizado', 'desde' => '2026-10-03', 'hasta' => '2026-10-03', 'seccion' => 'accesos']))->viewData('secciones'))->first();
        $this->assertSame([0, 1], [$s['cifras'][0][1], $s['cifras'][0][2]]);

        // El periodo anterior dura lo mismo y termina justo antes
        $p = Periodo::crear('mes', null, null, 'America/Mexico_City');
        $this->assertSame(['2026-10-01', '2026-10-07', '2026-09-24', '2026-09-30'], [$p->desde->toDateString(), $p->hasta->toDateString(), $p->anterior()->desde->toDateString(), $p->anterior()->hasta->toDateString()]);
        $this->assertCount(count($p->cubetas()), $p->cubetasAnteriores());
    }

    public function test_if08_tendencias_filtra_sedes_dentro_del_alcance_y_usa_cache_corta(): void
    {
        $this->acceso($this->centro, '2026-10-06 15:00:00');
        $this->acceso($this->playa, '2026-10-06 15:00:00');
        $total = fn (User $u, array $q = []) => collect($this->actingAs($u)->get(route('tendencias.index', $q + ['seccion' => 'accesos']))->viewData('secciones'))->first()['cifras'][0][1];

        $this->assertSame(2, $total($this->director));
        $this->assertSame(1, $total($this->director, ['sedes' => [$this->playa->id], 'fresco' => 1]));
        $this->assertSame(1, $total($this->jefeCentro));
        $this->assertSame(1, $total($this->jefeCentro, ['sedes' => [$this->playa->id]])); // Playa no es suya: se ignora

        // Caché de 5 minutos: un ingreso nuevo se ve al pedir «Actualizar cifras» o al vencer
        $this->acceso($this->centro, '2026-10-07 14:00:00');
        $this->assertSame(2, $total($this->director));
        $this->assertSame(3, $total($this->director, ['fresco' => 1]));
    }

    public function test_if09_csv_de_tendencias_con_permiso_exportar(): void
    {
        $this->acceso($this->centro, '2026-10-06 15:00:00');
        $r = $this->actingAs($this->director)->get(route('tendencias.exportar', ['seccion' => 'accesos']))->assertOk();
        $this->assertStringContainsString('text/csv', (string) $r->headers->get('Content-Type'));
        $csv = $this->contenido($r);
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Indicador,"Este periodo","Periodo anterior","Cambio (%)"', $csv);
        $this->assertStringContainsString('"Ingresos registrados",1,0,', $csv);

        $this->quitar('Director', 'tendencias.exportar');
        $this->actingAs($this->director)->get(route('tendencias.exportar', ['seccion' => 'accesos']))->assertForbidden();
        $this->actingAs($this->director)->get(route('tendencias.index'))->assertOk()->assertDontSee(route('tendencias.exportar', ['seccion' => 'accesos']), false);
    }

    // ------------------------------------------------------ Informe ejecutivo

    public function test_if10_informe_ejecutivo_imprimible_y_sin_datos_sensibles(): void
    {
        $this->colaborador($this->centro, ['curp' => 'PEJJ800101HDFRRN09', 'rfc' => 'PEJJ800101AB1', 'nss' => '12345678901', 'telefono' => '9981112233']);
        $this->enEmpresa(fn () => Proveedor::create(['nombre' => 'Fumigaciones del Sur', 'categoria' => 'contratista', 'rfc' => 'FSU990101AA1', 'telefono' => '9984445566', 'activo' => true]));

        $pantalla = $this->actingAs($this->director)->get('/informes/ejecutivo')->assertOk()
            ->assertSee('Personal y activos')->assertSee('Colaboradores activos')->assertSee('Ver / Ocultar listado de registros')
            ->assertSee('Fumigaciones del Sur')->assertSee('Tendencias')->assertSee('Bitácora del día')->assertSee(route('informe_ejecutivo.imprimir'), false);
        $hoja = $this->actingAs($this->director)->get('/informes/ejecutivo/imprimir')->assertOk()
            ->assertSee('Informe ejecutivo')->assertSee('Imprimir o guardar como PDF')->assertSee('últimos 90 días')->assertDontSee('barra-pc', false);
        $csv = $this->contenido($this->actingAs($this->director)->get('/informes/ejecutivo/exportar')->assertOk());
        $this->assertStringContainsString('"Personal y activos","Colaboradores activos",1', $csv);

        foreach ([$pantalla->getContent(), $hoja->getContent(), $csv] as $texto) {
            foreach (['PEJJ800101HDFRRN09', 'PEJJ800101AB1', '12345678901', '9981112233', 'FSU990101AA1', '9984445566'] as $sensible) {
                $this->assertStringNotContainsString($sensible, $texto);
            }
        }
        $this->actingAs($this->director)->get(route('tablero.detalle', ['ficha' => 'colaboradores']))->assertOk()->assertSee('Juan Pérez')->assertDontSee('PEJJ800101HDFRRN09');
    }

    public function test_if11_informe_ejecutivo_incluye_candidatos_y_autorizaciones_sin_datos_personales(): void
    {
        $rh = $this->crearUsuario($this->empresa, 'Recursos Humanos');
        // Director con permiso de candidatos (consulta) para ver el bloque
        $this->enEmpresa(fn () => (new Candidato)->forceFill(['sede_id' => $this->centro->id, 'nombre_completo' => 'Marta Candidata Sosa', 'telefono' => '9987654321', 'etapa' => 'con_rh'])->save());
        $this->enEmpresa(function () {
            $a = new Autorizacion(['sede_id' => $this->centro->id, 'tipo' => 'visita', 'solicitada_en' => now()->subMinutes(30)]);
            $a->forceFill(['estado' => 'autorizada', 'respondida_en' => now()->subMinutes(20), 'escalado_en' => now()->subMinutes(25)])->save();
        });
        $this->darRol($this->director, 'Recursos Humanos');
        app(Autorizador::class)->olvidar();

        $html = $this->actingAs($this->director)->get('/informes/ejecutivo?fresco=1')->assertOk()
            ->assertSee('Candidatos y vacantes')->assertSee('Candidatos en proceso')->assertSee('Autorizaciones')->assertSee('10 min')->getContent();
        $this->assertStringNotContainsString('Marta Candidata', $html);
        $this->assertStringNotContainsString('9987654321', $html);
        $this->assertNotNull($rh);
    }

    // -------------------------------------------------- Bitácora general del día

    public function test_if12_bitacora_del_dia_por_fecha_y_zona_horaria_de_la_sede(): void
    {
        // 23:30 del 4 de octubre en Ciudad de México = 05:30 UTC del 5
        $this->acceso($this->centro, '2026-10-05 05:30:00', ['nombre' => 'LLEGÓ DE NOCHE', 'estado' => 'finalizado', 'salida_at' => CarbonImmutable::parse('2026-10-05 06:15:00', 'UTC')]);
        $this->enEmpresa(function () {
            $n = new Novedad(['sede_id' => $this->centro->id, 'categoria' => 'incidente_general', 'descripcion' => 'Fuga de agua en la cocina', 'reportado_por' => 'Caseta', 'ubicacion' => 'Cocina']);
            $n->forceFill(['numero' => 1, 'created_at' => CarbonImmutable::parse('2026-10-04 20:00:00', 'UTC')])->save();
        });

        $r = $this->actingAs($this->jefeCentro)->get(route('bitacora_dia.index', ['fecha' => '2026-10-04']))->assertOk();
        $r->assertSeeInOrder(['14:00', 'Ticket abierto', 'Fuga de agua', '23:30', 'Entrada', 'LLEGÓ DE NOCHE'])->assertSee('domingo 4 de octubre de 2026');
        $this->assertSame(['accesos' => 1, 'novedades' => 1], array_filter($r->viewData('conteos')));
        $this->actingAs($this->jefeCentro)->get(route('bitacora_dia.index', ['fecha' => '2026-10-05']))->assertOk()
            ->assertSee('00:15')->assertSee('Salida')->assertDontSee('Fuga de agua');

        // Con la sede en Tijuana (UTC-7), la misma entrada es las 22:30 del día 4
        $this->centro->forceFill(['zona_horaria' => 'America/Tijuana'])->save();
        $this->actingAs($this->director)->get(route('bitacora_dia.index', ['fecha' => '2026-10-04', 'sede' => $this->centro->id]))->assertOk()->assertSee('22:30');

        // Por omisión es hoy; una fecha futura o imposible vuelve a hoy; filtro por tipo
        $this->assertSame('2026-10-07', $this->actingAs($this->jefeCentro)->get(route('bitacora_dia.index'))->viewData('fecha')->format('Y-m-d'));
        $this->assertSame('2026-10-07', $this->actingAs($this->jefeCentro)->get(route('bitacora_dia.index', ['fecha' => '2030-01-01']))->viewData('fecha')->format('Y-m-d'));
        $this->assertSame('2026-10-07', $this->actingAs($this->jefeCentro)->get(route('bitacora_dia.index', ['fecha' => '2026-02-31']))->viewData('fecha')->format('Y-m-d'));
        $this->actingAs($this->jefeCentro)->get(route('bitacora_dia.index', ['fecha' => '2026-10-04', 'tipo' => 'novedades']))->assertOk()
            ->assertSee('Fuga de agua')->assertDontSee('LLEGÓ DE NOCHE');
    }

    public function test_if13_bitacora_del_dia_por_sede_imprimible_y_en_excel(): void
    {
        $this->acceso($this->centro, '2026-10-07 14:00:00', ['nombre' => 'EN CENTRO']);
        $this->acceso($this->playa, '2026-10-07 14:00:00', ['nombre' => 'EN PLAYA']);
        $this->enEmpresa(function () {
            $turno = DB::table('turnos')->insertGetId(['empresa_id' => $this->empresa->id, 'nombre' => 'Matutino', 'hora_inicio' => '07:00', 'hora_fin' => '15:00', 'activo' => true]);
            $proveedor = Proveedor::create(['nombre' => 'Transportes del Mar', 'categoria' => 'transporte_personal', 'activo' => true]);
            $ruta = DB::table('rutas')->insertGetId(['empresa_id' => $this->empresa->id, 'sede_id' => $this->playa->id, 'sentido' => 'llegada', 'nombre' => 'Ruta Norte', 'turno_id' => $turno, 'proveedor_id' => $proveedor->id, 'activo' => true]);
            $m = new MovimientoTransporte(['sede_id' => $this->playa->id, 'ruta_id' => $ruta, 'tipo_movimiento' => 'llegada', 'estatus' => 'a_tiempo', 'cantidad_pax' => 12]);
            $m->forceFill(['fecha' => '2026-10-07', 'created_at' => CarbonImmutable::parse('2026-10-07 13:00:00', 'UTC')])->save();
        });

        $this->actingAs($this->jefeCentro)->get(route('bitacora_dia.index'))->assertOk()->assertSee('EN CENTRO')->assertDontSee('EN PLAYA')->assertDontSee('12 PAX');
        $this->actingAs($this->director)->get(route('bitacora_dia.index'))->assertOk()->assertSee('EN CENTRO')->assertSee('EN PLAYA')->assertSee('12 PAX');
        $this->actingAs($this->director)->get(route('bitacora_dia.index', ['sede' => $this->playa->id]))->assertOk()->assertDontSee('EN CENTRO');

        $this->actingAs($this->jefeCentro)->get(route('bitacora_dia.imprimir'))->assertOk()
            ->assertSee('Bitácora general del día')->assertSee('Entrega el turno')->assertSee('EN CENTRO')->assertDontSee('barra-pc', false);
        $csv = $this->contenido($this->actingAs($this->jefeCentro)->get(route('bitacora_dia.exportar'))->assertOk());
        $this->assertStringContainsString('"Control de accesos",Entrada,"Personal Externo: EN CENTRO"', $csv);
        $this->assertStringNotContainsString('EN PLAYA', $csv);

        $this->quitar('Jefe de seguridad', 'bitacora_dia.imprimir');
        $this->actingAs($this->jefeCentro)->get(route('bitacora_dia.imprimir'))->assertForbidden();
        $this->actingAs($this->jefeCentro)->get(route('bitacora_dia.index'))->assertOk()->assertDontSee(route('bitacora_dia.imprimir'), false);
    }

    public function test_if14_super_administrador_sin_empresa_elige_empresa(): void
    {
        $super = $this->crearSuperadmin();
        foreach (['/informes/tablero', '/informes/tendencias', '/informes/bitacora-del-dia', '/informes/ejecutivo'] as $url) {
            $this->actingAs($super)->get($url)->assertOk()->assertSee('empresa de trabajo');
        }
        $this->actingAs($super)->get('/informes/ejecutivo/imprimir')->assertNotFound();
    }
}
