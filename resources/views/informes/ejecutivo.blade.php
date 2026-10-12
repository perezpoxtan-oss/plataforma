@extends('layouts.app')

@section('titulo', 'Informe ejecutivo')

@section('contenido')
<div class="pantalla-informes">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    <div class="informe-encabezado">
        <div class="encabezado-pantalla m-0">
            <div class="icono"><i class="bi bi-file-earmark-bar-graph text-indigo" aria-hidden="true"></i></div>
            <div>
                <h1>Informe ejecutivo</h1>
                <p>El panorama de la operación para Dirección. Abre «Ver / Ocultar listado de registros» para ver de dónde sale cada cifra.</p>
                @unless ($sinEmpresa)
                    <p class="texto-padron-de"><i class="bi bi-building-check" aria-hidden="true"></i> {{ $empresaNombre }} · {{ $textoSedes }} · generado el @fecha($generado)</p>
                @endunless
            </div>
        </div>
        @unless ($sinEmpresa)
            <div class="acciones-informe">
                @include('informes._enlaces', ['actual' => 'ejecutivo'])
                <a href="{{ route('informe_ejecutivo.index', array_filter(['sedes' => $seleccion ?: null, 'fresco' => 1])) }}" class="btn-cancelar boton-informe" title="Las cifras se guardan 5 minutos para que la pantalla abra rápido"><i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i>Actualizar cifras</a>
                <a href="{{ route('informe_ejecutivo.imprimir', array_filter(['sedes' => $seleccion ?: null])) }}" class="btn-filtrar boton-informe" target="_blank" rel="noopener"><i class="bi bi-printer me-2" aria-hidden="true"></i>Imprimir</a>
                @if ($puedeExportar)
                    <a href="{{ route('informe_ejecutivo.exportar', array_filter(['sedes' => $seleccion ?: null])) }}" class="btn-exportar boton-informe"><i class="bi bi-file-earmark-excel me-2" aria-hidden="true"></i>Exportar</a>
                @endif
            </div>
        @endunless
    </div>

    @if ($sinEmpresa)
        @include('informes._sin-empresa')
    @else
        @if ($sedes->count() > 1)
            <form method="GET" action="{{ route('informe_ejecutivo.index') }}" class="filtro-informe tarjeta" role="search" aria-label="Sedes del informe">
                @include('informes._filtro-sedes')
                <div class="d-flex gap-2 flex-wrap align-items-end">
                    <button type="submit" class="btn-filtrar"><i class="bi bi-funnel-fill me-2" aria-hidden="true"></i>Aplicar</button>
                </div>
            </form>
        @endif

        @if ($bloques === [])
            <div class="tarjeta estado-vacio">
                <div class="icono"><i class="bi bi-file-earmark-bar-graph" aria-hidden="true"></i></div>
                <p class="text-muted small m-0">Tu rol no puede consultar ninguno de los módulos que forman este informe. Pide a tu administrador que revise tus permisos.</p>
            </div>
        @else
            @include('informes._ejecutivo-bloques', ['imprimir' => false])
        @endif
    @endif
</div>
@endsection
