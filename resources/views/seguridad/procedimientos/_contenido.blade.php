{{-- Contenido de una versión: datos, a quién aplica, pasos, notas y adjuntos. $p, $version, $aplicaA. --}}
<div class="tarjeta p-3 mb-3">
    <dl class="datos-pase">
        <div class="completo"><dt>Objetivo</dt><dd>{{ $version->objetivo }}</dd></div>
        @if ($version->alcance)<div class="completo"><dt>Alcance</dt><dd>{{ $version->alcance }}</dd></div>@endif
        @if ($version->responsables)<div class="completo"><dt>Responsables</dt><dd>{{ $version->responsables }}</dd></div>@endif
        <div><dt>Sedes</dt><dd>{{ $aplicaA['sedes'] }}</dd></div>
        <div class="doble"><dt>Personal</dt><dd>{{ $aplicaA['personal'] }}</dd></div>
    </dl>
</div>

<h2 class="titulo-seccion-pase"><i class="bi bi-list-ol me-2 text-primary" aria-hidden="true"></i>Pasos</h2>
<ol class="pasos-procedimiento">
    @foreach ($version->pasos as $paso)
        <li class="paso-procedimiento {{ $paso->critico ? 'critico' : '' }}">
            <span class="paso-procedimiento-numero" aria-hidden="true">{{ $paso->orden }}</span>
            <div class="min-w-0">
                @if ($paso->critico)<span class="etiqueta-critico"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i> Punto crítico</span>@endif
                <p class="m-0">{{ $paso->texto }}</p>
                @if ($paso->responsable)<span class="paso-responsable"><i class="bi bi-person" aria-hidden="true"></i> {{ $paso->responsable }}</span>@endif
            </div>
        </li>
    @endforeach
</ol>

@if ($version->notas)
    <h2 class="titulo-seccion-pase"><i class="bi bi-sticky me-2 text-primary" aria-hidden="true"></i>Notas</h2>
    <div class="tarjeta p-3 mb-3 texto-notas">{{ $version->notas }}</div>
@endif

@if ($version->adjuntos->isNotEmpty())
    <h2 class="titulo-seccion-pase"><i class="bi bi-paperclip me-2 text-primary" aria-hidden="true"></i>Adjuntos</h2>
    <ul class="adjuntos-procedimiento">
        @foreach ($version->adjuntos as $a)
            <li>
                <a href="{{ route('procedimientos.adjunto', [$p->id, $a->id]) }}" target="_blank" rel="noopener">
                    @if ($a->esImagen())
                        <img src="{{ route('procedimientos.adjunto', [$p->id, $a->id]) }}" alt="" class="miniatura-adjunto" loading="lazy">
                    @else
                        <span class="icono-adjunto" aria-hidden="true"><i class="bi bi-file-earmark-pdf-fill"></i></span>
                    @endif
                    <span class="min-w-0"><strong>{{ $a->nombre }}</strong><span class="d-block small text-muted">{{ $a->esImagen() ? 'Imagen' : 'PDF' }} · {{ $a->tamanoLegible() }}</span></span>
                </a>
            </li>
        @endforeach
    </ul>
@endif
