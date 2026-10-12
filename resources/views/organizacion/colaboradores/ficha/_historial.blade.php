@use('App\Models\EvaluacionCandidato')
@use('App\Models\Candidato')
@use('App\Models\Acceso')
@use('App\Http\Controllers\RecursosHumanos\ExpedienteController')
{{-- Pestaña «Historial»: altas, bajas, reingresos y cambios del registro, su paso por Candidatos y lo último que registró la operación (cada bloque solo si puede ver ese módulo). --}}
<div class="row g-3">
    <div class="col-lg-6">
        <section class="tarjeta p-4 h-100" aria-labelledby="t-movimientos">
            <h2 id="t-movimientos" class="h6 fw-bold"><i class="bi bi-clock-history me-2 text-secondary" aria-hidden="true"></i>Movimientos del registro</h2>
            @if ($c->creado_por && isset($autores[$c->creado_por]))
                <div class="texto-traza"><i class="bi bi-plus-circle" aria-hidden="true"></i> Creado por {{ $autores[$c->creado_por] }} · @fecha($c->created_at)</div>
            @endif
            @if ($c->actualizado_por && isset($autores[$c->actualizado_por]) && $c->updated_at?->ne($c->created_at))
                <div class="texto-traza"><i class="bi bi-clock-history" aria-hidden="true"></i> Editado por {{ $autores[$c->actualizado_por] }} · @fecha($c->updated_at)</div>
            @endif
            @if ($c->validado_en)
                <div class="texto-traza"><i class="bi bi-patch-check" aria-hidden="true"></i> Validado por Recursos Humanos{{ isset($autores[$c->validado_por]) ? ' ('.$autores[$c->validado_por].')' : '' }} · @fecha($c->validado_en)</div>
            @endif
            @if ($c->fusionado_en_id && $c->fusionadoEn)
                <div class="texto-traza"><i class="bi bi-arrow-left-right" aria-hidden="true"></i> Era un duplicado: se unió con #{{ $c->fusionadoEn->num_empleado }} {{ $c->fusionadoEn->nombreCompleto() }}.</div>
            @endif
            @if ($movimientos->isNotEmpty())
                <ul class="lista-checklist mt-2" aria-label="Movimientos del registro">
                    @foreach ($movimientos as $m)
                        @php [$texto, $icono] = ExpedienteController::MOVIMIENTOS[$m->evento] ?? [$m->evento, 'bi-dot']; @endphp
                        <li class="{{ $m->evento === 'colaboradores.desactivado' ? 'falta' : 'hecho' }}">
                            <i class="bi {{ $icono }}" aria-hidden="true"></i>
                            <span>{{ $texto }}{{ $m->autor ? ' · '.$m->autor : '' }}</span>
                            <small>@fecha($m->creado_en)</small>
                        </li>
                    @endforeach
                </ul>
            @else
                <p class="small text-muted mt-2 mb-0">Sin movimientos registrados desde su alta.</p>
            @endif
            @php $aliasHistorial = \App\Services\Auditoria\LectorAuditoria::alias(\App\Models\Colaborador::class); @endphp
            @if ($aliasHistorial && auth()->user()?->can('auditoria.ver'))
                <p class="texto-traza mt-2 mb-0"><a href="{{ route('auditoria.index', ['registro' => $aliasHistorial, 'id' => $c->id]) }}">Ver el detalle en la Bitácora de auditoría</a></p>
            @endif
        </section>
    </div>

    @if ($ficha !== null || $postulacion !== null)
        <div class="col-lg-6">
            <section class="tarjeta p-4 h-100" aria-labelledby="t-candidato">
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
</div>
