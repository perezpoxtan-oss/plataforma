{{--
    Tarjeta de un movimiento (SEGCAT: .b-card de bitacora_transporte.php).
    Parámetros: $m (con relaciones), $editable, $anulable, $autorizable, $detalle (bool: enlace "Ver detalle").
--}}
@php
    $esTaxi = $m->esTaxi();
    $llegada = $m->esLlegada();
    $unidad = $m->vehiculo
        ? $m->etiquetaUnidad().' ('.$m->vehiculo->placas.($m->vehiculo->numero_economico ? ' · Eco. '.$m->vehiculo->numero_economico : '').')'
        : $m->etiquetaUnidad().' (—)';
    $pasajeros = $m->pasajeros->map(fn ($c) => $c->nombreCompleto().' ['.$c->num_empleado.']');
    $claseEstatus = ['a_tiempo' => 'a-tiempo', 'retraso' => 'retraso', 'no_llego' => 'no-llego'][$m->estatus] ?? '';
    $valores = json_encode([
        'folio' => $m->folio(), 'taxi' => $esTaxi, 'sede_id' => $m->sede_id, 'estatus' => $m->estatus,
        'cantidad_pax' => $m->cantidad_pax, 'monto' => $m->monto, 'destino' => $m->paradero?->nombre,
        'justificacion' => $m->justificacion, 'observaciones' => $m->observaciones,
        'tope' => $m->ruta?->costo_maximo_taxi,
        'pasajeros' => $m->pasajeros->map(fn ($c) => ['id' => $c->id, 'texto' => $c->nombreCompleto().' · Núm. '.$c->num_empleado])->values(),
    ]);
