{{-- Renglón de "Artículos que Salen". $i: índice (o "__i__" en la plantilla); $a: valores anteriores. --}}
<div class="articulo-fila" data-articulo-fila>
    <input type="hidden" name="articulos[{{ $i }}][equipo_id]" value="{{ $a['equipo_id'] ?? '' }}" data-articulo="equipo_id">
    <button type="button" class="btn-quitar-articulo" data-quitar-articulo title="Quitar artículo" aria-label="Quitar artículo"><i class="bi bi-trash" aria-hidden="true"></i></button>
    <div class="row">
        <div class="col-4 col-md-2">
            <label class="campo-etiqueta" for="art_{{ $i }}_cantidad">Cantidad</label>
            <input type="number" id="art_{{ $i }}_cantidad" name="articulos[{{ $i }}][cantidad]" class="campo" min="1" max="9999" inputmode="numeric"
                   value="{{ $a['cantidad'] ?? 1 }}" required data-articulo="cantidad">
        </div>
        <div class="col-8 col-md-4">
            <label class="campo-etiqueta" for="art_{{ $i }}_equipo">Equipo</label>
            <input type="text" id="art_{{ $i }}_equipo" name="articulos[{{ $i }}][equipo]" class="campo" maxlength="150" placeholder="Ej: Laptop, Radio..."
                   value="{{ $a['equipo'] ?? '' }}" required data-articulo="equipo">
        </div>
        <div class="col-6 col-md-3">
            <label class="campo-etiqueta" for="art_{{ $i }}_marca">Marca</label>
            <input type="text" id="art_{{ $i }}_marca" name="articulos[{{ $i }}][marca]" class="campo" maxlength="100" value="{{ $a['marca'] ?? '' }}" data-articulo="marca">
        </div>
        <div class="col-6 col-md-3">
            <label class="campo-etiqueta" for="art_{{ $i }}_modelo">Modelo</label>
            <input type="text" id="art_{{ $i }}_modelo" name="articulos[{{ $i }}][modelo]" class="campo" maxlength="100" value="{{ $a['modelo'] ?? '' }}" data-articulo="modelo">
        </div>
    </div>
    <div class="row">
        <div class="col-md-4">
            <label class="campo-etiqueta" for="art_{{ $i }}_serie">Número de Serie</label>
            <input type="text" id="art_{{ $i }}_serie" name="articulos[{{ $i }}][serie]" class="campo" maxlength="100" value="{{ $a['serie'] ?? '' }}" data-articulo="serie">
        </div>
        <div class="col-md-8">
            <label class="campo-etiqueta" for="art_{{ $i }}_descripcion">Descripción</label>
            <input type="text" id="art_{{ $i }}_descripcion" name="articulos[{{ $i }}][descripcion]" class="campo" maxlength="500" placeholder="Estado, características..."
                   value="{{ $a['descripcion'] ?? '' }}" data-articulo="descripcion">
        </div>
    </div>
</div>
