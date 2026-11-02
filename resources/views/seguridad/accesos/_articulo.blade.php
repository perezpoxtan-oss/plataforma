{{-- C-19: un renglón de «¿Trae herramienta o equipo?» ($i, $fila) --}}
<div class="fila-articulo" data-fila-articulo>
    <input type="text" name="articulos[{{ $i }}][articulo]" class="campo text-uppercase" maxlength="120" value="{{ $fila['articulo'] ?? '' }}" placeholder="Artículo (ej. taladro)" aria-label="Artículo">
    <input type="number" name="articulos[{{ $i }}][cantidad]" class="campo" min="1" max="999" inputmode="numeric" value="{{ $fila['cantidad'] ?? 1 }}" aria-label="Cantidad">
    <input type="text" name="articulos[{{ $i }}][serie]" class="campo text-uppercase" maxlength="80" value="{{ $fila['serie'] ?? '' }}" placeholder="Serie (opcional)" aria-label="Número de serie">
</div>
