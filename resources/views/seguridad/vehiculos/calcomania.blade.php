{{--
    Calcomanía del vehículo (SEGCAT: vehiculo_ticket.php, "Imprimir Calcomanía").
    Página propia, sin menús, para imprimir. El QR viene dibujado del
    servidor en SVG y solo contiene la dirección /vehiculos/qr/{código}:
    ningún dato personal ni del vehículo, y no se consulta a terceros.
--}}
@php
    $version = fn (string $archivo) => asset($archivo).'?v='.(@filemtime(public_path($archivo)) ?: '1');
    $tipo = $vehiculo->tipo === 'otro' && $vehiculo->descripcion_otro ? $vehiculo->descripcion_otro : (\App\Models\Vehiculo::TIPOS[$vehiculo->tipo] ?? '—');
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Calcomanía · {{ $vehiculo->placas }}</title>
    <link href="{{ $version('vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ $version('vendor/bootstrap-icons/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ $version('css/plataforma.css') }}" rel="stylesheet">
    <link href="{{ $version('css/modos-pantalla.css') }}" rel="stylesheet">
    <script src="{{ $version('js/modo-pantalla.js') }}"></script>
</head>
<body class="pagina-calcomania">
<main class="calcomania-contenedor">
    <div class="calcomania">
        <div class="calcomania-empresa"><i class="bi bi-building-check" aria-hidden="true"></i> {{ $empresaNombre }}</div>
        <div class="calcomania-placas">{{ $vehiculo->placas }}</div>
        <div class="calcomania-qr" role="img" aria-label="Código QR del vehículo {{ $vehiculo->placas }}">{!! $qr !!}</div>
        <div class="calcomania-codigo">{{ $codigoLegible }}</div>
        <div class="calcomania-detalle">
            <div><span>Marca/Modelo:</span> <strong>{{ trim($vehiculo->marca.' '.$vehiculo->modelo) ?: '—' }}</strong></div>
            <div><span>Color:</span> <strong>{{ $vehiculo->color ?: '—' }}</strong></div>
            <div><span>Tipo:</span> <strong>{{ $tipo }}</strong></div>
            <div><span>Categoría:</span> <strong>{{ \App\Models\Vehiculo::PROPIEDADES[$vehiculo->propiedad] ?? $vehiculo->propiedad }}</strong></div>
            @if ($vehiculo->numero_economico)
                <div><span>No. Económico:</span> <strong>{{ $vehiculo->numero_economico }}</strong></div>
            @endif
            @if ($vehiculo->proveedor)
                <div><span>Empresa:</span> <strong>{{ $vehiculo->proveedor->nombre }}</strong></div>
            @endif
        </div>
        @unless ($vehiculo->activo)
            <div class="calcomania-baja">VEHÍCULO DADO DE BAJA</div>
        @endunless
        <div class="calcomania-pie">Padrón Vehicular · {{ $identidad->get('nombre_corto') }}</div>
    </div>
    <div class="calcomania-acciones">
        <button type="button" class="btn-imprimir-calcomania" data-accion="imprimir"><i class="bi bi-printer me-1" aria-hidden="true"></i> Imprimir Calcomanía</button>
        <a href="{{ route('vehiculos.index') }}#vehiculo-{{ $vehiculo->id }}" class="btn-cerrar-calcomania"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Volver al Padrón Vehicular</a>
    </div>
</main>
<script src="{{ $version('js/plataforma.js') }}"></script>
</body>
</html>
