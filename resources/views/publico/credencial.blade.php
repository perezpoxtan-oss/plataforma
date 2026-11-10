{{--
    C-44 (Etapa 8): verificación pública de una credencial (QR impreso, sin
    sesión). Solo «Vigente» con nombre, empresa y hasta cuándo vale, o
    «No vigente». Nada más: ni foto (decisión del dueño, 3 nov), ni datos
    personales, ni el motivo.
--}}
@extends('publico.privacidad.plantilla')

@section('titulo', 'Verificación de credencial')

@section('contenido')
@if ($vigente)
    <div class="verificacion-credencial vigente" role="status">
        <p class="verificacion-estado"><i class="bi bi-patch-check-fill me-1" aria-hidden="true"></i>Vigente</p>
        <h1 class="acceso-titulo h4 mb-1">{{ $nombre }}</h1>
        <p class="acceso-subtitulo mb-1">{{ $empresa }}</p>
        <p class="small mb-0">{{ $vence ? 'Vale hasta el '.$vence : 'Sin fecha de vencimiento' }}</p>
    </div>
@else
    <div class="verificacion-credencial no-vigente" role="status">
        <p class="verificacion-estado"><i class="bi bi-x-octagon-fill me-1" aria-hidden="true"></i>No vigente</p>
        <p class="small mb-0">Esta credencial no es válida. Si alguien la presenta, avisa a la caseta.</p>
    </div>
@endif
<p class="small text-muted mt-3 mb-0">Verificación de la plataforma de seguridad. No muestra datos personales.</p>
@endsection
