{{--
    Impresión del Expediente de Novedad (hoja carta, se imprime o se guarda
    como PDF desde el navegador). Muestra el reporte inicial, las preguntas
    base, el formato de la categoría, el Minuto a Minuto y el cierre.
--}}
@php
    $version = fn (string $archivo) => asset($archivo).'?v='.(@filemtime(public_path($archivo)) ?: '1');
    $sn = fn ($valor) => $valor === null ? '—' : ($valor ? 'Sí' : 'No');
    $txt = fn ($valor) => ($valor === null || $valor === '') ? '—' : $valor;
    $hora = fn ($valor) => $valor ? substr((string) $valor, 0, 5) : '—';
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Expediente {{ $n->folio() }} — {{ $n->etiquetaCategoria() }}</title>
    <link href="{{ $version('vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ $version('vendor/bootstrap-icons/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ $version('css/plataforma.css') }}" rel="stylesheet">
    <link href="{{ $version('css/modos-pantalla.css') }}" rel="stylesheet">
    <script src="{{ $version('js/modo-pantalla.js') }}"></script>
</head>
<body class="pagina-impresion-novedad">
<main>
    <div class="controles-impresion">
        <h1>Expediente {{ $n->folio() }} — listo para imprimir</h1>
        <p>Imprime o guarda como PDF desde el navegador.</p>
        <div class="acciones">
            <button type="button" class="btn-imprimir-calcomania" data-accion="imprimir"><i class="bi bi-printer me-2" aria-hidden="true"></i>Imprimir Expediente</button>
            <a href="{{ route('novedades.index', ['abrir' => $n->id]) }}" class="btn-cerrar-calcomania"><i class="bi bi-folder2-open me-1" aria-hidden="true"></i> Volver al expediente</a>
        </div>
    </div>

    <article class="hoja-expediente">
        <header class="encabezado-impresion">
            <div>
                <div class="dato-etiqueta">{{ $empresaNombre }} · {{ $n->sede?->nombre }}</div>
                <h2>Expediente de Novedad {{ $n->folio() }}</h2>
                <div class="subtitulo-impresion">{{ $n->etiquetaCategoria() }}</div>
            </div>
            <div class="estatus-impresion {{ $n->estatus }}">{{ $n->etiquetaEstatus() }}</div>
        </header>

        <section>
            <h3>Reporte inicial</h3>
            <div class="datos-impresion">
                <div><span class="dato-etiqueta">Reportado</span>@fecha($n->created_at) · {{ $n->creador?->name ?? '—' }}</div>
                <div><span class="dato-etiqueta">¿Quién reporta?</span>{{ $n->reportado_por }}</div>
                <div><span class="dato-etiqueta">Ubicación específica</span>{{ $n->ubicacion }}</div>
                <div><span class="dato-etiqueta">Área General</span>{{ $txt($n->textoArea(false)) }}</div>
                <div><span class="dato-etiqueta">Habitación específica</span>{{ $txt($n->areaEspecifica?->nombre) }}</div>
                <div><span class="dato-etiqueta">¿Cuándo sucedió?</span>{{ $n->ocurrio_en ? app(\App\Support\HoraLocal::class)->formatear($n->ocurrio_en) : '—' }}</div>
                <div><span class="dato-etiqueta">¿A quién se canaliza?</span>{{ $txt($n->asignado?->name) }}</div>
                <div><span class="dato-etiqueta">¿Involucra a más personas o áreas?</span>{{ $txt($n->involucrados) }}</div>
                <div class="completo"><span class="dato-etiqueta">¿Qué sucedió?</span>{!! nl2br(e($n->descripcion)) !!}</div>
                <div class="completo"><span class="dato-etiqueta">¿Cómo sucedió?</span>{!! nl2br(e($txt($n->como_sucedio))) !!}</div>
                @if ($n->origen)
                    <div class="completo"><span class="dato-etiqueta">Generado desde</span>Ticket {{ $n->origen->folio() }} · {{ $n->origen->etiquetaCategoria() }}</div>
                @endif
            </div>
        </section>

        @switch($n->categoria)
            @case('incidente_general')
                @php $r = $n->reporteGeneral; @endphp
                <section>
                    <h3>Detalle: Reporte General</h3>
                    <div class="datos-impresion">
                        <div class="completo"><span class="dato-etiqueta">¿Quién o quiénes fueron observados?</span>{{ $txt($r?->observados) }}</div>
                        <div><span class="dato-etiqueta">¿Qué actividad realizaban?</span>{{ $txt($r?->actividad) }}</div>
                        <div><span class="dato-etiqueta">¿Por qué la realizaban?</span>{{ $txt($r?->motivo) }}</div>
                        <div class="completo"><span class="dato-etiqueta">Acciones Inmediatas Tomadas por Seguridad</span>{!! nl2br(e($txt($r?->acciones_inmediatas))) !!}</div>
                    </div>
                </section>
                @break

            @case('accidente')
                @php $h = $n->accidenteHuesped; $c = $n->accidenteColaborador; $m = $n->accidenteDictamen; $g = $n->accidenteGuardavidas; $rh = $n->accidenteIncapacidad; @endphp
                <section>
                    <h3>1. Seguridad: Formato de Afectado — {{ $h ? 'Huésped / Cliente' : ($c ? 'Colaborador Interno' : 'Sin seleccionar') }}</h3>
                    @if ($h)
                        <div class="datos-impresion">
                            <div><span class="dato-etiqueta">Fecha y hora del accidente</span>{{ $h->fecha_accidente?->format('d/m/Y') ?? '—' }} {{ $hora($h->hora_accidente) }}</div>
                            <div><span class="dato-etiqueta">Nombre del Huésped</span>{{ $txt($h->nombre) }}</div>
                            <div><span class="dato-etiqueta">No. Habitación / Agencia</span>{{ $txt($h->num_habitacion) }} / {{ $txt($h->agencia) }}</div>
                            <div><span class="dato-etiqueta">Check-in / Check-out</span>{{ $h->fecha_check_in?->format('d/m/Y') ?? '—' }} / {{ $h->fecha_check_out?->format('d/m/Y') ?? '—' }}</div>
                            <div><span class="dato-etiqueta">País · Sexo/Edad</span>{{ $txt($h->pais) }} · {{ $txt($h->sexo) }} {{ $h->edad }}</div>
                            <div><span class="dato-etiqueta">Lugar o área del accidente</span>{{ $txt($h->lugar) }}</div>
                            <div class="completo"><span class="dato-etiqueta">Explique cómo sucedió</span>{!! nl2br(e($txt($h->explicacion))) !!}</div>
                            <div><span class="dato-etiqueta">¿Asistencia médica? ¿Por qué?</span>{{ $sn($h->requiere_asistencia_medica) }} — {{ $txt($h->motivo_asistencia) }}</div>
                            <div><span class="dato-etiqueta">¿Hubo testigos?</span>{{ $sn($h->hubo_testigos) }}{{ $h->detalles_testigos ? ' — '.$h->detalles_testigos : '' }}</div>
                        </div>
                    @elseif ($c)
                        <div class="datos-impresion">
                            <div><span class="dato-etiqueta">Fecha y hora del accidente</span>{{ $c->fecha_accidente?->format('d/m/Y') ?? '—' }} {{ $hora($c->hora_accidente) }}</div>
                            <div><span class="dato-etiqueta">Colaborador</span>{{ $c->colaborador ? $c->colaborador->nombreCompleto().' · Núm. '.$c->colaborador->num_empleado : '—' }}</div>
                            <div><span class="dato-etiqueta">Departamento / Puesto</span>{{ $txt($c->departamento) }} / {{ $txt($c->puesto) }}</div>
                            <div><span class="dato-etiqueta">Turno / Área de Trabajo</span>{{ $txt($c->turno) }} / {{ $txt($c->area_trabajo) }}</div>
                            <div><span class="dato-etiqueta">Jefe inmediato / Puesto</span>{{ $txt($c->jefe_inmediato) }} / {{ $txt($c->puesto_jefe) }}</div>
                            <div><span class="dato-etiqueta">1ra vez accidentado</span>{{ $sn($c->primera_vez) }}</div>
                            <div class="completo"><span class="dato-etiqueta">Causas</span>{{ collect(['Terceras personas' => $c->causa_terceras_personas, 'Acto inseguro' => $c->causa_acto_inseguro, 'Condición insegura' => $c->causa_condicion_insegura])->filter()->keys()->join(', ') ?: '—' }}</div>
                            <div class="completo"><span class="dato-etiqueta">Explicación</span>{!! nl2br(e($txt($c->explicacion_causas))) !!}</div>
                            <div class="completo"><span class="dato-etiqueta">Testigos</span>{{ $n->testigos->where('formato', 'accidente')->map(fn ($t) => $t->nombre.($t->departamento ? ' ('.$t->departamento.')' : ''))->join(', ') ?: '—' }}</div>
                            <div><span class="dato-etiqueta">Aviso dado por / Depto</span>{{ $txt($c->aviso_dado_por) }} / {{ $txt($c->depto_aviso) }}</div>
                            <div><span class="dato-etiqueta">¿Mismas actividades al momento?</span>{{ $sn($c->mismas_actividades) }}</div>
                            <div class="completo"><span class="dato-etiqueta">Actividades cotidianas</span>{!! nl2br(e($txt($c->actividades_cotidianas))) !!}</div>
                        </div>
                    @endif
                </section>
                <section>
                    <h3>2. Servicio Médico: Dictamen Clínico</h3>
                    <div class="datos-impresion">
                        <div><span class="dato-etiqueta">Tipo de Herida</span>{{ implode(', ', $m?->tipos_herida ?? []) ?: '—' }}</div>
                        <div><span class="dato-etiqueta">Zonas Afectadas</span>{{ implode(', ', $m?->zonas_cuerpo ?? []) ?: '—' }}</div>
                        <div><span class="dato-etiqueta">Primeros Auxilios</span>{{ $sn($m?->primeros_auxilios) }}{{ $m?->cuales_auxilios ? ' — '.$m->cuales_auxilios : '' }}</div>
                        <div><span class="dato-etiqueta">Atención Médica</span>{{ $sn($m?->atencion_medica) }}{{ $m?->cuales_atencion ? ' — '.$m->cuales_atencion : '' }}</div>
                        <div class="completo"><span class="dato-etiqueta">Diagnóstico preliminar</span>{!! nl2br(e($txt($m?->diagnostico))) !!}</div>
                        <div><span class="dato-etiqueta">Hospitalización</span>{{ $sn($m?->hospitalizacion) }}{{ $m?->nombre_hospital ? ' — '.$m->nombre_hospital : '' }}</div>
                        <div><span class="dato-etiqueta">Trasladado en / Doctor</span>{{ $txt($m?->trasladado_en) }} / {{ $txt($m?->nombre_medico) }}</div>
                        <div class="completo"><span class="dato-etiqueta">Observaciones Generales Médicas</span>{!! nl2br(e($txt($m?->observaciones))) !!}</div>
                    </div>
                </section>
                @if ($g)
                    <section>
                        <h3>3. Guardavidas: Anexo Acuático</h3>
                        <div class="datos-impresion">
                            <div><span class="dato-etiqueta">Fecha / Hora / Turno</span>{{ $g->fecha?->format('d/m/Y') ?? '—' }} {{ $hora($g->hora) }} · {{ $txt($g->turno) }}</div>
                            <div><span class="dato-etiqueta">Lugar Exacto</span>{{ $txt($g->lugar) }}</div>
                            <div><span class="dato-etiqueta">Guardavidas / Puesto / Supervisor</span>{{ $g->nombre }} / {{ $txt($g->puesto) }} / {{ $txt($g->supervisor) }}</div>
                            <div><span class="dato-etiqueta">Alcoholizado · Descalzo · Calzado</span>{{ $sn($g->alcoholizado) }} · {{ $sn($g->descalzo) }} · {{ $txt($g->tipo_calzado) }}</div>
                            <div><span class="dato-etiqueta">Herida / Parte apreciada</span>{{ $txt($g->tipo_herida) }} / {{ $txt($g->parte_afectada) }}</div>
                            <div><span class="dato-etiqueta">Riesgos</span>{{ collect(['Acto Inseguro' => $g->acto_inseguro, 'Cond. Insegura' => $g->condicion_insegura])->filter()->keys()->join(', ') ?: '—' }}{{ $g->especifique_riesgo ? ' — '.$g->especifique_riesgo : '' }}</div>
                            <div class="completo"><span class="dato-etiqueta">Descripción de los hechos</span>{!! nl2br(e($txt($g->descripcion))) !!}</div>
                            <div><span class="dato-etiqueta">¿Acudió a servicio médico? · Material</span>{{ $sn($g->acudio_servicio_medico) }} · {{ $txt($g->material_curacion) }}</div>
                            <div><span class="dato-etiqueta">Se informa a</span>{{ $txt($g->se_informa_a) }}</div>
                        </div>
                    </section>
                @endif
                @if ($c)
                    <section>
                        <h3>4. Uso Exclusivo Recursos Humanos</h3>
                        <div class="datos-impresion">
                            <div><span class="dato-etiqueta">Días de Incapacidad</span>{{ $rh?->dias_incapacidad ?? 0 }}</div>
                            <div><span class="dato-etiqueta">Se presenta a laborar</span>{{ $rh?->fecha_presenta?->format('d/m/Y') ?? '—' }}</div>
                        </div>
                    </section>
                @endif
                <section>
                    <h3>Firmas Digitales de Cierre</h3>
                    <div class="firmas-impresion">
                        @foreach (\App\Models\AccidenteFirma::ROLES as $rol => $texto)
                            @php $f = $n->firmas->firstWhere('rol', $rol); @endphp
                            <div>
                                <div class="firma-espacio">@if ($f)<img src="{{ route('novedades.firma', [$n->id, $rol]) }}" alt="Firma: {{ $texto }}">@endif</div>
                                <div class="firma-linea">{{ $texto }}</div>
                            </div>
                        @endforeach
                    </div>
                </section>
                @break

            @case('habitacion')
                @php $d = $n->valoresVista; @endphp
                <section>
                    <h3>Detalle: Valores a la Vista</h3>
                    <div class="datos-impresion">
                        <div><span class="dato-etiqueta">Número de Habitación</span>{{ $d?->areaEspecifica?->nombre ?? $txt($d?->num_habitacion) }}</div>
                        <div><span class="dato-etiqueta">Quién Reporta</span>{{ $txt($d?->quien_reporta) }} · {{ $txt($d?->depto_reporta) }} · {{ $txt($d?->puesto_reporta) }}</div>
                        <div class="completo"><span class="dato-etiqueta">Actividad dentro de la habitación</span>{{ $txt($d?->actividad_reporta) }}</div>
                        <div><span class="dato-etiqueta">Nombre del Agente (atiende)</span>{{ $txt($d?->quien_atiende) }} · {{ $txt($d?->depto_atiende) }} · {{ $txt($d?->puesto_atiende) }}</div>
                        <div class="completo"><span class="dato-etiqueta">Otras personas en la habitación</span>{{ $n->valoresVistaPersonas->map(fn ($p) => $p->nombre.' ('.trim($p->departamento.' '.$p->puesto).') '.($p->se_retira ? 'se retira' : 'permanece'))->join('; ') ?: '—' }}</div>
                        <div class="completo"><span class="dato-etiqueta">Puertas, Ventanas y Terrazas</span>{{ $n->valoresVistaAperturas->map(fn ($a) => ucfirst($a->tipo).': '.$a->estado.($a->es_especial ? ' ('.\App\Services\Novedades\Formatos\ValoresVista::ESPECIAL_APERTURA[$a->tipo].')' : '').($a->descripcion ? ' — '.$a->descripcion : ''))->join('; ') ?: '—' }}</div>
                        <div><span class="dato-etiqueta">Estado de la Caja Fuerte</span>{{ \App\Models\ValoresVistaDetalle::CAJA_FUERTE[$d?->caja_fuerte] ?? '—' }}</div>
                        <div><span class="dato-etiqueta">Acción aplicada</span>{{ \App\Models\ValoresVistaDetalle::CAJA_ACCION[$d?->caja_accion] ?? '—' }}</div>
                        <div class="completo"><span class="dato-etiqueta">Valores dentro de la caja</span>{{ $txt($d?->valores_dentro) }}</div>
                        <div class="completo"><span class="dato-etiqueta">Valores a la vista, por zona</span>{{ $n->valoresVistaZonas->map(fn ($z) => $z->zona.': '.$z->descripcion)->join('; ') ?: '—' }}</div>
                        <div><span class="dato-etiqueta">¿El personal se retiró? · ¿Cliente llega? · ¿Fotos?</span>{{ $d?->personal_retiro === 'no' ? 'No' : 'Sí' }} · {{ $sn($d?->cliente_llega) }} · {{ $d?->tomo_fotos === 'no' ? 'No' : 'Sí' }}</div>
                        <div class="completo"><span class="dato-etiqueta">Condiciones generales, observaciones y notificaciones</span>{!! nl2br(e($txt($d?->observaciones))) !!}</div>
                    </div>
                </section>
                @break

            @case('proteccion_civil')
                @php $s = $n->siniestro; @endphp
                <section>
                    <h3>Detalle: Siniestro Protección Civil</h3>
                    <div class="datos-impresion">
                        <div><span class="dato-etiqueta">Clasificación del Evento</span>{{ \App\Models\SiniestroDetalle::TIPOS[$s?->tipo_evento] ?? '—' }}{{ $s?->descripcion_otro ? ' — '.$s->descripcion_otro : '' }}</div>
                        <div><span class="dato-etiqueta">Se controló</span>{{ $s?->controlado_en ? app(\App\Support\HoraLocal::class)->formatear($s->controlado_en) : '—' }}</div>
                        <div><span class="dato-etiqueta">Alarma · Evacuación</span>{{ $sn($s?->alarma_activada) }} · {{ $sn($s?->requiere_evacuacion) }}{{ $s?->num_evacuados !== null ? ' ('.$s->num_evacuados.' personas, '.$txt($s->punto_reunion).')' : '' }}</div>
                        <div><span class="dato-etiqueta">Lesionados</span>{{ $sn($s?->hubo_lesionados) }}{{ $s?->num_lesionados ? ' ('.$s->num_lesionados.')' : '' }}{{ $s?->accidente ? ' — Ticket de Accidente '.$s->accidente->folio() : '' }}</div>
                        <div class="completo"><span class="dato-etiqueta">Servicios de Emergencia Externos</span>{{ $n->siniestroServicios->map(fn ($x) => $x->servicio.($x->hora_llegada ? ' ('.$hora($x->hora_llegada).')' : ''))->join(', ') ?: '—' }}</div>
                        <div class="completo"><span class="dato-etiqueta">Equipos Involucrados</span>{{ $n->siniestroEquipos->map(fn ($e) => $e->identificador.' — '.($e->estado_uso === 'danado' ? 'Resultó dañado' : 'Se utilizó'))->join('; ') ?: '—' }}</div>
                        <div class="completo"><span class="dato-etiqueta">Daños Materiales</span>{{ $n->siniestroDanos->map(fn ($x) => $x->zona.($x->descripcion ? ': '.$x->descripcion : ''))->join('; ') ?: '—' }}</div>
                        <div class="completo"><span class="dato-etiqueta">Testigos</span>{{ $n->testigos->where('formato', 'proteccion_civil')->map(fn ($t) => $t->nombre.($t->departamento ? ' ('.$t->departamento.')' : ''))->join(', ') ?: '—' }}</div>
                        <div class="completo"><span class="dato-etiqueta">Causa probable</span>{!! nl2br(e($txt($s?->causa_probable))) !!}</div>
                        <div class="completo"><span class="dato-etiqueta">Acciones tomadas</span>{!! nl2br(e($txt($s?->acciones_tomadas))) !!}</div>
                    </div>
                </section>
                @break

            @case('recorrido_pc')
                <section>
                    <h3>Checklist: Inspección Preventiva de Equipos PC</h3>
                    @forelse ($n->recorridoPuntos as $p)
                        <div class="datos-impresion">
                            <div><span class="dato-etiqueta">Punto #{{ $loop->iteration }}</span>{{ $p->identificador }} · {{ \App\Services\Novedades\Formatos\RecorridoPc::CATEGORIAS[$p->categoria][0] ?? $p->categoria }}</div>
                            <div><span class="dato-etiqueta">Ubicación</span>{{ collect([$p->edificio, $p->nivel, $p->area])->filter()->join(' · ') ?: '—' }}</div>
                            <div class="completo"><span class="dato-etiqueta">Faltantes / dañados</span>{{ collect($p->criterios)->reject()->keys()->map(fn ($k) => \App\Services\Novedades\Formatos\RecorridoPc::piezas($p->categoria)[$k] ?? $k)->join(', ') ?: 'Todo en orden' }}</div>
                            <div class="completo"><span class="dato-etiqueta">Observaciones</span>{{ $txt($p->observaciones) }}</div>
                        </div>
                    @empty
                        <p class="text-muted">Sin puntos de inspección.</p>
                    @endforelse
                </section>
                @break

            @case('lost_found')
                <section>
                    <h3>Detalle: Lost &amp; Found</h3>
                    @if ($n->lostFound?->folio_externo || $n->lostFound?->enlace_externo)
                        <p class="small">Plataforma externa: {{ $txt($n->lostFound->folio_externo) }} {{ $n->lostFound->enlace_externo }}</p>
                    @endif
                    <table class="tabla-impresion">
                        <thead><tr><th>Folio</th><th>Objeto</th><th>Tipo de Valor</th><th>Marca / Color</th><th>Dónde</th><th>Bodega</th><th>Estatus</th></tr></thead>
                        <tbody>
                            @forelse ($n->articulos as $a)
                                <tr><td>{{ $a->folio }}</td><td>{{ $a->objeto }}</td><td>{{ $a->etiquetaTipo() }}</td><td>{{ trim($a->marca.' '.$a->color) ?: '—' }}</td>
                                    <td>{{ collect([$a->areaEspecifica?->nombre, $a->lugar_detalle])->filter()->join(' · ') ?: '—' }}</td><td>{{ $txt($a->ubicacion_bodega) }}</td>
                                    <td>{{ $a->etiquetaEstatus() }}@php $sem = $a->semaforo($umbrales); @endphp @if ($sem) ({{ $sem['texto'] }}, {{ $sem['dias'] }} días)@endif</td></tr>
                            @empty
                                <tr><td colspan="7">Sin artículos encontrados.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                    @if ($n->reportesPerdida->isNotEmpty())
                        <h4>Reportes de Pérdida</h4>
                        <table class="tabla-impresion">
                            <thead><tr><th>Folio</th><th>Objeto</th><th>Huésped</th><th>Contacto</th><th>Desde</th><th>Estatus</th></tr></thead>
                            <tbody>
                                @foreach ($n->reportesPerdida as $r)
                                    <tr><td>{{ $r->folio }}</td><td>{{ $r->objeto }} {{ trim($r->marca.' '.$r->color) }}</td><td>{{ $txt($r->nombre_huesped) }}</td>
                                        <td>{{ collect([$r->telefono, $r->correo])->filter()->join(' · ') ?: '—' }}</td><td>{{ $r->fecha_aproximada?->format('d/m/Y') ?? '—' }}</td>
                                        <td>{{ $r->etiquetaEstatus() }}{{ $r->articuloVinculado ? ' — '.$r->articuloVinculado->folio : '' }}</td></tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                </section>
                @break

            @case('robo')
                @php $r = $n->robo; @endphp
                <section>
                    <h3>Detalle: Robo</h3>
                    <div class="datos-impresion">
                        <div><span class="dato-etiqueta">Hora aproximada</span>{{ $hora($r?->hora_aproximada) }}</div>
                        <div><span class="dato-etiqueta">Lugar exacto</span>{{ $txt($r?->lugar_exacto) }}</div>
                        <div class="completo"><span class="dato-etiqueta">¿Qué se llevaron?</span>{!! nl2br(e($txt($r?->objetos_descripcion))) !!}</div>
                        <div><span class="dato-etiqueta">Valor estimado</span>{{ $r?->valor_estimado !== null ? '$'.number_format((float) $r->valor_estimado, 2) : '—' }}</div>
                        <div><span class="dato-etiqueta">Sospechoso</span>{{ $sn($r?->hay_sospechoso) }}</div>
                        @if ($r?->hay_sospechoso)
                            <div class="completo"><span class="dato-etiqueta">Descripción del sospechoso</span>{{ $txt($r->descripcion_sospechoso) }}</div>
                        @endif
                        <div class="completo"><span class="dato-etiqueta">Testigos</span>
                            @forelse ($n->testigos->where('formato', 'robo') as $t)
                                <div>{{ $t->nombre }}{{ $t->departamento ? ' ('.$t->departamento.')' : '' }}{{ $t->declaracion ? ': '.$t->declaracion : '' }}</div>
                            @empty
                                —
                            @endforelse
                        </div>
                        <div><span class="dato-etiqueta">Parte a la policía</span>{{ $sn($r?->parte_policia) }}{{ $r?->folio_policial ? ' — Folio '.$r->folio_policial : '' }}</div>
                        <div><span class="dato-etiqueta">Canalización</span>{{ collect(['Gerencia' => $r?->canalizado_gerencia, 'Legal' => $r?->canalizado_legal])->filter()->keys()->join(', ') ?: '—' }}</div>
                        @if ($r?->articuloVinculado)
                            <div class="completo"><span class="dato-etiqueta">Hallazgo vinculado</span>{{ $r->articuloVinculado->folio }} — {{ $r->articuloVinculado->objeto }}</div>
                        @endif
                        <div class="completo"><span class="dato-etiqueta">Observaciones de la investigación</span>{!! nl2br(e($txt($r?->observaciones))) !!}</div>
                    </div>
                </section>
                @break
        @endswitch

        <section>
            <h3>Minuto a Minuto / Log de Seguimiento</h3>
            <div class="log-impresion">
                @forelse ($n->notas as $nota)
                    <div>[@fecha($nota->created_at)] {{ $nota->autor_nombre }}: {{ $nota->texto }}</div>
                @empty
                    <div>No hay registros previos.</div>
                @endforelse
            </div>
        </section>

        <section>
            <h3>Estatus Final / Resolución</h3>
            <div class="datos-impresion">
                <div><span class="dato-etiqueta">Estatus</span>{{ \App\Models\Novedad::ESTATUS[$n->estatus][0] ?? $n->estatus }}</div>
                <div><span class="dato-etiqueta">Cierre</span>{{ $n->cerrado_en ? app(\App\Support\HoraLocal::class)->formatear($n->cerrado_en).' · '.($n->cerrador?->name ?? '—') : '—' }}</div>
                <div class="completo"><span class="dato-etiqueta">Resolución</span>{!! nl2br(e($txt($n->resolucion))) !!}</div>
            </div>
        </section>
        <footer class="pie-impresion">Impreso @fecha(now()) · {{ auth()->user()->name }}</footer>
    </article>
</main>
<script src="{{ $version('js/plataforma.js') }}"></script>
</body>
</html>
