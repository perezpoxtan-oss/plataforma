<?php

namespace Tests\Feature\Seguridad;

use App\Models\AccidenteColaborador;
use App\Models\AccidenteDictamen;
use App\Models\AccidenteFirma;
use App\Models\AccidenteGuardavidas;
use App\Models\AccidenteHuesped;
use App\Models\AccidenteIncapacidad;
use App\Models\Colaborador;
use App\Models\Departamento;
use App\Models\Espacio;
use App\Models\LostFoundArticulo;
use App\Models\LostFoundReportePerdida;
use App\Models\LostFoundUmbral;
use App\Models\Novedad;
use App\Models\NovedadTestigo;
use App\Models\RecorridoPcPunto;
use App\Models\RoboDetalle;
use App\Models\SiniestroDetalle;
use App\Models\SiniestroServicio;
use App\Models\ValoresVistaApertura;
use App\Models\ValoresVistaDetalle;
use App\Models\ValoresVistaPersona;
use App\Models\ValoresVistaZona;
use App\Services\Novedades\AdministradorNovedades;
use App\Services\Novedades\FichaHechos\FichaDeHechos;
use Illuminate\Support\Facades\Storage;

/**
 * Bitácora de Novedades: formato de cada categoría del expediente, Lost &
 * Found (folios, coincidencias, vincular), Robo y Ficha de Hechos.
 */
class NovedadesFormatosTest extends PruebaNovedades
{
    private Espacio $torre;

    private Espacio $piso;

    private Espacio $hab101;

    private Espacio $hab102;

    protected function setUp(): void
    {
        parent::setUp();
        $this->torre = $this->espacio($this->centro, Espacio::EDIFICIO, 'Torre A');
        $this->piso = $this->espacio($this->centro, Espacio::AREA, 'Piso 1', $this->torre);
        $this->hab101 = $this->espacio($this->centro, Espacio::AREA_ESPECIFICA, '101', $this->piso);
        $this->hab102 = $this->espacio($this->centro, Espacio::AREA_ESPECIFICA, '102', $this->piso);
    }

    /** Ticket con Área General Torre A · Piso 1. */
    private function ticket(string $categoria, array $extra = []): Novedad
    {
        return $this->novedad(['categoria' => $categoria, 'area_id' => $this->piso->id] + $extra);
    }

    private function guardar(Novedad $n, array $datos)
    {
        return $this->actingAs($this->admin)->put("/novedades/{$n->id}", $this->expediente($n, [
            'area_edificio_id' => $this->torre->id, 'area_piso_id' => $this->piso->id,
        ] + $datos));
    }

    private function contar(string $modelo, int $novedadId): int
    {
        return $this->enEmpresa(fn () => $modelo::where('novedad_id', $novedadId)->count());
    }

    // ------------------------------------------------------------- Accidente

