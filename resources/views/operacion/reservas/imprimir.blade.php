{{--
    Hoja de la reserva para imprimir con el formato de una «orden de evento»
    de hotel: encabezado (lugar, personas contra el aforo, fecha, solicitado
    por y su departamento, fecha de solicitud, horario, montaje y desmontaje,
    indicaciones resaltadas) y una rejilla de secciones por departamento con
    casillas (☒ lo pedido con su nota). «Mostrar hoja completa» agrega las
    casillas vacías del catálogo. Si es para un cliente, la cotización con
    desglose. Carta, una página cuando cabe. Sin datos del sistema.
--}}
@php
    use App\Services\Reservas\Cotizador;
    $version = fn (string $archivo) => asset($archivo).'?v='.(@filemtime(public_path($archivo)) ?: '1');
    $local = fn ($f, $formato) => $f?->copy()->setTimezone($zona)->format($formato);
    $m = $reserva->moneda;
    $dias = ['', 'Lunes', 'Martes', 'Miércoles', 'Jueves', 'Viernes', 'Sábado', 'Domingo'];
    $inicioLocal = $reserva->inicio->copy()->setTimezone($zona);
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $reserva->para_cliente ? 'Cotización' : 'Orden de evento' }} {{ $reserva->folioTexto() }}</title>
    <link href="{{ $version('vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ $version('vendor/bootstrap-icons/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ $version('css/plataforma.css') }}" rel="stylesheet">
    <link href="{{ $version('css/reservas.css') }}" rel="stylesheet">
