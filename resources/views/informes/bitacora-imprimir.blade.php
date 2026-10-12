@include('informes._hoja', [
    'titulo' => 'Bitácora general del día',
    'periodo' => mb_strtoupper(mb_substr($fechaLegible, 0, 1)).mb_substr($fechaLegible, 1).' · '.$textoSedes.($tipo ? ' · '.\App\Services\Informes\BitacoraDelDia::TIPOS[$tipo][0] : '').' · horas de '.$zonaTexto,
    'volver' => route('bitacora_dia.index', array_filter(['fecha' => $fecha->format('Y-m-d'), 'sede' => $sede, 'tipo' => $tipo])),
    'contenido' => view('informes._bitacora-linea', compact('eventos', 'conteos', 'sedes', 'sede') + ['imprimir' => true])->render()
        .'<div class="firmas-turno"><div><span></span>Entrega el turno (nombre y firma)</div><div><span></span>Recibe el turno (nombre y firma)</div></div>',
])
