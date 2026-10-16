@extends('publico.privacidad.plantilla')

@section('titulo', 'Aviso de privacidad')

@section('contenido')
<h1 class="acceso-titulo h3">Aviso de privacidad</h1>
<p class="acceso-subtitulo">{{ $empresa->nombre_comercial }}@if ($aviso) · versión {{ $aviso->numero }} del @fecha($aviso->publicado_en, 'd/m/Y')@endif</p>

<div class="acceso-texto text-start" style="white-space:pre-line;">{{ $aviso?->texto }}</div>

<div class="alert alert-info acceso-aviso text-start" role="note">
    <i class="bi bi-person-vcard me-2" aria-hidden="true"></i>¿Quieres saber qué datos tenemos de ti, corregirlos, que los borremos o que dejemos de usarlos? <strong>Llena la solicitud de derechos ARCO</strong>; te respondemos en máximo 20 días hábiles.
</div>
<a href="{{ route('privacidad.empresa.solicitud', $codigo) }}" class="acceso-boton text-decoration-none d-flex justify-content-center align-items-center">Ejercer mis derechos ARCO <i class="bi bi-arrow-right-short fs-5" aria-hidden="true"></i></a>
@endsection
