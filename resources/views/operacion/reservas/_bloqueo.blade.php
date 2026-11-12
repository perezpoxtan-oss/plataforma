{{-- Un bloqueo en el calendario (visible para todos). $b: arreglo de ReservaController::bloqueosCalendario; $conSitio: muestra el sitio bloqueado --}}
@php $texto = 'Bloqueado: '.$b['motivo']; @endphp
@if ($b['url'])
    <a href="{{ $b['url'] }}" class="res-evento bloqueado" title="{{ $texto }} · {{ $b['sitio'] }} · {{ $b['desde'] }}–{{ $b['hasta'] }}">
@else
    <div class="res-evento bloqueado" title="{{ $texto }} · {{ $b['sitio'] }} · {{ $b['desde'] }}–{{ $b['hasta'] }}">
@endif
    <span class="res-hora"><i class="bi bi-lock-fill" aria-hidden="true"></i> {{ $b['desde'] }}–{{ $b['hasta'] }}</span>
    <span class="res-titulo"> {{ $texto }}</span>
    @if ($conSitio ?? true)<span class="res-area">{{ $b['sitio'] }}</span>@endif
@if ($b['url'])</a>@else</div>@endif
