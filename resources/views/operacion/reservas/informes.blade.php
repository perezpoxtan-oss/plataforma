@extends('layouts.app')

@section('titulo', 'Informes de reservas')

@php use App\Services\Reservas\Cotizador; $m = $datos['totales']['moneda']; @endphp

@section('contenido')
<div class="tema-verde">
    @include('administracion.partes.avisos')
    @include('operacion.reservas._nav', ['titulo' => 'Informes de reservas', 'descripcion' => 'Ocupación por área, reservas por tipo e ingresos de eventos para clientes (solo reservas confirmadas).', 'icono' => 'bi-bar-chart', 'actual' => 'informes'])

    <form method="GET" action="{{ route('reservas.informes') }}" class="filtros-rh">
        <label class="filtro-fecha"><span>Del</span><input type="date" id="desde" name="desde" value="{{ $desde->toDateString() }}"></label>
        <label class="filtro-fecha"><span>al</span><input type="date" id="hasta" name="hasta" value="{{ $hasta->toDateString() }}"></label>
        @if ($sedes->count() > 1)
            <select name="sede" class="filtro-select" aria-label="Sede">
                <option value="">Todas las sedes</option>
                @foreach ($sedes as $s)<option value="{{ $s->id }}" @selected($sede === $s->id)>{{ $s->nombre }}</option>@endforeach
            </select>
        @endif
        <button type="submit" class="btn-verde btn-accion-rh"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Ver</button>
        @if ($puede['exportar'])
            <a href="{{ route('reservas.informes.exportar', array_filter(['desde' => $desde->toDateString(), 'hasta' => $hasta->toDateString(), 'sede' => $sede])) }}" class="btn-secundario-rh"><i class="bi bi-file-earmark-spreadsheet me-1" aria-hidden="true"></i>Descargar en Excel</a>
        @endif
    </form>

    <div class="res-cifras">
        <div class="res-cifra"><span class="res-meta">Reservas confirmadas</span><strong>{{ $datos['totales']['reservas'] }}</strong></div>
        <div class="res-cifra"><span class="res-meta">Para clientes</span><strong>{{ $datos['totales']['clientes'] }}</strong></div>
        <div class="res-cifra"><span class="res-meta">Uso interno</span><strong>{{ $datos['totales']['internas'] }}</strong></div>
        <div class="res-cifra"><span class="res-meta">Ingresos (con IVA)</span><strong>{{ Cotizador::dinero($datos['totales']['ingresos'], $m) }}</strong></div>
    </div>

    <section class="res-tarjeta mb-3" aria-labelledby="t-ocupacion">
        <h2 id="t-ocupacion">Ocupación por área</h2>
        <div class="table-responsive">
            <table class="res-tabla">
                <thead><tr><th>Área</th><th class="num">Reservas</th><th class="num">Horas reservadas</th><th class="num">Horas disponibles</th><th>Ocupación</th></tr></thead>
                <tbody>
                    @forelse ($datos['ocupacion'] as $f)
                        <tr>
                            <td>{{ $f['area'] }}</td><td class="num">{{ $f['reservas'] }}</td><td class="num">{{ $f['horas'] }}</td><td class="num">{{ $f['disponibles'] }}</td>
                            <td>@if ($f['porcentaje'] !== null)<div class="d-flex align-items-center gap-2"><div class="res-barra" aria-hidden="true"><span style="width: {{ $f['porcentaje'] }}%"></span></div>{{ $f['porcentaje'] }} %</div>@else — @endif</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="res-meta">No hay áreas reservables.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    <div class="res-ficha">
        <section class="res-tarjeta" aria-labelledby="t-ingresos">
            <h2 id="t-ingresos">Ingresos de eventos para clientes</h2>
            <div class="table-responsive">
                <table class="res-tabla">
                    <thead><tr><th>Área</th><th class="num">Eventos</th><th class="num">Subtotal</th><th class="num">IVA</th><th class="num">Total</th></tr></thead>
                    <tbody>
                        @forelse ($datos['ingresos'] as $f)
                            <tr><td>{{ $f['area'] }}@if ($f['cortesias'])<div class="res-meta">{{ $f['cortesias'] }} {{ $f['cortesias'] === 1 ? 'cortesía' : 'cortesías' }}</div>@endif</td>
                                <td class="num">{{ $f['eventos'] }}</td><td class="num">{{ Cotizador::dinero($f['subtotal'], $m) }}</td><td class="num">{{ Cotizador::dinero($f['iva'], $m) }}</td><td class="num">{{ Cotizador::dinero($f['total'], $m) }}</td></tr>
                        @empty
                            <tr><td colspan="5" class="res-meta">Sin eventos para clientes en el periodo.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
        <section class="res-tarjeta" aria-labelledby="t-tipos">
            <h2 id="t-tipos">Reservas por tipo de uso</h2>
            <table class="res-tabla">
                <thead><tr><th>Tipo</th><th class="num">Total</th><th class="num">Clientes</th><th class="num">Internas</th></tr></thead>
                <tbody>
                    @forelse ($datos['tipos'] as $f)
                        <tr><td>{{ $f['tipo'] }}</td><td class="num">{{ $f['reservas'] }}</td><td class="num">{{ $f['clientes'] }}</td><td class="num">{{ $f['internas'] }}</td></tr>
                    @empty
                        <tr><td colspan="4" class="res-meta">Sin reservas en el periodo.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </section>
    </div>
</div>
@endsection
