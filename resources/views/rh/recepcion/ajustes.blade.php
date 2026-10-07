@extends('layouts.app')

@section('titulo', 'Ajustes de Recepción')

@section('contenido')
<div class="pantalla-ajustes-rh">
    @include('administracion.partes.avisos')
    <a href="{{ route('recepcion.index') }}" class="enlace-volver"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Recepción</a>
    <div class="encabezado-pantalla mb-3">
        <div class="icono"><i class="bi bi-sliders text-success" aria-hidden="true"></i></div>
        <div>
            <h1>Ajustes de Recepción</h1>
            <p>Aviso de privacidad del CV y duración del enlace del kiosco.</p>
        </div>
    </div>
    @include('rh.recepcion._ajustes', ['empresa' => $empresa])
</div>
@endsection