    public function test_accidente_conserva_el_formulario_de_segcat_y_usa_el_lector_para_el_colaborador(): void
    {
        $n = $this->ticket('accidente');
        $pagina = $this->actingAs($this->admin)->get('/novedades?abrir='.$n->id)->assertOk();

        // Mismas secciones, etiquetas y opciones que frag_accidente.php
        $pagina->assertSeeInOrder(['1. Seguridad: Formato de Afectado', 'Tipo de Afectado', '-- Seleccionar formato a desplegar --', 'Huésped / Cliente', 'Colaborador Interno',
            'Guest Injury Report', 'Nombre del Huésped', 'No. Habitación', 'Agencia', 'Check-in', 'Check-out', 'País', 'Sexo/Edad', 'Lugar o área del accidente',
            'Explique cómo sucedió (Qué, cómo, objeto o sustancia)', '¿Asistencia médica?', '¿Por qué?', '¿Hubo testigos?',
            'Reporte Accidente de Colaborador', 'Buscar Colaborador Afectado', 'Departamento', 'Puesto', 'Turno', 'Área de Trabajo', 'Nombre del jefe inmediato',
            'Puesto del jefe', '1ra vez accidentado', 'Causas (Marcar aplicables):', 'Terceras personas', 'Acto inseguro', 'Condición insegura',
            'Explique cualquiera de los anteriores o ambos', 'Testigos', 'Aviso dado por (Nombre)', 'Depto de quien avisa', 'Actividades cotidianas',
            '¿Mismas actividades al momento del accidente?',
            '2. Servicio Médico: Dictamen Clínico', 'Tipo de Herida', 'Lacerante', 'Contusa', 'Cortante', 'Punzante', 'Abrasión', 'Quemadura', 'Amputación',
            'Hernia', 'Enfermedad', 'Otros', 'Zonas Afectadas', 'Frente', 'Espalda', 'Lat. Der', 'Lat. Izq', 'Información Complementaria', 'Primeros Auxilios',
            'Atención Médica', 'Diagnóstico preliminar', 'Hospitalización', 'Trasladado en (Ambulancia, Taxi, etc.)', 'Nombre del Doctor que atendió',
            'Observaciones Generales Médicas', '3. Guardavidas: Anexo Acuático', 'Fecha Suceso', 'Hora Suceso', 'Lugar Exacto', 'Nombre Guardavidas', 'Supervisor',
            'Condiciones del Sujeto', 'Alcoholizado', '¿Estaba Descalzo?', 'Tipo de calzado', 'Apreciación Visual del Guardavidas', 'Riesgos Identificados',
            'Descripción de los hechos', '¿Acudió a servicio médico?', 'Material de curación utilizado', 'Se informa a (Nombre)',
            '4. Uso Exclusivo Recursos Humanos', 'Días de Incapacidad', 'Se presenta a laborar:', 'Firmas Digitales de Cierre',
            'Seleccione quién va a firmar en este momento:', 'Reportante / Afectado / Testigo', 'Agente de Seguridad (Atiende)', 'Servicio Médico (Si aplica)',
            'Jefe de Área / Departamento', 'Recursos Humanos (Si aplica)', 'Ejecutivo de Guardia / Gerencia', 'Guardar Esta Firma'])
            // El colaborador se elige con el lector universal (gafete, QR, NFC o número)
            ->assertSee('data-tipos="colaborador"', false)->assertSee('name="c_id_colaborador"', false)
            ->assertSee('data-zona="Pie Der"', false)->assertDontSee('onclick=', false);
        foreach (array_keys(AccidenteFirma::ROLES) as $rol) {
            $pagina->assertSee('name="f_'.$rol.'"', false);
        }
    }

