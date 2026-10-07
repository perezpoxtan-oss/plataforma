<?php

namespace Tests\Feature\Seguridad;

use App\Models\Auditoria;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Gafete;
use App\Models\Sede;
use App\Models\TipoGafete;
use App\Models\User;
use App\Models\VoucherReposicion;
use App\Services\Gafetes\AdministradorGafetes;
use App\Support\Tenancy\EmpresaDeTrabajo;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

class GafetesTest extends TestCase
{
    use CreaDatosNucleo, RefreshDatabase;

    private Empresa $empresa;

    private Sede $centro;

    private Sede $playa;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrarCatalogo();
        $this->empresa = $this->crearEmpresa('Hotel Ébano');
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->playa = $this->crearSede($this->empresa, 'PLA');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
    }

    private function enEmpresa(callable $fn, ?Empresa $empresa = null): mixed
    {
        return app(Tenant::class)->conEmpresa(($empresa ?? $this->empresa)->id, $fn);
    }

    private function tipo(string $nombre, ?Empresa $empresa = null): TipoGafete
    {
        return $this->enEmpresa(fn () => TipoGafete::firstOrCreate(['nombre' => $nombre]), $empresa);
    }

    /**
     * Genera un lote con el servicio (como lo haría la pantalla).
     *
     * @return list<Gafete>
     */
    private function lote(Sede $sede, string $tipo, int $cantidad, ?Empresa $empresa = null, ?User $actor = null): array
    {
        $empresa ??= $this->empresa;
        $tipoModelo = $this->tipo($tipo, $empresa);

        return $this->enEmpresa(fn () => app(AdministradorGafetes::class)->generarLote($actor ?? $this->admin, $empresa->id, [
            'sede_id' => $sede->id, 'tipo_gafete_id' => $tipoModelo->id, 'cantidad' => $cantidad,
        ])->all(), $empresa);
    }

    private function gafete(string $nomenclatura, ?Empresa $empresa = null): ?Gafete
    {
        return $this->enEmpresa(fn () => Gafete::where('nomenclatura', $nomenclatura)->first(), $empresa);
    }

    private function colaborador(string $num, ?Empresa $empresa = null): Colaborador
    {
        return $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => $num, 'nombre' => 'Roberto', 'apellido_paterno' => 'Hernández']), $empresa);
    }

    // ---------------------------------------------------------------- Lista

    public function test_lista_con_textos_de_segcat_tipos_basicos_y_filtros(): void
    {
        $this->actingAs($this->admin)->get('/gafetes')->assertOk()
            ->assertSee('Inventario Gafetes')->assertSee('Plásticos físicos para accesos.')
            ->assertSee('Generar Lote')->assertSee('Todavía no hay gafetes');
        // La primera vez se crean los tipos de siempre (como la semilla de SEGCAT)
        $this->assertSame(['Contratista', 'Proveedor', 'Visitante'], $this->enEmpresa(fn () => TipoGafete::orderBy('nombre')->pluck('nombre')->all()));

        [$g] = $this->lote($this->centro, 'Visitante', 1);
        $this->lote($this->playa, 'Proveedor', 2);

        $this->actingAs($this->admin)->get('/gafetes')->assertOk()
            ->assertSee('HOT-CEN-VIS-001')->assertSee('HOT-PLA-PRO-002')
            ->assertSee('id="gafete-'.$g->id.'"', false)
            ->assertSee('data-filtro-tipo="gafetes"', false)->assertSee('data-filtro-sede="gafetes"', false)
            ->assertSee('data-filtro-estado="gafetes"', false)->assertSee('data-tipo="'.$g->tipo_gafete_id.'"', false)
            ->assertSee('Marcar todos')->assertSee('form="formImprimirGafetes"', false)
            ->assertSee('DISPONIBLE')->assertSee('Creado por')
            ->assertSee('Todas las sedes')->assertSee('Sede CEN')
            ->assertDontSee('onclick');
        // Los tipos no se duplican al volver a entrar
        $this->assertSame(3, $this->enEmpresa(fn () => TipoGafete::count()));
    }

    // ---------------------------------------------------------------- Lote

    public function test_generar_lote_arma_la_nomenclatura_y_continua_el_consecutivo(): void
    {
        $visitante = $this->tipo('Visitante');

        $this->actingAs($this->admin)->post('/gafetes/lote', ['sede_id' => $this->centro->id, 'tipo_gafete_id' => $visitante->id, 'cantidad' => 3, '_dialogo' => 'lote'])
            ->assertRedirect(route('gafetes.index'))
            ->assertSessionHas('ok', 'Lote de 3 gafetes generado correctamente (HOT-CEN-VIS-001 a HOT-CEN-VIS-003). Márcalos y presiona «Imprimir».');

        // "Hotel Ébano" -> HOT; sin acentos ni minúsculas
        $g = $this->gafete('HOT-CEN-VIS-003');
        $this->assertNotNull($g);
        $this->assertSame(3, $g->consecutivo);
        $this->assertSame($this->admin->id, $g->creado_por);
        $this->assertMatchesRegularExpression('/^[a-z0-9]{24}$/', $g->codigo_qr);

        // Sigue después del último, aunque uno se haya dado de baja
        $this->enEmpresa(fn () => $g->forceFill(['activo' => false])->save());
        $this->actingAs($this->admin)->post('/gafetes/lote', ['sede_id' => $this->centro->id, 'tipo_gafete_id' => $visitante->id, 'cantidad' => 2])
            ->assertSessionHas('ok');
        $this->assertNotNull($this->gafete('HOT-CEN-VIS-005'));

        // Cada sede lleva su propia numeración
        $this->actingAs($this->admin)->post('/gafetes/lote', ['sede_id' => $this->playa->id, 'tipo_gafete_id' => $visitante->id, 'cantidad' => 1])
            ->assertSessionHas('ok', 'Gafete generado correctamente (HOT-PLA-VIS-001). Márcalos y presiona «Imprimir».');

        $auditoria = Auditoria::where('evento', 'gafetes.lote')->firstOrFail();
        $this->assertSame(['HOT-CEN-VIS-001', 'HOT-CEN-VIS-003', 3], [$auditoria->despues['desde'], $auditoria->despues['hasta'], $auditoria->despues['cantidad']]);
    }

    public function test_tipos_con_las_mismas_tres_letras_no_repiten_nomenclatura(): void
    {
        $this->lote($this->centro, 'Proveedor', 2);
        $this->lote($this->centro, 'Promotor', 1);

        $this->assertNotNull($this->gafete('HOT-CEN-PRO-003'));
        $this->assertSame(3, $this->enEmpresa(fn () => Gafete::count()));
    }

    public function test_tipo_nuevo_desde_el_lote_sin_duplicar_el_catalogo(): void
    {
        $this->actingAs($this->admin)->post('/gafetes/lote', ['sede_id' => $this->centro->id, 'tipo_gafete_id' => '__nuevo__', 'nombre_tipo_nuevo' => '  capital   humano ', 'cantidad' => 1])
            ->assertSessionHas('ok');
        $tipo = $this->enEmpresa(fn () => TipoGafete::where('nombre', 'Capital humano')->firstOrFail());
        $this->assertNotNull($this->gafete('HOT-CEN-CAP-001'));
        $this->assertSame(1, Auditoria::where('evento', 'gafetes.tipo_creado')->count());

        // Mismo nombre con otras mayúsculas: se reutiliza
        $this->actingAs($this->admin)->post('/gafetes/lote', ['sede_id' => $this->centro->id, 'tipo_gafete_id' => '__nuevo__', 'nombre_tipo_nuevo' => 'CAPITAL HUMANO', 'cantidad' => 1])
            ->assertSessionHas('ok');
        $this->assertSame(1, $this->enEmpresa(fn () => TipoGafete::whereRaw('LOWER(nombre) = ?', ['capital humano'])->count()));
        $this->assertSame(2, $this->enEmpresa(fn () => Gafete::where('tipo_gafete_id', $tipo->id)->count()));

        $this->actingAs($this->admin)->post('/gafetes/lote', ['sede_id' => $this->centro->id, 'tipo_gafete_id' => '__nuevo__', 'nombre_tipo_nuevo' => '', 'cantidad' => 1, '_dialogo' => 'lote'])
            ->assertSessionHasErrors(['nombre_tipo_nuevo' => 'Escribe el nombre del tipo nuevo (por ejemplo: Capital Humano).']);
    }

    public function test_validaciones_del_lote(): void
    {
        $tipo = $this->tipo('Visitante');
        $datos = ['sede_id' => $this->centro->id, 'tipo_gafete_id' => $tipo->id, '_dialogo' => 'lote'];

        $this->actingAs($this->admin)->post('/gafetes/lote', $datos + ['cantidad' => 0])->assertSessionHasErrors(['cantidad' => 'La cantidad debe ser de 1 a 50 gafetes.']);
        $this->actingAs($this->admin)->post('/gafetes/lote', $datos + ['cantidad' => 51])->assertSessionHasErrors(['cantidad' => 'Máximo 50 gafetes por lote. Si necesitas más, genera otro lote.']);
        $this->actingAs($this->admin)->post('/gafetes/lote', ['tipo_gafete_id' => $tipo->id, 'cantidad' => 1])->assertSessionHasErrors(['sede_id' => 'Elige la sede donde se usarán los gafetes.']);

        // Sede o tipo de otra empresa: como si no existieran
        $otra = $this->crearEmpresa('Hotel Dos');
        $sedeAjena = $this->crearSede($otra, 'OTR');
        $tipoAjeno = $this->tipo('Visitante', $otra);
        $this->actingAs($this->admin)->post('/gafetes/lote', ['sede_id' => $sedeAjena->id, 'tipo_gafete_id' => $tipo->id, 'cantidad' => 1])->assertSessionHasErrors('sede_id');
        $this->actingAs($this->admin)->post('/gafetes/lote', ['sede_id' => $this->centro->id, 'tipo_gafete_id' => $tipoAjeno->id, 'cantidad' => 1])
            ->assertSessionHasErrors(['tipo_gafete_id' => 'Elige el tipo de gafete de la lista.']);
        // Sede desactivada
        $this->enEmpresa(fn () => $this->playa->forceFill(['activo' => false])->save());
        $this->actingAs($this->admin)->post('/gafetes/lote', ['sede_id' => $this->playa->id, 'tipo_gafete_id' => $tipo->id, 'cantidad' => 1])->assertSessionHasErrors('sede_id');

        $this->assertSame(0, Gafete::withoutGlobalScopes()->count());

        // El error se ve dentro del diálogo, que se vuelve a abrir solo
        $html = $this->actingAs($this->admin)->from('/gafetes')->followingRedirects()->post('/gafetes/lote', $datos + ['cantidad' => 99])
            ->assertSee('Máximo 50 gafetes por lote.')->getContent();
        $this->assertMatchesRegularExpression('/<dialog id="dialogoLoteGafetes"[^>]*data-abrir-al-cargar/', $html);
    }

    // -------------------------------------------------------------- Edición

    public function test_editar_nomenclatura_tipo_y_etiqueta_nfc(): void
    {
        [$g, $otro] = $this->lote($this->centro, 'Visitante', 2);
        $proveedor = $this->tipo('Proveedor');

        $this->actingAs($this->admin)->put("/gafetes/{$g->id}", ['nomenclatura' => ' hot-cen-vip-01 ', 'tipo_gafete_id' => $proveedor->id, 'etiqueta_nfc' => '04:a2:3b:1c'])
            ->assertRedirect(route('gafetes.index').'#gafete-'.$g->id)->assertSessionHas('ok', 'Gafete HOT-CEN-VIP-01 actualizado correctamente.');
        $g = $g->fresh();
        $this->assertSame(['HOT-CEN-VIP-01', $proveedor->id, '04A23B1C'], [$g->nomenclatura, $g->tipo_gafete_id, $g->etiqueta_nfc]);
        $auditoria = Auditoria::where('evento', 'gafetes.actualizado')->firstOrFail();
        $this->assertSame(['HOT-CEN-VIS-001', 'HOT-CEN-VIP-01'], [$auditoria->antes['nomenclatura'], $auditoria->despues['nomenclatura']]);

        // Nomenclatura única por empresa
        $this->actingAs($this->admin)->put("/gafetes/{$otro->id}", ['nomenclatura' => 'hot-cen-vip-01', 'tipo_gafete_id' => $proveedor->id, '_dialogo' => 'editar-'.$otro->id])
            ->assertSessionHasErrors(['nomenclatura' => 'Ya existe un gafete con la nomenclatura «HOT-CEN-VIP-01» en esta empresa.']);
        // La misma etiqueta en otro gafete (escrita de otra forma): dice quién la tiene
        $this->actingAs($this->admin)->put("/gafetes/{$otro->id}", ['nomenclatura' => $otro->nomenclatura, 'tipo_gafete_id' => $proveedor->id, 'etiqueta_nfc' => '04A23B1C'])
            ->assertSessionHasErrors(['etiqueta_nfc' => 'Esa etiqueta NFC / RFID ya está asignada al gafete HOT-CEN-VIP-01. Quítasela primero o usa otra.']);
        // Quitarle la etiqueta
        $this->actingAs($this->admin)->put("/gafetes/{$g->id}", ['nomenclatura' => 'HOT-CEN-VIP-01', 'tipo_gafete_id' => $proveedor->id, 'etiqueta_nfc' => ''])->assertSessionHasNoErrors();
        $this->assertNull($g->fresh()->etiqueta_nfc);

        // Otra empresa sí puede usar la misma nomenclatura (SEGCAT la revisaba global)
        $otra = $this->crearEmpresa('Hotel Dos');
        $ajeno = $this->lote($this->crearSede($otra, 'OTR'), 'Visitante', 1, $otra)[0];
        $adminOtra = $this->crearUsuario($otra, 'Administrador');
        $this->actingAs($adminOtra)->put("/gafetes/{$ajeno->id}", ['nomenclatura' => 'HOT-CEN-VIP-01', 'tipo_gafete_id' => $ajeno->tipo_gafete_id])->assertSessionHasNoErrors();
    }

    public function test_el_formulario_de_edicion_trae_el_lector_en_modo_capturar(): void
    {
        $this->lote($this->centro, 'Visitante', 1);

        $this->actingAs($this->admin)->get('/gafetes')->assertOk()
            ->assertSee('Etiqueta NFC / RFID (opcional)')->assertSee('data-modo="capturar"', false)
            ->assertSee('name="etiqueta_nfc"', false)->assertSee('Código Interno');
    }

    // ------------------------------------------------- Baja con voucher

    public function test_baja_con_voucher_y_costo_sugerido(): void
    {
        [$g, $otro] = $this->lote($this->centro, 'Visitante', 2);
        $roberto = $this->colaborador('1005');

        $this->actingAs($this->admin)->post("/gafetes/{$g->id}/baja", [
            'motivo' => 'extraviado', 'descripcion' => 'Se fue sin devolverlo', 'aplica_cobro' => '1', 'monto' => '150', 'colaborador_id' => $roberto->id,
        ])->assertRedirect(route('gafetes.index').'#gafete-'.$g->id)->assertSessionHas('aviso');

        $this->assertFalse($g->fresh()->activo);
        $voucher = $this->enEmpresa(fn () => VoucherReposicion::firstOrFail());
        $this->assertSame(['gafete', $g->id, 'HOT-CEN-VIS-001', 'extraviado', true, '150.00', $roberto->id, $this->centro->id],
            [$voucher->origen_tipo, $voucher->origen_id, $voucher->origen_descripcion, $voucher->motivo, $voucher->aplica_cobro, $voucher->monto, $voucher->colaborador_id, $voucher->sede_id]);
        $this->assertMatchesRegularExpression('/^VR-\d{4}-\d{5}$/', $voucher->folio);
        $this->assertSame(1, Auditoria::where('evento', 'gafetes.desactivado')->count());
        $this->assertSame(1, Auditoria::where('evento', 'vouchers.creado')->count());

        // La lista ofrece imprimir el voucher recién generado y sugiere el costo para el siguiente
        $this->actingAs($this->admin)->withSession(['voucher_generado' => ['id' => $voucher->id, 'folio' => $voucher->folio]])->get('/gafetes')
            ->assertSee(route('vouchers.imprimir', $voucher->id))->assertSee($voucher->folio)
            ->assertSee('NO DISPONIBLE')
            ->assertSee('&quot;monto&quot;:&quot;150.00&quot;', false);

        // Ya dado de baja: no se repite
        $this->actingAs($this->admin)->post("/gafetes/{$g->id}/baja", ['motivo' => 'robado'])->assertSessionHasErrors(['motivo' => 'El gafete HOT-CEN-VIS-001 ya está dado de baja.']);
        $this->assertSame(1, $this->enEmpresa(fn () => VoucherReposicion::count()));

        // Sin cobro: sin monto ni responsable
        $this->actingAs($this->admin)->post("/gafetes/{$otro->id}/baja", ['motivo' => 'danado'])->assertSessionHas('aviso');
        $sinCobro = $this->enEmpresa(fn () => VoucherReposicion::where('origen_id', $otro->id)->firstOrFail());
        $this->assertSame([false, '0.00', null, 'danado'], [$sinCobro->aplica_cobro, $sinCobro->monto, $sinCobro->colaborador_id, $sinCobro->motivo]);
    }

    public function test_validaciones_de_la_baja_se_muestran_en_el_dialogo(): void
    {
        [$g] = $this->lote($this->centro, 'Visitante', 1);
        $otra = $this->crearEmpresa('Hotel Dos');
        $ajeno = $this->colaborador('9', $otra);

        $this->actingAs($this->admin)->post("/gafetes/{$g->id}/baja", ['motivo' => 'perdido'])->assertSessionHasErrors('motivo');
        $this->actingAs($this->admin)->post("/gafetes/{$g->id}/baja", ['motivo' => 'robado', 'aplica_cobro' => '1'])
            ->assertSessionHasErrors(['monto' => 'Indica el monto a cobrar.', 'colaborador_id' => 'Elige al responsable al que se le cobrará.']);
        $this->actingAs($this->admin)->post("/gafetes/{$g->id}/baja", ['motivo' => 'robado', 'aplica_cobro' => '1', 'monto' => 100, 'colaborador_id' => $ajeno->id])
            ->assertSessionHasErrors(['colaborador_id' => 'El responsable no pertenece a esta empresa.']);
        $this->assertTrue($g->fresh()->activo);
        $this->assertSame(0, VoucherReposicion::withoutGlobalScopes()->count());

        $html = $this->actingAs($this->admin)->from('/gafetes')->followingRedirects()
            ->post("/gafetes/{$g->id}/baja", ['motivo' => 'robado', 'aplica_cobro' => '1', '_dialogo' => 'baja-'.$g->id])
            ->assertSee(route('gafetes.baja', $g->id))->assertSee('Indica el monto a cobrar.')->getContent();
        $this->assertMatchesRegularExpression('/<dialog id="dialogoBajaGafete"[^>]*data-abrir-al-cargar/', $html);
        // Tras el error, la casilla de cobro sigue marcada y su caja visible
        $this->assertMatchesRegularExpression('/name="aplica_cobro" value="1"\s+checked/', $html);
    }

    public function test_reactivar_conserva_el_voucher(): void
    {
        [$g] = $this->lote($this->centro, 'Visitante', 1);
        $this->actingAs($this->admin)->post("/gafetes/{$g->id}/baja", ['motivo' => 'extraviado'])->assertSessionHas('aviso');

        $this->actingAs($this->admin)->patch("/gafetes/{$g->id}/reactivar")
            ->assertRedirect(route('gafetes.index').'#gafete-'.$g->id)->assertSessionHas('ok', 'Gafete HOT-CEN-VIS-001 reactivado correctamente.');
        $this->assertTrue($g->fresh()->activo);
        $this->assertSame(1, $this->enEmpresa(fn () => VoucherReposicion::count()));
        $this->assertSame(1, Auditoria::where('evento', 'gafetes.reactivado')->count());
    }

    // ------------------------------------------------------------- Impresión

    public function test_impresion_doble_vista_con_qr_local(): void
    {
        [$g, $h] = $this->lote($this->centro, 'Visitante', 2);

        $respuesta = $this->actingAs($this->admin)->post('/gafetes/imprimir', ['gafetes' => [$g->id, $h->id]])->assertOk()
            ->assertSee('Impresión Doble Vista (Libro)')->assertSee('HOT-CEN-VIS-001')->assertSee('HOT-CEN-VIS-002')
            ->assertSee('Hotel Ébano')->assertSee('Sede: Sede CEN')->assertSee('franja-visitante', false)
            ->assertSee('Espacio Logo')->assertSee('<svg', false)
            ->assertDontSee('qrserver');
        $html = $respuesta->getContent();
        $this->assertStringNotContainsString('<?xml', $html);
        $this->assertStringNotContainsString('onclick', $html);
        $this->assertDoesNotMatchRegularExpression('/<img[^>]+src="https?:\/\/(?!localhost)/i', $html);

        // Un solo gafete desde su ficha (GET)
        $this->actingAs($this->admin)->get(route('gafetes.imprimir', ['gafetes' => [$h->id]]))->assertOk()->assertSee('HOT-CEN-VIS-002')->assertDontSee('HOT-CEN-VIS-001');

        // Nada marcado: aviso, sin página vacía
        $this->actingAs($this->admin)->post('/gafetes/imprimir', [])->assertRedirect(route('gafetes.index'))
            ->assertSessionHas('aviso');
    }

    // --------------------------------------------------------- Lector universal

    public function test_el_lector_encuentra_el_gafete_por_codigo_etiqueta_o_nomenclatura(): void
    {
        [$g] = $this->lote($this->centro, 'Visitante', 1);
        $this->enEmpresa(fn () => $g->forceFill(['etiqueta_nfc' => '00:bc:61:4e'])->save());

        $this->actingAs($this->admin)->getJson('/lector/resolver?entrada='.urlencode(route('lector.ir', $g->codigo_qr)))
            ->assertOk()->assertJsonPath('resultados.0.tipo', 'gafete')->assertJsonPath('resultados.0.id', $g->id)
            ->assertJsonPath('resultados.0.titulo', 'HOT-CEN-VIS-001')->assertJsonPath('resultados.0.detalle', 'Visitante · Sede CEN');
        // Lector de 125 kHz (decimal) y nomenclatura tecleada
        $this->actingAs($this->admin)->getJson('/lector/resolver?entrada=0012345678&tipos=gafete')->assertJsonPath('resultados.0.id', $g->id);
        $this->actingAs($this->admin)->getJson('/lector/resolver?entrada=hot-cen-vis-001&tipos=gafete')->assertJsonPath('resultados.0.id', $g->id);

        $this->actingAs($this->admin)->get('/e/'.$g->codigo_qr)->assertRedirect(route('gafetes.index').'#gafete-'.$g->id);
    }

    // ------------------------------------------------------ Aislamiento y alcance

    public function test_cada_empresa_ve_y_toca_solo_sus_gafetes(): void
    {
        $this->lote($this->centro, 'Visitante', 1);
        $otra = $this->crearEmpresa('Hotel Dos');
        $ajeno = $this->lote($this->crearSede($otra, 'OTR'), 'Contratista', 1, $otra)[0];

        $this->actingAs($this->admin)->get('/gafetes')->assertSee('HOT-CEN-VIS-001')->assertDontSee($ajeno->nomenclatura);
        $this->actingAs($this->admin)->put("/gafetes/{$ajeno->id}", ['nomenclatura' => 'X', 'tipo_gafete_id' => $ajeno->tipo_gafete_id])->assertNotFound();
        $this->actingAs($this->admin)->post("/gafetes/{$ajeno->id}/baja", ['motivo' => 'robado'])->assertNotFound();
        $this->actingAs($this->admin)->patch("/gafetes/{$ajeno->id}/reactivar")->assertNotFound();
        $this->actingAs($this->admin)->post('/gafetes/imprimir', ['gafetes' => [$ajeno->id]])->assertNotFound();
        $this->actingAs($this->admin)->get('/e/'.$ajeno->codigo_qr)->assertNotFound();
        $this->assertTrue(Gafete::withoutGlobalScopes()->findOrFail($ajeno->id)->activo);
    }

    public function test_con_alcance_de_sede_solo_ve_y_genera_en_sus_sedes(): void
    {
        $this->lote($this->centro, 'Visitante', 1);
        [$dePlaya] = $this->lote($this->playa, 'Visitante', 1);
        $jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);

        $pagina = $this->actingAs($jefe)->get('/gafetes')->assertOk()->assertSee('HOT-CEN-VIS-001')->assertDontSee('HOT-PLA-VIS-001')->getContent();
        // En el alta solo aparece su sede
        $this->assertStringNotContainsString('Sede PLA (PLA)', $pagina);
        $this->assertStringContainsString('Sede CEN (CEN)', $pagina);

        $tipo = $this->tipo('Visitante');
        $this->actingAs($jefe)->post('/gafetes/lote', ['sede_id' => $this->playa->id, 'tipo_gafete_id' => $tipo->id, 'cantidad' => 1])
            ->assertSessionHasErrors(['sede_id' => 'Elige una sede activa de la lista (solo puedes generar gafetes para tus sedes).']);
        $this->actingAs($jefe)->post('/gafetes/lote', ['sede_id' => $this->centro->id, 'tipo_gafete_id' => $tipo->id, 'cantidad' => 1])->assertSessionHasNoErrors();

        $this->actingAs($jefe)->put("/gafetes/{$dePlaya->id}", ['nomenclatura' => 'X', 'tipo_gafete_id' => $tipo->id])->assertNotFound();
        $this->actingAs($jefe)->post("/gafetes/{$dePlaya->id}/baja", ['motivo' => 'robado'])->assertNotFound();
        $this->actingAs($jefe)->post('/gafetes/imprimir', ['gafetes' => [$dePlaya->id]])->assertNotFound();
        $this->assertTrue($dePlaya->fresh()->activo);
    }

    public function test_el_agente_consulta_pero_no_genera_edita_da_de_baja_ni_imprime(): void
    {
        [$g] = $this->lote($this->centro, 'Visitante', 1);
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $tipo = $this->tipo('Visitante');

        $this->actingAs($agente)->get('/gafetes')->assertOk()->assertSee('HOT-CEN-VIS-001')
            ->assertDontSee('Generar Lote')->assertDontSee('dialogoEditarGafete')->assertDontSee('dialogoBajaGafete')
            ->assertDontSee('Marcar todos')->assertDontSee(route('gafetes.reactivar', $g->id));
        $this->actingAs($agente)->post('/gafetes/lote', ['sede_id' => $this->centro->id, 'tipo_gafete_id' => $tipo->id, 'cantidad' => 1])->assertForbidden();
        $this->actingAs($agente)->put("/gafetes/{$g->id}", ['nomenclatura' => 'X', 'tipo_gafete_id' => $tipo->id])->assertForbidden();
        $this->actingAs($agente)->post("/gafetes/{$g->id}/baja", ['motivo' => 'robado'])->assertForbidden();
        $this->actingAs($agente)->patch("/gafetes/{$g->id}/reactivar")->assertForbidden();
        $this->actingAs($agente)->post('/gafetes/imprimir', ['gafetes' => [$g->id]])->assertForbidden();
        // Sí lo encuentra con el lector
        $this->actingAs($agente)->getJson('/lector/resolver?entrada=HOT-CEN-VIS-001&tipos=gafete')->assertJsonPath('resultados.0.id', $g->id);

        $sinPermiso = $this->crearUsuario($this->empresa);
        $this->actingAs($sinPermiso)->get('/gafetes')->assertForbidden();
    }

    public function test_super_administrador_elige_la_empresa_de_trabajo(): void
    {
        $this->lote($this->centro, 'Visitante', 1);
        $sa = $this->crearSuperadmin();

        $this->actingAs($sa)->get('/gafetes')->assertOk()->assertSee('Elige arriba la');
        $this->actingAs($sa)->post('/gafetes/lote', ['sede_id' => $this->centro->id, 'tipo_gafete_id' => 1, 'cantidad' => 1])->assertNotFound();
        $this->actingAs($sa)->withSession([EmpresaDeTrabajo::SESION => $this->empresa->id])->get('/gafetes')->assertOk()->assertSee('HOT-CEN-VIS-001')->assertSee('Generar Lote');
    }

    public function test_menu_enlaza_a_gafetes_y_vouchers(): void
    {
        $this->actingAs($this->admin)->get('/')->assertOk()->assertSee(route('gafetes.index'))->assertSee(route('vouchers.index'));
    }
}
