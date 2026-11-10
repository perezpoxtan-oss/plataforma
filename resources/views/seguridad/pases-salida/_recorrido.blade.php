{{--
    «Recorrido del pase»: cada momento físico del pase (sale, llega a destino,
    sale de regreso, regresa), con lo Programado (lo que capturó quien lo
    pidió) y lo Real (lo que registró la caseta). En escritorio es una tabla;
    en celular, tarjetas apiladas. $recorrido: AdministradorPasesSalida::recorrido().
--}}
<section class="recorrido-pase" aria-labelledby="titulo-recorrido-{{ $pase->id }}">
    <div class="recorrido-pase-cabecera">
        <h2 id="titulo-recorrido-{{ $pase->id }}" class="titulo-seccion-pase m-0"><i class="bi bi-signpost-2 me-2 text-primary" aria-hidden="true"></i>Recorrido del pase</h2>
        @if ($recorrido['definitivo'])
            <span class="chip-recorrido definitivo"><i class="bi bi-box-arrow-right" aria-hidden="true"></i> Salida definitiva, no espera regreso</span>
        @elseif ($pase->estado === \App\Models\PaseSalida::REGRESADO)
            <span class="chip-recorrido"><i class="bi bi-check2-circle" aria-hidden="true"></i> {{ $pase->cerrado_con_faltantes ? 'Cerrado con faltantes' : 'Ya regresó a '.$pase->sede?->nombre }}</span>
        @elseif ($pase->estado !== \App\Models\PaseSalida::CANCELADO)
            <span class="chip-recorrido"><i class="bi bi-arrow-repeat" aria-hidden="true"></i> Espera regreso a {{ $pase->sede?->nombre }}</span>
        @endif
    </div>
    <table class="tabla-recorrido">
        <thead>
            <tr><th scope="col">Momento</th><th scope="col">Programado</th><th scope="col">Real</th></tr>
        </thead>
        <tbody>
            @foreach ($recorrido['renglones'] as $r)
                <tr class="recorrido-{{ $r['estado'] }}" data-recorrido="{{ $r['clave'] }}">
                    <th scope="row"><span class="recorrido-punto" aria-hidden="true"></span>{{ $r['momento'] }}</th>
                    <td data-etiqueta="Programado">{{ $r['programado'] ?? '—' }}</td>
                    <td data-etiqueta="Real">
                        @if ($r['real'])
                            <strong>{{ $r['real'] }}</strong>
                        @endif
                        @if ($r['texto'])
                            <span class="recorrido-texto">{{ $r['texto'] }}</span>
                        @endif
                        @if ($r['nota'])
                            <span class="recorrido-nota">{{ $r['nota'] }}</span>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <p class="recorrido-ayuda">Programado: lo que se capturó al pedir el pase. Real: la fecha y hora que registró la caseta.</p>
</section>
