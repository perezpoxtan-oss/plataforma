@extends('layouts.app')

@section('titulo', 'Tickets de mi departamento')

@section('contenido')
{{-- C-28 (DD-15): solo los tickets canalizados a los departamentos que atiendes --}}
<div class="tema-rojo">
    @include('administracion.partes.avisos')
    <div class="encabezado-pantalla mb-3">
        <div class="icono"><i class="bi bi-diagram-2-fill text-danger" aria-hidden="true"></i></div>
        <div>
            <h1>Tickets de mi departamento</h1>
            <p>Seguridad te canalizó estos incidentes. Ábrelos, agrega notas y toca <strong>Atender</strong> cuando hayas hecho tu parte.</p>
        </div>
    </div>

    @if ($tickets->isEmpty())
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-check2-all" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">No hay tickets canalizados a tu departamento.</p>
        </div>
    @else
        <div class="lista-tickets-departamento">
            @foreach ($tickets as $n)
                @php
                    $nivel = $niveles[$n->id] ?? null;
                    $estado = $n->resuelto() ? ['RESUELTO', 'finalizado'] : ($n->departamento_atendido_en ? ['ATENDIDO', 'en-sitio'] : ['POR ATENDER', 'pendiente']);
                @endphp
                <a href="{{ route('novedades.departamento.show', $n->id) }}" class="tarjeta ticket-departamento d-block mb-2 {{ $nivel ? 'espera-'.$nivel : '' }}" id="ticket-{{ $n->id }}">
                    <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                        <div>
                            <strong class="folio">{{ $n->folio() }}</strong> · {{ $n->etiquetaCategoria() }}
                            <div class="small text-muted"><i class="bi bi-geo-alt" aria-hidden="true"></i> {{ $n->ubicacion }} · {{ $n->sede?->nombre }}</div>
                            <div class="small"><i class="bi bi-diagram-2" aria-hidden="true"></i> {{ $n->departamento?->nombre }} · desde @fecha($n->departamento_en ?? $n->created_at)</div>
                        </div>
                        <span class="estado-acceso {{ $estado[1] }}">{{ $estado[0] }}</span>
                    </div>
                    <p class="small mb-0 mt-1">{{ \Illuminate\Support\Str::limit($n->descripcion, 160) }}</p>
                </a>
            @endforeach
        </div>
    @endif
</div>
@endsection
