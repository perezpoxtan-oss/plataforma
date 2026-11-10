<?php

namespace Tests\Feature\Seguridad;

use App\Models\Acceso;
use App\Models\Auditoria;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Empresa;
use App\Models\Gafete;
use App\Models\Notificacion;
use App\Models\Persona;
use App\Models\Proveedor;
use App\Models\Sede;
use App\Models\TipoGafete;
use App\Models\User;
use App\Models\Vehiculo;
use App\Models\VoucherReposicion;
use App\Models\ZonaEstacionamiento;
use App\Services\Activos\CustodiaActivos;
use App\Services\Estacionamientos\OcupacionEstacionamientos;
use App\Support\EstadoActivo;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Etapa 2 en la caseta: C-15 (¿devolvió el gafete?), C-13 (dar de baja frente
 * a restringir acceso; parecidos al guardar) y C-21 (Liberar lugar, zona
 * llena, salida temporal), con sus vínculos: Bitácora de accesos, Gafetes,
 * Vouchers, Personas, Empresas externas, Vehículos, Estacionamientos,
 * Autorizaciones y la campana.
 */
class EtapaDosCasetaTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private Sede $playa;

    private User $admin;

    private User $agente;

    private User $jefe;

    private Colaborador $host;

    private int $consecutivo = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        // Proveedores y contratistas sin esperar al departamento: aquí se prueba el padrón, no la autorización
        $this->empresa->forceFill(['preferencias' => ['recepcion' => ['proveedores_requieren_autorizacion' => false, 'contratistas_requieren_autorizacion' => false]]])->save();
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->playa = $this->crearSede($this->empresa, 'PLA');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        $this->host = $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => '2001', 'nombre' => 'Carlos', 'apellido_paterno' => 'Host', 'sede_id' => $this->centro->id]));
    }

    private function enEmpresa(callable $fn): mixed
    {
        return app(Tenant::class)->conEmpresa($this->empresa->id, $fn);
    }

    private function gafete(): Gafete
    {
        $n = ++$this->consecutivo;

        return $this->enEmpresa(fn () => Gafete::create(['sede_id' => $this->centro->id, 'tipo_gafete_id' => TipoGafete::firstOrCreate(['nombre' => 'Visitante'])->id,
            'nomenclatura' => 'HOT-CEN-VIS-'.str_pad((string) $n, 3, '0', STR_PAD_LEFT), 'consecutivo' => $n]));
    }

    private function registrar(array $datos, ?User $actor = null): TestResponse
    {
        if (($datos['tipo'] ?? null) === 'visitante') {
            $datos += ['motivo_visita' => 'general', 'persona_visita' => 'Recepción'];
        }

        return $this->actingAs($actor ?? $this->agente)->post('/accesos', $datos + ['sede_id' => $this->centro->id, '_dialogo' => 'ingreso']);
    }

    private function proveedorDatos(string $empresa, array $extra = []): array
    {
        return ['tipo' => 'proveedor', 'nombre' => 'Jesús Balam', 'empresa_procedencia' => $empresa, 'host_colaborador_id' => $this->host->id] + $extra;
    }

    private function ultimo(): Acceso
    {
        return $this->enEmpresa(fn () => Acceso::with('acompanantes')->orderByDesc('id')->firstOrFail());
    }

    // ============================================================ C-15

    public function test_devolvio_el_gafete_viene_marcado_y_desmarcarlo_lo_deja_no_regreso(): void
    {
        $g1 = $this->gafete();
        $g2 = $this->gafete();
        $this->registrar(['tipo' => 'visitante', 'nombre' => 'Laura Gómez', 'gafete_id' => $g1->id, 'acompanantes' => [['nombre' => 'Pedro', 'gafete_id' => $g2->id]]])->assertSessionHasNoErrors();
        $a = $this->ultimo();

        // La tarjeta abre el diálogo con las casillas (mismos clics que antes)
        $this->actingAs($this->agente)->get('/accesos')->assertSee('data-dar-salida=', false)->assertSee('&quot;gafete_id&quot;:'.$g1->id, false);

        // Pedro no devolvió el suyo; a Laura no se le devolvió su identificación
        $this->actingAs($this->agente)->patch("/accesos/{$a->id}/salida", ['gafetes_revisados' => 1, 'devolvio' => [$g1->id], 'identificacion_revisada' => 1])
            ->assertSessionHas('ok', fn ($m) => str_contains($m, 'El gafete '.$g2->nomenclatura.' quedó como «No regresó»'));
        $this->assertSame([EstadoActivo::DISPONIBLE, EstadoActivo::NO_REGRESO], [$g1->fresh()->estado, $g2->fresh()->estado]);
        $a->refresh();
        $this->assertFalse($a->gafete_no_regreso);
        $this->assertFalse($a->identificacion_devuelta);
        $this->assertTrue($this->enEmpresa(fn () => $a->acompanantes()->first()->gafete_no_regreso));
        $this->assertTrue(Auditoria::where('evento', 'gafetes.no_regreso')->where('auditable_id', $g2->id)->exists());

        // Al supervisor le llega con «Dar de baja»; el voucher propone a quien lo tuvo
        $aviso = Notificacion::withoutGlobalScopes()->where('user_id', $this->jefe->id)->where('tipo', CustodiaActivos::TIPO_AVISO)->firstOrFail();
        $this->assertSame('No regresó: gafete '.$g2->nomenclatura, $aviso->titulo);
        $this->actingAs($this->agente)->get('/accesos?pestana=historial')->assertSee('No devolvió: '.$g2->nomenclatura)->assertSee('ID quedó en caseta');

        // No se vuelve a prestar
        $this->registrar(['tipo' => 'visitante', 'nombre' => 'Otra Persona', 'gafete_id' => $g2->id])
            ->assertSessionHasErrors(['gafete_id' => 'El gafete '.$g2->nomenclatura.' está marcado «No regresó». Si ya lo devolvieron, recíbelo en Gafetes; si no, elige otro.']);
        $this->actingAs($this->jefe)->get('/gafetes')->assertSee('NO REGRESÓ')->assertSee('Último préstamo: PEDRO (acompañante de LAURA GÓMEZ)');

        // Lo devolvieron después: la caseta lo recibe
        $this->actingAs($this->agente)->patch("/gafetes/{$g2->id}/recibir")->assertSessionHas('ok');
        $this->assertSame(EstadoActivo::DISPONIBLE, $g2->fresh()->estado);
        $this->assertNull(Notificacion::withoutGlobalScopes()->find($aviso->id)->acciones);
    }

    public function test_sin_las_casillas_la_salida_es_como_siempre_y_la_baja_se_carga_a_la_persona(): void
    {
        $g = $this->gafete();
        $this->registrar($this->proveedorDatos('Abarrotes del Caribe', ['gafete_id' => $g->id]))->assertSessionHasNoErrors();
        $a = $this->ultimo();
        $this->actingAs($this->jefe)->patch("/accesos/{$a->id}/autorizar");
        $this->actingAs($this->agente)->patch("/accesos/{$a->id}/salida", ['gafetes_revisados' => 1])->assertSessionHas('ok');
        $this->assertTrue($g->fresh()->noRegreso());

        // La ficha del gafete propone a la persona del padrón (con su empresa)
        $this->actingAs($this->jefe)->get('/gafetes')->assertSee('&quot;tipo&quot;:&quot;persona&quot;', false);
        $persona = $this->enEmpresa(fn () => Persona::firstOrFail());
        $this->actingAs($this->jefe)->post("/gafetes/{$g->id}/baja", ['motivo' => 'extraviado', 'aplica_cobro' => 1, 'monto' => 80,
            'responsable_tipo' => 'persona', 'responsable_id' => $persona->id])->assertSessionHasNoErrors();
        $v = $this->enEmpresa(fn () => VoucherReposicion::firstOrFail());
        $this->assertSame(['persona', $persona->id], [$v->responsable_tipo, $v->responsable_id]);
        $this->assertStringContainsString('Abarrotes del Caribe', (string) $v->nombreResponsable());

        // Otra salida sin los campos (pantallas de antes): se da por devuelto
        $g2 = $this->gafete();
        $this->registrar(['tipo' => 'visitante', 'nombre' => 'Sin Casillas', 'gafete_id' => $g2->id])->assertSessionHasNoErrors();
        $this->actingAs($this->agente)->patch('/accesos/'.$this->ultimo()->id.'/salida')->assertSessionHas('ok');
        $this->assertSame(EstadoActivo::DISPONIBLE, $g2->fresh()->estado);
    }

    // ============================================================ C-13

    /** Prueba de B: empresa vetada (restringida) registrada con un nombre ligeramente distinto. */
    public function test_empresa_restringida_con_otro_nombre_se_detiene_al_guardar(): void
    {
        $vetada = $this->enEmpresa(fn () => Proveedor::create(['nombre' => 'Fletes Rápidos del Sureste', 'categoria' => 'transportadora', 'todas_las_sedes' => true]));
        $this->actingAs($this->admin)->patch("/restriccion/proveedores/{$vetada->id}", ['motivo' => 'Robo de mercancía', 'hasta' => ''])->assertSessionHas('aviso');
        $this->assertTrue($vetada->fresh()->restringido());

        // Mismo registro: no pasa
        $this->registrar($this->proveedorDatos('Fletes Rápidos del Sureste'))
            ->assertSessionHasErrors(['empresa_procedencia' => 'La empresa «Fletes Rápidos del Sureste» no puede ingresar: Acceso restringido (sin fecha de fin): Robo de mercancía. Llama a tu supervisor.']);
        // Otro nombre parecido: se detiene con «¿Es la misma?»
        $this->registrar($this->proveedorDatos('Fletes Rapidos del Sureste SA de CV'))
            ->assertSessionHasErrors(['parecido_restringido' => 'La empresa «Fletes Rapidos del Sureste SA de CV» se parece a «Fletes Rápidos del Sureste», que tiene el acceso restringido (Acceso restringido (sin fecha de fin): Robo de mercancía). ¿Es la misma?']);
        $this->assertSame(0, $this->enEmpresa(fn () => Acceso::count()));
        $this->registrar($this->proveedorDatos('Fletes Rapidos del Sureste SA de CV', ['restriccion_decision' => 'misma']))->assertSessionHasErrors('empresa_procedencia');
        $this->assertSame(1, $this->enEmpresa(fn () => Proveedor::count()));

        // Es otra: sigue, y quien autoriza lo ve en la tarjeta
        $this->registrar($this->proveedorDatos('Fletes Rapidos del Sureste SA de CV', ['restriccion_decision' => 'distinta']))->assertSessionHasNoErrors();
        $a = $this->ultimo();
        $this->assertSame('Parecida a una empresa restringida («Fletes Rápidos del Sureste») · Empresa nueva, pendiente de verificar', $a->aviso_padron);
        $this->actingAs($this->agente)->get('/accesos?pestana=pendientes')->assertSee('Parecida a una empresa restringida');
    }

    public function test_la_baja_de_una_empresa_ya_no_veta_y_la_caseta_la_reactiva(): void
    {
        $cerrada = $this->enEmpresa(fn () => Proveedor::create(['nombre' => 'Pinturas del Golfo', 'categoria' => 'proveedor', 'todas_las_sedes' => false]));
        $this->enEmpresa(fn () => $cerrada->sedes()->sync([$this->playa->id]));
        $this->actingAs($this->admin)->patch("/proveedores/{$cerrada->id}/estado", ['activo' => 0])->assertSessionHasNoErrors();
        $this->registrar($this->proveedorDatos('Pinturas del Golfo'))->assertSessionHasErrors('reactivar');
        $this->registrar($this->proveedorDatos('Pinturas del Golfo', ['reactivar_decision' => 'si']))->assertSessionHasNoErrors();
        $this->assertTrue($cerrada->fresh()->activo);
        // B-16: no opera en esta sede → quien autoriza lo sabe
        $this->assertSame('La empresa no está dada de alta en esta sede', $this->ultimo()->aviso_padron);
    }

    /** Prueba de B: persona dada de baja (ya no se duplica) y persona restringida. */
    public function test_persona_dada_de_baja_se_reactiva_y_la_restringida_no_pasa(): void
    {
        $ricardo = $this->enEmpresa(fn () => Persona::create(['tipo' => 'visitante', 'nombre_completo' => 'Ricardo Ortega Vela']));
        $this->actingAs($this->admin)->patch("/personas/{$ricardo->id}/estado", ['activo' => 0]);
        $this->assertFalse($ricardo->fresh()->activo);

        $this->registrar(['tipo' => 'visitante', 'nombre' => 'Ricardo Ortega Vela'])
            ->assertSessionHasErrors(['persona_repetida' => '«Ricardo Ortega Vela» está dada de baja en el Padrón de personas. ¿Es la misma? Si es la misma, se reactiva al guardar.']);
        $this->registrar(['tipo' => 'visitante', 'nombre' => 'Ricardo Ortega Vela', 'persona_decision' => 'misma'])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->enEmpresa(fn () => Persona::count()), 'No se duplica');
        $this->assertTrue($ricardo->fresh()->activo);
        $this->assertSame($ricardo->id, $this->ultimo()->persona_id);

        // Restringida: también con un nombre parecido y como acompañante
        $this->actingAs($this->admin)->patch("/restriccion/personas/{$ricardo->id}", ['motivo' => 'Agresión al personal']);
        $this->registrar(['tipo' => 'visitante', 'nombre' => 'Ricardo Ortega Vela', 'persona_id' => $ricardo->id])->assertSessionHasErrors('nombre');
        $this->registrar(['tipo' => 'visitante', 'nombre' => 'Ricardo Ortega'])->assertSessionHasErrors('parecido_restringido');
        $this->registrar(['tipo' => 'visitante', 'nombre' => 'Ana Ruiz', 'acompanantes' => [['nombre' => 'ricardo ortega vela']]])->assertSessionHasErrors('parecido_restringido');
        $this->registrar(['tipo' => 'visitante', 'nombre' => 'Ricardo Ortega', 'restriccion_decision' => 'misma'])->assertSessionHasErrors('nombre');
        // Quitar la restricción
        $this->actingAs($this->admin)->patch("/restriccion/personas/{$ricardo->id}", ['accion' => 'quitar'])->assertSessionHas('ok');
        $this->registrar(['tipo' => 'visitante', 'nombre' => 'Ricardo Ortega Vela', 'persona_id' => $ricardo->id])->assertSessionHasNoErrors();
        $this->assertTrue(Auditoria::where('evento', 'visitantes.restringido')->exists() && Auditoria::where('evento', 'visitantes.restriccion_quitada')->exists());
        // El agente no restringe
        $this->actingAs($this->agente)->patch("/restriccion/personas/{$ricardo->id}", ['motivo' => 'x'])->assertForbidden();
    }

    /** Prueba de B: vehículo dado de baja, restringido o de un colaborador dado de baja. */
    public function test_vehiculo_dado_de_baja_restringido_o_de_un_dueno_de_baja(): void
    {
        $taxi = $this->enEmpresa(fn () => Vehiculo::create(['placas' => 'TX2301', 'tipo' => 'sedan', 'propiedad' => 'empresa']));
        $this->actingAs($this->admin)->patch("/vehiculos/{$taxi->id}/estado", ['activo' => 0]);
        $datos = ['tipo' => 'visitante', 'nombre' => 'Chofer', 'modo_arribo' => 'auto', 'placas' => 'tx-2301'];
        $this->registrar($datos)->assertSessionHasErrors(['reactivar' => 'El vehículo TX2301 está dado de baja en el Padrón vehicular. ¿Es el mismo y vuelve a entrar? Si continúas, se reactiva.']);
        $this->registrar($datos + ['reactivar_decision' => 'si'])->assertSessionHasNoErrors();
        $this->assertTrue($taxi->fresh()->activo);

        $this->actingAs($this->admin)->patch("/restriccion/vehiculos/{$taxi->id}", ['motivo' => 'Reporte de robo', 'hasta' => now()->addDays(5)->format('Y-m-d')]);
        $this->registrar(['nombre' => 'Otro'] + $datos + ['reactivar_decision' => 'si'])->assertSessionHasErrors('placas');

        $ex = $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => '99', 'nombre' => 'Ex', 'apellido_paterno' => 'Empleado', 'activo' => false]));
        $this->enEmpresa(fn () => Vehiculo::create(['placas' => 'EXE111', 'tipo' => 'sedan', 'propiedad' => 'colaborador', 'colaborador_id' => $ex->id]));
        $this->registrar(['tipo' => 'visitante', 'nombre' => 'Visita', 'modo_arribo' => 'auto', 'placas' => 'EXE111'])
            ->assertSessionHasErrors(['reactivar' => 'El vehículo EXE111 es de un colaborador dado de baja (Ex Empleado). ¿Lo dejas pasar?']);
    }

    public function test_migracion_convierte_las_empresas_de_baja_en_restringidas(): void
    {
        $migracion = require database_path('migrations/2026_11_11_000500_restringir_acceso_en_padrones.php');
        $migracion->down();
        $vetada = $this->enEmpresa(fn () => Proveedor::create(['nombre' => 'Vetada', 'categoria' => 'proveedor']));
        \DB::table('proveedores')->where('id', $vetada->id)->update(['activo' => false]);
        $migracion->up();
        $fila = \DB::table('proveedores')->where('id', $vetada->id)->first();
        $this->assertTrue((bool) $fila->activo);
        $this->assertNotNull($fila->restringido_en);
        $migracion->down();
        $this->assertFalse((bool) \DB::table('proveedores')->where('id', $vetada->id)->value('activo'));
        $migracion->up();
    }

    // ============================================================ C-21

    /** Prueba de B: «Dar salida» en Estacionamientos ya no cierra la visita. */
    public function test_liberar_lugar_zona_llena_y_salida_temporal(): void
    {
        $zona = $this->enEmpresa(fn () => ZonaEstacionamiento::create(['sede_id' => $this->centro->id, 'nombre' => 'Sótano', 'tipo' => 'estacionamiento', 'cupo_total' => 1]));
        $this->registrar($this->proveedorDatos('Abarrotes del Caribe', ['modo_arribo' => 'auto', 'placas' => 'AAA111', 'zona_estacionamiento_id' => $zona->id]))->assertSessionHasNoErrors();
        $pendiente = $this->ultimo();
        $this->assertSame('pendiente', $pendiente->estado);
        // Los pendientes con vehículo ya ocupan su lugar
        $this->assertSame([$zona->id => 1], $this->enEmpresa(fn () => app(OcupacionEstacionamientos::class)->ocupados([$zona->id])));

        $this->registrar(['tipo' => 'visitante', 'nombre' => 'Lleno', 'modo_arribo' => 'auto', 'placas' => 'BBB222', 'zona_estacionamiento_id' => $zona->id])
            ->assertSessionHasErrors(['zona_llena' => 'La zona «Sótano» está llena (1/1). ¿La envías de todos modos?']);
        $this->registrar(['tipo' => 'visitante', 'nombre' => 'Lleno', 'modo_arribo' => 'auto', 'placas' => 'BBB222', 'zona_estacionamiento_id' => $zona->id, 'zona_llena' => 'si'])->assertSessionHasNoErrors();

        $this->actingAs($this->jefe)->patch("/accesos/{$pendiente->id}/autorizar");
        $this->actingAs($this->agente)->post("/accesos/{$pendiente->id}/salida-temporal", ['libera_lugar' => 1])->assertSessionHas('ok');
        $this->assertNull($pendiente->fresh()->zona_estacionamiento_id);
        $this->assertSame('en_sitio', $pendiente->fresh()->estado);
    }
}
