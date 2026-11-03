{{-- Asistencia (ADR-0024): encabezado común con los dos atajos (Asistencia del día · Reporte por periodo) --}}
<div class="informe-encabezado">
    <div class="encabezado-pantalla m-0">
        <div class="icono"><i class="bi bi-calendar2-check text-primary" aria-hidden="true"></i></div>
        <div>
            <h1>{{ $titulo }}</h1>
            <p>{{ $subtitulo }}</p>
            @isset($lineaFecha)
                <p class="texto-padron-de"><i class="bi bi-calendar-event" aria-hidden="true"></i> <span class="text-capitalize-first">{{ $lineaFecha }}</span> · horas de {{ $zonaTexto }}</p>
            @endisset
        </div>
    </div>
    <div class="acciones-informe">
        <nav class="enlaces-informes" aria-label="Pantallas de asistencia">
            @if ($actual !== 'dia')
                <a href="{{ route('asistencia.index', array_filter(['sede' => $sede, 'departamento' => $departamento])) }}" class="btn-cancelar boton-informe"><i class="bi bi-calendar-day me-1" aria-hidden="true"></i>Asistencia del día</a>
            @endif
            @if ($actual !== 'periodo')
                <a href="{{ route('asistencia.periodo', array_filter(['sede' => $sede, 'departamento' => $departamento])) }}" class="btn-cancelar boton-informe"><i class="bi bi-calendar-range me-1" aria-hidden="true"></i>Reporte por periodo</a>
            @endif
        </nav>
        @if ($actual === 'periodo' && $puedeExportar)
            <a href="{{ route('asistencia.exportar', array_filter(['desde' => $desde, 'hasta' => $hasta, 'sede' => $sede, 'departamento' => $departamento, 'colaborador' => $colaboradorId])) }}" class="btn-exportar boton-informe"><i class="bi bi-file-earmark-excel me-2" aria-hidden="true"></i>Exportar a Excel</a>
        @endif
    </div>
</div>
@if ($sinTurno > 0)
    <div class="alert alert-info aviso mb-3" role="status"><i class="bi bi-info-circle-fill" aria-hidden="true"></i>
        <span>{{ $sinTurno === 1 ? 'Hay 1 colaborador' : "Hay {$sinTurno} colaboradores" }} sin turno: no se les calculan retardos ni faltas.
            @if ($verColaboradores)<a href="{{ route('colaboradores.index') }}">Asígnales su turno en Colaboradores</a>.@else Pide a Recursos Humanos que les asigne su turno.@endif</span>
    </div>
@endif
