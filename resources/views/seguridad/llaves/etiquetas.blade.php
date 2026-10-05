{{--
    Etiquetas de llaveros (SEGCAT: llave_imprimir.php, "Etiquetas de Llaveros
    Listas"). Página propia, sin menús, para imprimir en hoja carta: cada
    etiqueta mide 6.5 × 3.5 cm (micas de acrílico de la caseta).
    El QR viene dibujado del servidor en SVG y solo contiene la dirección
    /e/{código}: ningún dato de la llave, y no se consulta a terceros.
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
    <title>Etiquetas de llaveros · {{ $empresaNombre }}</title>
    <link href="{{ $version('vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ $version('vendor/bootstrap-icons/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ $version('css/plataforma.css') }}" rel="stylesheet">
    <link href="{{ $version('css/modos-pantalla.css') }}" rel="stylesheet">
    <script src="{{ $version('js/modo-pantalla.js') }}"></script>
</head>
<body class="pagina-etiquetas-llaves">
<main>
    <div class="panel-etiquetas-llaves">
        <h1>Etiquetas de Llaveros Listas</h1>
        <p>{{ $llaves->count() }} {{ $llaves->count() === 1 ? 'etiqueta' : 'etiquetas' }} · Ideal para micas de acrílico rígidas de control en caseta.</p>
        <div class="panel-etiquetas-acciones">
            <button type="button" class="btn-imprimir-calcomania" data-accion="imprimir"><i class="bi bi-printer me-1" aria-hidden="true"></i> Imprimir Etiquetas</button>
            <a href="{{ route('llaves.index') }}" class="btn-cerrar-calcomania"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Volver al Catálogo de Llaves</a>
        </div>
        <p class="panel-etiquetas-nota"><i class="bi bi-info-circle" aria-hidden="true"></i> Al escanear el QR con la cámara del celular (o acercar el llavero NFC con la dirección grabada) se abre la ficha de la llave. Solo funciona con sesión iniciada en esta empresa.</p>
    </div>

    <div class="hoja-etiquetas-llaves">
        @foreach ($llaves as $l)
            <div class="etiqueta-llave">
                <div class="etiqueta-llave-barra tipo-{{ $l->tipo_dispositivo }}"></div>
                <div class="etiqueta-llave-cuerpo">
                    <div class="etiqueta-llave-empresa">{{ $empresaNombre }}</div>
                    <div class="etiqueta-llave-nombre">{{ $l->nomenclatura }}</div>
                    <div><span class="etiqueta-llave-alcance">{{ $l->etiquetaAlcance() }}</span></div>
                    <div class="etiqueta-llave-lugar"><i class="bi bi-geo-alt" aria-hidden="true"></i> {{ $l->sede?->nombre }} · {{ $l->resumenLugares() }}</div>
                    <div class="etiqueta-llave-codigo">{{ trim(chunk_split($l->codigo_qr, 4, ' ')) }}</div>
                    @unless ($l->activo)
                        <div class="etiqueta-llave-baja">DADA DE BAJA</div>
                    @endunless
                </div>
                <div class="etiqueta-llave-qr" role="img" aria-label="Código QR de la llave {{ $l->nomenclatura }}">{!! $qrs[$l->id] !!}</div>
            </div>
        @endforeach
    </div>
</main>
<script src="{{ $version('js/plataforma.js') }}"></script>
</body>
</html>
