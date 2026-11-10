{{--
    Ayuda directa al apartado del manual (ADR-0016): un enlace «¿Cómo se hace?»
    que abre la página del manual justo en la tarea (p. ej. desde la ventana
    de alta, «Cómo dar de alta una cuenta»). Solo aparece si el usuario puede
    ver esa página. Uso:
    @include('componentes.ayuda-apartado', ['pagina' => 'usuarios', 'apartado' => 'Cómo dar de alta una cuenta'])
--}}
@php $enlaceAyuda = auth()->check() ? app(\App\Services\Manual\Manual::class)->enlaceApartado(auth()->user(), $pagina, $apartado) : null; @endphp
@if ($enlaceAyuda)
    <a href="{{ $enlaceAyuda }}" class="ayuda-apartado no-imprimir" target="_blank" rel="noopener" title="Abre el manual en otra pestaña">
        <i class="bi bi-question-circle" aria-hidden="true"></i> {{ $texto ?? '¿Cómo se hace?' }}
    </a>
@endif
