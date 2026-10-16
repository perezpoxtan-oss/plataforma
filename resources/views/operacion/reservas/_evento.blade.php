{{-- Una reserva en el calendario. $e: arreglo de ReservaController::eventoCalendario; $conArea: muestra el área --}}
@php $clase = 'res-evento estado-'.$e['estado'].($e['url'] === null ? ' ajena' : ''); @endphp
@if ($e['url'])
    <a href="{{ $e['url'] }}" class="{{ $clase }}" title="{{ $e['titulo'] }} · {{ $e['estadoTexto'] }} · ocupa {{ $e['bloqueo'] }}">
@else
    <div class="{{ $clase }}" title="Ocupado · {{ $e['bloqueo'] }}">
@endif
    <span class="res-hora">{{ $e['desde'] }}–{{ $e['hasta'] }}</span>
    <span class="res-titulo"> {{ $e['titulo'] }}</span>
    @if ($conArea ?? true)<span class="res-area">{{ $e['area'] }}</span>@endif
    @if ($e['url'])<span class="visually-hidden">· {{ $e['estadoTexto'] }}</span>@endif
@if ($e['url'])</a>@else</div>@endif
