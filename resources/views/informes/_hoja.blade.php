{{--
    Hoja imprimible de los informes (ADR-0017): carta vertical, sin menús, con
    el logo de Identidad, la empresa, la fecha y el periodo. «Guardar como PDF»
    es el diálogo de impresión del navegador, como en el resto de la plataforma.
--}}
@php
    $version = fn (string $archivo) => asset($archivo).'?v='.(@filemtime(public_path($archivo)) ?: '1');
    $logo = $identidad->get('logo_claro') ?: $identidad->get('simbolo');
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $titulo }} · {{ $empresaNombre }}</title>
    <link href="{{ $version('vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ $version('vendor/bootstrap-icons/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ $version('css/plataforma.css') }}" rel="stylesheet">
</head>
<body class="pagina-hoja-informe">
<main class="hoja-informe">
    <div class="hoja-informe-controles">
        <button type="button" class="btn-filtrar" data-accion="imprimir"><i class="bi bi-printer me-2" aria-hidden="true"></i>Imprimir o guardar como PDF</button>
        <a href="{{ $volver }}" class="btn-cancelar boton-informe"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver</a>
    </div>
    <header class="hoja-informe-encabezado">
        <div class="hoja-informe-logo">
            @if ($logo)
                <img src="{{ asset($logo) }}" alt="{{ $identidad->get('nombre_corto') }}">
            @else
                <i class="bi bi-shield-lock-fill" aria-hidden="true"></i>
            @endif
        </div>
        <div class="flex-grow-1">
            <div class="hoja-informe-empresa">{{ $empresaNombre }}</div>
            <h1>{{ $titulo }}</h1>
            <div class="hoja-informe-periodo">{{ $periodo }}</div>
        </div>
        <div class="hoja-informe-fecha">Generado el<br><strong>@fecha($generado)</strong></div>
    </header>

    {!! $contenido !!}

    <footer class="hoja-informe-pie">{{ $identidad->get('nombre_corto') }} · {{ $titulo }} · {{ $empresaNombre }} · @fecha($generado)</footer>
</main>
<script src="{{ $version('js/plataforma.js') }}"></script>
</body>
</html>
