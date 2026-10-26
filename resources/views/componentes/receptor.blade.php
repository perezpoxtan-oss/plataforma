{{--
    C-16 (DD-08): ¿a quién se entrega o a quién se carga? Colaborador (con el
    lector de siempre), persona del Padrón de personas (incluido el personal
    de una empresa externa) o empresa externa. Lo usan Préstamo de llaves,
    Responsivas y el voucher de las bajas (Llaves, Gafetes y Equipos).

    Envía:
      - colaborador_id (como siempre) cuando es un colaborador;
      - {prefijo}_tipo = persona | proveedor y {prefijo}_id cuando es externo.

    Parámetros:
      id        prefijo único de la página
      prefijo   'receptor' (préstamos y responsivas) o 'responsable' (vouchers)
      etiqueta  texto de la etiqueta
      requerido bool (el colaborador lo marca el lector)
      valor     ['tipo' => …, 'id' => …, 'titulo' => …] para volver a mostrarlo (o null)
      ayuda     texto bajo el lector del colaborador (opcional)
    plataforma.js («Receptor general») cambia entre los tres y busca en el padrón.
--}}
@php
    $prefijo = $prefijo ?? 'receptor';
    $valor = $valor ?? null;
    $tipoInicial = $valor['tipo'] ?? 'colaborador';
    $externo = $tipoInicial !== 'colaborador';
@endphp
<div class="receptor" data-receptor data-url="{{ route('receptores.buscar') }}">
    <span class="campo-etiqueta d-block">{{ $etiqueta ?? '¿A quién se le entrega?' }}</span>
    <div class="receptor-tipos" role="radiogroup" aria-label="{{ $etiqueta ?? '¿A quién se le entrega?' }}">
        @foreach (\App\Support\Receptor::TIPOS as $clave => $texto)
            <label class="receptor-tipo">
                <input type="radio" name="_{{ $id }}_tipo" value="{{ $clave }}" data-receptor-opcion @checked($tipoInicial === $clave) @if ($clave === 'colaborador') data-por-defecto @endif>
                <span>{{ $texto }}</span>
            </label>
        @endforeach
    </div>
    <input type="hidden" name="{{ $prefijo }}_tipo" value="{{ $tipoInicial }}" data-receptor-tipo>
    <input type="hidden" name="{{ $prefijo }}_id" value="{{ $externo ? ($valor['id'] ?? '') : '' }}" data-receptor-id>

    <div data-receptor-panel="colaborador" @if ($externo) hidden @endif>
        @include('componentes.lector', ['id' => $id.'_colaborador', 'tipos' => 'colaborador', 'nombre' => 'colaborador_id',
            'requerido' => ($requerido ?? false) && ! $externo,
            'valor' => ! $externo ? ($valor['id'] ?? null) : null, 'elegido' => ! $externo ? ($valor['titulo'] ?? null) : null,
            'ayuda' => $ayuda ?? 'Escanea su gafete o escribe su número de nómina o su nombre.'])
    </div>
    <div data-receptor-panel="externo" @unless ($externo) hidden @endunless>
        <div class="lector-elegido" data-receptor-elegido @unless ($externo && ! empty($valor['titulo'])) hidden @endunless>
            <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
            <span data-receptor-titulo>{{ $externo ? ($valor['titulo'] ?? '') : '' }}</span>
            <button type="button" class="lector-cambiar" data-receptor-limpiar>Cambiar</button>
        </div>
        <input type="search" class="campo" maxlength="100" autocomplete="off" id="{{ $id }}_buscar" aria-label="Buscar en el padrón"
               placeholder="Escribe al menos 2 letras del nombre..." data-receptor-buscar @if ($externo && ! empty($valor['titulo'])) hidden @endif>
        <div class="lista-unir" data-receptor-resultados aria-live="polite"></div>
        <p class="campo-ayuda" data-receptor-ayuda-persona>Busca a la persona en el Padrón de personas (por ejemplo, el técnico de un proveedor). Si no aparece, regístrala primero en caseta o en el padrón.</p>
        <p class="campo-ayuda" data-receptor-ayuda-proveedor hidden>Busca la empresa externa (proveedor o contratista) que responde.</p>
    </div>
</div>
