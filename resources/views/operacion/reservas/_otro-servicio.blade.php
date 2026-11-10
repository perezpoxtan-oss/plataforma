{{--
    Renglón «+ Otro servicio» de una tarjeta de departamento: nombre y nota
    libres; se guarda como renglón con ese departamento y sin servicio del
    catálogo. En «Otros» se elige el departamento que lo atiende.
    Parámetros: $n (índice o «__N__» en la plantilla), $f (valores), $grupo.
--}}
<div class="res-otro" data-res-otro>
    @if ($grupo['id'] === null)
        <select name="otros[{{ $n }}][departamento_id]" class="campo" aria-label="Departamento que lo atiende">
            <option value="">Sin departamento</option>
            @foreach ($departamentos as $d)<option value="{{ $d->id }}" @selected((int) ($f['departamento_id'] ?? 0) === $d->id)>{{ $d->nombre }}</option>@endforeach
        </select>
    @else
        <input type="hidden" name="otros[{{ $n }}][departamento_id]" value="{{ $grupo['id'] }}">
    @endif
    <input type="text" name="otros[{{ $n }}][nombre]" class="campo" maxlength="80" value="{{ $f['nombre'] ?? '' }}" placeholder="Otro servicio" aria-label="Otro servicio para {{ $grupo['nombre'] }}">
    <input type="text" name="otros[{{ $n }}][notas]" class="campo" maxlength="255" value="{{ $f['notas'] ?? '' }}" placeholder="Nota (opcional)" aria-label="Nota del otro servicio">
    <input type="hidden" name="otros[{{ $n }}][cantidad]" value="{{ $f['cantidad'] ?? '' }}">
    <button type="button" class="btn-icono eliminar" data-res-quitar-otro title="Quitar" aria-label="Quitar este servicio"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
</div>
