{{--
    Ubicación de un equipo de Protección Civil: «Zona / Piso» y «Área
    Específica» (Zonas y edificios). Con miles de habitaciones estas listas
    pesan cientos de KB, así que el diálogo las pide al abrirlo (GET
    equipos_pc.ubicaciones, EquipoPcController::ubicaciones) y solo las arma en
    línea cuando el formulario regresa con errores. El diálogo elige la zona y
    el área después de cargarlas (plataforma.js, «Catálogo de equipos»).
    Parámetros: $modo (nuevo | editar), $nodos (Ubicaciones::nodos), $valor (campo => valor elegido).
--}}
@php
    $zonas = $nodos->whereIn('nivel', [\App\Models\Espacio::EDIFICIO, \App\Models\Espacio::AREA]);
    $areas = $nodos->where('nivel', \App\Models\Espacio::AREA_ESPECIFICA);
@endphp
<div class="row">
    <div class="col-md-6">
        <label class="campo-etiqueta" for="{{ $modo }}_epc_zona">Zona / Piso <span class="text-lowercase fw-normal">(opcional)</span></label>
        <select id="{{ $modo }}_epc_zona" name="zona_id" class="campo" data-epc-zona>
            <option value="" data-por-defecto>-- Elige sede primero --</option>
            @foreach ($zonas as $n)
                <option value="{{ $n['id'] }}" data-sede="{{ $n['sede_id'] }}" @selected($valor('zona_id') === (string) $n['id']) @unless ($n['activo']) data-inactivo @endunless>{{ $n['texto'] }}{{ $n['activo'] ? '' : ' (desactivada)' }}</option>
            @endforeach
        </select>
    </div>
    <div class="col-md-6">
        <label class="campo-etiqueta" for="{{ $modo }}_epc_area">Área Específica <span class="text-lowercase fw-normal">(opcional)</span></label>
        <select id="{{ $modo }}_epc_area" name="area_especifica_id" class="campo" data-epc-area>
            <option value="" data-por-defecto>-- Opcional --</option>
            @foreach ($areas as $n)
                <option value="{{ $n['id'] }}" data-sede="{{ $n['sede_id'] }}" data-ancestros="{{ implode(' ', $n['ancestros']) }}" @selected($valor('area_especifica_id') === (string) $n['id']) @unless ($n['activo']) data-inactivo @endunless>{{ $n['nombre'] }}{{ $n['activo'] ? '' : ' (desactivada)' }}</option>
            @endforeach
        </select>
    </div>
</div>
