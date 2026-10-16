{{-- Reloj del plazo legal de una solicitud ARCO: $s, $recordar (días hábiles para ámbar) --}}
@php
    $restan = \App\Services\Privacidad\SolicitudesArco::diasRestantes($s);
    $nivelReloj = \App\Services\Privacidad\SolicitudesArco::nivel($s, $recordar);
@endphp
@if ($s->estado === \App\Models\SolicitudArco::PENDIENTE && $restan !== null)
    <span class="reloj-arco {{ $nivelReloj }}" title="Responder a más tardar el {{ $s->vence_en->format('d/m/Y') }}">
        <i class="bi bi-clock" aria-hidden="true"></i>
        @if ($restan > 0) Faltan {{ $restan }} día(s) hábil(es) @elseif ($restan === 0) Vence hoy @else Venció hace {{ abs($restan) }} día(s) hábil(es) @endif
    </span>
@elseif ($s->estado === \App\Models\SolicitudArco::POR_VERIFICAR)
    <span class="reloj-arco gris"><i class="bi bi-envelope" aria-hidden="true"></i> Correo sin confirmar</span>
@else
    <span class="reloj-arco gris"><i class="bi bi-check2" aria-hidden="true"></i> {{ \App\Models\SolicitudArco::ESTADOS[$s->estado] ?? $s->estado }} @if ($s->respondida_en) el @fecha($s->respondida_en, 'd/m/Y') @endif</span>
@endif
