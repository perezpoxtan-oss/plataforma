<?php

namespace Tests\Feature\Seguridad;

use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Proveedor;
use App\Models\Sede;
use App\Models\User;
use App\Models\Vehiculo;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Mapa de procesos (decisión del dueño): las ocho categorías del Padrón
 * Vehicular se funden en «Particular» y «De una empresa»; y el «¿Es alguno
 * de estos?» del registro rápido pide doble confirmación antes de crear.
 */
class DosCategoriasVehiculosTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $sede;

    private User $admin;

    private User $agente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->sede = $this->crearSede($this->empresa, 'CEN');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->agente = $this->crearUsuario($this->empresa, 'Agente', $this->sede);
    }

    private function enEmpresa(callable $fn): mixed
    {
        return app(Tenant::class)->conEmpresa($this->empresa->id, $fn);
    }

    private function proveedor(string $nombre, string $categoria = 'taxi'): Proveedor
    {
        return $this->enEmpresa(fn () => Proveedor::create(['nombre' => $nombre, 'categoria' => $categoria, 'activo' => true, 'todas_las_sedes' => true]));
    }

    private function datos(array $extra = []): array
    {
        return array_merge(['placas' => 'abc-123-a', 'propiedad' => 'particular', 'tipo' => 'sedan', 'marca' => 'nissan', 'color' => 'blanco'], $extra);
    }

    private function migracion(): object
    {
        return require database_path('migrations/2026_10_29_000310_fundir_categorias_de_vehiculos.php');
    }

    // ------------------------------------------------------------ Conversión

    public function test_la_conversion_funde_las_ocho_categorias_y_se_revierte_exacta(): void
    {
        $migracion = $this->migracion();
        $migracion->down();

        $anteriores = array_keys(Vehiculo::CATEGORIAS_ANTERIORES);
        foreach ($anteriores as $i => $categoria) {
            DB::table('vehiculos')->insert(['empresa_id' => $this->empresa->id, 'placas' => 'V'.$i, 'propiedad' => $categoria, 'codigo_qr' => 'qr'.$i]);
        }

        $migracion->up();
        $convertidos = DB::table('vehiculos')->orderBy('id')->get(['propiedad', 'categoria_anterior']);
        $this->assertSame(array_values(Vehiculo::CATEGORIAS_ANTERIORES), $convertidos->pluck('propiedad')->all());
        $this->assertSame($anteriores, $convertidos->pluck('categoria_anterior')->all());
        $migracion->up(); // segunda vez: sin cambios
        $this->assertSame(array_values(Vehiculo::CATEGORIAS_ANTERIORES), DB::table('vehiculos')->orderBy('id')->pluck('propiedad')->all());

        // Dados de alta después: sin categoría anterior, regresan a la más cercana
        $colaborador = $this->enEmpresa(fn () => Colaborador::create(['nombre' => 'Ana', 'apellido_paterno' => 'López', 'activo' => true]));
        $this->enEmpresa(function () use ($colaborador) {
            Vehiculo::create(['placas' => 'NUEVO1', 'propiedad' => 'particular', 'marca' => 'KIA', 'color' => 'ROJO']);
            Vehiculo::create(['placas' => 'NUEVO2', 'propiedad' => 'particular', 'colaborador_id' => $colaborador->id, 'marca' => 'KIA', 'color' => 'ROJO']);
            Vehiculo::create(['placas' => 'NUEVO3', 'propiedad' => 'empresa', 'marca' => 'KIA', 'color' => 'ROJO']);
        });

        $migracion->down();
        $this->assertSame([...$anteriores, 'propio_visitante', 'propio_colaborador', 'empresa_proveedor'], DB::table('vehiculos')->orderBy('id')->pluck('propiedad')->all());
        $migracion->up();
    }

    public function test_si_cambia_de_categoria_ya_no_regresa_a_la_anterior(): void
    {
        $v = $this->enEmpresa(fn () => Vehiculo::create(['placas' => 'TX1', 'propiedad' => 'empresa', 'marca' => 'NISSAN', 'color' => 'BLANCO']));
        DB::table('vehiculos')->where('id', $v->id)->update(['categoria_anterior' => 'taxi_app']);

        $this->actingAs($this->admin)->put("/vehiculos/{$v->id}", $this->datos(['placas' => 'TX1', 'color' => 'rojo']))->assertSessionHasNoErrors();
        $this->assertNull(DB::table('vehiculos')->where('id', $v->id)->value('categoria_anterior'));
    }

    // ------------------------------------------------------------ Reglas

    public function test_de_una_empresa_pide_empresa_y_numero_economico_particular_permite_colaborador(): void
    {
        $taxis = $this->proveedor('Taxis del Centro');

        $this->actingAs($this->admin)->post('/vehiculos', $this->datos(['propiedad' => 'empresa']))
            ->assertSessionHasErrors(['proveedor_id', 'numero_economico']);
        $this->actingAs($this->admin)->post('/vehiculos', $this->datos(['propiedad' => 'empresa', 'proveedor_id' => $taxis->id, 'numero_economico' => 't-45']))
            ->assertSessionHasNoErrors();
        $v = $this->enEmpresa(fn () => Vehiculo::where('placas', 'ABC123A')->first());
        $this->assertSame(['empresa', $taxis->id, 'T-45', null], [$v->propiedad, $v->proveedor_id, $v->numero_economico, $v->colaborador_id]);

        // Particular: no pide empresa; la empresa que llegue no se guarda
        $this->actingAs($this->admin)->post('/vehiculos', $this->datos(['placas' => 'PAR1', 'proveedor_id' => $taxis->id]))->assertSessionHasNoErrors();
        $this->assertNull($this->enEmpresa(fn () => Vehiculo::where('placas', 'PAR1')->value('proveedor_id')));

        // Una clave vieja que llegue de una pantalla abierta antes se convierte
        $this->actingAs($this->admin)->post('/vehiculos', $this->datos(['placas' => 'VIEJA1', 'propiedad' => 'propio_huesped']))->assertSessionHasNoErrors();
        $this->assertSame('particular', $this->enEmpresa(fn () => Vehiculo::where('placas', 'VIEJA1')->value('propiedad')));

        $pagina = $this->actingAs($this->admin)->get('/vehiculos')->assertOk()->getContent();
        $this->assertStringContainsString('Particular (de un colaborador, huésped, visitante, familiar…)', $pagina);
        $this->assertStringContainsString('De una empresa (flotilla, agencia, taxi, transporte…)', $pagina);
        $this->assertStringContainsString('Colaborador (dueño del vehículo)', $pagina);
        $this->assertStringContainsString('data-requerido-si=\'{"propiedad":'.e(json_encode(['empresa'])).'}\'', $pagina);
    }

    public function test_registro_rapido_de_la_caseta_con_empresa_pide_numero_economico(): void
    {
        $taxis = $this->proveedor('Taxis del Centro');

        $this->actingAs($this->agente)->postJson('/vehiculos/rapido', $this->datos(['propiedad' => 'empresa', 'proveedor_id' => $taxis->id, 'origen' => 'accesos']))
            ->assertStatus(422)->assertJsonPath('errores.numero_economico.0', 'Un vehículo de una empresa lleva su número económico (el que trae pintado la unidad).');
        $this->actingAs($this->agente)->postJson('/vehiculos/rapido', $this->datos([
            'propiedad' => 'empresa', 'proveedor_id' => $taxis->id, 'numero_economico' => 'T-9', 'origen' => 'accesos', 'confirmar_nuevo' => 1,
        ]))->assertCreated()->assertJsonPath('vehiculo.propiedad_etiqueta', 'De una empresa');

        // El diálogo de registro rápido trae el número económico y «Particular» por omisión
        $this->actingAs($this->admin);
        $rapido = $this->enEmpresa(fn () => view('seguridad.vehiculos._registro-rapido')->render());
        $this->assertStringContainsString('id="dialogoRegistroRapidoVehiculo"', $rapido);
        $this->assertStringContainsString('name="numero_economico"', $rapido);
        $this->assertMatchesRegularExpression('/<option value="particular"\s+selected\s+data-por-defecto\s*>/', $rapido);
    }

    public function test_verificar_un_alta_de_una_empresa_permite_completar_empresa_y_numero_economico(): void
    {
        $taxis = $this->proveedor('Taxis del Centro');
        $alta = $this->enEmpresa(function () {
            $v = Vehiculo::create(['placas' => 'TX230', 'propiedad' => 'empresa', 'tipo' => 'sedan', 'marca' => 'NISSAN', 'color' => 'BLANCO']);
            $v->forceFill(['verificacion' => 'pendiente', 'origen_alta' => 'transporte', 'sede_alta_id' => $this->sede->id, 'creado_por' => $this->agente->id])->save();

            return $v;
        });

        // El diálogo «Verificar Alta Pendiente» trae empresa propietaria y número económico
        $html = $this->actingAs($this->admin)->get('/vehiculos')->assertOk()->getContent();
        $this->assertStringContainsString('id="va_proveedor"', $html);
        $this->assertStringContainsString('id="va_economico"', $html);

        $datos = ['placas' => 'TX230', 'propiedad' => 'empresa', 'tipo' => 'sedan', 'marca' => 'nissan', 'color' => 'blanco'];
        $this->actingAs($this->admin)->putJson("/vehiculos/{$alta->id}/aceptar", $datos)->assertStatus(422);
        $this->actingAs($this->admin)->putJson("/vehiculos/{$alta->id}/aceptar", $datos + ['proveedor_id' => $taxis->id, 'numero_economico' => 't-230'])
            ->assertOk()->assertJson(['ok' => true]);
        $alta = $this->enEmpresa(fn () => $alta->fresh());
        $this->assertSame(['verificado', $taxis->id, 'T-230'], [$alta->verificacion, $alta->proveedor_id, $alta->numero_economico]);
    }

    // ------------------------------------------------------------ Doble confirmación

    public function test_no_es_ninguno_pide_doble_confirmacion_sin_javascript_en_linea(): void
    {
        $js = (string) file_get_contents(public_path('js/plataforma.js'));
        $this->assertStringContainsString("'No es ninguno: registrarlo como nuevo'", $js);
        $this->assertStringContainsString("'¿Seguro que no es ninguno de los ' + cuantos + ' parecidos? Se creará un registro nuevo pendiente de verificar.'", $js);
        $this->assertStringContainsString("'Sí, registrar nuevo'", $js);
        $this->assertStringContainsString("'Volver a revisar'", $js);

        // El registro solo se confirma desde «Sí, registrar nuevo»
        $inicio = strpos($js, 'function confirmacionNuevo(');
        $bloque = substr($js, $inicio, strpos($js, 'return seguro;', $inicio) - $inicio);
        $this->assertStringContainsString("confirmar(form, '1');", $bloque);
        $antes = substr($js, strpos($js, 'function mostrarParecidos(form, lista)'), $inicio - strpos($js, 'function mostrarParecidos(form, lista)'));
        $this->assertStringNotContainsString("confirmar(form, '1')", $antes);

        foreach (['/accesos', '/transporte'] as $ruta) {
            $html = $this->actingAs($this->agente)->get($ruta)->assertOk()->getContent();
            $this->assertDoesNotMatchRegularExpression('/\son(click|submit|change)=/i', $html);
        }
    }
}
