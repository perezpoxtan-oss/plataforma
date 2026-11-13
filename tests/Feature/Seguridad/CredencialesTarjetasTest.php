<?php

namespace Tests\Feature\Seguridad;

use App\Models\Colaborador;
use App\Models\Credencial;
use App\Models\Empresa;
use App\Models\Persona;
use App\Models\Sede;
use App\Models\User;
use App\Services\Activos\HistorialActivos;
use App\Support\AjustesCredenciales;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Decisión del dueño (3 nov): la credencial de fábrica es solo QR; cada
 * empresa puede habilitar la tarjeta de proximidad 125 kHz, la MIFARE
 * 13.56 MHz o las dos. Se guarda en el ajuste qué usa la empresa y en la
 * credencial qué tarjeta lleva; la ficha y la Configuración dicen qué lector
 * necesita cada una. Sin tarjetas habilitadas no se pide número de tarjeta.
 * La tarjeta sigue siendo única entre todos los tipos.
 */
class CredencialesTarjetasTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private User $admin;

    private User $rh;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->rh = $this->crearUsuario($this->empresa, 'Recursos Humanos');
    }

    protected function tearDown(): void
    {
        HistorialActivos::olvidarTabla();
        parent::tearDown();
    }

    private function enEmpresa(callable $fn): mixed
    {
        return app(Tenant::class)->conEmpresa($this->empresa->id, $fn);
    }

    private function colaborador(string $num = '700'): Colaborador
    {
        return $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => $num, 'nombre' => 'Luis', 'apellido_paterno' => 'Mar'.$num, 'sede_id' => $this->centro->id]));
    }

    private function credencial(Colaborador $c): Credencial
    {
        return $this->enEmpresa(fn () => Credencial::where('colaborador_id', $c->id)->latest('id')->firstOrFail());
    }

    /** Guarda las tarjetas como lo hace el formulario: casillas marcadas más el valor vacío. */
    private function habilitar(array $tarjetas): void
    {
        $this->actingAs($this->admin)->put(route('configuracion.credenciales'), array_merge(AjustesCredenciales::OMISION, ['tarjetas' => array_merge([''], $tarjetas)]))
            ->assertSessionHasNoErrors();
    }

    public function test_de_fabrica_solo_qr_y_no_se_pide_tarjeta(): void
    {
        $this->assertSame([], AjustesCredenciales::de($this->empresa)['tarjetas']);
        $this->actingAs($this->admin)->get(route('configuracion.tema', 'estructura'))->assertOk()
            ->assertSee('Tecnología de la credencial')->assertSee('Tarjeta Proximidad 125 kHz')->assertSee('Tarjeta MIFARE 13.56 MHz')
            ->assertSee('Lector USB de proximidad 125 kHz (escribe el número como un teclado)')
            ->assertSee('NFC del celular Android o lector USB de 13.56 MHz (MIFARE)')
            ->assertDontSee('name="tecnologia"', false);

        $c = $this->colaborador();
        $this->actingAs($this->rh)->post(route('credenciales.emitir', $c->id))->assertSessionHasNoErrors();
        $this->actingAs($this->rh)->get(route('colaboradores.expediente', $c->id))->assertOk()
            ->assertSee('Se lee con:')->assertSee(AjustesCredenciales::LECTOR_QR)
            ->assertDontSee('Avanzado: tarjeta NFC / RFID del plástico')->assertDontSee('id="credencial_nfc"', false)->assertDontSee('name="tipo_tarjeta"', false);

        // Aunque alguien mande un número, no se registra tarjeta
        $this->actingAs($this->rh)->post(route('credenciales.salida', $c->id), ['etiqueta_nfc' => '0012345678'])->assertSessionHasErrors('etiqueta_nfc');
        $this->assertNull($c->fresh()->etiqueta_nfc);
        $this->actingAs($this->rh)->post(route('credenciales.salida', $c->id))->assertSessionHasNoErrors();
        $this->assertSame([null, null], [$this->credencial($c)->etiqueta_nfc, $this->credencial($c)->tipo_tarjeta]);
    }

    public function test_solo_125_khz_no_pregunta_el_tipo_y_dice_que_lector_necesita(): void
    {
        $this->habilitar(['proximidad_125']);
        $this->assertSame(['proximidad_125'], AjustesCredenciales::de($this->empresa->fresh())['tarjetas']);
        $this->assertSame(['tarjetas' => ['proximidad_125']], $this->empresa->fresh()->preferencias['credenciales']);

        $c = $this->colaborador();
        $this->actingAs($this->rh)->post(route('credenciales.emitir', $c->id));
        $this->actingAs($this->rh)->get(route('colaboradores.expediente', $c->id))->assertOk()
            ->assertSee('Avanzado: tarjeta NFC / RFID del plástico')->assertSee('name="tipo_tarjeta" value="proximidad_125"', false)
            ->assertDontSee('¿Qué tarjeta es?')->assertSee('tarjeta Proximidad 125 kHz — Lector USB de proximidad 125 kHz', false);

        // El lector USB de 125 kHz escribe el número como teclado: llega como texto
        $this->actingAs($this->rh)->post(route('credenciales.salida', $c->id), ['etiqueta_nfc' => '0004567890', 'tipo_tarjeta' => 'proximidad_125'])->assertSessionHasNoErrors();
        $cred = $this->credencial($c);
        $this->assertSame(['0004567890', 'proximidad_125'], [$cred->etiqueta_nfc, $cred->tipo_tarjeta]);
        $this->assertSame('0004567890', $c->fresh()->etiqueta_nfc);
        $this->actingAs($this->rh)->get(route('colaboradores.expediente', $c->id))->assertOk()
            ->assertSee('Proximidad 125 kHz · 0004567890')->assertSee('Lector que la lee');

        // MIFARE no está habilitada en esta empresa
        $otro = $this->colaborador('701');
        $this->actingAs($this->rh)->post(route('credenciales.salida', $otro->id), ['etiqueta_nfc' => '04A1B2C3', 'tipo_tarjeta' => 'mifare_1356'])
            ->assertSessionHasErrors('tipo_tarjeta');
        $this->assertNull($otro->fresh()->etiqueta_nfc);
        // Sin decir el tipo, es la única habilitada
        $this->actingAs($this->rh)->post(route('credenciales.salida', $otro->id), ['etiqueta_nfc' => '0004567891'])->assertSessionHasNoErrors();
        $this->assertSame('proximidad_125', $this->credencial($otro)->tipo_tarjeta);
    }

    public function test_las_dos_conviven_y_hay_que_elegir_cual_es(): void
    {
        $this->habilitar(['mifare_1356', 'proximidad_125']);
        // Se guardan en el orden de la lista, sin repetir
        $this->assertSame(['proximidad_125', 'mifare_1356'], AjustesCredenciales::de($this->empresa->fresh())['tarjetas']);
        $this->actingAs($this->admin)->get(route('configuracion.tema', 'estructura'))->assertOk()
            ->assertSee('id="cfg_cred_tarjeta_proximidad_125" name="tarjetas[]" value="proximidad_125" checked', false)
            ->assertSee('id="cfg_cred_tarjeta_mifare_1356" name="tarjetas[]" value="mifare_1356" checked', false);

        $c = $this->colaborador();
        $this->actingAs($this->rh)->post(route('credenciales.emitir', $c->id));
        $this->actingAs($this->rh)->get(route('colaboradores.expediente', $c->id))->assertOk()
            ->assertSee('¿Qué tarjeta es?')->assertSee('value="proximidad_125"', false)->assertSee('value="mifare_1356"', false)
            ->assertSee('tarjeta MIFARE 13.56 MHz — NFC del celular Android', false);

        $this->actingAs($this->rh)->post(route('credenciales.salida', $c->id), ['etiqueta_nfc' => '04:a1:b2:c3'])->assertSessionHasErrors('tipo_tarjeta');
        $this->assertNull($c->fresh()->etiqueta_nfc);
        $this->actingAs($this->rh)->post(route('credenciales.salida', $c->id), ['etiqueta_nfc' => '04:a1:b2:c3', 'tipo_tarjeta' => 'otra'])->assertSessionHasErrors('tipo_tarjeta');
        $this->actingAs($this->rh)->post(route('credenciales.salida', $c->id), ['etiqueta_nfc' => '04:a1:b2:c3', 'tipo_tarjeta' => 'mifare_1356'])->assertSessionHasNoErrors();
        $cred = $this->credencial($c);
        $this->assertSame(['04A1B2C3', 'mifare_1356'], [$cred->etiqueta_nfc, $cred->tipo_tarjeta]);
        $this->actingAs($this->rh)->get(route('colaboradores.expediente', $c->id))->assertSee('MIFARE 13.56 MHz · 04A1B2C3')
            ->assertSee('NFC del celular Android o lector USB de 13.56 MHz (MIFARE)');

        // Recibir OK y volver a darla sin tarjeta nueva: conserva su tarjeta y su tipo
        $this->actingAs($this->rh)->patch(route('credenciales.recibir', $cred->id), ['resultado' => 'ok'])->assertSessionHas('ok');
        $this->actingAs($this->rh)->post(route('credenciales.salida', $c->id))->assertSessionHasNoErrors();
        $this->assertSame(['04A1B2C3', 'mifare_1356'], [$cred->fresh()->etiqueta_nfc, $cred->fresh()->tipo_tarjeta]);

        // Desmarcar las dos regresa a solo QR (el valor vacío del formulario manda)
        $this->habilitar([]);
        $this->assertSame([], AjustesCredenciales::de($this->empresa->fresh())['tarjetas']);
        $this->assertArrayNotHasKey('credenciales', $this->empresa->fresh()->preferencias ?? []);
        // Tecnología inexistente
        $this->actingAs($this->admin)->put(route('configuracion.credenciales'), ['tarjetas' => ['', 'hid_iclass']])->assertSessionHasErrors('tarjetas');
    }

    public function test_la_tarjeta_sigue_siendo_unica_entre_tipos(): void
    {
        $this->habilitar(['proximidad_125', 'mifare_1356']);
        $persona = $this->enEmpresa(fn () => Persona::create(['tipo' => 'visitante', 'nombre_completo' => 'Ana Externa']));
        $this->enEmpresa(fn () => $persona->forceFill(['etiqueta_nfc' => '0009998887'])->save());
        $c = $this->colaborador();
        $otro = $this->colaborador('702');
        $this->actingAs($this->rh)->post(route('credenciales.salida', $c->id), ['etiqueta_nfc' => '0009998887', 'tipo_tarjeta' => 'proximidad_125'])
            ->assertSessionHasErrors();
        $this->assertNull($c->fresh()->etiqueta_nfc);

        // La misma tarjeta con otro tipo tampoco: el número es uno solo
        $this->actingAs($this->rh)->post(route('credenciales.salida', $c->id), ['etiqueta_nfc' => '04AABBCC', 'tipo_tarjeta' => 'mifare_1356'])->assertSessionHasNoErrors();
        $this->actingAs($this->rh)->post(route('credenciales.salida', $otro->id), ['etiqueta_nfc' => '04AABBCC', 'tipo_tarjeta' => 'proximidad_125'])
            ->assertSessionHasErrors();
        $this->assertNull($otro->fresh()->etiqueta_nfc);
    }

    public function test_el_ajuste_anterior_qr_tarjeta_se_convierte_y_la_migracion_revierte(): void
    {
        $c = $this->colaborador();
        $this->actingAs($this->rh)->post(route('credenciales.salida', $c->id))->assertSessionHasNoErrors();
        $otra = $this->crearEmpresa('Otra empresa');
        DB::table('empresas')->where('id', $this->empresa->id)->update(['preferencias' => json_encode(['credenciales' => ['tecnologia' => 'qr_tarjeta', 'telefono' => '998 1']])]);
        DB::table('empresas')->where('id', $otra->id)->update(['preferencias' => json_encode(['credenciales' => ['tecnologia' => 'qr'], 'avisos' => ['x' => true]])]);
        // Lo guardado antes de la decisión cuenta como las dos (nadie pierde el campo de tarjeta)
        $this->assertSame(['proximidad_125', 'mifare_1356'], AjustesCredenciales::de($this->empresa->fresh())['tarjetas']);

        $migracion = require database_path('migrations/2026_11_19_000200_tarjetas_de_credenciales.php');
        $migracion->down();
        $this->assertFalse(Schema::hasColumn('credenciales', 'tipo_tarjeta'));
        $migracion->up();
        $this->assertTrue(Schema::hasColumn('credenciales', 'tipo_tarjeta'));
        $this->assertSame(['telefono' => '998 1', 'tarjetas' => ['proximidad_125', 'mifare_1356']], $this->empresa->fresh()->preferencias['credenciales']);
        $this->assertSame(['avisos' => ['x' => true]], $otra->fresh()->preferencias);

        // down() regresa el ajuste anterior
        $migracion->down();
        $this->assertSame(['telefono' => '998 1', 'tecnologia' => 'qr_tarjeta'], $this->empresa->fresh()->preferencias['credenciales']);
        $migracion->up();
        $this->assertSame(['proximidad_125', 'mifare_1356'], AjustesCredenciales::de($this->empresa->fresh())['tarjetas']);
        $this->assertSame('CRED-000001', $this->credencial($c)->folio);
    }
}
