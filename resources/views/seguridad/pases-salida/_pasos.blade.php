{{--
    Indicador de pasos del pase: Solicitud → Aprobaciones → Salida → En destino / Fuera → Regreso → Cerrado.
    $etapas: AdministradorPasesSalida::etapas(); $compacto: en la ficha de la lista.
--}}
@php
    $iconos = ['hecho' => 'bi-check-lg', 'actual' => 'bi-hourglass-split', 'error' => 'bi-exclamation-lg', 'cancelado' => 'bi-slash-lg', 'pendiente' => ''];
    $estados = ['hecho' => 'completo', 'actual' => 'en curso', 'error' => 'requiere atención', 'cancelado' => 'cancelado', 'pendiente' => 'pendiente'];
@endphp
<ol class="pasos-pase {{ ($compacto ?? false) ? 'compacto' : '' }}" aria-label="Avance del pase">
    @foreach ($etapas as $i => $e)
        <li class="paso-pase-item {{ $e['estado'] }}" @if ($e['estado'] === 'actual' || $e['estado'] === 'error') aria-current="step" @endif>
            <span class="paso-pase-punto" aria-hidden="true">@if ($iconos[$e['estado']])<i class="bi {{ $iconos[$e['estado']] }}"></i>@else{{ $i + 1 }}@endif</span>
            <span class="paso-pase-texto">{!! str_replace('Aprobaciones', 'Aproba&shy;ciones', e($e['texto'])) !!}@if ($e['detalle'])<small>{{ $e['detalle'] }}</small>@endif</span>
            <span class="visually-hidden">: {{ $estados[$e['estado']] }}</span>
        </li>
    @endforeach
</ol>
