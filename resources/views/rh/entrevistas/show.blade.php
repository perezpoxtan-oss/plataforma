@extends('layouts.app')

@section('titulo', 'Entrevistar')

@section('contenido')
@use('App\Models\Candidato')
@php
    $mesTexto = fn (?string $m) => $m ? substr($m, 5, 2).'/'.substr($m, 0, 4) : null;
    $siNoTexto = fn ($v) => $v === null ? '—' : ($v ? 'Sí' : 'No');
    $reabrir = old('_dialogo') === 'entrevistar';
@endphp
<div class="pantalla-entrevistar">
    @include('administracion.partes.avisos')

    <a href="{{ route('entrevistas.index') }}" class="enlace-volver"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Entrevistas</a>

    <section class="tarjeta cabeza-candidato">
        <div class="d-flex flex-wrap gap-2 align-items-center mb-1">
            <span class="pastilla-etapa etapa-{{ Candidato::COLORES[$p->etapa] ?? 'gris' }}">{{ $p->etiquetaEtapa() }}</span>
            <span class="pastilla-origen"><i class="bi bi-chat-square-text me-1" aria-hidden="true"></i>{{ $p->numeroTexto() }} entrevista</span>
        </div>
        <h1 class="nombre-candidato">{{ $c->nombre_completo }}</h1>
        <div class="datos-candidato">
            <span><i class="bi bi-megaphone" aria-hidden="true"></i> {{ $p->titulo() }}</span>
            @if ($p->departamento)<span><i class="bi bi-diagram-2" aria-hidden="true"></i> {{ $p->departamento->nombre }}</span>@endif
            <span><i class="bi bi-geo-alt" aria-hidden="true"></i> {{ $p->sede?->nombre }}</span>
            <span><i class="bi bi-calendar-event" aria-hidden="true"></i> Cita: {{ $textoCita ?: 'sin fecha' }}{{ $p->cita_lugar ? ' · '.$p->cita_lugar : '' }}</span>
        </div>
        @if ($manual)
            <p class="small mt-2 mb-0"><a href="{{ $manual }}"><i class="bi bi-question-circle me-1" aria-hidden="true"></i>¿Cómo entrevistar, evaluar y elegir?</a></p>
        @endif
    </section>

    <div class="rejilla-ficha-candidato">
        <div class="d-flex flex-column gap-3">
            {{-- Resumen del candidato: sin datos oficiales, domicilio ni contacto --}}
            <section class="tarjeta p-4" aria-labelledby="t-resumen">
                <h2 id="t-resumen" class="h5 fw-bold"><i class="bi bi-file-person me-2 text-success" aria-hidden="true"></i>Resumen del candidato</h2>
                <dl class="cv-datos">
                    <dt>Vacante</dt><dd>{{ $p->titulo() }}</dd>
                    <dt>Escolaridad</dt><dd>{{ $c->escolaridadMaxima() ?? '—' }}</dd>
                    <dt>Experiencia</dt><dd>{{ $c->anosExperiencia() }} año(s)</dd>
                    <dt>Puede empezar</dt><dd>{{ Candidato::DISPONIBILIDAD[$c->disponibilidad] ?? '—' }}{{ $c->fecha_inicio_posible ? ' · '.$c->fecha_inicio_posible->format('d/m/Y') : '' }}{{ $c->disponibilidad_notas ? ' · '.$c->disponibilidad_notas : '' }}</dd>
                    <dt>Rolar turnos</dt><dd>{{ $siNoTexto($c->rolar_turnos) }}</dd>
                    <dt>Sueldo que espera</dt><dd>{{ $c->pretension !== null ? '$'.number_format((float) $c->pretension, 2).' al mes' : '—' }}</dd>
                    <dt>Idiomas</dt><dd>{{ $c->idiomas ?? '—' }}</dd>
                    <dt>Habilidades</dt><dd>{{ $c->habilidades ?? '—' }}</dd>
                </dl>
                <h3 class="subtitulo-cv">Empleos anteriores</h3>
                @forelse ((array) $c->experiencia as $e)
                    <p class="renglon-cv"><strong>{{ $e['empresa'] ?? '—' }}</strong>{{ ! empty($e['puesto']) ? ' · '.$e['puesto'] : '' }}
                        @if (! empty($e['ingreso'])) · {{ $mesTexto($e['ingreso']) }} a {{ $mesTexto($e['salida'] ?? null) ?? 'la fecha' }}@endif
                        {{ isset($e['anos']) && $e['anos'] !== null ? ' · '.$e['anos'].' año(s)' : '' }}</p>
                @empty
                    <p class="renglon-cv text-muted">Sin capturar.</p>
                @endforelse
                <p class="texto-traza mt-3 mb-0"><i class="bi bi-lock me-1" aria-hidden="true"></i>Los datos oficiales, el domicilio y el contacto del candidato solo los ve Recursos Humanos.</p>
                @if ($cv)
                    <a href="{{ route('entrevistas.cv', $p->id) }}" class="btn-secundario-rh mt-3" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf me-1" aria-hidden="true"></i>Ver su CV (PDF)</a>
                @endif
            </section>
        </div>

        <div class="d-flex flex-column gap-3">
            <section class="tarjeta p-4" aria-labelledby="t-evaluaciones">
                <h2 id="t-evaluaciones" class="h6 fw-bold"><i class="bi bi-clipboard-check me-2 text-primary" aria-hidden="true"></i>Evaluaciones</h2>
                @forelse ($evaluaciones as $e)
                    @include('rh.candidatos._evaluacion', ['e' => $e])
                @empty
                    <p class="small text-muted mb-0">Sin evaluaciones registradas.</p>
                @endforelse
            </section>

            @if ($puedeEvaluar)
                <section class="tarjeta p-4" aria-labelledby="t-evaluar">
                    <h2 id="t-evaluar" class="h5 fw-bold"><i class="bi bi-star-half me-2 text-warning" aria-hidden="true"></i>Tu evaluación</h2>
                    <form action="{{ route('entrevistas.evaluar', $p->id) }}" method="POST" autocomplete="off" data-form-evaluacion
                          data-confirmar-elegir="¿Elegir a {{ $c->nombre_completo }}? Recursos Humanos sigue con los documentos y el contrato.">
                        @csrf
                        <input type="hidden" name="_dialogo" value="entrevistar">
                        @if ($reabrir && $errors->any())
                            <div class="alert alert-danger small py-2 px-3" role="alert"><i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>Revisa tu evaluación:
                                <ul class="mb-0 ps-3">@foreach ($errors->all() as $m)<li>{{ $m }}</li>@endforeach</ul>
                            </div>
                        @endif
                        <p class="small text-muted">La decisión de elegir es tuya. <strong>Elegir</strong> lo deja listo para que Recursos Humanos lo contrate; con <strong>Considerar</strong>, <strong>Segunda entrevista</strong> o <strong>Rechazar</strong>, Recursos Humanos decide cómo sigue y habla con el candidato.</p>
                        @include('rh.candidatos._evaluar-campos', ['tipo' => 'departamento', 'criterios' => $criterios, 'prefijo' => 'depto', 'conOld' => $reabrir])
                        <div class="dialogo-acciones">
                            <button type="submit" class="btn-verde">Guardar evaluación</button>
                        </div>
                    </form>
                </section>
            @elseif ($p->etapa === 'canalizado')
                <p class="small text-muted">Solo la persona asignada (o su delegado) evalúa esta entrevista.</p>
            @else
                <div class="tarjeta p-3 small"><i class="bi bi-check2-circle me-1 text-success" aria-hidden="true"></i>Esta entrevista ya se evaluó. Recursos Humanos sigue con el candidato.</div>
            @endif
        </div>
    </div>
</div>
@endsection
