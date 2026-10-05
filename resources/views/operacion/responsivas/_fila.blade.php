{{--
    Fila "+ Añadir Equipo" del Nuevo Resguardo: equipo DISPONIBLE (de la sede
    elegida; las demás opciones se ocultan) + modalidad Turno / Fijo + quitar.
    Variables: $indice (número o "__N__" en la plantilla), $seleccionado, $modalidad, $disponibles, $textoEquipo.
--}}
<div class="fila-equipo" data-fila-equipo>
    <select name="equipos[]" class="campo mb-0 campo-equipo-lote" required aria-label="Equipo a resguardar" data-equipo-lote>
        <option value="">-- Seleccionar Equipo Disponible --</option>
        @foreach ($disponibles as $e)
            <option value="{{ $e->id }}" data-sede="{{ $e->sede_id }}" @selected($seleccionado === (string) $e->id)>{{ $textoEquipo($e) }}</option>
        @endforeach
    </select>
    <select name="modalidades[]" class="campo mb-0 campo-modalidad-lote" aria-label="Tipo de asignación">
        @foreach (\App\Models\EquipoResponsiva::MODALIDADES as $clave => $texto)
            <option value="{{ $clave }}" @selected($modalidad === $clave)>{{ $texto }}</option>
        @endforeach
    </select>
    <button type="button" class="btn-quitar-equipo" title="Quitar este equipo" aria-label="Quitar este equipo" data-quitar-equipo><i class="bi bi-trash" aria-hidden="true"></i></button>
</div>
