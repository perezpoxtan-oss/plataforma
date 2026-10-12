@include('informes._hoja', [
    'titulo' => 'Informe ejecutivo',
    'periodo' => $textoSedes.' · Robo y pases: últimos 90 días · Transporte y autorizaciones: últimos 30 días',
    'volver' => route('informe_ejecutivo.index', array_filter(['sedes' => $seleccion ?: null])),
    'contenido' => $bloques === []
        ? '<p class="text-muted">Tu rol no puede consultar ninguno de los módulos que forman este informe.</p>'
        : view('informes._ejecutivo-bloques', ['bloques' => $bloques, 'imprimir' => true])->render(),
])
