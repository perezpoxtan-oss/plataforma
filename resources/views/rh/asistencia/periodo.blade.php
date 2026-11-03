{{--
    Recursos Humanos → Asistencia → Reporte por periodo (ADR-0024). Totales
    por colaborador (días programados, asistencias, faltas, retardos, salidas
    anticipadas, horas) y el detalle día por día de quien se elija.
    «Exportar a Excel» entrega el detalle para la nómina externa.
--}}
@extends('layouts.app')

@section('titulo', 'Reporte de asistencia')

@section('contenido')
<div class="pantalla-informes pantalla-asistencia">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    @include('rh.asistencia._encabezado', ['actual' => 'periodo', 'titulo' => 'Reporte por periodo',
        'subtitulo' => 'Asistencia de cada colaborador en el periodo, para revisarla o llevarla a la nómina. La plataforma no calcula la nómina.',
        'lineaFecha' => 'Del '.$periodoLegible])

    <form method="GET" action="{{ route('asistencia.periodo') }}" data-autoenviar class="filtro-informe tarjeta" role="search" aria-label="Periodo, sede y departamento">
        <div class="filtro-informe-fila">
            <label class="filtro-informe-campo"><span class="campo-etiqueta">Desde</span>
                <input type="date" name="desde" class="campo mb-0" value="{{ $desde }}" max="{{ $hoy }}">
            </label>
            <label class="filtro-informe-campo"><span class="campo-etiqueta">Hasta</span>
                <input type="date" name="hasta" class="campo mb-0" value="{{ $hasta }}" max="{{ $hoy }}">
            </label>
            @include('rh.asistencia._filtros')
            @if ($colaboradorId)<input type="hidden" name="colaborador" value="{{ $colaboradorId }}">@endif
            <noscript><button type="submit" class="btn-filtrar">Ver</button></noscript>
        </div>
        <p class="small text-muted m-0 mt-2">Hasta {{ \App\Http\Controllers\RecursosHumanos\AsistenciaController::MAX_DIAS }} días por reporte. De fábrica, la quincena en curso.</p>
    </form>

    @php $filtrosBase = array_filter(['desde' => $desde, 'hasta' => $hasta, 'sede' => $sede, 'departamento' => $departamento]); @endphp

    @if ($detalle)
        @php $c = $detalle['colaborador']; $r = $detalle['resumen']; @endphp
        <div class="tarjeta p-3 mb-3" id="detalle">
            <div class="d-flex flex-wrap justify-content-between gap-2 align-items-start">
                <div>
                    <h2 class="h5 fw-bold m-0">{{ $c->nombreCompleto() }}</h2>
                    <div class="small text-muted">Núm. {{ $c->num_empleado ?: '—' }}{{ $c->departamento ? ' · '.$c->departamento->nombre : '' }}{{ $c->turno ? ' · '.$c->turno->nombre.' ('.$c->turno->inicio().' a '.$c->turno->fin().', '.mb_strtolower($c->turno->textoDias()).')' : ' · sin turno' }}</div>
                </div>
                <a href="{{ route('asistencia.periodo', $filtrosBase) }}" class="btn-cancelar boton-informe"><i class="bi bi-people me-1" aria-hidden="true"></i>Ver a todos</a>
            </div>
            <p class="small mt-2 mb-2">Asistencias: <strong>{{ $r['asistencias'] }}</strong> de {{ $r['programados'] }} · Faltas: <strong>{{ $r['faltas'] }}</strong>{{ $r['faltas_justificadas'] ? ' (+'.$r['faltas_justificadas'].' justificadas)' : '' }} · Retardos: <strong>{{ $r['retardos'] }}</strong>{{ $r['retardos_justificados'] ? ' (+'.$r['retardos_justificados'].' justificados)' : '' }} · Salidas anticipadas: <strong>{{ $r['anticipadas'] }}</strong> · Horas trabajadas: <strong>{{ \App\Services\Asistencia\CalculoAsistencia::horas($r['trabajados']) }}</strong></p>
            <div class="table-responsive">
                <table class="table table-sm tabla-informe tabla-asistencia">
                    <thead><tr><th scope="col">Día</th><th scope="col">Turno</th><th scope="col">Entrada</th><th scope="col">Salida</th><th scope="col">Horas</th><th scope="col">Estado</th><th scope="col"><span class="visually-hidden">Acciones</span></th></tr></thead>
                    <tbody>
                        @foreach ($detalle['dias'] as $dia)
                            @continue($dia['estado'] === 'antes_de_alta')
                            <tr>
                                <td class="text-nowrap">{{ \App\Models\Turno::DIAS_CORTOS[$dia['dia']] }} {{ \Carbon\CarbonImmutable::parse($dia['fecha'])->format('d/m') }}</td>
                                <td>{{ $dia['horario'] ?? '—' }}</td>
                                <td>{{ $dia['entrada']?->format('H:i') ?? '—' }}</td>
                                <td>{{ $dia['sin_salida'] ? 'Sin salida' : ($dia['salida'] ? ($dia['salida']->format('Y-m-d') !== $dia['fecha'] ? $dia['salida']->format('d/m H:i') : $dia['salida']->format('H:i')) : '—') }}</td>
                                <td>{{ $dia['trabajados'] > 0 ? \App\Services\Asistencia\CalculoAsistencia::horas($dia['trabajados']) : '—' }}</td>
                                <td>@include('rh.asistencia._estado', ['dia' => $dia])</td>
                                <td><div class="acciones-asistencia">@include('rh.asistencia._boton-justificar', ['colaborador' => $c, 'dia' => $dia])</div></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @include('rh.asistencia._dialogo-justificar')
    @endif

    <div class="tarjeta p-3">
        @if ($resumenes->isEmpty())
            <p class="text-muted small m-0">No hay colaboradores en tu alcance con estos filtros.</p>
        @else
            <div class="table-responsive">
                <table class="table table-sm tabla-informe tabla-asistencia">
                    <thead><tr><th scope="col">Colaborador</th><th scope="col">Asistencias</th><th scope="col">Faltas</th><th scope="col">Retardos</th><th scope="col">Salidas anticipadas</th><th scope="col">Horas</th></tr></thead>
                    <tbody>
                        @foreach ($resumenes as $fila)
                            @php $c = $fila['colaborador']; $r = $fila['resumen']; @endphp
                            <tr @if ($colaboradorId === $c->id) class="table-active" @endif>
                                <td><a href="{{ route('asistencia.periodo', $filtrosBase + ['colaborador' => $c->id]) }}#detalle" class="fw-semibold">{{ $c->nombreCompleto() }}</a>
                                    <div class="small text-muted">Núm. {{ $c->num_empleado ?: '—' }}{{ $c->departamento ? ' · '.$c->departamento->nombre : '' }}{{ $c->turno ? '' : ' · sin turno' }}{{ $c->activo ? '' : ' · de baja' }}</div></td>
                                <td>{{ $r['asistencias'] }} / {{ $r['programados'] }}</td>
                                <td>{{ $r['faltas'] }}{{ $r['faltas_justificadas'] ? ' (+'.$r['faltas_justificadas'].' just.)' : '' }}</td>
                                <td>{{ $r['retardos'] }}{{ $r['retardos_justificados'] ? ' (+'.$r['retardos_justificados'].' just.)' : '' }}</td>
                                <td>{{ $r['anticipadas'] }}</td>
                                <td>{{ \App\Services\Asistencia\CalculoAsistencia::horas($r['trabajados']) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </div>
</div>
@endsection
