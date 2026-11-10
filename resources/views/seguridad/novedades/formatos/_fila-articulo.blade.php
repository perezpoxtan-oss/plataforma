@php
    $tiposValor = \App\Models\LostFoundArticulo::TIPOS_VALOR;
    $estatusArt = $a['estatus'] ?? 'EN_RESGUARDO';
    $semaforo = $a['semaforo'] ?? null;
    $numero = is_int($i) ? $i + 1 : '__n__';
@endphp
<div class="tarjeta-articulo fila-dinamica" data-fila>
    <input type="hidden" name="lf_articulos[{{ $i }}][id]" value="{{ $a['id'] ?? '' }}">
    <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
        <span class="small fw-bold text-warning-emphasis"><i class="bi bi-bag-fill me-1" aria-hidden="true"></i>Artículo <span data-numero-fila>{{ $numero }}</span>
            @if ($semaforo)
                <span class="badge-semaforo {{ $semaforo['clase'] }}"><i class="bi bi-circle-fill" aria-hidden="true"></i> {{ $semaforo['texto'] }} ({{ $semaforo['dias'] }} días)</span>
            @endif
        </span>
        @if (empty($a['bloqueado']))
            <button type="button" class="btn-quitar-fila" data-quitar-fila aria-label="Quitar artículo"><i class="bi bi-trash" aria-hidden="true"></i></button>
        @endif
    </div>
    <div class="row">
        <div class="col-md-4"><label class="campo-etiqueta" for="lf_objeto_{{ $i }}">Objeto</label><input type="text" id="lf_objeto_{{ $i }}" name="lf_articulos[{{ $i }}][objeto]" class="campo text-uppercase" maxlength="150" placeholder="Ej: Teléfono, Cartera, Prenda" value="{{ $a['objeto'] ?? '' }}"></div>
        <div class="col-md-4"><label class="campo-etiqueta" for="lf_tipo_{{ $i }}">Tipo / Categoría de Valor</label>
            <select id="lf_tipo_{{ $i }}" name="lf_articulos[{{ $i }}][tipo_valor]" class="campo">
                @foreach ($tiposValor as $clave => $texto)
                    <option value="{{ $clave }}" @selected(($a['tipo_valor'] ?? 'OTRO') === $clave)>{{ $texto }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-4"><span class="campo-etiqueta">Folio</span><input type="text" class="campo bg-white" value="{{ $a['folio'] ?? 'Se asigna al guardar' }}" readonly aria-label="Folio"></div>
    </div>
    <div class="row">
        <div class="col-md-3"><label class="campo-etiqueta" for="lf_marca_{{ $i }}">Marca</label><input type="text" id="lf_marca_{{ $i }}" name="lf_articulos[{{ $i }}][marca]" class="campo text-uppercase" maxlength="100" value="{{ $a['marca'] ?? '' }}"></div>
        <div class="col-md-3"><label class="campo-etiqueta" for="lf_color_{{ $i }}">Color</label><input type="text" id="lf_color_{{ $i }}" name="lf_articulos[{{ $i }}][color]" class="campo text-uppercase" maxlength="50" value="{{ $a['color'] ?? '' }}"></div>
        <div class="col-md-3">
            <label class="campo-etiqueta" for="lf_area_{{ $i }}">Área Específica <span class="text-lowercase fw-normal">(Habitación, etc.)</span></label>
            <select id="lf_area_{{ $i }}" name="lf_articulos[{{ $i }}][area_especifica_id]" class="campo" data-nov-habitaciones data-vacio="-- Opcional --">
                <option value="">-- Opcional --</option>
                @foreach ($habitaciones as $h)
                    <option value="{{ $h->id }}" data-ruta="{{ $h->ruta }}" @selected((string) ($a['area_especifica_id'] ?? '') === (string) $h->id)>{{ $h->nombre }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3"><label class="campo-etiqueta" for="lf_bodega_{{ $i }}">Ubicación en bodega</label><input type="text" id="lf_bodega_{{ $i }}" name="lf_articulos[{{ $i }}][ubicacion_bodega]" class="campo text-uppercase" maxlength="100" list="listaBodegaLF" placeholder="Ej: Anaquel 3, Caja B" value="{{ $a['ubicacion_bodega'] ?? '' }}"></div>
    </div>
    <label class="campo-etiqueta" for="lf_lugar_{{ $i }}">Detalle adicional del lugar <span class="text-lowercase fw-normal">(opcional, si el catálogo no alcanza a precisarlo)</span></label>
    <input type="text" id="lf_lugar_{{ $i }}" name="lf_articulos[{{ $i }}][lugar_detalle]" class="campo text-uppercase" maxlength="150" placeholder="Ej: junto a la alberca chica" value="{{ $a['lugar_detalle'] ?? '' }}">
    <div class="row align-items-end">
        <div class="col-md-6">
            <span class="campo-etiqueta">Estatus del artículo</span>
            <div class="campo bg-light text-muted mb-1 estatus-fijo">{{ \App\Models\LostFoundArticulo::ESTATUS[$estatusArt] ?? $estatusArt }}</div>
            <p class="campo-ayuda mt-0">Se cambia solo desde "Cerrar / Entregar" — así siempre queda registrado quién y cómo.</p>
        </div>
        @if (! empty($a['bloqueado']) && $estatusArt !== 'EN_RESGUARDO')
            <div class="col-md-6"><p class="small text-success fw-bold mb-3"><i class="bi bi-check-circle-fill me-1" aria-hidden="true"></i>Cierre ya registrado</p></div>
        @endif
    </div>
</div>
