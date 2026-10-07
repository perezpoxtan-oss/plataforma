<?php

namespace Tests\Feature\Seguridad;

use App\Models\AccidenteFirma;
use App\Models\Colaborador;
use App\Models\Novedad;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Ronda 8 de ajustes de QA: firmas del Accidente (NV-03), canalizar y
 * clasificación (NV-01), filtros automáticos y secciones plegables (NV-06),
 * acompañantes en la búsqueda de Accesos (AC-05), devolución parcial de
 * Responsivas (RS-04), diálogos con un solo scroll (RT-04) y hoja semanal de
 * horarios de Rutas (RT-07 / RT-08).
 */
class AjustesRonda8Test extends PruebaNovedades
{
    // ------------------------------------------------------------- NV-03

    private function accidente(string $tipo = 'HUESPED'): Novedad
    {
        $n = $this->novedad(['categoria' => 'accidente']);
        $this->actingAs($this->admin)->put("/novedades/{$n->id}", $this->expediente($n, ['acc_tipo_afectado' => $tipo, 'h_nombre' => 'John Miller']))
            ->assertSessionHasNoErrors();

        return $n;
    }

    public function test_nv03_guardar_expediente_con_firma_no_da_405_y_la_direccion_con_get_abre_el_expediente(): void
    {
        Storage::fake('local');
        $n = $this->accidente();

        // «Guardar Esta Firma» + «Guardar Expediente»: PUT con la firma → regresa a la lista, sin 405
        $this->actingAs($this->admin)->put("/novedades/{$n->id}", $this->expediente($n, ['acc_tipo_afectado' => 'HUESPED', 'f_afectado' => self::firmaJpeg(), 'firma_en_curso' => '']))
            ->assertSessionHasNoErrors()->assertRedirect(route('novedades.index').'#novedad-'.$n->id);
        $this->assertSame(['afectado'], $this->enEmpresa(fn () => AccidenteFirma::where('novedad_id', $n->id)->pluck('rol')->all()));

        // La misma dirección con GET (recargar, volver atrás, un envío redirigido) abre el expediente
        $this->actingAs($this->admin)->get("/novedades/{$n->id}")->assertRedirect(route('novedades.index', ['abrir' => $n->id]));
        // …pero solo el propio: otra empresa → 404
        $ajena = $this->novedad([], $this->crearEmpresa('Hotel Dos'));
        $this->actingAs($this->admin)->get("/novedades/{$ajena->id}")->assertNotFound();

        // El formulario del expediente usa PUT y no tiene formularios anidados
        $html = $this->actingAs($this->admin)->get('/novedades?abrir='.$n->id)->assertOk()->getContent();
        $this->assertStringContainsString('name="_method" value="PUT"', $html);
        $inicio = strpos($html, 'data-form-novedad="expediente"');
        $fin = strpos($html, '</form>', $inicio);
        $this->assertStringNotContainsString('<form', substr($html, $inicio, $fin - $inicio));
    }

    public function test_nv03_en_produccion_un_405_muestra_la_pagina_de_error_de_la_plataforma(): void
    {
        config(['app.debug' => false]);
        $n = $this->novedad();

        $this->actingAs($this->admin)->delete("/novedades/{$n->id}")->assertStatus(405)
            ->assertSee('La acción no llegó completa')->assertSee('Error 405')
            ->assertDontSee('Oops! An Error Occurred')->assertDontSee('Method Not Allowed');
        // Cualquier otro 4xx sin vista propia también sale en español
        $this->assertStringContainsString('No se pudo completar la solicitud', view('errors.4xx', ['exception' => new HttpException(410)])->render());
    }

