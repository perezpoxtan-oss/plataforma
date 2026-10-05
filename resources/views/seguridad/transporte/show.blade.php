@extends('layouts.app')

@section('titulo', 'Registro '.$m->folio().' · Bitácora de Transporte')

@section('contenido')
@php
    $esTaxi = $m->esTaxi();
    $llegada = $m->esLlegada();
@endphp
<div class="tema-transporte">
    @include('administracion.partes.avisos')

    <nav class="migas" aria-label="Ubicación">
        <a href="{{ route('transporte.index', ['fecha_inicio' => $m->fecha->toDateString(), 'fecha_fin' => $m->fecha->toDateString()]) }}"><i class="bi bi-clock-history" aria-hidden="true"></i> Bitácora de Transporte</a>
        <i class="bi bi-chevron-right" aria-hidden="true"></i>
        <span class="actual" aria-current="page">Registro {{ $m->folio() }}</span>
    </nav>

    @include('seguridad.transporte._tarjeta', ['m' => $m, 'editable' => $editable, 'anulable' => $anulable, 'autorizable' => $autorizable, 'detalle' => false])

    <section class="tarjeta p-3 p-md-4 mb-3 transporte-detalle" aria-labelledby="t-detalle">
        <h2 id="t-detalle" class="h6 fw-bold mb-3"><i class="bi bi-card-list me-2 text-primary" aria-hidden="true"></i>Detalle del registro</h2>
        <dl class="transporte-datos-lista">
            <div><dt>Fecha (sede)</dt><dd>{{ $m->fecha->format('d/m/Y') }} · @fecha($m->created_at, 'h:i A')</dd></div>
            <div><dt>Movimiento</dt><dd>{{ $m->etiquetaTipo() }} {{ $llegada ? '(A la Sede)' : '(Hacia Paraderos)' }}</dd></div>
            <div><dt>Ruta</dt><dd>{{ $m->ruta?->nombre ?? '—' }}</dd></div>
            <div><dt>Horario programado</dt><dd>@if ($m->horario){{ $m->horario->nombre }} · {{ $m->horario->inicio() }}–{{ $m->horario->fin() }} · {{ $m->horario->patronDias() }}@else — @endif</dd></div>
            <div><dt>Transportista de la ruta</dt><dd>{{ $m->ruta?->proveedor?->nombre ?? '—' }}</dd></div>
            <div><dt>Estatus</dt><dd>{{ $m->etiquetaEstatus() }}</dd></div>
            <div><dt>Unidad</dt><dd>{{ $m->etiquetaUnidad() }}@if ($m->vehiculo) · {{ $m->vehiculo->placas }}{{ $m->vehiculo->numero_economico ? ' · Eco. '.$m->vehiculo->numero_economico : '' }}{{ trim($m->vehiculo->marca.' '.$m->vehiculo->modelo) !== '' ? ' · '.trim($m->vehiculo->marca.' '.$m->vehiculo->modelo) : '' }}@endif</dd></div>
            <div><dt>{{ $esTaxi ? 'Conductor' : 'Chofer' }}</dt><dd>{{ $m->chofer?->nombre_completo ?? 'No registrado' }}@if ($m->chofer?->telefono && auth()->user()->can('visitantes.ver')) · Tel. {{ $m->chofer->telefono }}@endif</dd></div>
            @if ($m->vehiculo?->capacidad)
                <div><dt>Capacidad</dt><dd>{{ $m->vehiculo->capacidad }} PAX @if ($m->cantidad_pax > $m->vehiculo->capacidad)<span class="texto-sobrecupo">· Sobrecupo</span>@endif</dd></div>
            @endif
            @if ($esTaxi)
                <div><dt>Tope de la ruta</dt><dd>{{ $m->ruta?->costo_maximo_taxi !== null ? '$'.number_format((float) $m->ruta->costo_maximo_taxi, 2) : 'Sin tope' }}</dd></div>
            @endif
            @if (count($hermanos) > 1)
                <div><dt>Taxis de la misma captura</dt><dd>
                    @foreach ($hermanos as $id)
                        @if ($id === $m->id)<strong>{{ '#'.str_pad((string) $id, 6, '0', STR_PAD_LEFT) }}</strong>@else<a href="{{ route('transporte.show', $id) }}">{{ '#'.str_pad((string) $id, 6, '0', STR_PAD_LEFT) }}</a>@endif{{ $loop->last ? '' : ', ' }}
                    @endforeach
                </dd></div>
            @endif
        </dl>

        <h3 class="h6 fw-bold mt-3">Trazas</h3>
        <ul class="transporte-trazas">
            <li><i class="bi bi-plus-circle" aria-hidden="true"></i> Registrado por {{ $m->registro?->name ?? '—' }} · @fecha($m->created_at)</li>
            @if ($m->editado_en)
                <li><i class="bi bi-pencil" aria-hidden="true"></i> Editado por {{ $m->editor?->name ?? '—' }} · @fecha($m->editado_en)</li>
            @endif
            @if ($m->autorizado())
                <li><i class="bi bi-patch-check" aria-hidden="true"></i> Vo.Bo. de {{ $m->autorizador?->name ?? '—' }} · @fecha($m->autorizado_en)</li>
            @endif
            @if ($m->anulado)
                <li class="text-danger"><i class="bi bi-slash-circle" aria-hidden="true"></i> Anulado por {{ $m->anulador?->name ?? '—' }} · @fecha($m->anulado_en)</li>
            @endif
        </ul>
    </section>

    @if ($m->firma_guardia || $m->firma_taxista)
        <section class="tarjeta p-3 p-md-4 mb-3" aria-labelledby="t-firmas">
            <h2 id="t-firmas" class="h6 fw-bold mb-3"><i class="bi bi-pen me-2 text-primary" aria-hidden="true"></i>Firmas</h2>
            <div class="transporte-firmas">
                @if ($m->firma_guardia)
                    <figure><img src="{{ route('transporte.firma', [$m->id, 'guardia']) }}" alt="Firma del guardia"><figcaption>Seguridad (Caseta) · {{ $m->registro?->name }}</figcaption></figure>
                @endif
                @if ($m->firma_taxista)
                    <figure><img src="{{ route('transporte.firma', [$m->id, 'taxista']) }}" alt="Firma del taxista"><figcaption>Conductor (Recibí) · {{ $m->chofer?->nombre_completo }}</figcaption></figure>
                @endif
            </div>
        </section>
    @endif

    @if ($editable)
        @foreach ($paraderosPorSede as $sedeId => $nombres)
            <datalist id="paraderosTransporte-{{ $sedeId }}">
                @foreach ($nombres as $nombre)
                    <option value="{{ $nombre }}"></option>
                @endforeach
            </datalist>
        @endforeach
        @include('seguridad.transporte._editar')
    @endif
</div>
@endsection
