{{-- Símbolo de la marca: el logo cargado en Identidad o, mientras no haya, el escudo de SEGCAT --}}
@if ($identidad->get('simbolo'))
    <img src="{{ asset($identidad->get('simbolo')) }}" alt="">
@else
    <i class="bi {{ $icono ?? 'bi-shield-lock-fill' }}" aria-hidden="true"></i>
@endif
