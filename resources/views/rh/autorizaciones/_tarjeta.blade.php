{{-- Tarjeta de una solicitud de autorización. $a: Autorizacion · $botones: bool --}}
@use('App\Models\Autorizacion')
@php
    $c = $a->candidato;
    $acc = $a->acceso;
@endphp
<article class="tarjeta-autorizacion estado-{{ $a->estado }}">
    <div class="d-flex flex-wrap justify-content-between gap-2 align-items-start">
        <div class="min-w-0">
            <span class="pastilla-tipo-rh {{ $a->tipo === 'candidato' ? 'candidato' : 'visita' }}">{{ $a->tipo === 'candidato' ? 'Candidato' : 'Visita' }}</span>
            <span class="pastilla-estado-aut estado-{{ $a->estado }}">{{ Autorizacion::ESTADOS[$a->estado] ?? $a->estado }}</span>
            <h3 class="nombre-autorizacion"><a href="{{ route('autorizaciones.show', $a->id) }}">{{ $a->titulo() }}</a></h3>
        </div>
        <div class="espera-autorizacion {{ $a->pendiente() && $a->minutosEspera() >= 10 ? 'larga' : '' }}">
            <i class="bi bi-stopwatch" aria-hidden="true"></i> {{ $a->minutosEspera() }} min
        </div>
    </div>
    <div class="datos-candidato">
        <span><i class="bi bi-diagram-2" aria-hidden="true"></i> {{ $a->departamento?->nombre }}</span>
        <span><i class="bi bi-geo-alt" aria-hidden="true"></i> {{ $a->sede?->nombre }}</span>
        @if ($a->tipo === 'visita' && $acc)
            @if ($acc->persona_visita)<span><i class="bi bi-person" aria-hidden="true"></i> Visita a {{ $acc->persona_visita }}</span>@endif
            @if ($acc->empresa_procedencia)<span><i class="bi bi-building" aria-hidden="true"></i> {{ $acc->empresa_procedencia }}</span>@endif
        @elseif ($c)
            <span><i class="bi bi-briefcase" aria-hidden="true"></i> {{ $c->puestoVisible() ?? 'Puesto sin definir' }}</span>
            @if ($c->escolaridadMaxima())<span><i class="bi bi-mortarboard" aria-hidden="true"></i> {{ $c->escolaridadMaxima() }}</span>@endif
            @if ($c->anosExperiencia() > 0)<span><i class="bi bi-building" aria-hidden="true"></i> {{ $c->anosExperiencia() }} año(s) de experiencia</span>@endif
        @endif
    </div>
    <p class="texto-traza mb-1">Avisado @fecha($a->solicitada_en) · registró {{ $a->registradoPor?->name ?? '—' }}</p>
    @unless ($a->pendiente())
        <p class="texto-traza mb-1">Respondió {{ $a->respondidaPor?->name ?? '—' }} ({{ Autorizacion::MEDIOS[$a->respuesta_medio] ?? $a->respuesta_medio }}) · @fecha($a->respondida_en){{ $a->comentario ? ' — «'.$a->comentario.'»' : '' }}</p>
    @endunless
    @if ($botones && $a->pendiente())
        <div class="botones-autorizacion">
            @foreach (array_keys(Autorizacion::RESPUESTAS[$a->tipo]) as $respuesta)
                <form action="{{ route('autorizaciones.responder', $a->id) }}" method="POST" class="m-0"
                      @if ($respuesta === 'rechazar') data-confirmar="¿Rechazar a {{ $a->titulo() }}? La caseta y Recursos Humanos verán tu respuesta." @endif>
                    @csrf
                    <input type="hidden" name="respuesta" value="{{ $respuesta }}">
                    <button type="submit" class="btn-respuesta {{ $respuesta === 'rechazar' ? 'rechazar' : 'aceptar' }}">
                        <i class="bi {{ $respuesta === 'rechazar' ? 'bi-x-lg' : ($respuesta === 'autorizar' ? 'bi-door-open-fill' : 'bi-chat-square-text-fill') }} me-1" aria-hidden="true"></i>{{ Autorizacion::BOTONES[$respuesta] }}
                    </button>
                </form>
            @endforeach
            <a href="{{ route('autorizaciones.show', $a->id) }}" class="btn-secundario-rh">Ver detalle / comentar</a>
        </div>
    @endif
</article>
