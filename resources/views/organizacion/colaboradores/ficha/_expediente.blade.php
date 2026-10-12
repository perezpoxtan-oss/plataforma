{{-- Pestaña «Expediente»: datos personales y documentos de contratación. Solo existe con el permiso de datos personales (la ficha no la muestra sin él). --}}
<div class="row g-3">
    <div class="col-lg-6">
        <section class="tarjeta p-4 h-100" aria-labelledby="t-legales">
            <h2 id="t-legales" class="h6 fw-bold"><i class="bi bi-shield-lock me-2 text-secondary" aria-hidden="true"></i>Datos legales y de contacto</h2>
            <dl class="lista-datos-expediente">
                <dt>CURP</dt><dd>{{ $c->curp ?: '—' }}</dd>
                <dt>RFC</dt><dd>{{ $c->rfc ?: '—' }}</dd>
                <dt>NSS</dt><dd>{{ $c->nss ?: '—' }}</dd>
                <dt>Fecha de nacimiento</dt><dd>{{ $c->fecha_nacimiento?->format('d/m/Y') ?? '—' }}</dd>
                <dt>Estado de nacimiento</dt><dd>{{ $c->lugar_nacimiento ?: '—' }}</dd>
                <dt>Nacionalidad</dt><dd>{{ $c->nacionalidad ?: '—' }}</dd>
                <dt>Correo personal</dt><dd>{{ $c->correo_personal ?: '—' }}</dd>
                <dt>Dirección</dt><dd>{{ $c->direccion_completa ?: '—' }}</dd>
            </dl>
            <p class="texto-traza mb-0"><i class="bi bi-lock-fill me-1" aria-hidden="true"></i>Son datos personales: no los compartas fuera de Recursos Humanos. Se corrigen con «Editar» en <a href="{{ route('colaboradores.index', ['q' => $c->num_empleado]) }}">Colaboradores</a>.</p>
        </section>
    </div>

    <div class="col-lg-6">
        <section class="tarjeta p-4 h-100" id="documentos" aria-labelledby="t-documentos">
            <h2 id="t-documentos" class="h6 fw-bold"><i class="bi bi-paperclip me-2 text-secondary" aria-hidden="true"></i>Documentos de contratación</h2>
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
        </section>
    </div>
</div>
