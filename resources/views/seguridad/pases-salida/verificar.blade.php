@extends('layouts.app')

@section('titulo', 'Verificar pase '.$pase->folio)

@section('contenido')
@php
    [$textoEstado, $claseEstado] = $pase->insignia($vencido);
    $puedeSalir = $pase->estado === \App\Models\PaseSalida::APROBADO;
@endphp
<div class="tema-azul pantalla-pases">
    <div class="verificacion-pase pase-{{ $claseEstado }}">
        <div class="verificacion-icono {{ $puedeSalir ? 'ok' : '' }}" aria-hidden="true">
            <i class="bi {{ $puedeSalir ? 'bi-shield-check' : 'bi-shield-fill-exclamation' }}"></i>
        </div>
        <p class="verificacion-autentico"><i class="bi bi-patch-check-fill" aria-hidden="true"></i> Pase auténtico de esta empresa</p>
        <h1 class="ficha-pase-folio grande">{{ $pase->folio }}</h1>
        <span class="badge-pase pase-{{ $claseEstado }} grande">{{ $textoEstado }}</span>
        <p class="verificacion-mensaje">
            @if ($puedeSalir)
                <strong>Autorizado para salir.</strong> Registra la salida y verifica cada artículo.
            @elseif ($pase->estado === \App\Models\PaseSalida::PENDIENTE)
                <strong>Aún no está aprobado:</strong> no debe salir todavía.
            @elseif (in_array($pase->estado, [\App\Models\PaseSalida::RECHAZADO, \App\Models\PaseSalida::CANCELADO], true))
                <strong>No autorizado:</strong> este pase no permite la salida.
            @else
                Estado al momento: {{ $textoEstado }}.
            @endif
        </p>
        <p class="small text-muted m-0">{{ $pase->articulos_count }} {{ $pase->articulos_count === 1 ? 'artículo' : 'artículos' }} · sale de {{ $pase->sede?->nombre }} · consultado @fecha(now())</p>
        <a href="{{ route('pases-salida.show', $pase->id) }}" class="btn-accion-pase aprobar mt-3"><i class="bi bi-folder2-open" aria-hidden="true"></i> Abrir el pase</a>
    </div>
</div>
@endsection
