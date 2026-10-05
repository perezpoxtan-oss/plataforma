{{--
    Hoja del resguardo (SEGCAT: ticket_responsiva.php, "RESGUARDO MÚLTIPLE DE
    ACTIVOS DE SEGURIDAD"). Página propia, sin menús, para imprimir o guardar
    como PDF. La firma se pide a la plataforma con permiso (disco privado).
--}}
@php
    $version = fn (string $archivo) => asset($archivo).'?v='.(@filemtime(public_path($archivo)) ?: '1');
    $nombre = $responsiva->colaborador?->nombreCompleto() ?? '—';
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Responsiva - Folio {{ $responsiva->folio }}</title>
    <link href="{{ $version('vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ $version('vendor/bootstrap-icons/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ $version('css/plataforma.css') }}" rel="stylesheet">
    <link href="{{ $version('css/modos-pantalla.css') }}" rel="stylesheet">
    <script src="{{ $version('js/modo-pantalla.js') }}"></script>
</head>
<body class="pagina-hoja-resguardo">
<div class="hoja-resguardo-acciones">
    <button type="button" class="btn-imprimir-resguardo" data-accion="imprimir"><i class="bi bi-printer me-1" aria-hidden="true"></i>Imprimir Resguardo</button>
    <a href="{{ route('responsivas.index') }}#responsiva-{{ $responsiva->id }}" class="btn-volver-resguardo"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver a Resguardos</a>
</div>

<main class="hoja-resguardo">
    <div class="hoja-resguardo-encabezado">
        <h1>RESGUARDO MÚLTIPLE DE ACTIVOS DE SEGURIDAD</h1>
        <h2>{{ $empresaNombre }}</h2>
        <div class="hoja-resguardo-folio">FOLIO: {{ $responsiva->folio }}</div>
    </div>

    @unless ($responsiva->enCampo())
        <div class="hoja-resguardo-cerrado">LOTE CERRADO · Devuelto a caseta el @fecha($responsiva->devuelto_en, 'd/m/Y H:i') ({{ $responsiva->recibio?->name ?? '—' }})</div>
    @endunless

    <div class="hoja-resguardo-emision">
        <strong>Ubicación Emisión:</strong> {{ $responsiva->sede?->nombre }}<br>
        <strong>Fecha Exacta:</strong> @fecha($responsiva->entregado_en, 'd/m/Y H:i:s')
    </div>

    <div class="hoja-resguardo-caja">
        <div class="hoja-resguardo-titulo">Datos del Colaborador Resguardante</div>
        <div class="hoja-resguardo-nombre">{{ $nombre }}</div>
        <div><strong>No. Nómina / Empleado:</strong> {{ $responsiva->colaborador?->num_empleado ?? 'Provisional' }}</div>
        @if ($responsiva->colaborador?->puesto)
            <div><strong>Puesto:</strong> {{ $responsiva->colaborador->puesto->nombre }}</div>
        @endif
    </div>

    <div class="hoja-resguardo-tabla-scroll">
        <table class="hoja-resguardo-tabla">
            <thead>
                <tr><th>Tipo de Activo</th><th>Marca / Modelo</th><th>Núm. Serie (S/N)</th><th>Modalidad</th></tr>
            </thead>
            <tbody>
                @foreach ($responsiva->equipos as $f)
                    <tr>
                        <td>{{ $f->equipo?->tipo?->nombre ?? 'Equipo' }}</td>
                        <td>{{ trim(($f->equipo?->marca ?? '').' '.($f->equipo?->modelo ?? '')) ?: '—' }}</td>
                        <td class="hoja-resguardo-serie">{{ $f->equipo?->numero_serie }}</td>
                        <td>{{ \App\Models\EquipoResponsiva::MODALIDADES_HOJA[$f->modalidad] ?? $f->modalidad }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="hoja-resguardo-legal">
        <p>Por medio de la presente, el colaborador asume la total y estricta responsabilidad por la custodia, cuidado y buen uso del(los) equipo(s) enlistado(s) en la tabla superior. Certifica haberlos recibido en óptimas condiciones físicas y operativas.</p>
        <p><strong>Cláusula de Daños y Extravío:</strong> En caso de pérdida, robo o daño derivado de mal uso o negligencia, autorizo a <strong>{{ $empresaNombre }}</strong> para realizar el descuento vía nómina por el costo de reparación o reposición integral del activo.</p>
    </div>

    <div class="hoja-resguardo-firmas">
        <div class="hoja-resguardo-firma">
            <div class="hoja-resguardo-firma-imagen">
                <img src="{{ route('responsivas.firma', $responsiva->id) }}" alt="Firma de {{ $nombre }}">
            </div>
            <div class="hoja-resguardo-linea">FIRMA DE CONFORMIDAD<br><span>{{ $nombre }}</span></div>
        </div>
        <div class="hoja-resguardo-firma">
            <div class="hoja-resguardo-firma-imagen"></div>
            <div class="hoja-resguardo-linea">ENTREGADO POR (CASETA)<br><span>{{ $responsiva->entrego?->name ?? '—' }}</span></div>
        </div>
    </div>
</main>
<script src="{{ $version('js/plataforma.js') }}"></script>
</body>
</html>
