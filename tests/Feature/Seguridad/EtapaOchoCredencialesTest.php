<?php

namespace Tests\Feature\Seguridad;

use App\Models\Colaborador;
use App\Models\Credencial;
use App\Models\Empresa;
use App\Models\MovimientoActivo;
use App\Models\Notificacion;
use App\Models\Sede;
use App\Models\User;
use App\Models\VoucherReposicion;
use App\Services\Activos\CustodiaActivos;
use App\Support\EstadoActivo;
use App\Support\Menu\MisPendientes;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Etapa 8 · C-44 (parte 3): credencialización. La credencial del colaborador
 * es un activo más (DISPONIBLE / ASIGNADO / NO REGRESÓ / DE BAJA), con la
 * recepción común de C-45, voucher de origen «credencial», baja automática en
 * «Antes de dar de baja» y su historial. Vínculos: ficha del colaborador,
 * lector (el plástico perdido ya no lo identifica), Vouchers, Mis pendientes
 * y campana, Etiquetas QR (Imprimir credencial) y permisos por sede.
 */
class EtapaOchoCredencialesTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private Sede $playa;

    private User $admin;

    private User $rh;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa();
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->playa = $this->crearSede($this->empresa, 'PLA');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
        $this->rh = $this->crearUsuario($this->empresa, 'Recursos Humanos');
    }

    private function enEmpresa(callable $fn): mixed
    {
        return app(Tenant::class)->conEmpresa($this->empresa->id, $fn);
    }

    private function colaborador(string $num = '900', ?Sede $sede = null): Colaborador
    {
        return $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => $num, 'nombre' => 'Marta', 'apellido_paterno' => 'Num'.$num, 'sede_id' => ($sede ?? $this->centro)->id]));
    }

    private function credencial(Colaborador $c): Credencial
    {
        return $this->enEmpresa(fn () => Credencial::where('colaborador_id', $c->id)->latest('id')->firstOrFail());
    }

    public function test_permiso_nuevo_por_plantilla_y_en_la_ficha(): void
    {
        $this->assertTrue($this->admin->can('colaboradores.credencial'));
        $this->assertTrue($this->rh->can('colaboradores.credencial'));
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->assertFalse($agente->can('colaboradores.credencial'));

        $c = $this->colaborador();
        $this->actingAs($this->rh)->get(route('colaboradores.expediente', $c->id))->assertOk()
            ->assertSee('Credencial')->assertSee('Todavía no tiene credencial. Toma su foto y emítela.')->assertSee('Emitir credencial')->assertSee('Tomar foto');
        // Quien no la maneja no ve los botones ni la sección vacía
        $this->actingAs($agente)->get(route('colaboradores.expediente', $c->id))->assertOk()->assertDontSee('Todavía no tiene credencial');
        $this->actingAs($agente)->post(route('credenciales.salida', $c->id))->assertForbidden();
    }

    /** La empresa usa tarjeta MIFARE además del QR (de fábrica solo QR y no se pide tarjeta). */
    private function conTarjeta(): void
    {
        $this->empresa->forceFill(['preferencias' => ['credenciales' => ['tarjetas' => ['mifare_1356']]]])->save();
    }

    public function test_dar_salida_recibir_ok_y_volver_a_darla_conserva_el_folio(): void
    {
        $this->conTarjeta();
        $c = $this->colaborador();
        $this->actingAs($this->rh)->post(route('credenciales.salida', $c->id), ['etiqueta_nfc' => '04:a2:3b:11'])
            ->assertRedirect(route('colaboradores.expediente', $c->id).'#codigo')->assertSessionHas('ok', 'Credencial CRED-000001 entregada.')
            ->assertSessionHas('siguientes');
        $cred = $this->credencial($c);
        $this->assertSame([EstadoActivo::ASIGNADO, 'CRED-000001', '04A23B11', $this->centro->id], [$cred->estado, $cred->folio, $cred->etiqueta_nfc, $cred->sede_id]);
        $this->assertSame('04A23B11', $c->fresh()->etiqueta_nfc);
        // Una sola a la vez
        $this->actingAs($this->rh)->post(route('credenciales.salida', $c->id))->assertSessionHasErrors('credencial');

        $this->actingAs($this->rh)->patch(route('credenciales.recibir', $cred->id), ['resultado' => 'ok'])->assertSessionHas('ok');
        $this->assertSame(EstadoActivo::DISPONIBLE, $cred->fresh()->estado);
        $this->actingAs($this->rh)->post(route('credenciales.salida', $c->id))->assertSessionHasNoErrors();
        $this->assertSame([EstadoActivo::ASIGNADO, 'CRED-000001'], [$cred->fresh()->estado, $cred->fresh()->folio]);
        $this->assertSame(1, $this->enEmpresa(fn () => Credencial::count()));

        $this->assertSame(['emision', 'salida', 'recepcion', 'salida'], $this->enEmpresa(fn () => MovimientoActivo::where('activo_tipo', 'credencial')->orderBy('id')->pluck('accion')->all()));
        $this->actingAs($this->rh)->get(route('activos.historial', ['credencial', $cred->id]))->assertOk()->assertSee('CRED-000001')->assertSee('Marta Num900');
    }

    public function test_no_regreso_avisa_y_la_baja_con_voucher_apaga_su_codigo(): void
    {
        $this->conTarjeta();
        $c = $this->colaborador();
        $this->actingAs($this->rh)->post(route('credenciales.salida', $c->id), ['etiqueta_nfc' => 'AABB01']);
        $cred = $this->credencial($c);
        $qrAnterior = $c->fresh()->codigo_qr;

        $this->actingAs($this->admin)->patch(route('credenciales.recibir', $cred->id), ['resultado' => 'no_regreso'])->assertSessionHas('ok');
        $this->assertSame(EstadoActivo::NO_REGRESO, $cred->fresh()->estado);
        // Campana de Recursos Humanos y Mis pendientes
        $aviso = Notificacion::withoutGlobalScopes()->where('user_id', $this->rh->id)->where('tipo', CustodiaActivos::TIPO_AVISO)->first();
        $this->assertNotNull($aviso);
        $this->assertSame('No regresó: credencial CRED-000001', $aviso->titulo);
        $this->actingAs($this->rh)->get(route('colaboradores.index'))->assertOk()->assertSee('Credenciales que no regresaron');
        $items = collect(app(MisPendientes::class)->para($this->rh)['items'])->keyBy('clave');
        $this->assertSame([1, 'roja'], [$items['credenciales_no_regreso']['total'], $items['credenciales_no_regreso']['nivel']]);

        $this->actingAs($this->rh)->post(route('credenciales.baja', $cred->id), ['motivo' => 'extraviado', 'aplica_cobro' => 1, 'monto' => '80'])
            ->assertSessionHasNoErrors()->assertSessionHas('ok');
        $voucher = $this->enEmpresa(fn () => VoucherReposicion::where('origen_tipo', 'credencial')->firstOrFail());
        $this->assertSame([$cred->id, 'colaborador', $c->id, '80.00', 'Credencial'], [$voucher->origen_id, $voucher->responsable_tipo, $voucher->responsable_id, $voucher->monto, $voucher->etiquetaOrigen()]);
        $this->assertSame([EstadoActivo::BAJA, 'reposicion', $voucher->id], [$cred->fresh()->estado, $cred->fresh()->baja_motivo, $cred->fresh()->voucher_id]);
        // El plástico perdido ya no lo identifica: sin tarjeta y con otro QR
        $this->assertNull($c->fresh()->etiqueta_nfc);
        $this->assertNotSame($qrAnterior, $c->fresh()->codigo_qr);
        $this->actingAs($this->admin)->getJson('/lector/resolver?entrada=AABB01')->assertJsonCount(0, 'resultados');
        $this->actingAs($this->admin)->get(route('lector.ir', $qrAnterior))->assertNotFound();
        // El aviso quedó resuelto y el voucher aparece en Vouchers con su origen
        $this->assertSame([], $aviso->fresh()->acciones ?? []);
        $this->actingAs($this->admin)->get(route('vouchers.index'))->assertOk()->assertSee($voucher->folio)->assertSee('Credencial');
        $this->actingAs($this->admin)->get(route('vouchers.imprimir', $voucher->id))->assertOk();

        // Nueva credencial con otro folio
        $this->actingAs($this->rh)->post(route('credenciales.salida', $c->id))->assertSessionHasNoErrors();
        $this->assertSame('CRED-000002', $this->credencial($c)->folio);
    }

    public function test_recibir_danada_la_da_de_baja_sin_voucher(): void
    {
        $c = $this->colaborador();
        $this->actingAs($this->rh)->post(route('credenciales.salida', $c->id));
        $cred = $this->credencial($c);
        $this->actingAs($this->rh)->patch(route('credenciales.recibir', $cred->id), ['resultado' => 'danado'])->assertSessionHas('ok');
        $this->assertSame([EstadoActivo::BAJA, 'reposicion', null], [$cred->fresh()->estado, $cred->fresh()->baja_motivo, $cred->fresh()->voucher_id]);
        $this->assertSame(['emision', 'salida', 'recepcion/danado', 'baja'], $this->enEmpresa(fn () => MovimientoActivo::where('activo_tipo', 'credencial')->orderBy('id')->get()
            ->map(fn ($m) => $m->accion.($m->resultado && $m->resultado !== 'ok' ? '/'.$m->resultado : ''))->all()));
        $this->actingAs($this->rh)->patch(route('credenciales.recibir', $cred->id), ['resultado' => 'ok'])->assertSessionHasErrors('credencial');
        $this->actingAs($this->rh)->patch(route('credenciales.recibir', $cred->id), ['resultado' => 'otro'])->assertSessionHasErrors('resultado');
    }

    public function test_antes_de_dar_de_baja_al_colaborador_la_da_de_baja_sola(): void
    {
        $c = $this->colaborador();
        $this->actingAs($this->rh)->post(route('credenciales.salida', $c->id));
        $cred = $this->credencial($c);

        $this->actingAs($this->rh)->get(route('bajas.revisar', ['tipo' => 'colaboradores', 'id' => $c->id]))->assertOk()
            ->assertSee('Tiene su credencial')->assertSee('CRED-000001');
        $this->actingAs($this->rh)->patch(route('colaboradores.estado', $c->id), ['activo' => 0, 'revisado' => 1])->assertSessionHasNoErrors();
        $this->assertSame([EstadoActivo::BAJA, 'baja_colaborador'], [$cred->fresh()->estado, $cred->fresh()->baja_motivo]);
        $this->assertSame(0, $this->enEmpresa(fn () => VoucherReposicion::count()));
    }

    public function test_otra_empresa_no_alcanza_la_credencial(): void
    {
        $c = $this->colaborador();
        $this->actingAs($this->rh)->post(route('credenciales.salida', $c->id));
        $cred = $this->credencial($c);

        $otra = $this->crearEmpresa('Hotel Dos');
        $ajeno = $this->crearUsuario($otra, 'Administrador');
        $this->actingAs($ajeno)->post(route('credenciales.salida', $c->id))->assertNotFound();
        $this->actingAs($ajeno)->patch(route('credenciales.recibir', $cred->id), ['resultado' => 'ok'])->assertNotFound();
        $this->actingAs($ajeno)->post(route('credenciales.baja', $cred->id), ['motivo' => 'extraviado'])->assertNotFound();
        $this->actingAs($ajeno)->get(route('activos.historial', ['credencial', $cred->id]))->assertNotFound();
        $this->assertSame(EstadoActivo::ASIGNADO, $cred->fresh()->estado);
    }
}
