{{--
    Campos de una evaluación de entrevista (RR. HH. o departamento): cada
    criterio en estrellas (radios con etiqueta: se llenan con teclado o con el
    dedo), fecha de la entrevista, resultado y comentario (obligatorio para
    Considerar y Rechazar). $tipo: rh | departamento · $criterios: list<string>
    · $prefijo: para los id · $conOld: volvió con errores.
--}}
@use('App\Models\EvaluacionCandidato')
@use('App\Services\Recepcion\AjustesRecepcion')
@php
    $hora = app(\App\Support\HoraLocal::class);
    $valorOld = fn (string $campo, $defecto = null) => $conOld ? old($campo, $defecto) : $defecto;
    $ahoraLocal = now()->setTimezone($hora->zona());
@endphp
<fieldset class="bloque-cv">
    <legend><i class="bi bi-star-half me-2" aria-hidden="true"></i>Califica de 1 a 5 estrellas</legend>
    <p class="campo-ayuda mt-0">1 = muy bajo · 3 = cumple · 5 = excelente. Toca la estrella o usa las flechas del teclado.</p>
    @foreach ($criterios as $nombre)
        @php $clave = AjustesRecepcion::claveCriterio($nombre); $marcada = (string) $valorOld('criterios.'.$clave); @endphp
        <fieldset class="criterio-estrellas">
            <legend>{{ $nombre }}</legend>
            <div class="estrellas">
                @for ($n = EvaluacionCandidato::MINIMO; $n <= EvaluacionCandidato::MAXIMO; $n++)
                    <input type="radio" id="{{ $prefijo }}_{{ $clave }}_{{ $n }}" name="criterios[{{ $clave }}]" value="{{ $n }}" required @checked($marcada === (string) $n)>
                    <label for="{{ $prefijo }}_{{ $clave }}_{{ $n }}" title="{{ $n }} de 5"><i class="bi bi-star-fill" aria-hidden="true"></i><span class="visually-hidden">{{ $n }} de 5 en {{ $nombre }}</span></label>
                @endfor
            </div>
            @error('criterios.'.$clave)<p class="texto-error-rh m-0">{{ $message }}</p>@enderror
        </fieldset>
    @endforeach
</fieldset>

<div class="rejilla-cv">
    <div>
        <label class="campo-etiqueta" for="{{ $prefijo }}_entrevista_fecha">Fecha de la entrevista</label>
        <input type="date" id="{{ $prefijo }}_entrevista_fecha" name="entrevista_fecha" class="campo" value="{{ $valorOld('entrevista_fecha', $ahoraLocal->format('Y-m-d')) }}" max="{{ $ahoraLocal->format('Y-m-d') }}">
    </div>
    <div>
        <label class="campo-etiqueta" for="{{ $prefijo }}_entrevista_hora">Hora</label>
        <input type="time" id="{{ $prefijo }}_entrevista_hora" name="entrevista_hora" class="campo" value="{{ $valorOld('entrevista_hora', $ahoraLocal->format('H:i')) }}">
    </div>
</div>
@error('entrevista_en')<p class="texto-error-rh">{{ $message }}</p>@enderror

<span class="campo-etiqueta d-block">Resultado *</span>
<div class="opciones-cv" role="radiogroup" aria-label="Resultado de la entrevista">
    @foreach (EvaluacionCandidato::RESULTADOS[$tipo] as $clave => $texto)
        <label class="opcion-cv opcion-resultado resultado-{{ $clave }}"><input type="radio" name="resultado" value="{{ $clave }}" required @checked((string) $valorOld('resultado') === $clave)><span>{{ $texto }}</span></label>
    @endforeach
</div>
@error('resultado')<p class="texto-error-rh">{{ $message }}</p>@enderror

<label class="campo-etiqueta" for="{{ $prefijo }}_comentario">Comentario <span class="text-muted fw-normal">(obligatorio para «Considerar» y «Rechazar»)</span></label>
<textarea id="{{ $prefijo }}_comentario" name="comentario" class="campo" rows="3" maxlength="1000" data-requerido-si='{"resultado":["considerar","rechazar"]}'
          placeholder="Ej. Buena actitud; le falta experiencia en turnos nocturnos">{{ $valorOld('comentario') }}</textarea>
@error('comentario')<p class="texto-error-rh">{{ $message }}</p>@enderror
