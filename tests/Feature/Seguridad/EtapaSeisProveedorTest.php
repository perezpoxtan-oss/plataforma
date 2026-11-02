<?php

namespace Tests\Feature\Seguridad;

use App\Models\Acceso;
use App\Models\ArticuloAcceso;
use App\Models\Auditoria;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Notificacion;
use App\Models\PaseSalida;
use App\Models\Proveedor;
use App\Models\Sede;
use App\Models\User;
use App\Services\Relojes\MotorRelojes;
use App\Support\Menu\MisPendientes;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Etapa 6 · C-19 (B-07, P-07, DD-18): lo que el proveedor trae al entrar (lista
 * corta y opcional) y la comparación al darle salida, con su canalización al
 * supervisor (campana, Mis pendientes, reloj) y el pase de su empresa.
 */
class EtapaSeisProveedorTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private User $admin;

    private User $agente;

    private User $jefe;

    private Colaborador $host;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->empresa->forceFill(['preferencias' => ['recepcion' => ['proveedores_requieren_autorizacion' => false, 'contratistas_requieren_autorizacion' => false]]])->save();
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        $this->host = $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => '2001', 'nombre' => 'Carlos', 'apellido_paterno' => 'Host', 'sede_id' => $this->centro->id]));
    }

    private function enEmpresa(callable $fn): mixed
    {
        return app(Tenant::class)->conEmpresa($this->empresa->id, $fn);
    }

    private function registrar(array $extra = []): TestResponse
    {
        $r = $this->actingAs($this->agente)->post('/accesos', $extra + ['tipo' => 'proveedor', 'nombre' => 'Jesús Balam', 'empresa_procedencia' => 'Elevadores del Sur',
            'host_colaborador_id' => $this->host->id, 'sede_id' => $this->centro->id, '_dialogo' => 'ingreso']);
        // La persona citada no tiene cuenta: el supervisor confirma su autorización (aquí se prueba la salida)
        $ultimo = $this->enEmpresa(fn () => Acceso::orderByDesc('id')->first());
        if ($ultimo?->estado === 'pendiente') {
            $this->actingAs($this->admin)->patch("/accesos/{$ultimo->id}/autorizar");
        }

        return $r;
    }

    private function ultimo(): Acceso
    {
        return $this->enEmpresa(fn () => Acceso::with('articulos')->orderByDesc('id')->firstOrFail());
    }

    public function test_sin_herramienta_no_hay_pasos_de_mas(): void
    {
        $form = $this->actingAs($this->agente)->get('/accesos')->assertOk()->getContent();
        // La lista va plegada y es opcional
        $this->assertMatchesRegularExpression('/<details class="mas-detalles trae-equipo" data-trae-equipo\s*>/u', $form);
        $this->assertStringContainsString('¿Trae herramienta o equipo?', $form);

        // Renglones vacíos (los del formulario) se ignoran
        $this->registrar(['articulos' => [['articulo' => '', 'cantidad' => '1', 'serie' => ''], ['articulo' => ' ', 'cantidad' => '1']]])->assertSessionHasNoErrors();
        $a = $this->ultimo();
        $this->assertCount(0, $a->articulos);
        // La salida no pide comparar nada (solo la identificación de siempre)
        $html = $this->actingAs($this->agente)->get('/accesos')->getContent();
        $this->assertStringContainsString('&quot;articulos&quot;:[],&quot;pase&quot;:null', $html);
        $this->assertStringNotContainsString('Trae:', $html);
        $this->actingAs($this->agente)->patch("/accesos/{$a->id}/salida")->assertSessionHas('ok', "Salida de {$a->nombre} registrada correctamente.");
        $this->assertNull($a->fresh()->articulos_salida);
    }

    public function test_proveedor_con_herramienta_comparada_al_entrar_y_al_salir_con_lo_mismo(): void
    {
        $this->registrar(['articulos' => [
            ['articulo' => ' taladro ', 'cantidad' => '2', 'serie' => 'dw-123'],
            ['articulo' => 'Escalera', 'cantidad' => '', 'serie' => ''],
        ]])->assertSessionHasNoErrors();
        $a = $this->ultimo();
        $this->assertSame(['2 × TALADRO (serie DW-123)', 'ESCALERA'], $a->articulos->map->texto()->all());
        $this->assertSame(['2 × TALADRO (DW-123)', 'ESCALERA'], Auditoria::where('evento', 'accesos.creado')->where('auditable_id', $a->id)->sole()->despues['trae']);

        // La tarjeta lo muestra y la salida abre el diálogo con la lista
        $html = $this->actingAs($this->agente)->get('/accesos')->assertOk()->getContent();
        $this->assertStringContainsString('Trae: 2 × TALADRO (serie DW-123), ESCALERA', $html);
        $this->assertStringContainsString('&quot;articulos&quot;:[&quot;2 \u00d7 TALADRO (serie DW-123)&quot;,&quot;ESCALERA&quot;]', $html);

        $this->actingAs($this->agente)->patch("/accesos/{$a->id}/salida", ['articulos_revisados' => 1, 'sale_con_lo_mismo' => 1])
            ->assertSessionHas('ok', "Salida de {$a->nombre} registrada correctamente.");
        $a->refresh();
        $this->assertSame('mismo', $a->articulos_salida);
        $this->assertFalse($a->articulos_por_revisar);
        $this->assertSame(0, Notificacion::withoutGlobalScopes()->where('tipo', 'acceso_articulos')->count());
        $this->actingAs($this->agente)->get('/accesos?pestana=historial')->assertSee('salió con lo mismo');
    }

    public function test_si_sale_con_algo_distinto_se_canaliza_al_supervisor_con_reloj_y_atender(): void
    {
        $this->registrar(['articulos' => [['articulo' => 'Taladro', 'cantidad' => '1']]])->assertSessionHasNoErrors();
        $a = $this->ultimo();

        $this->actingAs($this->agente)->patch("/accesos/{$a->id}/salida", ['articulos_revisados' => 1, 'articulos_nota' => 'Lleva también una escalera'])
            ->assertSessionHas('ok', fn ($m) => str_contains($m, 'ya se avisó a tu supervisor'));
        $a->refresh();
        $this->assertSame('finalizado', $a->estado);
        $this->assertSame('distinto', $a->articulos_salida);
        $this->assertSame('Lleva también una escalera', $a->articulos_nota);
        $this->assertTrue($a->articulos_por_revisar);

        // Campana del supervisor (jefe de seguridad), no del agente
        $aviso = Notificacion::withoutGlobalScopes()->where('user_id', $this->jefe->id)->where('tipo', 'acceso_articulos')->sole();
        $this->assertSame('Salió con algo distinto: JESÚS BALAM', $aviso->titulo);
        $this->assertStringContainsString('Lleva también una escalera', $aviso->texto);
        $this->assertSame('Atender', $aviso->acciones[0]['etiqueta']);
        $this->assertSame(0, Notificacion::withoutGlobalScopes()->where('user_id', $this->agente->id)->where('tipo', 'acceso_articulos')->count());

        // Mis pendientes y el reloj
        $this->assertArrayHasKey('articulos_caseta', app(MotorRelojes::class)->relojes());
        $pendientes = collect(app(MisPendientes::class)->para($this->jefe)['items'])->keyBy('clave');
        $this->assertSame(1, $pendientes['articulos_caseta']['total'] ?? null);
        $this->actingAs($this->jefe)->get('/accesos?pestana=historial&articulos=1')->assertOk()->assertSee('No salió con lo mismo que trajo: por revisar')->assertSee('JESÚS BALAM');

        // El agente no puede atenderlo; el jefe sí, una sola vez
        $this->actingAs($this->agente)->patch("/accesos/{$a->id}/revisar-articulos")->assertForbidden();
        $this->actingAs($this->jefe)->patch("/accesos/{$a->id}/revisar-articulos")->assertSessionHas('ok', 'Listo: la salida de JESÚS BALAM quedó revisada.');
        $this->assertNotNull($a->fresh()->articulos_revisado_en);
        $this->assertNull(Notificacion::withoutGlobalScopes()->find($aviso->id)->acciones);
        $this->actingAs($this->jefe)->patch("/accesos/{$a->id}/revisar-articulos")->assertSessionHas('error', 'Esta salida ya se había revisado.');
        $this->assertTrue(Auditoria::where('evento', 'accesos.articulos_revisados')->where('auditable_id', $a->id)->exists());
    }

    public function test_el_pase_de_salida_aprobado_de_su_empresa_se_muestra_y_se_liga(): void
    {
        $proveedor = $this->enEmpresa(fn () => Proveedor::create(['nombre' => 'ELEVADORES DEL SUR', 'categoria' => 'proveedor']));
        $paseId = $this->enEmpresa(fn () => DB::table('pases_salida')->insertGetId([
            'empresa_id' => $this->empresa->id, 'sede_id' => $this->centro->id, 'folio_numero' => 1, 'folio' => 'PS-00001', 'motivo' => 'reparacion', 'colaborador_id' => $this->host->id,
            'destino_tipo' => 'proveedor', 'proveedor_id' => $proveedor->id, 'estado' => PaseSalida::APROBADO, 'requiere_regreso' => true, 'created_at' => now(), 'updated_at' => now(),
        ]));
        $this->registrar(['proveedor_id' => $proveedor->id, 'empresa_procedencia' => 'ELEVADORES DEL SUR'])->assertSessionHasNoErrors();
        $a = $this->ultimo();
        $this->assertSame($proveedor->id, $a->proveedor_id);

        $html = $this->actingAs($this->agente)->get('/accesos')->assertOk()->getContent();
        $this->assertStringContainsString('&quot;folio&quot;:&quot;PS-00001&quot;', $html);

        $this->actingAs($this->agente)->patch("/accesos/{$a->id}/salida", ['pase_salida_id' => $paseId])->assertSessionHasNoErrors();
        $this->assertSame($paseId, $a->fresh()->pase_salida_id);
        // Otro pase (no el suyo) no se liga
        $this->registrar(['proveedor_id' => $proveedor->id, 'empresa_procedencia' => 'ELEVADORES DEL SUR', 'nombre' => 'Otro Técnico'])->assertSessionHasNoErrors();
        $b = $this->ultimo();
        $this->actingAs($this->agente)->patch("/accesos/{$b->id}/salida", ['pase_salida_id' => 999999])->assertSessionHasNoErrors();
        $this->assertNull($b->fresh()->pase_salida_id);
    }

    public function test_validaciones_y_solo_proveedor_o_contratista(): void
    {
        $filas = array_fill(0, ArticuloAcceso::MAXIMO + 1, ['articulo' => 'X', 'cantidad' => 1]);
        $this->registrar(['articulos' => $filas])->assertSessionHasErrors(['articulos' => 'La lista de lo que trae admite máximo 10 renglones.']);
        $this->registrar(['articulos' => [['articulo' => 'Taladro', 'cantidad' => '0']]])->assertSessionHasErrors('articulos.0.cantidad');

        // Una visita no guarda lista aunque llegue
        $this->actingAs($this->agente)->post('/accesos', ['tipo' => 'visitante', 'nombre' => 'Laura Gómez', 'motivo_visita' => 'general', 'persona_visita' => 'Recepción',
            'sede_id' => $this->centro->id, '_dialogo' => 'ingreso', 'articulos' => [['articulo' => 'Laptop']]])->assertSessionHasNoErrors();
        $this->assertCount(0, $this->ultimo()->articulos);
    }
}
