{{-- Lost & Found (SEGCAT: LOST_FOUND, frag_lost_found.php) --}}
@php
    $articulos = array_values((array) $v['lf_articulos']);
    $reportes = array_values((array) $v['rp_reportes']);
    $edificioLf = $n->area ? ($n->area->nivel === \App\Models\Espacio::AREA ? $n->area->padre?->nombre : $n->area->nombre) : null;
    $pisoLf = $n->area?->nivel === \App\Models\Espacio::AREA ? $n->area->nombre : null;
@endphp
<div class="accordion-item" id="moduloLostFound">
    <h2 class="accordion-header"><a class="accordion-button" role="button" data-bs-toggle="collapse" href="#colLostFound" aria-expanded="true"><i class="bi bi-bag-fill me-2 text-warning" aria-hidden="true"></i>Detalle: Lost &amp; Found</a></h2>
    <div id="colLostFound" class="accordion-collapse collapse show">
        <div class="accordion-body" data-lost-found data-coincidencias="{{ route('novedades.coincidencias') }}">
            <h6 class="titulo-seccion-formato"><i class="bi bi-link-45deg text-primary me-2" aria-hidden="true"></i>1. Plataforma Externa <span class="text-muted text-lowercase fw-normal">(opcional)</span></h6>
            <div class="row">
                <div class="col-md-6">
                    <label class="campo-etiqueta" for="lf_folio_externo">Folio en plataforma externa</label>
                    <input type="text" id="lf_folio_externo" name="lf_folio_externo" class="campo" maxlength="100" placeholder="Si ya usan otro sistema para esto" value="{{ $v['lf_folio_externo'] }}">
                </div>
                <div class="col-md-6">
                    <label class="campo-etiqueta" for="lf_enlace_externo">Enlace directo al artículo</label>
                    <input type="url" id="lf_enlace_externo" name="lf_enlace_externo" class="campo" maxlength="255" placeholder="https://..." value="{{ $v['lf_enlace_externo'] }}">
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2 mb-2">
                {{-- Seguridad: solo enlaces http(s); al regresar con errores no se pinta un javascript: --}}
                @if (is_string($v['lf_enlace_externo']) && preg_match('#^https?://#i', $v['lf_enlace_externo']))
                    <a href="{{ $v['lf_enlace_externo'] }}" target="_blank" rel="noopener noreferrer" class="btn-ver-detalle"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Abrir en la otra plataforma</a>
                @endif
                @if ($expediente['puedeImprimir'] && $n->categoria === 'lost_found')
                    <a href="{{ route('novedades.acuse', $n->id) }}" target="_blank" rel="noopener" class="btn-ver-detalle"><i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i>Imprimir Acuse de Recibo</a>
                @endif
            </div>

            <h6 class="titulo-seccion-formato mt-3"><i class="bi bi-geo-alt-fill text-danger me-2" aria-hidden="true"></i>2. Ubicación</h6>
            @if ($n->area)
                <div class="alert alert-success py-2 px-3 small mb-2"><i class="bi bi-geo-alt-fill me-1" aria-hidden="true"></i>Edificio: <strong>{{ $edificioLf ?? '—' }}</strong>@if ($pisoLf) · Piso: <strong>{{ $pisoLf }}</strong>@endif — heredado del Área General del ticket. Si lo cambias arriba, guarda para actualizar las habitaciones.</div>
            @else
                <div class="alert alert-warning py-2 px-3 small mb-2"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Este ticket todavía no tiene Área General (edificio y piso) elegida arriba — sin eso, no hay Área Específica que sugerir por artículo.</div>
            @endif

            <h6 class="titulo-seccion-formato mt-3"><i class="bi bi-bag-fill text-warning me-2" aria-hidden="true"></i>3. Artículos Encontrados</h6>
            <div data-filas="lf_articulos" data-siguiente="{{ count($articulos) }}">
                @foreach ($articulos as $i => $a)
                    @include('seguridad.novedades.formatos._fila-articulo', ['i' => $i, 'a' => $a])
                @endforeach
            </div>
            <template data-plantilla="lf_articulos">@include('seguridad.novedades.formatos._fila-articulo', ['i' => '__i__', 'a' => []])</template>
            <button type="button" class="btn-ver-detalle mb-2" data-agregar-fila="lf_articulos"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Agregar otro artículo</button>
            <datalist id="listaBodegaLF">
                @foreach ($expediente['sugerenciasBodega'] as $b)
                    <option value="{{ $b }}">
                @endforeach
            </datalist>

            <h6 class="titulo-seccion-formato mt-3"><i class="bi bi-search text-danger me-2" aria-hidden="true"></i>4. Reportes de Pérdida <span class="text-muted text-lowercase fw-normal">(lo que un huésped dice que le falta)</span></h6>
            <p class="text-muted small mb-1">Ya guardado el reporte, «Buscar Coincidencias» busca entre lo ya encontrado artículos parecidos por tipo, marca/color y fecha — sin que tengas que buscarlo a mano.</p>
            <div data-filas="rp_reportes" data-siguiente="{{ count($reportes) }}">
                @foreach ($reportes as $i => $r)
                    @include('seguridad.novedades.formatos._fila-reporte-perdida', ['i' => $i, 'r' => $r])
                @endforeach
            </div>
            <template data-plantilla="rp_reportes">@include('seguridad.novedades.formatos._fila-reporte-perdida', ['i' => '__i__', 'r' => []])</template>
            <button type="button" class="btn-ver-detalle mb-2" data-agregar-fila="rp_reportes"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Agregar reporte de pérdida</button>
        </div>
    </div>
</div>
