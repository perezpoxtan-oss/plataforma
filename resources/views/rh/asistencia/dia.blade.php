{{--
    Recursos Humanos → Asistencia → Asistencia del día (ADR-0024). Quién
    llegó, quién falta y los retardos de un día, con filtros por sede y
    departamento según el alcance. Se calcula de las entradas y salidas de
    la Bitácora de accesos; la caseta no hace nada distinto.
--}}
@extends('layouts.app')

@section('titulo', 'Asistencia del día')

@section('contenido')
<div class="pantalla-informes pantalla-asistencia">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    @if ($sinEmpresa)
        <div class="encabezado-pantalla mb-4">
            <div class="icono"><i class="bi bi-calendar2-check text-primary" aria-hidden="true"></i></div>
            <div><h1>Asistencia del día</h1><p>Quién llegó, quién falta y los retardos.</p></div>
        </div>
        @include('informes._sin-empresa')
    @else
        @include('rh.asistencia._encabezado', ['actual' => 'dia', 'titulo' => 'Asistencia del día',
            'subtitulo' => 'Quién llegó, quién falta y los retardos, según el turno de cada colaborador. Las entradas y salidas son las de la Bitácora de accesos.',
            'lineaFecha' => $fechaLegible.($sede ? ' · '.$sedes->firstWhere('id', $sede)?->nombre : ''), 'desde' => null, 'hasta' => null, 'colaboradorId' => null])

        <form method="GET" action="{{ route('asistencia.index') }}" data-autoenviar class="filtro-informe tarjeta" role="search" aria-label="Día, sede y departamento">
            <div class="filtro-informe-fila">
                <label class="filtro-informe-campo"><span class="campo-etiqueta">Día</span>
                    <input type="date" name="fecha" class="campo mb-0" value="{{ $fecha }}" max="{{ $hoy }}">
                </label>
                @include('rh.asistencia._filtros')
                @if ($ver)<input type="hidden" name="ver" value="{{ $ver }}">@endif
                @if ($fecha !== $hoy)
                    <a href="{{ route('asistencia.index', array_filter(['sede' => $sede, 'departamento' => $departamento])) }}" class="btn-cancelar boton-informe align-self-end">Hoy</a>
                @endif
                <noscript><button type="submit" class="btn-filtrar">Ver</button></noscript>
            </div>
        </form>

        @php $base = array_filter(['fecha' => $fecha !== $hoy ? $fecha : null, 'sede' => $sede, 'departamento' => $departamento]); @endphp
        <nav class="pastillas-credenciales" aria-label="Mostrar">
            <a href="{{ route('asistencia.index', $base) }}" class="{{ $ver === null ? 'activa' : '' }}" @if ($ver === null) aria-current="page" @endif>Todos ({{ $total }})</a>
            <a href="{{ route('asistencia.index', $base + ['ver' => 'faltan']) }}" class="{{ $ver === 'faltan' ? 'activa' : '' }}" @if ($ver === 'faltan') aria-current="page" @endif>Faltan ({{ $cuentas['faltan'] }})</a>
            <a href="{{ route('asistencia.index', $base + ['ver' => 'retardos']) }}" class="{{ $ver === 'retardos' ? 'activa' : '' }}" @if ($ver === 'retardos') aria-current="page" @endif>Retardos ({{ $cuentas['retardos'] }})</a>
            <a href="{{ route('asistencia.index', $base + ['ver' => 'llegaron']) }}" class="{{ $ver === 'llegaron' ? 'activa' : '' }}" @if ($ver === 'llegaron') aria-current="page" @endif>Llegaron ({{ $cuentas['llegaron'] }})</a>
        </nav>
        <p class="small text-muted">Por llegar: {{ $cuentas['por_llegar'] }} · Descanso: {{ $cuentas['descanso'] }} · Tolerancia de retardo: {{ $ajustes['tolerancia_minutos'] }} min.</p>

        <div class="tarjeta p-3">
            @if ($filas->isEmpty())
                <p class="text-muted small m-0">{{ $total === 0 ? 'No hay colaboradores activos en tu alcance con estos filtros.' : 'Nadie en este grupo.' }}</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm tabla-informe tabla-asistencia">
                        <thead><tr><th scope="col">Colaborador</th><th scope="col">Turno</th><th scope="col">Entrada</th><th scope="col">Salida</th><th scope="col">Estado</th><th scope="col"><span class="visually-hidden">Acciones</span></th></tr></thead>
                        <tbody>
                            @foreach ($filas as $f)
                                @php $c = $f['colaborador']; $dia = $f['dia']; @endphp
                                <tr>
                                    <td><span class="fw-semibold">{{ $c->nombreCompleto() }}</span>
                                        <div class="small text-muted">Núm. {{ $c->num_empleado ?: '—' }}{{ $c->departamento ? ' · '.$c->departamento->nombre : '' }}{{ $c->sede ? ' · '.$c->sede->nombre : '' }}</div></td>
                                    <td>{{ $dia['turno'] ?? 'Sin turno' }}@if ($dia['horario'])<div class="small text-muted">{{ $dia['horario'] }}{{ $dia['cruza'] ? ' (termina al día siguiente)' : '' }}</div>@endif</td>
                                    <td>{{ $dia['entrada']?->format('H:i') ?? '—' }}</td>
                                    <td>{{ $dia['sin_salida'] ? 'En sitio' : ($dia['salida'] ? ($dia['salida']->format('Y-m-d') !== $dia['fecha'] ? $dia['salida']->format('d/m H:i') : $dia['salida']->format('H:i')) : '—') }}</td>
                                    <td>@include('rh.asistencia._estado', ['dia' => $dia])</td>
                                    <td class="text-end">@include('rh.asistencia._boton-justificar', ['colaborador' => $c, 'dia' => $dia])
                                        <a href="{{ route('asistencia.periodo', array_filter(['colaborador' => $c->id, 'sede' => $sede, 'departamento' => $departamento])) }}" class="btn-icono" title="Reporte por periodo" aria-label="Reporte por periodo de {{ $c->nombreCompleto() }}"><i class="bi bi-calendar-range" aria-hidden="true"></i></a></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        @include('rh.asistencia._dialogo-justificar')

        {{-- Regla 7: lo que casi no se cambia, en «Avanzado» --}}
        @if ($configura)
            <details class="tarjeta p-3 mt-3 avanzado-asistencia">
                <summary class="fw-semibold">Avanzado · Ajustes de asistencia</summary>
                <form method="POST" action="{{ route('asistencia.ajustes') }}" class="mt-3">
                    @csrf
                    @method('PUT')
                    <label class="campo-etiqueta" for="asistencia_tolerancia">Tolerancia de retardo (minutos)</label>
                    <input type="number" id="asistencia_tolerancia" name="tolerancia_minutos" class="campo" min="0" max="{{ \App\Services\Asistencia\AjustesAsistencia::TOLERANCIA_MAXIMA }}" value="{{ old('tolerancia_minutos', $ajustes['tolerancia_minutos']) }}" required>
                    <p class="small text-muted"><span class="valor-original">Valor original: {{ \App\Services\Asistencia\AjustesAsistencia::TOLERANCIA }} minutos.</span> También cuenta para la salida anticipada.</p>
                    <input type="hidden" name="aviso_faltas" value="0">
                    <label class="fila-check"><input type="checkbox" name="aviso_faltas" value="1" @checked(old('aviso_faltas', $ajustes['aviso_faltas']))>
                        Avisar al jefe del departamento cuando alguien no llegue (campana y Mis pendientes, con reloj y suplente)</label>
                    <p class="small text-muted"><span class="valor-original">Valor original: apagado.</span> Los días que se trabaja cada turno se eligen en Turnos.</p>
                    <div class="dialogo-acciones"><button type="submit" class="btn-filtrar">Guardar</button></div>
                </form>
            </details>
        @endif
    @endif
</div>
@endsection
