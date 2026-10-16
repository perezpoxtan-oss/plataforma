@extends('layouts.app')

@section('titulo', 'Reservas de áreas')

@section('contenido')
<div class="tema-verde">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    @if ($sinEmpresa)
        <div class="encabezado-pantalla mb-4">
            <div class="icono"><i class="bi bi-calendar-check text-success" aria-hidden="true"></i></div>
            <div><h1>Reservas de áreas</h1><p>Salones, salas y terrazas de cada sede.</p></div>
        </div>
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-buildings" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong> para ver sus reservas.</p>
        </div>
    @else
        @include('operacion.reservas._nav', ['titulo' => 'Reservas de áreas', 'descripcion' => 'Elige una sede para ver sus áreas reservables.',
            'actual' => 'areas', 'buscar' => $sedes->count() > 1 ? ['reservas-sedes', 'Buscar sede...'] : null])

        @if ($sedes->isEmpty())
            <div class="tarjeta estado-vacio">
                <div class="icono"><i class="bi bi-door-closed" aria-hidden="true"></i></div>
                <p class="fw-semibold mb-1">Todavía no hay áreas que se puedan reservar.</p>
                <p class="text-muted small m-0">
                    @if ($puede['configurar'])
                        En <a href="{{ route('reservas.configuracion') }}">Configuración</a> marca como «Reservable» un salón o sala de Zonas y edificios.
                    @else
                        Pide a quien administra las reservas que marque como «Reservable» un salón o sala.
                    @endif
                </p>
            </div>
        @else
            <div class="fichas-grid" data-fichas="reservas-sedes">
                @foreach ($sedes as $s)
                    @php $r = $resumen[$s->id]; @endphp
                    <div class="ficha-card" data-ficha data-estado="1" data-texto="{{ mb_strtolower($s->nombre.' '.$s->codigo) }}">
                        <div>
                            <h2 class="h5 fw-bold m-0"><i class="bi bi-geo-alt text-success me-2" aria-hidden="true"></i>{{ $s->nombre }}
                                @if ($s->codigo)<span class="text-muted small fw-semibold">({{ $s->codigo }})</span>@endif
                            </h2>
                            <ul class="list-unstyled small text-muted mt-2 pt-2 border-top m-0">
                                <li><i class="bi bi-door-open me-1" aria-hidden="true"></i> {{ $r['areas'] }} {{ $r['areas'] === 1 ? 'área reservable' : 'áreas reservables' }}</li>
                                <li><i class="bi bi-calendar-event me-1" aria-hidden="true"></i> {{ $r['hoy'] }} {{ $r['hoy'] === 1 ? 'reserva hoy' : 'reservas hoy' }}</li>
                                <li><i class="bi bi-patch-check me-1" aria-hidden="true"></i> {{ $r['por_aprobar'] }} por aprobar</li>
                            </ul>
                        </div>
                        <a href="{{ route('reservas.index', ['sede' => $s->id]) }}" class="btn-entrar" aria-label="Entrar a {{ $s->nombre }}"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> Entrar a la sede</a>
                    </div>
                @endforeach
            </div>
            <p class="text-muted text-center p-4" data-sin-resultados="reservas-sedes" hidden><i class="bi bi-search me-2" aria-hidden="true"></i>No hay sedes que coincidan con tu búsqueda.</p>
        @endif
    @endif
</div>
@endsection
