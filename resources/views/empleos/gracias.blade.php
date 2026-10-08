@extends('empleos.plantilla')

@section('titulo', '¡Gracias!')
@section('sin-indexar', '1')

@section('contenido')
<section class="tarjeta kiosco-tarjeta text-center">
    <i class="bi bi-check-circle-fill kiosco-icono exito" aria-hidden="true"></i>
    <h1 class="kiosco-h1">¡Listo! Recibimos tu solicitud</h1>
    <p>Recursos Humanos de {{ $empresa->nombre_comercial }} la revisará y te llamará si tu perfil es el que buscamos.</p>
    <a href="{{ route('empleos.index', $empresa->bolsa_slug) }}" class="btn-secundario-rh">Ver otras vacantes</a>
</section>
@endsection
