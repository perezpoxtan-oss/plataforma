{{-- Ficha de un pase en la lista. $p: PaseSalida (con relaciones), $meToca: espera la firma del usuario. --}}
@php
    $vencido = $pasesSrv->vencido($p);
    [$textoEstado, $claseEstado] = $p->insignia($vencido);
    $tentativa = \App\Models\PaseSalida::dia($p->fecha_tentativa_regreso);
    $salida = \App\Models\PaseSalida::dia($p->fecha_salida_programada);
    $siguiente = $pasesSrv->siguiente($p);
    $iconoDestino = ['sede' => 'bi-building', 'proveedor' => 'bi-truck', 'colaborador' => 'bi-person'][$p->destino_tipo] ?? 'bi-signpost';
@endphp
<article class="ficha-pase pase-{{ $claseEstado }} {{ $meToca ? 'me-toca' : '' }}" id="pase-{{ $p->id }}">
    <div class="ficha-pase-cabecera">
        <span class="ficha-pase-folio">{{ $p->folio }}</span>
        <span class="badge-pase pase-{{ $claseEstado }}">{{ $textoEstado }}</span>
    </div>
    @if ($meToca)
        <p class="chip-me-toca"><i class="bi bi-pen-fill" aria-hidden="true"></i> Espera tu firma</p>
    @endif
    <h2 class="ficha-pase-solicitante">{{ $p->solicitante?->nombreCompleto() ?? '—' }}</h2>
    <p class="ficha-pase-motivo">{{ $p->etiquetaMotivo() }} · {{ $p->articulos_count }} {{ $p->articulos_count === 1 ? 'artículo' : 'artículos' }}</p>
    <p class="ficha-pase-ruta">
        <i class="bi bi-geo-alt" aria-hidden="true"></i> {{ $p->sede?->nombre }}
        <i class="bi bi-arrow-right mx-1" aria-hidden="true"></i>
        <i class="bi {{ $iconoDestino }}" aria-hidden="true"></i> {{ $p->nombreDestino() }}
    </p>

    @include('seguridad.pases-salida._pasos', ['etapas' => $pasesSrv->etapas($p, $vencido), 'compacto' => true])

    @if ($siguiente)
        <p class="siguiente-pase"><i class="bi bi-arrow-right-circle" aria-hidden="true"></i> {{ $siguiente }}</p>
    @endif

    <dl class="fechas-pase">
        <div><dt>Creado</dt><dd>@fecha($p->created_at, 'd/m/Y')</dd></div>
        @if ($salida)<div><dt>Sale</dt><dd>@fecha($salida, 'd/m/Y')</dd></div>@endif
        @if ($tentativa && $p->requiere_regreso)
            <div class="{{ $vencido ? 'texto-vencido' : '' }}"><dt>{{ $vencido ? 'Debió regresar' : 'Regresa (tentativo)' }}</dt><dd>@if ($vencido)<i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i> @endif @fecha($tentativa, 'd/m/Y')</dd></div>
        @endif
    </dl>

    <div class="ficha-pase-pie">
        @if ($p->editor && $p->updated_at?->ne($p->created_at))
            <span class="texto-traza"><i class="bi bi-clock-history" aria-hidden="true"></i> Editado por {{ $p->editor->name }} · @fecha($p->updated_at)</span>
        @elseif ($p->creador)
            <span class="texto-traza"><i class="bi bi-plus-circle" aria-hidden="true"></i> Creado por {{ $p->creador->name }} · @fecha($p->created_at)</span>
        @endif
        <a href="{{ route('pases-salida.show', $p->id) }}" class="btn-ver-pase {{ $meToca ? 'principal' : '' }}">
            <i class="bi {{ $meToca ? 'bi-pen' : 'bi-folder2-open' }} me-1" aria-hidden="true"></i>{{ $p->textoBoton() }}
        </a>
    </div>
</article>