</head>
<body class="bg-light">
<main>
    <div class="res-controles-impresion">
        <div><strong>{{ $reserva->para_cliente ? 'Orden de evento y cotización' : 'Orden de evento' }}</strong> · hoja carta</div>
        <div class="d-flex flex-wrap gap-2">
            <button type="button" class="btn-verde btn-accion-rh" data-accion="imprimir"><i class="bi bi-printer me-1" aria-hidden="true"></i>Imprimir</button>
            @if ($completa)
                <a href="{{ route('reservas.imprimir', $reserva->id) }}" class="btn-secundario-rh"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Solo lo pedido</a>
            @else
                <a href="{{ route('reservas.imprimir', ['reserva' => $reserva->id, 'completa' => 1]) }}" class="btn-secundario-rh"><i class="bi bi-grid-3x3 me-1" aria-hidden="true"></i>Mostrar hoja completa</a>
            @endif
            <a href="{{ route('reservas.show', $reserva->id) }}" class="btn-secundario-rh">Volver a la reserva</a>
        </div>
    </div>

    <article class="res-hoja res-orden">
        <header class="res-hoja-encabezado">
            <div>
                <div class="fw-bold">{{ $empresa?->nombre_comercial }}</div>
                @if ($empresa?->razon_social && $empresa->razon_social !== $empresa->nombre_comercial)<div class="small">{{ $empresa->razon_social }}</div>@endif
                <div class="small">{{ $reserva->sede?->nombre }}{{ $reserva->sede?->direccion ? ' · '.$reserva->sede->direccion : '' }}</div>
            </div>
            <div class="text-end">
                <h1>{{ $reserva->para_cliente ? 'Orden de evento / Cotización' : 'Orden de evento' }}</h1>
                <div class="fw-bold">{{ $reserva->folioTexto() }}</div>
                <div class="small">{{ $reserva->estadoTexto() }}</div>
            </div>
        </header>

        <h2 class="res-orden-evento">{{ $reserva->titulo }}</h2>
        <dl class="res-orden-cabecera">
            <div class="res-ancho-2"><dt>Lugar donde se realizará</dt><dd>{{ $reserva->area->rutaTexto() }}</dd></div>
            <div><dt>Núm. de pax</dt><dd>{{ $reserva->asistentes }}@if ($aforo) <span class="fw-normal">de {{ $aforo }} permitidos</span>@endif</dd></div>
            <div><dt>Fecha del evento</dt><dd>{{ $dias[$inicioLocal->dayOfWeekIso] }} {{ $inicioLocal->format('d/m/Y') }}</dd></div>
            <div><dt>Horario del evento</dt><dd>{{ $reserva->dia_completo ? 'Día completo · ' : '' }}{{ $local($reserva->inicio, 'H:i') }} a {{ $local($reserva->fin, 'H:i') }}</dd></div>
            <div class="res-ancho-2"><dt>Solicitado por</dt><dd>{{ $reserva->solicitadoPorTexto() }}</dd></div>
            <div><dt>Fecha de solicitud</dt><dd>{{ $reserva->created_at?->copy()->setTimezone($zona)->format('d/m/Y') }}</dd></div>
            <div class="res-ancho-2"><dt>Montaje / desmontaje</dt><dd>{{ $textos->tiemposMontaje($reserva) }}</dd></div>
            <div><dt>El área se ocupa</dt><dd>{{ $local($reserva->bloqueo_desde, 'H:i') }} a {{ $local($reserva->bloqueo_hasta, 'H:i') }}</dd></div>
            <div><dt>Tipo de uso</dt><dd>{{ $reserva->tipoUso?->nombre ?? '—' }}</dd></div>
            <div class="res-ancho-4"><dt>Para</dt><dd>{{ $reserva->para_cliente ? trim($reserva->cliente_nombre.($reserva->cliente_empresa ? ' · '.$reserva->cliente_empresa : '')).($reserva->cliente_correo ? ' · '.$reserva->cliente_correo : '').($reserva->cliente_telefono ? ' · '.$reserva->cliente_telefono : '') : 'Uso interno (sin costo)' }}</dd></div>
        </dl>

        @if ($reserva->indicaciones_montaje)
            <p class="res-orden-resaltado">{{ $reserva->indicaciones_montaje }}</p>
        @endif
        @if ($reserva->excede_aforo)
            <p class="res-orden-excepcion"><strong>Excepción de aforo:</strong> {{ $reserva->motivo_excepcion_aforo }}</p>
        @endif

        @if ($secciones !== [])
            <div class="res-orden-rejilla">
                @foreach ($secciones as $sec)
                    <section class="res-orden-seccion">
                        <h3>{{ $sec['nombre'] }}</h3>
                        @if ($sec['montaje'] !== null)
                            <div class="res-orden-renglon"><span class="res-casilla" aria-hidden="true">{{ $sec['montaje']['elegido'] ? '☒' : '☐' }}</span>
                                <span><strong>Tipo de montaje:</strong> {{ $sec['montaje']['elegido'] ?? '' }}</span></div>
                            @foreach ($sec['montaje']['opciones'] as $op)
                                @continue($op === $sec['montaje']['elegido'])
                                <div class="res-orden-renglon res-orden-opcion"><span class="res-casilla" aria-hidden="true">☐</span><span>{{ $op }}</span></div>
                            @endforeach
                        @endif
                        @foreach ($sec['renglones'] as $r)
                            <div class="res-orden-renglon"><span class="res-casilla" aria-hidden="true">{{ $r['marcado'] ? '☒' : '☐' }}</span>
                                <span>{{ $r['nombre'] }}@if ($r['detalle'] !== '') <span class="res-orden-nota">{{ $r['detalle'] }}</span>@endif</span>
                                <span class="visually-hidden">{{ $r['marcado'] ? '(pedido)' : '(no pedido)' }}</span></div>
                        @endforeach
                        @if ($completa)
                            <div class="res-orden-renglon"><span class="res-casilla" aria-hidden="true">☐</span><span>Otro servicio: <span class="res-orden-linea"></span></span></div>
                        @endif
                    </section>
                @endforeach
            </div>
        @else
            <p class="small text-muted">Sin servicios ni montaje especial.</p>
        @endif

        @if ($reserva->notas)<p class="small mt-2 mb-0"><strong>Notas:</strong> {{ $reserva->notas }}</p>@endif

        @if ($reserva->para_cliente)
            <h2>Cotización</h2>
            <table class="res-tabla">
                <thead><tr><th>Concepto</th><th class="num">Cantidad</th><th class="num">Precio</th><th class="num">Importe</th></tr></thead>
                <tbody>
                    <tr><td>Renta de {{ $reserva->area->nombre() }} ({{ $reserva->importe_area_manual !== null ? 'importe del evento'.($reserva->ajuste_motivo ? ': '.$reserva->ajuste_motivo : '') : (\App\Models\ReservaArea::TARIFAS[$reserva->tarifa][1] ?? 'sin tarifa') }})</td><td class="num">1</td><td class="num"></td><td class="num">{{ Cotizador::dinero($reserva->importe_area, $m) }}</td></tr>
                    @foreach ($reserva->items as $i)
                        <tr><td>{{ $i->nombre }}</td><td class="num">{{ $i->cantidadTexto() }}</td><td class="num">{{ Cotizador::dinero($i->costo_unitario, $m) }}</td><td class="num">{{ Cotizador::dinero($i->importe, $m) }}</td></tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr><td colspan="3">Subtotal</td><td class="num">{{ Cotizador::dinero((float) $reserva->importe_area + (float) $reserva->importe_servicios, $m) }}</td></tr>
                    @if ((float) $reserva->descuento > 0)
                        <tr><td colspan="3">{{ $reserva->cortesia ? 'Cortesía' : 'Descuento' }}{{ $reserva->ajuste_motivo ? ' ('.$reserva->ajuste_motivo.')' : '' }}</td><td class="num">− {{ Cotizador::dinero($reserva->descuento, $m) }}</td></tr>
                    @endif
                    @if ((float) $reserva->iva_porcentaje > 0)
                        <tr><td colspan="3">IVA {{ rtrim(rtrim(number_format((float) $reserva->iva_porcentaje, 2), '0'), '.') }} %</td><td class="num">{{ Cotizador::dinero($reserva->iva, $m) }}</td></tr>
                    @endif
                    <tr><td colspan="3">Total</td><td class="num">{{ Cotizador::dinero($reserva->total, $m) }}</td></tr>
                </tfoot>
            </table>
        @endif

        @php $aprobadas = $reserva->aprobaciones->where('vigente', true)->where('estado', 'aprobada'); @endphp
        @if ($aprobadas->isNotEmpty())
            <h2>Aprobaciones</h2>
            <p class="small mb-0">
                @foreach ($aprobadas as $a){{ $a->tipo === 'area' ? 'Área' : $a->departamento?->nombre }}: {{ $a->respondidaPor?->name }} (@fecha($a->respondida_en))@if (! $loop->last) · @endif @endforeach
            </p>
        @endif

        <div class="res-firmas">
            <div>Solicita</div>
            <div>Responsable del área</div>
            <div>{{ $reserva->para_cliente ? 'Cliente' : 'Recibe' }}</div>
        </div>
        <p class="small text-muted mt-3 mb-0">Impreso el @fecha(now()) · {{ auth()->user()->name }}</p>
    </article>
</main>
<script src="{{ $version('js/plataforma.js') }}"></script>
</body>
</html>
