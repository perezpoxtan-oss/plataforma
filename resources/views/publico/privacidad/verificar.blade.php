@extends('publico.privacidad.plantilla')

@section('titulo', 'Confirmar solicitud')

@section('contenido')
<h1 class="acceso-titulo h3">Confirmar tu solicitud</h1>

@if ($confirmada && $solicitud)
    <div class="alert alert-success acceso-aviso text-start" role="status">
        <i class="bi bi-check-circle-fill me-2" aria-hidden="true"></i> Listo: confirmaste tu solicitud <strong>{{ $solicitud->folio }}</strong>. {{ \App\Http\Controllers\Publico\PrivacidadController::textoPlazo($solicitud) }} Te escribiremos a tu correo.
    </div>
@elseif ($solicitud)
    <p class="acceso-texto">Solicitud <strong>{{ $solicitud->folio }}</strong> ({{ mb_strtolower($solicitud->etiquetaDerecho()) }}). Toca el botón para confirmar que el correo es tuyo y empezar el trámite.</p>
    <form action="{{ route('privacidad.verificar.confirmar', $token) }}" method="POST">
        @csrf
        <button type="submit" class="acceso-boton">Confirmar mi solicitud <i class="bi bi-check2-circle" aria-hidden="true"></i></button>
    </form>
@else
    <div class="alert alert-warning acceso-aviso text-start" role="alert">
        <i class="bi bi-exclamation-triangle-fill me-2" aria-hidden="true"></i> Este enlace ya se usó o venció (sirve 72 horas). Si ya confirmaste, no tienes que hacer nada más; si no, vuelve a enviar tu solicitud.
    </div>
@endif
@endsection
