<?php

namespace Tests\Feature\Seguridad;

use App\Models\AccidenteFirma;
use App\Models\Auditoria;
use App\Models\Colaborador;
use App\Models\Empresa;
use App\Models\Espacio;
use App\Models\Modulo;
use App\Models\Novedad;
use App\Models\NovedadNota;
use App\Models\RoboDetalle;
use App\Models\Sede;
use App\Services\Colaboradores\AdministradorColaboradores;
use App\Services\Permisos\Alcance;
use Illuminate\Support\Facades\Storage;

/**
 * Bitácora de Novedades: despacho, expediente, estatus, alcance y permisos.
 * Los formatos por categoría están en NovedadesFormatosTest.
 */
class NovedadesTest extends PruebaNovedades
{
    // ------------------------------------------------------------------ Lista

    public function test_pantalla_con_pestanas_y_textos_de_segcat(): void
    {
        $abierta = $this->novedad(['categoria' => 'robo', 'descripcion' => 'Se llevaron una laptop']);
        $this->novedad(['categoria' => 'incidente_general', 'descripcion' => 'Caso cerrado de prueba', 'estatus' => Novedad::RESUELTO, 'resolucion' => 'Listo']);

        $this->actingAs($this->admin)->get('/novedades')->assertOk()
            ->assertSee('Despacho y Novedades')->assertSee('Levantamiento modular y control operativo.')
            ->assertSee('Tickets Abiertos / Asignados')->assertSee('Historial Resueltos')
            ->assertSee('Buscar por #Ticket, ubicación o detalles del reporte...')->assertSee('Todas las Categorías')
            ->assertSee('Nuevo Ticket')->assertSee('Generar Ticket Rápido')->assertSee('Despachar Ticket')
            ->assertSee('¿Quién reporta?')->assertSee('¿A quién se canaliza?')->assertSee('Fui yo quien lo observó')
            ->assertSee('id="novedad-'.$abierta->id.'"', false)->assertSee('#00001')->assertSee('Se llevaron una laptop')
            ->assertSee('Abrir Expediente')->assertSee('Caso cerrado de prueba')->assertSee('Ver Expediente')
            // El lector universal para escanear el gafete de quien reporta
            ->assertSee('data-tipos="colaborador"', false)->assertSee('name="reportado_colaborador_id"', false)
            ->assertDontSee('onclick=', false);
    }

    public function test_sin_tickets_muestra_estados_vacios(): void
    {
        $this->actingAs($this->admin)->get('/novedades')->assertOk()
            ->assertSee('No hay tickets abiertos.')->assertSee('Todavía no hay casos resueltos.');
    }

    // ------------------------------------------------------------------- Alta

    public function test_despachar_normaliza_numera_por_empresa_convierte_la_hora_y_audita(): void
    {
        $respuesta = $this->actingAs($this->admin)->post('/novedades', $this->datos());

        $n = $this->enEmpresa(fn () => Novedad::firstOrFail());
        $respuesta->assertRedirect(route('novedades.index').'#novedad-'.$n->id)
            ->assertSessionHas('ok', 'Ticket #00001 despachado correctamente. Ábrelo con «Abrir Expediente» para darle seguimiento.');
        $this->assertSame([1, 'CAMARISTA ROSA', 'PISO 2, CERCA DEL ELEVADOR', 'sin_clasificar', Novedad::ABIERTO, $this->admin->id],
            [$n->numero, $n->reportado_por, $n->ubicacion, $n->categoria, $n->estatus, $n->creado_por]);
        // La hora llega en la zona de la sede (Ciudad de México, UTC-6) y se guarda en UTC
        $this->assertSame('2026-10-01 14:30:00', $n->ocurrio_en->utc()->format('Y-m-d H:i:s'));
        $this->assertSame(1, Auditoria::where('evento', 'novedades.creado')->where('auditable_id', $n->id)->firstOrFail()->despues['numero']);

        // Consecutivo propio de cada empresa
        $this->actingAs($this->admin)->post('/novedades', $this->datos())->assertSessionHasNoErrors();
        $otra = $this->crearEmpresa('Hotel Dos');
        $this->novedad([], $otra);
        $this->assertSame([1, 2], $this->enEmpresa(fn () => Novedad::orderBy('numero')->pluck('numero')->all()));
        $this->assertSame([1], $this->enEmpresa(fn () => Novedad::pluck('numero')->all(), $otra));
    }

