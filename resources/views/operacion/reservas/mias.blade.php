@extends('layouts.app')

@section('titulo', 'Mis reservas')

@php use App\Models\Reserva; @endphp

@section('contenido')
<div class="tema-verde">
    @include('administracion.partes.avisos')
    @include('operacion.reservas._nav', ['titulo' => 'Mis reservas', 'descripcion' => 'Las reservas que hiciste: su estado, lo que falta por aprobar y el avance de los servicios.', 'icono' => 'bi-person-check', 'actual' => 'mias'])

    <div class="res-filtros">
        <div class="res-vistas" role="group" aria-label="Qué reservas ver">
            <a href="{{ route('reservas.mias') }}" class="{{ ! $pasadas && $estado === null ? 'activa' : '' }}">Próximas</a>
            @foreach ([Reserva::POR_APROBAR, Reserva::CAMBIO_PEDIDO, Reserva::CONFIRMADA] as $e)
                <a href="{{ route('reservas.mias', ['estado' => $e]) }}" class="{{ $estado === $e ? 'activa' : '' }}">{{ Reserva::ESTADOS[$e] }}@if (($conteos[$e] ?? 0) > 0) ({{ $conteos[$e] }})@endif</a>
            @endforeach
            <a href="{{ route('reservas.mias', ['pasadas' => 1]) }}" class="{{ $pasadas ? 'activa' : '' }}">Pasadas</a>
        </div>
    </div>

    <div class="res-lista">
        @forelse ($reservas as $r)
            @php
                $faltan = $r->aprobaciones->where('vigente', true)->where('estado', 'pendiente');
                $ordenes = $r->ordenes->where('estado', '!=', 'cancelada');
            @endphp
            <a href="{{ route('reservas.show', $r->id) }}" class="res-tarjeta text-reset text-decoration-none d-block">
                <div class="res-tarjeta-fila">
                    <div>
                        <span class="res-estado estado-{{ $r->terminada() ? 'terminada' : $r->estado }}">{{ $r->estadoTexto() }}</span>
                        <span class="res-meta ms-1">{{ $r->folioTexto() }}</span>
                        <h2 class="mt-1">{{ $r->titulo }}</h2>
                        <div class="res-meta">{{ $textos->resumen($r) }}</div>
                    </div>
                    <div class="res-meta text-end">
                        @if ($r->estado === Reserva::CAMBIO_PEDIDO)
                            <strong class="text-warning-emphasis"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Te piden un cambio</strong>
                        @elseif ($faltan->isNotEmpty())
                            Falta aprobar: {{ $faltan->map(fn ($a) => $a->tipo === 'area' ? 'área' : $a->departamento?->nombre)->join(', ') }}
                        @elseif ($ordenes->isNotEmpty() && $r->estado === Reserva::CONFIRMADA)
                            Servicios listos: {{ $ordenes->where('estado', 'lista')->count() }} de {{ $ordenes->count() }}
                        @endif
                    </div>
                </div>
            </a>
        @empty
            <div class="tarjeta estado-vacio">
                <div class="icono"><i class="bi bi-calendar2-week" aria-hidden="true"></i></div>
                <p class="fw-semibold mb-1">No tienes reservas {{ $pasadas ? 'pasadas' : ($estado ? 'en «'.Reserva::ESTADOS[$estado].'»' : 'próximas') }}.</p>
                @if ($puede['crear'])<p class="text-muted small m-0">Toca <strong>Nueva reserva</strong> para apartar un área.</p>@endif
            </div>
        @endforelse
    </div>
    <div class="mt-3">{{ $reservas->links() }}</div>
</div>
@endsection
