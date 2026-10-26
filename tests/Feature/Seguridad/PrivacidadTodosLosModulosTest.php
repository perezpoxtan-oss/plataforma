<?php

namespace Tests\Feature\Seguridad;

use App\Models\AccidenteColaborador;
use App\Models\AccidenteDictamen;
use App\Models\AccidenteFirma;
use App\Models\AccidenteGuardavidas;
use App\Models\AccidenteHuesped;
use App\Models\Auditoria;
use App\Models\Colaborador;
use App\Models\Espacio;
use App\Models\LostFoundArticulo;
use App\Models\LostFoundEntrega;
use App\Models\LostFoundReportePerdida;
use App\Models\MovimientoTransporte;
use App\Models\Novedad;
use App\Models\NovedadTestigo;
use App\Models\Persona;
use App\Models\Proveedor;
use App\Models\Reserva;
use App\Models\Ruta;
use App\Models\SolicitudArco;
use App\Models\Turno;
use App\Services\Privacidad\ConservacionDatos;
use App\Services\Privacidad\SolicitudesArco;
use App\Services\Reservas\CatalogosReservas;
use App\Services\Rutas\AdministradorRutas;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * C-37 · Privacidad completa (X-01; docs/tecnico/privacidad.md): una
 * solicitud ARCO de un huésped con un objeto en Lost & Found, un accidente
 * (con datos médicos), una reserva y un viaje en transporte lo encuentra y
 * lo trata TODO; el conductor de un taxi pierde su firma; los accidentes de
 * colaborador se conservan; la conservación alcanza los mismos módulos y
 * nada se borra en una empresa que no confirmó los plazos nuevos. Los
 * formularios de Accidentes no cambian: los datos se crean con ellos.
 * Vinculados: informes, auditoría y búsqueda.
 */
class PrivacidadTodosLosModulosTest extends PruebaNovedades
{
    private const NOMBRE = 'Ernesto Valdivia';

    private Espacio $torre;

