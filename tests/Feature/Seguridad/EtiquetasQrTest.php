<?php

namespace Tests\Feature\Seguridad;

use App\Models\Auditoria;
use App\Models\Empresa;
use App\Models\EtiquetaPlantilla;
use App\Models\ImpresionEtiquetas;
use App\Models\Llave;
use App\Models\Sede;
use App\Models\User;
use App\Models\Vehiculo;
use App\Support\Tenancy\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Feature\Nucleo\CreaDatosNucleo;
use Tests\TestCase;

/**
 * Ronda 7 (parte B): gestor centralizado de impresión QR — plantillas
 * (rollo térmico u hoja carta/A4), filtros por fecha de alta, tipo y
 * estatus, e historial con «Reimprimir».
 */
class EtiquetasQrTest extends TestCase
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

    private function llave(string $nomenclatura, ?Sede $sede = null, array $extra = []): Llave
    {
        return $this->enEmpresa(function () use ($nomenclatura, $sede, $extra) {
            $l = Llave::create(['sede_id' => ($sede ?? $this->centro)->id, 'nomenclatura' => $nomenclatura, 'descripcion' => 'Acceso de prueba', 'tipo_dispositivo' => 'metalica', 'alcance' => 'global']);
            $l->forceFill($extra)->save();

            return $l;
        });
    }

    /** @return array<string, mixed> */
    private function datos(array $extra = []): array
    {
        return array_merge([
            'nombre' => 'Zebra 2 × 1', 'formato' => 'rollo', 'ancho_mm' => '50.8', 'alto_mm' => '25.4', 'separacion_vertical_mm' => '0',
            'orientacion' => 'horizontal', 'qr_mm' => '21', 'mostrar_titulo' => '1', 'mostrar_codigo' => '1', 'mostrar_tipo' => '1',
            'mostrar_ubicacion' => '0', 'mostrar_fecha' => '0', 'mostrar_logo' => '0',
        ], $extra);
    }

    private function plantilla(string $clave): EtiquetaPlantilla
    {
        return $this->enEmpresa(fn () => EtiquetaPlantilla::where('clave', $clave)->firstOrFail());
    }

    // ============================================================= Plantillas

    public function test_las_cuatro_plantillas_de_siempre_y_permisos_de_configurar(): void
    {
        $this->actingAs($this->admin)->get('/etiquetas/plantillas')->assertOk()
            ->assertSee('Llavero pequeño')->assertSee('Etiqueta 50 × 25 mm')->assertSee('Gafete')->assertSee('Calcomanía vehicular')
            ->assertSee('86 × 54 mm · hoja carta, 2 × 4 = 8 por hoja')->assertSee('Plantilla de siempre');
        $this->assertSame(4, $this->enEmpresa(fn () => EtiquetaPlantilla::count()));
        // Se crean una sola vez
        $this->actingAs($this->admin)->get('/etiquetas')->assertOk();
        $this->assertSame(4, $this->enEmpresa(fn () => EtiquetaPlantilla::count()));

        // Plantillas de rol: Administrador, Director y Jefe de seguridad configuran; nadie más
        $director = $this->crearUsuario($this->empresa, 'Director');
        $this->actingAs($director)->get('/etiquetas/plantillas')->assertOk();
        $this->actingAs($this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro))->get('/etiquetas/plantillas')->assertOk();
        foreach (['Supervisor', 'Asistente', 'Agente'] as $rol) {
            $this->actingAs($this->crearUsuario($this->empresa, $rol, $this->centro))->get('/etiquetas/plantillas')->assertForbidden();
        }
        // La pestaña «Plantillas» solo se ve con el permiso
        $this->actingAs($this->admin)->get('/etiquetas')->assertSee(route('etiquetas.plantillas'), false);
        $this->actingAs($this->crearUsuario($this->empresa, 'Supervisor', $this->centro))->get('/etiquetas')->assertOk()->assertDontSee(route('etiquetas.plantillas'), false);
    }

    public function test_crear_editar_desactivar_y_reactivar_plantilla_con_auditoria(): void
    {
        $this->actingAs($this->admin)->get('/etiquetas/plantillas/nueva')->assertOk()->assertSee('Nueva plantilla')->assertSee('Vista previa');
        $this->actingAs($this->admin)->post('/etiquetas/plantillas', $this->datos())->assertRedirect(route('etiquetas.plantillas'));
        $zebra = $this->enEmpresa(fn () => EtiquetaPlantilla::where('nombre', 'Zebra 2 × 1')->firstOrFail());
        $this->assertSame([50.8, 25.4, 'rollo', null, 1, 1], [$zebra->ancho_mm, $zebra->alto_mm, $zebra->formato, $zebra->sede_id, $zebra->columnas, $zebra->filas]);
        $this->assertSame($this->admin->id, $zebra->creado_por);
        $this->assertTrue(Auditoria::where('evento', 'etiquetas_qr.plantilla_creada')->where('auditable_id', $zebra->id)->exists());

        // Hoja carta 3 × 10 (planilla de 66.7 × 25.4 mm)
        $this->actingAs($this->admin)->post('/etiquetas/plantillas', $this->datos(['nombre' => 'Planilla 3 × 10', 'formato' => 'hoja', 'papel' => 'carta',
            'ancho_mm' => '66.7', 'alto_mm' => '25.4', 'columnas' => 3, 'filas' => 10, 'margen_superior_mm' => '12.7', 'margen_izquierdo_mm' => '4.8',
            'separacion_horizontal_mm' => '3.2', 'separacion_vertical_mm' => '0']))->assertSessionHasNoErrors();

        // Editar (con coma decimal) y la edición se audita
        $this->actingAs($this->admin)->get("/etiquetas/plantillas/{$zebra->id}/editar")->assertOk()->assertSee('Zebra 2 × 1');
        $this->actingAs($this->admin)->put("/etiquetas/plantillas/{$zebra->id}", $this->datos(['qr_mm' => '20,5', 'orientacion' => 'vertical', 'alto_mm' => '40']))
            ->assertSessionHasNoErrors()->assertRedirect(route('etiquetas.plantillas'));
        $this->assertSame([20.5, 'vertical'], [$zebra->fresh()->qr_mm, $zebra->fresh()->orientacion]);
        $this->assertTrue(Auditoria::where('evento', 'etiquetas_qr.plantilla_actualizada')->exists());

        // Desactivar y reactivar
        $this->actingAs($this->admin)->patch("/etiquetas/plantillas/{$zebra->id}/estado", ['activo' => 0])->assertSessionHas('aviso');
        $this->assertFalse($zebra->fresh()->activo);
        $this->actingAs($this->admin)->get('/etiquetas')->assertDontSee('>Zebra 2 × 1</option>', false);
        $this->actingAs($this->admin)->patch("/etiquetas/plantillas/{$zebra->id}/estado", ['activo' => 1])->assertSessionHas('ok');
        $this->assertTrue($zebra->fresh()->activo);
        $this->assertSame(1, Auditoria::where('evento', 'etiquetas_qr.plantilla_desactivada')->count());
        $this->assertSame(1, Auditoria::where('evento', 'etiquetas_qr.plantilla_reactivada')->count());
    }

    public function test_validacion_de_medidas_con_mensajes_claros(): void
    {
        $this->actingAs($this->admin)->get('/etiquetas/plantillas'); // crea las de siempre
        $this->actingAs($this->admin)->post('/etiquetas/plantillas', $this->datos(['qr_mm' => '30']))
            ->assertSessionHasErrors(['qr_mm' => 'El QR de 30 mm no cabe en una etiqueta de 50.8 × 25.4 mm: puede medir hasta 22.4 mm.']);
        $this->actingAs($this->admin)->post('/etiquetas/plantillas', $this->datos(['qr_mm' => '5']))
            ->assertSessionHasErrors(['qr_mm' => 'El QR debe medir al menos 8 mm para que la cámara lo lea.']);
        $this->actingAs($this->admin)->post('/etiquetas/plantillas', $this->datos(['orientacion' => 'vertical']))->assertSessionHasErrors('qr_mm');
        $this->actingAs($this->admin)->post('/etiquetas/plantillas', $this->datos(['mostrar_titulo' => '0', 'mostrar_codigo' => '0']))
            ->assertSessionHasErrors(['mostrar_titulo' => 'Marca al menos el nombre o el código legible: una etiqueta solo con el QR no se reconoce a simple vista.']);
        $this->actingAs($this->admin)->post('/etiquetas/plantillas', $this->datos(['nombre' => 'llavero PEQUEÑO']))
            ->assertSessionHasErrors(['nombre' => 'Ya existe una plantilla con ese nombre en esta empresa.']);
        $this->actingAs($this->admin)->post('/etiquetas/plantillas', $this->datos(['nombre' => '']))->assertSessionHasErrors('nombre');
        // La planilla no cabe en la hoja
        $this->actingAs($this->admin)->post('/etiquetas/plantillas', $this->datos(['formato' => 'hoja', 'papel' => 'carta', 'ancho_mm' => '70', 'alto_mm' => '30',
            'columnas' => 3, 'filas' => 10, 'margen_superior_mm' => '10', 'margen_izquierdo_mm' => '5', 'separacion_horizontal_mm' => '3']))
            ->assertSessionHasErrors([
                'columnas' => 'No caben 3 columnas en la hoja carta: ocupan 221 mm (con el margen izquierdo y la separación) y la hoja mide 215.9 mm de ancho. Quita una columna o reduce las medidas.',
                'filas' => 'No caben 10 filas en la hoja carta: ocupan 310 mm (con el margen superior y la separación) y la hoja mide 279.4 mm de alto. Quita una fila o reduce las medidas.',
            ]);
        $this->actingAs($this->admin)->post('/etiquetas/plantillas', $this->datos(['formato' => 'hoja']))->assertSessionHasErrors(['papel', 'columnas', 'filas']);
        $this->assertSame(4, $this->enEmpresa(fn () => EtiquetaPlantilla::count()));
    }

    public function test_alcance_de_sede_empresa_y_ultima_plantilla_activa(): void
    {
        $this->actingAs($this->admin)->get('/etiquetas/plantillas');
        $jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        // El Jefe solo crea plantillas de su sede
        $this->actingAs($jefe)->post('/etiquetas/plantillas', $this->datos())->assertSessionHasErrors(['sede_id' => 'Elige la sede de la plantilla.']);
        $this->actingAs($jefe)->post('/etiquetas/plantillas', $this->datos(['sede_id' => $this->playa->id]))->assertSessionHasErrors(['sede_id' => 'Elige una de tus sedes.']);
        $this->actingAs($jefe)->post('/etiquetas/plantillas', $this->datos(['sede_id' => $this->centro->id]))->assertSessionHasNoErrors();
        $deCentro = $this->enEmpresa(fn () => EtiquetaPlantilla::where('nombre', 'Zebra 2 × 1')->firstOrFail());
        $this->assertSame($this->centro->id, $deCentro->sede_id);

        // Las de toda la empresa las ve pero no las cambia (403); la de otra sede no existe para él (404)
        $llavero = $this->plantilla('llavero');
        $this->actingAs($jefe)->get('/etiquetas/plantillas')->assertOk()->assertSee('solo la cambia quien administra toda la empresa');
        $this->actingAs($jefe)->get("/etiquetas/plantillas/{$llavero->id}/editar")->assertForbidden();
        $this->actingAs($jefe)->put("/etiquetas/plantillas/{$llavero->id}", $this->datos(['nombre' => 'X']))->assertForbidden();
        $dePlaya = $this->enEmpresa(fn () => EtiquetaPlantilla::create($this->datos(['nombre' => 'De Playa', 'sede_id' => $this->playa->id, 'ancho_mm' => 50.8, 'alto_mm' => 25.4, 'qr_mm' => 21])));
        $this->actingAs($jefe)->get("/etiquetas/plantillas/{$dePlaya->id}/editar")->assertNotFound();
        $this->actingAs($jefe)->patch("/etiquetas/plantillas/{$dePlaya->id}/estado", ['activo' => 0])->assertNotFound();
        $this->assertTrue($dePlaya->fresh()->activo);

        // Al imprimir, el Agente de Centro ve las de la empresa y la de Centro, no la de Playa
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->actingAs($this->admin)->get('/etiquetas')->assertSee('De Playa');
        $this->actingAs($jefe)->get('/etiquetas')->assertSee('Zebra 2 × 1')->assertDontSee('De Playa');

        // Otra empresa: 404
        $intruso = $this->crearUsuario($this->crearEmpresa('Hotel Dos'), 'Administrador');
        $this->actingAs($intruso)->get("/etiquetas/plantillas/{$llavero->id}/editar")->assertNotFound();
        $this->actingAs($intruso)->patch("/etiquetas/plantillas/{$llavero->id}/estado", ['activo' => 0])->assertNotFound();
        $this->assertNotNull($agente);

        // No se desactiva la última activa de toda la empresa
        $this->enEmpresa(fn () => EtiquetaPlantilla::whereNull('sede_id')->where('clave', '!=', 'etiqueta')->update(['activo' => false]));
        $this->actingAs($this->admin)->patch('/etiquetas/plantillas/'.$this->plantilla('etiqueta')->id.'/estado', ['activo' => 0])
            ->assertSessionHasErrors(['activo' => 'Es la única plantilla activa de toda la empresa: activa otra antes de desactivar esta (sin plantillas no se podría imprimir).']);
    }

    // ============================================================= Impresión

    public function test_imprimir_registra_el_historial_y_la_hoja_usa_las_medidas_de_la_plantilla(): void
    {
        $a = $this->llave('HDC-101');
        $b = $this->llave('HDC-102');
        $this->actingAs($this->admin)->get('/etiquetas')->assertOk()->assertSee('Llavero pequeño')->assertSee('name="plantilla"', false);
        $llavero = $this->plantilla('llavero');

        $r = $this->actingAs($this->admin)->post('/etiquetas/imprimir', ['sel' => ['llave-'.$a->id, 'llave-'.$b->id], 'plantilla' => $llavero->id]);
        $impresion = $this->enEmpresa(fn () => ImpresionEtiquetas::with('items')->latest('id')->firstOrFail());
        $r->assertRedirect(route('etiquetas.impresion', $impresion->id));
        $this->assertSame([2, $llavero->id, 'Llavero pequeño', $this->centro->id, $this->admin->id], [$impresion->cantidad, $impresion->plantilla_id, $impresion->plantilla_nombre, $impresion->sede_id, $impresion->creado_por]);
        $this->assertSame(['llave-'.$a->id, 'llave-'.$b->id], $impresion->items->map->clave()->all());
        $auditoria = Auditoria::where('evento', 'etiquetas_qr.impreso')->firstOrFail();
        $this->assertSame(2, $auditoria->despues['cantidad']);

        // Rollo: @page del tamaño de la etiqueta, una por página
        $this->actingAs($this->admin)->get(route('etiquetas.impresion', $impresion->id))->assertOk()
            ->assertSee('@page { size: 40mm 25mm; margin: 0; }', false)->assertSee('HDC-101')->assertSee('HDC-102')
            ->assertSee('Impresión núm. '.$impresion->id)->assertSee('<svg', false)
            ->assertSee(trim(chunk_split($a->codigo_qr, 4, ' ')));
        $this->assertSame(2, substr_count($this->actingAs($this->admin)->get(route('etiquetas.impresion', $impresion->id))->getContent(), '<section class="pagina-qr rollo"'));

        // Hoja: @page carta y la planilla de 2 × 4 en una sola página
        $gafete = $this->plantilla('gafete');
        $html = $this->actingAs($this->admin)->followingRedirects()->post('/etiquetas/imprimir', ['sel' => ['llave-'.$a->id, 'llave-'.$b->id], 'plantilla' => $gafete->id])
            ->assertSee('@page { size: 215.9mm 279.4mm; margin: 0; }', false)->assertSee('grid-template-columns: repeat(2, 86mm)', false)->getContent();
        $this->assertSame(1, substr_count($html, '<section class="pagina-qr hoja"'));

        // Se recuerda la última plantilla del usuario
        $this->actingAs($this->admin)->get('/etiquetas')->assertSee('<option value="'.$gafete->id.'" selected', false);
    }

    public function test_impresion_respeta_permisos_sedes_y_empresas(): void
    {
        $centro = $this->llave('HDC-101');
        $playa = $this->llave('HDP-201', $this->playa);
        $this->actingAs($this->admin)->post('/etiquetas/imprimir', ['sel' => ['llave-'.$playa->id]]);
        $dePlaya = $this->enEmpresa(fn () => ImpresionEtiquetas::latest('id')->firstOrFail());
        $this->assertSame($this->playa->id, $dePlaya->sede_id);

        $jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        $this->actingAs($jefe)->post('/etiquetas/imprimir', ['sel' => ['llave-'.$centro->id]])->assertRedirect();
        $deJefe = $this->enEmpresa(fn () => ImpresionEtiquetas::latest('id')->firstOrFail());

        // El Jefe de Centro no ve ni reimprime la de Playa
        $this->actingAs($jefe)->get(route('etiquetas.impresion', $dePlaya->id))->assertNotFound();
        $this->actingAs($jefe)->post(route('etiquetas.reimprimir', $dePlaya->id))->assertNotFound();
        $this->actingAs($jefe)->get('/etiquetas/historial')->assertOk()->assertSee('Impresión núm. '.$deJefe->id)->assertDontSee('Impresión núm. '.$dePlaya->id);
        $this->actingAs($this->admin)->get('/etiquetas/historial')->assertSee('Impresión núm. '.$deJefe->id)->assertSee('Impresión núm. '.$dePlaya->id);

        // Otra empresa: 404
        $intruso = $this->crearUsuario($this->crearEmpresa('Hotel Dos'), 'Administrador');
        $this->actingAs($intruso)->get(route('etiquetas.impresion', $deJefe->id))->assertNotFound();
        $this->actingAs($intruso)->post(route('etiquetas.reimprimir', $deJefe->id))->assertNotFound();
        $this->actingAs($intruso)->get('/etiquetas/historial')->assertOk()->assertDontSee('HDC-101');

        // Una plantilla de otra sede no se puede usar: se usa la preelegida
        $dePlantillaPlaya = $this->enEmpresa(fn () => EtiquetaPlantilla::create($this->datos(['nombre' => 'De Playa', 'sede_id' => $this->playa->id, 'ancho_mm' => 50.8, 'alto_mm' => 25.4, 'qr_mm' => 21])));
        $this->actingAs($jefe)->post('/etiquetas/imprimir', ['sel' => ['llave-'.$centro->id], 'plantilla' => $dePlantillaPlaya->id]);
        $this->assertNotSame($dePlantillaPlaya->id, $this->enEmpresa(fn () => ImpresionEtiquetas::latest('id')->value('plantilla_id')));

        // El Agente de Centro entra al historial, pero no ve impresiones de llaves (no imprime llaves)
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->actingAs($agente)->get('/etiquetas/historial')->assertOk()->assertDontSee('HDC-101')->assertSee('Todavía no hay impresiones.');
        $this->actingAs($agente)->get(route('etiquetas.impresion', $deJefe->id))->assertNotFound();

        // Sin el permiso del módulo: 403
        $sinRol = $this->crearUsuario($this->empresa);
        $this->actingAs($sinRol)->get('/etiquetas/historial')->assertForbidden();
        $this->actingAs($sinRol)->post('/etiquetas/imprimir', ['sel' => ['llave-'.$centro->id]])->assertForbidden();
    }

    public function test_reimprimir_todas_o_solo_algunas(): void
    {
        $a = $this->llave('HDC-101');
        $b = $this->llave('HDC-102');
        $c = $this->llave('HDC-103');
        $this->actingAs($this->admin)->post('/etiquetas/imprimir', ['sel' => ['llave-'.$a->id, 'llave-'.$b->id, 'llave-'.$c->id], 'tamano' => 'etiqueta']);
        $original = $this->enEmpresa(fn () => ImpresionEtiquetas::latest('id')->firstOrFail());

        $this->actingAs($this->admin)->get('/etiquetas/historial')->assertOk()
            ->assertSee('Impresión núm. '.$original->id)->assertSee('3 etiquetas')->assertSee('Etiqueta 50 × 25 mm · 3 Llaves')
            ->assertSee('data-accion="reimprimir-etiquetas"', false)->assertSee('HDC-101, HDC-102, HDC-103');

        // Solo la que se dañó; lo que no es de esa impresión se ignora
        $r = $this->actingAs($this->admin)->post(route('etiquetas.reimprimir', $original->id), ['sel' => ['llave-'.$b->id, 'llave-99999']]);
        $nueva = $this->enEmpresa(fn () => ImpresionEtiquetas::with('items')->latest('id')->firstOrFail());
        $r->assertRedirect(route('etiquetas.impresion', $nueva->id));
        $this->assertSame([1, $original->id, $original->plantilla_id, ['llave-'.$b->id]], [$nueva->cantidad, $nueva->reimpresion_de_id, $nueva->plantilla_id, $nueva->items->map->clave()->all()]);
        $this->assertSame($original->id, Auditoria::where('evento', 'etiquetas_qr.reimpreso')->firstOrFail()->despues['reimpresion_de']);

        // Todas, con otra plantilla
        $gafete = $this->plantilla('gafete');
        $this->actingAs($this->admin)->post(route('etiquetas.reimprimir', $original->id), ['plantilla' => $gafete->id]);
        $todas = $this->enEmpresa(fn () => ImpresionEtiquetas::latest('id')->firstOrFail());
        $this->assertSame([3, $gafete->id], [$todas->cantidad, $todas->plantilla_id]);

        // Nada marcado de esa impresión: regresa con aviso
        $this->actingAs($this->admin)->post(route('etiquetas.reimprimir', $original->id), ['sel' => ['llave-99999']])
            ->assertRedirect(route('etiquetas.historial'))->assertSessionHas('aviso', 'Marca al menos una etiqueta para reimprimir.');

        // Historial: reimpresión marcada y filtros
        $this->actingAs($this->admin)->get('/etiquetas/historial')->assertSee('Reimpresión de la núm. '.$original->id);
        $this->actingAs($this->admin)->get('/etiquetas/historial?q=hdc-102')->assertSee('Impresión núm. '.$nueva->id);
        $this->actingAs($this->admin)->get('/etiquetas/historial?plantilla='.$gafete->id)->assertSee('Impresión núm. '.$todas->id)->assertDontSee('Impresión núm. '.$nueva->id.'<');
        $this->actingAs($this->admin)->get('/etiquetas/historial?desde=2000-01-01&hasta=2000-01-02')->assertSee('No hay impresiones que coincidan con tu búsqueda.');
    }

    public function test_hoja_de_prueba_no_queda_en_el_historial(): void
    {
        $this->actingAs($this->admin)->get('/etiquetas/plantillas');
        $gafete = $this->plantilla('gafete');
        $html = $this->actingAs($this->admin)->get("/etiquetas/plantillas/{$gafete->id}/prueba")->assertOk()
            ->assertSee('Hoja de prueba')->assertSee('EJEMPLO-08')->assertSee('no se guardan en el historial')->getContent();
        $this->assertSame(8, substr_count($html, 'class="etiqueta-qr-r7'));
        $this->assertSame(0, $this->enEmpresa(fn () => ImpresionEtiquetas::count()));
        $this->actingAs($this->crearUsuario($this->empresa, 'Agente', $this->centro))->get("/etiquetas/plantillas/{$gafete->id}/prueba")->assertForbidden();
    }

    // ================================================================ Filtros

    public function test_filtros_por_fecha_de_alta_tipo_y_estatus(): void
    {
        Carbon::setTestNow('2026-09-01 15:00:00');
        $vieja = $this->llave('HDC-VIEJA');
        Carbon::setTestNow('2026-10-05 15:00:00');
        $this->llave('HDC-NUEVA');
        $this->llave('HDC-BAJA', null, ['activo' => false]);
        $this->enEmpresa(fn () => (new Vehiculo(['placas' => 'ABC123A', 'propiedad' => 'propio_huesped', 'tipo' => 'sedan', 'marca' => 'NISSAN', 'modelo' => 'VERSA', 'color' => 'BLANCO']))->save());
        Carbon::setTestNow();
        $this->assertNotNull($vieja);

        $this->actingAs($this->admin)->get('/etiquetas?desde=2026-10-01')->assertSee('HDC-NUEVA')->assertDontSee('HDC-VIEJA');
        $this->actingAs($this->admin)->get('/etiquetas?hasta=2026-09-30')->assertSee('HDC-VIEJA')->assertDontSee('HDC-NUEVA')->assertSee('Quitar filtros');
        $this->actingAs($this->admin)->get('/etiquetas?desde=2026-09-01&hasta=2026-09-01')->assertSee('HDC-VIEJA')->assertDontSee('HDC-NUEVA');
        $this->actingAs($this->admin)->get('/etiquetas?estado=baja')->assertSee('HDC-BAJA')->assertDontSee('HDC-NUEVA');
        $this->actingAs($this->admin)->get('/etiquetas?tipo=vehiculo')->assertSee('ABC123A')->assertDontSee('HDC-NUEVA');
        // Fechas inválidas se ignoran
        $this->actingAs($this->admin)->get('/etiquetas?desde=ayer&hasta[]=x')->assertOk()->assertSee('HDC-NUEVA')->assertSee('HDC-VIEJA');
        // La lista muestra la fecha de alta
        $this->actingAs($this->admin)->get('/etiquetas')->assertSee('Alta: 05/10/2026');
    }
}
