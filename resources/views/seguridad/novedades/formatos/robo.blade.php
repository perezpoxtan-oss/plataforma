{{-- Robo (SEGCAT: frag_robo.php) --}}
@php
    $sel = fn ($actual, $valor) => (string) $actual === (string) $valor ? 'selected' : '';
    $testigosRobo = array_values((array) $v['robo_testigos']);
@endphp
<div class="accordion-item" id="moduloRobo">
    <h2 class="accordion-header"><a class="accordion-button" role="button" data-bs-toggle="collapse" href="#colRobo" aria-expanded="true"><i class="bi bi-exclamation-octagon-fill me-2 text-danger" aria-hidden="true"></i>Detalle: Robo</a></h2>
    <div id="colRobo" class="accordion-collapse collapse show">
        <div class="accordion-body" data-robo data-coincidencias="{{ route('novedades.coincidencias') }}">
            <div class="alert alert-danger py-2 px-3 small mb-3"><i class="bi bi-shield-exclamation me-1" aria-hidden="true"></i>Este formato es para levantar una investigación, no un registro administrativo — entre más preciso el detalle, mejor.</div>

            <x-seccion clave="nov-robo-1" :abierta="true">
                <x-slot:titulo><i class="bi bi-clock-history text-primary me-2" aria-hidden="true"></i>1. Circunstancias</x-slot:titulo>
            <div class="row">
                <div class="col-md-4"><label class="campo-etiqueta" for="robo_hora_aproximada">Hora aproximada</label><input type="time" id="robo_hora_aproximada" name="robo_hora_aproximada" class="campo" value="{{ $v['robo_hora_aproximada'] }}"></div>
                <div class="col-md-8"><label class="campo-etiqueta" for="robo_lugar_exacto">Lugar exacto <span class="text-lowercase fw-normal">(si el catálogo de habitación no alcanza a precisarlo)</span></label><input type="text" id="robo_lugar_exacto" name="robo_lugar_exacto" class="campo text-uppercase" maxlength="150" placeholder="Ej. Estacionamiento nivel 2, cajón 14" value="{{ $v['robo_lugar_exacto'] }}"></div>
            </div>
            <label class="campo-etiqueta mt-2" for="robo_objetos_descripcion">¿Qué se llevaron? <span class="text-lowercase fw-normal">(descripción detallada)</span></label>
            <textarea id="robo_objetos_descripcion" name="robo_objetos_descripcion" class="campo" rows="3" maxlength="5000" placeholder="Cada objeto, con marca, color, características distintivas..." data-robo-objetos>{{ $v['robo_objetos_descripcion'] }}</textarea>
            <label class="campo-etiqueta mt-2" for="robo_valor_estimado">Valor estimado de lo robado <span class="text-lowercase fw-normal">(opcional, si se puede calcular)</span></label>
            <input type="number" step="0.01" min="0" id="robo_valor_estimado" name="robo_valor_estimado" class="campo" inputmode="decimal" placeholder="Ej. 15000.00" value="{{ $v['robo_valor_estimado'] }}">

            <div class="alert alert-secondary py-2 px-3 small mt-2">
                <i class="bi bi-search me-1" aria-hidden="true"></i>Antes de dar por hecho que es un robo, vale la pena cotejar contra lo que ya se ha encontrado — a veces resulta que solo se extravió.
                @if ($v['robo_vinculado'])
                    <div class="mt-2 text-success fw-bold"><i class="bi bi-link-45deg me-1" aria-hidden="true"></i>Vinculado con el hallazgo {{ $v['robo_vinculado'] }}.</div>
                @endif
                <div class="mt-2 d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-sm btn-outline-dark" data-accion="novedad-coincidencias-robo" data-vincular="{{ route('novedades.robo.vincular', $n->id) }}"><i class="bi bi-search me-1" aria-hidden="true"></i>Buscar Coincidencias en Lost &amp; Found</button>
                    @if ($n->area_especifica_id)
                        <a class="btn btn-sm btn-outline-dark" target="_blank" rel="noopener"
                           href="{{ route('novedades.ficha-hechos', ['habitacion' => $n->area_especifica_id, 'fecha' => $n->ocurrio_en ? $n->ocurrio_en->copy()->setTimezone($n->zonaSede())->format('Y-m-d') : null, 'novedad' => $n->id, 'origen' => 'robo', 'origen_id' => $n->id]) }}"><i class="bi bi-clipboard-data-fill me-1" aria-hidden="true"></i>Ficha de Hechos (habitación, llaves y más)</a>
                    @else
                        <span class="text-muted small align-self-center"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>{{ $textoSinHabitacion ?? 'Para la Ficha de Hechos elige arriba la habitación específica y guarda.' }}</span>
                    @endif
                </div>
                <div class="resultados-coincidencias mt-2" data-resultados-coincidencias aria-live="polite"></div>
            </div>

            </x-seccion>
            <x-seccion clave="nov-robo-2" :abierta="false">
                <x-slot:titulo><i class="bi bi-person-bounding-box text-warning me-2" aria-hidden="true"></i>2. Sospechoso</x-slot:titulo>
            <label class="campo-etiqueta d-block" for="robo_hay_sospechoso">¿Hay algún sospechoso identificado?</label>
            <select id="robo_hay_sospechoso" name="robo_hay_sospechoso" class="campo"><option value="0" {{ $sel($v['robo_hay_sospechoso'], '0') }}>No</option><option value="1" {{ $sel($v['robo_hay_sospechoso'], '1') }}>Sí</option></select>
            <div data-nov-mostrar-si='{"robo_hay_sospechoso":["1"]}' @if ((string) $v['robo_hay_sospechoso'] !== '1') hidden @endif>
                <label class="campo-etiqueta mt-2" for="robo_descripcion_sospechoso">Descripción del sospechoso</label>
                <textarea id="robo_descripcion_sospechoso" name="robo_descripcion_sospechoso" class="campo" rows="2" maxlength="5000" placeholder="Características físicas, vestimenta, si es huésped/colaborador/externo, matrícula si aplica...">{{ $v['robo_descripcion_sospechoso'] }}</textarea>
            </div>

            </x-seccion>
            <x-seccion clave="nov-robo-3" :abierta="false">
                <x-slot:titulo><i class="bi bi-people-fill text-success me-2" aria-hidden="true"></i>3. Testigos</x-slot:titulo>
            <div data-filas="robo_testigos" data-siguiente="{{ count($testigosRobo) }}">
                @foreach ($testigosRobo as $i => $t)
                    @include('seguridad.novedades.formatos._fila-testigo', ['campo' => 'robo_testigos', 'i' => $i, 't' => $t, 'declaracion' => true])
                @endforeach
            </div>
            <template data-plantilla="robo_testigos">@include('seguridad.novedades.formatos._fila-testigo', ['campo' => 'robo_testigos', 'i' => '__i__', 't' => [], 'declaracion' => true])</template>
            <button type="button" class="btn-ver-detalle mb-2" data-agregar-fila="robo_testigos"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Agregar testigo</button>

            </x-seccion>
            <x-seccion clave="nov-robo-4" :abierta="false">
                <x-slot:titulo><i class="bi bi-send-check-fill text-primary me-2" aria-hidden="true"></i>4. Canalización</x-slot:titulo>
            <div class="row">
                <div class="col-md-6">
                    <label class="campo-etiqueta d-block" for="robo_se_dio_parte_policia">¿Se dio parte a la policía?</label>
                    <select id="robo_se_dio_parte_policia" name="robo_se_dio_parte_policia" class="campo"><option value="0" {{ $sel($v['robo_se_dio_parte_policia'], '0') }}>No</option><option value="1" {{ $sel($v['robo_se_dio_parte_policia'], '1') }}>Sí</option></select>
                </div>
                <div class="col-md-6" data-nov-mostrar-si='{"robo_se_dio_parte_policia":["1"]}' @if ((string) $v['robo_se_dio_parte_policia'] !== '1') hidden @endif>
                    <label class="campo-etiqueta" for="robo_folio_policial">Folio / número de reporte policial</label>
                    <input type="text" id="robo_folio_policial" name="robo_folio_policial" class="campo text-uppercase" maxlength="100" value="{{ $v['robo_folio_policial'] }}">
                </div>
            </div>
            <div class="row mt-2 casillas-formato">
                <div class="col-md-6"><label class="d-flex align-items-center gap-2"><input type="checkbox" name="robo_canalizado_gerencia" class="casilla-grande" value="1" @checked($v['robo_canalizado_gerencia'])> Se notificó a Gerencia</label></div>
                <div class="col-md-6"><label class="d-flex align-items-center gap-2"><input type="checkbox" name="robo_canalizado_legal" class="casilla-grande" value="1" @checked($v['robo_canalizado_legal'])> Se canalizó a Legal</label></div>
            </div>
            <label class="campo-etiqueta mt-2" for="robo_observaciones_investigacion">Observaciones de la investigación</label>
            <textarea id="robo_observaciones_investigacion" name="robo_observaciones_investigacion" class="campo" rows="2" maxlength="5000" placeholder="Seguimiento, acciones tomadas, cámaras revisadas, etc.">{{ $v['robo_observaciones_investigacion'] }}</textarea>
            </x-seccion>
        </div>
    </div>
</div>
