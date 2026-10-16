@extends('layouts.app')

@section('titulo', 'Reservas por aprobar')

@section('contenido')
<div class="tema-verde">
    @include('administracion.partes.avisos')
    @include('operacion.reservas._nav', ['titulo' => 'Por aprobar', 'descripcion' => 'Reservas que esperan tu visto bueno, como responsable del área o de los servicios de tu departamento.', 'icono' => 'bi-patch-check', 'actual' => 'aprobaciones'])

    @foreach (['mias' => ['Te toca a ti', $mias], 'todas' => ['Otras de tus sedes (puedes responderlas porque apruebas reservas)', $todas]] as $clave => [$tituloGrupo, $grupo])
        @continue($clave === 'todas' && $grupo->isEmpty())
        <h2 class="h6 fw-bold mt-3 mb-2">{{ $tituloGrupo }}</h2>
        <div class="res-lista">
            @forelse ($grupo as $a)
                @php $r = $a->reserva; $reloj = $relojes[$a->id] ?? null; @endphp
                <article class="res-tarjeta">
                    <div class="res-tarjeta-fila">
                        <div>
                            <div class="res-meta">{{ $a->tipo === 'area' ? 'Como responsable del área' : 'Servicios de '.$a->departamento?->nombre }} · {{ $r->folioTexto() }}</div>
                            <h3 class="mt-1"><a href="{{ route('reservas.show', $r->id) }}">{{ $r->titulo }}</a></h3>
                            <div class="res-meta">{{ $textos->resumen($r) }} · reservó {{ $r->solicitante?->name ?? '—' }}</div>
                            @if ($a->tipo === 'departamento')<div class="res-meta"><strong>Piden:</strong> {{ $textos->serviciosDe($r, (int) $a->departamento_id) }}</div>@endif
                            @if ($reloj)
                                <span class="reloj-vobo espera-{{ $reloj['nivel'] }} mt-1"><i class="bi {{ $reloj['nivel'] === 'normal' ? 'bi-clock' : 'bi-exclamation-triangle-fill' }}" aria-hidden="true"></i>
                                    Esperando desde {{ $reloj['hace'] }} · <strong>{{ $reloj['etiqueta'] }}</strong></span>
                            @endif
                        </div>
                        <div class="res-acciones">
                            <form method="POST" action="{{ route('reservas.aprobaciones.responder', $a->id) }}" class="m-0">
                                @csrf
                                <input type="hidden" name="respuesta" value="aprobar">
                                <input type="hidden" name="volver" value="bandeja">
                                <button type="submit" class="res-boton verde"><i class="bi bi-check2-circle" aria-hidden="true"></i>Aprobar</button>
                            </form>
                            <a href="{{ route('reservas.show', $r->id) }}" class="res-boton rojo"><i class="bi bi-x-octagon" aria-hidden="true"></i>Rechazar o pedir cambio</a>
                        </div>
                    </div>
                </article>
            @empty
                <div class="tarjeta estado-vacio">
                    <div class="icono"><i class="bi bi-check2-all" aria-hidden="true"></i></div>
                    <p class="fw-semibold mb-1">Nada pendiente: ninguna reserva espera tu aprobación.</p>
                    <p class="text-muted small m-0">Cuando alguien reserve un área que te toca o pida servicios de tu departamento, te llegará a la campana y aparecerá aquí.</p>
                </div>
            @endforelse
        </div>
    @endforeach
</div>
@endsection
