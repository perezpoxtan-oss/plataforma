{{-- Reporte General (SEGCAT: frag_incidente_general.php) --}}
<div class="accordion-item">
    <h2 class="accordion-header"><button class="accordion-button" type="button" data-bs-toggle="collapse" data-bs-target="#colIncidenteGeneral" aria-expanded="true"><i class="bi bi-journal-text me-2 text-secondary" aria-hidden="true"></i>Detalle: Reporte General</button></h2>
    <div id="colIncidenteGeneral" class="accordion-collapse collapse show">
        <div class="accordion-body">
            <p class="text-muted small mb-2"><i class="bi bi-info-circle" aria-hidden="true"></i> Para cuando el guardia observa algo directamente en su área, sin que nadie se lo haya reportado antes.</p>
            <label class="campo-etiqueta" for="ig_observados">¿Quién o quiénes fueron observados?</label>
            <input type="text" id="ig_observados" name="ig_observados" class="campo text-uppercase" maxlength="255" list="novColaboradores" placeholder="Nombre(s) de la(s) persona(s) observada(s)" value="{{ $v['ig_observados'] }}">
            <div class="row">
                <div class="col-md-6"><label class="campo-etiqueta" for="ig_actividad">¿Qué actividad realizaban?</label><input type="text" id="ig_actividad" name="ig_actividad" class="campo text-uppercase" maxlength="255" value="{{ $v['ig_actividad'] }}"></div>
                <div class="col-md-6"><label class="campo-etiqueta" for="ig_motivo">¿Por qué la realizaban?</label><input type="text" id="ig_motivo" name="ig_motivo" class="campo text-uppercase" maxlength="255" value="{{ $v['ig_motivo'] }}"></div>
            </div>
            <label class="campo-etiqueta" for="ig_acciones">Acciones Inmediatas Tomadas por Seguridad</label>
            <textarea id="ig_acciones" name="ig_acciones" class="campo" rows="2" maxlength="5000" placeholder="Describa el protocolo aplicado al momento del evento...">{{ $v['ig_acciones'] }}</textarea>
        </div>
    </div>
</div>
