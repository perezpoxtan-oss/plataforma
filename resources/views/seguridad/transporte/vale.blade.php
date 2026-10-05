{{--
    "VALE DE CAJA CHICA - TAXI DE OPERACIÓN" (SEGCAT: ticket_taxi.php): una
    hoja carta con 3 copias (Contabilidad, Caseta y Operador de taxi) para
    recortar. Las firmas capturadas en pantalla salen impresas; si no hay,
    queda la línea para firmar a mano.
--}}
@php
    $version = fn (string $archivo) => asset($archivo).'?v='.(@filemtime(public_path($archivo)) ?: '1');
    $colaboradores = $m->pasajeros->map(fn ($c) => $c->nombreCompleto().' ['.$c->num_empleado.']')->implode(', ');
    $copias = ['1. COPIA PARA CONTABILIDAD / CAJA CHICA', '2. COPIA PARA CONTROL INTERNO DE CASETA', '3. COPIA PARA EL OPERADOR DE TAXI'];
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Planilla de Vales de Caja Chica - Folio {{ $m->folio() }}</title>
    <link href="{{ $version('vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ $version('vendor/bootstrap-icons/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ $version('css/plataforma.css') }}" rel="stylesheet">
    <link href="{{ $version('css/modos-pantalla.css') }}" rel="stylesheet">
    <script src="{{ $version('js/modo-pantalla.js') }}"></script>
</head>
<body class="pagina-impresion-voucher pagina-vale-taxi">
<main>
    <div class="controles-impresion">
        <h1>Vale de Caja Chica — Taxi {{ $m->folio() }}</h1>
        <p>3 copias en una hoja. Recorta por las líneas punteadas.@unless ($m->firma_taxista) Seguridad, el conductor y quien autoriza firman a mano.@endunless</p>
        <div class="acciones">
            <button type="button" class="btn-imprimir-calcomania" data-accion="imprimir"><i class="bi bi-printer me-2" aria-hidden="true"></i>Ejecutar Impresión de Hoja Completa</button>
            <a href="{{ route('transporte.show', $m->id) }}" class="btn-cerrar-calcomania"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Ver el registro</a>
        </div>
    </div>

    <div class="hoja-vale-taxi">
        @foreach ($copias as $copia)
            @unless ($loop->first)
                <div class="linea-corte" aria-hidden="true"><span>✂ recortar aquí</span></div>
            @endunless
            <section class="vale-talon {{ $m->anulado ? 'anulado' : '' }}" aria-label="{{ $copia }}">
                @if ($m->anulado)
                    <div class="vale-sello-anulado" aria-hidden="true">ANULADO</div>
                @endif
                <div class="vale-encabezado">
                    <div>
                        <h2>VALE DE CAJA CHICA - TAXI DE OPERACIÓN</h2>
                        <small>{{ mb_strtoupper($empresa->nombre_comercial) }}</small>
                    </div>
                    <div class="vale-leyenda">{{ $copia }}</div>
                </div>

                <div class="vale-folio-monto">
                    <div>
                        <strong>FOLIO REGISTRO:</strong> <span class="vale-folio">{{ $m->folio() }}</span><br>
                        <strong>EMISIÓN:</strong> @fecha($m->created_at, 'd/m/Y H:i')
                    </div>
                    <div class="vale-monto">${{ number_format((float) $m->monto, 2) }} MXN</div>
                </div>

                <div class="vale-datos">
                    <div><strong>Sede Origen:</strong> {{ $m->sede?->nombre }}</div>
                    <div><strong>Ruta Incidente:</strong> {{ $m->ruta?->nombre }} ({{ $m->etiquetaTipo() }})</div>
                    <div><strong>Unidad / Eco:</strong> {{ $m->vehiculo?->placas }}{{ $m->vehiculo?->numero_economico ? ' — Eco. '.$m->vehiculo->numero_economico : '' }}</div>
                    <div><strong>Nombre Chofer:</strong> {{ $m->chofer?->nombre_completo }}</div>
                    <div class="completo"><strong>Destino / Recorrido autorizado:</strong> {{ $m->paradero?->nombre }}</div>
                    @if ($m->justificacion)
                        <div class="completo"><strong>Justificación de costo:</strong> {{ $m->justificacion }}</div>
                    @endif
                </div>

                <div class="vale-colaboradores"><strong>COLABORADORES ABORDADOS ({{ $m->cantidad_pax }}):</strong> {{ $colaboradores ?: 'Sin registrar' }}</div>

                <div class="vale-firmas">
                    <div>
                        @if ($m->firma_guardia)
                            <img src="{{ route('transporte.firma', [$m->id, 'guardia']) }}" alt="Firma del guardia" class="vale-firma-img">
                        @else
                            <div class="vale-firma-espacio"></div>
                        @endif
                        <div class="vale-firma-linea">Seguridad (Caseta)</div>
                        <div class="vale-firma-nombre">{{ $m->registro?->name }}</div>
                    </div>
                    <div>
                        @if ($m->firma_taxista)
                            <img src="{{ route('transporte.firma', [$m->id, 'taxista']) }}" alt="Firma del conductor" class="vale-firma-img">
                        @else
                            <div class="vale-firma-espacio"></div>
                        @endif
                        <div class="vale-firma-linea">Conductor (Recibí)</div>
                        <div class="vale-firma-nombre">{{ $m->chofer?->nombre_completo }}</div>
                    </div>
                    <div>
                        <div class="vale-firma-espacio">@if ($m->autorizado())<span class="vale-vobo">Vo.Bo. electrónico · @fecha($m->autorizado_en, 'd/m/Y H:i')</span>@endif</div>
                        <div class="vale-firma-linea">Vo.Bo Autorización</div>
                        <div class="vale-firma-nombre">{{ $m->autorizador?->name }}</div>
                    </div>
                </div>
            </section>
        @endforeach
    </div>
</main>
<script src="{{ $version('js/plataforma.js') }}"></script>
</body>
</html>
