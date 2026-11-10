{{--
    Secciones de Tendencias (pantalla e impresión). $imprimir = sin botones de Exportar.
    Cada cifra comparable dice si MEJORÓ (verde) o EMPEORÓ (rojo) según si para
    ese indicador subir es bueno o malo (Tendencias::SENTIDOS); las neutras dicen
    «subió» o «bajó» sin color. La flecha marca hacia dónde se movió la cifra.
--}}
@php
    use App\Services\Informes\Indicadores;
    use App\Support\GraficaSvg;
    $formatear = function ($valor, string $unidad): string {
        if ($unidad === 'texto') { return (string) $valor; }
        return GraficaSvg::numero((float) $valor, $unidad === '%' ? '' : $unidad).($unidad === '%' ? ' %' : '');
    };
@endphp
@forelse ($secciones as $s)
    <section class="tarjeta seccion-tendencia" id="seccion-{{ $s['clave'] }}" aria-labelledby="titulo-{{ $s['clave'] }}">
        <div class="seccion-tendencia-cabecera">
            <h2 id="titulo-{{ $s['clave'] }}"><i class="bi {{ $s['icono'] }}" aria-hidden="true"></i> {{ $s['titulo'] }}</h2>
            @if (! $imprimir && $puedeExportar)
                <a href="{{ route('tendencias.exportar', $filtrosUrl + ['seccion' => $s['clave']]) }}" class="btn-exportar boton-informe"><i class="bi bi-file-earmark-excel me-2" aria-hidden="true"></i>Exportar</a>
            @endif
        </div>
        <div class="cifras-informe">
            @foreach ($s['cifras'] as [$titulo, $actual, $anterior, $unidad, $sentido])
                @php $cambio = $anterior === null || $unidad === 'texto' ? null : Indicadores::valorar((float) $actual, (float) $anterior, $sentido); @endphp
                <div class="cifra-informe">
                    <span class="cifra-informe-valor">{{ $formatear($actual, $unidad) }}</span>
                    <span class="cifra-informe-titulo">{{ $titulo }}</span>
                    @if ($cambio !== null)
                        <span class="cambio-informe {{ $cambio['direccion'] }} {{ $cambio['estado'] }}">
                            @if ($cambio['direccion'] === 'sube')<span aria-hidden="true">▲</span>@elseif ($cambio['direccion'] === 'baja')<span aria-hidden="true">▼</span>@else<span aria-hidden="true">=</span>@endif
                            <span class="cambio-informe-texto">{{ $cambio['texto'] }}</span>
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
