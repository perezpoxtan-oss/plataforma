<?php

namespace Tests\Feature\Seguridad;

use App\Models\Auditoria;
use App\Models\EquipoPc;
use App\Models\Novedad;
use App\Models\RecorridoPc;
use App\Models\SiniestroServicio;
use App\Models\User;

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
}
