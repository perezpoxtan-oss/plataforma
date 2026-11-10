<div class="row mb-2 align-items-end fila-dinamica" data-fila>
    <input type="hidden" name="hab_valores[{{ $i }}][zona]" value="{{ $zv['zona'] ?? 'Otro' }}">
    <div class="col-md-2"><span class="campo-etiqueta">Zona</span><input type="text" class="campo bg-light" value="{{ $zv['zona'] ?? 'Otro' }}" readonly aria-label="Zona"></div>
    <div class="col-md-9"><label class="campo-etiqueta" for="hab_valor_desc_{{ $i }}">Descripción de los valores</label><input type="text" id="hab_valor_desc_{{ $i }}" name="hab_valores[{{ $i }}][descripcion]" class="campo border-warning" maxlength="2000" placeholder="Ej. Reloj y cartera sobre el buró" value="{{ $zv['descripcion'] ?? '' }}"></div>
    <div class="col-md-1"><button type="button" class="btn-quitar-fila mb-3" data-quitar-fila aria-label="Quitar zona"><i class="bi bi-trash" aria-hidden="true"></i></button></div>
</div>
