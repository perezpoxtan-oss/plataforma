@extends('publico.planes.plantilla')

@section('titulo', 'Aviso de privacidad')

@section('contenido')
<div class="lp-envoltura">
    <section class="lp-tarjeta-simple" aria-labelledby="titulo-privacidad">
        <h1 id="titulo-privacidad">Aviso de privacidad</h1>
        <p class="lp-texto-largo">{{ $texto }}</p>
        <p>Para ejercer tus derechos de acceso, rectificación, cancelación u oposición (ARCO), <a href="{{ route('privacidad.solicitud') }}">llena la solicitud en línea</a>.</p>
    </section>
</div>
@endsection
