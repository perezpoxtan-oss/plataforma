@include('informes._hoja', [
    'titulo' => 'Tablero',
    'periodo' => $textoSedes.' · cifras de este momento',
    'volver' => route('tablero.index', array_filter(['sede' => $sede])),
    'contenido' => view('informes._tablero-hoja', ['fichas' => $fichas])->render(),
])
