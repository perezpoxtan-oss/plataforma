{{--
    Acuse de Recibo — Lost & Found (SEGCAT: lf_acuse.php): comprobante para
    el colaborador que encontró y entregó los artículos.
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
    <title>Acuse de Recibo — Lost &amp; Found {{ $n->folio() }}</title>
    <link href="{{ $version('vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ $version('vendor/bootstrap-icons/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ $version('css/plataforma.css') }}" rel="stylesheet">
    <link href="{{ $version('css/modos-pantalla.css') }}" rel="stylesheet">
    <script src="{{ $version('js/modo-pantalla.js') }}"></script>
</head>
<body class="pagina-impresion-novedad">
<main>
    <div class="controles-impresion">
        <h1>Acuse de Recibo listo</h1>
        <p>Entrégalo a quien encontró los artículos: es su comprobante.</p>
        <div class="acciones">
            <button type="button" class="btn-imprimir-calcomania" data-accion="imprimir"><i class="bi bi-printer me-2" aria-hidden="true"></i>Imprimir / Guardar PDF</button>
            <a href="{{ route('novedades.index', ['abrir' => $n->id]) }}" class="btn-cerrar-calcomania"><i class="bi bi-folder2-open me-1" aria-hidden="true"></i> Volver al expediente</a>
        </div>
    </div>

    <article class="hoja-expediente acuse-lf">
        <header class="encabezado-impresion">
            <div>
                <h2>Acuse de Recibo — Lost &amp; Found</h2>
                <div class="subtitulo-impresion">Ticket {{ $n->folio() }} — {{ $empresaNombre }} / {{ $n->sede?->nombre }} — @fecha($n->created_at)</div>
            </div>
        </header>

        <div class="datos-acuse">
            <strong>Recibido de:</strong> {{ $n->reportado_por }}<br>
            <strong>Ubicación reportada:</strong> {{ $n->ubicacion }}
        </div>

        <table class="tabla-impresion">
            <thead><tr><th>Folio</th><th>Objeto</th><th>Tipo de Valor</th><th>Marca / Color</th><th>Área Encontrado</th></tr></thead>
            <tbody>
                @forelse ($n->articulos as $a)
                    <tr>
                        <td>{{ $a->folio }}</td>
                        <td>{{ $a->objeto }}</td>
                        <td>{{ $a->etiquetaTipo() }}</td>
                        <td>{{ trim(($a->marca ?? '').' '.($a->color ?? '')) ?: '—' }}</td>
                        <td>{{ collect([$a->areaEspecifica?->nombre, $a->lugar_detalle])->filter()->join(' · ') ?: '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5">Sin artículos registrados en este ticket.</td></tr>
                @endforelse
            </tbody>
        </table>

        <p class="small">Este documento confirma que el/los artículo(s) anteriores fueron recibidos por Seguridad y quedaron registrados en el sistema con los folios indicados. Consérvalo como comprobante de entrega.</p>

        <div class="firmas-impresion dos">
            <div><div class="firma-espacio"></div><div class="firma-linea">Firma de quien entrega</div></div>
            <div><div class="firma-espacio"></div><div class="firma-linea">Recibe por Seguridad: {{ auth()->user()->name }}</div></div>
        </div>
    </article>
</main>
<script src="{{ $version('js/plataforma.js') }}"></script>
</body>
</html>
