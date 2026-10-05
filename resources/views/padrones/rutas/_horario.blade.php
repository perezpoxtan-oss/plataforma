{{--
    Un bloque "Horario" del diálogo de ruta: nombre, horas, días y paraderos.
    $h = índice del bloque (o __H__ en la plantilla), $numero = "Horario N",
    $datos = ['id', 'nombre', 'hora_inicio', 'hora_fin', 'dias' => [...], 'paraderos' => [['nombre', 'hora'], ...]].
--}}
@php
    $dias = $datos['dias'] ?? [];
    $paraderosBloque = $datos['paraderos'] ?? [];
@endphp
<fieldset class="rutas-horario" data-horario data-indice="{{ $h }}" data-siguiente-paradero="{{ count($paraderosBloque) }}">
    <legend class="rutas-horario-cabecera">
        <span class="rutas-horario-titulo"><i class="bi bi-clock-history me-1" aria-hidden="true"></i><span data-titulo-horario>Horario {{ $numero }}</span></span>
        <button type="button" class="btn-quitar-fila" data-quitar-horario title="Quitar este horario" aria-label="Quitar este horario"><i class="bi bi-trash3" aria-hidden="true"></i></button>
    </legend>
    <input type="hidden" name="horarios[{{ $h }}][id]" value="{{ $datos['id'] ?? '' }}" data-horario-id>

    <label class="campo-etiqueta" for="{{ $prefijo }}_h{{ $h }}_nombre">Nombre del Horario <span class="text-lowercase fw-normal">(opcional)</span></label>
    <input type="text" id="{{ $prefijo }}_h{{ $h }}_nombre" name="horarios[{{ $h }}][nombre]" class="campo" maxlength="60" placeholder="Ej: Lunes a Viernes" value="{{ $datos['nombre'] ?? '' }}">

    <div class="campo-grupo">
        <div class="flex-fill">
            <label class="campo-etiqueta" for="{{ $prefijo }}_h{{ $h }}_inicio">Hora Inicio del Recorrido</label>
            <input type="time" id="{{ $prefijo }}_h{{ $h }}_inicio" name="horarios[{{ $h }}][hora_inicio]" class="campo" value="{{ $datos['hora_inicio'] ?? '' }}" required>
        </div>
        <div class="flex-fill">
            <label class="campo-etiqueta" for="{{ $prefijo }}_h{{ $h }}_fin">Hora de Llegada a Destino</label>
            <input type="time" id="{{ $prefijo }}_h{{ $h }}_fin" name="horarios[{{ $h }}][hora_fin]" class="campo" value="{{ $datos['hora_fin'] ?? '' }}" required>
        </div>
    </div>
    <p class="campo-ayuda rutas-ayuda"><i class="bi bi-moon-stars" aria-hidden="true"></i> Si la llegada es más temprano que el inicio (ej. 23:30 a 00:40), se entiende que llega al día siguiente.</p>

    <span class="campo-etiqueta">Días de Operación de este Horario</span>
    <div class="rutas-dias" role="group" aria-label="Días de operación">
        @foreach (\App\Models\Ruta::DIAS as $codigo => $dia)
            <label class="rutas-dia"><input type="checkbox" name="horarios[{{ $h }}][dias][]" value="{{ $codigo }}" @checked(in_array($codigo, $dias, true))> {{ $dia }}</label>
        @endforeach
    </div>
    <p class="campo-ayuda rutas-ayuda"><i class="bi bi-info-circle" aria-hidden="true"></i> Ninguno marcado = este horario opera todos los días.</p>

    <span class="campo-etiqueta">Paraderos de este Horario <span class="text-lowercase fw-normal">(opcional)</span></span>
    <div class="rutas-paraderos-encabezado" aria-hidden="true"><span>Paradero</span><span>Hora</span><span></span></div>
    <div data-paraderos>
        @foreach ($paraderosBloque as $p => $parada)
            @include('padrones.rutas._paradero', ['h' => $h, 'p' => $p, 'nombre' => $parada['nombre'] ?? '', 'hora' => $parada['hora'] ?? ''])
        @endforeach
    </div>
    <button type="button" class="btn-agregar-paradero" data-agregar-paradero><i class="bi bi-plus-circle me-1" aria-hidden="true"></i> Agregar paradero</button>
    <p class="campo-ayuda rutas-ayuda mt-0"><i class="bi bi-info-circle" aria-hidden="true"></i> Escribe para buscar en el catálogo de esta sede; si no existe, se crea solo al guardar.</p>
</fieldset>
