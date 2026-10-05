@extends('layouts.app')

@section('titulo', 'Bitácora de Transporte')

@section('contenido')
<div class="tema-transporte">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    @if ($sinEmpresa)
        <div class="encabezado-pantalla mb-4">
            <div class="icono"><i class="bi bi-clock-history text-primary" aria-hidden="true"></i></div>
            <div><h1>Bitácora de Transporte</h1><p>Registro operativo e incidencias multi-taxi en tiempo real.</p></div>
        </div>
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-buildings" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong> para ver su bitácora de transporte.</p>
        </div>
    @else
        @php
            $hoy = \Carbon\CarbonImmutable::now(app(\App\Support\HoraLocal::class)->zona());
            $rapidos = [
                'Hoy' => [$hoy->toDateString(), $hoy->toDateString()],
                'Ayer' => [$hoy->subDay()->toDateString(), $hoy->subDay()->toDateString()],
                'Últimos 7 días' => [$hoy->subDays(6)->toDateString(), $hoy->toDateString()],
            ];
            $hayFiltrosExtra = $filtros['sede'] !== null || $filtros['estatus'] !== null || $filtros['tipo'] !== null || $filtros['estado'] !== null || $filtros['q'] !== '';
            $consultaActual = array_filter(['fecha_inicio' => $filtros['fecha_inicio'], 'fecha_fin' => $filtros['fecha_fin'], 'sede' => $filtros['sede'],
                'estatus' => $filtros['estatus'], 'tipo' => $filtros['tipo'], 'estado' => $filtros['estado'], 'q' => $filtros['q'] ?: null], fn ($v) => $v !== null);
        @endphp

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
            <div class="encabezado-pantalla m-0">
                <div class="icono"><i class="bi bi-clock-history text-primary" aria-hidden="true"></i></div>
                <div>
                    <h1>Bitácora de Transporte</h1>
                    <p>Registro operativo e incidencias multi-taxi en tiempo real.</p>
                    <p class="texto-padron-de"><i class="bi bi-building-check" aria-hidden="true"></i> Bitácora de: <strong>{{ $empresaNombre }}</strong></p>
                </div>
            </div>
            @if ($puede['crear'])
                <button type="button" class="btn-registrar-movimiento" data-abrir-dialogo="dialogoAltaTransporte">
                    <i class="bi bi-plus-circle me-2" aria-hidden="true"></i>Registrar Movimiento
                </button>
            @endif
        </div>

        @if (session('vales'))
            <div class="alert alert-danger aviso transporte-vales" role="status">
                <i class="bi bi-printer-fill" aria-hidden="true"></i>
                <div>
                    <strong>Imprime los vales de caja chica:</strong>
                    @foreach (session('vales') as $vale)
                        <a href="{{ route('transporte.vale', $vale['id']) }}" target="_blank" rel="noopener" class="btn-planilla ms-1">Vale {{ $vale['folio'] }}</a>
                    @endforeach
                </div>
            </div>
        @endif

        <form class="filtros-transporte" method="GET" action="{{ route('transporte.index') }}" role="search" aria-label="Filtrar la bitácora">
            <div class="filtros-transporte-fechas">
                <label class="fecha-transporte"><span>Fecha Inicio</span><input type="date" name="fecha_inicio" value="{{ $filtros['fecha_inicio'] }}"></label>
                <label class="fecha-transporte"><span>Fecha Fin</span><input type="date" name="fecha_fin" value="{{ $filtros['fecha_fin'] }}"></label>
                <div class="rangos-rapidos" aria-label="Rangos rápidos">
                    @foreach ($rapidos as $texto => [$desde, $hasta])
                        <a href="{{ route('transporte.index', array_merge($consultaActual, ['fecha_inicio' => $desde, 'fecha_fin' => $hasta])) }}"
                           class="rango-rapido {{ $filtros['fecha_inicio'] === $desde && $filtros['fecha_fin'] === $hasta ? 'activo' : '' }}">{{ $texto }}</a>
                    @endforeach
                </div>
            </div>
            <input type="checkbox" id="verFiltrosTransporte" class="ver-filtros-check" @checked($hayFiltrosExtra)>
            <label for="verFiltrosTransporte" class="ver-filtros"><i class="bi bi-sliders me-2" aria-hidden="true"></i>Más filtros (sede, estatus, búsqueda)<i class="bi bi-chevron-down ms-2" aria-hidden="true"></i></label>
            <div class="filtros-transporte-resto">
                <div class="buscador">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="search" name="q" value="{{ $filtros['q'] }}" placeholder="Placas, chofer, ruta, pasajero..." aria-label="Buscar en la bitácora">
                </div>
                @if ($sedes->count() > 1)
                    <select name="sede" class="filtro-select" aria-label="Filtrar por sede">
                        <option value="">Todas las sedes</option>
                        @foreach ($sedes as $s)
                            <option value="{{ $s->id }}" @selected($filtros['sede'] === $s->id)>{{ $s->nombre }}</option>
                        @endforeach
                    </select>
                @endif
                <select name="tipo" class="filtro-select" aria-label="Filtrar por tipo de movimiento">
                    <option value="">Llegadas y salidas</option>
                    <option value="llegada" @selected($filtros['tipo'] === 'llegada')>Solo llegadas</option>
                    <option value="salida" @selected($filtros['tipo'] === 'salida')>Solo salidas</option>
                </select>
                <select name="estatus" class="filtro-select" aria-label="Filtrar por estatus">
                    <option value="">Todos los estatus</option>
                    <option value="a_tiempo" @selected($filtros['estatus'] === 'a_tiempo')>A Tiempo</option>
                    <option value="retraso" @selected($filtros['estatus'] === 'retraso')>Retraso</option>
                    <option value="no_llego" @selected($filtros['estatus'] === 'no_llego')>Uso de Taxis</option>
                </select>
                <select name="estado" class="filtro-select" aria-label="Filtrar por estado">
                    <option value="">Vigentes y anulados</option>
                    <option value="vigentes" @selected($filtros['estado'] === 'vigentes')>Solo vigentes</option>
                    <option value="anulados" @selected($filtros['estado'] === 'anulados')>Solo anulados</option>
                    <option value="por_autorizar" @selected($filtros['estado'] === 'por_autorizar')>Vales sin Vo.Bo.</option>
                </select>
            </div>
            <div class="filtros-transporte-botones">
                <button type="submit" class="btn-filtrar"><i class="bi bi-search me-2" aria-hidden="true"></i>Filtrar</button>
                @if ($puede['exportar'])
                    <a href="{{ route('transporte.exportar', $consultaActual) }}" class="btn-exportar"><i class="bi bi-file-earmark-excel-fill me-2" aria-hidden="true"></i>Exportar Excel</a>
                @endif
                <a href="{{ route('transporte.reportes') }}" class="btn-reportes"><i class="bi bi-bar-chart-line-fill me-2" aria-hidden="true"></i>Reportes Avanzados</a>
                @if ($hayFiltrosExtra)
                    <a href="{{ route('transporte.index', ['fecha_inicio' => $filtros['fecha_inicio'], 'fecha_fin' => $filtros['fecha_fin']]) }}" class="btn-limpiar-filtros"><i class="bi bi-x-lg me-1" aria-hidden="true"></i>Quitar filtros</a>
                @endif
            </div>
        </form>

        <div class="resumen-transporte" aria-label="Resumen del periodo">
            <div><span class="valor">{{ $resumen['total'] }}</span><span class="etiqueta">Movimientos</span></div>
            <div class="a-tiempo"><span class="valor">{{ $resumen['a_tiempo'] }}</span><span class="etiqueta">A tiempo</span></div>
            <div class="retraso"><span class="valor">{{ $resumen['retraso'] }}</span><span class="etiqueta">Retrasos</span></div>
            <div class="taxis"><span class="valor">{{ $resumen['taxis'] }}</span><span class="etiqueta">Taxis</span></div>
            <div class="gasto"><span class="valor">${{ number_format($resumen['gasto'], 2) }}</span><span class="etiqueta">Gasto en taxis</span></div>
            @if ($resumen['anulados'] > 0)
                <div class="anulados"><span class="valor">{{ $resumen['anulados'] }}</span><span class="etiqueta">Anulados</span></div>
            @endif
        </div>

        <div class="transporte-lista">
            @forelse ($movimientos as $m)
                @include('seguridad.transporte._tarjeta', [
                    'm' => $m,
                    'editable' => in_array($m->id, $editables, true),
                    'anulable' => in_array($m->id, $anulables, true),
                    'autorizable' => in_array($m->id, $autorizables, true),
                ])
            @empty
                <div class="tarjeta estado-vacio">
                    <div class="icono"><i class="bi bi-inbox" aria-hidden="true"></i></div>
                    <p class="mt-2 mb-1">No se encontraron registros en este periodo.</p>
                    @if ($puede['crear'])
                        <p class="text-muted small m-0">Usa <strong>Registrar Movimiento</strong> cuando llegue o salga una unidad.</p>
                    @endif
                </div>
            @endforelse
        </div>

        @if ($movimientos->hasPages())
            <div class="mt-4">{{ $movimientos->onEachSide(1)->links('pagination::bootstrap-5') }}</div>
        @endif

        @foreach ($paraderosPorSede as $sedeId => $nombres)
            <datalist id="paraderosTransporte-{{ $sedeId }}">
                @foreach ($nombres as $nombre)
                    <option value="{{ $nombre }}"></option>
                @endforeach
            </datalist>
        @endforeach

        @if ($alta)
            @include('seguridad.transporte._alta')
        @endif
        @if ($puede['editar'])
            @include('seguridad.transporte._editar')
        @endif
        @if ($alta || $puede['editar'])
            @include('organizacion.colaboradores._registro-rapido', ['sedeSugerida' => $alta && $alta['sedes']->count() === 1 ? $alta['sedes']->first()->id : null])
        @endif
    @endif
</div>
@endsection
