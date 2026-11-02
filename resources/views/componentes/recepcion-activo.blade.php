{{--
    C-45 (DD-19): recepción común de lo que regresa (llave, gafete, equipo o
    credencial): OK / Dañado / No regresó. OK viene marcado: recibir en buen
    estado no agrega pasos. Va dentro del <form> de «Recibir» de cada módulo.
    Parámetros (prefijo «ra»): raId (único en la página), raNombre (campo; «resultado»),
    raNoRegreso (bool: ofrecer «No regresó»), raDanado (bool: ofrecer «Dañado»; sí por omisión).
--}}
@php
    $raNombre ??= 'resultado';
    $raNoRegreso ??= true;
    $raDanado ??= true;
@endphp
<fieldset class="recepcion-comun" aria-label="¿Cómo regresa?">
    <legend class="visually-hidden">¿Cómo regresa?</legend>
    <label class="opcion-recepcion ok"><input type="radio" name="{{ $raNombre }}" value="ok" id="{{ $raId }}_ok" checked data-por-defecto> OK</label>
    @if ($raDanado)
        <label class="opcion-recepcion danado"><input type="radio" name="{{ $raNombre }}" value="danado" id="{{ $raId }}_danado"> Dañado</label>
    @endif
    @if ($raNoRegreso)
        <label class="opcion-recepcion no-regreso"><input type="radio" name="{{ $raNombre }}" value="no_regreso" id="{{ $raId }}_no_regreso"> No regresó</label>
    @endif
</fieldset>
