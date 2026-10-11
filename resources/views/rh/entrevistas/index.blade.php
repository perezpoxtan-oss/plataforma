@extends('layouts.app')

@section('titulo', 'Entrevistas')

@section('contenido')
@use('App\Models\Candidato')
<div class="pantalla-entrevistas">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
        <div class="encabezado-pantalla m-0">
            <div class="icono"><i class="bi bi-chat-square-text text-primary" aria-hidden="true"></i></div>
            <div>
                <h1>Entrevistas</h1>
                <p>Candidatos que Recursos Humanos te canalizó: entrevístalos, evalúalos y elige.</p>
            </div>
        </div>
        @if (! $sinEmpresa && $manual)
            <a href="{{ $manual }}" class="btn-secundario-rh"><i class="bi bi-question-circle me-1" aria-hidden="true"></i>¿Cómo entrevistar y elegir?</a>
        @endif
    </div>

    @if ($sinEmpresa)
        <div class="tarjeta estado-vacio"><p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong>.</p></div>
    @else
        <section class="mb-4" aria-labelledby="t-por-evaluar">
            <h2 id="t-por-evaluar" class="titulo-seccion-rh"><i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>Por evaluar ({{ $pendientes->count() }})</h2>
            @forelse ($pendientes as $p)
                <a href="{{ route('entrevistas.show', $p->id) }}" class="tarjeta fila-entrevista">
                    <div class="cita-fila {{ $p->cita_ahora ? 'ahora' : ($p->cita_en?->isPast() ? 'pasada' : '') }}">
                        <i class="bi {{ $p->cita_ahora ? 'bi-person-check-fill' : 'bi-calendar-event' }}" aria-hidden="true"></i>
                        @foreach (explode(' ', $textoCita($p), 2) as $parte)<span class="d-block">{{ $parte }}</span>@endforeach
                    </div>
                    <div class="flex-grow-1 min-w-0">
                        <strong class="d-block">{{ $p->candidato?->nombre_completo }}</strong>
                        <span class="small text-muted">{{ $p->vacantePublicada?->titulo ?? $p->puesto?->nombre ?? $p->vacante ?? 'Sin vacante definida' }}{{ $p->departamento ? ' · '.$p->departamento->nombre : '' }} · {{ $p->sede?->nombre }} · {{ $p->numeroTexto() }} entrevista</span>
                        @if ($p->cita_lugar)<span class="d-block small"><i class="bi bi-geo-alt me-1" aria-hidden="true"></i>{{ $p->cita_lugar }}</span>@endif
                        @if ((int) $p->entrevistador_id !== auth()->id())<span class="d-block texto-traza">Por delegación de {{ $p->entrevistador?->name }}</span>@endif
                    </div>
                    <span class="btn-etapa principal">Entrevistar</span>
                </a>
            @empty
                <div class="tarjeta estado-vacio">
                    <div class="icono"><i class="bi bi-check2-circle" aria-hidden="true"></i></div>
                    <p class="text-muted small m-0">Nada pendiente. Cuando Recursos Humanos te canalice a un candidato, te llegará un aviso en la campana, en Mis pendientes y por correo.</p>
                </div>
            @endforelse
        </section>

        @if ($evaluadas->isNotEmpty())
            <section aria-labelledby="t-evaluadas">
                <h2 id="t-evaluadas" class="titulo-seccion-rh"><i class="bi bi-clipboard-check me-1" aria-hidden="true"></i>Ya evaluadas</h2>
                <div class="tarjeta p-0">
                    <div class="tabla-scroll">
                        <table class="tabla-metricas tabla-entrevistas">
                            <thead><tr><th scope="col">Candidato</th><th scope="col">Vacante</th><th scope="col">Tu evaluación</th><th scope="col">Etapa</th></tr></thead>
                            <tbody>
                                @foreach ($evaluadas as $p)
                                    @php $mia = $p->evaluaciones->where('tipo', 'departamento')->last(); @endphp
                                    <tr>
                                        <td><a href="{{ route('entrevistas.show', $p->id) }}">{{ $p->candidato?->nombre_completo }}</a></td>
                                        <td>{{ $p->vacantePublicada?->titulo ?? $p->vacante ?? '—' }}</td>
                                        <td>{{ $mia ? $mia->etiquetaResultado().' · '.$mia->promedioTexto() : '—' }}</td>
                                        <td><span class="pastilla-etapa etapa-{{ Candidato::COLORES[$p->etapa] ?? 'gris' }}">{{ $p->etiquetaEtapa() }}</span></td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </section>
        @endif
    @endif
</div>
@endsection
