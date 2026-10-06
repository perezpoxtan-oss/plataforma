@extends('layouts.app')

@section('titulo', 'Recorridos de Protección Civil')

@section('contenido')
<div class="tema-recorridos-pc">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    @if ($sinEmpresa)
        <div class="encabezado-pantalla mb-4">
            <div class="icono"><i class="bi bi-clipboard-check-fill text-success" aria-hidden="true"></i></div>
            <div><h1>Recorridos de Protección Civil</h1><p>Ronda de inspección de equipos — actividad diaria, se completa en una sola sesión.</p></div>
        </div>
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-buildings" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong> para ver sus recorridos de Protección Civil.</p>
        </div>
    @else
        @php
            $conteo = $recorridos->countBy('estatus');
            $dialogo = old('_dialogo');
            $unaSede = $sedesAlta->count() === 1 ? (string) $sedesAlta->first()->id : '';
            $verTickets = auth()->user()->can('novedades.ver');
        @endphp

        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-3">
            <div class="encabezado-pantalla m-0">
                <div class="icono"><i class="bi bi-clipboard-check-fill text-success" aria-hidden="true"></i></div>
                <div>
                    <h1>Recorridos de Protección Civil</h1>
                    <p>Ronda de inspección de equipos — actividad diaria, se completa en una sola sesión.</p>
                    <p class="texto-padron-de"><i class="bi bi-building-check" aria-hidden="true"></i> Recorridos de: <strong>{{ $empresaNombre }}</strong></p>
                </div>
            </div>
            <div class="rpc-acciones-cabecera">
                <a href="{{ route('recorridos_pc.equipos.index') }}" class="btn-rpc btn-rpc-contorno"><i class="bi bi-shield-fill-check me-2" aria-hidden="true"></i>Catálogo de Equipos</a>
                @if ($puede['imprimir'])
                    <a href="{{ route('recorridos_pc.reporte', $periodo) }}" target="_blank" rel="noopener" class="btn-rpc btn-rpc-oscuro-contorno"><i class="bi bi-file-earmark-pdf-fill me-2" aria-hidden="true"></i>Reporte de Auditoría</a>
                @endif
                @if ($puede['crear'])
                    <button type="button" class="btn-rpc btn-rpc-verde" data-abrir-dialogo="dialogoNuevoRecorrido"><i class="bi bi-plus-circle-fill me-2" aria-hidden="true"></i>Nuevo Recorrido</button>
                @endif
            </div>
        </div>

        <div class="rpc-filtros">
            <div class="pildoras-tipo m-0" role="group" aria-label="Filtrar por estatus">
                <button type="button" class="btn-pill-tipo active" data-filtro-rpc-estatus="" aria-pressed="true">Todos <span class="conteo-pill">{{ $recorridos->count() }}</span></button>
                @foreach (\App\Models\RecorridoPc::ESTATUS as $clave => $texto)
                    <button type="button" class="btn-pill-tipo" data-filtro-rpc-estatus="{{ $clave }}" aria-pressed="false">{{ $texto }} <span class="conteo-pill">{{ $conteo[$clave] ?? 0 }}</span></button>
                @endforeach
            </div>
            <div class="rpc-filtros-campos">
                @if ($sedesFiltro->count() > 1)
                    <select class="filtro-select" aria-label="Filtrar por sede" data-filtro-rpc="sede">
                        <option value="">Todas las sedes</option>
                        @foreach ($sedesFiltro as $s)
                            <option value="{{ $s->id }}">{{ $s->nombre }}</option>
                        @endforeach
                    </select>
                @endif
                <div class="buscador">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="search" placeholder="Buscar por número, guardia, sede, zona..." aria-label="Buscar recorrido" data-filtro-rpc="texto">
                </div>
            </div>
        </div>

        <div class="fichas-grid rpc-fichas" data-lista-rpc>
            @forelse ($recorridos as $r)
                @php
                    $continuable = in_array($r->id, $continuables, true);
                    $texto = mb_strtolower(implode(' ', array_filter([$r->folio(), $r->numero, $r->sede?->nombre, $r->espacio?->nombre, $r->creador?->name, $r->etiquetaEstatus(), $r->novedad?->folio()])));
                @endphp
                <article class="ficha-card rpc-ficha estatus-{{ $r->estatus }}" id="recorrido-{{ $r->id }}" data-rpc data-estatus="{{ $r->estatus }}" data-sede="{{ $r->sede_id }}" data-texto="{{ $texto }}">
                    <div>
                        <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                            <span class="rpc-badge rpc-badge-{{ $r->estatus }}">{{ $r->etiquetaEstatus() }}</span>
                            <span class="small text-muted">@fecha($r->created_at)</span>
                        </div>
                        <h2 class="rpc-ficha-titulo">Recorrido {{ $r->folio() }}</h2>
                        @if ($sedesFiltro->count() > 1)
                            <div class="small fw-bold text-success mb-1"><i class="bi bi-building me-1" aria-hidden="true"></i>{{ $r->sede?->nombre }}</div>
                        @endif
                        @if ($r->espacio)
                            <div class="small mb-1"><i class="bi bi-geo-alt me-1 text-secondary" aria-hidden="true"></i>{{ $r->espacio->nombre }}</div>
                        @endif
                        <div class="small mb-1"><i class="bi bi-person-fill me-1 text-secondary" aria-hidden="true"></i>{{ $r->creador?->name ?? '—' }}</div>
                        <div class="small text-muted mb-1"><i class="bi bi-clipboard-check me-1" aria-hidden="true"></i>{{ $r->revisiones_count }} {{ $r->revisiones_count === 1 ? 'equipo revisado' : 'equipos revisados' }}</div>
                        @if ($r->fallas_count > 0)
                            <div class="small rpc-texto-hallazgo mb-1"><i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>{{ $r->fallas_count }} con hallazgo</div>
                        @endif
                        @if ($r->novedad)
                            <div class="small rpc-texto-ticket mb-1"><i class="bi bi-link-45deg me-1" aria-hidden="true"></i>Generó ticket de seguimiento
                                @if ($verTickets)<a href="{{ route('novedades.index', ['abrir' => $r->novedad->id]) }}">{{ $r->novedad->folio() }}</a>@else{{ $r->novedad->folio() }}@endif
                            </div>
                        @endif
                        @if ($r->finalizado_en)
                            <div class="texto-traza mt-2"><i class="bi bi-flag" aria-hidden="true"></i> Finalizado por {{ $r->finalizador?->name ?? '—' }} · @fecha($r->finalizado_en)</div>
                        @else
                            <div class="texto-traza mt-2"><i class="bi bi-plus-circle" aria-hidden="true"></i> Iniciado por {{ $r->creador?->name ?? '—' }} · @fecha($r->created_at)</div>
                        @endif
                    </div>
                    <div class="mt-3">
                        @if ($continuable)
                            <a href="{{ route('recorridos_pc.show', $r->id) }}#escanear" class="btn-rpc btn-rpc-azul w-100"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Continuar Recorrido</a>
                        @else
                            <a href="{{ route('recorridos_pc.show', $r->id) }}" class="btn-rpc btn-rpc-claro w-100"><i class="bi bi-eye me-1" aria-hidden="true"></i>Ver detalle</a>
                        @endif
                    </div>
                </article>
            @empty
                <div class="tarjeta estado-vacio rpc-vacio">
                    <div class="icono"><i class="bi bi-clipboard-check" aria-hidden="true"></i></div>
                    <p class="fw-semibold mb-1">Aún no hay recorridos registrados.</p>
                    @if ($puede['crear'])
                        <p class="text-muted small m-0">Toca <strong>«Nuevo Recorrido»</strong> para empezar la ronda de inspección.</p>
                    @endif
                </div>
            @endforelse

            <div class="sin-resultados" data-sin-resultados-rpc hidden>
                <i class="bi bi-search d-block mb-2" aria-hidden="true"></i>
                <p class="fw-semibold m-0">No hay recorridos que coincidan con tu búsqueda.</p>
            </div>
        </div>
        @if ($recorridos->where('estatus', '!=', \App\Models\RecorridoPc::EN_PROCESO)->count() >= \App\Http\Controllers\Seguridad\RecorridoPcController::MAX_LISTA)
            <p class="text-muted small mt-3"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Se muestran los últimos {{ \App\Http\Controllers\Seguridad\RecorridoPcController::MAX_LISTA }} recorridos finalizados. Para los anteriores usa el «Reporte de Auditoría» por fechas.</p>
        @endif

        {{-- ===== Nuevo Recorrido ===== --}}
        @if ($puede['crear'])
            <dialog id="dialogoNuevoRecorrido" class="dialogo dialogo-recorrido-pc" aria-labelledby="titulo-nuevo-rpc" @if ($dialogo === 'crear') data-abrir-al-cargar @endif>
                <div class="dialogo-cabecera">
                    <h2 id="titulo-nuevo-rpc"><i class="bi bi-clipboard-check-fill text-success me-2" aria-hidden="true"></i>Nuevo Recorrido de Protección Civil</h2>
                    <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
                <div class="dialogo-cuerpo">
                    <form action="{{ route('recorridos_pc.store') }}" method="POST" autocomplete="off" data-form-nuevo-rpc>
                        @csrf
                        <input type="hidden" name="_dialogo" value="crear">

                        <label class="campo-etiqueta" for="rpc_sede">Sede</label>
                        <select id="rpc_sede" name="sede_id" class="campo" required data-rpc-sede>
                            <option value="" @if ($unaSede === '') data-por-defecto @endif>-- Seleccionar --</option>
                            @foreach ($sedesAlta as $s)
                                <option value="{{ $s->id }}" @selected(($dialogo === 'crear' ? (string) old('sede_id') : $unaSede) === (string) $s->id) @if ($unaSede === (string) $s->id) data-por-defecto @endif>{{ $s->nombre }}</option>
                            @endforeach
                        </select>

                        <label class="campo-etiqueta" for="rpc_edificio">Edificio / Zona <span class="text-lowercase fw-normal">(opcional)</span></label>
                        <select id="rpc_edificio" name="espacio_id" class="campo" data-rpc-depende-sede>
                            <option value="" data-por-defecto>-- Toda la sede --</option>
                            @foreach ($edificios as $e)
                                <option value="{{ $e['id'] }}" data-sede="{{ $e['sede_id'] }}" @selected($dialogo === 'crear' && (string) old('espacio_id') === (string) $e['id'])>{{ $e['texto'] }}</option>
                            @endforeach
                        </select>

                        <label class="campo-etiqueta" for="rpc_obs_gen">Observaciones Generales del Recorrido <span class="text-lowercase fw-normal">(opcional)</span></label>
                        <textarea id="rpc_obs_gen" name="observaciones_generales" class="campo" rows="2" maxlength="2000" placeholder="Notas generales, si aplica...">{{ $dialogo === 'crear' ? old('observaciones_generales') : '' }}</textarea>

                        <p class="rpc-ayuda"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Después de iniciar, escanea cada equipo con la cámara, el NFC del celular o el lector USB. Cada punto se guarda al momento: si se va la señal o cierras la página, no pierdes lo revisado.</p>

                        <div class="dialogo-acciones">
                            <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                            <button type="submit" class="btn-rpc-verde"><i class="bi bi-play-circle-fill me-1" aria-hidden="true"></i>Iniciar Recorrido</button>
                        </div>
                    </form>
                </div>
            </dialog>
        @endif
    @endif
</div>
@endsection
