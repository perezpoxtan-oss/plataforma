@extends('layouts.app')

@section('titulo', 'Tablero')

@section('contenido')
<div class="pantalla-informes">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    <div class="informe-encabezado">
        <div class="encabezado-pantalla m-0">
            <div class="icono"><i class="bi bi-speedometer2 text-primary" aria-hidden="true"></i></div>
            <div>
                <h1>Consola de Monitoreo</h1>
                <p>Las cifras de este momento. Toca una ficha para ver su gráfica, el listado y el enlace al módulo.</p>
            </div>
        </div>
        @unless ($sinEmpresa)
            <div class="acciones-informe">
                @include('informes._enlaces', ['actual' => 'tablero'])
                @if ($puedeImprimir)
                    <a href="{{ route('tablero.imprimir', array_filter(['sede' => $sede])) }}" class="btn-filtrar boton-informe" target="_blank" rel="noopener"><i class="bi bi-printer me-2" aria-hidden="true"></i>Imprimir</a>
                @endif
            </div>
        @endunless
    </div>

    @if ($sinEmpresa)
        @include('informes._sin-empresa')
    @else
        @if ($sedes->count() > 1)
            <form method="GET" action="{{ route('tablero.index') }}" data-autoenviar class="filtro-informe tarjeta" role="search" aria-label="Filtro del tablero">
                <label class="filtro-informe-campo"><span class="campo-etiqueta">Sede</span>
                    <select name="sede" class="campo mb-0">
                        <option value="">Todas mis sedes</option>
                        @foreach ($sedes as $s)
                            <option value="{{ $s->id }}" @selected($sede === $s->id)>{{ $s->nombre }}</option>
                        @endforeach
                    </select>
                </label>
                <noscript><button type="submit" class="btn-filtrar">Ver</button></noscript>
            </form>
        @endif

        @if ($fichas === [])
            <div class="tarjeta estado-vacio">
                <div class="icono"><i class="bi bi-speedometer2" aria-hidden="true"></i></div>
                <h2 class="h6 fw-bold mb-1">Sin fichas para mostrar</h2>
                <p class="text-muted small m-0">Cada ficha aparece si tu rol puede consultar el módulo de donde sale el dato. Si esperabas ver alguna, pide a tu administrador que revise tus permisos.</p>
            </div>
        @else
            <div class="fichas-informe">
                @foreach ($fichas as $f)
                    <a href="{{ route('tablero.detalle', array_filter(['ficha' => $f['clave'], 'sede' => $sede])) }}" class="ficha-informe {{ ! empty($f['nivel']) ? 'nivel-'.$f['nivel'] : '' }}" data-ficha-tablero>
                        <span class="ficha-informe-icono"><i class="bi {{ $f['icono'] }}" aria-hidden="true"></i></span>
                        <span class="ficha-informe-valor">{{ $f['valor'] }}</span>
                        <span class="ficha-informe-titulo">{{ $f['titulo'] }}</span>
                        <span class="ficha-informe-nota">{{ $f['nota'] }}</span>
                        <span class="ficha-informe-ver"><i class="bi bi-bar-chart-line" aria-hidden="true"></i> Ver detalle</span>
                    </a>
                @endforeach
            </div>
            <p class="text-muted small mt-3"><i class="bi bi-clock" aria-hidden="true"></i> Calculado a las @fecha($generado, 'H:i') ({{ app(\App\Support\HoraLocal::class)->etiqueta() }}). Vuelve a abrir la pantalla para actualizar.</p>
        @endif

        <dialog id="dialogoFichaTablero" class="dialogo dialogo-ficha-informe" aria-labelledby="titulo-ficha-tablero">
            <div class="dialogo-cabecera">
                <h2 id="titulo-ficha-tablero">Detalle</h2>
                <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </div>
            <div class="dialogo-cuerpo" data-cuerpo-ficha aria-live="polite"></div>
        </dialog>
    @endif
</div>
@endsection
