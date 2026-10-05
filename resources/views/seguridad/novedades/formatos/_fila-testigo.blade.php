{{-- Testigo de Siniestro PC o de Robo (con declaración) --}}
<div class="{{ $declaracion ? 'border rounded p-2 mb-2 bg-light' : 'row mb-2 align-items-end' }} fila-dinamica" data-fila>
    @if ($declaracion)<div class="row">@endif
    <div class="col-md-5">
        <label class="campo-etiqueta" for="{{ $campo }}_{{ $i }}">Nombre <span class="text-lowercase fw-normal">(buscar o escribir)</span></label>
        <input type="text" id="{{ $campo }}_{{ $i }}" name="{{ $campo }}[{{ $i }}][nombre]" class="campo text-uppercase" maxlength="150" list="novColaboradores" placeholder="Nombre del testigo" value="{{ $t['nombre'] ?? '' }}">
    </div>
    <div class="col-md-5"><label class="campo-etiqueta" for="{{ $campo }}_depto_{{ $i }}">{{ $declaracion ? 'Departamento / Procedencia' : 'Departamento' }}</label><input type="text" id="{{ $campo }}_depto_{{ $i }}" name="{{ $campo }}[{{ $i }}][departamento]" class="campo text-uppercase" maxlength="100" placeholder="{{ $declaracion ? 'Colaborador, huésped, externo...' : 'Si aplica' }}" value="{{ $t['departamento'] ?? '' }}"></div>
    <div class="col-md-2 d-flex align-items-end"><button type="button" class="btn-quitar-fila mb-3" data-quitar-fila aria-label="Quitar testigo"><i class="bi bi-trash" aria-hidden="true"></i></button></div>
    @if ($declaracion)
        </div>
        <label class="campo-etiqueta" for="{{ $campo }}_decl_{{ $i }}">Declaración</label>
        <textarea id="{{ $campo }}_decl_{{ $i }}" name="{{ $campo }}[{{ $i }}][declaracion]" class="campo" rows="2" maxlength="5000" placeholder="Lo que dice haber visto u oído...">{{ $t['declaracion'] ?? '' }}</textarea>
    @endif
</div>
