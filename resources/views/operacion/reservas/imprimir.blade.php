{{--
    Hoja de la reserva para imprimir: orden de evento (qué, cuándo, dónde,
    montaje, servicios por departamento) y, si es para un cliente, cotización
    con desglose (área + servicios − descuento + IVA). Sin datos del sistema.
--}}
@php
    use App\Models\ReservaServicio;
    use App\Services\Reservas\Cotizador;
    $version = fn (string $archivo) => asset($archivo).'?v='.(@filemtime(public_path($archivo)) ?: '1');
    $local = fn ($f, $formato) => $f?->copy()->setTimezone($zona)->format($formato);
    $m = $reserva->moneda;
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
        <div><strong>{{ $reserva->para_cliente ? 'Orden de evento y cotización' : 'Orden de evento' }}</strong> · una hoja carta</div>
        <div class="res-acciones">
            <button type="button" class="res-boton verde" data-accion="imprimir"><i class="bi bi-printer" aria-hidden="true"></i>Imprimir</button>
            <a href="{{ route('reservas.show', $reserva->id) }}" class="res-boton">Volver a la reserva</a>
        </div>
    </div>

    <article class="res-hoja">
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

        <h2>{{ $reserva->titulo }}</h2>
        <table class="res-tabla">
            <tbody>
                <tr><th style="width: 32%">Área</th><td>{{ $reserva->area->nombre() }}</td></tr>
                <tr><th>Fecha y horario</th><td>{{ $textos->cuando($reserva) }}</td></tr>
                <tr><th>Montaje y desmontaje</th><td>{{ $reserva->montaje?->nombre ?? 'Sin montaje especial' }} · el área se ocupa de {{ $local($reserva->bloqueo_desde, 'H:i') }} a {{ $local($reserva->bloqueo_hasta, 'H:i') }}</td></tr>
                <tr><th>Personas</th><td>{{ $reserva->asistentes }}</td></tr>
                <tr><th>Tipo de uso</th><td>{{ $reserva->tipoUso?->nombre ?? '—' }}</td></tr>
                <tr><th>Para</th><td>{{ $reserva->para_cliente ? trim($reserva->cliente_nombre.($reserva->cliente_empresa ? ' · '.$reserva->cliente_empresa : '')).($reserva->cliente_correo ? ' · '.$reserva->cliente_correo : '').($reserva->cliente_telefono ? ' · '.$reserva->cliente_telefono : '') : 'Uso interno (sin costo)' }}</td></tr>
                <tr><th>Reservó</th><td>{{ $reserva->solicitante?->name ?? '—' }}</td></tr>
                @if ($reserva->notas)<tr><th>Notas</th><td>{{ $reserva->notas }}</td></tr>@endif
            </tbody>
        </table>

        @if ($reserva->items->isNotEmpty())
            <h2>Servicios por departamento</h2>
            <table class="res-tabla">
                <thead><tr><th>Departamento</th><th>Servicio</th><th class="num">Cantidad</th><th>Indicaciones</th></tr></thead>
                <tbody>
                    @foreach ($reserva->items->sortBy(fn ($i) => $i->departamento?->nombre) as $i)
                        <tr><td>{{ $i->departamento?->nombre ?? '—' }}</td><td>{{ $i->nombre }}</td><td class="num">{{ $i->cantidadTexto() }} {{ ReservaServicio::UNIDADES[$i->unidad][1] ?? '' }}</td><td>{{ $i->notas }}</td></tr>
                    @endforeach
                </tbody>
            </table>
        @endif

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
            <div>Reservó</div>
            <div>Responsable del área</div>
            <div>{{ $reserva->para_cliente ? 'Cliente' : 'Recibe' }}</div>
        </div>
        <p class="small text-muted mt-4 mb-0">Impreso el @fecha(now()) · {{ auth()->user()->name }}</p>
    </article>
</main>
<script src="{{ $version('js/plataforma.js') }}"></script>
</body>
</html>
