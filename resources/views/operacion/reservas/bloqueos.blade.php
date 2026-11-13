@extends('layouts.app')

@section('titulo', 'Bloqueos de reservas')

@php use App\Services\Reservas\Disponibilidad; @endphp

@section('contenido')
<div class="tema-verde">
    @include('administracion.partes.avisos')
    @include('operacion.reservas._nav', ['titulo' => 'Bloqueos', 'descripcion' => 'Sitios que no se pueden reservar por un tiempo (mantenimiento, evento de dirección…). Bloquear una zona bloquea también sus espacios interiores, y al revés. En el calendario se ven como «Bloqueado».',
        'icono' => 'bi-lock', 'actual' => 'bloqueos', 'buscar' => ['reservas-bloqueos', 'Buscar bloqueo...']])

    <nav class="pildoras-tipo" aria-label="Filtrar bloqueos">
        <a href="{{ route('reservas.bloqueos') }}" class="btn-pill-tipo {{ $historial ? '' : 'active' }}">Vigentes y programados</a>
        @if ($historial || $enHistorial > 0)
            <a href="{{ route('reservas.bloqueos', ['ver' => 'historial']) }}" class="btn-pill-tipo {{ $historial ? 'active' : '' }}">Terminados y dados de baja <span class="conteo-pill">{{ $enHistorial }}</span></a>
        @endif
    </nav>

    <div class="fichas-grid" data-fichas="reservas-bloqueos">
        @unless ($historial)
            <a href="{{ route('reservas.bloqueos.create') }}" class="ficha-card ficha-create text-decoration-none">
                <i class="bi bi-plus-circle-fill" aria-hidden="true"></i>
                <span class="h6 fw-bold m-0 mt-2 titulo-crear">Bloquear un sitio</span>
                <span class="small text-muted mt-1">Sitio, desde, hasta y motivo</span>
            </a>
        @endunless
        @forelse ($bloqueos as $b)
            @php
                $z = $zona($b);
                $empezo = $b->desde->lessThanOrEqualTo(now());
                [$clase, $estado] = match (true) {
                    ! $b->activo => ['inactivo', 'Dado de baja'],
                    $b->hasta->lessThanOrEqualTo(now()) => ['inactivo', 'Terminado'],
                    $empezo => ['pendiente', 'Bloqueado ahora'],
                    default => ['activo', 'Programado'],
                };
                $n = (int) ($afectadas[$b->id] ?? 0);
            @endphp
            <div class="ficha-card" id="bloqueo-{{ $b->id }}" data-ficha data-estado="1" data-texto="{{ mb_strtolower($b->sitio().' '.$b->motivo.' '.($b->area?->rutaTexto() ?? '')) }}">
                <div>
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <h2 class="ficha-title"><i class="bi bi-lock-fill text-danger me-1" aria-hidden="true"></i>{{ $b->sitio() }}</h2>
                        <span class="etiqueta-estado {{ $clase }} text-nowrap">{{ $estado }}</span>
                    </div>
                    <div class="ficha-subtitulo">{{ $b->area?->rutaTexto() }} · {{ $b->sede?->nombre }}</div>
                    <ul class="list-unstyled small text-muted mt-2 pt-2 border-top m-0">
                        <li><i class="bi bi-calendar-event me-1" aria-hidden="true"></i> <strong>{{ ucfirst(Disponibilidad::rangoTexto($b->desde, $b->hasta, $z)) }}</strong></li>
                        <li><i class="bi bi-chat-left-text me-1" aria-hidden="true"></i> Motivo: {{ $b->motivo }}</li>
                        <li><i class="bi bi-person me-1" aria-hidden="true"></i> Lo bloqueó {{ $b->creadoPor?->name ?? '—' }} · @fecha($b->created_at)</li>
                        @if (! $b->activo)
                            <li><i class="bi bi-unlock me-1" aria-hidden="true"></i> Dado de baja por {{ $b->dadoDeBajaPor?->name ?? '—' }} · @fecha($b->dado_de_baja_en){{ $b->motivo_baja ? ' · «'.$b->motivo_baja.'»' : '' }}</li>
                        @endif
                    </ul>
                    @if ($n > 0)
                        <span class="ficha-dato"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> {{ $n === 1 ? '1 reserva afectada: quien la hizo debe moverla o darla de baja' : $n.' reservas afectadas: quienes las hicieron deben moverlas o darlas de baja' }}</span>
                    @endif
                </div>
                <div class="ficha-footer">
                    <a href="{{ route('reservas.calendario', ['area' => $b->reserva_area_id, 'sede' => $b->sede_id, 'fecha' => $b->desde->copy()->setTimezone($z)->toDateString()]) }}" class="btn-icono ver" title="Ver en el calendario" aria-label="Ver {{ $b->sitio() }} en el calendario"><i class="bi bi-calendar3" aria-hidden="true"></i></a>
                    @if ($b->activo && $b->hasta->greaterThan(now()) && ($gestiona[$b->id] ?? false))
                        <button type="button" class="btn-icono desbloquear" data-abrir-dialogo="dialogoBaja{{ $b->id }}" title="Dar de baja el bloqueo" aria-label="Dar de baja el bloqueo de {{ $b->sitio() }}"><i class="bi bi-unlock" aria-hidden="true"></i></button>
                    @endif
                </div>
            </div>
        @empty
            <div class="tarjeta estado-vacio">
                <div class="icono"><i class="bi bi-unlock" aria-hidden="true"></i></div>
                <p class="text-muted small m-0">{{ $historial ? 'Todavía no hay bloqueos terminados ni dados de baja.' : 'No hay sitios bloqueados: todo se puede reservar según su horario.' }}</p>
            </div>
        @endforelse
    </div>
    <p class="text-muted text-center p-4" data-sin-resultados="reservas-bloqueos" hidden><i class="bi bi-search me-2" aria-hidden="true"></i>No hay bloqueos que coincidan con tu búsqueda.</p>

    @foreach ($bloqueos as $b)
        @continue (! ($b->activo && $b->hasta->greaterThan(now()) && ($gestiona[$b->id] ?? false)))
        <dialog id="dialogoBaja{{ $b->id }}" class="dialogo" aria-labelledby="t-baja-{{ $b->id }}">
            <div class="dialogo-cabecera">
                <h2 id="t-baja-{{ $b->id }}"><i class="bi bi-unlock me-2" aria-hidden="true"></i>Dar de baja el bloqueo — {{ $b->sitio() }}</h2>
                <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </div>
            <div class="dialogo-cuerpo">
                <form method="POST" action="{{ route('reservas.bloqueos.baja', $b->id) }}">
                    @csrf
                    @method('PATCH')
                    <p class="small">El sitio se podrá reservar otra vez {{ Disponibilidad::rangoTexto($b->desde, $b->hasta, $zona($b)) }}. @if ((int) ($afectadas[$b->id] ?? 0) > 0)Las reservas afectadas dejan de estarlo y avisamos a quienes las hicieron.@endif</p>
                    <label class="campo-etiqueta" for="motivo_baja{{ $b->id }}">¿Por qué? (opcional)</label>
                    <input type="text" id="motivo_baja{{ $b->id }}" name="motivo_baja" class="campo" maxlength="255" placeholder="Ej. El mantenimiento terminó antes">
                    <div class="dialogo-acciones">
                        <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                        <button type="submit" class="btn-verde btn-accion-rh">Dar de baja</button>
                    </div>
                </form>
            </div>
        </dialog>
    @endforeach
</div>
@endsection
