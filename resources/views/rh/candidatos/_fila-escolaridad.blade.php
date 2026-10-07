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
    </div>
    <div class="fila-cv-pie">
        <label class="campo-etiqueta flex-grow-1">Carrera o especialidad
            <input type="text" name="escolaridad[{{ $i }}][titulo]" class="campo" maxlength="150" value="{{ $f['titulo'] ?? '' }}" placeholder="Opcional">
        </label>
        <label class="casilla-concluido"><input type="checkbox" name="escolaridad[{{ $i }}][concluido]" value="1" @checked(! empty($f['concluido']))> Terminado</label>
        <button type="button" class="btn-quitar-fila-cv" data-quitar-fila-cv aria-label="Quitar estos estudios"><i class="bi bi-trash3" aria-hidden="true"></i></button>
    </div>
</div>
