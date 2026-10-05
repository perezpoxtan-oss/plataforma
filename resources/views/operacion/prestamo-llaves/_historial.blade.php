{{--
    "Historial de Movimientos" de una llave (SEGCAT: llave_historial_ajax.php).
    Fragmento que se pinta dentro del diálogo #dialogoHistorialLlave.
--}}
<div class="dialogo-cabecera">
    <div>
        <h2 class="m-0"><i class="bi bi-clock-history me-2 text-primary" aria-hidden="true"></i>Historial de Movimientos</h2>
        <div class="historial-subtitulo">Llave: {{ $llave->nomenclatura }} - {{ $llave->descripcion }}</div>
    </div>
    <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
</div>
<div class="dialogo-cuerpo">
    @if ($movimientos->isEmpty())
        <div class="text-center text-muted py-4">No hay registros de uso para esta llave aún.</div>
    @else
        <ol class="linea-tiempo">
            @foreach ($movimientos as $h)
                @php $clase = $h->anulado ? 'anulado' : ($h->estado === \App\Models\PrestamoLlave::EN_USO ? 'fuera' : 'dentro'); @endphp
                <li class="linea-tiempo-item {{ $clase }}">
                    <div class="fw-bold">
                        <i class="bi bi-person-fill me-1 text-secondary" aria-hidden="true"></i>{{ $h->colaborador?->nombreCompleto() ?? '—' }}
                        @if ($h->colaborador?->num_empleado)<span class="chip-nomina">#{{ $h->colaborador->num_empleado }}</span>@endif
                        @if ($h->anulado)<span class="badge-prestamo anulada ms-1">ANULADO</span>@endif
                    </div>
                    <div class="linea-tiempo-datos">
                        <div>
                            <span class="linea-tiempo-rotulo">Préstamo (Salida)</span>
                            <div class="fw-bold"><i class="bi bi-calendar-event me-1" aria-hidden="true"></i>@fecha($h->prestado_en)</div>
                            <div class="text-secondary"><i class="bi bi-shield-lock me-1" aria-hidden="true"></i>Entregó: {{ $h->entrego?->name ?? '—' }}</div>
                            <div class="mt-1"><span class="id-box-mini">ID Garantía: {{ $h->textoGarantia() }}</span></div>
                        </div>
                        <div class="linea-tiempo-devolucion">
                            <span class="linea-tiempo-rotulo">Devolución (Entrada)</span>
                            @if ($h->anulado)
                                <div class="text-secondary fw-bold"><i class="bi bi-slash-circle me-1" aria-hidden="true"></i>Anulado @fecha($h->anulado_en)</div>
                                <div class="text-secondary">por {{ $h->anulo?->name ?? '—' }}</div>
                            @elseif ($h->devuelto_en)
                                <div class="fw-bold texto-regreso"><i class="bi bi-calendar-check me-1" aria-hidden="true"></i>@fecha($h->devuelto_en)</div>
                                <div class="text-secondary"><i class="bi bi-shield-check me-1" aria-hidden="true"></i>Recibió: {{ $h->recibio?->name ?? '—' }}</div>
                            @else
                                <div class="texto-aun-en-uso fw-bold"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>AÚN EN USO</div>
                            @endif
                        </div>
                    </div>
                </li>
            @endforeach
        </ol>
    @endif
</div>
