@extends('layouts.app')

@section('titulo', $detalle['titulo'])

@section('contenido')
<div class="pantalla-informes">
    <div class="informe-encabezado">
        <div class="encabezado-pantalla m-0">
            <div class="icono"><i class="bi {{ $detalle['icono'] }} text-primary" aria-hidden="true"></i></div>
            <div>
                <h1>{{ $detalle['titulo'] }}</h1>
                <p>Detalle de la ficha del Tablero.</p>
            </div>
        </div>
        <a href="{{ route('tablero.index', array_filter(['sede' => $sede])) }}" class="btn-cancelar boton-informe"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver al Tablero</a>
    </div>
    <div class="tarjeta p-3">
        @include('informes._tablero-detalle')
    </div>
</div>
@endsection
