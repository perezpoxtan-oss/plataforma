@extends('empleos.plantilla')

@section('titulo', '¡Gracias!')
@section('sin-indexar', '1')

@section('contenido')
<section class="tarjeta kiosco-tarjeta text-center">
    <i class="bi bi-check-circle-fill kiosco-icono exito" aria-hidden="true"></i>
    <h1 class="kiosco-h1">¡Listo! Recibimos tu postulación</h1>
    @if (session('enlace_enviado'))
        <p>Si aún no tienes tu <strong>solicitud de empleo completa</strong>, te llegará a tu correo un enlace para llenarla (vale {{ \App\Services\Candidatos\Kiosco::HORAS_ENLACE_CORREO }} horas). Si no lo ves, revisa la carpeta de no deseados.</p>
    @else
        <p>Tu solicitud de empleo completa la llenarás cuando vayas a tu entrevista, o con el enlace que te mande Recursos Humanos.</p>
    @endif
    <p>Recursos Humanos de {{ $empresa->nombre_comercial }} revisará tu perfil y te llamará si es el que buscamos.</p>
    <a href="{{ route('empleos.index', $empresa->bolsa_slug) }}" class="btn-secundario-rh">Ver otras vacantes</a>
</section>
@endsection
