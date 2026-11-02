<?php

namespace Tests\Feature\Seguridad;

use App\Models\Auditoria;
use App\Models\EquipoPc;
use App\Models\LostFoundArticulo;
use App\Models\Novedad;
use App\Models\RecorridoPc;
use App\Models\RoboDetalle;
use App\Models\Rubro;
use App\Models\SiniestroServicio;
use App\Models\User;
use App\Support\RegistroTerminos;

/**
 * Etapa 6 · Datos que viajan entre módulos (C-29 Protección Civil). Las
 * demás correcciones de la etapa tienen sus propias pruebas
 * (EtapaSeisDatosQueViajanTest, EtapaSeisAccidentesTest, EtapaSeisProveedorTest).
 */
class EtapaSeisTest extends PruebaNovedades
{
    private User $agente;

    private User $jefe;

    protected function setUp(): void
    {
        parent::setUp();
        $this->agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
    }

    private function extintor(string $serie = 'EXT-01'): EquipoPc
    {
        return $this->enEmpresa(function () use ($serie) {
            $e = new EquipoPc(['sede_id' => $this->centro->id, 'categoria' => 'EXTINTOR', 'numero_serie' => $serie]);
            $e->forceFill(['activo' => true])->save();

            return $e;
        });
    }

    private function hallazgo(EquipoPc $equipo, ?User $quien = null): RecorridoPc
    {
        $quien ??= $this->agente;
        $this->actingAs($quien)->post('/recorridos-pc', ['sede_id' => $this->centro->id])->assertSessionHasNoErrors();
        $r = $this->enEmpresa(fn () => RecorridoPc::orderByDesc('id')->firstOrFail());
        $this->actingAs($quien)->post("/recorridos-pc/{$r->id}/revisiones", [
            '_punto' => 'equipo', 'equipo_pc_id' => $equipo->id, 'criterios' => [], 'observaciones' => 'Despresurizado',
        ])->assertSessionHasNoErrors();

        return $this->enEmpresa(fn () => RecorridoPc::findOrFail($r->id));
    }

    // ------------------------------------------------------------------ C-29

    public function test_hallazgo_de_extintor_abre_mantenimiento_y_no_cuenta_como_siniestro(): void
    {
        $r = $this->hallazgo($this->extintor());
        $ticket = $this->buscar($r->novedad_id);
        $this->assertSame('mantenimiento_pc', $ticket->categoria);
        $this->assertSame('Mantenimiento de equipo PC', $ticket->etiquetaCategoria());

        // El Tablero cuenta por categoría: el extintor no aparece como siniestro
        $tablero = $this->actingAs($this->admin)->get('/informes/tablero')->assertOk()->getContent();
        $this->assertStringNotContainsString('Siniestro Protección Civil', $tablero);

        // Configuración: «Mantenimiento» es el valor original y se ofrece primero
        $config = $this->actingAs($this->admin)->get('/configuracion')->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/<option value="mantenimiento_pc" selected>Mantenimiento de equipo PC \(recomendado\)<\/option>/u', $config);
        $this->assertStringContainsString('Valor original: Mantenimiento de equipo PC.', $config);

        // La empresa que lo quiere como siniestro lo elige (y queda guardado aunque ya no sea el de fábrica)
        $this->actingAs($this->admin)->put('/configuracion/novedades', ['categoria_hallazgo_pc' => 'proteccion_civil'])->assertSessionHasNoErrors();
        $this->assertSame('proteccion_civil', $this->empresa->fresh()->preferencias['novedades']['categoria_hallazgo_pc']);
        $this->enEmpresa(fn () => Novedad::whereKey($ticket->id)->update(['estatus' => Novedad::RESUELTO, 'resolucion' => 'Recargado']));
        $otro = $this->hallazgo($this->extintor('EXT-02'));
        $this->assertSame('proteccion_civil', $this->buscar($otro->novedad_id)->categoria);
    }

    public function test_filtro_unico_de_proteccion_civil_con_subtipos_en_lista_y_excel(): void
    {
        $siniestro = $this->novedad(['categoria' => 'proteccion_civil', 'descripcion' => 'Conato en cocina']);
        $mantto = $this->novedad(['categoria' => 'mantenimiento_pc', 'descripcion' => 'Extintor sin presión']);
        $viejo = $this->novedad(['categoria' => 'recorrido_pc', 'descripcion' => 'Inspección de antes']);
        $general = $this->novedad(['categoria' => 'incidente_general', 'descripcion' => 'Ruido en pasillo']);

        $lista = $this->actingAs($this->admin)->get('/novedades')->assertOk()->getContent();
        $this->assertStringContainsString('<optgroup label="Protección Civil">', $lista);
        $this->assertStringContainsString('<option value="pc">Protección Civil (todos)</option>', $lista);
        foreach ([$siniestro, $mantto, $viejo] as $n) {
            $this->assertMatchesRegularExpression('/id="novedad-'.$n->id.'"[^>]*data-grupo="pc"/u', $lista);
        }
        $this->assertDoesNotMatchRegularExpression('/id="novedad-'.$general->id.'"[^>]*data-grupo="pc"/u', $lista);

        $csv = $this->actingAs($this->admin)->get('/novedades/exportar?categoria=pc')->assertOk()->streamedContent();
        $this->assertStringContainsString('Conato en cocina', $csv);
        $this->assertStringContainsString('Extintor sin presión', $csv);
        $this->assertStringContainsString('Inspección de antes', $csv);
        $this->assertStringNotContainsString('Ruido en pasillo', $csv);
        $this->actingAs($this->admin)->get('/novedades/exportar?categoria=otra')->assertSessionHasErrors('categoria');
    }

