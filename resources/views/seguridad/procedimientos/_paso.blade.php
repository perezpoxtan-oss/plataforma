{{-- Renglón de un paso en el formulario. $i: índice (o "__i__" en la plantilla); $paso: valores anteriores. --}}
<li class="paso-editor" data-paso-fila>
    <div class="paso-editor-cabecera">
        <span class="paso-editor-numero" data-paso-numero aria-hidden="true">{{ is_int($i) ? $i + 1 : '' }}</span>
        <label class="paso-editor-critico">
            <input type="checkbox" name="pasos[{{ $i }}][critico]" value="1" @checked(! empty($paso['critico']))>
            <span><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i> Punto crítico</span>
        </label>
        <div class="paso-editor-mover">
            <button type="button" class="btn-icono" data-paso-subir title="Subir este paso" aria-label="Subir este paso"><i class="bi bi-arrow-up" aria-hidden="true"></i></button>
            <button type="button" class="btn-icono" data-paso-bajar title="Bajar este paso" aria-label="Bajar este paso"><i class="bi bi-arrow-down" aria-hidden="true"></i></button>
            <button type="button" class="btn-icono quitar" data-paso-quitar title="Quitar este paso" aria-label="Quitar este paso"><i class="bi bi-trash" aria-hidden="true"></i></button>
        </div>
    </div>
    <label class="visually-hidden" for="paso_{{ $i }}_texto">Qué hay que hacer</label>
    <textarea id="paso_{{ $i }}_texto" name="pasos[{{ $i }}][texto]" class="campo" rows="2" maxlength="1000"
              placeholder="Qué hay que hacer. Ej: Aislar el área y no dejar entrar a nadie.">{{ $paso['texto'] ?? '' }}</textarea>
    <label class="campo-etiqueta mt-1" for="paso_{{ $i }}_responsable">Responsable <span class="text-lowercase fw-normal">(opcional)</span></label>
    <input type="text" id="paso_{{ $i }}_responsable" name="pasos[{{ $i }}][responsable]" class="campo" maxlength="120"
           placeholder="Ej: Agente de caseta, Supervisor de turno" value="{{ $paso['responsable'] ?? '' }}">
</li>
