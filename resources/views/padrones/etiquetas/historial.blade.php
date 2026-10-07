@extends('layouts.app')

@section('titulo', 'Historial de etiquetas QR')

@section('contenido')
{{--
    Ronda 7: Etiquetas QR → Historial. Cada impresión (quién, cuándo, plantilla,
    cuántas y cuáles) con «Reimprimir» (todas o solo algunas, con la misma u otra
    plantilla). El diálogo lo llena el bloque «Ajustes Ronda 7» de plataforma.js
    con data-reimprimir (las etiquetas de esa impresión).
--}}
<div class="tema-gafetes pantalla-etiquetas-qr">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    <div class="encabezado-pantalla mb-3">
        <div class="icono"><i class="bi bi-qr-code text-dark" aria-hidden="true"></i></div>
        <div>
            <h1>Etiquetas QR</h1>
            <p>Historial de impresiones: quién imprimió, cuándo, con qué plantilla y cuáles etiquetas. Desde aquí puedes reimprimirlas.</p>
            @unless ($sinEmpresa)
                <p class="texto-padron-de"><i class="bi bi-building-check" aria-hidden="true"></i> Etiquetas de: <strong>{{ $empresaNombre }}</strong></p>
            @endunless
        </div>
    </div>

    @if ($sinEmpresa)
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-buildings" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong> para ver su historial de impresiones.</p>
        </div>
    @else
        @include('padrones.etiquetas._pestanas', ['activa' => 'historial'])
        @php
            $hayFiltros = $filtros['desde'] !== null || $filtros['hasta'] !== null || $filtros['usuario'] !== null || $filtros['plantilla'] !== null || $filtros['q'] !== '';
        @endphp

        <form method="GET" data-autoenviar action="{{ route('etiquetas.historial') }}" class="filtros-vouchers filtros-etiquetas-qr" role="search" aria-label="Filtrar historial">
            <div class="buscador">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="search" name="q" value="{{ $filtros['q'] }}" placeholder="Buscar una etiqueta (nombre, placas...)" aria-label="Buscar una etiqueta">
            </div>
            @if ($autores->count() > 1)
                <select name="usuario" class="filtro-select" aria-label="Quién imprimió" data-enviar-al-cambiar>
                    <option value="">Todos los usuarios</option>
                    @foreach ($autores as $id => $nombre)
                        <option value="{{ $id }}" @selected($filtros['usuario'] === $id)>{{ $nombre }}</option>
                    @endforeach
                </select>
            @endif
            @if ($todasLasPlantillas->count() > 1)
                <select name="plantilla" class="filtro-select" aria-label="Plantilla" data-enviar-al-cambiar>
                    <option value="">Todas las plantillas</option>
                    @foreach ($todasLasPlantillas as $id => $nombre)
                        <option value="{{ $id }}" @selected($filtros['plantilla'] === $id)>{{ $nombre }}</option>
                    @endforeach
                </select>
            @endif
            <label class="filtro-fecha"><span>Desde</span><input type="date" name="desde" value="{{ $filtros['desde'] }}" data-enviar-al-cambiar></label>
            <label class="filtro-fecha"><span>Hasta</span><input type="date" name="hasta" value="{{ $filtros['hasta'] }}" data-enviar-al-cambiar></label>
            <div class="acciones-filtro">
                <button type="submit" class="btn-gafetes"><i class="bi bi-search me-1" aria-hidden="true"></i>Buscar</button>
                @if ($hayFiltros)
                    <a href="{{ route('etiquetas.historial') }}" class="btn-limpiar-filtros"><i class="bi bi-x-lg me-1" aria-hidden="true"></i>Quitar filtros</a>
                @endif
            </div>
        </form>

        @if ($impresiones->isEmpty())
            <div class="sin-resultados">
                <i class="bi {{ $hayFiltros ? 'bi-search' : 'bi-clock-history' }} d-block mb-2" aria-hidden="true"></i>
                <p class="fw-semibold mb-1">{{ $hayFiltros ? 'No hay impresiones que coincidan con tu búsqueda.' : 'Todavía no hay impresiones.' }}</p>
                @unless ($hayFiltros)
                    <p class="text-muted small m-0">Cuando imprimas etiquetas en la pestaña «Imprimir», aparecerán aquí para que puedas reimprimirlas.</p>
                @endunless
            </div>
        @else
            <ul class="lista-impresiones-qr">
                @foreach ($impresiones as $im)
                    @php
                        $items = $im->items->map(fn ($i) => ['clave' => $i->clave(), 'titulo' => $i->titulo, 'tipo' => \App\Services\Lector\EtiquetasMasivas::NOMBRES[$i->tipo] ?? $i->tipo])->values();
                    @endphp
                    <li class="tarjeta impresion-qr" id="impresion-{{ $im->id }}">
                        <div class="impresion-qr-cabeza">
                            <div>
                                <strong>Impresión núm. {{ $im->id }}</strong>
                                @if ($im->reimpresion_de_id)
                                    <span class="pildora-reimpresion"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Reimpresión de la núm. {{ $im->reimpresion_de_id }}</span>
                                @endif
                                <small class="d-block">@fecha($im->created_at) · {{ $im->autor?->name ?? 'Usuario eliminado' }}</small>
                            </div>
                            <span class="conteo-impresion-qr">{{ $im->cantidad }} {{ $im->cantidad === 1 ? 'etiqueta' : 'etiquetas' }}</span>
                        </div>
                        <p class="impresion-qr-detalle"><i class="bi bi-rulers" aria-hidden="true"></i> {{ $im->plantilla_nombre }} · {{ $resumen->resumenTipos($im) }}</p>
                        <p class="impresion-qr-titulos">{{ $items->take(6)->pluck('titulo')->join(', ') }}{{ $items->count() > 6 ? ' y '.($items->count() - 6).' más' : '' }}</p>
                        <div class="impresion-qr-acciones">
                            <a href="{{ route('etiquetas.impresion', $im->id) }}" target="_blank" class="btn-limpiar-filtros"><i class="bi bi-eye me-1" aria-hidden="true"></i>Ver hoja</a>
                            <button type="button" class="btn-gafetes" data-accion="reimprimir-etiquetas"
                                    data-url="{{ route('etiquetas.reimprimir', $im->id) }}" data-numero="{{ $im->id }}" data-plantilla="{{ $im->plantilla_id }}"
                                    data-reimprimir="{{ $items->toJson(JSON_UNESCAPED_UNICODE) }}">
                                <i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Reimprimir
                            </button>
                        </div>
                    </li>
                @endforeach
            </ul>
            @if ($impresiones->count() >= 100)
                <p class="campo-ayuda"><i class="bi bi-info-circle" aria-hidden="true"></i> Se muestran las 100 más recientes: usa los filtros para encontrar las anteriores.</p>
            @endif
        @endif

        {{-- Reimprimir: todas o solo algunas, con la misma u otra plantilla --}}
        <dialog id="dialogoReimprimirEtiquetas" class="dialogo" aria-labelledby="titulo-reimprimir">
            <div class="dialogo-cabecera">
                <h2 id="titulo-reimprimir"><i class="bi bi-arrow-repeat me-2" aria-hidden="true"></i>Reimprimir impresión núm. <span data-reimprimir-numero></span></h2>
                <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </div>
            <div class="dialogo-cuerpo">
                <form method="POST" action="" target="_blank" data-form-reimprimir>
                    @csrf
                    <p class="campo-ayuda">Quita la marca de las que no necesitas (por ejemplo, solo la que se dañó). Se registra como una impresión nueva en el historial.</p>
                    <label class="marcar-todas-qr mb-2">
                        <input type="checkbox" data-reimprimir-todas checked>
                        <span>Todas <small data-reimprimir-conteo></small></span>
                    </label>
                    <ul class="lista-reimprimir-qr" data-reimprimir-lista></ul>
                    <label class="campo-etiqueta" for="reimprimir_plantilla">Plantilla</label>
                    <select id="reimprimir_plantilla" name="plantilla" class="campo" data-reimprimir-plantilla>
                        <option value="">La misma de la impresión original</option>
                        @foreach ($plantillas as $pl)
                            <option value="{{ $pl->id }}">{{ $pl->nombre }} ({{ $pl->resumen() }})</option>
                        @endforeach
                    </select>
                    <div class="dialogo-acciones">
                        <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                        <button type="submit" class="btn-gafetes" data-reimprimir-enviar><i class="bi bi-printer me-1" aria-hidden="true"></i>Reimprimir <span data-reimprimir-marcadas></span></button>
                    </div>
                </form>
            </div>
        </dialog>
    @endif
</div>
@endsection
