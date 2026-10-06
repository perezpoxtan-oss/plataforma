@extends('layouts.app')

@section('titulo', 'Bitácora de auditoría')

@section('contenido')
<div class="tema-azul">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
        <div class="encabezado-pantalla m-0">
            <div class="icono"><i class="bi bi-clipboard-data text-primary" aria-hidden="true"></i></div>
            <div>
                <h1>Bitácora de auditoría</h1>
                <p>Quién hizo qué y cuándo. Horas de {{ $zona }}.@if ($soloPropios) Ves solo tus propios movimientos.@endif</p>
            </div>
        </div>
        @if ($puedeExportar && $pagina->total() > 0)
            <a href="{{ route('auditoria.exportar', request()->query()) }}" class="btn btn-outline-dark fw-bold" style="min-height:44px;border-radius:10px;"><i class="bi bi-file-earmark-spreadsheet me-1" aria-hidden="true"></i> Exportar a Excel</a>
        @endif
    </div>

    <form method="GET" data-autoenviar action="{{ route('auditoria.index') }}" class="tarjeta p-3 mb-3 filtros-auditoria" role="search">
        <div>
            <label class="campo-etiqueta" for="aud_desde">Desde</label>
            <input type="date" id="aud_desde" name="desde" class="campo mb-0" value="{{ $filtros['desde'] }}">
        </div>
        <div>
            <label class="campo-etiqueta" for="aud_hasta">Hasta</label>
            <input type="date" id="aud_hasta" name="hasta" class="campo mb-0" value="{{ $filtros['hasta'] }}">
        </div>
        @unless ($soloPropios)
            <div>
                <label class="campo-etiqueta" for="aud_usuario">Usuario</label>
                <select id="aud_usuario" name="usuario" class="campo mb-0">
                    <option value="">Todos</option>
                    @foreach ($usuarios as $u)
                        <option value="{{ $u->id }}" @selected($filtros['usuario'] === $u->id)>{{ $u->name }}</option>
                    @endforeach
                </select>
            </div>
        @endunless
        <div>
            <label class="campo-etiqueta" for="aud_modulo">Módulo</label>
            <select id="aud_modulo" name="modulo" class="campo mb-0">
                <option value="">Todos</option>
                @foreach ($modulos as $clave => $nombre)
                    <option value="{{ $clave }}" @selected($filtros['modulo'] === $clave)>{{ $nombre }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="campo-etiqueta" for="aud_texto">Contiene</label>
            <input type="search" id="aud_texto" name="texto" class="campo mb-0" maxlength="60" placeholder="Nombre, número, IP..." value="{{ $filtros['texto'] }}">
        </div>
        <div class="d-flex gap-2 align-items-end">
            <button type="submit" class="btn-azul" style="min-height:44px;border-radius:10px;padding:0 1rem;"><i class="bi bi-funnel me-1" aria-hidden="true"></i> Filtrar</button>
            @if (array_filter($filtros))
                <a href="{{ route('auditoria.index') }}" class="btn btn-outline-secondary" style="min-height:44px;border-radius:10px;display:inline-flex;align-items:center;">Limpiar</a>
            @endif
        </div>
    </form>

    @if ($pagina->isEmpty())
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-clipboard" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">No hay movimientos con esos filtros.</p>
        </div>
    @else
        <p class="small text-muted mb-2">{{ number_format($pagina->total()) }} movimiento(s).</p>
        <div class="tarjeta p-0 lista-auditoria">
            @foreach ($pagina as $fila)
                @php
                    $detalle = $lector->diferencias($fila->antes, $fila->despues);
                    $registro = $registros[$fila->auditable_type.'#'.$fila->auditable_id] ?? '';
                    $cambios = collect($detalle)->where('cambio', true)->pluck('campo');
                @endphp
                <div class="fila-auditoria">
                    <div class="aud-fecha">@fecha($fila->creado_en, 'd/m/Y')<br><strong>@fecha($fila->creado_en, 'H:i:s')</strong></div>
                    <div class="aud-que">
                        <div><span class="chip-catalogo">{{ $lector->modulo($fila->evento) }}</span> <strong>{{ $lector->accion($fila->evento) }}</strong></div>
                        <div class="small">{{ $registro }}</div>
                        @if ($cambios->isNotEmpty())
                            <div class="small text-muted text-truncate" title="{{ $cambios->join(', ') }}">Cambió: {{ $cambios->take(5)->join(', ') }}{{ $cambios->count() > 5 ? '…' : '' }}</div>
                        @endif
                    </div>
                    <div class="aud-quien small"><i class="bi bi-person" aria-hidden="true"></i> {{ $nombres[$fila->user_id] ?? 'Sistema' }}<br><span class="text-muted">{{ $fila->ip }}</span></div>
                    <div class="aud-ver">
                        <button type="button" class="btn-icono editar" title="Ver detalle" aria-label="Ver detalle del movimiento"
                                data-detalle-auditoria="{{ json_encode(['titulo' => $lector->modulo($fila->evento).' · '.$lector->accion($fila->evento), 'registro' => $registro, 'filas' => $detalle]) }}">
                            <i class="bi bi-eye" aria-hidden="true"></i>
                        </button>
                    </div>
                </div>
            @endforeach
        </div>
        <div class="mt-3">{{ $pagina->onEachSide(1)->links('pagination::bootstrap-5') }}</div>
    @endif

    <dialog id="dialogoDetalleAuditoria" class="dialogo ancho" aria-labelledby="titulo-aud" data-conservar-al-cerrar>
        <div class="dialogo-cabecera">
            <h2 id="titulo-aud"><i class="bi bi-clipboard-data me-2 text-primary" aria-hidden="true"></i><span data-aud-titulo></span></h2>
            <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div class="dialogo-cuerpo">
            <p class="fw-semibold" data-aud-registro></p>
            <div class="table-responsive">
                <table class="table table-sm tabla-auditoria">
                    <thead><tr><th>Campo</th><th>Antes</th><th>Después</th></tr></thead>
                    <tbody data-aud-filas></tbody>
                </table>
            </div>
            <p class="campo-ayuda mb-0"><i class="bi bi-shield-lock" aria-hidden="true"></i> Por privacidad, en esta bitácora la CURP, el RFC, el NSS y los teléfonos aparecen ocultos (por ejemplo ••••1234).</p>
        </div>
    </dialog>
</div>
@endsection
