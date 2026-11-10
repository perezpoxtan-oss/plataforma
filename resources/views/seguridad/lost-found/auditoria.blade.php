{{--
    Auditoría de Inventario — Lost & Found (SEGCAT: lf_auditoria_pdf.php).
    "Inventario ciego": lista lo que el sistema dice que debería estar
    físicamente en bodega, para cotejarlo contra las existencias reales. Sin
    totales grandes al principio, para no anclar a quien cuenta.
    Opcional: una sede y un rango de fechas en que se encontraron.
--}}
@php
    $version = fn (string $archivo) => asset($archivo).'?v='.(@filemtime(public_path($archivo)) ?: '1');
    $rango = collect([
        isset($filtros['desde']) ? 'desde el '.\Illuminate\Support\Carbon::createFromFormat('Y-m-d', $filtros['desde'])->format('d/m/Y') : null,
        isset($filtros['hasta']) ? 'hasta el '.\Illuminate\Support\Carbon::createFromFormat('Y-m-d', $filtros['hasta'])->format('d/m/Y') : null,
    ])->filter()->join(' ');
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Auditoría de Inventario — Lost &amp; Found</title>
    <link href="{{ $version('vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ $version('vendor/bootstrap-icons/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ $version('css/plataforma.css') }}" rel="stylesheet">
    <link href="{{ $version('css/modos-pantalla.css') }}" rel="stylesheet">
    <script src="{{ $version('js/modo-pantalla.js') }}"></script>
</head>
<body class="pagina-impresion-novedad">
<main>
    <div class="controles-impresion controles-auditoria-lf">
        <h1>Auditoría de Inventario lista</h1>
        <p>Imprímela y marca cada artículo conforme lo encuentres en bodega. Puedes acotarla por sede o por la fecha en que se encontraron.</p>
        @if ($errors->any())
            <div class="alert alert-danger py-2 small" role="alert">@foreach ($errors->all() as $e)<div>{{ $e }}</div>@endforeach</div>
        @endif
        <form method="GET" data-autoenviar action="{{ route('lost_found.auditoria') }}" class="filtros-auditoria-lf">
            <div>
                <label class="campo-etiqueta" for="aud_sede">Sede</label>
                <select id="aud_sede" name="sede" class="campo">
                    <option value="">Todas mis sedes</option>
                    @foreach ($sedesFiltro as $s)
                        <option value="{{ $s->id }}" @selected((int) ($filtros['sede'] ?? 0) === $s->id)>{{ $s->nombre }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="campo-etiqueta" for="aud_desde">Encontrados desde</label>
                <input type="date" id="aud_desde" name="desde" class="campo" value="{{ $filtros['desde'] ?? '' }}">
            </div>
            <div>
                <label class="campo-etiqueta" for="aud_hasta">Hasta</label>
                <input type="date" id="aud_hasta" name="hasta" class="campo" value="{{ $filtros['hasta'] ?? '' }}">
            </div>
            <button type="submit" class="btn-buscar-pases"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Aplicar</button>
        </form>
        <div class="acciones">
            <button type="button" class="btn-imprimir-calcomania" data-accion="imprimir"><i class="bi bi-printer me-2" aria-hidden="true"></i>Imprimir / Guardar PDF</button>
            <a href="{{ route('lost_found.archivo') }}" class="btn-cerrar-calcomania"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Volver a Lost &amp; Found</a>
        </div>
    </div>

    <article class="hoja-expediente">
        <header class="encabezado-impresion">
            <div>
                <h2>Auditoría de Inventario — Lost &amp; Found</h2>
                <div class="subtitulo-impresion">{{ $empresaNombre }}@if ($sede) / {{ $sede->nombre }}@endif — Generado el @fecha(now())</div>
            </div>
        </header>
        <p class="small">Artículos que deberían estar físicamente en resguardo hoy{{ $rango !== '' ? ' (encontrados '.$rango.')' : '' }}. Marca la casilla conforme confirmes cada artículo contra existencias reales. Anota cualquier discrepancia al final.</p>

        <table class="tabla-impresion tabla-auditoria-lf">
            <thead>
                <tr>
                    <th class="col-check">✓</th>
                    <th>Folio</th>
                    <th>Objeto</th>
                    <th>Tipo</th>
                    <th>Marca / Color</th>
                    <th>Área Específica</th>
                    <th>Ubicación en Bodega</th>
                    @if ($variasSedes)<th>Sede</th>@endif
                </tr>
            </thead>
            <tbody>
                @forelse ($articulos as $a)
                    <tr>
                        <td class="col-check"><span class="casilla-auditoria" aria-hidden="true"></span></td>
                        <td>{{ $a->folio }}</td>
                        <td>{{ $a->objeto }}</td>
                        <td>{{ $a->etiquetaTipo() }}</td>
                        <td>{{ trim($a->marca.' '.$a->color) ?: '—' }}</td>
                        <td>{{ collect([$a->areaEspecifica?->nombre, $a->lugar_detalle])->filter()->join(' · ') ?: '—' }}</td>
                        <td>{{ $a->ubicacion_bodega ?: '—' }}</td>
                        @if ($variasSedes)<td>{{ $a->sede?->nombre }}</td>@endif
                    </tr>
                @empty
                    <tr><td colspan="{{ $variasSedes ? 8 : 7 }}">No hay artículos en resguardo actualmente.</td></tr>
                @endforelse
            </tbody>
        </table>

        <section class="discrepancias-lf">
            <strong>Discrepancias encontradas:</strong>
            <div class="renglon-lf"></div>
            <div class="renglon-lf"></div>
            <div class="renglon-lf"></div>
        </section>
        <div class="firmas-impresion dos">
            <div><div class="firma-espacio"></div><div class="linea-firma-lf">Realizó el conteo</div></div>
            <div><div class="firma-espacio"></div><div class="linea-firma-lf">Cotejó / Gerente de Seguridad</div></div>
        </div>
        <div class="pie-impresion">{{ $articulos->count() }} artículo(s) · {{ $identidad->get('nombre_corto') }}</div>
    </article>
</main>
<script src="{{ $version('js/plataforma.js') }}"></script>
</body>
</html>
