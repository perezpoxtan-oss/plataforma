@include('informes._hoja', [
    'titulo' => 'Tendencias',
    'periodo' => $periodo->texto().' contra '.$periodo->anterior()->texto().' · '.$textoSedes.' · horas de '.\App\Support\ZonasHorarias::etiqueta($periodo->zona),
    'volver' => route('tendencias.index', array_filter([
        'periodo' => $periodo->tipo,
        'desde' => $periodo->tipo === 'personalizado' ? $periodo->desde->format('Y-m-d') : null,
        'hasta' => $periodo->tipo === 'personalizado' ? $periodo->hasta->format('Y-m-d') : null,
        'sedes' => $seleccion ?: null,
        'seccion' => $solo,
    ])),
    'contenido' => view('informes._tendencias-secciones', [
        'secciones' => $secciones, 'imprimir' => true, 'puedeExportar' => false, 'filtrosUrl' => [],
    ])->render(),
])
