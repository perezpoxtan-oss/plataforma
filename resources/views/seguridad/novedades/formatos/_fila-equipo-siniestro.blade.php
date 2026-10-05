<div class="row mb-2 align-items-end fila-dinamica" data-fila>
    <div class="col-md-5">
        <label class="campo-etiqueta" for="siniestro_equipo_{{ $i }}">Identificador del equipo <span class="text-lowercase fw-normal">(escanear o escribir)</span></label>
        <input type="text" id="siniestro_equipo_{{ $i }}" name="siniestro_equipos[{{ $i }}][identificador]" class="campo text-uppercase" maxlength="100" placeholder="Ej: EXT-01" autocapitalize="characters" value="{{ $e['identificador'] ?? '' }}">
    </div>
    <div class="col-md-5">
        <label class="campo-etiqueta" for="siniestro_equipo_estado_{{ $i }}">¿Cómo se involucró?</label>
        <select id="siniestro_equipo_estado_{{ $i }}" name="siniestro_equipos[{{ $i }}][estado_uso]" class="campo">
            <option value="utilizado" @selected(($e['estado_uso'] ?? 'utilizado') === 'utilizado')>Se utilizó durante el siniestro</option>
            <option value="danado" @selected(($e['estado_uso'] ?? '') === 'danado')>Resultó dañado</option>
        </select>
    </div>
    <div class="col-md-2"><button type="button" class="btn-quitar-fila mb-3" data-quitar-fila aria-label="Quitar equipo"><i class="bi bi-trash" aria-hidden="true"></i></button></div>
</div>
