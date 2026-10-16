@extends('layouts.app')

@section('titulo', 'Reservas de áreas · '.$sede->nombre)

@section('contenido')
<div class="tema-verde">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    @if ($variasSedes)
        {{-- Ruta: Reservas de áreas (tarjetas de sedes) › Sede --}}
        <nav class="migas" aria-label="Ubicación">
            <a href="{{ route('reservas.index') }}"><i class="bi bi-calendar-check" aria-hidden="true"></i> Reservas de áreas</a>
            <i class="bi bi-chevron-right" aria-hidden="true"></i>
            <span class="actual" aria-current="page">{{ $sede->nombre }}</span>
        </nav>
    @endif

    @include('operacion.reservas._nav', ['titulo' => 'Reservas de áreas', 'descripcion' => $sede->nombre.' — elige un área para reservarla o ver su calendario.',
        'actual' => 'areas', 'buscar' => ['reservas-areas', 'Buscar área...']])

    <div class="fichas-grid" data-fichas="reservas-areas">
        @if ($puede['crear'] && $puedeReservar)
            <a href="{{ route('reservas.create') }}" class="ficha-card ficha-create text-decoration-none">
                <i class="bi bi-plus-circle-fill" aria-hidden="true"></i>
                <span class="h6 fw-bold m-0 mt-2 titulo-crear">Nueva reserva</span>
                <span class="small text-muted mt-1">Área, día, horario y para qué</span>
            </a>
        @endif

        @forelse ($fichas as $f)
            @php
                $a = $f['area'];
                $montajes = $a->montajes->pluck('nombre');
            @endphp
            <div class="ficha-card" id="area-{{ $a->id }}" data-ficha data-estado="1" data-texto="{{ mb_strtolower($a->nombre().' '.$montajes->join(' ').' '.$f['responsable']) }}">
                <div>
                    @if ($a->foto)
                        <img src="{{ route('reservas.foto', $a->id) }}" alt="Foto de {{ $a->nombre() }}" class="res-foto-ficha mb-3" loading="lazy">
                    @endif
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <h2 class="ficha-title"><i class="bi bi-door-open text-success me-2" aria-hidden="true"></i>{{ $a->nombre() }}</h2>
                        @if ($f['hoy'] > 0)
                            <span class="etiqueta-estado pendiente text-nowrap" title="Reservas de hoy">Hoy: {{ $f['hoy'] }}</span>
                        @else
                            <span class="etiqueta-estado activo text-nowrap" title="Sin reservas hoy">Libre hoy</span>
                        @endif
                    </div>
                    <ul class="list-unstyled small text-muted mt-2 pt-2 border-top m-0">
                        <li><i class="bi bi-people me-1" aria-hidden="true"></i> {{ $a->aforo_maximo ? 'Hasta '.$a->aforo_maximo.' personas' : 'Sin aforo definido' }}</li>
                        <li><i class="bi bi-grid-3x3-gap me-1" aria-hidden="true"></i> {{ $montajes->isEmpty() ? 'Sin montajes' : $montajes->join(', ') }}</li>
                        <li><i class="bi bi-cash-coin me-1" aria-hidden="true"></i> {{ $f['tarifa'] ?? 'Sin costo' }}</li>
                        <li><i class="bi bi-person-check me-1" aria-hidden="true"></i> Aprueba {{ $f['responsable'] ?? 'quien aprueba reservas en la sede' }}</li>
                        @if ($a->confirmacion_inmediata)<li><i class="bi bi-lightning-charge me-1" aria-hidden="true"></i> Confirmación inmediata</li>@endif
                    </ul>
                    <span class="ficha-dato"><i class="bi bi-calendar-event" aria-hidden="true"></i> Próxima: {{ $f['proxima'] ?? 'sin reservas' }}</span>
                </div>
                <div class="ficha-footer">
                    <span class="small text-muted">{{ $a->montaje_minutos || $a->desmontaje_minutos ? 'Montaje '.$a->montaje_minutos.' / '.$a->desmontaje_minutos.' min' : '' }}</span>
                    <div class="d-flex gap-2">
                        @if ($puede['crear'] && $puedeReservar)
                            <a href="{{ route('reservas.create', ['area' => $a->id]) }}" class="btn-icono reactivar" title="Reservar" aria-label="Reservar {{ $a->nombre() }}"><i class="bi bi-calendar-plus" aria-hidden="true"></i></a>
                        @endif
                        <a href="{{ route('reservas.calendario', ['area' => $a->id, 'sede' => $a->sede_id]) }}" class="btn-icono ver" title="Calendario del área" aria-label="Calendario de {{ $a->nombre() }}"><i class="bi bi-calendar3" aria-hidden="true"></i></a>
                        @if ($puede['configurar'])
                            <a href="{{ route('reservas.configuracion.area', $a->id) }}" class="btn-icono editar" title="Configurar" aria-label="Configurar {{ $a->nombre() }}"><i class="bi bi-sliders" aria-hidden="true"></i></a>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="tarjeta estado-vacio">
                <div class="icono"><i class="bi bi-door-closed" aria-hidden="true"></i></div>
                <p class="text-muted small m-0">Esta sede no tiene áreas reservables.</p>
            </div>
        @endforelse
    </div>
    <p class="text-muted text-center p-4" data-sin-resultados="reservas-areas" hidden><i class="bi bi-search me-2" aria-hidden="true"></i>No hay áreas que coincidan con tu búsqueda.</p>
</div>
@endsection
