@extends('layouts.app')

@section('titulo', 'Tiempos de espera')

@section('contenido')
<div class="pantalla-metricas-rh">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    <a href="{{ route('recepcion.index') }}" class="enlace-volver"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Recepción</a>
    <div class="encabezado-pantalla mb-3">
        <div class="icono"><i class="bi bi-bar-chart-line-fill text-primary" aria-hidden="true"></i></div>
        <div>
            <h1>Tiempos de espera</h1>
            <p>Cuánto esperan visitas y candidatos: de la caseta al aviso, a la respuesta y a la entrevista.</p>
        </div>
    </div>

    @if ($sinEmpresa)
        <div class="tarjeta estado-vacio"><p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong>.</p></div>
    @else
        @php $min = fn (?int $v) => $v === null ? '—' : ($v < 60 ? $v.' min' : intdiv($v, 60).' h '.($v % 60).' min'); @endphp
        <form method="GET" action="{{ route('recepcion.metricas') }}" class="filtros-rh">
            <label class="filtro-fecha"><span>Desde</span><input type="date" name="desde" class="filtro-select" value="{{ $desde }}"></label>
            <label class="filtro-fecha"><span>Hasta</span><input type="date" name="hasta" class="filtro-select" value="{{ $hasta }}"></label>
            @if ($sedes->count() > 1)
                <select name="sede" class="filtro-select" aria-label="Sede">
                    <option value="">Todas las sedes</option>
                    @foreach ($sedes as $s)
                        <option value="{{ $s->id }}" @selected($sede === $s->id)>{{ $s->nombre }}</option>
                    @endforeach
                </select>
            @endif
            <button type="submit" class="btn-azul btn-accion-rh"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Ver</button>
        </form>

        <div class="contadores-recepcion">
            <div class="contador-rh"><strong>{{ $min($m['candidatos']['llegada_a_aviso']) }}</strong><span>De la caseta al aviso a RR. HH.</span></div>
            <div class="contador-rh"><strong>{{ $min($m['candidatos']['llegada_a_atencion']) }}</strong><span>De la caseta a que RR. HH. lo atiende</span></div>
            <div class="contador-rh"><strong>{{ $min($m['candidatos']['aprobado_a_respuesta']) }}</strong><span>Respuesta del departamento (candidatos)</span></div>
            <div class="contador-rh"><strong>{{ $min($m['visitas']['promedio']) }}</strong><span>Respuesta a visitas ({{ $m['visitas']['total'] }})</span></div>
        </div>

        <section class="tarjeta p-3 mb-3">
            <h2 class="h6 fw-bold mb-2">Promedio de espera por departamento</h2>
            @if ($m['departamentos'] === [])
                <p class="small text-muted m-0">Sin solicitudes a departamentos en esas fechas.</p>
            @else
                @php $maximo = max(1, ...array_map(fn ($d) => (int) ($d['promedio'] ?? 0), $m['departamentos'])); @endphp
                <div class="tabla-scroll">
                    <table class="tabla-metricas">
                        <thead><tr><th scope="col">Departamento</th><th scope="col">Solicitudes</th><th scope="col">Pendientes</th><th scope="col">Autorizadas</th><th scope="col">Rechazadas</th><th scope="col">Espera promedio</th><th scope="col">Máxima</th></tr></thead>
                        <tbody>
                            @foreach ($m['departamentos'] as $d)
                                <tr>
                                    <th scope="row">{{ $d['departamento'] }}</th>
                                    <td>{{ $d['total'] }} <span class="texto-traza">({{ $d['visitas'] }} visitas · {{ $d['candidatos'] }} candidatos)</span></td>
                                    <td>{{ $d['pendientes'] }}</td>
                                    <td>{{ $d['autorizadas'] }}</td>
                                    <td>{{ $d['rechazadas'] }}</td>
                                    <td><span class="barra-metrica"><span style="width: {{ (int) round(100 * (int) ($d['promedio'] ?? 0) / $maximo) }}%"></span></span> {{ $min($d['promedio']) }}</td>
                                    <td>{{ $min($d['maximo']) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <div class="rejilla-ficha-candidato">
            <section class="tarjeta p-3">
                <h2 class="h6 fw-bold mb-2">Por día</h2>
                @forelse ($m['dias'] as $d)
                    <div class="renglon-metrica"><span>{{ \Illuminate\Support\Carbon::parse($d['dia'])->format('d/m/Y') }}</span><span>{{ $d['total'] }} solicitud(es)</span><strong>{{ $min($d['promedio']) }}</strong></div>
                @empty
                    <p class="small text-muted m-0">Sin datos en esas fechas.</p>
                @endforelse
            </section>
            <section class="tarjeta p-3">
                <h2 class="h6 fw-bold mb-2">Candidatos por etapa ({{ $m['candidatos']['total'] }})</h2>
                @foreach ($m['candidatos']['por_etapa'] as $e)
                    <div class="renglon-metrica"><span>{{ $e['etapa'] }}</span><strong>{{ $e['total'] }}</strong></div>
                @endforeach
                <p class="texto-traza mt-2 mb-0">De la caseta a la entrevista: {{ $min($m['candidatos']['llegada_a_entrevista']) }} en promedio.</p>
            </section>
        </div>
    @endif
</div>
@endsection
