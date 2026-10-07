{{--
    Ronda 8 (RT-07 / RT-08): Hoja de horarios de la SEMANA para caseta
    (SEGCAT: ruta_imprimir_dia.php, «vista informativa de la semana»).
    Página propia, sin menús, horizontal: una hoja para Llegadas y otra para
    Salidas con TODOS los horarios activos de la sede. Cada horario dice en
    qué días opera (mini semana L M X J V S D), así se ven también los
    horarios alternos (fin de semana o días sueltos). Orden de SEGCAT: todos
    los días, lunes a viernes, días sueltos y fin de semana; luego por hora.
    Una columna por paradero (hora tentativa, ✓ si para sin hora, — si no
    para ahí). La «Hoja del día» sigue disponible para una fecha.
--}}
@php
    $version = fn (string $archivo) => asset($archivo).'?v='.(@filemtime(public_path($archivo)) ?: '1');
    $meses = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $fechaGenerado = \App\Models\Ruta::DIAS_LARGOS[\App\Models\Ruta::codigoDia($generado->dayOfWeekIso)].' '.$generado->day.' de '.$meses[$generado->month].' de '.$generado->year;
    $letras = ['LU' => 'L', 'MA' => 'M', 'MI' => 'X', 'JU' => 'J', 'VI' => 'V', 'SA' => 'S', 'DO' => 'D'];
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Horario de Llegadas y Salidas · {{ $sede->nombre }}</title>
    <link href="{{ $version('vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ $version('vendor/bootstrap-icons/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ $version('css/plataforma.css') }}" rel="stylesheet">
    <link href="{{ $version('css/modos-pantalla.css') }}" rel="stylesheet">
    <script src="{{ $version('js/modo-pantalla.js') }}"></script>
</head>
<body class="pagina-rutas-hoja">
<main class="rutas-hoja rutas-hoja-horizontal">
    <div class="rutas-hoja-controles">
        <form method="GET" action="{{ route('rutas.dia', $sede->id) }}" class="rutas-hoja-fecha">
            <label for="fecha" class="fw-bold">Hoja de un día:</label>
            <input type="date" id="fecha" name="fecha" value="{{ $hoy }}" class="campo mb-0">
            <button type="submit" class="btn-cancelar rutas-boton">Ver día</button>
        </form>
        <div class="d-flex gap-2 flex-wrap">
            <button type="button" class="btn-imprimir-calcomania" data-accion="imprimir"><i class="bi bi-printer me-1" aria-hidden="true"></i> Imprimir</button>
            <a href="{{ route('rutas.sede', $sede->id) }}" class="btn-cerrar-calcomania"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Volver a la sede</a>
        </div>
    </div>

    @foreach ($hojas as $sentido => $hoja)
        <section class="rutas-hoja-pagina">
            <div class="rutas-hoja-encabezado">
                <div>
                    <h1>{{ $sede->nombre }}</h1>
                    <div class="rutas-hoja-empresa">{{ $empresaNombre }}</div>
                </div>
                <div class="rutas-hoja-fecha-texto">
                    <span class="rutas-hoja-titulo"><i class="bi {{ $sentido === 'llegada' ? 'bi-sign-turn-right-fill' : 'bi-sign-turn-left-fill' }} me-1" aria-hidden="true"></i>Horario de {{ $hoja['titulo'] }}</span>
                    <small>Vista informativa de la semana · Generado {{ $fechaGenerado }}</small>
                </div>
            </div>

            @if ($hoja['filas']->isEmpty())
                <p class="rutas-hoja-vacia"><i class="bi bi-calendar-x d-block mb-2" aria-hidden="true"></i>Sin rutas de {{ mb_strtolower($hoja['titulo']) }} registradas.</p>
            @else
                @php $repetidas = $hoja['filas']->countBy(fn ($h) => $h->ruta_id); @endphp
                <div class="rutas-hoja-tabla-scroll">
                    <table class="rutas-hoja-tabla rutas-hoja-semana">
                        <thead>
                            <tr>
                                <th class="col-hora">Sale</th>
                                <th class="col-ruta">Ruta</th>
                                <th class="col-hora">Llega</th>
                                <th class="col-dias" title="Días que opera: L M X J V S D (Lunes a Domingo)">Días</th>
                                @foreach ($hoja['columnas'] as $nombreParadero)
                                    <th class="col-paradero"><span>{{ $nombreParadero }}</span></th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($hoja['filas'] as $h)
                                @php
                                    $paradas = $h->paradas->keyBy('paradero_id');
                                    $diasHorario = $h->listaDias();
                                @endphp
                                <tr>
                                    <td class="col-hora">{{ $h->inicio() }}</td>
                                    <td class="col-ruta">
                                        <strong>{{ $h->ruta->nombre }}</strong>
                                        @if ($repetidas[$h->ruta_id] > 1)<span class="rutas-hoja-extra">{{ $h->nombre }}</span>@endif
                                        <span class="rutas-hoja-transportista">{{ $h->ruta->proveedor?->nombre }} · {{ $h->ruta->turno?->nombre }}</span>
                                    </td>
                                    <td class="col-hora">{{ $h->fin() }}@if ($h->cruzaMedianoche())<sup title="Día siguiente">+1</sup>@endif</td>
                                    <td class="col-dias" title="{{ $h->textoDias() }}" aria-label="Opera: {{ $h->textoDias() }}">
                                        <span class="mini-semana">@foreach ($letras as $codigo => $letra)<span class="{{ in_array($codigo, $diasHorario, true) ? 'si' : 'no' }}">{{ in_array($codigo, $diasHorario, true) ? $letra : '·' }}</span>@endforeach</span>
                                        <span class="patron-dias">{{ $h->patronDias() }}</span>
                                    </td>
                                    @foreach ($hoja['columnas'] as $paraderoId => $nombreParadero)
                                        @php $parada = $paradas->get($paraderoId); @endphp
                                        <td class="col-paradero {{ $parada ? 'con-parada' : '' }}">{{ $parada === null ? '—' : ($parada->horaCorta() ?: '✓') }}</td>
                                    @endforeach
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
            @php $suspendidasSentido = $suspendidas->where('sentido', $sentido); @endphp
            @if ($suspendidasSentido->isNotEmpty())
                <p class="rutas-hoja-nota"><i class="bi bi-pause-circle me-1" aria-hidden="true"></i><strong>Suspendidas (no operan):</strong> {{ $suspendidasSentido->pluck('nombre')->join(', ') }}</p>
            @endif
        </section>
    @endforeach

    <div class="rutas-hoja-pie">Generado el @fecha($generado) · {{ $identidad->get('nombre_corto') }} · Días: L M X J V S D (· = no opera) · ✓ = para ahí sin hora fija · — = no para ahí</div>
</main>
<script src="{{ $version('js/plataforma.js') }}"></script>
</body>
</html>
