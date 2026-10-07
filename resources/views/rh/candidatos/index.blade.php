@extends('layouts.app')
@use('App\Models\Candidato')

@section('titulo', 'Candidatos')

@section('contenido')
<div class="pantalla-candidatos">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    @if ($sinEmpresa)
        <div class="encabezado-pantalla mb-4">
            <div class="icono"><i class="bi bi-person-workspace text-success" aria-hidden="true"></i></div>
            <div><h1>Candidatos</h1><p>Solicitudes de empleo y su avance.</p></div>
        </div>
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-buildings" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong> para ver sus candidatos.</p>
        </div>
    @else
        @php
            $hayFiltros = $filtros['q'] !== '' || $filtros['etapa'] !== '' || $filtros['sede'] > 0 || $filtros['departamento'] > 0;
            $enProceso = collect(Candidato::ABIERTAS)->sum(fn ($e) => (int) ($conteos[$e] ?? 0));
            $base = array_filter(['q' => $filtros['q'], 'sede' => $filtros['sede'] ?: null, 'departamento' => $filtros['departamento'] ?: null]);
            $reabrir = old('_dialogo') === 'candidato';
        @endphp

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
            <div class="encabezado-pantalla m-0">
                <div class="icono"><i class="bi bi-person-workspace text-success" aria-hidden="true"></i></div>
                <div>
                    <h1>Candidatos</h1>
                    <p>Solicitudes de empleo: CV, etapas y contratación.</p>
                    <p class="texto-padron-de"><i class="bi bi-building-check" aria-hidden="true"></i> Candidatos de: <strong>{{ $empresaNombre }}</strong></p>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <a href="{{ route('recepcion.index') }}" class="btn-secundario-rh"><i class="bi bi-person-check me-1" aria-hidden="true"></i>Recepción</a>
                @if ($puede['exportar'] && $lista->total() > 0)
                    <a href="{{ route('candidatos.exportar', request()->query()) }}" class="btn-secundario-rh"><i class="bi bi-file-earmark-spreadsheet me-1" aria-hidden="true"></i>Exportar a Excel</a>
                @endif
                @if ($puede['crear'] && $sedesAlta->isNotEmpty())
                    <button type="button" class="btn-verde btn-accion-rh" data-abrir-dialogo="dialogoCandidato"><i class="bi bi-person-plus-fill me-1" aria-hidden="true"></i>Nuevo candidato</button>
                @endif
            </div>
        </div>

        <form method="GET" action="{{ route('candidatos.index') }}" class="filtros-rh" role="search" data-autoenviar>
            <input type="hidden" name="etapa" value="{{ $filtros['etapa'] }}">
            <div class="buscador">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="search" name="q" value="{{ $filtros['q'] }}" maxlength="100" placeholder="Buscar por nombre o vacante..." aria-label="Buscar candidato">
            </div>
            @if ($sedes->count() > 1)
                <select name="sede" class="filtro-select" aria-label="Filtrar por sede" data-enviar-al-cambiar>
                    <option value="">Todas las sedes</option>
                    @foreach ($sedes as $s)
                        <option value="{{ $s->id }}" @selected($filtros['sede'] === $s->id)>{{ $s->nombre }}</option>
                    @endforeach
                </select>
            @endif
            <select name="departamento" class="filtro-select" aria-label="Filtrar por departamento" data-enviar-al-cambiar>
                <option value="">Todos los departamentos</option>
                @foreach ($departamentos as $d)
                    <option value="{{ $d->id }}" @selected($filtros['departamento'] === $d->id)>{{ $d->nombre }}</option>
                @endforeach
            </select>
            @if ($hayFiltros)
                <a href="{{ route('candidatos.index') }}" class="btn-limpiar-filtros">Limpiar</a>
            @endif
        </form>

        <nav class="pildoras-pases" aria-label="Etapas">
            <a href="{{ route('candidatos.index', $base) }}" class="btn-pill-tipo {{ $filtros['etapa'] === '' ? 'active' : '' }}">Todos ({{ $conteos->sum() }})</a>
            <a href="{{ route('candidatos.index', $base + ['etapa' => 'en_proceso']) }}" class="btn-pill-tipo {{ $filtros['etapa'] === 'en_proceso' ? 'active' : '' }}">En proceso ({{ $enProceso }})</a>
            @foreach (Candidato::ETAPAS as $clave => $texto)
                <a href="{{ route('candidatos.index', $base + ['etapa' => $clave]) }}" class="btn-pill-tipo {{ $filtros['etapa'] === $clave ? 'active' : '' }}">{{ $texto }} ({{ (int) ($conteos[$clave] ?? 0) }})</a>
            @endforeach
        </nav>

        <div class="fichas-grid fichas-candidatos">
            @forelse ($lista as $c)
                <a href="{{ route('candidatos.show', $c->id) }}" class="ficha-card ficha-candidato">
                    <div>
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                            <span class="pastilla-etapa etapa-{{ Candidato::COLORES[$c->etapa] ?? 'gris' }}">{{ $c->etiquetaEtapa() }}</span>
                            @if ($c->autocaptura_pendiente)
                                <span class="pastilla-revisar"><i class="bi bi-phone me-1" aria-hidden="true"></i>Por revisar</span>
                            @endif
                        </div>
                        <h2 class="ficha-title">{{ $c->nombre_completo }}</h2>
                        <div class="datos-candidato">
                            <span><i class="bi bi-briefcase" aria-hidden="true"></i> {{ $c->puestoVisible() ?? 'Puesto sin definir' }}</span>
                            @if ($c->departamento)<span><i class="bi bi-diagram-2" aria-hidden="true"></i> {{ $c->departamento->nombre }}</span>@endif
                            @if ($sedes->count() > 1)<span><i class="bi bi-geo-alt" aria-hidden="true"></i> {{ $c->sede?->nombre }}</span>@endif
                            <span><i class="bi bi-clock" aria-hidden="true"></i> Llegó: @fecha($c->llegada_en ?? $c->created_at) · {{ Candidato::ORIGENES[$c->origen] ?? $c->origen }}</span>
                        </div>
                        <div class="texto-traza mt-2"><i class="bi bi-plus-circle" aria-hidden="true"></i> Creado por {{ $c->registradoPor?->name ?? 'el candidato (kiosco)' }} · @fecha($c->created_at)</div>
                        @if ($c->editadoPor && $c->updated_at?->ne($c->created_at))
                            <div class="texto-traza"><i class="bi bi-clock-history" aria-hidden="true"></i> Editado por {{ $c->editadoPor->name }} · @fecha($c->updated_at)</div>
                        @endif
                    </div>
                    <span class="ver-ficha-candidato">Abrir ficha <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
                </a>
            @empty
                <div class="tarjeta estado-vacio">
                    <div class="icono"><i class="bi bi-person-workspace" aria-hidden="true"></i></div>
                    <p class="text-muted small m-0">
                        @if ($hayFiltros)
                            Sin candidatos con esos filtros.
                        @else
                            Todavía no hay candidatos. Llegan solos cuando la caseta registra a alguien que viene a Recursos Humanos como candidato. @if ($puede['crear'])También puedes capturarlo con <strong>Nuevo candidato</strong>.@endif
                        @endif
                    </p>
                </div>
            @endforelse
        </div>
        <div class="mt-4">{{ $lista->onEachSide(1)->links('pagination::bootstrap-5') }}</div>

        @if ($puede['crear'] && $sedesAlta->isNotEmpty())
            <dialog id="dialogoCandidato" class="dialogo ancho" aria-labelledby="titulo-candidato" @if ($reabrir) data-abrir-al-cargar @endif>
                <div class="dialogo-cabecera">
                    <h2 id="titulo-candidato"><i class="bi bi-person-plus-fill me-2 text-success" aria-hidden="true"></i>Nuevo candidato</h2>
                    <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
                <div class="dialogo-cuerpo">
                    <form action="{{ route('candidatos.store') }}" method="POST" autocomplete="off" data-form-cv>
                        @csrf
                        <input type="hidden" name="_dialogo" value="candidato">
                        @if ($reabrir && $errors->any())
                            <div class="alert alert-danger small py-2 px-3" role="alert"><i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>Revisa los datos:
                                <ul class="mb-0 ps-3">@foreach ($errors->all() as $m)<li>{{ $m }}</li>@endforeach</ul>
                            </div>
                        @endif
                        <p class="small text-muted">Captura lo esencial; lo demás se completa después en su ficha. Si el candidato está aquí, también puede llenarlo él mismo en el <strong>kiosco</strong>.</p>
                        @if ($sedesAlta->count() === 1)
                            <input type="hidden" name="sede_id" value="{{ $sedesAlta->first()->id }}">
                            <p class="linea-empresa mb-3"><i class="bi bi-geo-alt-fill me-1" aria-hidden="true"></i>Sede: <strong>{{ $sedesAlta->first()->nombre }}</strong></p>
                        @else
                            <label class="campo-etiqueta" for="cand_sede">Sede *</label>
                            <select id="cand_sede" name="sede_id" class="campo" required>
                                <option value="">-- Seleccionar --</option>
                                @foreach ($sedesAlta as $s)
                                    <option value="{{ $s->id }}" @selected((string) old('sede_id') === (string) $s->id)>{{ $s->nombre }}</option>
                                @endforeach
                            </select>
                        @endif
                        @include('rh.candidatos._cv', ['c' => null, 'id' => 'nuevo_cv', 'kiosco' => false, 'conOld' => $reabrir, 'pidePrivacidad' => true])
                        <div class="dialogo-acciones">
                            <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                            <button type="submit" class="btn-verde">Guardar candidato</button>
                        </div>
                    </form>
                </div>
            </dialog>
        @endif
    @endif
</div>
@endsection
