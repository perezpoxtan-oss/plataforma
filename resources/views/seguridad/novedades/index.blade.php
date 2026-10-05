@extends('layouts.app')

@section('titulo', 'Bitácora de Novedades y Despacho')

@section('contenido')
<div class="pantalla-novedades">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    @if ($sinEmpresa)
        <div class="encabezado-pantalla mb-4">
            <div class="icono"><i class="bi bi-headset text-danger" aria-hidden="true"></i></div>
            <div><h1>Despacho y Novedades</h1><p>Levantamiento modular y control operativo.</p></div>
        </div>
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-buildings" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong> para ver su bitácora de novedades.</p>
        </div>
    @else
        @php
            $soloLf = request()->routeIs('lost_found.index');
            $categoriasFiltro = $soloLostFound
                ? ['lost_found' => 'Lost & Found (Objetos Perdidos)']
                : ['incidente_general' => 'Reporte General', 'accidente' => 'Accidente / Lesión', 'habitacion' => 'Valores a la Vista',
                    'proteccion_civil' => 'Siniestro Protección Civil', 'recorrido_pc' => 'Recorridos PC (Inspección)',
                    'lost_found' => 'Lost & Found (Objetos Perdidos)', 'robo' => 'Robo', 'sin_clasificar' => 'Sin Clasificar'];
        @endphp

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
            <div class="encabezado-pantalla m-0">
                <div class="icono"><i class="bi bi-headset text-danger" aria-hidden="true"></i></div>
                <div>
                    <h1>Despacho y Novedades</h1>
                    <p>Levantamiento modular y control operativo.</p>
                    <p class="texto-padron-de"><i class="bi bi-building-check" aria-hidden="true"></i> Bitácora de: <strong>{{ $empresaNombre }}</strong></p>
                </div>
            </div>
            <div class="acciones-novedades">
                @if ($puede['exportar'])
                    <a href="{{ route('novedades.exportar') }}" class="btn-accion-novedades" data-exportar-novedades data-base="{{ route('novedades.exportar') }}">
                        <i class="bi bi-file-earmark-spreadsheet" aria-hidden="true"></i> Exportar
                    </a>
                @endif
                @if ($puede['crear'])
                    <button type="button" class="btn-nuevo-ticket" data-abrir-dialogo="dialogoAltaTicket"><i class="bi bi-lightning-fill me-1" aria-hidden="true"></i>Nuevo Ticket</button>
                @endif
            </div>
        </div>

        @if ($soloLf && ! $soloLostFound)
            <div class="alert alert-warning aviso mb-3" role="status">
                <i class="bi bi-bag-fill" aria-hidden="true"></i>
                <span>Mostrando solo los tickets de <strong>Lost &amp; Found</strong>. <a href="{{ route('novedades.index') }}">Ver todas las novedades</a>.</span>
            </div>
        @endif

        {{-- Barra de búsqueda y filtros --}}
        <div class="barra-novedades tarjeta">
            <div class="buscador buscador-novedades">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="search" placeholder="Buscar por #Ticket, ubicación o detalles del reporte..." aria-label="Buscar ticket" data-filtro-novedades="texto">
            </div>
            <select class="filtro-select" aria-label="Filtrar por categoría" data-filtro-novedades="categoria">
                <option value="">Todas las Categorías</option>
                @foreach ($categoriasFiltro as $clave => $texto)
                    <option value="{{ $clave }}">{{ $texto }}</option>
                @endforeach
            </select>
            @if ($variasSedes)
                <select class="filtro-select" aria-label="Filtrar por sede" data-filtro-novedades="sede">
                    <option value="">Todas las sedes</option>
                    @foreach ($sedesFiltro as $s)
                        <option value="{{ $s->id }}">{{ $s->nombre }}</option>
                    @endforeach
                </select>
            @endif
        </div>

        <div class="pestanas-novedades" role="tablist" aria-label="Tickets">
            <button type="button" class="active" role="tab" aria-selected="true" data-pestana-novedades="abiertas">
                Tickets Abiertos / Asignados <span class="conteo-pill">{{ $abiertas->count() }}</span>
            </button>
            <button type="button" role="tab" aria-selected="false" data-pestana-novedades="resueltas">
                Historial Resueltos <span class="conteo-pill">{{ $resueltas->count() }}</span>
            </button>
        </div>

        @foreach (['abiertas' => $abiertas, 'resueltas' => $resueltas] as $grupo => $lista)
            <div class="fichas-grid fichas-novedades" data-vista-novedades="{{ $grupo }}" @if ($grupo === 'resueltas') hidden @endif>
                @forelse ($lista as $n)
                    @php
                        $abierta = $grupo === 'abiertas';
                        $puedeAbrir = $abierta && in_array($n->id, $editables, true);
                        $area = $n->textoArea();
                    @endphp
                    <article class="ficha-novedad {{ $abierta ? 'abierta' : 'resuelta' }}" id="novedad-{{ $n->id }}" data-novedad
                             data-cat="{{ $n->categoria }}" data-sede="{{ $n->sede_id }}"
                             data-texto="{{ \App\Http\Controllers\Seguridad\NovedadController::textoBusqueda($n) }}">
                        <div>
                            <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                                <span class="badge-categoria {{ $abierta ? 'abierta' : 'resuelta' }}">
                                    <i class="bi {{ $abierta ? 'bi-bell-fill' : 'bi-check-circle' }} me-1" aria-hidden="true"></i>{{ $n->etiquetaCategoria() }}
                                </span>
                                <span class="folio-novedad">{{ $n->folio() }}</span>
                            </div>
                            @if ($n->estatus === \App\Models\Novedad::PENDIENTE_TURNO)
                                <span class="pastilla-pendiente-turno"><i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>PENDIENTE DE TURNO</span>
                            @endif
                            @if ($variasSedes && $n->sede)
                                <div class="linea-novedad text-success"><i class="bi bi-building me-1" aria-hidden="true"></i>{{ $n->sede->nombre }}</div>
                            @endif
                            <div class="linea-novedad"><i class="bi bi-geo-alt-fill text-primary me-1" aria-hidden="true"></i>{{ $n->ubicacion }}@if ($area) <span class="text-muted fw-normal">· {{ $area }}</span>@endif</div>
                            <p class="texto-novedad">{!! nl2br(e(\Illuminate\Support\Str::limit($n->descripcion, 280))) !!}</p>
                            @if ($n->articulos->isNotEmpty())
                                <div class="linea-novedad text-warning-emphasis"><i class="bi bi-tag-fill me-1" aria-hidden="true"></i>Folios: {{ $n->articulos->pluck('folio')->join(', ') }}</div>
                            @endif
                            @if ($n->asignado)
                                <div class="texto-traza"><i class="bi bi-person-badge me-1" aria-hidden="true"></i>Canalizado a: <strong>{{ $n->asignado->name }}</strong></div>
                            @endif
                            <div class="texto-traza">
                                <i class="bi bi-person-plus-fill me-1" aria-hidden="true"></i>Creó: <strong>{{ $n->creador?->name ?? '—' }}</strong> · @fecha($n->created_at)
                                @if ($n->editor && ($abierta ? $n->actualizado_por !== $n->creado_por : true))
                                    · <i class="bi bi-person-check-fill me-1" aria-hidden="true"></i>{{ $abierta ? 'Último en dar seguimiento' : 'Cerró/atendió' }}: <strong>{{ $abierta ? $n->editor->name : ($n->cerrador?->name ?? $n->editor->name) }}</strong>
                                @endif
                            </div>
                        </div>
                        <div class="pie-novedad">
                            @if ($puedeAbrir)
                                <a href="{{ route('novedades.index', ['abrir' => $n->id]) }}" class="btn-expediente abrir"><i class="bi bi-folder2-open me-1" aria-hidden="true"></i>Abrir Expediente</a>
                            @else
                                <a href="{{ route('novedades.index', ['abrir' => $n->id]) }}" class="btn-expediente {{ $abierta ? 'ver' : 'resuelto' }}"><i class="bi bi-eye-fill me-1" aria-hidden="true"></i>Ver Expediente</a>
                            @endif
                        </div>
                    </article>
                @empty
                    <div class="tarjeta estado-vacio estado-vacio-novedades">
                        <div class="icono"><i class="bi {{ $grupo === 'abiertas' ? 'bi-inbox' : 'bi-archive' }}" aria-hidden="true"></i></div>
                        <p class="text-muted small m-0">
                            @if ($grupo === 'abiertas')
                                No hay tickets abiertos.@if ($puede['crear']) Usa <strong>Nuevo Ticket</strong> para despachar uno.@endif
                            @else
                                Todavía no hay casos resueltos.
                            @endif
                        </p>
                    </div>
                @endforelse
                <div class="sin-resultados" data-sin-resultados-novedades hidden>
                    <i class="bi bi-search d-block mb-2" aria-hidden="true"></i>
                    <p class="fw-semibold m-0">No hay tickets que coincidan con tu búsqueda.</p>
                </div>
            </div>
        @endforeach
        @if ($resueltas->count() >= \App\Services\Novedades\AdministradorNovedades::MAX_RESUELTOS)
            <p class="campo-ayuda mt-3" data-nota-resueltas hidden><i class="bi bi-info-circle" aria-hidden="true"></i> Se muestran los {{ \App\Services\Novedades\AdministradorNovedades::MAX_RESUELTOS }} casos resueltos más recientes. Para el historial completo usa <strong>Exportar</strong>.</p>
        @endif

        @if ($puede['crear'])
            @include('seguridad.novedades._alta')
        @endif

        @if ($expediente)
            @include('seguridad.novedades._expediente')
        @endif

        @if ($catalogos)
            <datalist id="novColaboradores"></datalist>
            <script type="application/json" id="novColaboradoresDatos">@json($catalogos['colaboradores'])</script>
        @endif
    @endif
</div>
@endsection