    public function test_reclasificar_hallazgos_anteriores_es_opcional_y_respeta_los_siniestros_documentados(): void
    {
        $this->actingAs($this->admin)->put('/configuracion/novedades', ['categoria_hallazgo_pc' => 'proteccion_civil'])->assertSessionHasNoErrors();
        $r1 = $this->hallazgo($this->extintor('EXT-01'));
        $this->enEmpresa(fn () => Novedad::whereKey($r1->novedad_id)->update(['estatus' => Novedad::RESUELTO, 'resolucion' => 'x']));
        $r2 = $this->hallazgo($this->extintor('EXT-02'));
        // El segundo sí se documentó como siniestro real: no se toca
        $this->enEmpresa(fn () => SiniestroServicio::create(['novedad_id' => $r2->novedad_id, 'servicio' => 'BOMBEROS']));
        $suelto = $this->novedad(['categoria' => 'proteccion_civil']); // siniestro capturado a mano: no es de un recorrido

        $this->actingAs($this->admin)->get('/configuracion')->assertOk()->assertSee('hallazgos anteriores registrados como siniestro (1)');
        // Sin permiso de toda la empresa, no
        $this->actingAs($this->jefe)->put('/configuracion/novedades/reclasificar-hallazgos')->assertForbidden();

        $this->actingAs($this->admin)->put('/configuracion/novedades/reclasificar-hallazgos')->assertSessionHasNoErrors()
            ->assertSessionHas('ok', fn ($m) => str_contains($m, '1 hallazgo anterior pasó'));
        $this->assertSame('mantenimiento_pc', $this->buscar($r1->novedad_id)->categoria);
        $this->assertStringContainsString('Se reclasificó', $this->buscar($r1->novedad_id)->notas->last()->texto);
        $this->assertSame('proteccion_civil', $this->buscar($r2->novedad_id)->categoria);
        $this->assertSame('proteccion_civil', $this->buscar($suelto->id)->categoria);
        $this->assertSame([$this->buscar($r1->novedad_id)->folio()], Auditoria::where('evento', 'novedades.hallazgos_reclasificados')->sole()->despues['tickets']);

        // Una segunda vez no hay nada que hacer y la sección desaparece
        $this->actingAs($this->admin)->put('/configuracion/novedades/reclasificar-hallazgos')->assertSessionHas('ok', 'No hay hallazgos anteriores que reclasificar.');
        $this->actingAs($this->admin)->get('/configuracion')->assertDontSee('hallazgos anteriores registrados como siniestro');
    }

    // ------------------------------------------------------------------ C-32

    private function giro(string $clave): void
    {
        $this->empresa->forceFill(['rubro_id' => Rubro::where('clave', $clave)->value('id')])->save();
        app()->forgetInstance(RegistroTerminos::class);
    }

    public function test_en_condominio_no_se_ofrece_valores_a_la_vista_y_lost_found_dice_objetos_olvidados(): void
    {
        $this->giro('condominio');
        $lista = $this->actingAs($this->admin)->get('/novedades')->assertOk()->getContent();
        $this->assertStringNotContainsString('Valores a la Vista', $lista);
        $this->assertStringContainsString('Objetos olvidados', $lista);
        $this->assertStringNotContainsString('Objetos olvidados (Objetos Perdidos)', $lista);
        $this->actingAs($this->admin)->post('/novedades', $this->datos(['categoria' => 'habitacion']))->assertSessionHasErrors('categoria');

        // Un ticket de antes con esa categoría la conserva
        $viejo = $this->novedad(['categoria' => 'habitacion']);
        $this->actingAs($this->admin)->get('/novedades?abrir='.$viejo->id)->assertOk()->assertSee('Valores a la Vista');

        // En hotel todo sigue como siempre
        $this->giro('hotel');
        $this->actingAs($this->admin)->get('/novedades')->assertOk()->assertSee('Valores a la Vista')->assertSee('Lost &amp; Found (Objetos Perdidos)', false);
    }

