@extends('kiosco.plantilla')

@section('titulo', 'Enlace no disponible')

@section('contenido')
<section class="tarjeta kiosco-tarjeta text-center">
    <i class="bi bi-hourglass-bottom kiosco-icono" aria-hidden="true"></i>
    <h1 class="kiosco-h1">
        @if ($motivo === 'usado')
            Este enlace ya se usó
        @elseif ($motivo === 'vencido')
            Este enlace ya venció
        @else
            Este enlace no existe
        @endif
    </h1>
    <p>Pide en recepción un código nuevo para llenar tu solicitud.</p>
    <a href="{{ route('kiosco.codigo') }}" class="btn-verde kiosco-boton">Tengo un código</a>
</section>
@endsection
