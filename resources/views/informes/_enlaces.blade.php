{{-- Atajos entre los informes que la persona puede abrir (ADR-0017) --}}
@php
    $informes = [
        'tablero' => ['dashboard.ver', 'tablero.index', 'bi-speedometer2', 'Tablero'],
        'bitacora' => ['bitacora_dia.ver', 'bitacora_dia.index', 'bi-calendar-day', 'Bitácora del día'],
        'tendencias' => ['tendencias.ver', 'tendencias.index', 'bi-graph-up', 'Tendencias'],
        'ejecutivo' => ['informe_ejecutivo.ver', 'informe_ejecutivo.index', 'bi-file-earmark-bar-graph', 'Informe ejecutivo'],
    ];
@endphp
<nav class="enlaces-informes" aria-label="Otros informes">
    @foreach ($informes as $clave => [$permiso, $ruta, $icono, $texto])
        @if ($clave !== $actual && auth()->user()->can($permiso))
            <a href="{{ route($ruta) }}" class="btn-cancelar boton-informe"><i class="bi {{ $icono }} me-1" aria-hidden="true"></i>{{ $texto }}</a>
        @endif
    @endforeach
</nav>
