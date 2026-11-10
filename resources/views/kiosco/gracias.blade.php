@extends('kiosco.plantilla')

@section('titulo', '¡Gracias!')

@section('contenido')
<section class="tarjeta kiosco-tarjeta text-center">
    <i class="bi bi-check-circle-fill kiosco-icono exito" aria-hidden="true"></i>
    <h1 class="kiosco-h1">¡Listo! Recibimos tu solicitud</h1>
    <p>Recursos Humanos{{ $empresa ? ' de '.$empresa : '' }} ya la tiene. Espera un momento: en breve te llamarán.</p>
    <p class="small text-muted mb-0">Ya puedes cerrar esta página.</p>
</section>
@endsection
