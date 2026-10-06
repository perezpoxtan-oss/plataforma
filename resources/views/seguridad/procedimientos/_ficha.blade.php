{{-- Ficha de un procedimiento en la lista. $p: Procedimiento (con categoria, vigente, trabajo). --}}
@php
    [$textoEstado, $claseEstado] = $p->insignia();
    $v = $p->vigente ?? $p->trabajo;
    $porLeerMio = in_array($p->id, $porLeer, true);
    $porAprobarMio = in_array($p->id, $porAprobar, true);
    $cumple = $p->vigente ? ($cumplimientos[$p->vigente->id] ?? null) : null;
    $porcentaje = $cumple && $cumple[1] > 0 ? (int) floor($cumple[0] * 100 / $cumple[1]) : null;
    $color = $p->categoria?->color ?? 'gris';
@endphp
<article class="ficha-procedimiento cat-{{ $color }} estado-{{ $claseEstado }} {{ $porLeerMio || $porAprobarMio ? 'me-toca' : '' }}" id="procedimiento-{{ $p->id }}">
    <div class="ficha-pase-cabecera">
        <span class="ficha-pase-folio">{{ $p->clave }}</span>
        <span class="d-flex gap-1 flex-wrap justify-content-end">
            @if ($p->version_vigente)<span class="chip-version">v{{ $p->version_vigente }}</span>@endif
            <span class="badge-procedimiento {{ $claseEstado }}">{{ $textoEstado }}</span>
        </span>
    </div>
    <p class="categoria-procedimiento"><span class="punto-categoria" aria-hidden="true"></span>{{ $p->categoria?->nombre }}</p>
    @if ($porLeerMio)
        <p class="chip-me-toca"><i class="bi bi-book-half" aria-hidden="true"></i> Por leer y firmar</p>
    @elseif ($porAprobarMio)
        <p class="chip-me-toca"><i class="bi bi-patch-check" aria-hidden="true"></i> Espera tu aprobación</p>
    @endif
    <h2 class="ficha-procedimiento-titulo">{{ $p->titulo }}</h2>
    @if ($v)
        <p class="ficha-procedimiento-objetivo">{{ \Illuminate\Support\Str::limit($v->objetivo, 140) }}</p>
        <p class="ficha-procedimiento-meta">
            <span><i class="bi bi-list-ol" aria-hidden="true"></i> {{ $v->pasos_count }} {{ $v->pasos_count === 1 ? 'paso' : 'pasos' }}</span>
            @if ($v->criticos_count > 0)<span class="texto-critico"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i> {{ $v->criticos_count }} {{ $v->criticos_count === 1 ? 'crítico' : 'críticos' }}</span>@endif
            @if ($p->publicado_en)<span><i class="bi bi-calendar-check" aria-hidden="true"></i> Vigente desde @fecha($p->publicado_en, 'd/m/Y')</span>@endif
        </p>
    @endif
    @if (! $lector && $p->version_trabajo && $p->estado === \App\Models\Procedimiento::PUBLICADO)
        <p class="siguiente-pase"><i class="bi bi-pencil-square" aria-hidden="true"></i> Versión {{ $p->version_trabajo }} {{ $p->estado_trabajo === 'en_revision' ? 'en revisión' : 'en borrador' }} (la {{ $p->version_vigente }} sigue vigente)</p>
    @endif
    @if ($porcentaje !== null)
        <div class="acuse-barra" title="Firmaron de enterado {{ $cumple[0] }} de {{ $cumple[1] }}">
            <div class="acuse-barra-texto"><span>Acuses</span><strong>{{ $cumple[0] }} de {{ $cumple[1] }} · {{ $porcentaje }}%</strong></div>
            <div class="acuse-barra-fondo"><div class="acuse-barra-avance {{ $porcentaje === 100 ? 'completo' : '' }}" style="width: {{ $porcentaje }}%"></div></div>
        </div>
    @endif

    <div class="ficha-pase-pie">
        @if ($p->editor && $p->updated_at?->ne($p->created_at))
            <span class="texto-traza"><i class="bi bi-clock-history" aria-hidden="true"></i> Editado por {{ $p->editor->name }} · @fecha($p->updated_at)</span>
        @elseif ($p->creador)
            <span class="texto-traza"><i class="bi bi-plus-circle" aria-hidden="true"></i> Creado por {{ $p->creador->name }} · @fecha($p->created_at)</span>
        @endif
        <div class="botones-ficha-procedimiento">
            <a href="{{ route('procedimientos.show', $p->id) }}" class="btn-ver-pase"><i class="bi bi-folder2-open me-1" aria-hidden="true"></i>Ficha</a>
            @if ($v)
                <a href="{{ route('procedimientos.leer', $p->id) }}" class="btn-ver-pase principal"><i class="bi bi-book me-1" aria-hidden="true"></i>{{ $porLeerMio ? 'Leer y firmar' : 'Leer' }}</a>
            @endif
        </div>
    </div>
</article>
