{{--
    "Historial de Auditoría" de un equipo (SEGCAT: equipo_historial_ajax.php).
    Fragmento que se pinta dentro del diálogo #dialogoHistorialEquipo.
--}}
<div class="dialogo-cabecera">
    <div>
        <h2 class="m-0"><i class="bi bi-clock-history me-2" aria-hidden="true"></i>Historial de Auditoría</h2>
        <div class="historial-subtitulo">{{ $equipo->tipo?->nombre ?? 'Equipo' }} · S/N {{ $equipo->numero_serie }}</div>
    </div>
    <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
</div>
<div class="dialogo-cuerpo">
    @if ($movimientos->isEmpty())
        <div class="text-center text-muted py-4"><i class="bi bi-inbox fs-1 d-block" aria-hidden="true"></i>Este equipo es nuevo, nunca ha sido prestado.</div>
    @else
        <ol class="linea-tiempo">
            @foreach ($movimientos as $h)
                @php $abierto = $h->devuelto_en === null; @endphp
                <li class="linea-tiempo-item {{ $abierto ? 'fuera' : 'dentro' }}">
                    <div class="d-flex justify-content-between align-items-center gap-2 mb-1 flex-wrap">
                        <span class="badge-resguardo {{ $abierto ? 'en-campo' : 'cerrado' }}">
                            {{ $abierto ? 'EN USO ACTUALMENTE' : 'DEVUELTO ('.mb_strtoupper($h->estado_devolucion ?? 'ok').')' }}
                        </span>
                        <small class="fw-bold text-muted">{{ $h->responsiva?->folio }} · @fecha($h->responsiva?->entregado_en, 'd/m/Y')</small>
                    </div>
                    <div class="small">
                        <strong>Resguardó:</strong> {{ $h->responsiva?->colaborador?->nombreCompleto() ?? '—' }} ({{ $h->etiquetaModalidad() }})<br>
                        <strong>Entregó:</strong> {{ $h->responsiva?->entrego?->name ?? '—' }} (@fecha($h->responsiva?->entregado_en, 'H:i'))<br>
                        @unless ($abierto)
                            <strong>Recibido:</strong> @fecha($h->devuelto_en) ({{ $h->responsiva?->recibio?->name ?? '—' }})
                        @endunless
                    </div>
                </li>
            @endforeach
        </ol>
    @endif
</div>