    public function test_validaciones_en_espanol(): void
    {
        $this->actingAs($this->admin)->post('/novedades', $this->datos(['reportado_por' => '', 'ubicacion' => ' ', 'descripcion' => '']))
            ->assertSessionHasErrors([
                'reportado_por' => 'Escribe quién reporta (o toca «Fui yo quien lo observó»).',
                'ubicacion' => 'Escribe la ubicación específica (por ejemplo: Piso 2, cerca del elevador).',
                'descripcion' => 'Describe qué sucedió.',
            ]);
        $this->actingAs($this->admin)->post('/novedades', $this->datos(['ocurrio_en' => now()->addDays(2)->format('Y-m-d\TH:i')]))
            ->assertSessionHasErrors(['ocurrio_en' => '«¿Cuándo sucedió?» no puede ser una fecha u hora que todavía no llega.']);
        $this->actingAs($this->admin)->post('/novedades', $this->datos(['ocurrio_en' => '01/10/2026']))
            ->assertSessionHasErrors(['ocurrio_en' => 'Revisa la fecha y hora de «¿Cuándo sucedió?».']);

        // Sede de otra empresa, o canalizado a alguien sin acceso a la sede
        $otra = $this->crearEmpresa('Hotel Dos');
        $sedeAjena = $this->crearSede($otra, 'XX');
        $this->actingAs($this->admin)->post('/novedades', $this->datos(['sede_id' => $sedeAjena->id]))
            ->assertSessionHasErrors(['sede_id' => 'Elige una de tus sedes activas.']);
        $dePlaya = $this->crearUsuario($this->empresa, 'Agente', $this->playa);
        $ajeno = $this->crearUsuario($otra, 'Administrador');
        foreach ([$dePlaya, $ajeno] as $usuario) {
            $this->actingAs($this->admin)->post('/novedades', $this->datos(['asignado_a' => $usuario->id]))
                ->assertSessionHasErrors(['asignado_a' => 'La persona a quien se canaliza no está activa o no tiene acceso a esa sede.']);
        }
        $deCentro = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->actingAs($this->admin)->post('/novedades', $this->datos(['asignado_a' => $deCentro->id]))->assertSessionHasNoErrors();

        // Área General de otra sede, o un piso que no es de ese edificio
        $torre = $this->espacio($this->centro, Espacio::EDIFICIO, 'Torre A');
        $villas = $this->espacio($this->playa, Espacio::EDIFICIO, 'Villas');
        $pisoVillas = $this->espacio($this->playa, Espacio::AREA, 'Planta Baja', $villas);
        $this->actingAs($this->admin)->post('/novedades', $this->datos(['area_edificio_id' => $villas->id]))->assertSessionHasErrors('area_edificio_id');
        $this->actingAs($this->admin)->post('/novedades', $this->datos(['area_edificio_id' => $torre->id, 'area_piso_id' => $pisoVillas->id]))->assertSessionHasErrors('area_edificio_id');
        $this->assertSame(1, $this->enEmpresa(fn () => Novedad::count()));
    }

