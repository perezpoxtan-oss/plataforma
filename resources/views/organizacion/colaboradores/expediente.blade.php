@extends('layouts.app')

@section('titulo', 'Expediente del colaborador')

@section('contenido')
@use('App\Models\EvaluacionCandidato')
@use('App\Models\Candidato')
@use('App\Models\Acceso')
{{-- Candidatos, fase 3: expediente del colaborador (ficha de detalle). Cada bloque se muestra solo si el usuario puede ver ese módulo. --}}
<div class="pantalla-candidato">
    @include('administracion.partes.avisos')

    <a href="{{ route('colaboradores.index') }}" class="enlace-volver"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Colaboradores</a>

    <div class="encabezado-pantalla mb-3">
        <div class="icono"><i class="bi bi-folder2-open text-success" aria-hidden="true"></i></div>
        <div>
            <h1>Expediente de {{ $c->nombreCompleto() }}</h1>
            <p>Núm. {{ $c->num_empleado }}{{ $c->sede ? ' · '.$c->sede->nombre : '' }}{{ $c->departamento ? ' · '.$c->departamento->nombre : '' }}{{ $c->puesto ? ' · '.$c->puesto->nombre : '' }}</p>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-6">
            {{-- Datos del colaborador --}}
            <section class="tarjeta p-4 h-100" aria-labelledby="t-datos">
                <h2 id="t-datos" class="h6 fw-bold"><i class="bi bi-person-badge me-2 text-secondary" aria-hidden="true"></i>Datos del colaborador</h2>
                <dl class="lista-datos-expediente">
                    <dt>Estado</dt><dd>{{ $c->activo ? 'Activo' : 'De baja' }}</dd>
                    <dt>Sede</dt><dd>{{ $c->sede?->nombre ?? '—' }}@if ($c->sedesAdicionales->isNotEmpty()) · también {{ $c->sedesAdicionales->pluck('nombre')->join(', ') }}@endif</dd>
                    <dt>Departamento</dt><dd>{{ $c->departamento?->nombre ?? '—' }}</dd>
                    <dt>Puesto</dt><dd>{{ $c->puesto?->nombre ?? '—' }}</dd>
                    <dt>Teléfono</dt><dd>{{ $c->telefono ?: '—' }}</dd>
                    <dt>Alta en la plataforma</dt><dd>@fecha($c->created_at, 'd/m/Y')</dd>
                    <dt>Cuenta de usuario</dt><dd>{{ $c->usuario ? $c->usuario->username.($c->usuario->activo ? '' : ' (dada de baja)') : 'Sin cuenta' }}</dd>
                </dl>
                <p class="texto-traza mb-0"><i class="bi bi-pencil me-1" aria-hidden="true"></i>Los datos se editan desde <a href="{{ route('colaboradores.index', ['q' => $c->num_empleado]) }}">Colaboradores</a>.</p>
            </section>
        </div>

        <div class="col-lg-6">
            {{-- Documentos de contratación (datos personales) --}}
            <section class="tarjeta p-4 h-100" id="documentos" aria-labelledby="t-documentos">
                <h2 id="t-documentos" class="h6 fw-bold"><i class="bi bi-paperclip me-2 text-secondary" aria-hidden="true"></i>Documentos de contratación</h2>
                @if (! $personales)
                    <p class="small text-muted m-0"><i class="bi bi-lock-fill me-1" aria-hidden="true"></i>Son datos personales: solo los ve quien puede consultar los datos personales de este colaborador.</p>
                @else
                    <p class="small text-muted">Los entregó como candidato y pasaron a su expediente al contratarlo. No los compartas fuera de Recursos Humanos.</p>
                    @forelse ($documentos as $doc)
                        <div class="documento-candidato">
                            @if ($doc->enPapel())
                                <i class="bi bi-journal-check" aria-hidden="true"></i>
                                <span class="d-grid">
                                    <strong>{{ $nombres[$doc->tipo] ?? $doc->tipo }}</strong>
                                    <span>Recibido en papel el {{ $doc->recibido_en?->format('d/m/Y') }} · lo recibió {{ $doc->registradoPor?->name ?? '' }}</span>
                                </span>
                            @else
                                <i class="bi {{ $doc->esImagen() ? 'bi-file-earmark-image' : 'bi-file-earmark-pdf' }}" aria-hidden="true"></i>
                                <a href="{{ route('colaboradores.expediente.documento', [$c->id, $doc->id]) }}" @if ($doc->esImagen()) target="_blank" rel="noopener" @endif>
                                    <strong>{{ $nombres[$doc->tipo] ?? $doc->tipo }}</strong>
                                    <span>{{ $doc->nombre_original }} · {{ $doc->tamano() }} · {{ $doc->origen === 'kiosco' ? 'lo subió el candidato' : ($doc->registradoPor?->name ?? '') }} · @fecha($doc->created_at)</span>
                                </a>
                            @endif
                        </div>
                    @empty
                        <p class="small text-muted m-0">Sin documentos en su expediente (no llegó por Candidatos o no se subió ninguno).</p>
                    @endforelse
                @endif
            </section>
        </div>

        @if ($ficha !== null || $postulacion !== null)
            <div class="col-12">
                {{-- Su paso por Candidatos --}}
                <section class="tarjeta p-4" aria-labelledby="t-candidato">
                    <h2 id="t-candidato" class="h6 fw-bold"><i class="bi bi-person-workspace me-2 text-secondary" aria-hidden="true"></i>Su paso por Candidatos</h2>
                    @if ($postulacion)
                        <p class="small mb-2">
                            Llegó por <strong>{{ Candidato::ORIGENES[$ficha?->origen ?? ''] ?? 'Candidatos' }}</strong>
                            @if ($ficha) el @fecha($ficha->llegada_en ?? $ficha->created_at, 'd/m/Y')@endif
                            @if ($postulacion->vacantePublicada) · vacante <strong>{{ $postulacion->vacantePublicada->titulo }}</strong>@elseif ($postulacion->vacante) · vacante <strong>{{ $postulacion->vacante }}</strong>@endif
                            @if ($postulacion->departamento) · {{ $postulacion->departamento->nombre }}@endif
                            @if ($postulacion->contratado_en) · contratado el @fecha($postulacion->contratado_en, 'd/m/Y')@endif
                            @if ($postulacion->contratoFirmado()) · contrato firmado el @fecha($postulacion->contrato_firmado_en, 'd/m/Y')@endif
                        </p>
                        @if ($postulacion->evaluaciones->isNotEmpty())
                            <ul class="lista-checklist" aria-label="Evaluaciones de entrevista">
                                @foreach ($postulacion->evaluaciones as $e)
                                    <li class="hecho">
                                        <i class="bi bi-star-fill" aria-hidden="true"></i>
                                        <span>{{ EvaluacionCandidato::TIPOS[$e->tipo] ?? $e->tipo }}{{ $e->numero > 1 ? ' (entrevista '.$e->numero.')' : '' }} · {{ number_format((float) $e->promedio, 1) }} de 5 · {{ $e->etiquetaResultado() }}{{ $e->evaluador ? ' · '.$e->evaluador->name : '' }}</span>
                                        <small>@fecha($e->created_at, 'd/m/Y')</small>
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    @endif
                    @if ($ficha)
                        <p class="texto-traza mb-0"><i class="bi bi-file-earmark-text me-1" aria-hidden="true"></i>Su solicitud de empleo y su historial completo siguen en <a href="{{ route('candidatos.show', $ficha->id) }}">su ficha de candidato</a>.</p>
                    @endif
                </section>
            </div>
        @endif

        {{-- Resúmenes operativos con enlace --}}
        @if ($accesos !== null)
            <div class="col-md-6">
                <section class="tarjeta p-4 h-100" aria-labelledby="t-accesos">
                    <h2 id="t-accesos" class="h6 fw-bold"><i class="bi bi-door-open me-2 text-secondary" aria-hidden="true"></i>Accesos recientes</h2>
                    @forelse ($accesos as $a)
                        <div class="small py-1 border-bottom">
                            @fecha($a->entrada_at) · {{ $a->sede?->nombre }} · {{ Acceso::ESTADOS[$a->estado] ?? $a->estado }}@if ($a->salida_at) · salió @fecha($a->salida_at, 'H:i')@endif
                        </div>
                    @empty
                        <p class="small text-muted m-0">Sin accesos registrados.</p>
                    @endforelse
                    <p class="texto-traza mt-2 mb-0"><a href="{{ route('accesos.index', ['pestana' => 'historial']) }}">Ver la Bitácora de accesos</a></p>
                </section>
            </div>
        @endif
        @if ($llaves !== null)
            <div class="col-md-6">
                <section class="tarjeta p-4 h-100" aria-labelledby="t-llaves">
                    <h2 id="t-llaves" class="h6 fw-bold"><i class="bi bi-key-fill me-2 text-secondary" aria-hidden="true"></i>Llaves en uso ({{ $llaves->count() }})</h2>
                    @forelse ($llaves as $p)
                        <div class="small py-1 border-bottom"><strong>{{ $p->llave?->nomenclatura }}</strong> {{ $p->llave?->descripcion }} · {{ $p->sede?->nombre }} · desde @fecha($p->created_at)</div>
                    @empty
                        <p class="small text-muted m-0">No tiene llaves prestadas.</p>
                    @endforelse
                    <p class="texto-traza mt-2 mb-0"><a href="{{ route('prestamo_llaves.index') }}">Ver Préstamo de llaves</a></p>
                </section>
            </div>
        @endif
        @if ($pases !== null)
            <div class="col-md-6">
                <section class="tarjeta p-4 h-100" aria-labelledby="t-pases">
                    <h2 id="t-pases" class="h6 fw-bold"><i class="bi bi-box-arrow-right me-2 text-secondary" aria-hidden="true"></i>Pases de salida</h2>
                    @forelse ($pases as $p)
                        <div class="small py-1 border-bottom"><a href="{{ route('pases-salida.show', $p->id) }}">{{ $p->folio }}</a> · {{ $p->insignia(false)[0] }} · {{ $p->etiquetaMotivo() }} · @fecha($p->created_at, 'd/m/Y')</div>
                    @empty
                        <p class="small text-muted m-0">Sin pases de salida.</p>
                    @endforelse
                    <p class="texto-traza mt-2 mb-0"><a href="{{ route('pases-salida.index') }}">Ver Pases de salida</a></p>
                </section>
            </div>
        @endif
        @if ($responsivas !== null)
            <div class="col-md-6">
                <section class="tarjeta p-4 h-100" aria-labelledby="t-responsivas">
                    <h2 id="t-responsivas" class="h6 fw-bold"><i class="bi bi-pen me-2 text-secondary" aria-hidden="true"></i>Resguardos en campo ({{ $responsivas->count() }})</h2>
                    @forelse ($responsivas as $r)
                        <div class="small py-1 border-bottom"><strong>{{ $r->folio }}</strong> · {{ $r->equipos_count }} equipo{{ $r->equipos_count === 1 ? '' : 's' }} · {{ $r->sede?->nombre }} · @fecha($r->created_at, 'd/m/Y')</div>
                    @empty
                        <p class="small text-muted m-0">No tiene equipos en resguardo.</p>
                    @endforelse
                    <p class="texto-traza mt-2 mb-0"><a href="{{ route('responsivas.index') }}">Ver Responsivas</a></p>
                </section>
            </div>
        @endif
        @if ($procedimientos !== null)
            <div class="col-md-6">
                <section class="tarjeta p-4 h-100" aria-labelledby="t-procedimientos">
                    <h2 id="t-procedimientos" class="h6 fw-bold"><i class="bi bi-book me-2 text-secondary" aria-hidden="true"></i>Procedimientos por leer ({{ $procedimientos->count() }})</h2>
                    @forelse ($procedimientos as $p)
                        <div class="small py-1 border-bottom"><a href="{{ route('procedimientos.show', $p->id) }}">{{ $p->clave }} · {{ $p->titulo }}</a></div>
                    @empty
                        <p class="small text-muted m-0">Al día: no le falta ningún acuse «Leí y entendí».</p>
                    @endforelse
                </section>
            </div>
        @endif
    </div>
</div>
@endsection
