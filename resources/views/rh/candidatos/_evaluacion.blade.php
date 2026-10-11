{{--
    Una evaluación de entrevista (solo lectura): quién, cuándo, estrellas por
    criterio, promedio, resultado y comentario. $e: EvaluacionCandidato.
--}}
@php $hora = app(\App\Support\HoraLocal::class); @endphp
<article class="evaluacion-entrevista tipo-{{ $e->tipo }}">
    <header class="d-flex flex-wrap align-items-center gap-2">
        <strong>{{ $e->tipo === 'rh' ? 'Entrevista de RR. HH.' : 'Entrevista del departamento' }} · {{ $e->numero }}.ª</strong>
        <span class="promedio-evaluacion" title="Promedio de {{ count((array) $e->criterios) }} criterios"><i class="bi bi-star-fill" aria-hidden="true"></i> {{ $e->promedioTexto() }}<span class="visually-hidden"> de 5 en promedio</span></span>
        <span class="pastilla-resultado resultado-{{ $e->resultado }}">{{ $e->etiquetaResultado() }}</span>
    </header>
    <p class="texto-traza mb-1">{{ $e->evaluador?->name ?? '—' }} · {{ $hora->formatear($e->entrevista_en ?? $e->created_at) }}</p>
    <ul class="criterios-evaluados">
        @foreach ((array) $e->criterios as $nombre => $valor)
            <li><span>{{ $nombre }}</span><span class="estrellas-lectura" aria-label="{{ (int) $valor }} de 5">@for ($i = 1; $i <= 5; $i++)<i class="bi {{ $i <= (int) $valor ? 'bi-star-fill' : 'bi-star' }}" aria-hidden="true"></i>@endfor</span></li>
        @endforeach
    </ul>
    @if ($e->comentario)
        <p class="comentario-evaluacion mb-0">«{{ $e->comentario }}»</p>
    @endif
</article>
