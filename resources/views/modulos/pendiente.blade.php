@extends('layouts.app')

@section('titulo', $modulo->nombre)

@section('contenido')
<div class="container p-0" style="max-width: 1200px;">
    <div class="tarjeta estado-vacio">
        <div class="icono"><i class="bi {{ $modulo->icono ?? 'bi-grid' }}" aria-hidden="true"></i></div>
        <h1 class="h5 fw-bold mb-1">{{ $modulo->nombre }}</h1>
        <p class="text-muted small mb-3">Este módulo se está migrando a la nueva plataforma. Mientras tanto sigue disponible en el sistema actual.</p>
        <a href="{{ route('panel') }}" class="btn btn-outline-primary btn-sm rounded-3"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Volver al inicio</a>
    </div>
</div>
@endsection
