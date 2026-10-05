@php
    $tiposValor = \App\Models\LostFoundArticulo::TIPOS_VALOR;
    $guardado = ! empty($r['id']);
    $estatusRp = $r['estatus'] ?? 'BUSCANDO';
    $numero = is_int($i) ? $i + 1 : '__n__';
@endphp
<div class="tarjeta-perdida fila-dinamica" data-fila data-reporte-perdida="{{ $r['id'] ?? '' }}">
    <input type="hidden" name="rp_reportes[{{ $i }}][id]" value="{{ $r['id'] ?? '' }}">
    <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
        <span class="small fw-bold text-danger"><i class="bi bi-search me-1" aria-hidden="true"></i>Reporte de Pérdida <span data-numero-fila>{{ $numero }}</span>
            <span class="badge bg-secondary-subtle text-secondary">Folio: {{ $r['folio'] ?? 'se asigna al guardar' }}</span>
        </span>
        @if ($estatusRp === 'BUSCANDO')
            <button type="button" class="btn-quitar-fila" data-quitar-fila aria-label="Quitar reporte de pérdida"><i class="bi bi-trash" aria-hidden="true"></i></button>
        @endif
    </div>
    <div class="row">
        <div class="col-md-4"><label class="campo-etiqueta" for="rp_objeto_{{ $i }}">Objeto</label><input type="text" id="rp_objeto_{{ $i }}" name="rp_reportes[{{ $i }}][objeto]" class="campo text-uppercase" maxlength="150" placeholder="Ej: Teléfono, Cartera, Prenda" value="{{ $r['objeto'] ?? '' }}" data-rp="objeto"></div>
        <div class="col-md-4"><label class="campo-etiqueta" for="rp_tipo_{{ $i }}">Tipo / Categoría de Valor</label>
            <select id="rp_tipo_{{ $i }}" name="rp_reportes[{{ $i }}][tipo_valor]" class="campo" data-rp="tipo_valor">
                @foreach ($tiposValor as $clave => $texto)
                    <option value="{{ $clave }}" @selected(($r['tipo_valor'] ?? 'OTRO') === $clave)>{{ $texto }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-2"><label class="campo-etiqueta" for="rp_marca_{{ $i }}">Marca</label><input type="text" id="rp_marca_{{ $i }}" name="rp_reportes[{{ $i }}][marca]" class="campo text-uppercase" maxlength="100" value="{{ $r['marca'] ?? '' }}" data-rp="marca"></div>
        <div class="col-6 col-md-2"><label class="campo-etiqueta" for="rp_color_{{ $i }}">Color</label><input type="text" id="rp_color_{{ $i }}" name="rp_reportes[{{ $i }}][color]" class="campo text-uppercase" maxlength="50" value="{{ $r['color'] ?? '' }}" data-rp="color"></div>
    </div>
    <div class="row">
        <div class="col-md-4"><label class="campo-etiqueta" for="rp_huesped_{{ $i }}">Nombre del huésped</label><input type="text" id="rp_huesped_{{ $i }}" name="rp_reportes[{{ $i }}][nombre_huesped]" class="campo text-uppercase" maxlength="150" value="{{ $r['nombre_huesped'] ?? '' }}"></div>
        <div class="col-md-4">
            <label class="campo-etiqueta" for="rp_area_{{ $i }}">Habitación <span class="text-lowercase fw-normal">(si la recuerda)</span></label>
            <select id="rp_area_{{ $i }}" name="rp_reportes[{{ $i }}][area_especifica_id]" class="campo" data-nov-habitaciones data-vacio="-- Opcional --">
                <option value="">-- Opcional --</option>
                @foreach ($habitaciones as $h)
                    <option value="{{ $h->id }}" data-ruta="{{ $h->ruta }}" @selected((string) ($r['area_especifica_id'] ?? '') === (string) $h->id)>{{ $h->nombre }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-4"><label class="campo-etiqueta" for="rp_fecha_{{ $i }}">¿Desde cuándo nota que no lo tiene?</label><input type="date" id="rp_fecha_{{ $i }}" name="rp_reportes[{{ $i }}][fecha_aproximada]" class="campo" value="{{ $r['fecha_aproximada'] ?? '' }}" data-rp="fecha"></div>
    </div>
    <div class="row">
        <div class="col-md-6"><label class="campo-etiqueta" for="rp_tel_{{ $i }}">Teléfono de contacto</label><input type="tel" inputmode="tel" id="rp_tel_{{ $i }}" name="rp_reportes[{{ $i }}][telefono]" class="campo" maxlength="20" value="{{ $r['telefono'] ?? '' }}"></div>
        <div class="col-md-6"><label class="campo-etiqueta" for="rp_correo_{{ $i }}">Correo de contacto</label><input type="email" id="rp_correo_{{ $i }}" name="rp_reportes[{{ $i }}][correo]" class="campo" maxlength="150" value="{{ $r['correo'] ?? '' }}"></div>
    </div>
    <label class="campo-etiqueta" for="rp_desc_{{ $i }}">Señas particulares <span class="text-lowercase fw-normal">(rayones, iniciales, contenido, etc.)</span></label>
    <textarea id="rp_desc_{{ $i }}" name="rp_reportes[{{ $i }}][descripcion]" class="campo" rows="2" maxlength="2000">{{ $r['descripcion'] ?? '' }}</textarea>
    <div class="row align-items-end">
        <div class="col-md-6">
            <span class="campo-etiqueta">Estatus</span>
            <div class="campo bg-light text-muted estatus-fijo" data-rp-estatus>{{ \App\Models\LostFoundReportePerdida::ESTATUS[$estatusRp] ?? $estatusRp }}@if (! empty($r['vinculado'])) — {{ $r['vinculado'] }}@endif</div>
        </div>
        @if ($guardado && $estatusRp === 'BUSCANDO')
            <div class="col-6 col-md-3">
                <button type="button" class="btn-ver-detalle w-100 mb-3" data-accion="novedad-coincidencias"
                        data-vincular="{{ route('novedades.perdidas.vincular', $r['id']) }}"><i class="bi bi-search me-1" aria-hidden="true"></i>Buscar Coincidencias</button>
            </div>
        @endif
        @if ($guardado && ! empty($r['area_especifica_id']))
            <div class="col-6 col-md-3">
                <a class="btn-ver-detalle w-100 mb-3" target="_blank" rel="noopener"
                   href="{{ route('novedades.ficha-hechos', ['habitacion' => $r['area_especifica_id'], 'fecha' => $r['fecha_aproximada'] ?? null, 'novedad' => $n->id, 'origen' => 'perdida', 'origen_id' => $r['id']]) }}"><i class="bi bi-clipboard-data-fill me-1" aria-hidden="true"></i>Ficha de Hechos</a>
            </div>
        @endif
    </div>
    <div class="resultados-coincidencias" data-resultados-coincidencias aria-live="polite"></div>
</div>
