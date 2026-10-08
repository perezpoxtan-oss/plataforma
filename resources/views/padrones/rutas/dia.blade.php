{{--
    Hoja del día para caseta (SEGCAT: ruta_imprimir_dia.php). Página propia,
    sin menús, horizontal: una hoja para Llegadas y otra para Salidas, con las
    rutas activas que salen ese día ordenadas por hora y una columna por
    paradero (hora tentativa, ✓ si para sin hora, — si no para ahí).
--}}
@php
    $version = fn (string $archivo) => asset($archivo).'?v='.(@filemtime(public_path($archivo)) ?: '1');
    $meses = [1 => 'enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];
    $fechaLegible = \App\Models\Ruta::DIAS_LARGOS[\App\Models\Ruta::codigoDia($fecha->dayOfWeekIso)].' '.$fecha->day.' de '.$meses[$fecha->month].' de '.$fecha->year;
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Hoja del día · {{ $sede->nombre }}</title>
    <link href="{{ $version('vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ $version('vendor/bootstrap-icons/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ $version('css/plataforma.css') }}" rel="stylesheet">
    <link href="{{ $version('css/modos-pantalla.css') }}" rel="stylesheet">
    <script src="{{ $version('js/modo-pantalla.js') }}"></script>
</head>
<body class="pagina-rutas-hoja">
<main class="rutas-hoja rutas-hoja-horizontal">
    <div class="rutas-hoja-controles">
        <form method="GET" data-autoenviar action="{{ route('rutas.dia', $sede->id) }}" class="rutas-hoja-fecha">
            <label for="fecha" class="fw-bold">Día:</label>
            <input type="date" id="fecha" name="fecha" value="{{ $fecha->toDateString() }}" class="campo mb-0">
            <button type="submit" class="btn-cancelar rutas-boton">Ver</button>
            @if ($fecha->toDateString() !== $hoy)
                <a href="{{ route('rutas.dia', $sede->id) }}" class="btn-cancelar rutas-boton">Hoy</a>
            @endif
        </form>
        <div class="d-flex gap-2 flex-wrap">
            {{-- Ronda 8 (RT-07): la hoja principal es la de la semana --}}
            <a href="{{ route('rutas.semana', $sede->id) }}" class="btn-cancelar rutas-boton"><i class="bi bi-calendar-week me-1" aria-hidden="true"></i> Semana completa</a>
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
                    <span class="rutas-hoja-titulo"><i class="bi {{ $sentido === 'llegada' ? 'bi-sign-turn-right-fill' : 'bi-sign-turn-left-fill' }} me-1" aria-hidden="true"></i>{{ $hoja['titulo'] }} del día</span>
                    <small>{{ $fechaLegible }}</small>
                </div>
            </div>

            @if ($hoja['filas']->isEmpty())
                <p class="rutas-hoja-vacia"><i class="bi bi-calendar-x d-block mb-2" aria-hidden="true"></i>Sin rutas de {{ mb_strtolower($hoja['titulo']) }} que operen este día.</p>
            @else
                @php
                    $repetidas = $hoja['filas']->countBy(fn ($h) => $h->ruta_id);
                @endphp
                <div class="rutas-hoja-tabla-scroll">
                    <table class="rutas-hoja-tabla">
                        <thead>
                            <tr>
                                <th class="col-hora">Sale</th>
                                <th class="col-ruta">Ruta</th>
                                <th class="col-hora">Llega</th>
                                @foreach ($hoja['columnas'] as $nombreParadero)
                                    <th class="col-paradero"><span>{{ $nombreParadero }}</span></th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($hoja['filas'] as $h)
                                @php $paradas = $h->paradas->keyBy('paradero_id'); @endphp
                                <tr>
                                    <td class="col-hora">{{ $h->inicio() }}</td>
                                    <td class="col-ruta">
                                        <strong>{{ $h->ruta->nombre }}</strong>
                                        @if ($repetidas[$h->ruta_id] > 1)<span class="rutas-hoja-extra">{{ $h->nombre }}</span>@endif
                                        <span class="rutas-hoja-transportista">{{ $h->ruta->proveedor?->nombre }} · {{ $h->ruta->turno?->nombre }}</span>
                                    </td>
                                    <td class="col-hora">{{ $h->fin() }}@if ($h->cruzaMedianoche())<sup title="Día siguiente">+1</sup>@endif</td>
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
            {{-- Ronda 8 (RT-07): por qué no aparece una ruta (opera otros días o está suspendida) --}}
            @php
                $noOperanSentido = $noOperan->filter(fn ($h) => $h->ruta->sentido === $sentido);
                $suspendidasSentido = $suspendidas->where('sentido', $sentido);
            @endphp
            @if ($noOperanSentido->isNotEmpty())
                <p class="rutas-hoja-nota"><i class="bi bi-calendar-minus me-1" aria-hidden="true"></i><strong>No operan este día:</strong>
                    {{ $noOperanSentido->map(fn ($h) => $h->ruta->nombre.' '.$h->inicio().' ('.$h->textoDias().')')->join(' · ') }}</p>
            @endif
            @if ($suspendidasSentido->isNotEmpty())
                <p class="rutas-hoja-nota"><i class="bi bi-pause-circle me-1" aria-hidden="true"></i><strong>Suspendidas (no operan):</strong> {{ $suspendidasSentido->pluck('nombre')->join(', ') }}</p>
            @endif
        </section>
    @endforeach

    <div class="rutas-hoja-pie">Generado el @fecha($generado) · {{ $identidad->get('nombre_corto') }} · ✓ = para ahí sin hora fija · — = no para ahí</div>
</main>
<script src="{{ $version('js/plataforma.js') }}"></script>
</body>
</html>
