@extends('layouts.app')

@section('titulo', 'Reportes de Transporte')

@section('contenido')
<div class="tema-transporte">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
        <div class="encabezado-pantalla m-0">
            <div class="icono"><i class="bi bi-bar-chart-line-fill text-primary" aria-hidden="true"></i></div>
            <div>
                <h1>Reportes de Transporte</h1>
                <p>Filtros avanzados y exportación de la operación.</p>
                @unless ($sinEmpresa)
                    <p class="texto-padron-de"><i class="bi bi-building-check" aria-hidden="true"></i> Empresa: <strong>{{ $empresaNombre }}</strong></p>
                @endunless
            </div>
        </div>
        <a href="{{ route('transporte.index') }}" class="btn-cancelar transporte-volver"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver a Bitácora</a>
    </div>

    @if ($sinEmpresa)
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-buildings" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong> para ver sus reportes de transporte.</p>
        </div>
    @else
        @php
            $consulta = array_filter(['fecha_inicio' => $filtros['fecha_inicio'], 'fecha_fin' => $filtros['fecha_fin'], 'sede' => $filtros['sede'],
                'estatus' => $filtros['estatus'], 'proveedor' => $filtros['proveedor'], 'tipo' => $filtros['tipo'], 'estado' => $filtros['estado']], fn ($v) => $v !== null);
            $clases = ['a_tiempo' => 'a-tiempo', 'retraso' => 'retraso', 'no_llego' => 'no-llego'];
        @endphp
        <form method="GET" data-autoenviar action="{{ route('transporte.reportes') }}" class="filtro-reporte tarjeta" role="search" aria-label="Filtros del reporte">
            <div class="filtro-reporte-campos">
                @if ($sedes->count() > 1)
                    <label><span class="campo-etiqueta">Sede</span>
                        <select name="sede" class="campo">
                            <option value="">Todas</option>
                            @foreach ($sedes as $s)
                                <option value="{{ $s->id }}" @selected($filtros['sede'] === $s->id)>{{ $s->nombre }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
                <label><span class="campo-etiqueta">Desde</span><input type="date" name="fecha_inicio" class="campo" value="{{ $filtros['fecha_inicio'] }}"></label>
                <label><span class="campo-etiqueta">Hasta</span><input type="date" name="fecha_fin" class="campo" value="{{ $filtros['fecha_fin'] }}"></label>
                <label><span class="campo-etiqueta">Estatus</span>
                    <select name="estatus" class="campo">
                        <option value="">Todos</option>
                        <option value="a_tiempo" @selected($filtros['estatus'] === 'a_tiempo')>A Tiempo</option>
                        <option value="retraso" @selected($filtros['estatus'] === 'retraso')>Retraso</option>
                        <option value="no_llego" @selected($filtros['estatus'] === 'no_llego')>Uso de Taxis</option>
                    </select>
                </label>
                <label><span class="campo-etiqueta">Proveedor (Fletera)</span>
                    <select name="proveedor" class="campo">
                        <option value="">Todos</option>
                        @foreach ($proveedores as $p)
                            <option value="{{ $p->id }}" @selected($filtros['proveedor'] === $p->id)>{{ $p->nombre }}</option>
                        @endforeach
                    </select>
                </label>
            </div>
            <div class="d-flex gap-2 flex-wrap">
                <button type="submit" class="btn-filtrar"><i class="bi bi-funnel-fill me-2" aria-hidden="true"></i>Aplicar Filtros</button>
                @if ($puedeExportar)
                    <a href="{{ route('transporte.exportar', $consulta) }}" class="btn-exportar"><i class="bi bi-file-earmark-excel-fill me-2" aria-hidden="true"></i>Exportar (mismos filtros)</a>
                @endif
            </div>
        </form>

        <div class="resumen-transporte reporte" aria-label="Resumen del periodo">
            <div><span class="valor">{{ $resumen['total'] }}</span><span class="etiqueta">Movimientos</span></div>
            <div class="taxis"><span class="valor">{{ $resumen['taxis'] }}</span><span class="etiqueta">Incidencias (Taxis)</span></div>
            <div class="gasto"><span class="valor">${{ number_format($resumen['gasto'], 2) }}</span><span class="etiqueta">Gasto en Taxis</span></div>
            <div><span class="valor">{{ $resumen['pax'] }}</span><span class="etiqueta">Pasajeros Totales</span></div>
            <div class="anulados"><span class="valor">{{ $resumen['anulados'] }}</span><span class="etiqueta">Anulados</span></div>
        </div>
        <p class="small text-muted"><i class="bi bi-info-circle" aria-hidden="true"></i> Movimientos, gasto y pasajeros cuentan solo los registros vigentes; los anulados se muestran aparte.</p>

        <div class="tabla-reporte-transporte">
            <div class="tabla-scroll">
                <table>
                    <thead>
                        <tr><th>Fecha</th><th>Sede</th><th>Ruta</th><th>Estatus</th><th>Unidad</th><th>Conductor</th><th class="text-center">PAX</th><th>Monto</th><th>Registró</th></tr>
                    </thead>
                    <tbody>
                        @forelse ($registros as $r)
                            <tr class="{{ $r->anulado ? 'anulada' : '' }}">
                                <td class="text-nowrap"><a href="{{ route('transporte.show', $r->id) }}">@fecha($r->created_at)</a></td>
                                <td>{{ $r->sede?->nombre }}</td>
                                <td>{{ $r->ruta?->nombre }} <span class="text-muted small">({{ $r->etiquetaTipo() }})</span></td>
                                <td><span class="pill-estatus {{ $clases[$r->estatus] ?? '' }}">{{ $r->etiquetaEstatus() }}{{ $r->anulado ? ' · ANULADO' : '' }}</span></td>
                                <td>{{ $r->etiquetaUnidad() }} ({{ $r->vehiculo?->placas ?? '—' }})</td>
                                <td>{{ $r->chofer?->nombre_completo ?? '—' }}</td>
                                <td class="text-center">{{ (int) $r->cantidad_pax }}</td>
                                <td class="text-nowrap">{{ $r->monto !== null ? '$'.number_format((float) $r->monto, 2) : '—' }}</td>
                                <td class="text-muted small">{{ $r->registro?->name }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="9" class="text-center text-muted py-4">No hay movimientos con estos filtros.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>

        @if ($registros->hasPages())
            <div class="mt-3">{{ $registros->onEachSide(1)->links('pagination::bootstrap-5') }}</div>
        @endif
        @if ($registros->total() > 0)
            <p class="text-center text-muted small mt-2">{{ $registros->total() }} registros en total, página {{ $registros->currentPage() }} de {{ $registros->lastPage() }}.</p>
        @endif
    @endif
</div>
@endsection
