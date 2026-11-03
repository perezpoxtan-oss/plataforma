{{-- Filtros por sede y departamento (solo los de tu alcance) --}}
@if ($sedes->count() > 1)
    <label class="filtro-informe-campo"><span class="campo-etiqueta">Sede</span>
        <select name="sede" class="campo mb-0">
            <option value="">Todas mis sedes</option>
            @foreach ($sedes as $s)
                <option value="{{ $s->id }}" @selected($sede === $s->id)>{{ $s->nombre }}</option>
            @endforeach
        </select>
    </label>
@endif
@if ($departamentos->count() > 1)
    <label class="filtro-informe-campo"><span class="campo-etiqueta">Departamento</span>
        <select name="departamento" class="campo mb-0">
            <option value="">Todos</option>
            @foreach ($departamentos as $dp)
                <option value="{{ $dp->id }}" @selected($departamento === $dp->id)>{{ $dp->nombre }}</option>
            @endforeach
        </select>
    </label>
@endif
