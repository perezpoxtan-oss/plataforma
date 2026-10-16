@extends('layouts.app')

@section('titulo', 'Reservas de áreas')

@section('contenido')
<div class="tema-verde">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    @if ($sinEmpresa)
        @push('estilos')<link href="{{ asset('css/reservas.css') }}" rel="stylesheet">@endpush
        <div class="encabezado-pantalla mb-4">
            <div class="icono"><i class="bi bi-calendar-check text-success" aria-hidden="true"></i></div>
            <div><h1>Reservas de áreas</h1><p>Salones, salas y terrazas de la sede: disponibilidad, aprobaciones y servicios.</p></div>
        </div>
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-buildings" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong> para ver sus reservas.</p>
        </div>
    @else
        @include('operacion.reservas._nav', ['titulo' => 'Reservas de áreas', 'descripcion' => 'Elige un área y un horario libre; quien la aprueba y los departamentos se enteran solos.', 'actual' => 'calendario'])

        @php
            $q = fn (array $extra) => array_filter(array_merge(['vista' => $vista, 'fecha' => $fecha->toDateString(), 'sede' => $sede, 'area' => $area], $extra), fn ($v) => $v !== null && $v !== '');
        @endphp

        <form method="GET" action="{{ route('reservas.index') }}" class="res-filtros" role="search">
            <input type="hidden" name="vista" value="{{ $vista }}">
            <input type="hidden" name="fecha" value="{{ $fecha->toDateString() }}">
            <div class="res-vistas" role="group" aria-label="Vista del calendario">
                @foreach (\App\Http\Controllers\Operacion\Reservas\ReservaController::VISTAS as $clave => $nombre)
                    <a href="{{ route('reservas.index', $q(['vista' => $clave])) }}" class="{{ $vista === $clave ? 'activa' : '' }}" @if ($vista === $clave) aria-current="true" @endif>{{ $nombre }}</a>
                @endforeach
            </div>
            <div class="res-navegar">
                <a href="{{ route('reservas.index', $q(['fecha' => $navegar['anterior']])) }}" aria-label="Anterior"><i class="bi bi-chevron-left" aria-hidden="true"></i></a>
                <a href="{{ route('reservas.index', $q(['fecha' => $hoy])) }}" class="px-3">Hoy</a>
                <a href="{{ route('reservas.index', $q(['fecha' => $navegar['siguiente']])) }}" aria-label="Siguiente"><i class="bi bi-chevron-right" aria-hidden="true"></i></a>
                <span class="res-titulo-periodo">{{ $navegar['titulo'] }}</span>
            </div>
            @if ($sedes->count() > 1)
                <select name="sede" class="filtro-select" aria-label="Sede" data-enviar-al-cambiar>
                    <option value="">Todas las sedes</option>
                    @foreach ($sedes as $s)<option value="{{ $s->id }}" @selected($sede === $s->id)>{{ $s->nombre }}</option>@endforeach
                </select>
            @endif
            <select name="area" class="filtro-select" aria-label="Área" data-enviar-al-cambiar>
                <option value="">Todas las áreas</option>
                @foreach ($areas as $a)<option value="{{ $a->id }}" @selected($area === $a->id)>{{ $a->nombre() }}{{ $sedes->count() > 1 ? ' · '.$a->sede?->nombre : '' }}</option>@endforeach
            </select>
        </form>

        @if ($mostrar->isEmpty())
            <div class="tarjeta estado-vacio">
                <div class="icono"><i class="bi bi-door-closed" aria-hidden="true"></i></div>
                <p class="fw-semibold mb-1">Todavía no hay áreas que se puedan reservar.</p>
                <p class="text-muted small m-0">
                    @if ($puede['configurar'])
                        En <a href="{{ route('reservas.configuracion') }}">Configuración</a> marca como «Reservable» un salón o sala de Zonas y edificios.
                    @else
                        Pide a quien administra las reservas que marque como «Reservable» un salón o sala.
                    @endif
                </p>
            </div>
        @elseif ($vista === 'dia')
            @php $dia = reset($dias); @endphp
            <div class="res-dia-lista">
                @foreach ($mostrar as $a)
                    @php $suyos = $dia['eventos']->where('area_id', $a->id); @endphp
                    <section class="res-area-dia" aria-label="{{ $a->nombre() }}">
                        <div class="res-tarjeta-fila mb-2">
                            <h2 class="h6 fw-bold m-0"><i class="bi bi-door-open me-1 text-success" aria-hidden="true"></i>{{ $a->nombre() }} <span class="res-meta fw-normal">· {{ $a->sede?->nombre }}@if ($a->aforo_maximo) · hasta {{ $a->aforo_maximo }} personas @endif</span></h2>
                            @if (in_array($a->id, $puedeReservar, true) && $dia['fecha']->toDateString() >= $hoy)
                                <a href="{{ route('reservas.create', ['area' => $a->id, 'fecha' => $dia['fecha']->toDateString()]) }}" class="res-boton"><i class="bi bi-calendar-plus" aria-hidden="true"></i>Reservar aquí</a>
                            @endif
                        </div>
                        @forelse ($suyos as $e)
                            @include('operacion.reservas._evento', ['e' => $e])
                        @empty
                            <p class="res-meta m-0"><i class="bi bi-check-circle text-success" aria-hidden="true"></i> Libre todo el día.</p>
                        @endforelse
                    </section>
                @endforeach
            </div>
        @else
            <div class="{{ $vista === 'mes' ? 'res-mes' : 'res-semana' }}">
                @if ($vista === 'mes')
                    @foreach (['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'] as $d)<div class="res-mes-cabecera" aria-hidden="true">{{ $d }}</div>@endforeach
                @endif
                @foreach ($dias as $clave => $dia)
                    @php
                        $otroMes = $vista === 'mes' && $dia['fecha']->month !== $fecha->month;
                        $reservable = $clave >= $hoy && count($puedeReservar) > 0;
                    @endphp
                    <div class="res-dia {{ $clave === $hoy ? 'hoy' : '' }} {{ $otroMes ? 'otro-mes' : '' }}">
                        <div class="res-dia-titulo">
                            <a href="{{ route('reservas.index', $q(['vista' => 'dia', 'fecha' => $clave])) }}" class="text-reset text-decoration-none" aria-label="Ver el {{ \App\Http\Controllers\Operacion\Reservas\ReservaController::diaTexto($dia['fecha']) }} {{ $dia['fecha']->format('d/m') }}">
                                @if ($vista === 'mes'){{ $dia['fecha']->day }}@else{{ \App\Http\Controllers\Operacion\Reservas\ReservaController::diaTexto($dia['fecha']) }} {{ $dia['fecha']->format('d/m') }}@endif
                            </a>
                            @if ($reservable && $vista !== 'mes')
                                <a href="{{ route('reservas.create', array_filter(['area' => $area ?? (count($puedeReservar) === 1 ? $puedeReservar[0] : null), 'fecha' => $clave])) }}" class="res-reservar-aqui" aria-label="Reservar el {{ $dia['fecha']->format('d/m') }}"><i class="bi bi-plus-circle" aria-hidden="true"></i> Reservar</a>
                            @endif
                        </div>
                        @foreach ($dia['eventos'] as $e)
                            @include('operacion.reservas._evento', ['e' => $e, 'conArea' => $area === null])
                        @endforeach
                    </div>
                @endforeach
            </div>
        @endif
        @if ($mostrar->isNotEmpty())
            <div class="res-leyenda" aria-label="Colores del calendario">
                <span style="--res-color:#15803d">Confirmada</span>
                <span style="--res-color:#d97706">Por aprobar o con cambio pedido</span>
                <span style="--res-color:#94a3b8">Ocupada (reserva de otra persona)</span>
                <span>El horario ocupado incluye el montaje y desmontaje del área.</span>
            </div>
        @endif
    @endif
</div>
@endsection
