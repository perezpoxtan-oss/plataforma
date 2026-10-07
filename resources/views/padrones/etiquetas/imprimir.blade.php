{{--
    Ronda 6 (LL-06): hoja de etiquetas QR en bloque (Padrones → Etiquetas QR).
    Página propia, sin menús, como las etiquetas de llaveros. Cada etiqueta
    lleva el QR (dibujado en el servidor, solo con la dirección /e/{código}:
    ningún dato del registro), el nombre y el código legible para teclearlo.
    El tamaño sale de EtiquetasMasivas::TAMANOS (clase etiqueta-qr-{tamaño}).
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
    <title>Etiquetas QR · {{ $empresaNombre }}</title>
    <link href="{{ $version('vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ $version('vendor/bootstrap-icons/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ $version('css/plataforma.css') }}" rel="stylesheet">
    <link href="{{ $version('css/modos-pantalla.css') }}" rel="stylesheet">
    <script src="{{ $version('js/modo-pantalla.js') }}"></script>
</head>
<body class="pagina-etiquetas-llaves pagina-etiquetas-qr">
<main>
    <div class="panel-etiquetas-llaves">
        <h1>Etiquetas QR listas</h1>
        <p>{{ $etiquetas->count() }} {{ $etiquetas->count() === 1 ? 'etiqueta' : 'etiquetas' }} · {{ $medidas[0] }} ({{ $medidas[3] }}).</p>
        @if ($recortadas)
            <p class="panel-etiquetas-nota"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Marcaste más de {{ \App\Services\Lector\EtiquetasMasivas::MAXIMO }}: aquí van las primeras {{ \App\Services\Lector\EtiquetasMasivas::MAXIMO }}. Imprime el resto en otra hoja.</p>
        @endif
        <div class="panel-etiquetas-acciones">
            <button type="button" class="btn-imprimir-calcomania" data-accion="imprimir"><i class="bi bi-printer me-1" aria-hidden="true"></i> Imprimir Etiquetas</button>
            <a href="{{ route('etiquetas.index') }}" class="btn-cerrar-calcomania"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Volver a Etiquetas QR</a>
        </div>
        <p class="panel-etiquetas-nota"><i class="bi bi-info-circle" aria-hidden="true"></i> Al escanear el QR con la cámara del celular (o con el lector de la caseta) se abre la ficha del registro. Solo funciona con sesión iniciada en esta empresa. En la impresora elige «Tamaño real / 100 %».</p>
    </div>

    <div class="hoja-etiquetas-qr tamano-{{ $tamano }}">
        @foreach ($etiquetas as $e)
            <div class="etiqueta-qr etiqueta-qr-{{ $tamano }} {{ $e['activo'] ? '' : 'de-baja' }}">
                <div class="etiqueta-qr-codigo-img" role="img" aria-label="Código QR de {{ $e['titulo'] }}">{!! $e['qr'] !!}</div>
                <div class="etiqueta-qr-cuerpo">
                    <div class="etiqueta-qr-empresa">{{ $empresaNombre }}</div>
                    <div class="etiqueta-qr-nombre">{{ $e['titulo'] }}</div>
                    <div class="etiqueta-qr-detalle">{{ $e['tipo_nombre'] }}</div>
                    <div class="etiqueta-qr-legible">{{ $e['codigo'] }}</div>
                    @unless ($e['activo'])
                        <div class="etiqueta-llave-baja">DE BAJA</div>
                    @endunless
                </div>
            </div>
        @endforeach
    </div>
</main>
<script src="{{ $version('js/plataforma.js') }}"></script>
</body>
</html>
