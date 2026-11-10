@extends('layouts.app')

@section('titulo', 'Aviso de privacidad · versión '.$aviso->numero)

@section('contenido')
<div class="tema-azul">
    <div class="encabezado-pantalla mb-3">
        <div class="icono"><i class="bi bi-file-earmark-lock text-primary" aria-hidden="true"></i></div>
        <div>
            <h1>Aviso de privacidad · versión {{ $aviso->numero }}</h1>
            <p>Publicada el @fecha($aviso->publicado_en, 'd/m/Y H:i'){{ $aviso->publicador ? ' por '.$aviso->publicador->name : '' }}. Huella: {{ substr($aviso->huella, 0, 16) }}…</p>
        </div>
    </div>
    <section class="tarjeta p-4">
        <div class="texto-privacidad" style="white-space:pre-line;">{{ $aviso->texto }}</div>
    </section>
    <p class="mt-3"><a href="{{ route('configuracion.index') }}#cfg-aviso"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver a Configuración → Privacidad</a></p>
</div>
@endsection
