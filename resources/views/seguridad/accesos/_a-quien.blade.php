{{--
    C-09 (DD-12): «¿A quién viene?» — un solo buscador con sugerencias (persona, área, vivienda,
    habitación o huésped en sitio). Reemplaza al host, al departamento, a «¿A quién visita?» y a la
    vivienda de la visita. Si es un residente, una vivienda o un huésped, la caseta llama y deja
    constancia: «Llamé y autorizó» (botón principal) o «No autorizó».
    $contexto: visita | proveedor | huesped · $giro: Terminos · $previo: closure del formulario · $previos: títulos tras un error
--}}
@php
    $residencial = $giro->esResidencial();
    $vivienda = mb_strtolower($giro->espacio());
    $placeholder = match (true) {
        $contexto === 'huesped' => 'Nombre del huésped o número de habitación',
        $residencial => 'Nombre del '.mb_strtolower($giro->residente()).', '.$vivienda.' o área',
        default => 'Nombre de la persona o del área',
    };
    $ayuda = match (true) {
        $contexto === 'huesped' => 'Llama a la habitación antes de dejarlo pasar: el botón deja constancia.',
        $residencial => 'Elige al '.mb_strtolower($giro->residente()).' o la '.$vivienda.' y llámale: el botón deja constancia de que autorizó. Si es un área de la administración, su responsable recibe el aviso.',
        default => 'Si la persona tiene cuenta, le llega el aviso para autorizar (su departamento es el suplente). Si eliges un área, autoriza su responsable.',
    };
    // Sin elegir de la lista: en residenciales es una vivienda «sin catálogo» y en hotel el nombre del huésped (también se llama)
    $llamarConTexto = $contexto === 'huesped' || $residencial;
    $id = 'ingreso_a_quien_'.$contexto;
    // Tras un error: lo elegido sigue pidiendo (o no) la llamada
    $clavePrevia = (string) $previo('a_quien');
    $llamarPrevio = str_starts_with($clavePrevia, 'e:') || str_starts_with($clavePrevia, 'h:')
        || (str_starts_with($clavePrevia, 'c:') && (bool) \App\Models\Colaborador::find((int) substr($clavePrevia, 2))?->esResidente());
@endphp
<div class="a-quien" data-a-quien="{{ $contexto }}" data-llamar-texto="{{ $llamarConTexto ? 1 : 0 }}" data-llamar="{{ $llamarPrevio ? 1 : 0 }}">
    <label class="campo-etiqueta" for="{{ $id }}">¿A quién viene? *</label>
    <div class="buscador-acceso">
        <input type="text" id="{{ $id }}" name="a_quien_texto" class="campo text-uppercase" maxlength="150" autocomplete="off"
               value="{{ $previo('a_quien_texto') ?: ($previos['a_quien'] ?? '') }}" placeholder="{{ $placeholder }}" data-a-quien-texto>
        <input type="hidden" name="a_quien" value="{{ $previo('a_quien') }}" data-a-quien-clave>
        <div class="acceso-sugerencias" data-sugerencias hidden></div>
    </div>
    <p class="campo-ayuda mt-0">{{ $ayuda }}</p>
    <div class="caja-llamada" data-caja-llamada hidden>
        <p class="mb-2"><i class="bi bi-telephone-fill me-1" aria-hidden="true"></i><span data-texto-llamada>Llama antes de dejarlo pasar.</span></p>
        <input type="hidden" name="llamada" value="autorizo" data-llamada disabled>
        <button type="submit" name="llamada" value="no_autorizo" class="btn-decision distinta" data-no-autorizo title="La persona no entra; queda la constancia de la llamada">
            <i class="bi bi-telephone-x me-1" aria-hidden="true"></i>No autorizó
        </button>
    </div>
</div>
