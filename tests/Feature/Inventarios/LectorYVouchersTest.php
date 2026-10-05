<?php

namespace Tests\Feature\Inventarios;

use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Vehiculo;
use App\Models\VoucherReposicion;
use App\Services\Inventarios\Vouchers;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

class LectorYVouchersTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->crearSede($this->empresa, 'CEN');
    }

    private function enEmpresa(callable $fn, ?Empresa $empresa = null): mixed
    {
        return app(Tenant::class)->conEmpresa(($empresa ?? $this->empresa)->id, $fn);
    }

    public function test_encuentra_por_etiqueta_rfid_en_cualquier_formato_por_codigo_y_por_texto(): void
    {
        $admin = $this->crearUsuario($this->empresa, 'Administrador');
        $ana = $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => '1001', 'nombre' => 'Ana', 'apellido_paterno' => 'López', 'etiqueta_nfc' => '00:bc:61:4e']));
        $auto = $this->enEmpresa(fn () => Vehiculo::create(['placas' => 'ABC123A', 'marca' => 'NISSAN', 'color' => 'BLANCO', 'tipo' => 'sedan']));
        $this->assertSame('00BC614E', $ana->fresh()->etiqueta_nfc);
        $this->assertSame(24, strlen($ana->codigo_qr));

        // Lector de 125 kHz que manda el número en decimal
        $this->actingAs($admin)->getJson('/lector/resolver?entrada=0012345678')->assertOk()
            ->assertJsonPath('resultados.0.tipo', 'colaborador')->assertJsonPath('resultados.0.id', $ana->id)
            ->assertJsonPath('resultados.0.titulo', 'Ana López');
        // QR de la calcomanía (dirección con el código) y placas tecleadas
        $this->actingAs($admin)->getJson('/lector/resolver?entrada='.urlencode(route('vehiculos.qr', $auto->codigo_qr)))->assertJsonPath('resultados.0.id', $auto->id);
        $this->actingAs($admin)->getJson('/lector/resolver?entrada=abc123a&tipos=vehiculo')->assertJsonPath('resultados.0.titulo', 'ABC123A');
        // Número de empleado, pero limitado a vehículos: nada
        $this->actingAs($admin)->getJson('/lector/resolver?entrada=1001&tipos=vehiculo')->assertJsonCount(0, 'resultados');
        $this->actingAs($admin)->getJson('/lector/resolver?entrada=1001&tipos=colaborador')->assertJsonPath('resultados.0.id', $ana->id);

        // /e/{codigo} lleva a la pantalla del registro
        $this->actingAs($admin)->get('/e/'.$ana->codigo_qr)->assertRedirect(route('colaboradores.index').'#colaborador-'.$ana->id);
        $this->actingAs($admin)->get('/e/noexiste12345678')->assertNotFound();
    }

    public function test_no_encuentra_registros_de_otra_empresa_ni_tipos_sin_permiso(): void
    {
        $otra = $this->crearEmpresa('Hotel Dos');
        $ajeno = $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => '9', 'nombre' => 'Otro', 'apellido_paterno' => 'X', 'etiqueta_nfc' => 'AABBCCDD']), $otra);

        $admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->actingAs($admin)->getJson('/lector/resolver?entrada=AABBCCDD')->assertJsonCount(0, 'resultados');
        $this->actingAs($admin)->get('/e/'.$ajeno->codigo_qr)->assertNotFound();

        $sinRol = $this->crearUsuario($this->empresa);
        $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => '5', 'nombre' => 'Luis', 'apellido_paterno' => 'Y', 'etiqueta_nfc' => '11223344']));
        $this->actingAs($sinRol)->getJson('/lector/resolver?entrada=11223344')->assertJsonCount(0, 'resultados');
        $this->actingAs($sinRol)->getJson('/lector/resolver?entrada=x&tipos=DROP;')->assertUnprocessable();
    }

    public function test_la_misma_etiqueta_no_se_repite_en_la_empresa(): void
    {
        $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => '1', 'nombre' => 'A', 'apellido_paterno' => 'B', 'etiqueta_nfc' => '04:A2:3B:1C']));
        $this->assertNotNull($this->enEmpresa(fn () => Colaborador::etiquetaOcupada('04a23b1c')));
        $this->assertNull($this->enEmpresa(fn () => Colaborador::etiquetaOcupada('')));
    }

    public function test_baja_con_voucher_folio_cobro_costo_sugerido_y_auditoria(): void
    {
        $admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->actingAs($admin);
        $vouchers = app(Vouchers::class);
        $responsable = $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => '7', 'nombre' => 'Eva', 'apellido_paterno' => 'Pérez']));
        // Cualquier modelo de la empresa sirve como origen en esta prueba
        $origen = $this->enEmpresa(fn () => Vehiculo::create(['placas' => 'X1', 'marca' => 'M', 'color' => 'C', 'tipo' => 'sedan']));

        try {
            $this->enEmpresa(fn () => $vouchers->validar(['motivo' => 'extraviado', 'aplica_cobro' => '1']));
            $this->fail('Con cobro deben exigirse monto y responsable.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('monto', $e->errors());
            $this->assertArrayHasKey('colaborador_id', $e->errors());
        }

        $voucher = $this->enEmpresa(function () use ($vouchers, $admin, $origen, $responsable) {
            $datos = $vouchers->validar(['motivo' => 'danado', 'descripcion' => 'Se rompió', 'aplica_cobro' => '1', 'monto' => '150', 'colaborador_id' => $responsable->id]);

            return $vouchers->darDeBaja($admin, $origen, 'gafete', 'HDM-CEN-VIS-001', $datos, fn () => $origen->update(['activo' => false]), 'Visitante');
        });

        $this->assertMatchesRegularExpression('/^VR-\d{4}-\d{5}$/', $voucher->folio);
        $this->assertSame('150.00', $voucher->monto);
        $this->assertFalse($origen->fresh()->activo);
        $this->assertSame('150.00', $this->enEmpresa(fn () => $vouchers->costoSugerido('gafete', 'Visitante')));
        $this->assertNull($this->enEmpresa(fn () => $vouchers->costoSugerido('gafete', 'Proveedor')));
        $this->assertDatabaseHas('auditoria', ['evento' => 'vouchers.creado', 'auditable_id' => $voucher->id]);

        // Si la baja falla, no queda voucher
        try {
            $this->enEmpresa(fn () => $vouchers->darDeBaja($admin, $origen, 'gafete', 'X', $vouchers->validar(['motivo' => 'robado']), fn () => throw new \RuntimeException('falla')));
        } catch (\RuntimeException) {
        }
        $this->assertSame(1, VoucherReposicion::withoutGlobalScopes()->count());
    }
}
