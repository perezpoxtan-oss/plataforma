{{--
    Mejoras padrones (criterio 5 de docs/criterios-ux.md): después de guardar,
    el aviso ofrece el siguiente paso natural con su botón. El controlador
    deja en sesión 'siguiente_paso' => [
        'texto' => 'Imprimir este lote', 'url' => …, 'icono' => 'bi-printer',
        'nueva_pestana' => true|false,
    ] (o una lista de ellos). Se pinta justo debajo de los avisos.
--}}
@php
    $pasosSig = session('siguiente_paso');
    $pasosSig = is_array($pasosSig) && isset($pasosSig['url']) ? [$pasosSig] : (is_array($pasosSig) ? $pasosSig : []);
@endphp
@if ($pasosSig !== [])
    <div class="siguiente-paso mb-3" role="group" aria-label="Siguiente paso">
        <span class="siguiente-paso-texto"><i class="bi bi-arrow-right-circle" aria-hidden="true"></i> Siguiente paso:</span>
        @foreach ($pasosSig as $paso)
            @continue (! is_array($paso) || empty($paso['url']) || empty($paso['texto']) || ! str_starts_with((string) $paso['url'], url('/')))
            <a href="{{ $paso['url'] }}" class="btn-siguiente-paso" @if (! empty($paso['nueva_pestana'])) target="_blank" rel="noopener" @endif>
                <i class="bi {{ $paso['icono'] ?? 'bi-arrow-right' }} me-1" aria-hidden="true"></i>{{ $paso['texto'] }}
            </a>
        @endforeach
    </div>
@endif
