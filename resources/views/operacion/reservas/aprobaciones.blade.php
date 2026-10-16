@extends('layouts.app')

@section('titulo', 'Reservas por aprobar')

@section('contenido')
<div class="tema-verde">
    @include('administracion.partes.avisos')
    @include('operacion.reservas._nav', ['titulo' => 'Por aprobar', 'descripcion' => 'Reservas que esperan tu visto bueno, como responsable del área o de los servicios de tu departamento.',
        'icono' => 'bi-patch-check', 'actual' => 'aprobaciones', 'buscar' => ['reservas-aprobar', 'Buscar reserva...']])

    @php
        $todasJuntas = $mias->toBase()->map(fn ($a) => [$a, 'mia'])->merge($todas->toBase()->map(fn ($a) => [$a, 'otra']));
    @endphp
    @if ($todas->isNotEmpty())
        <div class="pildoras-tipo" role="group" aria-label="Filtrar aprobaciones">
            <button type="button" class="btn-pill-tipo active" data-filtro-tipo="reservas-aprobar" data-valor="" aria-pressed="true">Todas <span class="conteo-pill">{{ $todasJuntas->count() }}</span></button>
            <button type="button" class="btn-pill-tipo" data-filtro-tipo="reservas-aprobar" data-valor="mia" aria-pressed="false">Te toca a ti <span class="conteo-pill">{{ $mias->count() }}</span></button>
            <button type="button" class="btn-pill-tipo" data-filtro-tipo="reservas-aprobar" data-valor="otra" aria-pressed="false">Otras de tus sedes <span class="conteo-pill">{{ $todas->count() }}</span></button>
        </div>
    @endif

    <div class="fichas-grid" data-fichas="reservas-aprobar">
        @forelse ($todasJuntas as [$a, $grupo])
            @php $r = $a->reserva; $reloj = $relojes[$a->id] ?? null; @endphp
            <div class="ficha-card" id="aprobacion-{{ $a->id }}" data-ficha data-estado="1" data-tipo="{{ $grupo }}"
                 data-texto="{{ mb_strtolower($r->folioTexto().' '.$r->titulo.' '.$r->area->nombre().' '.($a->departamento?->nombre ?? 'área')) }}">
                <div>
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <h2 class="ficha-title"><a href="{{ route('reservas.show', $r->id) }}" class="text-reset text-decoration-none">{{ $r->titulo }}</a></h2>
                        <span class="etiqueta-estado pendiente text-nowrap">{{ $a->tipo === 'area' ? 'Área' : $a->departamento?->nombre }}</span>
                    </div>
                    <div class="ficha-subtitulo">{{ $r->folioTexto() }} · reservó {{ $r->solicitante?->name ?? '—' }}</div>
                    <ul class="list-unstyled small text-muted mt-2 pt-2 border-top m-0">
                        <li><i class="bi bi-door-open me-1" aria-hidden="true"></i> {{ $r->area->nombre() }} · {{ $r->sede?->nombre }}</li>
                        <li><i class="bi bi-calendar-event me-1" aria-hidden="true"></i> {{ $textos->cuando($r) }}</li>
                        <li><i class="bi bi-people me-1" aria-hidden="true"></i> {{ $r->asistentes }} personas · {{ $r->para_cliente ? 'Cliente' : 'Uso interno' }}</li>
                        @if ($a->tipo === 'departamento')<li><i class="bi bi-cup-hot me-1" aria-hidden="true"></i> Piden: {{ $textos->serviciosDe($r, (int) $a->departamento_id) }}</li>@endif
                    </ul>
                    @if ($reloj)
                        <span class="reloj-vobo espera-{{ $reloj['nivel'] }} mt-2"><i class="bi {{ $reloj['nivel'] === 'normal' ? 'bi-clock' : 'bi-exclamation-triangle-fill' }}" aria-hidden="true"></i> <span>Esperando desde {{ $reloj['hace'] }} · <strong>{{ $reloj['etiqueta'] }}</strong></span></span>
                    @endif
                </div>
                <div class="ficha-footer">
                    <span class="small text-muted">{{ $grupo === 'mia' ? 'Te toca a ti' : 'Apruebas en la sede' }}</span>
                    <div class="d-flex gap-2">
                        <form method="POST" action="{{ route('reservas.aprobaciones.responder', $a->id) }}" class="m-0">
                            @csrf
                            <input type="hidden" name="respuesta" value="aprobar">
                            <input type="hidden" name="volver" value="bandeja">
                            <button type="submit" class="btn-icono reactivar" title="Aprobar" aria-label="Aprobar {{ $r->titulo }}"><i class="bi bi-check-lg" aria-hidden="true"></i></button>
                        </form>
                        <a href="{{ route('reservas.show', $r->id) }}#aprobaciones" class="btn-icono eliminar" title="Rechazar o pedir cambio" aria-label="Rechazar o pedir cambio en {{ $r->titulo }}"><i class="bi bi-x-lg" aria-hidden="true"></i></a>
                        <a href="{{ route('reservas.show', $r->id) }}" class="btn-icono ver" title="Ver" aria-label="Ver {{ $r->titulo }}"><i class="bi bi-eye" aria-hidden="true"></i></a>
                    </div>
                </div>
            </div>
        @empty
            <div class="tarjeta estado-vacio">
                <div class="icono"><i class="bi bi-check2-all" aria-hidden="true"></i></div>
                <p class="fw-semibold mb-1">Nada pendiente: ninguna reserva espera tu aprobación.</p>
                <p class="text-muted small m-0">Cuando alguien reserve un área que te toca o pida servicios de tu departamento, te llegará a la campana y aparecerá aquí.</p>
            </div>
        @endforelse
    </div>
    <p class="text-muted text-center p-4" data-sin-resultados="reservas-aprobar" hidden><i class="bi bi-search me-2" aria-hidden="true"></i>No hay reservas que coincidan con tu búsqueda.</p>
</div>
@endsection
