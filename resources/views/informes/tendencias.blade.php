@extends('layouts.app')

@section('titulo', 'Tendencias')

@section('contenido')
@php
    use App\Services\Informes\Indicadores;
    use App\Support\GraficaSvg;
    $filtrosUrl = $sinEmpresa ? [] : array_filter([
        'periodo' => $periodo->tipo,
        'desde' => $periodo->tipo === 'personalizado' ? $periodo->desde->format('Y-m-d') : null,
        'hasta' => $periodo->tipo === 'personalizado' ? $periodo->hasta->format('Y-m-d') : null,
        'sedes' => $seleccion ?: null,
    ]);
    $formatear = function ($valor, string $unidad): string {
        if ($unidad === 'texto') { return (string) $valor; }
        return GraficaSvg::numero((float) $valor, $unidad === '%' ? '' : $unidad).($unidad === '%' ? ' %' : '');
    };
@endphp
<div class="pantalla-informes">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    <div class="informe-encabezado">
        <div class="encabezado-pantalla m-0">
            <div class="icono"><i class="bi bi-graph-up text-success" aria-hidden="true"></i></div>
            <div>
                <h1>Tendencias</h1>
                <p>Cómo va cada módulo comparado con el periodo anterior de la misma duración.</p>
                @unless ($sinEmpresa)
                    <p class="texto-padron-de"><i class="bi bi-calendar-range" aria-hidden="true"></i> {{ $periodo->texto() }} contra {{ $periodo->anterior()->texto() }} · {{ $textoSedes }}</p>
                @endunless
            </div>
        </div>
        @unless ($sinEmpresa)
            <div class="acciones-informe">
                @include('informes._enlaces', ['actual' => 'tendencias'])
                <a href="{{ route('tendencias.index', $filtrosUrl + array_filter(['seccion' => $solo, 'fresco' => 1])) }}" class="btn-cancelar boton-informe" title="Las cifras se guardan 5 minutos para que la pantalla abra rápido"><i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i>Actualizar cifras</a>
            </div>
        @endunless
    </div>

    @if ($sinEmpresa)
        @include('informes._sin-empresa')
    @else
        <form method="GET" action="{{ route('tendencias.index') }}" class="filtro-informe tarjeta" role="search" aria-label="Periodo y sedes" data-filtro-tendencias>
            <div class="filtro-informe-fila">
                <label class="filtro-informe-campo"><span class="campo-etiqueta">Periodo</span>
                    <select name="periodo" class="campo mb-0" data-periodo-tendencias>
                        @foreach (\App\Services\Informes\Periodo::TIPOS as $clave => $texto)
                            <option value="{{ $clave }}" @selected($periodo->tipo === $clave)>{{ $texto }}</option>
                        @endforeach
                    </select>
                </label>
                <div class="rango-personalizado" data-rango-personalizado>
                    <label class="filtro-informe-campo"><span class="campo-etiqueta">Desde</span><input type="date" name="desde" class="campo mb-0" value="{{ $periodo->desde->format('Y-m-d') }}"></label>
                    <label class="filtro-informe-campo"><span class="campo-etiqueta">Hasta</span><input type="date" name="hasta" class="campo mb-0" value="{{ $periodo->hasta->format('Y-m-d') }}"></label>
                </div>
            </div>
            @include('informes._filtro-sedes')
            @if ($solo)<input type="hidden" name="seccion" value="{{ $solo }}">@endif
            <button type="submit" class="btn-filtrar mt-2"><i class="bi bi-funnel-fill me-2" aria-hidden="true"></i>Aplicar</button>
        </form>

        @if (count($visibles) > 1)
            <nav class="secciones-informe" aria-label="Secciones">
                <a href="{{ route('tendencias.index', $filtrosUrl) }}" class="chip-informe {{ $solo === null ? 'activo' : '' }}" @if ($solo === null) aria-current="page" @endif>Todas</a>
                @foreach ($visibles as $clave)
                    <a href="{{ route('tendencias.index', $filtrosUrl + ['seccion' => $clave]) }}" class="chip-informe {{ $solo === $clave ? 'activo' : '' }}" @if ($solo === $clave) aria-current="page" @endif>
                        <i class="bi {{ \App\Services\Informes\Tendencias::SECCIONES[$clave][1] }}" aria-hidden="true"></i> {{ $clave === 'novedades' ? \App\Services\Novedades\AjustesNovedades::nombreActual() : \App\Services\Informes\Tendencias::SECCIONES[$clave][0] }}
                    </a>
                @endforeach
            </nav>
        @endif

        @forelse ($secciones as $s)
            <section class="tarjeta seccion-tendencia" id="seccion-{{ $s['clave'] }}" aria-labelledby="titulo-{{ $s['clave'] }}">
                <div class="seccion-tendencia-cabecera">
                    <h2 id="titulo-{{ $s['clave'] }}"><i class="bi {{ $s['icono'] }}" aria-hidden="true"></i> {{ $s['titulo'] }}</h2>
                    @if ($puedeExportar)
                        <a href="{{ route('tendencias.exportar', $filtrosUrl + ['seccion' => $s['clave']]) }}" class="btn-exportar boton-informe"><i class="bi bi-file-earmark-excel me-2" aria-hidden="true"></i>Exportar</a>
                    @endif
                </div>
                <div class="cifras-informe">
                    @foreach ($s['cifras'] as [$titulo, $actual, $anterior, $unidad])
                        @php $cambio = $anterior === null || $unidad === 'texto' ? null : Indicadores::comparar((float) $actual, (float) $anterior); @endphp
                        <div class="cifra-informe">
                            <span class="cifra-informe-valor">{{ $formatear($actual, $unidad) }}</span>
                            <span class="cifra-informe-titulo">{{ $titulo }}</span>
                            @if ($cambio !== null)
                                <span class="cambio-informe {{ $cambio['direccion'] }}">
                                    @if ($cambio['direccion'] === 'sube')<span aria-hidden="true">▲</span>@elseif ($cambio['direccion'] === 'baja')<span aria-hidden="true">▼</span>@else<span aria-hidden="true">=</span>@endif
                                    {{ $cambio['cambio'] === null ? ($cambio['direccion'] === 'igual' ? 'Sin cambio' : 'Antes: 0') : ($cambio['cambio'] > 0 ? '+' : '').number_format($cambio['cambio'], 1).' %' }}
                                    <span class="visually-hidden">{{ ['sube' => 'subió', 'baja' => 'bajó', 'igual' => 'igual'][$cambio['direccion']] }}</span>
                                    <span class="cambio-informe-antes">antes {{ $formatear($anterior, $unidad) }}</span>
                                </span>
                            @endif
                        </div>
                    @endforeach
                </div>
                <div class="graficas-informe">
                    @foreach ($s['series'] as [$titulo, $etiquetas, $actual, $anterior])
                        <div class="grafica-informe"><h3>{{ $titulo }}</h3>{!! GraficaSvg::tiempo($etiquetas, $actual, $anterior, $titulo) !!}</div>
                    @endforeach
                    @foreach ($s['desgloses'] as [$titulo, $datos, $unidad])
                        <div class="grafica-informe"><h3>{{ $titulo }}</h3>{!! GraficaSvg::barras($datos, $titulo, $unidad) !!}</div>
                    @endforeach
                </div>
                @if ($s['nota'])<p class="small text-muted mb-0"><i class="bi bi-info-circle" aria-hidden="true"></i> {{ $s['nota'] }}</p>@endif
            </section>
        @empty
            <div class="tarjeta estado-vacio">
                <div class="icono"><i class="bi bi-graph-up" aria-hidden="true"></i></div>
                <p class="text-muted small m-0">Tu rol no puede consultar ninguno de los módulos de este informe. Pide a tu administrador que revise tus permisos.</p>
            </div>
        @endforelse
        <p class="text-muted small"><i class="bi bi-clock" aria-hidden="true"></i> Horas de {{ \App\Support\ZonasHorarias::etiqueta($periodo->zona) }}. Las cifras se calculan de nuevo cada 5 minutos.</p>
    @endif
</div>
@endsection
