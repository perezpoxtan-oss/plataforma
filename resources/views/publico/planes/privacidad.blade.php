@extends('publico.planes.plantilla')

@section('titulo', 'Aviso de privacidad')

@section('contenido')
<div class="lp-envoltura">
    <section class="lp-tarjeta-simple" aria-labelledby="titulo-privacidad">
        <h1 id="titulo-privacidad">Aviso de privacidad</h1>
        <p class="lp-texto-largo">{{ $texto }}</p>
    </section>
</div>
@endsection