    public function test_area_general_toma_el_piso_o_el_edificio_y_quien_reporta_por_gafete(): void
    {
        $torre = $this->espacio($this->centro, Espacio::EDIFICIO, 'Torre A');
        $piso = $this->espacio($this->centro, Espacio::AREA, 'Piso 1', $torre);
        $rosa = $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => '2001', 'nombre' => 'Rosa', 'apellido_paterno' => 'Poot', 'sede_id' => $this->centro->id]));

        $this->actingAs($this->admin)->post('/novedades', $this->datos(['area_edificio_id' => $torre->id, 'area_piso_id' => $piso->id, 'reportado_colaborador_id' => $rosa->id]))->assertSessionHasNoErrors();
        $this->actingAs($this->admin)->post('/novedades', $this->datos(['area_edificio_id' => $torre->id]))->assertSessionHasNoErrors();
        [$conPiso, $soloEdificio] = $this->enEmpresa(fn () => Novedad::with(['area.padre', 'areaEspecifica'])->orderBy('id')->get()->all());

        $this->assertSame([$piso->id, $rosa->id, 'Torre A · Piso 1'], [$conPiso->area_id, $conPiso->reportado_colaborador_id, $conPiso->textoArea()]);
        $this->assertSame($torre->id, $soloEdificio->area_id);
        // Unir duplicados de colaboradores también mueve quién reportó
        $this->assertSame('reportado_colaborador_id', AdministradorColaboradores::REFERENCIAS['novedades']);
        $this->assertSame('colaborador_id', AdministradorColaboradores::REFERENCIAS['accidente_colaboradores']);
    }

    public function test_robo_crea_su_detalle_al_despachar(): void
    {
        $this->actingAs($this->admin)->post('/novedades', $this->datos(['categoria' => 'robo']))->assertSessionHasNoErrors();
        $n = $this->enEmpresa(fn () => Novedad::firstOrFail());
        $this->assertTrue($this->enEmpresa(fn () => RoboDetalle::where('novedad_id', $n->id)->exists()));

        // Recorrido PC ya no se crea desde aquí: se despacha Sin clasificar
        $this->actingAs($this->admin)->post('/novedades', $this->datos(['categoria' => 'recorrido_pc']))->assertSessionHasNoErrors();
        $this->assertSame('sin_clasificar', $this->enEmpresa(fn () => Novedad::orderByDesc('id')->value('categoria')));
    }

    // -------------------------------------------------------------- Expediente

    public function test_el_expediente_se_abre_con_abrir_y_guarda_preguntas_base_y_minuto_a_minuto(): void
    {
        $n = $this->novedad(['categoria' => 'incidente_general']);

        $this->actingAs($this->admin)->get('/novedades?abrir='.$n->id)->assertOk()
            ->assertSee('Expediente de Novedad')->assertSee('Preguntas Base — se confirman/completan al atender')
            ->assertSee('Minuto a Minuto / Log de Seguimiento')->assertSee('No hay registros previos.')
            ->assertSee('Estatus Final del Expediente')->assertSee('Guardar Expediente')
            ->assertSee('data-abrir-al-cargar', false)->assertSee('Detalle: Reporte General');

        $this->actingAs($this->admin)->put("/novedades/{$n->id}", $this->expediente($n, [
            'como_sucedio' => 'Se dejó la llave abierta', 'involucrados' => 'mantenimiento', 'ocurrio_en' => '2026-10-02T21:15',
            'nueva_nota' => 'Se avisó a mantenimiento', 'ig_observados' => 'personal de cocina', 'ig_acciones' => 'Se cerró la llave de paso',
        ]))->assertRedirect(route('novedades.index').'#novedad-'.$n->id)->assertSessionHas('ok', 'Expediente #00001 guardado correctamente.');

        $n = $this->buscar($n->id);
        $this->assertSame(['Se dejó la llave abierta', 'MANTENIMIENTO', '2026-10-03 03:15'], [$n->como_sucedio, $n->involucrados, $n->ocurrio_en->utc()->format('Y-m-d H:i')]);
        $this->assertSame(['Ana Administradora', 'Se avisó a mantenimiento', 'nota'], [$n->notas[0]->autor_nombre, $n->notas[0]->texto, $n->notas[0]->tipo]);
        $this->assertSame('PERSONAL DE COCINA', $this->enEmpresa(fn () => $n->reporteGeneral()->first()->observados));
        $this->assertDatabaseHas('auditoria', ['evento' => 'novedades.actualizado', 'auditable_id' => $n->id]);

        // La nota aparece en el log como "[fecha] autor: texto"
        $this->actingAs($this->admin)->get('/novedades?abrir='.$n->id)->assertSee('Ana Administradora: Se avisó a mantenimiento');
    }

    public function test_estatus_abierto_pendiente_resuelto_y_reabrir_con_justificacion(): void
    {
        $n = $this->novedad();

        // Abierto → Pendiente de Turno → Abierto
        $this->actingAs($this->admin)->put("/novedades/{$n->id}", $this->expediente($n, ['estatus' => Novedad::PENDIENTE_TURNO]))->assertSessionHasNoErrors();
        $this->assertSame(Novedad::PENDIENTE_TURNO, $this->buscar($n->id)->estatus);
        $this->assertSame('Cambió el estatus a PENDIENTE DE TURNO.', $this->buscar($n->id)->notas->last()->texto);
        $this->actingAs($this->admin)->put("/novedades/{$n->id}", $this->expediente($n, ['estatus' => Novedad::ABIERTO]))->assertSessionHasNoErrors();

        // Resuelto exige la resolución
        $this->actingAs($this->admin)->put("/novedades/{$n->id}", $this->expediente($n, ['estatus' => Novedad::RESUELTO, 'resolucion' => ' ']))
            ->assertSessionHasErrors(['resolucion' => 'Para marcar el caso como Resuelto, primero escribe cómo se concluyó en «Estatus Final / Resolución».']);
        $this->actingAs($this->admin)->put("/novedades/{$n->id}", $this->expediente($n, ['estatus' => Novedad::RESUELTO, 'resolucion' => 'Se cerró la llave de gas']))
            ->assertSessionHasNoErrors()->assertRedirect(route('novedades.index').'#novedad-'.$n->id);
        $n = $this->buscar($n->id);
        $this->assertSame([Novedad::RESUELTO, $this->admin->id], [$n->estatus, $n->cerrado_por]);
        $this->assertTrue($n->cerrado_en->between(now()->subMinute(), now()->addMinute()));
        $this->assertSame('CERRÓ EL CASO — Resolución: Se cerró la llave de gas', $n->notas->last()->texto);
        $this->assertDatabaseHas('auditoria', ['evento' => 'novedades.resuelto', 'auditable_id' => $n->id]);

        // Resuelto: ya no se edita (ni con una nota) hasta reabrirlo
        $this->actingAs($this->admin)->put("/novedades/{$n->id}", $this->expediente($n, ['estatus' => Novedad::ABIERTO, 'nueva_nota' => 'otra']))
            ->assertSessionHasErrors(['estatus' => 'Este caso ya está Resuelto. Para editarlo usa «Reabrir Caso para Editar» y escribe el motivo.']);
        $this->actingAs($this->admin)->get('/novedades?abrir='.$n->id)
            ->assertSee('Reabrir Caso para Editar')->assertSee('Justificar Reapertura')->assertDontSee('Guardar Expediente')->assertSee('<fieldset disabled', false);

        // Reabrir: motivo obligatorio
        $this->actingAs($this->admin)->post("/novedades/{$n->id}/reabrir", ['motivo' => ''])
            ->assertSessionHasErrors(['motivo' => 'Escribe el motivo antes de continuar: un caso resuelto no se puede reabrir sin justificación.']);
        $this->actingAs($this->admin)->post("/novedades/{$n->id}/reabrir", ['motivo' => 'El olor volvió a presentarse'])
            ->assertRedirect(route('novedades.index', ['abrir' => $n->id]));
        $n = $this->buscar($n->id);
        $this->assertSame([Novedad::ABIERTO, null], [$n->estatus, $n->cerrado_en]);
        $this->assertSame(['reapertura', 'REABRIÓ EL CASO — Motivo: El olor volvió a presentarse'], [$n->notas->last()->tipo, $n->notas->last()->texto]);
        $this->assertDatabaseHas('auditoria', ['evento' => 'novedades.reabierto', 'auditable_id' => $n->id]);

        // Reabrir un caso que no está resuelto: no
        $this->actingAs($this->admin)->post("/novedades/{$n->id}/reabrir", ['motivo' => 'Otra vez'])
            ->assertSessionHasErrors(['motivo' => 'Este caso no está Resuelto: no hace falta reabrirlo.']);
        // Estatus que no existe
        $this->actingAs($this->admin)->put("/novedades/{$n->id}", $this->expediente($n, ['estatus' => 'cerrado']))->assertSessionHasErrors('estatus');
    }

    public function test_el_minuto_a_minuto_solo_se_agrega(): void
    {
        $n = $this->novedad();
        foreach (['Primera ronda', 'Segunda ronda'] as $nota) {
            $this->actingAs($this->admin)->put("/novedades/{$n->id}", $this->expediente($n, ['nueva_nota' => $nota]))->assertSessionHasNoErrors();
        }
        $this->assertSame(['Primera ronda', 'Segunda ronda'], $this->buscar($n->id)->notas->pluck('texto')->all());
        // No hay ruta para editar ni borrar notas
        $this->assertFalse(collect(app('router')->getRoutes())->contains(fn ($r) => str_contains($r->uri(), 'notas')));
        $this->assertSame(2, $this->enEmpresa(fn () => NovedadNota::count()));
    }

    public function test_cambiar_de_categoria_al_atender(): void
    {
        $n = $this->novedad();
        $this->actingAs($this->admin)->put("/novedades/{$n->id}", $this->expediente($n, ['categoria' => 'robo', 'robo_lugar_exacto' => 'buró']))->assertSessionHasNoErrors();
        $this->assertSame('robo', $this->buscar($n->id)->categoria);
        $this->assertSame('BURÓ', $this->enEmpresa(fn () => RoboDetalle::where('novedad_id', $n->id)->value('lugar_exacto')));

        // Recorrido PC no se elige en un ticket que no la traía
        $this->actingAs($this->admin)->put("/novedades/{$n->id}", $this->expediente($n, ['categoria' => 'recorrido_pc']))
            ->assertSessionHasErrors(['categoria' => 'Elige una de las categorías de la lista.']);
    }

    // ------------------------------------------------------- Imprimir y exportar

    public function test_imprimir_el_expediente_y_exportar_csv_con_filtros(): void
    {
        $robo = $this->novedad(['categoria' => 'robo', 'descripcion' => 'Laptop robada']);
        $this->novedad(['categoria' => 'accidente', 'descripcion' => 'Caída en escaleras']);

        $this->actingAs($this->admin)->get("/novedades/{$robo->id}/imprimir")->assertOk()
            ->assertSee('Expediente de Novedad #00001')->assertSee('Detalle: Robo')->assertSee('Laptop robada')->assertSee('Imprimir Expediente');

        $csv = $this->actingAs($this->admin)->get('/novedades/exportar?categoria=robo')->assertOk()->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $this->assertStringContainsString('Laptop robada', $csv);
        $this->assertStringNotContainsString('Caída en escaleras', $csv);
        $this->assertStringContainsString('Caída en escaleras', $this->actingAs($this->admin)->get('/novedades/exportar?q=escaleras')->streamedContent());
    }

    // ----------------------------------------------------------- Permisos

    public function test_cada_empresa_ve_y_toca_solo_sus_novedades(): void
    {
        $otra = $this->crearEmpresa('Hotel Dos');
        $ajena = $this->novedad(['descripcion' => 'Novedad ajena'], $otra);
        $adminOtra = $this->crearUsuario($otra, 'Administrador');

        $this->actingAs($this->admin)->get('/novedades')->assertOk()->assertDontSee('Novedad ajena');
        $this->actingAs($this->admin)->get('/novedades?abrir='.$ajena->id)->assertOk()->assertDontSee('Expediente de Novedad');
        $this->actingAs($this->admin)->put("/novedades/{$ajena->id}", ['categoria' => 'sin_clasificar', 'estatus' => 'abierto'])->assertNotFound();
        $this->actingAs($this->admin)->post("/novedades/{$ajena->id}/reabrir", ['motivo' => 'Prueba de reapertura'])->assertNotFound();
        $this->actingAs($this->admin)->get("/novedades/{$ajena->id}/imprimir")->assertNotFound();
        $this->actingAs($adminOtra)->get('/novedades')->assertSee('Novedad ajena');
    }

    public function test_alcance_de_sede_solo_ve_y_despacha_en_sus_sedes(): void
    {
        $jefe = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->centro);
        $deCentro = $this->novedad(['descripcion' => 'Caso de centro']);
        $dePlaya = $this->enEmpresa(function () {
            $n = new Novedad(['sede_id' => $this->playa->id, 'reportado_por' => 'X', 'ubicacion' => 'ALBERCA', 'descripcion' => 'Caso de playa']);
            $n->numero = 99;
            $n->save();

            return $n;
        });

        $this->actingAs($jefe)->get('/novedades')->assertOk()->assertSee('Caso de centro')->assertDontSee('Caso de playa');
        $this->actingAs($jefe)->post('/novedades', $this->datos(['sede_id' => $this->playa->id]))->assertSessionHasErrors(['sede_id' => 'Elige una de tus sedes activas.']);
        $this->actingAs($jefe)->post('/novedades', $this->datos())->assertSessionHasNoErrors();
        $this->actingAs($jefe)->put("/novedades/{$dePlaya->id}", $this->expediente($dePlaya))->assertNotFound();
        $this->actingAs($jefe)->get("/novedades/{$dePlaya->id}/imprimir")->assertNotFound();
        $this->actingAs($jefe)->put("/novedades/{$deCentro->id}", $this->expediente($deCentro, ['nueva_nota' => 'Atendido']))->assertSessionHasNoErrors();
    }

    public function test_el_agente_despacha_y_atiende_pero_no_reabre_ni_exporta(): void
    {
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $resuelto = $this->novedad(['estatus' => Novedad::RESUELTO, 'resolucion' => 'Listo']);

        $this->actingAs($agente)->get('/novedades')->assertOk()->assertSee('Nuevo Ticket')->assertDontSee('Exportar');
        $this->actingAs($agente)->post('/novedades', $this->datos(['reportado_por' => 'Andrea']))->assertSessionHasNoErrors();
        $nuevo = $this->enEmpresa(fn () => Novedad::where('reportado_por', 'ANDREA')->firstOrFail());
        $this->actingAs($agente)->put("/novedades/{$nuevo->id}", $this->expediente($nuevo, ['nueva_nota' => 'Ronda hecha']))->assertSessionHasNoErrors();

        $this->actingAs($agente)->get('/novedades?abrir='.$resuelto->id)->assertOk()->assertSee('Este caso está')->assertDontSee('Reabrir Caso para Editar');
        $this->actingAs($agente)->post("/novedades/{$resuelto->id}/reabrir", ['motivo' => 'Quiero editarlo'])->assertForbidden();
        $this->actingAs($agente)->get('/novedades/exportar')->assertForbidden();
    }

    public function test_un_usuario_sin_permiso_no_entra(): void
    {
        $rh = $this->crearUsuario($this->empresa, 'Recursos Humanos');
        $this->actingAs($rh)->get('/novedades')->assertForbidden();
        $this->actingAs($rh)->post('/novedades', $this->datos())->assertForbidden();
        $this->actingAs($rh)->get('/novedades/coincidencias')->assertForbidden();
    }

    public function test_solo_lost_found_trabaja_unicamente_sus_tickets(): void
    {
        $ama = $this->rolSoloLostFound($this->centro);
        $robo = $this->novedad(['categoria' => 'robo', 'descripcion' => 'Caso de robo']);
        $lf = $this->novedad(['categoria' => 'lost_found', 'descripcion' => 'Cartera encontrada']);

        $pagina = $this->actingAs($ama)->get('/novedades')->assertOk()->assertSee('Cartera encontrada')->assertDontSee('Caso de robo')->getContent();
        $this->assertStringContainsString('Lost &amp; Found (Objetos Perdidos)', $pagina);
        $this->assertStringNotContainsString('<option value="accidente"', $pagina);

        // Solo despacha Lost & Found, aunque mande otra categoría
        $this->actingAs($ama)->post('/novedades', $this->datos(['categoria' => 'accidente']))->assertSessionHasNoErrors();
        $this->assertSame('lost_found', $this->enEmpresa(fn () => Novedad::orderByDesc('id')->value('categoria')));

        // Edita su ticket, pero no lo puede convertir en otra categoría ni tocar los demás
        $this->actingAs($ama)->put("/novedades/{$lf->id}", $this->expediente($lf, ['nueva_nota' => 'En bodega']))->assertSessionHasNoErrors();
        $this->actingAs($ama)->put("/novedades/{$lf->id}", $this->expediente($lf, ['categoria' => 'robo']))->assertSessionHasErrors('categoria');
        $this->actingAs($ama)->put("/novedades/{$robo->id}", $this->expediente($robo))->assertNotFound();
        $this->actingAs($ama)->get("/novedades/{$robo->id}/imprimir")->assertNotFound();
        $this->actingAs($ama)->get(route('lost_found.index'))->assertOk()->assertSee('Cartera encontrada');
    }

    public function test_menu_y_atajo_enlazan_a_la_bitacora(): void
    {
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $this->actingAs($agente)->get('/novedades')->assertOk()
            ->assertSee(route('novedades.index'))->assertSee(route('lost_found.index'));
        $this->assertSame('novedades.index', Modulo::where('clave', 'novedades')->value('ruta'));
        $this->assertSame('lost_found.index', Modulo::where('clave', 'lost_found')->value('ruta'));
    }

    // ----------------------------------------------------------- Firmas

    public function test_las_firmas_del_accidente_se_sirven_solo_con_permiso_y_alcance(): void
    {
        Storage::fake('local');
        $n = $this->novedad(['categoria' => 'accidente']);
        $this->actingAs($this->admin)->put("/novedades/{$n->id}", $this->expediente($n, ['f_afectado' => self::firmaJpeg()]))->assertSessionHasNoErrors();

        $firma = $this->enEmpresa(fn () => AccidenteFirma::where('novedad_id', $n->id)->firstOrFail());
        $this->assertStringStartsWith("firmas/{$this->empresa->id}/accidentes/", $firma->ruta);
        Storage::disk('local')->assertExists($firma->ruta);
        $this->assertFileDoesNotExist(public_path($firma->ruta));

        $this->actingAs($this->admin)->get("/novedades/{$n->id}/firmas/afectado")->assertOk();
        $this->actingAs($this->admin)->get("/novedades/{$n->id}/firmas/medico")->assertNotFound();
        $this->actingAs($this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->playa))->get("/novedades/{$n->id}/firmas/afectado")->assertNotFound();
        $this->actingAs($this->crearUsuario($this->empresa, 'Recursos Humanos'))->get("/novedades/{$n->id}/firmas/afectado")->assertForbidden();
        $this->actingAs($this->crearUsuario($this->crearEmpresa('Hotel Dos'), 'Administrador'))->get("/novedades/{$n->id}/firmas/afectado")->assertNotFound();
    }
}
