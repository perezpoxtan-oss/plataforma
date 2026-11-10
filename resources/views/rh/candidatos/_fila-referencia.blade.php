@php $lista = $lista ?? 'referencias'; @endphp
<div class="fila-cv" data-fila-cv>
    <div class="rejilla-cv">
        <div>
            <label class="campo-etiqueta">Nombre
                <input type="text" name="{{ $lista }}[{{ $i }}][nombre]" class="campo" maxlength="150" value="{{ $f['nombre'] ?? '' }}" placeholder="Nombre de la persona">
            </label>
        </div>
        <div>
            <label class="campo-etiqueta">Teléfono
                <input type="tel" name="{{ $lista }}[{{ $i }}][telefono]" class="campo" maxlength="20" inputmode="numeric" value="{{ $f['telefono'] ?? '' }}" placeholder="10 dígitos">
            </label>
        </div>
    </div>
    <div class="fila-cv-pie">
        <label class="campo-etiqueta flex-grow-1">{{ $lista === 'referencias' ? '¿Quién es? (relación u ocupación)' : 'Puesto o relación' }}
            <input type="text" name="{{ $lista }}[{{ $i }}][relacion]" class="campo" maxlength="80" value="{{ $f['relacion'] ?? '' }}" placeholder="{{ $lista === 'referencias' ? 'Ej. Vecina, maestra' : 'Ej. Jefe anterior' }}">
        </label>
        <label class="campo-etiqueta campo-anos">Años de conocerlo
            <input type="number" name="{{ $lista }}[{{ $i }}][anos_conocerlo]" class="campo" min="0" max="90" inputmode="numeric" value="{{ $f['anos_conocerlo'] ?? '' }}" placeholder="0">
        </label>
        <button type="button" class="btn-quitar-fila-cv" data-quitar-fila-cv aria-label="Quitar esta referencia"><i class="bi bi-trash3" aria-hidden="true"></i></button>
    </div>
</div>
