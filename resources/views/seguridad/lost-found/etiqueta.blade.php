{{--
    Etiqueta de la bolsa de un artículo de Lost & Found (SEGCAT: lf_etiqueta.php,
    "Imprimir Etiqueta"). El QR se dibuja en el servidor y solo lleva la
    dirección /e/{código}: al escanearlo con el lector universal (o con la
    cámara del celular) se abre la ficha del artículo. SEGCAT lo pedía a
    api.qrserver.com (un tercero) con el folio adentro.
--}}
@php
    $version = fn (string $archivo) => asset($archivo).'?v='.(@filemtime(public_path($archivo)) ?: '1');
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Etiqueta L&amp;F · {{ $a->folio }}</title>
    <link href="{{ $version('vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ $version('vendor/bootstrap-icons/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ $version('css/plataforma.css') }}" rel="stylesheet">
    <link href="{{ $version('css/modos-pantalla.css') }}" rel="stylesheet">
    <script src="{{ $version('js/modo-pantalla.js') }}"></script>
</head>
<body class="pagina-calcomania">
<main class="calcomania-contenedor etiqueta-lf-contenedor">
    <div class="calcomania etiqueta-lf">
        <div class="calcomania-empresa">LOST &amp; FOUND — {{ $a->sede?->nombre }}</div>
        <div class="etiqueta-lf-folio">{{ $a->folio }}</div>
        <div class="calcomania-qr etiqueta-lf-qr" role="img" aria-label="Código QR del artículo {{ $a->folio }}">{!! $qr !!}</div>
        <div class="etiqueta-lf-objeto">{{ $a->objeto }}</div>
        <div class="etiqueta-lf-dato">@fecha($a->created_at, 'd/m/Y'){{ $a->areaEspecifica ? ' — '.$a->areaEspecifica->nombre : ($a->lugar_detalle ? ' — '.$a->lugar_detalle : '') }}</div>
        <div class="etiqueta-lf-dato">{{ collect([trim($a->marca.' '.$a->color), $a->etiquetaTipo()])->filter()->join(' · ') }}</div>
        @if ($a->ubicacion_bodega)
            <div class="etiqueta-lf-bodega">Bodega: {{ $a->ubicacion_bodega }}</div>
        @endif
        @unless ($a->enResguardo())
            <div class="calcomania-baja">{{ mb_strtoupper($a->etiquetaEstatus()) }}</div>
        @endunless
        <div class="calcomania-pie">{{ $empresaNombre }} · {{ $identidad->get('nombre_corto') }}</div>
    </div>
    <div class="calcomania-acciones">
        <button type="button" class="btn-imprimir-calcomania etiqueta-lf-imprimir" data-accion="imprimir"><i class="bi bi-printer me-1" aria-hidden="true"></i> Imprimir Etiqueta</button>
        <a href="{{ route('lost_found.articulos.show', $a->id) }}" class="btn-cerrar-calcomania"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Volver a la ficha del artículo</a>
    </div>
</main>
<script src="{{ $version('js/plataforma.js') }}"></script>
</body>
</html>