@endphp
<article class="transporte-card {{ $esTaxi ? 'taxi' : '' }} {{ $m->anulado ? 'anulada' : '' }}" id="movimiento-{{ $m->id }}">
    <div class="transporte-card-cabecera">
        <div class="transporte-card-cuando">
            <span class="badge-mov {{ $llegada ? 'llegada' : 'salida' }}">
                <i class="bi {{ $llegada ? 'bi-box-arrow-in-right' : 'bi-box-arrow-up-right' }} me-1" aria-hidden="true"></i>{{ $m->etiquetaTipo() }}
            </span>
            <span class="transporte-hora"><i class="bi bi-clock me-1" aria-hidden="true"></i>@fecha($m->created_at, 'd/m/Y h:i A')</span>
            <span class="transporte-folio">{{ $m->folio() }}</span>
            @if ($m->anulado)
                <span class="badge-anulado">ANULADO por {{ $m->anulador?->name ?? '—' }}</span>
            @endif
        </div>
        <div class="transporte-card-acciones">
            <span class="pill-estatus {{ $claseEstatus }}">{{ $m->etiquetaEstatus() }}</span>
            @if ($detalle ?? true)
                <a href="{{ route('transporte.show', $m->id) }}" class="btn-icono ver" title="Ver detalle" aria-label="Ver detalle del registro {{ $m->folio() }}"><i class="bi bi-eye" aria-hidden="true"></i></a>
            @endif
            @if ($editable && ! $m->anulado && ! $m->autorizado())
                <button type="button" class="btn-icono editar" title="Editar" aria-label="Editar registro {{ $m->folio() }}"
                        data-accion="editar-movimiento" data-dialogo="dialogoEditarMovimiento" data-url="{{ route('transporte.update', $m->id) }}"
                        data-id="{{ $m->id }}" data-valores="{{ $valores }}">
                    <i class="bi bi-pencil-square" aria-hidden="true"></i>
                </button>
            @endif
            @if ($anulable)
                @if (! $m->anulado)
                    <form action="{{ route('transporte.anular', $m->id) }}" method="POST" class="m-0" data-confirmar="¿Anular este registro? Quedará marcado como anulado, sin borrarse del historial.">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="btn-icono eliminar" title="Anular" aria-label="Anular registro {{ $m->folio() }}"><i class="bi bi-slash-circle" aria-hidden="true"></i></button>
                    </form>
                @else
                    <form action="{{ route('transporte.reactivar', $m->id) }}" method="POST" class="m-0" data-confirmar="¿Reactivar este registro anulado? Volverá a contar como válido.">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="btn-icono reactivar" title="Reactivar" aria-label="Reactivar registro {{ $m->folio() }}"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></button>
                    </form>
                @endif
            @endif
        </div>
    </div>

    <div class="transporte-card-datos">
        <div>
            <div class="transporte-dato-etiqueta">RUTA / SEDE</div>
            <div class="transporte-ruta">{{ $m->ruta?->nombre ?? '—' }}@if ($m->horario) <span class="transporte-horario">{{ $llegada ? 'llega' : 'sale' }} {{ $llegada ? $m->horario->fin() : $m->horario->inicio() }}</span>@endif</div>
            <div class="transporte-sede"><i class="bi bi-house-door me-1" aria-hidden="true"></i>{{ $m->sede?->nombre }}</div>
        </div>
        <div>
            <div class="transporte-dato-etiqueta">VEHÍCULO Y CHOFER</div>
            <div class="fw-bold"><i class="bi {{ $esTaxi ? 'bi-taxi-front' : 'bi-bus-front' }} me-1" aria-hidden="true"></i>{{ $unidad }}</div>
            <div class="small"><i class="bi bi-person-badge me-1" aria-hidden="true"></i>{{ $m->chofer?->nombre_completo ?? 'No registrado' }}</div>
        </div>
        <div class="transporte-pax">
            <div class="transporte-dato-etiqueta">VOLUMEN (PAX)</div>
            <div class="transporte-pax-numero">{{ (int) $m->cantidad_pax }} <i class="bi bi-people-fill" aria-hidden="true"></i></div>
        </div>
    </div>

    @if ($pasajeros->isNotEmpty())
        <div class="transporte-pasajeros"><strong>Pasajeros:</strong> {{ $pasajeros->implode(', ') }}</div>
    @endif

    @if ($esTaxi)
        <div class="transporte-caja-pago">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div class="transporte-pago"><i class="bi bi-cash-stack me-2" aria-hidden="true"></i>Pago: ${{ number_format((float) $m->monto, 2) }}</div>
                <a href="{{ route('transporte.vale', $m->id) }}" target="_blank" rel="noopener" class="btn-planilla"><i class="bi bi-printer-fill me-1" aria-hidden="true"></i>Imprimir Planilla</a>
            </div>
            <div class="small"><strong>Destino:</strong> {{ $m->paradero?->nombre ?? '—' }}</div>
            @if ($m->justificacion)
                <div class="small transporte-justificacion mt-1"><i class="bi bi-flag-fill me-1" aria-hidden="true"></i><strong>Justificación de costo:</strong> {{ $m->justificacion }}</div>
            @endif
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mt-2">
                @if ($m->autorizado())
                    <span class="vobo autorizado"><i class="bi bi-patch-check-fill me-1" aria-hidden="true"></i>Vo.Bo.: {{ $m->autorizador?->name ?? '—' }} · @fecha($m->autorizado_en)</span>
                @elseif (! $m->anulado)
                    <span class="vobo pendiente"><i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>Pendiente de Vo.Bo.</span>
                    @if ($autorizable)
                        <form action="{{ route('transporte.autorizar', $m->id) }}" method="POST" class="m-0"
                              data-confirmar="¿Dar el Vo.Bo. a este vale de ${{ number_format((float) $m->monto, 2) }}? Después ya no se podrá editar.">
                            @csrf
                            @method('PATCH')
                            <button type="submit" class="btn-autorizar"><i class="bi bi-patch-check me-1" aria-hidden="true"></i>Autorizar (Vo.Bo.)</button>
                        </form>
                    @endif
                @endif
            </div>
        </div>
    @endif

    <div class="transporte-card-pie">
        Registrado por: <strong>{{ $m->registro?->name ?? '—' }}</strong>@if ($m->observaciones) | {{ $m->observaciones }}@endif
        @if ($m->editado_en)
            <br><span class="texto-traza"><i class="bi bi-clock-history" aria-hidden="true"></i> Editado por {{ $m->editor?->name ?? '—' }} · @fecha($m->editado_en)</span>
        @endif
    </div>
</article>
