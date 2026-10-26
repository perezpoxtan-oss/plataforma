<?php

namespace Tests\Feature\Nucleo;

use App\Models\Empresa;
use App\Models\Modulo;
use App\Models\Notificacion;
use App\Models\Paquete;
use App\Models\Plan;
use App\Models\Producto;
use App\Models\Suscripcion;
use App\Models\SuscripcionExcepcion;
use App\Models\User;
use App\Services\Comercial\CatalogoPublico;
use App\Services\Comercial\CoherenciaCatalogo;
use App\Services\Permisos\Autorizador;
use App\Services\Suscripciones\Aprovisionamiento;
use App\Services\Suscripciones\ModulosEfectivos;
use App\Support\DependenciasModulos;
use Database\Seeders\CatalogoComercialSeeder;
use Database\Seeders\CatalogoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * Lista de dependencias entre módulos (plan de correcciones C-02) y planes
 * coherentes (C-01, C-05). ES LA PRUEBA DE CI: falla si un plan o paquete
 * publicado vende un módulo sin lo que necesita.
 */
class DependenciasModulosTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private User $sa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->sa = $this->crearSuperadmin();
        RateLimiter::clear('comercial-contratar|127.0.0.1');
    }

    private function cliente(string $plan, array $paquetes = []): Suscripcion
    {
        [$s] = app(CatalogoPublico::class)->registrar(['nombre' => 'Laura Méndez', 'empresa' => 'Cliente '.$plan.count(Suscripcion::all()), 'correo' => 'c'.Suscripcion::count().$plan.'@ejemplo.com.mx',
            'sedes' => 1, 'plan_id' => Plan::where('slug', $plan)->value('id'), 'paquetes' => $paquetes], '127.0.0.1');

        return app(Aprovisionamiento::class)->confirmarPago($s, $this->sa, 'T-1');
    }

    /** @return list<string> */
    private function activos(int $empresaId): array
    {
        return DB::table('empresa_modulos as em')->join('modulos as m', 'm.id', '=', 'em.modulo_id')
            ->where('em.empresa_id', $empresaId)->where('em.activo', true)->pluck('m.clave')->sort()->values()->all();
    }

    // ------------------------------------------------------------------ CI: la lista y el catálogo

    public function test_la_lista_solo_nombra_modulos_que_existen_en_el_catalogo(): void
    {
        $claves = collect(CatalogoSeeder::catalogo())->flatMap(fn ($area) => collect($area[2])->flatMap(fn ($def, $clave) => [$clave, ...array_keys($def[3] ?? [])]))->all();
        foreach (DependenciasModulos::REQUIERE as $modulo => $necesita) {
            $this->assertContains($modulo, $claves, "«{$modulo}» no existe en el catálogo de módulos");
            foreach ($necesita as $n) {
                $this->assertContains($n, $claves, "«{$modulo}» necesita «{$n}», que no existe en el catálogo");
            }
        }
        foreach (DependenciasModulos::ACCIONES_CON_MODULO as $accion => $modulo) {
            $this->assertContains($modulo, $claves);
            $this->assertContains(explode('.', $accion)[0], $claves);
        }
    }

    public function test_ningun_plan_publicado_vende_un_modulo_sin_sus_dependencias(): void
    {
        $this->assertSame([], app(CoherenciaCatalogo::class)->problemas(), 'El catálogo comercial vende módulos que no funcionan');
        $this->assertSame([], app(CoherenciaCatalogo::class)->problemas(soloPublicados: false));
        // La definición del seeder (lo que se instala de fábrica) también cumple, sin base de datos
        $direccion = array_keys(CatalogoSeeder::catalogo()['direccion'][2]);
        foreach (CatalogoComercialSeeder::PLANES as $plan => $productos) {
            $base = array_merge($direccion, ...array_map(fn ($p) => CatalogoComercialSeeder::PRODUCTOS[$p][3], $productos));
            $this->assertSame([], DependenciasModulos::faltantes($base), "Plan {$plan}");
            foreach (CatalogoComercialSeeder::PAQUETES as $paquete => [$suyos, $planes]) {
                if (in_array($plan, $planes, true)) {
                    $con = array_merge($base, ...array_map(fn ($p) => CatalogoComercialSeeder::PRODUCTOS[$p][3], $suyos));
                    $this->assertSame([], DependenciasModulos::faltantes($con), "Plan {$plan} con {$paquete}");
                }
            }
        }
    }

    public function test_la_coherencia_detecta_un_plan_roto_y_avisa_al_guardarlo(): void
    {
        $caseta = Producto::where('clave', 'caseta')->firstOrFail();
        $this->actingAs($this->sa)->post('/catalogo-comercial/planes', ['nombre' => 'Solo caseta', 'precio_por_sede_mensual' => '500', 'productos' => [$caseta->id], 'publicado' => '1'])
            ->assertSessionHas('ok')
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'Préstamo de llaves necesita Catálogo de llaves') && str_contains($m, 'Bitácora de transporte necesita Rutas de transporte'));
        $this->assertNotSame([], app(CoherenciaCatalogo::class)->problemas());
        $this->actingAs($this->sa)->get('/catalogo-comercial?pestana=planes')->assertOk()->assertSee('Hay planes que no funcionan como están');
    }

    public function test_completar_y_faltantes(): void
    {
        $r = DependenciasModulos::completar(['transporte']);
        $this->assertContains('rutas', $r['claves']);
        $this->assertSame('transporte', $r['agregados']['rutas']);
        $this->assertSame(['transporte' => ['rutas']], DependenciasModulos::faltantes(['transporte']));
        $this->assertSame(['transporte'], DependenciasModulos::quienesLoNecesitan('rutas', ['transporte', 'llaves']));
        // Lo que ya está (Dirección) no se reporta como agregado
        $this->assertSame([], DependenciasModulos::completar(['accesos'], ['colaboradores', 'departamentos', 'autorizaciones', 'usuarios'])['agregados']);
    }

    // ------------------------------------------------------------------ Suscripciones y excepciones

    public function test_cada_plan_trae_la_organizacion_base_y_rh_se_queda_con_lo_suyo(): void
    {
        foreach (['esencial', 'profesional', 'corporativo'] as $plan) {
            $activos = $this->activos((int) $this->cliente($plan)->empresa_id);
            foreach (['colaboradores', 'departamentos', 'puestos', 'turnos', 'autorizaciones', 'accesos', 'prestamo_llaves', 'responsivas', 'pases_salida', 'rutas', 'transporte'] as $m) {
                $this->assertContains($m, $activos, "{$plan} debe traer {$m}");
            }
            $this->assertSame($plan === 'corporativo', in_array('candidatos', $activos, true), "{$plan}: candidatos");
            $this->assertSame($plan !== 'esencial', in_array('equipos_pc', $activos, true), "{$plan}: Equipos PC va con Incidentes");
            $this->assertNotContains('reservas', $activos);
        }
        // Reservas es un complemento para cualquier plan
        $reservas = Paquete::where('slug', 'reservas-y-eventos')->firstOrFail();
        $this->assertEqualsCanonicalizing(['esencial', 'profesional', 'corporativo'], $reservas->planes()->pluck('slug')->all());
        $this->assertContains('reservas', $this->activos((int) $this->cliente('esencial', [$reservas->id])->empresa_id));
    }

    public function test_el_expediente_y_los_datos_personales_son_de_recursos_humanos(): void
    {
        $esencial = $this->cliente('esencial');
        $admin = User::where('empresa_id', $esencial->empresa_id)->firstOrFail();
        $this->assertTrue($admin->can('colaboradores.ver'));
        $this->assertTrue($admin->can('colaboradores.crear'));
        $this->assertFalse($admin->can('colaboradores.datos_personales'));

        $rh = Paquete::where('slug', 'recursos-humanos')->firstOrFail();
        $conRh = $this->cliente('esencial', [$rh->id]);
        $this->assertTrue(User::where('empresa_id', $conRh->empresa_id)->firstOrFail()->can('colaboradores.datos_personales'));
        // Empresa sin suscripción: sin límites
        $libre = $this->crearEmpresa('Hotel Libre');
        $this->assertTrue($this->crearUsuario($libre, 'Administrador')->can('colaboradores.datos_personales'));
    }

    public function test_sincronizar_agrega_lo_indispensable_y_no_deja_quitar_lo_que_otro_necesita(): void
    {
        $s = $this->cliente('esencial');
        $rutas = Modulo::where('clave', 'rutas')->firstOrFail();
        $this->actingAs($this->sa)->post("/catalogo-comercial/clientes/{$s->id}/excepciones", ['tipo' => 'modulo_quitado', 'modulo_id' => $rutas->id, 'motivo' => 'No lo usan'])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'lo necesita Bitácora de transporte'));
        $this->assertContains('rutas', $this->activos((int) $s->empresa_id));

        // Plan a la medida con solo Bitácora de transporte: Rutas se agrega sola
        $producto = Producto::create(['clave' => 'solo_transporte', 'nombre' => 'Transporte']);
        $producto->modulos()->sync([Modulo::where('clave', 'transporte')->value('id')]);
        $plan = Plan::create(['nombre' => 'Medida', 'slug' => 'medida', 'precio_por_sede_mensual' => 100]);
        $plan->productos()->sync([$producto->id]);
        $s->forceFill(['plan_id' => $plan->id])->save();
        $r = app(ModulosEfectivos::class)->sincronizar($s->fresh());
        $this->assertSame('transporte', $r['agregados']['rutas']);
        $this->assertContains('rutas', $this->activos((int) $s->empresa_id));
    }

    public function test_al_bajar_de_plan_los_datos_quedan_en_solo_lectura_con_aviso_y_las_excepciones_se_conservan(): void
    {
        $s = $this->cliente('profesional');
        $admin = User::where('empresa_id', $s->empresa_id)->firstOrFail();
        $vehiculos = Modulo::where('clave', 'vehiculos')->firstOrFail();
        $robo = Modulo::where('clave', 'robo')->firstOrFail();
        $ap = app(Aprovisionamiento::class);
        $ap->agregarExcepcion($s, $this->sa, 'modulo_quitado', null, $vehiculos->id, 'No lo usan');
        $this->assertTrue($admin->can('novedades.crear'));

        $ap->cambiarPlan($s->fresh(), Plan::where('slug', 'esencial')->first(), 1, [], 'mensual', $this->sa, 'Bajó de plan');
        app(Autorizador::class)->olvidar();
        $empresaId = (int) $s->empresa_id;
        // Lo que el plan ya no trae: solo lectura (se consulta, exporta e imprime; no se captura)
        $this->assertDatabaseHas('empresa_modulos', ['empresa_id' => $empresaId, 'modulo_id' => Modulo::where('clave', 'novedades')->value('id'), 'activo' => false, 'solo_lectura' => true]);
        $this->assertTrue($admin->can('novedades.ver'));
        $this->assertTrue($admin->can('novedades.exportar'));
        $this->assertFalse($admin->can('novedades.crear'));
        $this->assertTrue($admin->can('robo.ver')); // submódulo en solo lectura con su padre
        $this->assertFalse($admin->can('robo.crear'));
        $this->assertTrue(app(Autorizador::class)->moduloEnSoloLectura($empresaId, 'novedades'));
        $this->assertFalse(app(Autorizador::class)->moduloActivo($empresaId, 'novedades'));
        // Lo quitado a propósito sigue apagado del todo y su excepción no se borró (A-09)
        $this->assertFalse($admin->can('vehiculos.ver'));
        $this->assertTrue(SuscripcionExcepcion::where('modulo_id', $vehiculos->id)->where('activo', true)->exists());
        // Aviso al administrador
        $this->assertTrue(Notificacion::withoutGlobalScopes()->where('user_id', $admin->id)->where('titulo', 'Algunos módulos quedaron en solo consulta')->exists());
        $admin->forceFill(['debe_cambiar_contrasena' => false])->save();
        $this->actingAs($admin)->get('/novedades')->assertOk();

        // Volver a subir de plan: se captura otra vez
        $ap->cambiarPlan($s->fresh(), Plan::where('slug', 'profesional')->first(), 1, [], 'mensual', $this->sa, 'Subió de plan');
        app(Autorizador::class)->olvidar();
        $this->assertTrue($admin->can('novedades.crear'));
        $this->assertDatabaseHas('empresa_modulos', ['empresa_id' => $empresaId, 'modulo_id' => $robo->id, 'activo' => true, 'solo_lectura' => false]);
    }

    public function test_en_solo_consulta_se_ocultan_las_acciones_de_alta_y_queda_pedir_la_renovacion(): void
    {
        $s = $this->cliente('esencial');
        $admin = User::where('empresa_id', $s->empresa_id)->firstOrFail();
        $this->assertTrue($admin->can('usuarios.crear'));
        app(Aprovisionamiento::class)->suspender($s, $this->sa, 'Falta de pago');
        $this->assertTrue($admin->can('usuarios.ver'));
        $this->assertTrue($admin->can('accesos.exportar'));
        $this->assertFalse($admin->can('usuarios.crear'));
        $this->assertFalse($admin->can('accesos.crear'));
        $this->assertTrue($admin->can('empresas.editar')); // «Tu suscripción»: pedir la renovación
        $admin->forceFill(['debe_cambiar_contrasena' => false])->save();
        $this->actingAs($admin)->get('/usuarios')->assertOk()->assertDontSee('Nuevo Usuario');
    }

    public function test_empresas_modulos_es_solo_consulta_con_suscripcion_y_agrega_dependencias_sin_ella(): void
    {
        $s = $this->cliente('esencial');
        $empresa = Empresa::findOrFail($s->empresa_id);
        $this->actingAs($this->sa)->put("/empresas/{$empresa->id}/modulos", ['modulos' => []])
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'sus módulos los fijan su plan y sus excepciones'));
        $this->assertContains('accesos', $this->activos($empresa->id));
        $this->actingAs($this->sa)->get('/empresas')->assertOk()->assertSee('Cambiar por excepción en su ficha de cliente');

        $libre = $this->crearEmpresa('Sin plan');
        $this->actingAs($this->sa)->put("/empresas/{$libre->id}/modulos", ['modulos' => [Modulo::where('clave', 'transporte')->value('id')]])
            ->assertSessionHas('ok', fn ($m) => str_contains($m, 'Se agregó Rutas de transporte porque Bitácora de transporte lo necesita.'));
        $activos = $this->activos($libre->id);
        $this->assertContains('rutas', $activos);
        $this->assertContains('colaboradores', $activos); // Dirección siempre
        $this->assertNotContains('novedades', $activos);
    }
}
