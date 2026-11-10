{{-- Fila de un acompañante en el Registro de Ingreso. $i: índice (o "__i__" en la plantilla) · $fila: lo enviado antes (tras un error) --}}
<div class="fila-acompanante" data-fila-acompanante>
    <span class="numero-acompanante" data-numero-acompanante>{{ is_int($i) ? $i + 1 : '' }}</span>
    <input type="text" name="acompanantes[{{ $i }}][nombre]" class="campo text-uppercase" maxlength="150" value="{{ $fila['nombre'] ?? '' }}"
           placeholder="Nombre del acompañante (opcional)" aria-label="Nombre del acompañante">
    <select name="acompanantes[{{ $i }}][identificacion]" class="campo" aria-label="Identificación del acompañante">
        <option value="">Sin ID</option>
        @foreach (\App\Models\Acceso::IDENTIFICACIONES as $clave => $texto)
            <option value="{{ $clave }}" @selected(($fila['identificacion'] ?? '') === $clave)>{{ $texto }}</option>
        @endforeach
    </select>
    <select name="acompanantes[{{ $i }}][gafete_id]" class="campo" aria-label="Gafete del acompañante" data-gafete-acompanante data-valor="{{ $fila['gafete_id'] ?? '' }}">
        <option value="">-- Sin gafete --</option>
    </select>
</div>
