{{--
    Etiqueta del equipo (SEGCAT: equipo_ticket.php, "Imprimir Etiqueta").
    Página propia, sin menús, para imprimir. El QR viene dibujado del
    servidor en SVG y solo lleva la dirección /e/{código}: ningún dato del
    equipo ni de la empresa, y no se consulta a terceros.
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
    <title>Etiqueta · {{ $equipo->numero_serie }}</title>
    <link href="{{ $version('vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ $version('vendor/bootstrap-icons/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ $version('css/plataforma.css') }}" rel="stylesheet">
    <link href="{{ $version('css/modos-pantalla.css') }}" rel="stylesheet">
    <script src="{{ $version('js/modo-pantalla.js') }}"></script>
</head>
<body class="pagina-calcomania">
<main class="calcomania-contenedor">
    <div class="calcomania etiqueta-equipo">
        <div class="calcomania-empresa"><i class="bi bi-building-check" aria-hidden="true"></i> {{ $empresaNombre }}</div>
        <div class="etiqueta-equipo-tipo">{{ $equipo->tipo->nombre ?? 'Equipo' }}</div>
        <div class="etiqueta-equipo-serie">{{ $equipo->numero_serie }}</div>
        <div class="calcomania-qr etiqueta-equipo-qr" role="img" aria-label="Código QR del equipo {{ $equipo->numero_serie }}">{!! $qr !!}</div>
        <div class="calcomania-codigo">{{ $codigoLegible }}</div>
        <div class="calcomania-detalle">
            @if (trim($equipo->marca.' '.$equipo->modelo) !== '')
                <div><span>Marca/Modelo:</span> <strong>{{ trim($equipo->marca.' '.$equipo->modelo) }}</strong></div>
            @endif
            <div><span>Sede:</span> <strong>{{ $equipo->sede->nombre ?? '—' }}</strong></div>
        </div>
        @if ($equipo->estado === 'baja')
            <div class="calcomania-baja">EQUIPO DADO DE BAJA</div>
        @endif
        <div class="calcomania-pie">Inventario de Equipos · {{ $identidad->get('nombre_corto') }}</div>
    </div>
    <div class="calcomania-acciones">
        <button type="button" class="btn-imprimir-calcomania" data-accion="imprimir"><i class="bi bi-printer me-1" aria-hidden="true"></i> Imprimir Etiqueta</button>
        <a href="{{ route('equipos.index') }}#equipo-{{ $equipo->id }}" class="btn-cerrar-calcomania"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Volver al Inventario de Equipos</a>
    </div>
</main>
<script src="{{ $version('js/plataforma.js') }}"></script>
</body>
</html>
