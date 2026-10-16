@extends('layouts.app')

@section('titulo', 'Mis reservas')

@php
    use App\Models\Reserva;
    $etiqueta = ['por_aprobar' => 'pendiente', 'cambio_pedido' => 'pendiente', 'confirmada' => 'activo', 'rechazada' => 'inactivo', 'cancelada' => 'inactivo'];
@endphp

@section('contenido')
<div class="tema-verde">
    @include('administracion.partes.avisos')
    @include('operacion.reservas._nav', ['titulo' => 'Mis reservas', 'descripcion' => 'Las reservas que hiciste: su estado, lo que falta por aprobar y el avance de los servicios.',
        'icono' => 'bi-person-check', 'actual' => 'mias', 'buscar' => ['reservas-mias', 'Buscar reserva...']])

    <nav class="pildoras-tipo" aria-label="Filtrar mis reservas">
        <a href="{{ route('reservas.mias') }}" class="btn-pill-tipo {{ ! $pasadas && $estado === null ? 'active' : '' }}">Próximas</a>
        @foreach ([Reserva::POR_APROBAR, Reserva::CAMBIO_PEDIDO, Reserva::CONFIRMADA] as $e)
            <a href="{{ route('reservas.mias', ['estado' => $e]) }}" class="btn-pill-tipo {{ $estado === $e ? 'active' : '' }}">{{ Reserva::ESTADOS[$e] }} <span class="conteo-pill">{{ $conteos[$e] ?? 0 }}</span></a>
        @endforeach
        <a href="{{ route('reservas.mias', ['pasadas' => 1]) }}" class="btn-pill-tipo {{ $pasadas ? 'active' : '' }}">Pasadas</a>
    </nav>

    <div class="fichas-grid" data-fichas="reservas-mias">
        @if ($puede['crear'] && ! $pasadas)
            <a href="{{ route('reservas.create') }}" class="ficha-card ficha-create text-decoration-none">
                <i class="bi bi-plus-circle-fill" aria-hidden="true"></i>
                <span class="h6 fw-bold m-0 mt-2 titulo-crear">Nueva reserva</span>
            </a>
        @endif
        @forelse ($reservas as $r)
            @php
                $faltan = $r->aprobaciones->where('vigente', true)->where('estado', 'pendiente');
                $ordenes = $r->ordenes->where('estado', '!=', 'cancelada');
                $clase = $r->terminada() ? 'inactivo' : ($etiqueta[$r->estado] ?? 'pendiente');
            @endphp
            <div class="ficha-card" id="reserva-{{ $r->id }}" data-ficha data-estado="1" data-texto="{{ mb_strtolower($r->folioTexto().' '.$r->titulo.' '.$r->area->nombre()) }}">
                <div>
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <h2 class="ficha-title"><a href="{{ route('reservas.show', $r->id) }}" class="text-reset text-decoration-none">{{ $r->titulo }}</a></h2>
                        <span class="etiqueta-estado {{ $clase }} text-nowrap">{{ $r->estadoTexto() }}</span>
                    </div>
                    <div class="ficha-subtitulo">{{ $r->folioTexto() }}</div>
                    <ul class="list-unstyled small text-muted mt-2 pt-2 border-top m-0">
                        <li><i class="bi bi-door-open me-1" aria-hidden="true"></i> {{ $r->area->nombre() }} · {{ $r->area->sede?->nombre }}</li>
                        <li><i class="bi bi-calendar-event me-1" aria-hidden="true"></i> {{ $textos->cuando($r) }}</li>
                        <li><i class="bi bi-people me-1" aria-hidden="true"></i> {{ $r->asistentes }} personas · {{ $r->para_cliente ? 'Cliente' : 'Uso interno' }}</li>
                    </ul>
                    @if ($r->estado === Reserva::CAMBIO_PEDIDO)
                        <span class="ficha-dato"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Te piden un cambio</span>
                    @elseif ($faltan->isNotEmpty())
                        <span class="ficha-dato"><i class="bi bi-hourglass-split" aria-hidden="true"></i> Falta aprobar: {{ $faltan->map(fn ($a) => $a->tipo === 'area' ? 'área' : $a->departamento?->nombre)->join(', ') }}</span>
                    @elseif ($ordenes->isNotEmpty() && $r->estado === Reserva::CONFIRMADA)
                        <span class="ficha-dato"><i class="bi bi-clipboard-check" aria-hidden="true"></i> Servicios listos: {{ $ordenes->where('estado', 'lista')->count() }} de {{ $ordenes->count() }}</span>
                    @endif
                </div>
                <div class="ficha-footer">
                    <span class="small text-muted">{{ $r->para_cliente ? \App\Services\Reservas\Cotizador::dinero($r->total, $r->moneda) : 'Sin costo' }}</span>
                    <div class="d-flex gap-2">
                        <a href="{{ route('reservas.show', $r->id) }}" class="btn-icono ver" title="Ver" aria-label="Ver {{ $r->titulo }}"><i class="bi bi-eye" aria-hidden="true"></i></a>
                        @if ($r->activa() && ! $r->yaEmpezo())
                            <a href="{{ route('reservas.edit', $r->id) }}" class="btn-icono editar" title="Modificar" aria-label="Modificar {{ $r->titulo }}"><i class="bi bi-pencil-square" aria-hidden="true"></i></a>
                        @endif
                        <a href="{{ route('reservas.imprimir', $r->id) }}" class="btn-icono imprimir-hoja" target="_blank" rel="noopener" title="Imprimir" aria-label="Imprimir {{ $r->titulo }}"><i class="bi bi-printer" aria-hidden="true"></i></a>
                    </div>
                </div>
            </div>
        @empty
            <div class="tarjeta estado-vacio">
                <div class="icono"><i class="bi bi-calendar2-week" aria-hidden="true"></i></div>
                <p class="fw-semibold mb-1">No tienes reservas {{ $pasadas ? 'pasadas' : ($estado ? 'en «'.Reserva::ESTADOS[$estado].'»' : 'próximas') }}.</p>
            </div>
        @endforelse
    </div>
    <p class="text-muted text-center p-4" data-sin-resultados="reservas-mias" hidden><i class="bi bi-search me-2" aria-hidden="true"></i>No hay reservas que coincidan con tu búsqueda.</p>
    <div class="mt-3">{{ $reservas->links() }}</div>
</div>
@endsection
