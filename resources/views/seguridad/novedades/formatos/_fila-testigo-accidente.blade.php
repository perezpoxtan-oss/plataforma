<div class="row mb-2 fila-dinamica" data-fila>
    <div class="col-md-5">
        <label class="campo-etiqueta" for="acc_testigo_{{ $i }}">Nombre Testigo <span class="text-lowercase fw-normal">(buscar o escribir)</span></label>
        <input type="text" id="acc_testigo_{{ $i }}" name="acc_testigos[{{ $i }}][nombre]" class="campo text-uppercase" maxlength="150" list="novColaboradores" placeholder="Nombre del testigo" value="{{ $t['nombre'] ?? '' }}">
    </div>
    <div class="col-md-5">
        <label class="campo-etiqueta" for="acc_testigo_depto_{{ $i }}">Departamento</label>
        <input type="text" id="acc_testigo_depto_{{ $i }}" name="acc_testigos[{{ $i }}][departamento]" class="campo text-uppercase" maxlength="100" placeholder="Si aplica" value="{{ $t['departamento'] ?? '' }}">
    </div>
    <div class="col-md-2 d-flex align-items-end"><button type="button" class="btn-quitar-fila mb-3" data-quitar-fila aria-label="Quitar testigo"><i class="bi bi-trash" aria-hidden="true"></i></button></div>
</div>
