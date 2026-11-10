{{--
    Píldora discreta «Mostrar quitadas / dados de baja (N)» de una sección de
    Configuración de reservas. Por omisión cada sección solo muestra lo activo;
    la píldora recarga la página mostrando también lo quitado o dado de baja
    (para editarlo o reactivarlo). Solo aparece si hay algo oculto.
    Parámetros: $seccion (areas|montajes|tipos|servicios), $ancla, $n, $ver, $texto ('quitadas', 'dados de baja'…)
--}}
@php $mostrando = in_array($seccion, $ver, true); @endphp
@if ($n > 0)
    <a href="{{ \App\Http\Controllers\Operacion\Reservas\ConfiguracionReservasController::urlVer($ver, $seccion, $ancla) }}"
       class="btn-pill-tipo res-ver-ocultas {{ $mostrando ? 'active' : '' }}" data-ver-ocultas="{{ $seccion }}">
        <i class="bi {{ $mostrando ? 'bi-eye-slash' : 'bi-eye' }} me-1" aria-hidden="true"></i>{{ $mostrando ? 'Ocultar' : 'Mostrar' }} {{ $texto }} <span class="conteo-pill">({{ $n }})</span>
    </a>
@endif
