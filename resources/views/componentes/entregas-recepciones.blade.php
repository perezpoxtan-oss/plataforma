{{--
    C-45 (DD-19): botón de la ficha que abre «Entregas y recepciones» (historial
    único de llaves, gafetes, equipos y credenciales). Uso:
    @include('componentes.entregas-recepciones', ['erTipo' => 'llave', 'erId' => $l->id, 'erNombre' => $l->nomenclatura])
--}}
<a href="{{ route('activos.historial', [$erTipo, $erId]) }}" class="btn-icono entregas-recepciones" title="Entregas y recepciones"
   aria-label="Entregas y recepciones de {{ $erNombre }}"><i class="bi bi-arrow-left-right" aria-hidden="true"></i></a>
