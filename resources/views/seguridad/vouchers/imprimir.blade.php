{{--
    Voucher de reposición (SEGCAT: voucher_imprimir.php): una sola hoja
    carta con 3 copias separadas por líneas de corte. Ronda 5 (LL-04): las
    copias son para Seguridad, Recepción y Administración (el colaborador
    firma, pero no recibe copia). Con firma digital, las firmas ya salen
    impresas; con firma física, se firman a mano.
--}}
@php
    $version = fn (string $archivo) => asset($archivo).'?v='.(@filemtime(public_path($archivo)) ?: '1');
    $responsable = $voucher->colaborador ? $voucher->colaborador->nombreCompleto().' (Núm. '.$voucher->colaborador->num_empleado.')' : 'No especificado';
    $motivo = \App\Models\VoucherReposicion::MOTIVOS[$voucher->motivo] ?? $voucher->motivo;
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Voucher {{ $voucher->folio }}</title>
    <link href="{{ $version('vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ $version('vendor/bootstrap-icons/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ $version('css/plataforma.css') }}" rel="stylesheet">
    <link href="{{ $version('css/modos-pantalla.css') }}" rel="stylesheet">
    <script src="{{ $version('js/modo-pantalla.js') }}"></script>
</head>
<body class="pagina-impresion-voucher">
<main>
    <div class="controles-impresion">
        <h1>Voucher de Reposición — 3 copias en una sola hoja</h1>
        @if ($voucher->firma_modo === 'digital')
            <p>Recorta por las líneas punteadas: una copia para Seguridad, otra para Recepción y otra para Administración. Las firmas digitales ya van impresas.</p>
        @else
            <p>Recorta por las líneas punteadas: una copia para Seguridad, otra para Recepción y otra para Administración. Seguridad y el responsable firman cada copia a mano.</p>
        @endif
        <div class="acciones">
            <button type="button" class="btn-imprimir-calcomania" data-accion="imprimir"><i class="bi bi-printer me-2" aria-hidden="true"></i>Imprimir Voucher</button>
            <a href="{{ route('vouchers.index') }}" class="btn-cerrar-calcomania"><i class="bi bi-receipt me-1" aria-hidden="true"></i> Ver todos los vouchers</a>
        </div>
    </div>

    <div class="hoja-voucher">
        @foreach (\App\Models\VoucherReposicion::COPIAS as $copia)
            @unless ($loop->first)
                <div class="linea-corte" aria-hidden="true"><span>✂ recortar aquí</span></div>
            @endunless
            <section class="copia-voucher" aria-label="{{ $copia }}">
                <div class="copia-etiqueta">{{ $copia }}</div>
                <div class="voucher-encabezado">
                    <h2>Voucher de Reposición</h2>
                    <div class="voucher-folio-impreso">{{ $voucher->folio }}</div>
                </div>

                <div class="voucher-datos">
                    <div><div class="dato-etiqueta">Empresa</div><div class="dato-valor">{{ $empresaNombre }}</div></div>
                    <div><div class="dato-etiqueta">Sede</div><div class="dato-valor">{{ $voucher->sede?->nombre ?? '—' }}</div></div>
                    <div><div class="dato-etiqueta">Fecha</div><div class="dato-valor">@fecha($voucher->created_at)</div></div>
                    <div><div class="dato-etiqueta">{{ $voucher->etiquetaOrigen() }}</div><div class="dato-valor">{{ $voucher->origen_descripcion }}</div></div>
                    <div><div class="dato-etiqueta">Motivo</div><div class="dato-valor">{{ $motivo }}</div></div>
                    <div><div class="dato-etiqueta">Responsable</div><div class="dato-valor">{{ $responsable }}</div></div>
                    @if ($voucher->descripcion)
                        <div class="dato-completo"><div class="dato-etiqueta">Cómo pasó</div><div class="dato-valor normal">{!! nl2br(e($voucher->descripcion)) !!}</div></div>
                    @endif
                </div>

                <div class="voucher-cobro-firmas">
                    <div class="caja-cxc {{ $voucher->aplica_cobro ? 'con-cobro' : 'sin-cobro' }}">
                        <span class="dato-etiqueta">CXC:</span>
                        <span class="cxc-monto">{{ $voucher->aplica_cobro ? '$'.number_format((float) $voucher->monto, 2).' MXN' : 'NO APLICA' }}</span>
                    </div>
                    @if ($voucher->aplica_cobro)
                        <div class="caja-referencia"><span class="dato-etiqueta">Referencia de pago (a mano):</span></div>
                    @endif
                    <div class="voucher-genero"><span class="dato-etiqueta">Generó:</span> {{ $voucher->creado_por_nombre ?? '—' }}</div>
                </div>

                <div class="voucher-firmas">
                    <div>
                        <div class="firma-espacio">@if ($voucher->firma_seguridad)<img src="{{ route('vouchers.firma', [$voucher->id, 'seguridad']) }}" alt="Firma de Seguridad" class="firma-impresa">@endif</div>
                        <div class="firma-linea">Seguridad</div>
                    </div>
                    <div>
                        <div class="firma-espacio">@if ($voucher->firma_responsable)<img src="{{ route('vouchers.firma', [$voucher->id, 'responsable']) }}" alt="Firma del responsable" class="firma-impresa">@endif</div>
                        <div class="firma-linea">Responsable{{ $voucher->colaborador ? ': '.$voucher->colaborador->nombreCompleto() : '' }}</div>
                    </div>
                    <div><div class="firma-espacio"></div><div class="firma-linea">Recibe ({{ \Illuminate\Support\Str::after($copia, 'Copia ') }})</div></div>
                </div>
            </section>
        @endforeach
    </div>
</main>
<script src="{{ $version('js/plataforma.js') }}"></script>
</body>
</html>
