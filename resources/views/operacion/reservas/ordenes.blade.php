@extends('layouts.app')

@section('titulo', 'Órdenes de servicio')

@php use App\Models\ReservaServicio; @endphp

@section('contenido')
<div class="tema-verde">
    @include('administracion.partes.avisos')
    @include('operacion.reservas._nav', ['titulo' => 'Órdenes de servicio', 'descripcion' => 'Lo que tu departamento debe preparar para las reservas confirmadas. Cuando esté listo, toca «Listo».',
        'icono' => 'bi-clipboard-check', 'actual' => 'ordenes', 'buscar' => ['reservas-ordenes', 'Buscar orden...']])

    <div class="pildoras-tipo" role="group" aria-label="Filtrar órdenes">
        <button type="button" class="btn-pill-tipo active" data-filtro-tipo="reservas-ordenes" data-valor="" aria-pressed="true">Todas <span class="conteo-pill">{{ $pendientes->count() + $listas->count() }}</span></button>
        <button type="button" class="btn-pill-tipo" data-filtro-tipo="reservas-ordenes" data-valor="pendiente" aria-pressed="false">Por atender <span class="conteo-pill">{{ $pendientes->count() }}</span></button>
        <button type="button" class="btn-pill-tipo" data-filtro-tipo="reservas-ordenes" data-valor="lista" aria-pressed="false">Listas <span class="conteo-pill">{{ $listas->count() }}</span></button>
    </div>

    <div class="fichas-grid" data-fichas="reservas-ordenes">
        @foreach ($pendientes->toBase()->map(fn ($o) => [$o, 'pendiente'])->merge($listas->toBase()->map(fn ($o) => [$o, 'lista'])) as [$o, $tipo])
            @php $r = $o->reserva; @endphp
            <div class="ficha-card" id="orden-{{ $o->id }}" data-ficha data-estado="1" data-tipo="{{ $tipo }}"
                 data-texto="{{ mb_strtolower($r->folioTexto().' '.$r->titulo.' '.$r->area->nombre().' '.$o->departamento?->nombre) }}">
                <div>
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <h2 class="ficha-title"><a href="{{ route('reservas.show', $r->id) }}" class="text-reset text-decoration-none">{{ $r->titulo }}</a></h2>
                        <span class="etiqueta-estado {{ $tipo === 'lista' ? 'activo' : 'pendiente' }} text-nowrap">{{ $tipo === 'lista' ? 'Listo' : 'Por atender' }}</span>
                    </div>
                    <div class="ficha-subtitulo">{{ $o->departamento?->nombre }} · {{ $r->folioTexto() }}</div>
                    <ul class="list-unstyled small text-muted mt-2 pt-2 border-top m-0">
                        <li><i class="bi bi-calendar-event me-1" aria-hidden="true"></i> <strong>{{ $textos->cuando($r) }}</strong></li>
                        <li><i class="bi bi-door-open me-1" aria-hidden="true"></i> {{ $r->area->nombre() }} · {{ $r->asistentes }} personas{{ $textos->montajeTexto($r) }}</li>
                        @foreach ($r->items->where('departamento_id', $o->departamento_id) as $i)
                            <li><i class="bi bi-dot" aria-hidden="true"></i><strong>{{ $i->nombre }}</strong> × {{ $i->cantidadTexto() }} {{ ReservaServicio::UNIDADES[$i->unidad][1] ?? '' }}@if ($i->notas) — {{ $i->notas }}@endif</li>
                        @endforeach
                    </ul>
                    @if ($tipo === 'lista')
                        <div class="texto-traza mt-2"><i class="bi bi-check2-all" aria-hidden="true"></i> {{ $o->listaPor?->name }} · @fecha($o->lista_en){{ $o->comentario ? ' · «'.$o->comentario.'»' : '' }}</div>
                    @endif
                </div>
                <div class="ficha-footer">
                    @if ($tipo === 'pendiente')
                        <form method="POST" action="{{ route('reservas.ordenes.listo', $o->id) }}" class="d-flex gap-2 align-items-center w-100 m-0">
                            @csrf
                            <label class="visually-hidden" for="comentario{{ $o->id }}">Comentario (opcional)</label>
                            <input type="text" id="comentario{{ $o->id }}" name="comentario" class="campo mb-0" maxlength="500" placeholder="Comentario (opcional)">
                            <button type="submit" class="btn-icono reactivar" title="Listo" aria-label="Marcar como listo: {{ $o->departamento?->nombre }}, {{ $r->titulo }}"><i class="bi bi-check2-all" aria-hidden="true"></i></button>
                            <a href="{{ route('reservas.show', $r->id) }}" class="btn-icono ver" title="Ver" aria-label="Ver {{ $r->titulo }}"><i class="bi bi-eye" aria-hidden="true"></i></a>
                        </form>
                    @else
                        <span></span>
                        <a href="{{ route('reservas.show', $r->id) }}" class="btn-icono ver" title="Ver" aria-label="Ver {{ $r->titulo }}"><i class="bi bi-eye" aria-hidden="true"></i></a>
                    @endif
                </div>
            </div>
        @endforeach
    </div>
    @if ($pendientes->isEmpty() && $listas->isEmpty())
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-check2-all" aria-hidden="true"></i></div>
            <p class="fw-semibold mb-1">Nada por preparar.</p>
            <p class="text-muted small m-0">Cuando una reserva con servicios de tu departamento se confirme, su orden llegará a tu campana y aparecerá aquí.</p>
        </div>
    @endif
    <p class="text-muted text-center p-4" data-sin-resultados="reservas-ordenes" hidden><i class="bi bi-search me-2" aria-hidden="true"></i>No hay órdenes que coincidan con tu búsqueda.</p>
</div>
@endsection
