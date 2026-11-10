{{-- Una fila "paradero + hora" dentro de un horario. $h y $p son índices (o __H__ / __P__ en la plantilla). --}}
<div class="rutas-paradero-fila" data-paradero-fila>
    <div>
        <label class="visually-hidden" for="{{ $prefijo }}_h{{ $h }}_p{{ $p }}_nombre">Paradero</label>
        <input type="text" id="{{ $prefijo }}_h{{ $h }}_p{{ $p }}_nombre" name="horarios[{{ $h }}][paraderos][{{ $p }}][nombre]" class="campo mb-0 text-uppercase"
               maxlength="150" list="paraderosSede" placeholder="Buscar o escribir paradero nuevo..." value="{{ $nombre ?? '' }}" autocomplete="off">
    </div>
    <div>
        <label class="visually-hidden" for="{{ $prefijo }}_h{{ $h }}_p{{ $p }}_hora">Hora en el paradero</label>
        <input type="time" id="{{ $prefijo }}_h{{ $h }}_p{{ $p }}_hora" name="horarios[{{ $h }}][paraderos][{{ $p }}][hora]" class="campo mb-0" value="{{ $hora ?? '' }}" title="Hora en el paradero (opcional)">
    </div>
    <button type="button" class="btn-quitar-fila" data-quitar-paradero title="Quitar este paradero" aria-label="Quitar este paradero"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
</div>
