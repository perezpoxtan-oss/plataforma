<div class="fila-cv" data-fila-cv>
    <div class="rejilla-cv">
        <div>
            <label class="campo-etiqueta">Nivel de estudios
                <select name="escolaridad[{{ $i }}][nivel]" class="campo">
                    <option value="">-- Elegir --</option>
                    @foreach (\App\Models\Candidato::ESCOLARIDAD as $clave => $texto)
                        <option value="{{ $clave }}" @selected(($f['nivel'] ?? '') === $clave)>{{ $texto }}</option>
                    @endforeach
                </select>
            </label>
        </div>
        <div>
            <label class="campo-etiqueta">Escuela
                <input type="text" name="escolaridad[{{ $i }}][institucion]" class="campo" maxlength="150" value="{{ $f['institucion'] ?? '' }}" placeholder="Nombre de la escuela">
            </label>
        </div>
        <div>
            <label class="campo-etiqueta">Periodo
                <input type="text" name="escolaridad[{{ $i }}][periodo]" class="campo" maxlength="40" value="{{ $f['periodo'] ?? '' }}" placeholder="Ej. 2015 a 2018">
            </label>
        </div>
        <div>
            <label class="campo-etiqueta">Documento obtenido
                <select name="escolaridad[{{ $i }}][documento]" class="campo">
                    <option value="">-- Elegir --</option>
                    @foreach (\App\Models\Candidato::DOCUMENTOS_ESTUDIO as $clave => $texto)
                        <option value="{{ $clave }}" @selected(($f['documento'] ?? (array_key_exists('concluido', $f) && empty($f['concluido']) ? 'trunco' : '')) === $clave)>{{ $texto }}</option>
                    @endforeach
                </select>
            </label>
        </div>
    </div>
    <div class="fila-cv-pie">
        <label class="campo-etiqueta flex-grow-1">Carrera o especialidad
            <input type="text" name="escolaridad[{{ $i }}][titulo]" class="campo" maxlength="150" value="{{ $f['titulo'] ?? '' }}" placeholder="Opcional">
        </label>
        <button type="button" class="btn-quitar-fila-cv" data-quitar-fila-cv aria-label="Quitar estos estudios"><i class="bi bi-trash3" aria-hidden="true"></i></button>
    </div>
</div>
