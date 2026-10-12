@extends('layouts.app')

@section('titulo', 'Inicio')

@section('contenido')
<div class="container p-0" style="max-width: 1200px;">
    {{-- Mejoras estructura (ADR-0016): Inicio = lo que espera tu respuesta, igual que «Mis pendientes» --}}
    <div class="tarjeta p-4 mb-2">
        <h1 class="h4 fw-bold mb-1"><i class="bi bi-house-door text-primary me-2" aria-hidden="true"></i>Inicio</h1>
        <p class="text-muted small m-0">Lo que espera tu respuesta, en el mismo orden que «Mis pendientes». Toca un aviso para resolverlo.</p>
        {{-- Informes (ADR-0017): las cifras del momento viven en el Tablero --}}
        @can('dashboard.ver')
            <a href="{{ route('tablero.index') }}" class="enlace-tablero-inicio"><i class="bi bi-speedometer2" aria-hidden="true"></i> Ver el tablero: gente en sitio, llaves, novedades abiertas y más <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
        @endcan
    </div>

    @forelse ($pendientes as $p)
        @if (! empty($p['detalle']))
            @include('panel._altas-por-verificar', ['aviso' => $p])
            @continue
        @endif
        <a href="{{ $p['url'] }}" class="aviso-pendiente mt-3 {{ ! empty($p['nivel']) ? 'nivel-'.$p['nivel'] : '' }}">
            <span class="icono"><i class="bi {{ $p['icono'] }}" aria-hidden="true"></i></span>
            <span class="flex-grow-1">
                <strong class="d-block">{{ $p['titulo'] }} <span class="conteo-inicio">{{ $p['total'] }}</span></strong>
                <span class="small">{{ $p['texto'] }}</span>
            </span>
            <span class="boton">{{ $p['boton'] }} <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
        </a>
    @empty
        <div class="tarjeta estado-vacio mt-4">
            <div class="icono"><i class="bi bi-check2-circle" aria-hidden="true"></i></div>
            <h2 class="h6 fw-bold mb-1">Estás al día</h2>
            <p class="text-muted small m-0">Nada espera tu respuesta. Cuando algo llegue, aparecerá aquí, en «Mis pendientes» y en la campana.</p>
        </div>
    @endforelse
</div>
@endsection
