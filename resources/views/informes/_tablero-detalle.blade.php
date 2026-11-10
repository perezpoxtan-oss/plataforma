{{-- Detalle de una ficha del Tablero (dentro del diálogo o en su página propia) --}}
<div class="detalle-ficha-informe" data-titulo-ficha="{{ $detalle['titulo'] }}">
    {!! $detalle['grafica'] !!}
    <p class="small text-muted mt-2 mb-2"><strong>{{ number_format($detalle['total']) }}</strong> en total{{ count($detalle['filas']) < $detalle['total'] ? ' · el listado muestra los primeros '.count($detalle['filas']) : '' }}.</p>
    <details class="listado-informe">
        <summary><i class="bi bi-table me-1" aria-hidden="true"></i>Ver / Ocultar listado de registros</summary>
        <div class="tabla-scroll">
            <table class="table table-sm tabla-informe">
                <thead><tr>@foreach ($detalle['columnas'] as $c)<th scope="col">{{ $c }}</th>@endforeach</tr></thead>
                <tbody>
                    @forelse ($detalle['filas'] as $fila)
                        <tr>@foreach ($fila as $celda)<td>{{ $celda }}</td>@endforeach</tr>
                    @empty
                        <tr><td colspan="{{ count($detalle['columnas']) }}" class="text-center text-muted">Sin registros.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </details>
    <a href="{{ $detalle['enlace'] }}" class="btn-filtrar mt-3"><i class="bi bi-box-arrow-up-right me-2" aria-hidden="true"></i>{{ $detalle['textoEnlace'] }}</a>
</div>
