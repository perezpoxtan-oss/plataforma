{{-- Mantenimiento de equipo PC (ADR-0013): equipos con falla de los recorridos que abrieron este ticket (no se recapturan) --}}
@php
    $recorridosNov = $n->relationLoaded('recorridosPc') ? $n->recorridosPc : $n->recorridosPc()->with('revisiones')->get();
@endphp
<div class="accordion-item" id="moduloMantenimientoPc">
    <h2 class="accordion-header"><a class="accordion-button" role="button" data-bs-toggle="collapse" href="#colMantenimientoPc" aria-expanded="true"><i class="bi bi-tools me-2 text-success" aria-hidden="true"></i>Detalle: Mantenimiento de equipo PC</a></h2>
    <div id="colMantenimientoPc" class="accordion-collapse collapse show">
        <div class="accordion-body">
            @forelse ($recorridosNov as $rec)
                <p class="small fw-bold mb-1">
                    Recorrido {{ $rec->folio() }} · {{ $rec->etiquetaEstatus() }}
                    @can('recorridos_pc.ver') · <a href="{{ route('recorridos_pc.show', $rec->id) }}">Ver recorrido</a>@endcan
                </p>
                <ul class="small mb-3">
                    @forelse ($rec->revisiones->where('resultado', \App\Models\RevisionRecorridoPc::FALLA) as $falla)
                        <li>{{ $falla->resumenHallazgo() }}</li>
                    @empty
                        <li class="text-muted">Sin equipos con falla.</li>
                    @endforelse
                </ul>
            @empty
                <p class="text-muted small m-0">Este ticket no tiene recorridos ligados.</p>
            @endforelse
            <p class="campo-ayuda mb-0">Anota en el Minuto a Minuto lo que se reparó o reemplazó y cierra el caso cuando el equipo quede en servicio.</p>
        </div>
    </div>
</div>
