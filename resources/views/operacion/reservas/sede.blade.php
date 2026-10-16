@extends('layouts.app')

@section('titulo', 'Reservas de áreas · '.($zona?->nombre() ?? $sede->nombre))

@section('contenido')
<div class="tema-verde">
    @include('administracion.partes.avisos')
    @include('componentes.siguiente-paso')
    @include('administracion.partes.selector-empresa')

    @if ($variasSedes || $zona)
        {{-- Ruta, como Zonas y edificios: Reservas de áreas › Sede › Zona › … --}}
        <nav class="migas migas-espacios" aria-label="Ubicación">
            <a href="{{ route('reservas.index') }}"><i class="bi bi-calendar-check" aria-hidden="true"></i> Reservas de áreas</a>
            <i class="bi bi-chevron-right" aria-hidden="true"></i>
            @if ($zona)
                <a href="{{ route('reservas.index', ['sede' => $sede->id]) }}"><small>Sede</small>{{ $sede->nombre }}</a>
                @foreach ($migas as $m)
                    <i class="bi bi-chevron-right" aria-hidden="true"></i>
                    @if ($loop->last)
                        <span class="actual" aria-current="page"><small>Zona</small>{{ $m->nombre() }}</span>
                    @else
                        <a href="{{ route('reservas.index', ['sede' => $sede->id, 'zona' => $m->id]) }}"><small>Zona</small>{{ $m->nombre() }}</a>
                    @endif
                @endforeach
            @else
                <span class="actual" aria-current="page"><small>Sede</small>{{ $sede->nombre }}</span>
            @endif
        </nav>
    @endif

    @include('operacion.reservas._nav', ['titulo' => $zona ? $zona->nombre() : 'Reservas de áreas',
        'descripcion' => $zona ? $sede->nombre.' — elige un espacio para reservarlo o ver su calendario.' : $sede->nombre.' — elige un área para reservarla o ver su calendario.',
        'icono' => $zona ? 'bi-building' : 'bi-calendar-check', 'actual' => 'areas', 'buscar' => ['reservas-areas', $zona ? 'Buscar espacio...' : 'Buscar área...']])

    <div class="fichas-grid" data-fichas="reservas-areas">
        @if ($puede['crear'] && $puedeReservar)
            <a href="{{ route('reservas.create', array_filter(['zona' => $zona?->id])) }}" class="ficha-card ficha-create text-decoration-none">
                <i class="bi bi-plus-circle-fill" aria-hidden="true"></i>
                <span class="h6 fw-bold m-0 mt-2 titulo-crear">Nueva reserva</span>
                <span class="small text-muted mt-1">Área, día, horario y para qué</span>
            </a>
        @endif
        @if ($configurarAqui)
            <button type="button" class="ficha-card ficha-create" data-abrir-dialogo="dialogoAgregarArea">
                <i class="bi bi-plus-circle-fill" aria-hidden="true"></i>
                <span class="h6 fw-bold m-0 mt-2 titulo-crear">Agregar área reservable</span>
                <span class="small text-muted mt-1">Salones, salas o habitaciones de Zonas y edificios</span>
            </button>
        @endif

        @php $grupoActual = null; @endphp
        @forelse ($fichas as $f)
            @php
                $a = $f['area'];
                $montajes = $a->montajes->pluck('nombre');
                $contenedora = $a->porInteriores() || $f['dentro'] > 0;
            @endphp
            @if ($zona && $f['grupo'] !== $grupoActual && $fichas->pluck('grupo')->unique()->count() > 1)
                @php $grupoActual = $f['grupo']; @endphp
                <h2 class="titulo-seccion-rh m-0 mt-2" style="grid-column: 1 / -1"><i class="bi bi-layers me-1" aria-hidden="true"></i>{{ $f['grupo'] ?: $zona->nombre() }}</h2>
            @endif
            <div class="ficha-card" id="area-{{ $a->id }}" data-ficha data-estado="1" data-texto="{{ mb_strtolower($a->nombre().' '.$f['grupo'].' '.$montajes->join(' ').' '.$f['responsable']) }}">
                <div>
                    @if ($a->foto)
                        <img src="{{ route('reservas.foto', $a->id) }}" alt="Foto de {{ $a->nombre() }}" class="res-foto-ficha mb-3" loading="lazy">
                    @endif
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <h2 class="h5 fw-bold m-0"><i class="bi {{ $contenedora ? 'bi-building' : 'bi-door-open' }} text-success me-2" aria-hidden="true"></i>{{ $a->nombre() }}</h2>
                        <div class="d-flex gap-1">
                            @if (! $a->porInteriores())
                                @if ($contenedora && $puede['crear'] && $puedeReservar)
                                    <a href="{{ route('reservas.create', ['area' => $a->id]) }}" class="btn-icono reactivar" title="Reservar toda la zona" aria-label="Reservar toda la zona {{ $a->nombre() }}"><i class="bi bi-calendar-plus" aria-hidden="true"></i></a>
                                @endif
                                <a href="{{ route('reservas.calendario', ['area' => $a->id, 'sede' => $a->sede_id]) }}" class="btn-icono ver" title="Calendario del área" aria-label="Calendario de {{ $a->nombre() }}"><i class="bi bi-calendar3" aria-hidden="true"></i></a>
                            @endif
                            @if ($configurarAqui)
                                @include('operacion.reservas._acciones-area', ['a' => $a, 'resumen' => $eliminar[$a->id] ?? null, 'volver' => 'sede'])
                            @endif
                        </div>
                    </div>
                    <div class="text-muted small mb-2 border-bottom pb-2 mt-1 d-flex justify-content-between align-items-center gap-2 flex-wrap">
                        <span>
                            @if ($a->porInteriores())
                                <i class="bi bi-diagram-3" aria-hidden="true"></i> Por sus espacios interiores
                            @elseif ($contenedora)
                                <i class="bi bi-diagram-3" aria-hidden="true"></i> Toda la zona o sus espacios
                            @else
                                <i class="bi bi-geo-alt" aria-hidden="true"></i> {{ $f['grupo'] ?: $sede->nombre }}
                            @endif
                        </span>
                        @if ($f['hoy'] > 0)
                            <span class="etiqueta-estado pendiente text-nowrap" title="Reservas de hoy">Hoy: {{ $f['hoy'] }}</span>
                        @else
                            <span class="etiqueta-estado activo text-nowrap" title="Sin reservas hoy">Libre hoy</span>
                        @endif
                    </div>
                    <ul class="list-unstyled small text-muted m-0">
                        @if ($contenedora)
                            <li><i class="bi bi-door-closed me-1" aria-hidden="true"></i> {{ $f['dentro'] }} {{ $f['dentro'] === 1 ? 'espacio reservable' : 'espacios reservables' }}</li>
                        @endif
                        @unless ($a->porInteriores())
                            <li><i class="bi bi-people me-1" aria-hidden="true"></i> {{ $a->aforo_maximo ? 'Hasta '.$a->aforo_maximo.' personas' : 'Sin aforo definido' }}</li>
                            <li><i class="bi bi-grid-3x3-gap me-1" aria-hidden="true"></i> {{ $montajes->isEmpty() ? 'Sin montajes' : $montajes->join(', ') }}</li>
                            <li><i class="bi bi-cash-coin me-1" aria-hidden="true"></i> {{ $f['tarifa'] ?? 'Sin costo' }}</li>
                            <li><i class="bi bi-person-check me-1" aria-hidden="true"></i> Aprueba {{ $f['responsable'] ?? 'quien aprueba reservas en la sede' }}</li>
                            @if ($a->confirmacion_inmediata)<li><i class="bi bi-lightning-charge me-1" aria-hidden="true"></i> Confirmación inmediata</li>@endif
                            @if ($a->montaje_minutos || $a->desmontaje_minutos)<li><i class="bi bi-hourglass-split me-1" aria-hidden="true"></i> Montaje {{ $a->montaje_minutos }} min · desmontaje {{ $a->desmontaje_minutos }} min</li>@endif
                        @endunless
                    </ul>
                    <span class="ficha-dato"><i class="bi bi-calendar-event" aria-hidden="true"></i> Próxima: {{ $f['proxima'] ?? 'sin reservas' }}</span>
                </div>
                @if ($contenedora)
                    <a href="{{ route('reservas.index', ['sede' => $a->sede_id, 'zona' => $a->id]) }}" class="btn-entrar"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> Entrar a sus espacios</a>
                @elseif ($puede['crear'] && $puedeReservar)
                    <a href="{{ route('reservas.create', ['area' => $a->id]) }}" class="btn-entrar" aria-label="Reservar {{ $a->nombre() }}"><i class="bi bi-calendar-plus" aria-hidden="true"></i> Reservar</a>
                @else
                    <a href="{{ route('reservas.calendario', ['area' => $a->id, 'sede' => $a->sede_id]) }}" class="btn-entrar"><i class="bi bi-calendar3" aria-hidden="true"></i> Ver calendario</a>
                @endif
            </div>
        @empty
            <div class="tarjeta estado-vacio">
                <div class="icono"><i class="bi bi-door-closed" aria-hidden="true"></i></div>
                <p class="text-muted small m-0">{{ $zona ? 'Esta zona todavía no tiene espacios reservables.' : 'Esta sede no tiene áreas reservables.' }}</p>
            </div>
        @endforelse
    </div>
    <p class="text-muted text-center p-4" data-sin-resultados="reservas-areas" hidden><i class="bi bi-search me-2" aria-hidden="true"></i>No hay áreas que coincidan con tu búsqueda.</p>

    @if ($configurarAqui)
        @include('operacion.reservas._dialogo-agregar', ['opciones' => $opciones, 'sedeId' => $sede->id])
    @endif
</div>
@endsection
