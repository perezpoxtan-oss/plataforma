{{--
    «Tipo de montaje» (catálogo Montajes con el aforo de cada uno en el área).
    Va con los datos del evento o, si en Ajustes hay «Departamento que hace el
    montaje», dentro de la tarjeta de ese departamento ($enTarjeta).
    Las opciones las pinta public/js/reservas.js según el área elegida.
--}}
<div @class(['res-montaje-tarjeta' => $enTarjeta ?? false])>
    <label class="campo-etiqueta" for="montaje_id">{{ ($enTarjeta ?? false) ? 'Tipo de montaje' : 'Montaje' }}</label>
    <select id="montaje_id" name="montaje_id" class="campo" data-res-montaje data-valor="{{ old('montaje_id', $valores['montaje_id'] ?? '') }}">
        <option value="">Sin montaje especial</option>
    </select>
</div>
