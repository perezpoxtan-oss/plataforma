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
        <div>
            <label class="campo-etiqueta">Mes de ingreso
                <input type="month" name="experiencia[{{ $i }}][ingreso]" class="campo" value="{{ $f['ingreso'] ?? '' }}" placeholder="AAAA-MM">
            </label>
        </div>
        <div>
            <label class="campo-etiqueta">Mes de salida <span class="text-muted fw-normal">(vacío si sigues ahí)</span>
                <input type="month" name="experiencia[{{ $i }}][salida]" class="campo" value="{{ $f['salida'] ?? '' }}" placeholder="AAAA-MM">
            </label>
        </div>
        <div>
            <label class="campo-etiqueta">Sueldo final al mes (opcional)
                <input type="text" name="experiencia[{{ $i }}][sueldo_final]" class="campo" maxlength="12" inputmode="decimal" value="{{ isset($f['sueldo_final']) && $f['sueldo_final'] !== null && $f['sueldo_final'] !== '' ? (string) (float) $f['sueldo_final'] : '' }}" placeholder="Ej. 8500">
            </label>
        </div>
        <div>
            <label class="campo-etiqueta">Jefe inmediato
                <input type="text" name="experiencia[{{ $i }}][jefe]" class="campo" maxlength="150" value="{{ $f['jefe'] ?? '' }}" placeholder="Nombre">
            </label>
        </div>
        <div>
            <label class="campo-etiqueta">Teléfono del jefe
                <input type="tel" name="experiencia[{{ $i }}][jefe_telefono]" class="campo" maxlength="20" inputmode="numeric" value="{{ $f['jefe_telefono'] ?? '' }}" placeholder="10 dígitos">
            </label>
        </div>
        <div>
            <label class="campo-etiqueta">¿Podemos pedir referencias?
                <select name="experiencia[{{ $i }}][pedir_referencias]" class="campo">
                    <option value="">-- Elegir --</option>
                    <option value="si" @selected(($f['pedir_referencias'] ?? '') === 'si')>Sí</option>
                    <option value="no" @selected(($f['pedir_referencias'] ?? '') === 'no')>No</option>
                </select>
            </label>
        </div>
    </div>
    <div class="fila-cv-pie">
        <label class="campo-etiqueta flex-grow-1">¿Por qué saliste?
            <input type="text" name="experiencia[{{ $i }}][motivo_salida]" class="campo" maxlength="200" value="{{ $f['motivo_salida'] ?? '' }}" placeholder="Opcional">
        </label>
        @if (empty($f['ingreso']) && isset($f['anos']) && $f['anos'] !== null && $f['anos'] !== '')
            {{-- Dato anterior (años, sin fechas): se conserva --}}
            <input type="hidden" name="experiencia[{{ $i }}][anos]" value="{{ $f['anos'] }}">
        @endif
        <button type="button" class="btn-quitar-fila-cv" data-quitar-fila-cv aria-label="Quitar este empleo"><i class="bi bi-trash3" aria-hidden="true"></i></button>
    </div>
</div>
