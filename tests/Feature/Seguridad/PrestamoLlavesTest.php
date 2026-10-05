<?php

namespace Tests\Feature\Seguridad;

use App\Models\Auditoria;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Llave;
use App\Models\PrestamoLlave;
use App\Models\Sede;
use App\Models\User;
use App\Services\Colaboradores\AdministradorColaboradores;
use App\Support\Tenancy\Tenant;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

class PrestamoLlavesTest extends TestCase
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
        $this->empresa = $this->crearEmpresa();
        $this->centro = $this->crearSede($this->empresa, 'CEN');
        $this->playa = $this->crearSede($this->empresa, 'PLA');
        $this->admin = $this->crearUsuario($this->empresa, 'Administrador');
    }

    private function enEmpresa(callable $fn, ?Empresa $empresa = null): mixed
    {
        return app(Tenant::class)->conEmpresa(($empresa ?? $this->empresa)->id, $fn);
    }

    private function llave(string $nomenclatura, ?Sede $sede = null, ?Empresa $empresa = null, bool $activa = true): Llave
    {
        return $this->enEmpresa(function () use ($nomenclatura, $sede, $activa) {
            $l = new Llave(['sede_id' => ($sede ?? $this->centro)->id, 'nomenclatura' => $nomenclatura,
                'descripcion' => 'Acceso de prueba '.$nomenclatura, 'tipo_dispositivo' => 'metalica', 'alcance' => 'global']);
            $l->forceFill(['activo' => $activa])->save();

            return $l;
        }, $empresa);
    }

    private function colaborador(string $num, ?Sede $sede = null, ?Empresa $empresa = null, array $extra = []): Colaborador
    {
        return $this->enEmpresa(function () use ($num, $sede, $extra) {
            $c = new Colaborador(['num_empleado' => $num, 'nombre' => 'Persona', 'apellido_paterno' => 'Num'.$num, 'sede_id' => $sede?->id]);
            $c->forceFill($extra)->save();

            return $c;
        }, $empresa);
    }

    /**
     * @return array<string, mixed>
     */
    private function datos(Llave $llave, Colaborador $colaborador, array $extra = []): array
    {
        return array_merge([
            'sede_id' => $llave->sede_id, 'llave_id' => $llave->id, 'colaborador_id' => $colaborador->id,
            'tipo_garantia' => 'ine', 'folio_garantia' => ' ine-55102 ',
        ], $extra);
    }

    private function prestamo(Llave $llave, Colaborador $colaborador, array $extra = [], ?Empresa $empresa = null): PrestamoLlave
    {
        return $this->enEmpresa(function () use ($llave, $colaborador, $extra) {
            $p = new PrestamoLlave(['sede_id' => $llave->sede_id, 'llave_id' => $llave->id, 'colaborador_id' => $colaborador->id, 'tipo_garantia' => 'ine']);
            $p->forceFill(array_merge(['estado' => PrestamoLlave::EN_USO, 'prestado_en' => now()->subHour(), 'entregado_por' => $this->admin->id], $extra))->save();

            return $p;
        }, $empresa);
    }

    private function buscar(int $id): PrestamoLlave
    {
        return $this->enEmpresa(fn () => PrestamoLlave::findOrFail($id));
    }

    // ---------------------------------------------------------------- Lista

    public function test_pantalla_con_pestanas_fichas_y_textos_de_segcat(): void
    {
        $l1 = $this->llave('HDC-MASTER-01');
        $l2 = $this->llave('HDC-BOD-01');
        $c = $this->colaborador('1005', $this->centro);
        $fuera = $this->prestamo($l1, $c);
        $devuelta = $this->prestamo($l2, $c, ['estado' => PrestamoLlave::DEVUELTA, 'devuelto_en' => now(), 'recibido_por' => $this->admin->id]);

        $this->actingAs($this->admin)->get('/prestamo-llaves')->assertOk()
            ->assertSee('Bitácora de Llaves')->assertSee('Registro operativo, préstamos y devoluciones.')
            ->assertSee('Llaves en Uso (<span data-conteo-en-uso>1</span>)', false)->assertSee('Historial de Entregas')
            ->assertSee('Excel (Auditoría)')->assertSee('Prestar Llave')->assertSee('Escáner Listo')
            ->assertSee('Buscar por colaborador, nómina o llave...')
            ->assertSee('id="prestamo-'.$fuera->id.'"', false)->assertSee('LLAVE FUERA')->assertSee('ID: #'.str_pad((string) $fuera->id, 5, '0', STR_PAD_LEFT))
            ->assertSee('No. Nómina: 1005')->assertSee('Garantía: INE')->assertSee('Recibir Llave a Caseta')
            ->assertSee('id="prestamo-'.$devuelta->id.'"', false)->assertSee('EN CASETA')->assertSee('Usada por: Persona Num1005')->assertSee('Regresó:')
            ->assertSee('Registrar y Capturar Siguiente')->assertSee('ID Dejada en Garantía')->assertSee('Ninguna (Riesgo)')
            ->assertDontSee('onclick')->assertDontSee('Hotel)');
    }

    public function test_sin_prestamos_muestra_los_estados_vacios(): void
    {
        $this->actingAs($this->admin)->get('/prestamo-llaves')->assertOk()
            ->assertSee('Todas las llaves están en caseta.')->assertSee('Todavía no hay entregas en el historial.');
    }

    public function test_el_dialogo_usa_el_lector_universal_para_llave_y_colaborador(): void
    {
        $pagina = $this->actingAs($this->admin)->get('/prestamo-llaves')->assertOk()->getContent();

        $this->assertStringContainsString('data-tipos="llave"', $pagina);
        $this->assertStringContainsString('data-tipos="colaborador"', $pagina);
        $this->assertStringContainsString('name="llave_id"', $pagina);
        $this->assertStringContainsString('name="colaborador_id"', $pagina);
        // Nada de escáneres propios como en SEGCAT
        $this->assertStringNotContainsString('select2', $pagina);
        $this->assertStringNotContainsString('cdn.jsdelivr.net/npm/jsqr', $pagina);
        $this->assertStringNotContainsString('NDEFReader', $pagina);
    }

    public function test_con_una_sola_sede_la_sede_viene_elegida(): void
    {
        $jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);

        $pagina = $this->actingAs($jefe)->get('/prestamo-llaves')->assertOk()->assertDontSee('-- Seleccionar --')->getContent();
        $this->assertMatchesRegularExpression('/<option value="'.$this->centro->id.'"\s+selected\s+data-por-defecto\s*>Sede CEN/', $pagina);
    }

    // ---------------------------------------------------------------- Prestar

    public function test_prestar_por_fetch_devuelve_la_ficha_y_audita(): void
    {
        $l = $this->llave('HDC-101');
        $c = $this->colaborador('1007', $this->centro);

        $respuesta = $this->actingAs($this->admin)->postJson('/prestamo-llaves', $this->datos($l, $c))->assertCreated()
            ->assertJsonPath('ok', true)->assertJsonPath('llave_id', $l->id)
            ->assertJsonPath('mensaje', 'Llave «HDC-101» entregada a Persona Num1007.');
        $this->assertStringContainsString('LLAVE FUERA', $respuesta->json('ficha'));
        $this->assertStringContainsString('Garantía: INE (INE-55102)', $respuesta->json('ficha'));

        $p = $this->enEmpresa(fn () => PrestamoLlave::where('llave_id', $l->id)->firstOrFail());
        $this->assertSame([PrestamoLlave::EN_USO, false, $this->centro->id, $this->admin->id, $this->admin->id, 'INE-55102'],
            [$p->estado, $p->anulado, $p->sede_id, $p->entregado_por, $p->creado_por, $p->folio_garantia]);
        $this->assertNotNull($p->prestado_en);

        $auditoria = Auditoria::where('evento', 'prestamo_llaves.creado')->where('auditable_id', $p->id)->firstOrFail();
        $this->assertSame('HDC-101', $auditoria->despues['llave']);
        $this->assertSame('EN USO', $auditoria->despues['estado']);
    }

    public function test_prestar_sin_javascript_redirige_y_los_errores_vuelven_al_dialogo(): void
    {
        $l = $this->llave('HDC-101');
        $c = $this->colaborador('1007', $this->centro);

        $this->actingAs($this->admin)->post('/prestamo-llaves', $this->datos($l, $c))
            ->assertRedirect(route('prestamo_llaves.index').'#prestamo-'.$this->enEmpresa(fn () => PrestamoLlave::value('id')))
            ->assertSessionHas('ok', 'Llave «HDC-101» entregada a Persona Num1007.');

        $this->actingAs($this->admin)->from('/prestamo-llaves')
            ->post('/prestamo-llaves', ['_dialogo' => 'prestar', 'sede_id' => $this->centro->id, 'tipo_garantia' => 'ine'])
            ->assertRedirect('/prestamo-llaves')
            ->assertSessionHasErrors(['llave_id' => 'Escanea o busca la llave a prestar.', 'colaborador_id' => 'Escanea el gafete o busca al colaborador que se lleva la llave.']);
        $this->actingAs($this->admin)->get('/prestamo-llaves')->assertSee('data-abrir-al-cargar', false);
    }

    public function test_validaciones_con_mensajes_claros(): void
    {
        $l = $this->llave('HDC-101');
        $c = $this->colaborador('1007', $this->centro);

        $this->actingAs($this->admin)->postJson('/prestamo-llaves', [])->assertStatus(422)
            ->assertJsonPath('errores.sede_id.0', 'Elige la sede donde se presta la llave.')
            ->assertJsonPath('errores.tipo_garantia.0', 'Elige la identificación que deja en garantía (o «Ninguna»).');
        $this->actingAs($this->admin)->postJson('/prestamo-llaves', $this->datos($l, $c, ['tipo_garantia' => 'credencial']))->assertStatus(422)
            ->assertJsonPath('errores.tipo_garantia.0', 'Elige la identificación que deja en garantía de la lista.');
        $this->actingAs($this->admin)->postJson('/prestamo-llaves', $this->datos($l, $c, ['folio_garantia' => str_repeat('x', 81)]))->assertStatus(422)
            ->assertJsonPath('errores.folio_garantia.0', 'El folio o detalle de la identificación es muy largo (máximo 80 caracteres).');

        // Ninguna (Riesgo) sin folio sí se acepta
        $this->actingAs($this->admin)->postJson('/prestamo-llaves', $this->datos($l, $c, ['tipo_garantia' => 'ninguna', 'folio_garantia' => '']))->assertCreated();
        $this->assertSame('NINGUNA', $this->enEmpresa(fn () => PrestamoLlave::firstOrFail())->textoGarantia());
    }

    public function test_reglas_de_la_llave_activa_de_la_sede_y_no_prestada(): void
    {
        $c = $this->colaborador('1007', $this->centro);
        $baja = $this->llave('HDC-BAJA', null, null, false);
        $dePlaya = $this->llave('HDP-01', $this->playa);
        $libre = $this->llave('HDC-101');

        $this->actingAs($this->admin)->postJson('/prestamo-llaves', $this->datos($baja, $c))->assertStatus(422)
            ->assertJsonPath('errores.llave_id.0', 'La llave HDC-BAJA está dada de baja: no se puede prestar.');
        $this->actingAs($this->admin)->postJson('/prestamo-llaves', $this->datos($dePlaya, $c, ['sede_id' => $this->centro->id]))->assertStatus(422)
            ->assertJsonPath('errores.llave_id.0', 'La llave HDP-01 es de otra sede. Elige la sede correcta o escanea otra llave.');
        $this->actingAs($this->admin)->postJson('/prestamo-llaves', $this->datos($libre, $c, ['llave_id' => 999999]))->assertStatus(422)
            ->assertJsonPath('errores.llave_id.0', 'Esa llave no existe en el catálogo de tu empresa.');

        $this->actingAs($this->admin)->postJson('/prestamo-llaves', $this->datos($libre, $c))->assertCreated();
        $otro = $this->colaborador('1009', $this->centro);
        $this->actingAs($this->admin)->postJson('/prestamo-llaves', $this->datos($libre, $otro))->assertStatus(422)
            ->assertJsonPath('errores.llave_id.0', 'Esa llave ya está fuera — la tiene Persona Num1007 en este momento.');
        $this->assertSame(1, $this->enEmpresa(fn () => PrestamoLlave::count()));
    }

    public function test_la_base_impide_dos_prestamos_vigentes_de_la_misma_llave(): void
    {
        $l = $this->llave('HDC-101');
        $c = $this->colaborador('1007', $this->centro);
        $this->prestamo($l, $c);
        // Uno anulado o devuelto no choca
        $this->prestamo($l, $c, ['anulado' => true]);
        $this->prestamo($l, $c, ['estado' => PrestamoLlave::DEVUELTA]);

        $this->expectException(UniqueConstraintViolationException::class);
        $this->prestamo($l, $c);
    }

    public function test_reglas_del_colaborador_activo_de_la_sede_o_corporativo(): void
    {
        $l = $this->llave('HDC-101');
        $deBaja = $this->colaborador('2001', $this->centro, null, ['activo' => false]);
        $dePlaya = $this->colaborador('2002', $this->playa);

        $this->actingAs($this->admin)->postJson('/prestamo-llaves', $this->datos($l, $deBaja))->assertStatus(422)
            ->assertJsonPath('errores.colaborador_id.0', 'El colaborador elegido no existe en tu empresa o está dado de baja.');
        $this->actingAs($this->admin)->postJson('/prestamo-llaves', $this->datos($l, $dePlaya))->assertStatus(422)
            ->assertJsonPath('errores.colaborador_id.0', 'Persona Num2002 no trabaja en la sede Sede CEN. El colaborador elegido no es válido para esta sede.');

        // Con la sede de Centro como adicional, sí
        $this->enEmpresa(fn () => $dePlaya->sedesAdicionales()->sync([$this->centro->id]));
        $this->actingAs($this->admin)->postJson('/prestamo-llaves', $this->datos($l, $dePlaya))->assertCreated();

        // Corporativo (sin sede fija) y provisional de la caseta, también
        $corporativo = $this->colaborador('2003');
        $this->actingAs($this->admin)->postJson('/prestamo-llaves', $this->datos($this->llave('HDC-102'), $corporativo))->assertCreated();
        $provisional = $this->colaborador('', $this->centro, null, ['provisional' => true, 'num_empleado' => null]);
        $this->actingAs($this->admin)->postJson('/prestamo-llaves', $this->datos($this->llave('HDC-103'), $provisional))->assertCreated();
    }

    // ------------------------------------------------------- Transiciones

    public function test_recibir_en_uso_a_devuelta_y_no_dos_veces(): void
    {
        $l = $this->llave('HDC-101');
        $p = $this->prestamo($l, $this->colaborador('1007', $this->centro));

        $this->actingAs($this->admin)->patch("/prestamo-llaves/{$p->id}/recibir")->assertRedirect(route('prestamo_llaves.index'))
            ->assertSessionHas('ok', 'Llave «HDC-101» recibida de vuelta correctamente. Devuelve la identificación en garantía.');
        $p = $this->buscar($p->id);
        $this->assertSame([PrestamoLlave::DEVUELTA, $this->admin->id], [$p->estado, $p->recibido_por]);
        $regreso = $p->devuelto_en;
        $this->assertNotNull($regreso);
        $this->assertTrue(Auditoria::where('evento', 'prestamo_llaves.recibido')->where('auditable_id', $p->id)->exists());

        // SEGCAT permitía "recibir" otra vez y cambiaba la hora de regreso
        $this->actingAs($this->admin)->patch("/prestamo-llaves/{$p->id}/recibir")->assertSessionHasErrors(['prestamo' => 'Esta llave ya se había recibido en caseta.']);
        $this->assertEquals($regreso, $this->buscar($p->id)->devuelto_en);

        // La llave ya se puede prestar otra vez
        $this->actingAs($this->admin)->postJson('/prestamo-llaves', $this->datos($l, $this->colaborador('1009', $this->centro)))->assertCreated();
    }

    public function test_anular_libera_la_llave_y_solo_aplica_a_prestamos_en_uso(): void
    {
        $l = $this->llave('HDC-101');
        $c = $this->colaborador('1007', $this->centro);
        $p = $this->prestamo($l, $c);

        $this->actingAs($this->admin)->patch("/prestamo-llaves/{$p->id}/anular")
            ->assertSessionHas('aviso', 'Préstamo '.$p->folio().' anulado — la llave «HDC-101» vuelve a estar disponible.');
        $p = $this->buscar($p->id);
        $this->assertTrue($p->anulado);
        $this->assertSame($this->admin->id, $p->anulado_por);
        $this->assertSame(PrestamoLlave::EN_USO, $p->estado);
        $this->assertTrue(Auditoria::where('evento', 'prestamo_llaves.anulado')->where('auditable_id', $p->id)->exists());

        // Anulado: no se recibe ni se anula otra vez
        $this->actingAs($this->admin)->patch("/prestamo-llaves/{$p->id}/recibir")->assertSessionHasErrors(['prestamo' => 'Este préstamo está anulado: no hay llave que recibir.']);
        $this->actingAs($this->admin)->patch("/prestamo-llaves/{$p->id}/anular")->assertSessionHasErrors(['prestamo' => 'Este préstamo ya está anulado.']);

        // Uno ya devuelto tampoco se anula
        $devuelto = $this->prestamo($this->llave('HDC-102'), $c, ['estado' => PrestamoLlave::DEVUELTA, 'devuelto_en' => now()]);
        $this->actingAs($this->admin)->patch("/prestamo-llaves/{$devuelto->id}/anular")
            ->assertSessionHasErrors(['prestamo' => 'Solo se anula un préstamo en uso. Este ya se recibió en caseta.']);

        // La llave anulada vuelve a estar disponible y pasa al historial
        $this->actingAs($this->admin)->postJson('/prestamo-llaves', $this->datos($l, $c))->assertCreated();
        $this->actingAs($this->admin)->get('/prestamo-llaves')->assertSee('ANULADO')->assertSee('Reactivar');
    }

    public function test_reactivar_solo_anulados_y_si_la_llave_no_se_volvio_a_prestar(): void
    {
        $l = $this->llave('HDC-101');
        $c = $this->colaborador('1007', $this->centro);
        $p = $this->prestamo($l, $c, ['anulado' => true, 'anulado_en' => now(), 'anulado_por' => $this->admin->id]);
        $vigente = $this->prestamo($this->llave('HDC-102'), $c);

        $this->actingAs($this->admin)->patch("/prestamo-llaves/{$vigente->id}/reactivar")->assertSessionHasErrors(['prestamo' => 'Este préstamo no está anulado.']);

        // Mientras tanto la llave se prestó en otro registro: no se reactiva
        $otro = $this->prestamo($l, $this->colaborador('1009', $this->centro));
        $this->actingAs($this->admin)->patch("/prestamo-llaves/{$p->id}/reactivar")
            ->assertSessionHasErrors(['prestamo' => 'No se puede reactivar — esa llave ya se volvió a prestar en otro registro mientras tanto.']);
        $this->assertTrue($this->buscar($p->id)->anulado);

        // Ya devuelta la otra, sí
        $this->enEmpresa(fn () => $otro->forceFill(['estado' => PrestamoLlave::DEVUELTA, 'devuelto_en' => now()])->save());
        $this->actingAs($this->admin)->patch("/prestamo-llaves/{$p->id}/reactivar")
            ->assertSessionHas('ok', 'Préstamo '.$p->folio().' reactivado — vuelve a contar como válido.');
        $p = $this->buscar($p->id);
        $this->assertFalse($p->anulado);
        $this->assertNull($p->anulado_por);
        $this->assertTrue($p->vigente());
        $this->assertTrue(Auditoria::where('evento', 'prestamo_llaves.reactivado')->where('auditable_id', $p->id)->exists());

        // Un anulado que ya estaba devuelto se reactiva sin revisar la llave
        $devueltoAnulado = $this->prestamo($l, $c, ['estado' => PrestamoLlave::DEVUELTA, 'devuelto_en' => now(), 'anulado' => true]);
        $this->actingAs($this->admin)->patch("/prestamo-llaves/{$devueltoAnulado->id}/reactivar")->assertSessionHasNoErrors();
    }

    // -------------------------------------------------- Historial y Excel

    public function test_historial_de_una_llave_con_usada_por_entrego_y_recibio(): void
    {
        $l = $this->llave('HDC-101');
        $c = $this->colaborador('1007', $this->centro);
        $this->prestamo($l, $c, ['estado' => PrestamoLlave::DEVUELTA, 'devuelto_en' => now(), 'recibido_por' => $this->admin->id]);
        $this->prestamo($l, $c, ['anulado' => true, 'anulado_en' => now(), 'anulado_por' => $this->admin->id]);
        $this->prestamo($l, $c);

        $this->actingAs($this->admin)->get("/prestamo-llaves/llaves/{$l->id}/historial")->assertOk()
            ->assertSee('Historial de Movimientos')->assertSee('Llave: HDC-101 - Acceso de prueba HDC-101')
            ->assertSee('Persona Num1007')->assertSee('AÚN EN USO')->assertSee('Recibió:')->assertSee('ANULADO')->assertSee('ID Garantía: INE');

        $sinUso = $this->llave('HDC-NUEVA');
        $this->actingAs($this->admin)->get("/prestamo-llaves/llaves/{$sinUso->id}/historial")->assertSee('No hay registros de uso para esta llave aún.');
    }

    public function test_excel_de_auditoria_con_filtros(): void
    {
        $c = $this->colaborador('1007', $this->centro);
        $this->prestamo($this->llave('HDC-FUERA'), $c);
        $this->prestamo($this->llave('HDC-DEVUELTA'), $c, ['estado' => PrestamoLlave::DEVUELTA, 'devuelto_en' => now(), 'recibido_por' => $this->admin->id]);
        $this->prestamo($this->llave('HDC-ANULADA'), $c, ['anulado' => true, 'anulado_en' => now(), 'anulado_por' => $this->admin->id]);
        $this->prestamo($this->llave('HDP-PLAYA', $this->playa), $this->colaborador('1008', $this->playa));

        $respuesta = $this->actingAs($this->admin)->get('/prestamo-llaves/exportar')->assertOk();
        $this->assertStringContainsString('Auditoria_Llaves_', $respuesta->headers->get('content-disposition'));
        $todo = $respuesta->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $todo);
        $this->assertStringContainsString('Folio,Sede,"Código de llave"', $todo);
        foreach (['HDC-FUERA', 'HDC-DEVUELTA', 'HDC-ANULADA', 'HDP-PLAYA', 'PENDIENTE', 'ANULADO', 'DEVUELTA'] as $texto) {
            $this->assertStringContainsString($texto, $todo);
        }

        $enUso = $this->actingAs($this->admin)->get('/prestamo-llaves/exportar?estado=en_uso&sede='.$this->centro->id)->streamedContent();
        $this->assertStringContainsString('HDC-FUERA', $enUso);
        $this->assertStringNotContainsString('HDC-DEVUELTA', $enUso);
        $this->assertStringNotContainsString('HDC-ANULADA', $enUso);
        $this->assertStringNotContainsString('HDP-PLAYA', $enUso);

        $anulados = $this->actingAs($this->admin)->get('/prestamo-llaves/exportar?estado=anulado')->streamedContent();
        $this->assertStringContainsString('HDC-ANULADA', $anulados);
        $this->assertStringNotContainsString('HDC-FUERA', $anulados);

        $manana = now('America/Cancun')->addDay()->format('Y-m-d');
        $this->assertStringNotContainsString('HDC-FUERA', $this->actingAs($this->admin)->get('/prestamo-llaves/exportar?desde='.$manana)->streamedContent());
        $this->actingAs($this->admin)->get('/prestamo-llaves/exportar?estado=perdida')->assertSessionHasErrors('estado');
    }

    // ------------------------------------------------- Permisos y aislamiento

    public function test_cada_empresa_ve_y_toca_solo_sus_prestamos(): void
    {
        $mia = $this->prestamo($this->llave('MIA-1'), $this->colaborador('1007', $this->centro));
        $otra = $this->crearEmpresa('Hotel Dos');
        $sedeOtra = $this->crearSede($otra, 'Z1');
        $llaveAjena = $this->llave('AJENA-1', $sedeOtra, $otra);
        $colaboradorAjeno = $this->colaborador('9001', $sedeOtra, $otra);
        $ajeno = $this->prestamo($llaveAjena, $colaboradorAjeno, [], $otra);

        $this->actingAs($this->admin)->get('/prestamo-llaves')->assertSee('MIA-1')->assertDontSee('AJENA-1');
        foreach (['recibir', 'anular', 'reactivar'] as $accion) {
            $this->actingAs($this->admin)->patch("/prestamo-llaves/{$ajeno->id}/{$accion}")->assertNotFound();
        }
        $this->actingAs($this->admin)->get("/prestamo-llaves/llaves/{$llaveAjena->id}/historial")->assertNotFound();
        $this->assertSame(PrestamoLlave::EN_USO, PrestamoLlave::withoutGlobalScopes()->findOrFail($ajeno->id)->estado);

        // Ni prestar una llave, a un colaborador o en una sede de otra empresa
        $c = $this->colaborador('1009', $this->centro);
        $this->actingAs($this->admin)->postJson('/prestamo-llaves', ['sede_id' => $sedeOtra->id, 'llave_id' => $llaveAjena->id, 'colaborador_id' => $c->id, 'tipo_garantia' => 'ine'])
            ->assertStatus(422)->assertJsonPath('errores.sede_id.0', 'Elige una de tus sedes activas.');
        $this->actingAs($this->admin)->postJson('/prestamo-llaves', ['sede_id' => $this->centro->id, 'llave_id' => $llaveAjena->id, 'colaborador_id' => $c->id, 'tipo_garantia' => 'ine'])
            ->assertStatus(422)->assertJsonPath('errores.llave_id.0', 'Esa llave no existe en el catálogo de tu empresa.');
        $this->actingAs($this->admin)->postJson('/prestamo-llaves', $this->datos($this->llave('MIA-2'), $colaboradorAjeno))
            ->assertStatus(422)->assertJsonPath('errores.colaborador_id.0', 'El colaborador elegido no existe en tu empresa o está dado de baja.');
        $this->assertStringNotContainsString('AJENA-1', $this->actingAs($this->admin)->get('/prestamo-llaves/exportar')->streamedContent());
        $this->assertTrue($this->buscar($mia->id)->vigente());
    }

    public function test_alcance_de_sede_solo_ve_y_presta_en_sus_sedes(): void
    {
        $jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        $deCentro = $this->prestamo($this->llave('HDC-1'), $this->colaborador('1007', $this->centro));
        $dePlaya = $this->prestamo($this->llave('HDP-1', $this->playa), $this->colaborador('1008', $this->playa));

        $this->actingAs($jefe)->get('/prestamo-llaves')->assertOk()->assertSee('HDC-1')->assertDontSee('HDP-1');
        $this->actingAs($jefe)->patch("/prestamo-llaves/{$dePlaya->id}/recibir")->assertNotFound();
        $this->actingAs($jefe)->patch("/prestamo-llaves/{$dePlaya->id}/anular")->assertNotFound();
        $this->actingAs($jefe)->get("/prestamo-llaves/llaves/{$dePlaya->llave_id}/historial")->assertNotFound();
        $this->assertStringNotContainsString('HDP-1', $this->actingAs($jefe)->get('/prestamo-llaves/exportar')->streamedContent());

        $llavePlaya = $this->llave('HDP-2', $this->playa);
        $this->actingAs($jefe)->postJson('/prestamo-llaves', $this->datos($llavePlaya, $this->colaborador('1010', $this->playa)))
            ->assertStatus(422)->assertJsonPath('errores.sede_id.0', 'Elige una de tus sedes activas.');
        $this->actingAs($jefe)->patch("/prestamo-llaves/{$deCentro->id}/recibir")->assertSessionHasNoErrors();
    }

    public function test_el_agente_presta_y_recibe_pero_no_anula_ni_exporta(): void
    {
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $l = $this->llave('HDC-1');
        $c = $this->colaborador('1007', $this->centro);
        $anulado = $this->prestamo($this->llave('HDC-2'), $c, ['anulado' => true]);

        $this->actingAs($agente)->get('/prestamo-llaves')->assertOk()->assertSee('Prestar Llave')
            ->assertDontSee('Excel (Auditoría)')->assertDontSee('Reactivar')->assertDontSee('Anular el préstamo');
        $this->actingAs($agente)->postJson('/prestamo-llaves', $this->datos($l, $c))->assertCreated();
        $p = $this->enEmpresa(fn () => PrestamoLlave::where('llave_id', $l->id)->firstOrFail());

        $this->actingAs($agente)->get('/prestamo-llaves')->assertSee('Recibir Llave a Caseta')->assertDontSee('Anular el préstamo');
        $this->actingAs($agente)->patch("/prestamo-llaves/{$p->id}/anular")->assertForbidden();
        $this->actingAs($agente)->patch("/prestamo-llaves/{$anulado->id}/reactivar")->assertForbidden();
        $this->actingAs($agente)->get('/prestamo-llaves/exportar')->assertForbidden();
        $this->actingAs($agente)->patch("/prestamo-llaves/{$p->id}/recibir")->assertSessionHasNoErrors();
        $this->assertSame(PrestamoLlave::DEVUELTA, $this->buscar($p->id)->estado);

        // Sin el módulo, ni la pantalla
        $this->actingAs($this->crearUsuario($this->empresa))->get('/prestamo-llaves')->assertForbidden();
        $this->actingAs($this->crearUsuario($this->empresa))->postJson('/prestamo-llaves', $this->datos($l, $c))->assertForbidden();
    }

    public function test_el_asistente_presta_y_exporta_pero_no_anula(): void
    {
        $asistente = $this->crearUsuario($this->empresa, 'Asistente', $this->centro);
        $p = $this->prestamo($this->llave('HDC-1'), $this->colaborador('1007', $this->centro));

        $this->actingAs($asistente)->get('/prestamo-llaves')->assertOk()->assertSee('Excel (Auditoría)')->assertSee('Prestar Llave');
        $this->actingAs($asistente)->get('/prestamo-llaves/exportar')->assertOk();
        $this->actingAs($asistente)->patch("/prestamo-llaves/{$p->id}/anular")->assertForbidden();
    }

    // ---------------------------------------------------- Ganchos con otros módulos

    public function test_el_catalogo_de_llaves_muestra_en_uso_y_quien_la_tiene(): void
    {
        $fuera = $this->llave('HDC-FUERA');
        $this->llave('HDC-CASETA');
        $this->prestamo($fuera, $this->colaborador('1007', $this->centro));

        $pagina = $this->actingAs($this->admin)->get('/llaves')->assertOk()
            ->assertSee('Usada por: Persona Num1007')->assertSee('Historial de préstamos')
            ->assertSee(route('prestamo_llaves.index').'#historial-llave-'.$fuera->id, false)->getContent();
        $this->assertSame(1, substr_count($pagina, 'etiqueta-estado en-uso'));
        $this->enEmpresa(function () use ($fuera) {
            $this->assertTrue(Llave::withExists(['prestamoAbierto as en_uso'])->findOrFail($fuera->id)->enUso());
        });
    }

    public function test_unir_un_colaborador_provisional_mueve_sus_prestamos(): void
    {
        $provisional = $this->colaborador('', $this->centro, null, ['provisional' => true, 'num_empleado' => null]);
        $correcto = $this->colaborador('1007', $this->centro);
        $p = $this->prestamo($this->llave('HDC-1'), $provisional);

        $this->enEmpresa(fn () => app(AdministradorColaboradores::class)->fusionar($this->admin, $provisional, $correcto));

        $this->assertSame($correcto->id, $this->buscar($p->id)->colaborador_id);
    }

    public function test_menu_enlaza_al_prestamo_de_llaves(): void
    {
        $this->actingAs($this->admin)->get('/')->assertOk()->assertSee(route('prestamo_llaves.index'));
    }
}
