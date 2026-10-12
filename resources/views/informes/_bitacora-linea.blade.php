{{-- Línea de tiempo de la Bitácora general del día (pantalla e impresión) --}}
@if ($conteos !== [])
    <div class="conteos-bitacora" aria-label="Resumen del día">
        @foreach ($conteos as $clave => $n)
            <span class="conteo-bitacora tipo-{{ $clave }}"><i class="bi {{ \App\Services\Informes\BitacoraDelDia::TIPOS[$clave][1] }}" aria-hidden="true"></i> {{ \App\Services\Informes\BitacoraDelDia::TIPOS[$clave][0] }}: {{ $n }}</span>
        @endforeach
        <span class="conteo-bitacora total">Total: {{ count($eventos) }}</span>
    </div>
@endif
@if ($eventos === [])
    <p class="sin-eventos-bitacora">Sin movimientos registrados este día.</p>
@else
    <ol class="linea-bitacora">
        @foreach ($eventos as $e)
            <li class="evento-bitacora tipo-{{ $e['tipo'] }}">
                <span class="evento-bitacora-hora">{{ $e['hora'] }}</span>
                <span class="evento-bitacora-tipo">{{ $e['etiqueta'] }}</span>
                <span class="evento-bitacora-texto">
                    @if (! $imprimir && $e['enlace'])<a href="{{ $e['enlace'] }}">{{ $e['texto'] }}</a>@else{{ $e['texto'] }}@endif
                    @if ($sedes->count() > 1 && $sede === null)<small class="evento-bitacora-sede">{{ $e['sede'] }}</small>@endif
                </span>
            </li>
        @endforeach
    </ol>
@endif
