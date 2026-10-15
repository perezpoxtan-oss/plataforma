@extends('publico.planes.plantilla')

@section('titulo', 'Solicitud enviada')
@section('sin-indexar', '1')

@section('contenido')
<div class="lp-envoltura">
    <section class="lp-tarjeta-simple" aria-labelledby="titulo-gracias">
        <div class="lp-icono-listo"><i class="bi bi-check2-circle" aria-hidden="true"></i></div>
        <h1 id="titulo-gracias">Recibimos tu solicitud</h1>
        @if (! empty($enviada['folio']))
            <span class="lp-folio">{{ $enviada['folio'] }}</span>
            <p>Guarda este folio. {{ $enviada['mensaje'] ?? '' }}</p>
            <p>Te enviamos una copia del desglose a <strong>{{ $enviada['correo'] }}</strong>. Si no llega en unos minutos, revisa tu carpeta de correo no deseado.</p>
        @else
            <p>Te contactaremos para confirmar tu plan y la forma de pago.</p>
        @endif
        <div class="lp-portada-botones">
            <a href="{{ route('planes.index') }}" class="lp-boton lp-boton-principal">Volver a los planes</a>
        </div>
    </section>
</div>
@endsection
