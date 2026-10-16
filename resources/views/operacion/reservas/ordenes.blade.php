@extends('layouts.app')

@section('titulo', 'Órdenes de servicio')

@php use App\Models\ReservaServicio; @endphp

@section('contenido')
<div class="tema-verde">
    @include('administracion.partes.avisos')
    @include('operacion.reservas._nav', ['titulo' => 'Órdenes de servicio', 'descripcion' => 'Lo que tu departamento debe preparar para las reservas confirmadas. Cuando esté listo, toca «Listo».', 'icono' => 'bi-clipboard-check', 'actual' => 'ordenes'])

    <h2 class="h6 fw-bold mb-2">Por atender</h2>
    <div class="res-lista">
        @forelse ($pendientes as $o)
            @php $r = $o->reserva; @endphp
            <article class="res-tarjeta">
                <div class="res-tarjeta-fila">
                    <div>
                        <div class="res-meta">{{ $o->departamento?->nombre }} · {{ $r->folioTexto() }}</div>
                        <h3 class="mt-1"><a href="{{ route('reservas.show', $r->id) }}">{{ $r->titulo }}</a></h3>
                        <div class="res-meta"><strong>{{ $textos->cuando($r) }}</strong> · {{ $r->area->nombre() }} · {{ $r->asistentes }} personas{{ $textos->montajeTexto($r) }}</div>
                        <ul class="mb-0 mt-2">
                            @foreach ($r->items->where('departamento_id', $o->departamento_id) as $i)
                                <li><strong>{{ $i->nombre }}</strong> × {{ $i->cantidadTexto() }} {{ ReservaServicio::UNIDADES[$i->unidad][1] ?? '' }}@if ($i->notas) — {{ $i->notas }}@endif</li>
                            @endforeach
                        </ul>
                    </div>
                    <form method="POST" action="{{ route('reservas.ordenes.listo', $o->id) }}" class="d-flex flex-column gap-2" style="min-width: 220px">
                        @csrf
                        <label class="visually-hidden" for="comentario{{ $o->id }}">Comentario (opcional)</label>
                        <input type="text" id="comentario{{ $o->id }}" name="comentario" class="campo" maxlength="500" placeholder="Comentario (opcional)">
                        <button type="submit" class="res-boton verde justify-content-center"><i class="bi bi-check2-all" aria-hidden="true"></i>Listo</button>
                    </form>
                </div>
            </article>
        @empty
            <div class="tarjeta estado-vacio">
                <div class="icono"><i class="bi bi-check2-all" aria-hidden="true"></i></div>
                <p class="fw-semibold mb-1">Nada por preparar.</p>
                <p class="text-muted small m-0">Cuando una reserva con servicios de tu departamento se confirme, su orden llegará a tu campana y aparecerá aquí.</p>
            </div>
        @endforelse
    </div>

    @if ($listas->isNotEmpty())
        <h2 class="h6 fw-bold mt-4 mb-2">Listas recientemente</h2>
        <div class="res-lista">
            @foreach ($listas as $o)
                <div class="res-tarjeta">
                    <span class="res-estado estado-lista">Listo</span>
                    <span class="res-meta ms-1">{{ $o->departamento?->nombre }} · {{ $o->listaPor?->name }} · @fecha($o->lista_en)</span>
                    <div class="mt-1"><a href="{{ route('reservas.show', $o->reserva_id) }}">{{ $o->reserva->titulo }}</a> <span class="res-meta">· {{ $textos->cuando($o->reserva) }} · {{ $o->reserva->area->nombre() }}</span></div>
                </div>
            @endforeach
        </div>
    @endif
</div>
@endsection
