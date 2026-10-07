@extends('layouts.app')

@section('titulo', 'Autorización')

@section('contenido')
@use('App\Models\Autorizacion')
@use('App\Models\Candidato')
@php $c = $a->candidato; $acc = $a->acceso; @endphp
<div class="pantalla-autorizacion">
    @include('administracion.partes.avisos')
    <a href="{{ route('autorizaciones.index') }}" class="enlace-volver"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Autorizaciones</a>

    @if ($confirmar && $puedeResponder)
        <div class="aviso-confirmar-correo"><i class="bi bi-envelope-check me-2" aria-hidden="true"></i>Viene del botón de tu correo: revisa y confirma tu respuesta abajo.</div>
    @endif

    <section class="tarjeta p-4">
        <div class="d-flex flex-column flex-md-row gap-3">
            @if ($acc?->foto_persona)
                <img class="foto-candidato" src="{{ route('accesos.foto-persona', $acc->id) }}" alt="Foto tomada en caseta">
            @endif
            <div class="flex-grow-1 min-w-0">
                <span class="pastilla-tipo-rh {{ $a->tipo === 'candidato' ? 'candidato' : 'visita' }}">{{ Autorizacion::TIPOS[$a->tipo] }}</span>
                <span class="pastilla-estado-aut estado-{{ $a->estado }}">{{ Autorizacion::ESTADOS[$a->estado] }}</span>
                <h1 class="nombre-candidato">{{ $a->titulo() }}</h1>
                <div class="datos-candidato">
                    <span><i class="bi bi-diagram-2" aria-hidden="true"></i> {{ $a->departamento?->nombre }}</span>
                    <span><i class="bi bi-geo-alt" aria-hidden="true"></i> {{ $a->sede?->nombre }}</span>
                    <span><i class="bi bi-stopwatch" aria-hidden="true"></i> {{ $a->pendiente() ? 'Esperando desde hace '.$a->minutosEspera().' min' : 'Respondida en '.$a->minutosEspera().' min' }}</span>
                </div>
                <p class="texto-traza mt-1 mb-0">Avisado @fecha($a->solicitada_en) · registró {{ $a->registradoPor?->name ?? '—' }}</p>
                @unless ($a->pendiente())
                    <p class="texto-traza mb-0">Respondió {{ $a->respondidaPor?->name ?? '—' }} ({{ Autorizacion::MEDIOS[$a->respuesta_medio] ?? $a->respuesta_medio }}) · @fecha($a->respondida_en){{ $a->comentario ? ' — «'.$a->comentario.'»' : '' }}</p>
                @endunless
            </div>
        </div>

        @if ($a->tipo === 'visita' && $acc)
            <dl class="cv-datos mt-3">
                <dt>Motivo</dt><dd>{{ \App\Models\Acceso::MOTIVOS[$acc->motivo_visita] ?? '—' }}</dd>
                <dt>Visita a</dt><dd>{{ $acc->persona_visita ?? $a->departamento?->nombre }}</dd>
                <dt>Viene de</dt><dd>{{ $acc->empresa_procedencia ?? '—' }}</dd>
                <dt>Llegó a caseta</dt><dd>@fecha($acc->entrada_at)</dd>
            </dl>
        @elseif ($c)
            {{-- Resumen del CV para el departamento (sin datos de contacto ni documentos) --}}
            <h2 class="subtitulo-cv mt-3">Resumen del candidato</h2>
            <dl class="cv-datos">
                <dt>Puesto</dt><dd>{{ $c->puestoVisible() ?? '—' }}</dd>
                <dt>Escolaridad</dt><dd>{{ $c->escolaridadMaxima() ?? '—' }}</dd>
                <dt>Experiencia</dt><dd>{{ $c->anosExperiencia() > 0 ? $c->anosExperiencia().' año(s)' : '—' }}</dd>
                <dt>Disponibilidad</dt><dd>{{ Candidato::DISPONIBILIDAD[$c->disponibilidad] ?? '—' }}</dd>
                <dt>Idiomas</dt><dd>{{ $c->idiomas ?? '—' }}</dd>
                <dt>Habilidades</dt><dd>{{ $c->habilidades ?? '—' }}</dd>
            </dl>
            @foreach ((array) $c->experiencia as $e)
                <p class="renglon-cv"><strong>{{ $e['empresa'] ?? '' }}</strong>{{ ! empty($e['puesto']) ? ' · '.$e['puesto'] : '' }}{{ isset($e['anos']) && $e['anos'] !== null ? ' · '.$e['anos'].' año(s)' : '' }}</p>
            @endforeach
            <p class="texto-traza">Recursos Humanos ya lo revisó y lo aprobó. Sus datos de contacto y documentos los guarda Recursos Humanos.</p>
        @endif

        @if ($puedeResponder)
            <form action="{{ route('autorizaciones.responder', $a->id) }}" method="POST" class="responder-autorizacion">
                @csrf
                <input type="hidden" name="medio" value="{{ $confirmar ? 'correo' : 'plataforma' }}">
                <label class="campo-etiqueta" for="aut_comentario">Comentario (opcional)</label>
                <textarea id="aut_comentario" name="comentario" class="campo" rows="2" maxlength="500" placeholder="{{ $a->tipo === 'visita' ? 'Ej. Que pase a la sala de juntas' : 'Ej. Que suba mañana a las 10:00' }}"></textarea>
                <div class="botones-autorizacion">
                    @foreach (array_keys(Autorizacion::RESPUESTAS[$a->tipo]) as $respuesta)
                        <button type="submit" name="respuesta" value="{{ $respuesta }}" class="btn-respuesta grande {{ $respuesta === 'rechazar' ? 'rechazar' : 'aceptar' }} {{ $confirmar === $respuesta ? 'sugerida' : '' }}">
                            <i class="bi {{ $respuesta === 'rechazar' ? 'bi-x-lg' : ($respuesta === 'autorizar' ? 'bi-door-open-fill' : 'bi-chat-square-text-fill') }} me-1" aria-hidden="true"></i>{{ $confirmar === $respuesta ? 'Confirmar: ' : '' }}{{ Autorizacion::BOTONES[$respuesta] }}
                        </button>
                    @endforeach
                </div>
            </form>
        @elseif ($a->pendiente())
            <p class="small text-muted mt-3 mb-0"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Responde el responsable del departamento (o su delegado).</p>
        @endif
    </section>
</div>
@endsection