    public function test_subtipos_de_reporte_general_por_giro_y_configurables(): void
    {
        $this->giro('condominio');
        $n = $this->novedad(['categoria' => 'incidente_general', 'descripcion' => 'Música alta en el 302']);
        $exp = $this->actingAs($this->admin)->get('/novedades?abrir='.$n->id)->assertOk()->getContent();
        $this->assertStringContainsString('<option value="Ruido"', $exp);
        $this->assertStringContainsString('<option value="Mascotas"', $exp);

        $this->actingAs($this->admin)->put("/novedades/{$n->id}", $this->expediente($n, ['ig_subtipo' => 'Inventado']))->assertSessionHasErrors('ig_subtipo');
        $this->actingAs($this->admin)->put("/novedades/{$n->id}", $this->expediente($n, ['ig_subtipo' => 'Ruido']))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->get('/novedades')->assertOk()->assertSee('Reporte General · Ruido');
        $this->assertStringContainsString('Reporte General · Ruido', $this->actingAs($this->admin)->get('/novedades/exportar')->streamedContent());

        // La empresa cambia la lista (Avanzado); el reporte guardado conserva su tipo aunque ya no esté
        $this->actingAs($this->admin)->put('/configuracion/novedades', ['categoria_hallazgo_pc' => 'mantenimiento_pc', 'subtipos_reporte_general' => " Fiesta \nFiesta\n\nAlberca sucia"])
            ->assertSessionHasNoErrors();
        $this->assertSame(['Fiesta', 'Alberca sucia'], $this->empresa->fresh()->preferencias['novedades']['subtipos_reporte_general']);
        $exp = $this->actingAs($this->admin)->get('/novedades?abrir='.$n->id)->assertOk()->getContent();
        $this->assertStringContainsString('<option value="Fiesta"', $exp);
        $this->assertMatchesRegularExpression('/<option value="Ruido" selected>/u', $exp);
        $this->actingAs($this->admin)->put("/novedades/{$n->id}", $this->expediente($n, ['ig_subtipo' => 'Ruido']))->assertSessionHasNoErrors();

        // En hotel no hay tipos de fábrica: el formato se ve como siempre
        $this->giro('hotel');
        $this->actingAs($this->admin)->put('/configuracion/novedades', ['categoria_hallazgo_pc' => 'mantenimiento_pc', 'subtipos_reporte_general' => ''])->assertSessionHasNoErrors();
        $otro = $this->novedad(['categoria' => 'incidente_general']);
        $this->actingAs($this->admin)->get('/novedades?abrir='.$otro->id)->assertOk()->assertDontSee('name="ig_subtipo"', false);
    }

    public function test_no_se_reclasifica_un_ticket_con_objetos_en_resguardo_ni_un_robo_con_detalle(): void
    {
        $lf = $this->novedad(['categoria' => 'lost_found']);
        $articulo = $this->enEmpresa(function () use ($lf) {
            $a = new LostFoundArticulo(['objeto' => 'CARTERA', 'tipo_valor' => 'OTRO', 'novedad_id' => $lf->id, 'sede_id' => $lf->sede_id]);
            $a->numero = 1;
            $a->folio = 'LF-000001';
            $a->save();

            return $a;
        });
        $this->actingAs($this->admin)->put("/novedades/{$lf->id}", $this->expediente($lf, ['categoria' => 'incidente_general']))
            ->assertSessionHasErrors(['categoria' => 'Este ticket tiene objetos en resguardo (LF-000001). Primero entrégalos o regístralos en otro ticket de Lost & Found; después cambia la categoría.']);
        $this->assertSame('lost_found', $this->buscar($lf->id)->categoria);
        // Ya entregado, sí se puede
        $this->enEmpresa(fn () => LostFoundArticulo::whereKey($articulo->id)->update(['estatus' => 'DEVUELTO']));
        $this->actingAs($this->admin)->put("/novedades/{$lf->id}", $this->expediente($lf, ['categoria' => 'incidente_general']))->assertSessionHasNoErrors();

        $robo = $this->novedad(['categoria' => 'robo']);
        $this->enEmpresa(fn () => RoboDetalle::create(['novedad_id' => $robo->id]));
        // Sin detalle capturado (recién despachado por error) sí se puede corregir
        $this->actingAs($this->admin)->put("/novedades/{$robo->id}", $this->expediente($robo, ['categoria' => 'incidente_general']))->assertSessionHasNoErrors();
        $robo2 = $this->novedad(['categoria' => 'robo']);
        $this->enEmpresa(fn () => RoboDetalle::create(['novedad_id' => $robo2->id, 'lugar_exacto' => 'CAJA FUERTE', 'objetos_descripcion' => 'RELOJ']));
        $this->actingAs($this->admin)->put("/novedades/{$robo2->id}", $this->expediente($robo2, ['categoria' => 'incidente_general']))->assertSessionHasErrors('categoria');
        $this->assertSame('robo', $this->buscar($robo2->id)->categoria);
    }
}
