{{-- Bloques del Informe ejecutivo (pantalla e impresión). $imprimir = sin listados ni enlaces --}}
@foreach ($bloques as $bloque)
    <section class="bloque-informe" aria-labelledby="bloque-{{ $loop->index }}">
        <h2 id="bloque-{{ $loop->index }}" class="bloque-informe-titulo"><i class="bi {{ $bloque['icono'] }}" aria-hidden="true"></i> {{ $bloque['titulo'] }}</h2>
        <div class="cifras-informe">
            @foreach ($bloque['fichas'] as $f)
                <div class="cifra-informe {{ $f['nivel'] ? 'nivel-'.$f['nivel'] : '' }}">
                    <span class="cifra-informe-valor">{{ $f['valor'] }}</span>
                    <span class="cifra-informe-titulo">{{ $f['titulo'] }}</span>
                    @if ($f['nota'])<span class="cifra-informe-nota">{{ $f['nota'] }}</span>@endif
                    @if (! $imprimir && $f['enlace'] && ! $f['grafica'] && $f['columnas'] === [])
                        <a href="{{ $f['enlace'] }}" class="cifra-informe-enlace">Ir al módulo <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                    @endif
                </div>
            @endforeach
        </div>
        @foreach ($bloque['fichas'] as $f)
            @if ($f['grafica'] || $f['columnas'] !== [])
                <div class="detalle-informe">
                    <h3 class="detalle-informe-titulo">{{ $f['titulo'] }}</h3>
                    @if ($f['grafica']){!! $f['grafica'] !!}@endif
                    @if ($imprimir && ! $f['grafica'] && $f['columnas'] !== [])
                        {{-- Sin gráfica (p. ej. vacantes publicadas): en la hoja va la tabla, máximo 25 renglones --}}
                        <table class="table table-sm tabla-informe">
                            <thead><tr>@foreach ($f['columnas'] as $c)<th scope="col">{{ $c }}</th>@endforeach</tr></thead>
                            <tbody>@foreach (array_slice($f['filas'], 0, 25) as $fila)<tr>@foreach ($fila as $celda)<td>{{ $celda }}</td>@endforeach</tr>@endforeach</tbody>
                        </table>
                    @endif
                    @unless ($imprimir)
                        @if ($f['columnas'] !== [])
                            <details class="listado-informe">
                                <summary><i class="bi bi-table me-1" aria-hidden="true"></i>Ver / Ocultar listado de registros</summary>
                                <div class="tabla-scroll">
                                    <table class="table table-sm tabla-informe">
                                        <thead><tr>@foreach ($f['columnas'] as $c)<th scope="col">{{ $c }}</th>@endforeach</tr></thead>
                                        <tbody>
                                            @forelse ($f['filas'] as $fila)
                                                <tr>@foreach ($fila as $celda)<td>{{ $celda }}</td>@endforeach</tr>
                                            @empty
                                                <tr><td colspan="{{ count($f['columnas']) }}" class="text-center text-muted">Sin registros.</td></tr>
                                            @endforelse
                                        </tbody>
                                    </table>
                                </div>
                                @if (count($f['filas']) >= \App\Services\Informes\InformeEjecutivo::MAX_FILAS)
                                    <p class="small text-muted mt-1 mb-0">Se muestran los primeros {{ \App\Services\Informes\InformeEjecutivo::MAX_FILAS }}; el listado completo está en el módulo.</p>
                                @endif
                            </details>
                        @endif
                        @if ($f['enlace'])
                            <a href="{{ $f['enlace'] }}" class="cifra-informe-enlace">Ir al módulo <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                        @endif
                    @endunless
                </div>
            @endif
        @endforeach
    </section>
@endforeach
