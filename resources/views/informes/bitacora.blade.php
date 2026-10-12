@extends('layouts.app')

@section('titulo', 'Bitácora del día')

@section('contenido')
<div class="pantalla-informes">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    <div class="informe-encabezado">
        <div class="encabezado-pantalla m-0">
            <div class="icono"><i class="bi bi-calendar-day text-info" aria-hidden="true"></i></div>
            <div>
                <h1>Bitácora general del día</h1>
                <p>Todo lo que pasó en el día, en una sola línea de tiempo: accesos, {{ mb_strtolower(\App\Services\Novedades\AjustesNovedades::nombreActual()) }}, transporte, llaves y más.</p>
                @unless ($sinEmpresa)
                    <p class="texto-padron-de"><i class="bi bi-calendar-event" aria-hidden="true"></i> <span class="text-capitalize-first">{{ $fechaLegible }}</span> · {{ $textoSedes }} · horas de {{ $zonaTexto }}</p>
                @endunless
            </div>
        </div>
        @unless ($sinEmpresa)
            @php $consulta = array_filter(['fecha' => $fecha->format('Y-m-d'), 'sede' => $sede, 'tipo' => $tipo]); @endphp
            <div class="acciones-informe">
                @include('informes._enlaces', ['actual' => 'bitacora'])
                @if ($puedeImprimir)
                    <a href="{{ route('bitacora_dia.imprimir', $consulta) }}" class="btn-filtrar boton-informe" target="_blank" rel="noopener"><i class="bi bi-printer me-2" aria-hidden="true"></i>Imprimir</a>
                @endif
                @if ($puedeExportar)
                    <a href="{{ route('bitacora_dia.exportar', $consulta) }}" class="btn-exportar boton-informe"><i class="bi bi-file-earmark-excel me-2" aria-hidden="true"></i>Exportar</a>
                @endif
            </div>
        @endunless
    </div>

    @if ($sinEmpresa)
        @include('informes._sin-empresa')
    @else
        <form method="GET" action="{{ route('bitacora_dia.index') }}" data-autoenviar class="filtro-informe tarjeta" role="search" aria-label="Día, sede y tipo">
            <div class="filtro-informe-fila">
                <label class="filtro-informe-campo"><span class="campo-etiqueta">Día</span>
                    <input type="date" name="fecha" class="campo mb-0" value="{{ $fecha->format('Y-m-d') }}" max="{{ $hoy->format('Y-m-d') }}">
                </label>
                @if ($sedes->count() > 1)
                    <label class="filtro-informe-campo"><span class="campo-etiqueta">Sede</span>
                        <select name="sede" class="campo mb-0">
                            <option value="">Todas mis sedes</option>
                            @foreach ($sedes as $s)
                                <option value="{{ $s->id }}" @selected($sede === $s->id)>{{ $s->nombre }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
                @if (count($visibles) > 1)
                    <label class="filtro-informe-campo"><span class="campo-etiqueta">Tipo</span>
                        <select name="tipo" class="campo mb-0">
                            <option value="">Todo</option>
                            @foreach ($visibles as $clave)
                                <option value="{{ $clave }}" @selected($tipo === $clave)>{{ \App\Services\Informes\BitacoraDelDia::nombreTipo($clave) }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
                @if ($fecha->format('Y-m-d') !== $hoy->format('Y-m-d'))
                    <a href="{{ route('bitacora_dia.index', array_filter(['sede' => $sede, 'tipo' => $tipo])) }}" class="btn-cancelar boton-informe align-self-end">Hoy</a>
                @endif
                <noscript><button type="submit" class="btn-filtrar">Ver</button></noscript>
            </div>
        </form>

        @if ($visibles === [])
            <div class="tarjeta estado-vacio">
                <div class="icono"><i class="bi bi-calendar-day" aria-hidden="true"></i></div>
                <p class="text-muted small m-0">Tu rol no puede consultar ninguno de los módulos de esta bitácora. Pide a tu administrador que revise tus permisos.</p>
            </div>
        @else
            <div class="tarjeta p-3">
                @include('informes._bitacora-linea', ['imprimir' => false])
            </div>
        @endif
    @endif
</div>
@endsection