    public function test_nv03_los_firmantes_dependen_del_tipo_de_afectado(): void
    {
        Storage::fake('local');
        $huesped = $this->accidente('HUESPED');
        $pagina = $this->actingAs($this->admin)->get('/novedades?abrir='.$huesped->id)->assertOk();
        // El selector trae los de un huésped (con las claves de SEGCAT) y la lista completa para cambiar de tipo
        $pagina->assertSee('<option value="afectado">Huésped / Afectado</option>', false)
            ->assertSee('<option value="ejecutivo">Gerente en Turno / Ejecutivo de Guardia</option>', false)
            ->assertDontSee('<option value="rh">', false)->assertDontSee('<option value="jefe">', false)
            ->assertSee('data-roles-por-tipo=', false);

        // Un firmante que no aplica al huésped se rechaza dentro del diálogo
        $this->actingAs($this->admin)->put("/novedades/{$huesped->id}", $this->expediente($huesped, ['acc_tipo_afectado' => 'HUESPED', 'f_rh' => self::firmaJpeg()]))
            ->assertSessionHasErrors(['f_rh' => 'La firma de «Recursos Humanos (Si aplica)» no corresponde a un accidente de huésped. Elige otro firmante.']);
        $this->actingAs($this->admin)->put("/novedades/{$huesped->id}", $this->expediente($huesped, ['acc_tipo_afectado' => 'HUESPED', 'f_testigo' => self::firmaJpeg(), 'f_supervisor' => self::firmaJpeg()]))
            ->assertSessionHasNoErrors();

        // Colaborador: jefe inmediato y RH sí; gerente en turno (ejecutivo) no
        $colab = $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => '3001', 'nombre' => 'Rosa', 'apellido_paterno' => 'Poot', 'sede_id' => $this->centro->id]));
        $n = $this->novedad(['categoria' => 'accidente']);
        $base = ['acc_tipo_afectado' => 'COLABORADOR', 'c_id_colaborador' => $colab->id];
        $this->actingAs($this->admin)->put("/novedades/{$n->id}", $this->expediente($n, $base + ['f_ejecutivo' => self::firmaJpeg()]))
            ->assertSessionHasErrors('f_ejecutivo');
        $this->actingAs($this->admin)->put("/novedades/{$n->id}", $this->expediente($n, $base + ['f_jefe' => self::firmaJpeg(), 'f_rh' => self::firmaJpeg(), 'f_afectado' => self::firmaJpeg()]))
            ->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->get('/novedades?abrir='.$n->id)
            ->assertSee('<option value="afectado">Colaborador Afectado</option>', false)
            ->assertSee('<option value="jefe">Jefe Inmediato (Jefe de Área / Departamento)</option>', false)
            ->assertDontSee('<option value="ejecutivo">', false);

        // La impresión muestra los firmantes del colaborador con su firma
        $this->actingAs($this->admin)->get("/novedades/{$n->id}/imprimir")->assertOk()
            ->assertSee('Colaborador Afectado')->assertSee('Jefe Inmediato (Jefe de Área / Departamento)')->assertDontSee('Gerente en Turno')
            ->assertSee(route('novedades.firma', [$n->id, 'jefe']));
        // Las firmas nuevas se sirven solo con permiso (rol nuevo «testigo» incluido)
        $this->actingAs($this->admin)->get("/novedades/{$huesped->id}/firmas/testigo")->assertOk();
        $sinPermiso = $this->crearUsuario($this->empresa, 'Recursos Humanos');
        $this->actingAs($sinPermiso)->get("/novedades/{$huesped->id}/firmas/testigo")->assertForbidden();
    }

    // ------------------------------------------------------------- NV-01

    public function test_nv01_canalizar_solo_al_personal_de_seguridad_de_la_sede(): void
    {
        $agenteCentro = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $agenteCentro->forceFill(['name' => 'Agente Centro'])->save();
        $agentePlaya = $this->crearUsuario($this->empresa, 'Agente', $this->playa);
        $agentePlaya->forceFill(['name' => 'Agente Playa'])->save();
        $supervisor = $this->crearUsuario($this->empresa, 'Supervisor', $this->centro);
        $supervisor->forceFill(['name' => 'Supervisor Centro'])->save();
        $director = $this->crearUsuario($this->empresa, 'Director');
        $director->forceFill(['name' => 'Director General'])->save();
        $rh = $this->crearUsuario($this->empresa, 'Recursos Humanos');
        $rh->forceFill(['name' => 'Recursos Humanos Uno'])->save();
        $inactivo = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $inactivo->forceFill(['name' => 'Agente Inactivo', 'activo' => false])->save();

        $pagina = $this->actingAs($this->admin)->get('/novedades')->assertOk();
        foreach (['Agente Centro', 'Agente Playa', 'Supervisor Centro', 'Director General', 'Ana Administradora'] as $nombre) {
            $pagina->assertSee('>'.$nombre.'</option>', false);
        }
        $pagina->assertDontSee('Recursos Humanos Uno')->assertDontSee('Agente Inactivo');

        // En el expediente de un ticket de Centro no aparece el agente de Playa
        $n = $this->novedad();
        $this->actingAs($this->admin)->get('/novedades?abrir='.$n->id)->assertOk();
        $this->actingAs($this->admin)->put("/novedades/{$n->id}", $this->expediente($n, ['asignado_a' => $agentePlaya->id]))->assertSessionHasErrors('asignado_a');
        $this->actingAs($this->admin)->put("/novedades/{$n->id}", $this->expediente($n, ['asignado_a' => $supervisor->id]))->assertSessionHasNoErrors();

        // Al despachar: RH no es personal de Seguridad
        $this->actingAs($this->admin)->post('/novedades', $this->datos(['asignado_a' => $rh->id]))
            ->assertSessionHasErrors(['asignado_a' => 'Solo se canaliza al personal de Seguridad activo de esa sede (agentes, supervisores, jefes o mandos).']);
        $this->actingAs($this->admin)->post('/novedades', $this->datos(['asignado_a' => $director->id]))->assertSessionHasNoErrors();
    }

    public function test_nv01_el_ticket_no_se_despacha_sin_clasificacion(): void
    {
        $mensaje = 'Elige la clasificación del ticket (Categoría). Si aún no estás seguro, elige la más cercana: se puede corregir al atenderlo.';
        foreach (['', 'sin_clasificar', 'recorrido_pc', 'inventada'] as $categoria) {
            $this->actingAs($this->admin)->post('/novedades', $this->datos(['categoria' => $categoria]))->assertSessionHasErrors(['categoria' => $mensaje]);
        }
        $this->assertSame(0, $this->enEmpresa(fn () => Novedad::count()));
        // El diálogo pide elegirla (sin «Sin clasificar») y se reabre con el aviso
        $this->actingAs($this->admin)->get('/novedades')->assertOk()
            ->assertSee('<option value="" data-por-defecto>-- Elige la clasificación --</option>', false)
            ->assertDontSee('-- Sin clasificar todavía --');

        // Quien solo trabaja Lost & Found no tiene que elegir: es su única categoría
        $amaLlaves = $this->rolSoloLostFound($this->centro);
        $this->actingAs($amaLlaves)->post('/novedades', $this->datos(['categoria' => '']))->assertSessionHasNoErrors();
        $this->assertSame('lost_found', $this->enEmpresa(fn () => Novedad::firstOrFail()->categoria));
    }
}
