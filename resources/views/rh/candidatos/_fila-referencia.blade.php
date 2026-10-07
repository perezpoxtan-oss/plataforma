<div class="fila-cv" data-fila-cv>
    <div class="rejilla-cv">
        <div>
            <label class="campo-etiqueta">Nombre
                <input type="text" name="referencias[{{ $i }}][nombre]" class="campo" maxlength="150" value="{{ $f['nombre'] ?? '' }}" placeholder="Nombre de la persona">
            </label>
        </div>
        <div>
            <label class="campo-etiqueta">Teléfono
                <input type="tel" name="referencias[{{ $i }}][telefono]" class="campo" maxlength="20" inputmode="numeric" value="{{ $f['telefono'] ?? '' }}" placeholder="10 dígitos">
            </label>
        </div>
    </div>
    <div class="fila-cv-pie">
        <label class="campo-etiqueta flex-grow-1">¿Quién es?
            <input type="text" name="referencias[{{ $i }}][relacion]" class="campo" maxlength="80" value="{{ $f['relacion'] ?? '' }}" placeholder="Ej. Jefe anterior">
        </label>
        <button type="button" class="btn-quitar-fila-cv" data-quitar-fila-cv aria-label="Quitar esta referencia"><i class="bi bi-trash3" aria-hidden="true"></i></button>
    </div>
</div>