    private Espacio $piso;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->travelTo(Carbon::parse('2026-11-10 15:00:00', 'UTC'));
        $this->torre = $this->espacio($this->centro, Espacio::EDIFICIO, 'Torre A');
        $this->piso = $this->espacio($this->centro, Espacio::AREA, 'Piso 1', $this->torre);
    }

    private function guardarExpediente(Novedad $n, array $datos)
    {
        return $this->actingAs($this->admin)->put("/novedades/{$n->id}", $this->expediente($n, [
            'area_edificio_id' => $this->torre->id, 'area_piso_id' => $this->piso->id,
        ] + $datos));
    }

    /** Accidente de huésped con dictamen médico, anexo de guardavidas, testigo y firma del afectado, capturado con su formulario. */
    private function accidenteDeHuesped(string $nombre): Novedad
    {
        $n = $this->novedad(['categoria' => 'accidente', 'area_id' => $this->piso->id, 'involucrados' => $nombre.' y su esposa']);
        $this->guardarExpediente($n, [
            'acc_tipo_afectado' => 'HUESPED', 'h_nombre' => $nombre, 'h_hab' => '101', 'h_edad' => '46', 'h_sexo' => 'M', 'h_pais' => 'México',
            'h_testigos' => '1', 'h_detalles_testigos' => 'su esposa',
            'm_diagnostico' => 'Esguince de tobillo derecho', 'm_hosp' => '1', 'm_hosp_nombre' => 'Hospital Galenia', 'm_doctor' => 'Dra. Pérez',
            'g_nombre' => 'pedro guardavidas', 'g_tipo_herida' => 'esguince', 'g_parte_afectada' => 'tobillo',
            'f_afectado' => self::firmaJpeg(),
        ])->assertSessionHasNoErrors();

        return $n->refresh();
    }

    /** Ticket de Lost & Found con el reporte de pérdida del huésped (formulario del expediente). */
    private function reportePerdida(string $nombre): LostFoundReportePerdida
    {
        $n = $this->novedad(['categoria' => 'lost_found', 'area_id' => $this->piso->id]);
        $this->guardarExpediente($n, ['rp_reportes' => [['objeto' => 'cartera', 'tipo_valor' => 'OTRO', 'nombre_huesped' => $nombre,
            'telefono' => '998 123 4567', 'correo' => 'ernesto@ejemplo.mx']]])->assertSessionHasNoErrors();

        return $this->enEmpresa(fn () => LostFoundReportePerdida::where('novedad_id', $n->id)->firstOrFail());
    }

    /** El objeto que se le devolvió en persona, con su firma. */
    private function entrega(string $nombre): LostFoundEntrega
    {
        $n = $this->novedad(['categoria' => 'lost_found', 'area_id' => $this->piso->id]);
        $this->guardarExpediente($n, ['lf_articulos' => [['objeto' => 'lentes']]])->assertSessionHasNoErrors();

        return $this->enEmpresa(function () use ($nombre) {
            $articulo = LostFoundArticulo::latest('id')->firstOrFail();
            $firma = 'firmas/'.$this->empresa->id.'/lf-'.uniqid().'.jpg';
            Storage::disk('local')->put($firma, 'firma');
            $articulo->forceFill(['estatus' => 'DEVUELTO', 'cerrado_en' => now()])->save();

            return LostFoundEntrega::create(['articulo_id' => $articulo->id, 'tipo_cierre' => 'PERSONA', 'nombre_recibe' => mb_strtoupper($nombre),
                'tipo_identificacion' => 'INE', 'correo_recibe' => 'ernesto@ejemplo.mx', 'firma_ruta' => $firma]);
        });
    }

    private function reserva(string $nombre): Reserva
    {
        return $this->enEmpresa(function () use ($nombre) {
            $salon = $this->espacio($this->centro, Espacio::AREA_ESPECIFICA, 'Salón Maya', $this->piso);
            $area = app(CatalogosReservas::class)->marcarReservable($this->admin, $salon);
            $r = new Reserva;
            $r->forceFill([
                'sede_id' => $this->centro->id, 'reserva_area_id' => $area->id, 'espacio_id' => $salon->id, 'folio' => 'R-0001',
                'titulo' => 'Cena de aniversario de '.$nombre, 'asistentes' => 20, 'inicio' => now()->addDay(), 'fin' => now()->addDay()->addHours(3),
                'bloqueo_desde' => now()->addDay(), 'bloqueo_hasta' => now()->addDay()->addHours(3), 'estado' => Reserva::CONFIRMADA,
                'para_cliente' => true, 'cliente_nombre' => $nombre, 'cliente_empresa' => 'Valdivia Consultores', 'cliente_correo' => 'ernesto@ejemplo.mx',
                'cliente_telefono' => '+52 998 123 4567', 'notas' => 'Llamar a '.$nombre.' para confirmar el menú.',
                'importe_area' => 0, 'importe_servicios' => 0, 'descuento' => 0, 'cortesia' => false, 'iva_porcentaje' => 0, 'iva' => 0, 'total' => 0, 'moneda' => 'MXN',
                'excede_aforo' => false, 'dia_completo' => false,
            ])->save();

            return $r;
        });
    }

    private function ruta(): Ruta
    {
        [$turno, $proveedor] = $this->enEmpresa(fn () => [
            Turno::create(['nombre' => 'Matutino', 'hora_inicio' => '07:00:00', 'hora_fin' => '15:00:00', 'todas_las_sedes' => true, 'activo' => true]),
            Proveedor::create(['nombre' => 'Transportes Kin-Ha', 'categoria' => 'transporte_personal', 'activo' => true, 'todas_las_sedes' => true]),
        ]);

        return $this->enEmpresa(fn () => app(AdministradorRutas::class)->crear($this->admin, $this->centro, [
            'sentido' => 'llegada', 'nombre' => 'RUTA 1', 'turno_id' => $turno->id, 'proveedor_id' => $proveedor->id, 'costo_maximo_taxi' => 250,
            'horarios' => [['nombre' => 'Diario', 'dias' => ['LU', 'MA', 'MI', 'JU', 'VI', 'SA', 'DO'], 'hora_inicio' => '06:00', 'hora_fin' => '06:40', 'paraderos' => [['nombre' => 'Centro', 'hora' => '']]]],
        ]));
    }

    private function viaje(Ruta $ruta, array $extra): MovimientoTransporte
    {
        return $this->enEmpresa(function () use ($ruta, $extra) {
            $firma = 'firmas/'.$this->empresa->id.'/taxi-'.uniqid().'.jpg';
            Storage::disk('local')->put($firma, 'firma');
            $m = new MovimientoTransporte(['sede_id' => $this->centro->id, 'ruta_id' => $ruta->id, 'tipo_movimiento' => 'llegada', 'estatus' => 'no_llego', 'fecha' => now()->toDateString(),
                'firma_taxista' => $firma, 'firma_guardia' => 'firmas/'.$this->empresa->id.'/guardia.jpg'] + $extra);
            $m->save();

            return $m;
        });
    }

    private function solicitud(): SolicitudArco
    {
        $s = app(SolicitudesArco::class)->crear($this->empresa, ['derecho' => 'cancelacion', 'nombre' => self::NOMBRE, 'correo' => 'ernesto@ejemplo.mx',
            'telefono' => '9981234567', 'detalle' => 'Fui huésped y quiero que borren todos mis datos.', 'acepta' => '1'], '127.0.0.1')[0];
        app(SolicitudesArco::class)->aprobarVerificacion($this->admin, $s);

        return $s->refresh();
    }

    // -------------------------------------------------------------------------

    public function test_arco_de_un_huesped_encuentra_y_trata_lost_found_accidente_reserva_y_transporte(): void
    {
        $reporte = $this->reportePerdida(self::NOMBRE);
        $entrega = $this->entrega(self::NOMBRE);
        $accidente = $this->accidenteDeHuesped(self::NOMBRE);
        $reserva = $this->reserva(self::NOMBRE);
        $ruta = $this->ruta();
        $viaje = $this->viaje($ruta, ['observaciones' => 'Taxi del huésped '.self::NOMBRE.' al aeropuerto.']);
        $ajeno = $this->viaje($ruta, ['observaciones' => 'Taxi de otra persona.']);
        // El formulario de Accidentes guardó los datos médicos y la firma del afectado
        $dictamen = $this->enEmpresa(fn () => AccidenteDictamen::where('novedad_id', $accidente->id)->firstOrFail());
        $this->assertSame('Hospital Galenia', $dictamen->nombre_hospital);
        $firmaAfectado = $this->enEmpresa(fn () => AccidenteFirma::where('novedad_id', $accidente->id)->where('rol', 'afectado')->value('ruta'));
        $this->assertNotNull($firmaAfectado);
        Storage::disk('local')->assertExists($firmaAfectado);
        $antes = $this->enEmpresa(fn () => [Novedad::count(), Reserva::count(), MovimientoTransporte::count()]);

        $s = $this->solicitud();
        $criterios = ['nombre' => self::NOMBRE, 'correo' => 'ernesto@ejemplo.mx', 'telefono' => '998-123-4567', 'folio' => ''];

        // 1. Buscar: lo encuentra en todos los módulos
        $r = $this->actingAs($this->admin)->get(route('arco.show', [$s->id] + $criterios))->assertOk()
            ->assertSee('Lost &amp; Found: reportes de pérdida', false)->assertSee('Lost &amp; Found: entregas', false)
            ->assertSee('Accidentes (con datos médicos)')->assertSee('Reservas')->assertSee('Bitácora de transporte')->assertSee('Novedades que la mencionan')
            ->assertSee('name="seleccion[lost_found_reporte][]" value="'.$reporte->id.'"', false)
            ->assertSee('name="seleccion[lost_found_entrega][]" value="'.$entrega->id.'"', false)
            ->assertSee('name="seleccion[accidente][]" value="'.$accidente->id.'"', false)
            ->assertSee('name="seleccion[reserva][]" value="'.$reserva->id.'"', false)
            ->assertSee('name="seleccion[transporte][]" value="'.$viaje->id.'"', false)
            ->assertSee('con datos médicos');
        $this->assertStringNotContainsString('name="seleccion[transporte][]" value="'.$ajeno->id.'"', $r->getContent());
        // También por solo el correo o el teléfono (sin el nombre)
        $soloCorreo = app(SolicitudesArco::class)->buscarDatos($s, ['correo' => 'ERNESTO@ejemplo.mx']);
        $this->assertSame([$reporte->id], array_column($soloCorreo['lost_found_reporte'], 'id'));
        $this->assertSame([$entrega->id], array_column($soloCorreo['lost_found_entrega'], 'id'));
        $this->assertSame([$reserva->id], array_column($soloCorreo['reserva'], 'id'));
        $soloTelefono = app(SolicitudesArco::class)->buscarDatos($s, ['telefono' => '9981234567']);
        $this->assertSame([$reporte->id], array_column($soloTelefono['lost_found_reporte'], 'id'));
        $this->assertSame([$reserva->id], array_column($soloTelefono['reserva'], 'id'));

        $seleccion = ['lost_found_reporte' => [$reporte->id], 'lost_found_entrega' => [$entrega->id], 'accidente' => [$accidente->id],
            'novedad' => [$accidente->id], 'reserva' => [$reserva->id], 'transporte' => [$viaje->id]];

        // 2. Exportar (derecho de acceso): todos los módulos y sus firmas viajan en el ZIP
        $this->actingAs($this->admin)->post(route('arco.exportar', $s->id), ['seleccion' => $seleccion, 'criterios' => $criterios])->assertSessionHas('ok');
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path($s->refresh()->archivo)) === true);
        $nombres = array_map(fn ($i) => $zip->getNameIndex($i), range(0, $zip->numFiles - 1));
        foreach (['lost_found_reportes_perdida', 'lost_found_entregas', 'accidentes_huesped', 'accidentes_datos_medicos', 'reservas', 'transporte', 'novedades'] as $archivo) {
            $this->assertContains('datos/'.$archivo.'.json', $nombres, $archivo);
        }
        $this->assertContains('archivos/'.$entrega->firma_ruta, $nombres);
        $this->assertContains('archivos/'.$firmaAfectado, $nombres);
        $this->assertStringContainsString('Esguince de tobillo derecho', (string) $zip->getFromName('datos/accidentes_datos_medicos.json'));
        $this->assertStringNotContainsString('guardia.jpg', (string) $zip->getFromName('datos/transporte.json')); // la firma del guardia es de otra persona
        $zip->close();

        // 3. Eliminar (cancelación): todo se trata y los registros se conservan
        $this->actingAs($this->admin)->post(route('arco.eliminar', $s->id), ['seleccion' => $seleccion, 'criterios' => $criterios, 'confirmar' => '1'])
            ->assertSessionHas('ok', fn ($m) => str_contains($m, 'Lost & Found: reportes de pérdida: 1') && str_contains($m, 'Accidentes (con datos médicos): 1')
                && str_contains($m, 'Reservas: 1') && str_contains($m, 'Bitácora de transporte: 1'));
        $texto = SolicitudesArco::TEXTO_ELIMINADO;

        $this->enEmpresa(function () use ($reporte, $entrega, $accidente, $reserva, $viaje, $ajeno, $texto, $firmaAfectado) {
            $rp = LostFoundReportePerdida::findOrFail($reporte->id);
            $this->assertSame([$texto, null, null], [$rp->nombre_huesped, $rp->telefono, $rp->correo]);
            $this->assertSame('CARTERA', $rp->objeto); // el reporte y su objeto se conservan
            $e = LostFoundEntrega::findOrFail($entrega->id);
            $this->assertSame([$texto, null, null, null], [$e->nombre_recibe, $e->tipo_identificacion, $e->correo_recibe, $e->firma_ruta]);
            Storage::disk('local')->assertMissing($entrega->firma_ruta);

            // Accidente: datos del huésped, datos médicos y firma del afectado
            $h = AccidenteHuesped::where('novedad_id', $accidente->id)->firstOrFail();
            $this->assertSame([$texto, null, null, null, null], [$h->nombre, $h->num_habitacion, $h->edad, $h->sexo, $h->detalles_testigos]);
            $this->assertTrue((bool) $h->requiere_asistencia_medica); // las casillas del hecho se conservan
            $d = AccidenteDictamen::where('novedad_id', $accidente->id)->firstOrFail();
            $this->assertSame([null, null, null], [$d->diagnostico, $d->nombre_hospital, $d->nombre_medico]);
            $g = AccidenteGuardavidas::where('novedad_id', $accidente->id)->firstOrFail();
            $this->assertSame('PEDRO GUARDAVIDAS', $g->nombre); // personal de la empresa
            $this->assertNull($g->parte_afectada);
            $this->assertFalse(AccidenteFirma::where('novedad_id', $accidente->id)->where('rol', 'afectado')->exists());
            Storage::disk('local')->assertMissing($firmaAfectado);
            $n = Novedad::findOrFail($accidente->id);
            $this->assertNull($n->involucrados);
            $this->assertNotNull($n->datos_eliminados_en);

            $rv = Reserva::findOrFail($reserva->id);
            $this->assertSame([$texto, null, null, null], [$rv->cliente_nombre, $rv->cliente_empresa, $rv->cliente_correo, $rv->cliente_telefono]);
            $this->assertStringNotContainsStringIgnoringCase('Valdivia', $rv->titulo.' '.$rv->notas);
            $this->assertSame(Reserva::CONFIRMADA, $rv->estado); // la reserva sigue en pie

            $m = MovimientoTransporte::findOrFail($viaje->id);
            $this->assertStringNotContainsStringIgnoringCase('Valdivia', (string) $m->observaciones);
            $this->assertSame('Taxi de otra persona.', MovimientoTransporte::findOrFail($ajeno->id)->observaciones);
            $this->assertNotNull($m->firma_guardia); // la del guardia no se toca
        });

        // 4. Volver a buscar: ya no hay nada de la persona
        $despues = app(SolicitudesArco::class)->buscarDatos($s, $criterios);
        foreach (['lost_found_reporte', 'lost_found_entrega', 'accidente', 'novedad', 'reserva', 'transporte'] as $tipo) {
            $this->assertSame([], $despues[$tipo], $tipo);
        }

        // Vinculados: los informes siguen contando los registros; la auditoría guarda qué se hizo sin el nombre
        $this->assertSame($antes, $this->enEmpresa(fn () => [Novedad::count(), Reserva::count(), MovimientoTransporte::count()]));
        foreach (['tablero.index', 'bitacora_dia.index', 'tendencias.index', 'informe_ejecutivo.index'] as $ruta) {
            $this->actingAs($this->admin)->get(route($ruta))->assertOk();
        }
        $this->actingAs($this->admin)->get('/novedades?abrir='.$accidente->id)->assertOk()->assertSee($accidente->folio())->assertDontSee('Valdivia')->assertDontSee('Hospital Galenia');
        $eliminado = Auditoria::where('evento', 'privacidad.arco_eliminado')->firstOrFail();
        $this->assertSame(1, $eliminado->despues['accidente']);
        $this->assertStringNotContainsString('Valdivia', json_encode($eliminado->despues));
        $this->actingAs($this->admin)->get('/auditoria')->assertOk();
    }

    public function test_el_conductor_del_taxi_pierde_su_firma_y_el_accidente_de_un_colaborador_solo_se_consulta(): void
    {
        $ruta = $this->ruta();
        $chofer = $this->enEmpresa(fn () => Persona::create(['tipo' => 'proveedor', 'nombre_completo' => 'JOAQUIN TAXISTA RUIZ', 'telefono' => '9997776655']));
        $viaje = $this->viaje($ruta, ['chofer_id' => $chofer->id]);
        $colaborador = $this->enEmpresa(fn () => Colaborador::create(['sede_id' => $this->centro->id, 'num_empleado' => '501', 'nombre' => 'Joaquin', 'apellido_paterno' => 'Taxista', 'apellido_materno' => 'Ruiz']));
        $accidente = $this->novedad(['categoria' => 'accidente', 'area_id' => $this->piso->id]);
        $this->guardarExpediente($accidente, ['acc_tipo_afectado' => 'COLABORADOR', 'c_id_colaborador' => $colaborador->id, 'm_diagnostico' => 'Corte en la mano'])->assertSessionHasNoErrors();
        $this->assertTrue($this->enEmpresa(fn () => AccidenteColaborador::where('novedad_id', $accidente->id)->exists()));

        $s = $this->solicitud();
        $encontrado = app(SolicitudesArco::class)->buscarDatos($s, ['nombre' => 'Joaquin Taxista Ruiz']);
        $this->assertSame([$viaje->id], array_column($encontrado['transporte'], 'id'));
        $this->assertStringContainsString('conductor del taxi (con firma)', $encontrado['transporte'][0]['detalle']);
        $this->assertSame([$accidente->id], array_column($encontrado['accidente'], 'id'));
        $this->assertStringContainsString('se conserva (relación laboral)', $encontrado['accidente'][0]['detalle']);

        $h = app(SolicitudesArco::class)->eliminar($this->admin, $s, ['persona' => [$chofer->id], 'transporte' => [$viaje->id], 'accidente' => [$accidente->id]], 'Joaquin Taxista Ruiz');
        $this->assertSame([1, 1, 0, 1], [$h['persona'], $h['transporte'], $h['accidente'], $h['omitidos']]);
        $this->enEmpresa(function () use ($viaje, $accidente) {
            $this->assertNull(MovimientoTransporte::findOrFail($viaje->id)->firma_taxista);
            Storage::disk('local')->assertMissing($viaje->firma_taxista);
            $this->assertSame('Corte en la mano', AccidenteDictamen::where('novedad_id', $accidente->id)->value('diagnostico')); // relación laboral
        });
    }

    public function test_la_conservacion_alcanza_lost_found_novedades_accidentes_reservas_y_transporte(): void
    {
        $reporte = $this->reportePerdida(self::NOMBRE);
        $entrega = $this->entrega(self::NOMBRE);
        $accidente = $this->accidenteDeHuesped(self::NOMBRE);
        $reserva = $this->reserva(self::NOMBRE);
        $viaje = $this->viaje($this->ruta(), []);
        $testigo = $this->novedad(['categoria' => 'incidente_general', 'involucrados' => 'Vecino de la 102']);
        $this->enEmpresa(fn () => NovedadTestigo::create(['novedad_id' => $testigo->id, 'formato' => 'general', 'nombre' => 'LUIS TESTIGO', 'declaracion' => 'Vi todo.']));
        // Los tickets se cierran
        $this->enEmpresa(fn () => Novedad::whereIn('id', [$accidente->id, $testigo->id])->update(['estatus' => Novedad::RESUELTO, 'cerrado_en' => now(), 'resolucion' => 'Atendido.']));

        // Empresa que ya existía: los plazos nuevos no se aplican hasta confirmarlos
        $p = $this->empresa->preferencias ?? [];
        $p['privacidad'] = ['plazos_ampliados' => false];
        $this->empresa->forceFill(['preferencias' => $p])->save();
        $this->actingAs($this->admin)->get('/configuracion')->assertOk()->assertSee('se aplicarán cuando revises los días y guardes esta tarjeta');
        $this->travel(6 * 365)->days();
        $this->artisan('plataforma:privacidad')->assertSuccessful();
        $this->assertSame(mb_strtoupper(self::NOMBRE), $this->enEmpresa(fn () => LostFoundReportePerdida::findOrFail($reporte->id)->nombre_huesped));

        // El administrador los confirma al guardar la tarjeta
        $this->flushSession();
        $this->actingAs($this->admin)->put('/configuracion/privacidad', ['conservacion_activa' => '1', 'fotos_persona_dias' => 90, 'fotos_identificacion_dias' => 90,
            'visitantes_dias' => 365, 'lost_found_dias' => 365, 'incidentes_dias' => 1825, 'reservas_dias' => 1825, 'transporte_dias' => 1825, 'arco_recordar_dias' => 5])->assertSessionHasNoErrors();
        $this->assertTrue(app(ConservacionDatos::class)->ajustes($this->empresa->refresh())['ampliados']);
        $this->assertSame(1, app(ConservacionDatos::class)->pendientes($this->empresa)['reservas']);
        $this->flushSession();
        $this->artisan('plataforma:privacidad')->assertSuccessful();

        $texto = ConservacionDatos::TEXTO_ELIMINADO;
        $this->enEmpresa(function () use ($reporte, $entrega, $accidente, $reserva, $viaje, $testigo, $texto) {
            $this->assertSame($texto, LostFoundReportePerdida::findOrFail($reporte->id)->nombre_huesped);
            $this->assertNull(LostFoundEntrega::findOrFail($entrega->id)->firma_ruta);
            $this->assertSame($texto, AccidenteHuesped::where('novedad_id', $accidente->id)->value('nombre'));
            $this->assertNull(AccidenteDictamen::where('novedad_id', $accidente->id)->value('diagnostico'));
            $this->assertSame($texto, Reserva::findOrFail($reserva->id)->cliente_nombre);
            $this->assertNull(MovimientoTransporte::findOrFail($viaje->id)->firma_taxista);
            $this->assertSame([$texto, null], NovedadTestigo::where('novedad_id', $testigo->id)->get(['nombre', 'declaracion'])->map(fn ($t) => [$t->nombre, $t->declaracion])->first());
            $this->assertNull(Novedad::findOrFail($testigo->id)->involucrados);
            $this->assertSame('Prueba', Novedad::findOrFail($testigo->id)->descripcion); // el hecho se conserva
        });
        $aplicada = Auditoria::where('evento', 'privacidad.conservacion_aplicada')->latest('id')->firstOrFail();
        $this->assertSame(1, $aplicada->despues['reservas']);
        $this->assertSame(2, $aplicada->despues['lost_found']);
        // Una segunda corrida no vuelve a tocar nada
        $this->assertSame(0, array_sum(array_intersect_key(app(ConservacionDatos::class)->pendientes($this->empresa->refresh()), ['lost_found' => 1, 'incidentes' => 1, 'reservas' => 1, 'transporte' => 1])));
    }
}
