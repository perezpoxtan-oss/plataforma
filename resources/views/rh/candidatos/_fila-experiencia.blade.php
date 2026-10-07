<div class="fila-cv" data-fila-cv>
    <div class="rejilla-cv">
        <div>
            <label class="campo-etiqueta">Empresa
                <input type="text" name="experiencia[{{ $i }}][empresa]" class="campo" maxlength="150" value="{{ $f['empresa'] ?? '' }}" placeholder="¿Dónde trabajaste?">
            </label>
        </div>
        <div>
            <label class="campo-etiqueta">Puesto
                <input type="text" name="experiencia[{{ $i }}][puesto]" class="campo" maxlength="150" value="{{ $f['puesto'] ?? '' }}" placeholder="¿Qué hacías?">
            </label>
        </div>
    </div>
    <div class="fila-cv-pie">
        <label class="campo-etiqueta campo-anos">Años
            <input type="number" name="experiencia[{{ $i }}][anos]" class="campo" min="0" max="60" inputmode="numeric" value="{{ $f['anos'] ?? '' }}" placeholder="0">
        </label>
        <label class="campo-etiqueta flex-grow-1">¿Por qué saliste?
            <input type="text" name="experiencia[{{ $i }}][motivo_salida]" class="campo" maxlength="200" value="{{ $f['motivo_salida'] ?? '' }}" placeholder="Opcional">
        </label>
        <button type="button" class="btn-quitar-fila-cv" data-quitar-fila-cv aria-label="Quitar este trabajo"><i class="bi bi-trash3" aria-hidden="true"></i></button>
    </div>
</div>
