{{--
    «Reporte de Auditoría — Recorridos de Protección Civil» (SEGCAT:
    recorridos_pc_pdf.php). Página propia para imprimir o guardar como PDF
    desde el navegador, con filtro por fechas (hora local) y sede.
--}}
@php
    $version = fn (string $archivo) => asset($archivo).'?v='.(@filemtime(public_path($archivo)) ?: '1');
    $dia = fn (string $fecha) => \Carbon\Carbon::createFromFormat('Y-m-d', $fecha)->format('d/m/Y');
    $consulta = array_filter($filtros, fn ($v) => $v !== null);
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Reporte de Auditoría — Recorridos de Protección Civil</title>
    <link href="{{ $version('vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ $version('vendor/bootstrap-icons/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ $version('css/plataforma.css') }}" rel="stylesheet">
    <link href="{{ $version('css/modos-pantalla.css') }}" rel="stylesheet">
    <script src="{{ $version('js/modo-pantalla.js') }}"></script>
</head>
<body class="pagina-reporte-rpc">
<main class="hoja-reporte-rpc">
    <form method="GET" action="{{ route('recorridos_pc.reporte') }}" class="rpc-reporte-filtros no-imprimir" aria-label="Filtrar el reporte">
        <label><span>Desde</span><input type="date" name="desde" value="{{ $filtros['desde'] }}" class="campo"></label>
        <label><span>Hasta</span><input type="date" name="hasta" value="{{ $filtros['hasta'] }}" class="campo"></label>
        @if ($sedes->count() > 1)
            <label><span>Sede</span>
                <select name="sede" class="campo">
                    <option value="">Todas las sedes</option>
                    @foreach ($sedes as $s)
                        <option value="{{ $s->id }}" @selected($filtros['sede'] === $s->id)>{{ $s->nombre }}</option>
                    @endforeach
                </select>
            </label>
        @endif
        <div class="rpc-reporte-botones">
            <button type="submit" class="btn-rpc btn-rpc-oscuro"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Filtrar</button>
            <button type="button" class="btn-rpc btn-rpc-verde" data-accion="imprimir"><i class="bi bi-printer me-1" aria-hidden="true"></i>Imprimir / Guardar PDF</button>
            @if ($puedeExportar)
                <a href="{{ route('recorridos_pc.exportar', $consulta) }}" class="btn-rpc btn-rpc-contorno"><i class="bi bi-file-earmark-excel-fill me-1" aria-hidden="true"></i>Exportar Excel</a>
            @endif
            <a href="{{ route('recorridos_pc.index') }}" class="btn-rpc btn-rpc-claro"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver</a>
        </div>
    </form>

    <h1>Reporte de Auditoría — Recorridos de Protección Civil</h1>
    <div class="subtitulo">{{ $empresaNombre }} · Del {{ $dia($filtros['desde']) }} al {{ $dia($filtros['hasta']) }} — generado el @fecha(now())</div>

    <div class="resumen">
        <div>Recorridos realizados<strong>{{ $resumen['recorridos'] }}</strong></div>
        <div>Equipos revisados<strong>{{ $resumen['equipos'] }}</strong></div>
        <div>Hallazgos<strong class="{{ $resumen['hallazgos'] > 0 ? 'falla' : 'ok' }}">{{ $resumen['hallazgos'] }}</strong></div>
    </div>

    @forelse ($recorridos as $r)
        <div class="tabla-scroll">
            <table>
                <thead>
                    <tr class="sesion-header">
                        <td colspan="4">
                            {{ $r->folio() }} · @fecha($r->created_at) —
                            {{ $r->sede?->nombre }}{{ $r->espacio ? ' / '.$r->espacio->nombre : '' }} —
                            Realizó: {{ $r->creador?->name ?? '—' }} —
                            <span class="{{ $r->estatus === \App\Models\RecorridoPc::CON_HALLAZGOS ? 'falla' : 'ok' }}">{{ mb_strtoupper($r->etiquetaEstatus()) }}</span>
                            @if ($r->novedad) — Ticket {{ $r->novedad->folio() }} @endif
                        </td>
                    </tr>
                    <tr><th>Identificador</th><th>Categoría</th><th>Ubicación</th><th>Resultado</th></tr>
                </thead>
                <tbody>
                    @forelse ($r->revisiones as $p)
                        @php $fallas = $p->criteriosConFalla(); @endphp
                        <tr>
                            <td>{{ $p->identificador }}</td>
                            <td>{{ $p->etiquetaCategoria() }}</td>
                            <td>{{ $p->ubicacion ?? '—' }}</td>
                            <td class="{{ $p->esFalla() ? 'falla' : 'ok' }}">
                                {{ $p->esFalla() ? 'FALLA' : 'OK' }}@if ($fallas !== []) — Falla en: {{ implode(', ', $fallas) }}@endif
                                @if ($p->observaciones) — {{ $p->observaciones }}@endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-muted">Sin equipos revisados.</td></tr>
                    @endforelse
                    @if ($r->observaciones_generales)
                        <tr><td colspan="4"><strong>Observaciones generales:</strong> {{ $r->observaciones_generales }}</td></tr>
                    @endif
                </tbody>
            </table>
        </div>
    @empty
        <p>No hay recorridos registrados en este rango de fechas.</p>
    @endforelse
</main>
<script src="{{ $version('js/plataforma.js') }}"></script>
</body>
</html>
