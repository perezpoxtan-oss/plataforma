{{--
    Itinerario de una ruta (SEGCAT: ruta_itinerario_pdf.php). Página propia,
    sin menús, para imprimir o guardar como PDF: datos de la ruta y, por cada
    horario, sus días, sus horas y sus paraderos en orden.
--}}
@php
    $version = fn (string $archivo) => asset($archivo).'?v='.(@filemtime(public_path($archivo)) ?: '1');
    $llegada = $ruta->esLlegada();
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Itinerario · {{ $ruta->nombre }}</title>
    <link href="{{ $version('vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ $version('vendor/bootstrap-icons/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ $version('css/plataforma.css') }}" rel="stylesheet">
    <link href="{{ $version('css/modos-pantalla.css') }}" rel="stylesheet">
    <script src="{{ $version('js/modo-pantalla.js') }}"></script>
</head>
<body class="pagina-rutas-hoja">
<main class="rutas-hoja rutas-hoja-vertical">
    <div class="rutas-hoja-encabezado">
        <div>
            <div class="rutas-hoja-empresa">{{ $empresaNombre }} · {{ $ruta->sede->nombre }}</div>
            <h1>{{ $ruta->nombre }}</h1>
        </div>
        <span class="rutas-sentido {{ $llegada ? 'llegada' : 'salida' }}">{{ $llegada ? 'Llegada a la Sede' : 'Salida de la Sede' }}</span>
    </div>

    @unless ($ruta->activo)
        <div class="calcomania-baja mb-3 text-center">RUTA SUSPENDIDA</div>
    @endunless

    <div class="rutas-itinerario-datos">
        <div><strong>Turno</strong>{{ $ruta->turno?->nombre ?? '—' }}@if ($ruta->turno) ({{ $ruta->turno->inicio() }} - {{ $ruta->turno->fin() }})@endif</div>
        <div><strong>Empresa Transportista</strong>{{ $ruta->proveedor?->nombre ?? 'No asignada' }}</div>
        <div><strong>Sede</strong>{{ $ruta->sede->nombre }}</div>
        <div><strong>Costo Máximo por Taxi</strong>{{ $ruta->costo_maximo_taxi !== null ? '$'.number_format((float) $ruta->costo_maximo_taxi, 2) : 'Sin tope' }}</div>
    </div>

    @foreach ($ruta->horarios as $h)
        <section class="rutas-itinerario-horario">
            <div class="rutas-itinerario-destacado">
                <div class="rutas-itinerario-etiqueta">{{ mb_strtolower($h->nombre) === mb_strtolower($h->textoDias()) ? $h->textoDias() : $h->nombre.' · '.$h->textoDias() }}</div>
                <div class="rutas-itinerario-hora">{{ $h->inicio() }} — {{ $h->fin() }}@if ($h->cruzaMedianoche()) <small>(día siguiente)</small>@endif</div>
            </div>
            @if ($h->paradas->isNotEmpty())
                <table class="rutas-itinerario-tabla">
                    <thead><tr><th>#</th><th>Paradero</th><th class="col-hora">Hora Tentativa</th></tr></thead>
                    <tbody>
                        @foreach ($h->paradas as $i => $p)
                            <tr>
                                <td>{{ $i + 1 }}</td>
                                <td>{{ $p->paradero?->nombre }}</td>
                                <td class="col-hora">{{ $p->horaCorta() ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p class="rutas-hoja-vacia">Este horario no tiene paraderos intermedios configurados.</p>
            @endif
        </section>
    @endforeach

    <div class="rutas-hoja-pie text-center">Itinerario informativo · Generado el @fecha($generado) · {{ $identidad->get('nombre_corto') }} · Consulta con tu supervisor ante cualquier duda o cambio de última hora.</div>

    <div class="calcomania-acciones">
        <button type="button" class="btn-imprimir-calcomania" data-accion="imprimir"><i class="bi bi-printer me-1" aria-hidden="true"></i> Imprimir / Guardar como PDF</button>
        <a href="{{ route('rutas.sede', ['sede' => $ruta->sede_id, 'tab' => $llegada ? 'llegadas' : 'salidas']) }}#ruta-{{ $ruta->id }}" class="btn-cerrar-calcomania"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Volver a la sede</a>
    </div>
</main>
<script src="{{ $version('js/plataforma.js') }}"></script>
</body>
</html>
