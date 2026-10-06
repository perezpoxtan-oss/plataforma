{{--
    Etiqueta del equipo de Protección Civil (SEGCAT: pc_equipos_ticket.php,
    «Imprimir Etiqueta»). Página propia, sin menús, para imprimir. El QR viene
    dibujado del servidor en SVG y solo lleva la dirección /e/{código}: no se
    consulta a terceros (SEGCAT usaba api.qrserver.com).
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
    <title>Etiqueta PC · {{ $equipo->numero_serie }}</title>
    <link href="{{ $version('vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ $version('vendor/bootstrap-icons/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ $version('css/plataforma.css') }}" rel="stylesheet">
    <link href="{{ $version('css/modos-pantalla.css') }}" rel="stylesheet">
    <script src="{{ $version('js/modo-pantalla.js') }}"></script>
</head>
<body class="pagina-calcomania">
<main class="calcomania-contenedor">
    <div class="calcomania etiqueta-pc">
        <div class="calcomania-empresa"><i class="bi bi-building-check" aria-hidden="true"></i> {{ $equipo->sede->nombre ?? $empresaNombre }}</div>
        <div class="etiqueta-pc-tipo"><i class="bi bi-shield-fill-check" aria-hidden="true"></i> {{ $equipo->etiquetaCategoria() }}</div>
        <div class="etiqueta-pc-serie">{{ $equipo->numero_serie }}</div>
        <div class="calcomania-qr" role="img" aria-label="Código QR del equipo {{ $equipo->numero_serie }}">{!! $qr !!}</div>
        <div class="calcomania-codigo">{{ $codigoLegible }}</div>
        @if ($ubicacion || $equipo->referencia)
            <div class="etiqueta-pc-ubicacion">{{ implode(' · ', array_filter([$ubicacion, $equipo->referencia])) }}</div>
        @endif
        @unless ($equipo->activo)
            <div class="calcomania-baja">EQUIPO DADO DE BAJA</div>
        @endunless
        <div class="calcomania-pie">Protección Civil · {{ $identidad->get('nombre_corto') }} — la misma dirección sirve para grabar una etiqueta NFC</div>
    </div>
    <div class="calcomania-acciones">
        <button type="button" class="btn-imprimir-calcomania etiqueta-pc-imprimir" data-accion="imprimir"><i class="bi bi-printer me-1" aria-hidden="true"></i> Imprimir Etiqueta</button>
        <a href="{{ route('equipos_pc.index') }}#equipopc-{{ $equipo->id }}" class="btn-cerrar-calcomania"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Volver al Catálogo de Equipos</a>
    </div>
</main>
<script src="{{ $version('js/plataforma.js') }}"></script>
</body>
</html>
