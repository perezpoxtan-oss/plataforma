{{-- Sedes dentro del alcance de la persona (varias a la vez); ninguna marcada = todas --}}
@if ($sedes->count() > 1)
    <fieldset class="filtro-sedes-informe">
        <legend class="campo-etiqueta">Sedes</legend>
        @foreach ($sedes as $s)
            <label class="filtro-sede-opcion"><input type="checkbox" name="sedes[]" value="{{ $s->id }}" @checked(in_array($s->id, $seleccion, true))> {{ $s->nombre }}</label>
        @endforeach
        <span class="campo-ayuda d-block mt-1">Sin marcar ninguna se incluyen todas tus sedes.</span>
    </fieldset>
@endif
