{{-- Siniestro Protección Civil (SEGCAT: PROTECCION_CIVIL, frag_proteccion_civil.php) --}}
@php
    $sel = fn ($actual, $valor) => (string) $actual === (string) $valor ? 'selected' : '';
    $equipos = array_values((array) $v['siniestro_equipos']);
    $danos = array_values((array) $v['siniestro_danos']);
    $testigosPc = array_values((array) $v['siniestro_testigos']);
    $accidenteLigado = $n->siniestro?->accidente;
@endphp
<div class="accordion-item" id="moduloProteccionCivil">
    <h2 class="accordion-header"><a class="accordion-button" role="button" data-bs-toggle="collapse" href="#colProteccion" aria-expanded="true"><i class="bi bi-cone-striped me-2 text-danger" aria-hidden="true"></i>Detalle: Siniestro Protección Civil</a></h2>
    <div id="colProteccion" class="accordion-collapse collapse show">
        <div class="accordion-body">
            <h6 class="titulo-seccion-formato"><i class="bi bi-info-square text-primary me-2" aria-hidden="true"></i>1. Identificación del Siniestro</h6>
            <div class="row">
                <div class="col-md-6">
                    <label class="campo-etiqueta" for="pc_tipo_evento">Clasificación del Evento</label>
                    <select id="pc_tipo_evento" name="pc_tipo_evento" class="campo fw-bold border-danger">
                        @foreach (\App\Models\SiniestroDetalle::TIPOS as $clave => $texto)
                            <option value="{{ $clave }}" {{ $sel($v['pc_tipo_evento'], $clave) }}>{{ $texto }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="campo-etiqueta" for="pc_fecha_control">Fecha y Hora en que se controló</label>
                    <input type="datetime-local" id="pc_fecha_control" name="pc_fecha_control" class="campo" value="{{ $v['pc_fecha_control'] }}">
                </div>
            </div>
            <div data-nov-mostrar-si='{"pc_tipo_evento":["OTROS"]}' @if ($v['pc_tipo_evento'] !== 'OTROS') hidden @endif>
                <label class="campo-etiqueta" for="pc_descripcion_otro">Describe el tipo de evento</label>
                <input type="text" id="pc_descripcion_otro" name="pc_descripcion_otro" class="campo text-uppercase" maxlength="150" value="{{ $v['pc_descripcion_otro'] }}">
            </div>
            <div class="row">
                <div class="col-md-6"><label class="campo-etiqueta" for="pc_alarma">¿Se activó la alarma de Protección Civil?</label>
                    <select id="pc_alarma" name="pc_alarma" class="campo"><option value="0" {{ $sel($v['pc_alarma'], '0') }}>No</option><option value="1" {{ $sel($v['pc_alarma'], '1') }}>Sí</option></select>
                </div>
                <div class="col-md-6"><label class="campo-etiqueta" for="pc_evacuacion">¿Requiere / requirió evacuación?</label>
                    <select id="pc_evacuacion" name="pc_evacuacion" class="campo">
                        <option value="0" {{ $sel($v['pc_evacuacion'], '0') }}>No, situación controlada</option>
                        <option value="1" {{ $sel($v['pc_evacuacion'], '1') }}>Sí, evacuación parcial o total</option>
                    </select>
                </div>
            </div>
            <div class="row" data-nov-mostrar-si='{"pc_evacuacion":["1"]}' @if ((string) $v['pc_evacuacion'] !== '1') hidden @endif>
                <div class="col-md-6"><label class="campo-etiqueta" for="pc_num_evacuados">Personas evacuadas (aprox.)</label><input type="number" id="pc_num_evacuados" name="pc_num_evacuados" class="campo" min="0" value="{{ $v['pc_num_evacuados'] }}"></div>
                <div class="col-md-6"><label class="campo-etiqueta" for="pc_punto_reunion">Punto de reunión utilizado</label><input type="text" id="pc_punto_reunion" name="pc_punto_reunion" class="campo text-uppercase" maxlength="150" value="{{ $v['pc_punto_reunion'] }}"></div>
            </div>

            <h6 class="titulo-seccion-formato mt-3"><i class="bi bi-telephone-fill text-success me-2" aria-hidden="true"></i>2. Servicios de Emergencia Externos</h6>
            <p class="text-muted small mb-1">Marca los que se llamaron y, si ya llegaron, su hora de llegada.</p>
            <div class="row servicios-externos">
                @foreach (array_keys(\App\Models\SiniestroDetalle::SERVICIOS) as $i => $servicio)
                    @php $s = $v['pc_servicios'][$i] ?? ['activo' => false, 'hora' => null]; $marcado = ! empty($s['activo']); @endphp
                    <div class="col-md-3 col-6 mb-2" data-nov-servicio>
                        <label class="d-flex align-items-center gap-2 casilla-servicio"><input type="checkbox" name="pc_servicios[{{ $i }}][activo]" value="1" class="casilla-grande" @checked($marcado) data-nov-marca-hora> {{ \App\Models\SiniestroDetalle::SERVICIOS[$servicio] }}</label>
                        <div data-nov-hora-servicio @unless ($marcado) hidden @endunless>
                            <label class="campo-etiqueta mt-1" for="pc_servicio_hora_{{ $i }}">Hora de llegada — {{ $servicio }}</label>
                            <input type="time" id="pc_servicio_hora_{{ $i }}" name="pc_servicios[{{ $i }}][hora]" class="campo" value="{{ $s['hora'] }}">
                        </div>
                    </div>
                @endforeach
            </div>

            <h6 class="titulo-seccion-formato mt-3"><i class="bi bi-bandaid-fill text-danger me-2" aria-hidden="true"></i>3. Lesionados</h6>
            <div class="row">
                <div class="col-md-6"><label class="campo-etiqueta" for="pc_hubo_lesionados">¿Hubo lesionados?</label>
                    <select id="pc_hubo_lesionados" name="pc_hubo_lesionados" class="campo"><option value="0" {{ $sel($v['pc_hubo_lesionados'], '0') }}>No</option><option value="1" {{ $sel($v['pc_hubo_lesionados'], '1') }}>Sí</option></select>
                </div>
                <div class="col-md-6" data-nov-mostrar-si='{"pc_hubo_lesionados":["1"]}' @if ((string) $v['pc_hubo_lesionados'] !== '1') hidden @endif>
                    <label class="campo-etiqueta" for="pc_num_lesionados">¿Cuántos?</label>
                    <input type="number" id="pc_num_lesionados" name="pc_num_lesionados" class="campo" min="1" value="{{ $v['pc_num_lesionados'] }}">
                </div>
            </div>
            @if ($accidenteLigado)
                <div class="alert alert-info py-2 px-3 small"><i class="bi bi-link-45deg me-1" aria-hidden="true"></i>Ya se abrió el ticket de Accidente <a href="{{ route('novedades.index', ['abrir' => $accidenteLigado->id]) }}">{{ $accidenteLigado->folio() }}</a> para documentar a los lesionados.</div>
            @else
                <div class="alert alert-warning py-2 px-3 small" data-nov-mostrar-si='{"pc_hubo_lesionados":["1"]}' @if ((string) $v['pc_hubo_lesionados'] !== '1') hidden @endif><i class="bi bi-link-45deg me-1" aria-hidden="true"></i>Al guardar, se abrirá automáticamente un ticket de Accidente/Lesión para documentar a cada persona lesionada.</div>
            @endif

            <h6 class="titulo-seccion-formato mt-3"><i class="bi bi-shield-fill-check text-success me-2" aria-hidden="true"></i>4. Equipos de Protección Civil Involucrados</h6>
            <p class="text-muted small mb-1">Equipos que se usaron durante el siniestro (extintor, hidrante...) o que resultaron dañados.</p>
            <div data-filas="siniestro_equipos" data-siguiente="{{ count($equipos) }}">
                @foreach ($equipos as $i => $e)
                    @include('seguridad.novedades.formatos._fila-equipo-siniestro', ['i' => $i, 'e' => $e])
                @endforeach
            </div>
            <template data-plantilla="siniestro_equipos">@include('seguridad.novedades.formatos._fila-equipo-siniestro', ['i' => '__i__', 'e' => []])</template>
            <button type="button" class="btn-ver-detalle mb-2" data-agregar-fila="siniestro_equipos"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Agregar equipo</button>

            <h6 class="titulo-seccion-formato mt-3"><i class="bi bi-exclamation-diamond-fill text-warning me-2" aria-hidden="true"></i>5. Daños Materiales, por Zona</h6>
            <div data-filas="siniestro_danos" data-siguiente="{{ count($danos) }}">
                @foreach ($danos as $i => $d)
                    @include('seguridad.novedades.formatos._fila-dano', ['i' => $i, 'd' => $d])
                @endforeach
            </div>
            <template data-plantilla="siniestro_danos">@include('seguridad.novedades.formatos._fila-dano', ['i' => '__i__', 'd' => []])</template>
            <button type="button" class="btn-ver-detalle mb-2" data-agregar-fila="siniestro_danos"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Agregar zona con daño</button>

            <h6 class="titulo-seccion-formato mt-3"><i class="bi bi-people-fill text-primary me-2" aria-hidden="true"></i>6. Testigos</h6>
            <div data-filas="siniestro_testigos" data-siguiente="{{ count($testigosPc) }}">
                @foreach ($testigosPc as $i => $t)
                    @include('seguridad.novedades.formatos._fila-testigo', ['campo' => 'siniestro_testigos', 'i' => $i, 't' => $t, 'declaracion' => false])
                @endforeach
            </div>
            <template data-plantilla="siniestro_testigos">@include('seguridad.novedades.formatos._fila-testigo', ['campo' => 'siniestro_testigos', 'i' => '__i__', 't' => [], 'declaracion' => false])</template>
            <button type="button" class="btn-ver-detalle mb-2" data-agregar-fila="siniestro_testigos"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Agregar testigo</button>

            <h6 class="titulo-seccion-formato mt-3"><i class="bi bi-search text-dark me-2" aria-hidden="true"></i>7. Causa y Acciones</h6>
            <label class="campo-etiqueta" for="pc_causa_probable">Causa probable</label>
            <textarea id="pc_causa_probable" name="pc_causa_probable" class="campo" rows="2" maxlength="5000" placeholder="Si ya se conoce o se sospecha...">{{ $v['pc_causa_probable'] }}</textarea>
            <label class="campo-etiqueta mt-2" for="pc_acciones_tomadas">Acciones tomadas / medidas correctivas</label>
            <textarea id="pc_acciones_tomadas" name="pc_acciones_tomadas" class="campo" rows="2" maxlength="5000" placeholder="Qué se hizo para controlar la situación y qué se hará después...">{{ $v['pc_acciones_tomadas'] }}</textarea>
        </div>
    </div>
</div>