    public function test_accidente_de_colaborador_guarda_sus_secciones_y_las_seis_firmas_en_privado(): void
    {
        Storage::fake('local');
        $n = $this->ticket('accidente');
        $depto = $this->enEmpresa(fn () => Departamento::create(['nombre' => 'Ama de Llaves']));
        $rosa = $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => '3001', 'nombre' => 'Rosa', 'apellido_paterno' => 'Poot',
            'sede_id' => $this->centro->id, 'departamento_id' => $depto->id]));

        // Colaborador obligatorio cuando el afectado es colaborador
        $this->guardar($n, ['acc_tipo_afectado' => 'COLABORADOR'])
            ->assertSessionHasErrors(['c_id_colaborador' => 'Busca y elige al colaborador afectado (escanea su gafete o escribe su número de empleado).']);
        // Colaborador de otra empresa: no
        $ajeno = $this->enEmpresa(fn () => Colaborador::create(['num_empleado' => '9', 'nombre' => 'Otro', 'apellido_paterno' => 'X']), $this->crearEmpresa('Hotel Dos'));
        $this->guardar($n, ['acc_tipo_afectado' => 'COLABORADOR', 'c_id_colaborador' => $ajeno->id])
            ->assertSessionHasErrors(['c_id_colaborador' => 'El colaborador afectado no existe en esta empresa o está dado de baja.']);

        // Solo las opciones de SEGCAT
        $this->guardar($n, ['m_herida' => ['Inventada']])->assertSessionHasErrors(['m_herida.0' => 'Elige una opción válida en «Tipo de Herida».']);

        $firmas = collect(array_keys(AccidenteFirma::rolesPara('COLABORADOR')))->mapWithKeys(fn ($rol) => ['f_'.$rol => self::firmaJpeg()])->all();
        $this->guardar($n, [
            'acc_tipo_afectado' => 'COLABORADOR', 'c_id_colaborador' => $rosa->id, 'c_fecha_accidente' => '2026-10-01', 'c_hora_accidente' => '10:15',
            'c_depto_colaborador' => 'LO QUE SEA', 'c_turno_colaborador' => 'matutino', 'c_causa_condicion' => '1', 'c_primera_vez' => '0',
            'acc_testigos' => [['nombre' => 'ana  pech', 'departamento' => 'cocina'], ['nombre' => '', 'departamento' => 'vacío']],
            'm_herida' => ['Cortante'], 'm_parte' => 'Mano Izq, Pierna Inventada', 'm_hosp' => '0',
            'g_nombre' => '', 'rh_dias' => '2', 'rh_fecha' => '2026-10-04',
        ] + $firmas)->assertSessionHasNoErrors();

        $c = $this->enEmpresa(fn () => AccidenteColaborador::where('novedad_id', $n->id)->firstOrFail());
        // Departamento sale del expediente del colaborador, no de lo que mande el formulario
        $this->assertSame([$rosa->id, 'AMA DE LLAVES', 'MATUTINO', true, false, '10:15:00'],
            [$c->colaborador_id, $c->departamento, $c->turno, $c->causa_condicion_insegura, $c->primera_vez, $c->hora_accidente]);
        $this->assertSame([['ANA PECH', 'COCINA']], $this->enEmpresa(fn () => NovedadTestigo::where('novedad_id', $n->id)->where('formato', 'accidente')
            ->get()->map(fn ($t) => [$t->nombre, $t->departamento])->all()));
        $m = $this->enEmpresa(fn () => AccidenteDictamen::where('novedad_id', $n->id)->firstOrFail());
        $this->assertSame([['Cortante'], ['Mano Izq'], false], [$m->tipos_herida, $m->zonas_cuerpo, $m->hospitalizacion]);
        $this->assertSame(2, $this->enEmpresa(fn () => AccidenteIncapacidad::where('novedad_id', $n->id)->value('dias_incapacidad')));
        // Anexo de guardavidas solo si trae nombre; formato de huésped no se crea
        $this->assertSame(0, $this->contar(AccidenteGuardavidas::class, $n->id));
        $this->assertSame(0, $this->contar(AccidenteHuesped::class, $n->id));

        // Las 6 firmas, en el disco privado, nunca en public/
        $guardadas = $this->enEmpresa(fn () => AccidenteFirma::where('novedad_id', $n->id)->get());
        $this->assertCount(count(AccidenteFirma::rolesPara('COLABORADOR')), $guardadas); // Ronda 8: firmantes de un colaborador
        foreach ($guardadas as $f) {
            Storage::disk('local')->assertExists($f->ruta);
            $this->assertFileDoesNotExist(public_path($f->ruta));
        }
        // Volver a firmar un rol reemplaza el archivo anterior
        $anterior = $guardadas->firstWhere('rol', 'medico')->ruta;
        $this->guardar($n, ['acc_tipo_afectado' => 'COLABORADOR', 'c_id_colaborador' => $rosa->id, 'f_medico' => self::firmaJpeg()])->assertSessionHasNoErrors();
        Storage::disk('local')->assertMissing($anterior);
        $this->assertSame(count(AccidenteFirma::rolesPara('COLABORADOR')), $this->contar(AccidenteFirma::class, $n->id));

        // Impresión con las firmas servidas por la ruta protegida
        $this->actingAs($this->admin)->get("/novedades/{$n->id}/imprimir")->assertOk()
            ->assertSee(route('novedades.firma', [$n->id, 'medico']))->assertSee('AMA DE LLAVES');
    }

    public function test_accidente_de_huesped_y_anexo_de_guardavidas(): void
    {
        $n = $this->ticket('accidente');
        $this->guardar($n, [
            'acc_tipo_afectado' => 'HUESPED', 'h_nombre' => 'john miller', 'h_hab' => '101', 'h_edad' => '46', 'h_sexo' => 'M', 'h_testigos' => '1',
            'h_detalles_testigos' => 'su esposa', 'g_nombre' => 'pedro', 'g_alcohol' => '1', 'g_descalzo' => '1',
        ])->assertSessionHasNoErrors();
        $h = $this->enEmpresa(fn () => AccidenteHuesped::where('novedad_id', $n->id)->firstOrFail());
        $this->assertSame(['JOHN MILLER', 46, true, 'SU ESPOSA'], [$h->nombre, $h->edad, $h->hubo_testigos, $h->detalles_testigos]);
        $g = $this->enEmpresa(fn () => AccidenteGuardavidas::where('novedad_id', $n->id)->firstOrFail());
        $this->assertSame(['PEDRO', true, true], [$g->nombre, $g->alcoholizado, $g->descalzo]);
        $this->assertSame(0, $this->contar(AccidenteColaborador::class, $n->id));

        $this->guardar($n, ['acc_tipo_afectado' => 'HUESPED', 'h_edad' => '300', 'h_sexo' => 'X'])->assertSessionHasErrors(['h_edad', 'h_sexo']);
    }

    // --------------------------------------------------------- Valores a la Vista

    public function test_valores_a_la_vista_guarda_personas_aperturas_y_valores_por_zona(): void
    {
        $n = $this->ticket('habitacion');
        $this->actingAs($this->admin)->get('/novedades?abrir='.$n->id)->assertOk()->assertSee('Detalle: Valores a la Vista');

        $this->guardar($n, [
            'hab_id_area_especifica' => $this->hab101->id, 'hab_quien_reporta' => 'rosa', 'hab_caja_estado' => 'abierta_con_valores',
            'hab_caja_accion' => 'cerrada_bloqueada', 'hab_valores_dentro' => 'Pasaportes',
            'hab_personas' => [['nombre' => 'rosa poot', 'departamento' => 'ama de llaves', 'se_retira' => '1'], ['nombre' => '']],
            'hab_aperturas' => [['tipo' => 'puerta', 'estado' => 'abierta', 'es_especial' => '1'], ['tipo' => 'terraza']],
            'hab_valores' => [['zona' => 'Recámara', 'descripcion' => 'Reloj en el buró'], ['zona' => 'Baño', 'descripcion' => '']],
        ])->assertSessionHasNoErrors();

        $d = $this->enEmpresa(fn () => ValoresVistaDetalle::where('novedad_id', $n->id)->firstOrFail());
        $this->assertSame([$this->hab101->id, 'ROSA', 'abierta_con_valores', 'cerrada_bloqueada', 'Pasaportes'],
            [$d->area_especifica_id, $d->quien_reporta, $d->caja_fuerte, $d->caja_accion, $d->valores_dentro]);
        $this->assertSame(1, $this->contar(ValoresVistaPersona::class, $n->id));
        $this->assertSame(2, $this->contar(ValoresVistaApertura::class, $n->id));
        $this->assertSame(1, $this->contar(ValoresVistaZona::class, $n->id));

        // Caja cerrada: no guarda valores dentro; habitación de otra Área General: error
        $otroPiso = $this->espacio($this->centro, Espacio::AREA, 'Piso 2', $this->torre);
        $ajena = $this->espacio($this->centro, Espacio::AREA_ESPECIFICA, '201', $otroPiso);
        $this->guardar($n, ['hab_id_area_especifica' => $ajena->id])
            ->assertSessionHasErrors(['hab_id_area_especifica' => 'La habitación elegida no pertenece al Área General del ticket. Revisa el edificio y piso arriba.']);
        $this->guardar($n, ['hab_caja_estado' => 'cerrada', 'hab_valores_dentro' => 'Algo'])->assertSessionHasNoErrors();
        $this->assertNull($this->enEmpresa(fn () => ValoresVistaDetalle::where('novedad_id', $n->id)->value('valores_dentro')));
        $this->guardar($n, ['hab_aperturas' => [['tipo' => 'ventanal']]])->assertSessionHasErrors('hab_aperturas.0.tipo');
    }

    // ------------------------------------------------------ Siniestro PC

    public function test_siniestro_con_lesionados_abre_una_sola_vez_su_ticket_de_accidente(): void
    {
        $n = $this->ticket('proteccion_civil');
        $datos = ['pc_tipo_evento' => 'CONATO DE INCENDIO', 'pc_evacuacion' => '1', 'pc_num_evacuados' => '20',
            'pc_servicios' => [0 => ['activo' => '1', 'hora' => '14:25'], 1 => ['hora' => '15:00']],
            'siniestro_equipos' => [['identificador' => 'ext-07', 'estado_uso' => 'danado']], 'siniestro_danos' => [['zona' => 'cocina', 'descripcion' => 'Humo']],
            'siniestro_testigos' => [['nombre' => 'luis', 'departamento' => 'a y b']]];

        $this->guardar($n, $datos + ['pc_hubo_lesionados' => '1'])
            ->assertSessionHasErrors(['pc_num_lesionados' => 'Indica cuántas personas resultaron lesionadas.']);
        $this->guardar($n, ['pc_tipo_evento' => 'METEORITO'])->assertSessionHasErrors('pc_tipo_evento');

        $this->guardar($n, $datos + ['pc_hubo_lesionados' => '1', 'pc_num_lesionados' => '2'])->assertSessionHasNoErrors();
        $s = $this->enEmpresa(fn () => SiniestroDetalle::where('novedad_id', $n->id)->firstOrFail());
        $this->assertSame([true, 20, 2], [$s->requiere_evacuacion, $s->num_evacuados, $s->num_lesionados]);
        $this->assertSame(['Bomberos'], $this->enEmpresa(fn () => SiniestroServicio::where('novedad_id', $n->id)->pluck('servicio')->all()));

        $accidente = $this->enEmpresa(fn () => Novedad::where('origen_novedad_id', $n->id)->firstOrFail());
        $this->assertSame(['accidente', $accidente->id, $this->piso->id], [$accidente->categoria, $s->accidente_novedad_id, $accidente->area_id]);
        $this->assertStringContainsString('Se abrió el ticket de Accidente '.$accidente->folio(), $this->buscar($n->id)->notas->last()->texto);

        // Guardar otra vez no abre otro
        $this->guardar($n, $datos + ['pc_hubo_lesionados' => '1', 'pc_num_lesionados' => '3'])->assertSessionHasNoErrors();
        $this->assertSame(1, $this->enEmpresa(fn () => Novedad::where('origen_novedad_id', $n->id)->count()));
        $this->actingAs($this->admin)->get('/novedades?abrir='.$accidente->id)->assertOk()->assertSee('Generado desde')->assertSee($n->folio());
    }

    // ------------------------------------------------------------ Recorrido PC

    public function test_recorrido_pc_guarda_sus_puntos_con_las_piezas_revisadas(): void
    {
        $n = $this->ticket('recorrido_pc');
        $this->actingAs($this->admin)->get('/novedades?abrir='.$n->id)->assertOk()->assertSee('Recorrido Protección Civil (histórico');

        $this->guardar($n, ['rpc_puntos' => [['identificador' => 'ext-01']]])
            ->assertSessionHasErrors(['rpc_puntos' => 'Elige la Categoría del Equipo del punto de inspección #1.']);
        $this->guardar($n, ['rpc_puntos' => [
            ['identificador' => 'ext-01', 'categoria' => 'EXTINTOR', 'criterios' => ['cilindro' => '1', 'visible' => '1', 'inventado' => '1'], 'observaciones' => 'Manómetro bajo'],
        ]])->assertSessionHasNoErrors();
        $p = $this->enEmpresa(fn () => RecorridoPcPunto::where('novedad_id', $n->id)->firstOrFail());
        $this->assertSame(['EXT-01', true, false, true], [$p->identificador, $p->criterios['cilindro'], $p->criterios['manometro'], $p->criterios['visible']]);
        $this->assertArrayNotHasKey('inventado', $p->criterios);
    }

    // ------------------------------------------------------------- Lost & Found

    public function test_lost_found_asigna_folios_lf_y_rp_una_sola_vez_y_nacen_en_resguardo(): void
    {
        $n = $this->ticket('lost_found');
        $this->guardar($n, [
            'lf_folio_externo' => 'EXT-9', 'lf_enlace_externo' => 'no es enlace',
        ])->assertSessionHasErrors('lf_enlace_externo');
        $this->guardar($n, [
            'lf_articulos' => [['objeto' => 'teléfono celular', 'tipo_valor' => 'ELECTRONICO', 'marca' => 'samsung', 'color' => 'negro', 'area_especifica_id' => $this->hab101->id],
                ['objeto' => 'sombrero'], ['objeto' => '']],
            'rp_reportes' => [['objeto' => 'teléfono', 'tipo_valor' => 'ELECTRONICO', 'nombre_huesped' => 'laura', 'correo' => 'laura@example.com']],
        ])->assertSessionHasNoErrors();

        $articulos = $this->enEmpresa(fn () => LostFoundArticulo::orderBy('id')->get());
        $this->assertSame(['LF-000001', 'LF-000002'], $articulos->pluck('folio')->all());
        $this->assertSame([LostFoundArticulo::EN_RESGUARDO, 'OTRO', $this->centro->id], [$articulos[0]->estatus, $articulos[1]->tipo_valor, $articulos[0]->sede_id]);
        $rp = $this->enEmpresa(fn () => LostFoundReportePerdida::firstOrFail());
        $this->assertSame(['RP-000001', LostFoundReportePerdida::BUSCANDO, 'LAURA'], [$rp->folio, $rp->estatus, $rp->nombre_huesped]);

        // Volver a guardar conserva los folios; quitar uno en resguardo lo borra; nuevo toma el siguiente
        $this->guardar($n, ['lf_articulos' => [['id' => $articulos[0]->id, 'objeto' => 'teléfono celular', 'color' => 'azul'], ['objeto' => 'cartera']],
            'rp_reportes' => [['id' => $rp->id, 'objeto' => 'teléfono']]])->assertSessionHasNoErrors();
        $this->assertSame(['LF-000001' => 'AZUL', 'LF-000003' => null], $this->enEmpresa(fn () => LostFoundArticulo::orderBy('id')->pluck('color', 'folio')->all()));

        // Habitación fuera del Área General del ticket
        $otroPiso = $this->espacio($this->centro, Espacio::AREA, 'Piso 2', $this->torre);
        $ajena = $this->espacio($this->centro, Espacio::AREA_ESPECIFICA, '201', $otroPiso);
        $this->guardar($n, ['lf_articulos' => [['objeto' => 'gorra', 'area_especifica_id' => $ajena->id]]])->assertSessionHasErrors('lf_articulos');

        // Folios propios de cada empresa y umbrales por omisión
        $this->assertSame(LostFoundUmbral::POR_OMISION, $this->enEmpresa(fn () => LostFoundUmbral::vigentes()));
        $this->actingAs($this->admin)->get('/novedades')->assertSee('Folios: LF-000001, LF-000003');
        $this->actingAs($this->admin)->get("/novedades/{$n->id}/acuse")->assertOk()->assertSee('LF-000001');
    }

    public function test_buscar_coincidencias_y_vincular_un_reporte_de_perdida(): void
    {
        $hallazgo = $this->ticket('lost_found');
        $this->guardar($hallazgo, ['lf_articulos' => [['objeto' => 'teléfono celular', 'tipo_valor' => 'ELECTRONICO', 'marca' => 'Samsung', 'color' => 'negro']]])->assertSessionHasNoErrors();
        $perdida = $this->ticket('lost_found');
        $this->guardar($perdida, ['rp_reportes' => [['objeto' => 'teléfono', 'tipo_valor' => 'ELECTRONICO']]])->assertSessionHasNoErrors();
        $articulo = $this->enEmpresa(fn () => LostFoundArticulo::firstOrFail());
        $reporte = $this->enEmpresa(fn () => LostFoundReportePerdida::firstOrFail());

        $this->actingAs($this->admin)->getJson('/novedades/coincidencias?tipo_valor=ELECTRONICO&objeto=teléfono&marca=sams')
            ->assertOk()->assertJsonPath('resultados.0.folio', 'LF-000001');
        $this->actingAs($this->admin)->getJson('/novedades/coincidencias?tipo_valor=ROPA')->assertJsonCount(0, 'resultados');
        $this->actingAs($this->admin)->getJson('/novedades/coincidencias?objeto=teléfono&fecha='.now()->addDays(40)->format('Y-m-d'))->assertJsonCount(0, 'resultados');

        $this->actingAs($this->admin)->postJson("/novedades/perdidas/{$reporte->id}/vincular", ['articulo_id' => $articulo->id])->assertOk()->assertJsonPath('ok', true);
        $reporte = $this->enEmpresa(fn () => $reporte->fresh());
        $this->assertSame([LostFoundReportePerdida::VINCULADO, $articulo->id], [$reporte->estatus, $reporte->articulo_vinculado_id]);
        $this->assertDatabaseHas('auditoria', ['evento' => 'lost_found.vinculado']);
        // Ya no está en búsqueda: no se vincula otra vez; un artículo vinculado no se puede quitar del ticket
        $this->actingAs($this->admin)->postJson("/novedades/perdidas/{$reporte->id}/vincular", ['articulo_id' => $articulo->id])->assertStatus(422);
        $this->guardar($hallazgo, ['lf_articulos' => []])->assertSessionHasNoErrors();
        $this->assertTrue($this->enEmpresa(fn () => LostFoundArticulo::whereKey($articulo->id)->exists()));

        // Otra empresa: no ve ni vincula
        $ajeno = $this->crearUsuario($this->crearEmpresa('Hotel Dos'), 'Administrador');
        $this->actingAs($ajeno)->getJson('/novedades/coincidencias?objeto=teléfono')->assertJsonCount(0, 'resultados');
        $this->actingAs($ajeno)->postJson("/novedades/perdidas/{$reporte->id}/vincular", ['articulo_id' => $articulo->id])->assertNotFound();
    }

    // ------------------------------------------------------------------- Robo

    public function test_robo_guarda_testigos_con_declaracion_y_vincula_un_hallazgo(): void
    {
        $robo = $this->ticket('robo', ['area_especifica_id' => $this->hab101->id]);
        $this->guardar($robo, [
            'area_especifica_id' => $this->hab101->id, 'robo_objetos_descripcion' => 'Laptop gris', 'robo_valor_estimado' => '18000.5',
            'robo_hay_sospechoso' => '0', 'robo_descripcion_sospechoso' => 'se borra', 'robo_se_dio_parte_policia' => '1', 'robo_folio_policial' => 'fge-1',
            'robo_canalizado_legal' => '1', 'robo_testigos' => [['nombre' => 'daniela', 'departamento' => 'recepción', 'declaracion' => 'Vio a alguien con mochila.']],
        ])->assertSessionHasNoErrors();
        $r = $this->enEmpresa(fn () => RoboDetalle::where('novedad_id', $robo->id)->firstOrFail());
        $this->assertSame(['18000.50', null, 'FGE-1', true, false], [$r->valor_estimado, $r->descripcion_sospechoso, $r->folio_policial, $r->canalizado_legal, $r->canalizado_gerencia]);
        $this->assertSame('Vio a alguien con mochila.', $this->enEmpresa(fn () => NovedadTestigo::where('novedad_id', $robo->id)->value('declaracion')));
        $this->guardar($robo, ['robo_valor_estimado' => 'mucho'])->assertSessionHasErrors('robo_valor_estimado');

        $lf = $this->ticket('lost_found');
        $this->guardar($lf, ['lf_articulos' => [['objeto' => 'laptop', 'area_especifica_id' => $this->hab101->id]]])->assertSessionHasNoErrors();
        $articulo = $this->enEmpresa(fn () => LostFoundArticulo::firstOrFail());

        // La Ficha de Hechos de la habitación muestra el hallazgo con "Vincular a este caso de robo"
        $this->actingAs($this->admin)->get(route('novedades.ficha-hechos', ['habitacion' => $this->hab101->id, 'novedad' => $robo->id, 'origen' => 'robo', 'origen_id' => $robo->id]))
            ->assertOk()->assertSee('En esta habitación exacta')->assertSee('LF-000001 — LAPTOP')->assertSee('Vincular a este caso de robo');

        $this->actingAs($this->admin)->postJson("/novedades/{$robo->id}/vincular-hallazgo", ['articulo_id' => $articulo->id])->assertOk();
        $this->assertSame($articulo->id, $this->enEmpresa(fn () => RoboDetalle::where('novedad_id', $robo->id)->value('articulo_vinculado_id')));
        $this->actingAs($this->admin)->get('/novedades?abrir='.$robo->id)->assertSee('Vinculado con el hallazgo LF-000001');

        // Solo un Robo, y no si ya está resuelto
        $this->actingAs($this->admin)->postJson("/novedades/{$lf->id}/vincular-hallazgo", ['articulo_id' => $articulo->id])
            ->assertStatus(422)->assertJsonPath('errors.articulo.0', 'Solo un caso de Robo se puede vincular con un hallazgo.');
        $this->enEmpresa(fn () => Novedad::whereKey($robo->id)->update(['estatus' => Novedad::RESUELTO]));
        $this->actingAs($this->admin)->postJson("/novedades/{$robo->id}/vincular-hallazgo", ['articulo_id' => $articulo->id])
            ->assertStatus(422)->assertJsonPath('errors.articulo.0', 'Este caso ya está Resuelto. Reábrelo antes de vincular un hallazgo.');
    }

    public function test_ficha_de_hechos_respeta_empresa_y_sede(): void
    {
        $this->ticket('incidente_general', ['area_especifica_id' => $this->hab101->id, 'descripcion' => 'Ruido en la 101']);
        $this->ticket('incidente_general', ['area_especifica_id' => $this->hab102->id, 'descripcion' => 'Fuga en la 102']);

        $this->actingAs($this->admin)->get(route('novedades.ficha-hechos', ['habitacion' => $this->hab101->id]))->assertOk()
            ->assertSee('Ruido en la 101')->assertSee('En esta misma zona, como contexto')->assertSee('Fuga en la 102');
        $dePlaya = $this->crearUsuario($this->empresa, 'Jefe de seguridad', $this->playa);
        $this->actingAs($dePlaya)->get(route('novedades.ficha-hechos', ['habitacion' => $this->hab101->id]))->assertOk()
            ->assertSee('No se encontró la habitación indicada')->assertDontSee('Ruido en la 101');
        $this->actingAs($this->crearUsuario($this->crearEmpresa('Hotel Dos'), 'Administrador'))
            ->get(route('novedades.ficha-hechos', ['habitacion' => $this->hab101->id]))->assertDontSee('Ruido en la 101');
        // La fuente de otros módulos (préstamos de llaves, accesos) se enchufa por etiqueta
        $this->assertSame('novedades.ficha_hechos', FichaDeHechos::ETIQUETA);
    }

    // ------------------------------------------------------- Lista y guardado

    public function test_la_lista_carga_las_trazas_de_casos_resueltos_y_atendidos(): void
    {
        $agente = $this->crearUsuario($this->empresa, 'Agente', $this->centro);
        $n = $this->ticket('incidente_general', ['descripcion' => 'Caso trazado']);
        $this->actingAs($agente)->put("/novedades/{$n->id}", $this->expediente($n, ['estatus' => Novedad::RESUELTO, 'resolucion' => 'Listo']))->assertSessionHasNoErrors();

        $this->actingAs($this->admin)->get('/novedades')->assertOk()->assertSee('Caso trazado')->assertSee('Cerró/atendió');
        $this->actingAs($this->admin)->get('/novedades/exportar')->assertOk();
    }

    public function test_guardar_desde_otro_proceso_no_borra_lo_que_no_envia(): void
    {
        $n = $this->ticket('incidente_general', ['area_especifica_id' => $this->hab101->id, 'involucrados' => 'COCINA', 'ocurrio_en' => now()->subHour()]);
        $this->enEmpresa(fn () => app(AdministradorNovedades::class)->actualizar($this->admin, Novedad::findOrFail($n->id),
            ['categoria' => 'incidente_general', 'estatus' => Novedad::ABIERTO, 'nueva_nota' => 'Solo una nota']));
        $n = $this->buscar($n->id);
        $this->assertSame([$this->piso->id, $this->hab101->id, 'COCINA'], [$n->area_id, $n->area_especifica_id, $n->involucrados]);
        $this->assertNotNull($n->ocurrio_en);
    }
}
