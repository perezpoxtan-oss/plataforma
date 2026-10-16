{{-- Formulario de un servicio del catálogo ($s = null para uno nuevo). Variables: $departamentos --}}
@php
    use App\Models\ReservaServicio;
    $idf = $s ? 'srv'.$s->id : 'srvnuevo';
@endphp
<form method="POST" action="{{ $s ? route('reservas.configuracion.catalogo.actualizar', ['servicios', $s->id]) : route('reservas.configuracion.catalogo.crear') }}" class="res-rejilla mt-3" autocomplete="off">
    @csrf
    @if (! $s)<input type="hidden" name="catalogo" value="servicios">@endif
    @if ($s) @method('PUT') @endif
    <div>
        <label class="campo-etiqueta" for="{{ $idf }}-nombre">Nombre <span class="text-danger" aria-hidden="true">*</span></label>
        <input type="text" id="{{ $idf }}-nombre" name="nombre" class="campo" maxlength="80" required value="{{ $s?->nombre }}" placeholder="Ej. Coffee break">
    </div>
    <div>
        <label class="campo-etiqueta" for="{{ $idf }}-categoria">Categoría</label>
        <select id="{{ $idf }}-categoria" name="categoria" class="campo">
            @foreach (ReservaServicio::CATEGORIAS as $k => $n)<option value="{{ $k }}" @selected(($s?->categoria ?? 'otro') === $k)>{{ $n }}</option>@endforeach
        </select>
    </div>
    <div>
        <label class="campo-etiqueta" for="{{ $idf }}-unidad">Se cobra / se pide</label>
        <select id="{{ $idf }}-unidad" name="unidad" class="campo">
            @foreach (ReservaServicio::UNIDADES as $k => [$n])<option value="{{ $k }}" @selected(($s?->unidad ?? 'pieza') === $k)>{{ $n }}</option>@endforeach
        </select>
    </div>
    <div>
        <label class="campo-etiqueta" for="{{ $idf }}-costo">Costo para clientes <span class="text-lowercase fw-normal">(opcional)</span></label>
        <input type="number" id="{{ $idf }}-costo" name="costo" class="campo" min="0" step="0.01" value="{{ $s?->costo }}">
    </div>
    <div>
        <label class="campo-etiqueta" for="{{ $idf }}-depto">Departamento que lo atiende</label>
        <select id="{{ $idf }}-depto" name="departamento_id" class="campo">
            <option value="">Ninguno</option>
            @foreach ($departamentos as $d)<option value="{{ $d->id }}" @selected((int) $s?->departamento_id === $d->id)>{{ $d->nombre }}</option>@endforeach
        </select>
    </div>
    <div class="d-flex flex-column justify-content-end gap-2">
        <label class="d-flex gap-2 align-items-center small"><input type="checkbox" name="requiere_aprobacion" value="1" @checked($s?->requiere_aprobacion ?? true)> El departamento lo aprueba antes de confirmar</label>
        <button type="submit" class="btn-verde"><i class="bi bi-check2 me-1" aria-hidden="true"></i>Guardar</button>
    </div>
</form>
