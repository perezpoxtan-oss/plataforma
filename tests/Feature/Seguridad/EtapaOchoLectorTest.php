<?php

namespace Tests\Feature\Seguridad;

use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Persona;
use App\Models\Proveedor;
use App\Models\Sede;
use App\Models\User;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Etapa 8 · C-44 (parte 1): personas del padrón y empresas externas se leen
 * con el lector universal (QR, NFC/RFID y, la empresa, su RFC). Vínculos:
 * lector, «Código e identificación», Etiquetas QR, fichas de Personas y
 * Empresas externas, y la regla única de etiqueta NFC entre todos los tipos.
 */
class EtapaOchoLectorTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private User $admin;

    private User $agente;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
    }

    private function enEmpresa(callable $fn, ?Empresa $empresa = null): mixed
    {
        return app(Tenant::class)->conEmpresa(($empresa ?? $this->empresa)->id, $fn);
    }

    private function proveedor(string $nombre = 'Limpieza Total', ?string $rfc = 'LTO010101AB1'): Proveedor
    {
        return $this->enEmpresa(fn () => Proveedor::create(['nombre' => $nombre, 'categoria' => 'contratista', 'rfc' => $rfc, 'todas_las_sedes' => true]));
    }

    private function persona(string $nombre = 'Ana Externa', ?Proveedor $proveedor = null): Persona
    {
        return $this->enEmpresa(fn () => Persona::create(['tipo' => $proveedor ? 'proveedor' : 'visitante', 'nombre_completo' => $nombre, 'proveedor_id' => $proveedor?->id,
            'tipo_identificacion' => 'ine', 'folio_identificacion' => 'ABCD123456']));
    }

    public function test_persona_y_empresa_externa_nacen_con_codigo_y_se_encuentran_por_qr_nfc_y_rfc(): void
    {
        $proveedor = $this->proveedor();
        $persona = $this->persona('Beto Técnico', $proveedor);
        $this->assertSame(24, strlen((string) $persona->codigo_qr));
        $this->assertSame(24, strlen((string) $proveedor->codigo_qr));

        // QR (dirección /e/{código})
        $this->actingAs($this->admin)->getJson('/lector/resolver?entrada='.urlencode(route('lector.ir', $persona->codigo_qr)))
            ->assertOk()->assertJsonPath('resultados.0.tipo', 'persona')->assertJsonPath('resultados.0.titulo', 'Beto Técnico')
            ->assertJsonPath('resultados.0.detalle', 'Proveedor · Limpieza Total');

        // Tarjeta NFC: se guarda normalizada y se encuentra en otro formato (decimal de 125 kHz)
        $this->enEmpresa(fn () => $proveedor->forceFill(['etiqueta_nfc' => '00:bc:61:4e'])->save());
        $this->assertSame('00BC614E', $proveedor->fresh()->etiqueta_nfc);
        $this->actingAs($this->admin)->getJson('/lector/resolver?entrada=0012345678&tipos=proveedor')
            ->assertJsonPath('resultados.0.id', $proveedor->id)->assertJsonPath('resultados.0.tipo', 'proveedor');

        // RFC tecleado o escaneado; el folio de la INE de la persona NO se busca (dato personal)
        $this->actingAs($this->admin)->getJson('/lector/resolver?entrada=LTO010101AB1')->assertJsonPath('resultados.0.titulo', 'Limpieza Total');
        $this->actingAs($this->admin)->getJson('/lector/resolver?entrada=ABCD123456&tipos=persona')->assertJsonCount(0, 'resultados');
    }

    public function test_solo_dentro_de_la_empresa_y_con_permiso_de_ver_el_padron(): void
    {
        $otra = $this->crearEmpresa('Hotel Dos');
        $ajena = $this->enEmpresa(fn () => Persona::create(['tipo' => 'visitante', 'nombre_completo' => 'De Otra Empresa']), $otra);
        $this->actingAs($this->admin)->getJson('/lector/resolver?entrada='.urlencode(route('lector.ir', $ajena->codigo_qr)))->assertJsonCount(0, 'resultados');
        $this->actingAs($this->admin)->get(route('lector.ir', $ajena->codigo_qr))->assertNotFound();

        // Sin visitantes.ver no aparece la persona aunque se lea su QR
        $propia = $this->persona();
        $sinPadron = $this->crearUsuario($this->empresa, 'Solicitante', $this->centro);
        $this->assertFalse($sinPadron->can('visitantes.ver'));
        $this->actingAs($sinPadron)->getJson('/lector/resolver?entrada='.urlencode(route('lector.ir', $propia->codigo_qr)))->assertJsonCount(0, 'resultados');
    }

    public function test_codigo_e_identificacion_asigna_la_tarjeta_unica_entre_todos_los_tipos(): void
    {
        $persona = $this->persona();
        $colaborador = $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => '501', 'nombre' => 'Carla', 'apellido_paterno' => 'Ruiz', 'sede_id' => $this->centro->id]));
        $this->enEmpresa(fn () => $colaborador->forceFill(['etiqueta_nfc' => 'AA11BB22'])->save());

        // La tarjeta de un colaborador no se le puede dar a una persona externa
        $this->actingAs($this->admin)->putJson(route('identificacion.etiqueta', ['persona', $persona->id]), ['etiqueta_nfc' => 'aa:11:bb:22'])
            ->assertStatus(422)->assertJsonValidationErrors('etiqueta_nfc');
        $this->assertStringContainsString('el colaborador', $this->actingAs($this->admin)
            ->putJson(route('identificacion.etiqueta', ['persona', $persona->id]), ['etiqueta_nfc' => 'AA11BB22'])->json('errors.etiqueta_nfc.0'));

        $this->actingAs($this->admin)->putJson(route('identificacion.etiqueta', ['persona', $persona->id]), ['etiqueta_nfc' => 'cc:33'])
            ->assertOk()->assertJsonPath('etiqueta', 'CC33');
        $this->assertDatabaseHas('auditoria', ['evento' => 'visitantes.etiqueta_asignada', 'auditable_id' => $persona->id]);

        // Y al revés: la de la persona ya no se le puede dar a una empresa externa
        $proveedor = $this->proveedor();
        $this->actingAs($this->admin)->putJson(route('identificacion.etiqueta', ['proveedor', $proveedor->id]), ['etiqueta_nfc' => 'CC33'])
            ->assertStatus(422);
        $this->assertStringContainsString('la persona', $this->actingAs($this->admin)
            ->putJson(route('identificacion.etiqueta', ['proveedor', $proveedor->id]), ['etiqueta_nfc' => 'CC33'])->json('errors.etiqueta_nfc.0'));

        // El Agente solo consulta el padrón: ve el QR pero no asigna tarjetas
        $this->actingAs($this->agente)->get(route('identificacion.qr', ['persona', $persona->id]))->assertOk();
        $this->actingAs($this->agente)->putJson(route('identificacion.etiqueta', ['persona', $persona->id]), ['etiqueta_nfc' => 'DD44'])->assertForbidden();
    }

    public function test_fichas_y_etiquetas_qr_los_ofrecen(): void
    {
        $proveedor = $this->proveedor();
        $persona = $this->persona('Ana Externa', $proveedor);

        $this->actingAs($this->admin)->get(route('personas.index'))->assertOk()
            ->assertSee('data-ver-identificacion', false)->assertSee('dialogoIdentificacion', false)->assertSee('Imprimir su etiqueta');
        $this->actingAs($this->admin)->get(route('proveedores.index'))->assertOk()
            ->assertSee('dialogoIdentificacion', false)->assertSee(route('identificacion.qr', ['proveedor', $proveedor->id]), false);
        // Quien solo consulta también abre el QR (sin asignar tarjeta)
        $this->actingAs($this->agente)->get(route('personas.index'))->assertOk()->assertSee(route('identificacion.qr', ['persona', $persona->id]), false);

        $this->actingAs($this->admin)->get(route('etiquetas.index', ['tipo' => 'persona']))->assertOk()->assertSee('Ana Externa')->assertSee('Personas externas');
        $this->actingAs($this->admin)->get(route('etiquetas.index', ['tipo' => 'proveedor']))->assertOk()->assertSee('Limpieza Total');
    }

    public function test_el_qr_fuera_de_un_formulario_abre_la_ficha(): void
    {
        $proveedor = $this->proveedor();
        $persona = $this->persona();
        // Sin acción probable (no hay nada en sitio ni permiso de caseta del administrador... sí lo tiene): se revisa en EtapaOchoAccionesLectorTest
        $this->actingAs($this->crearUsuario($this->empresa, 'Director'))->get(route('lector.ir', $persona->codigo_qr))
            ->assertRedirect(route('personas.index').'#persona-'.$persona->id);
        $this->actingAs($this->crearUsuario($this->empresa, 'Director'))->get(route('lector.ir', $proveedor->codigo_qr))
            ->assertRedirect(route('proveedores.show', $proveedor->id));
    }
}
