{{--
    Ficha "LLAVE FUERA" de la pestaña Llaves en Uso (SEGCAT: bitacora_llaves.php).
    También la devuelve el servidor tras "Registrar y Capturar Siguiente" para
    pintarla sin recargar. Variables: $p (PrestamoLlave con llave, colaborador,
    entrego y sede), $hoy (Y-m-d local), $puedeRecibir, $puedeAnular, $variasSedes (opcional).
--}}
@php
    $nombre = $p->nombreReceptor();
    $noRegreso = $p->estado === \App\Models\PrestamoLlave::NO_REGRESO;
    $texto = mb_strtolower(implode(' ', array_filter([$nombre, $p->colaborador?->num_empleado, $p->llave?->nomenclatura, $p->llave?->descripcion, $p->sede?->nombre, $p->etiquetaGarantia(), $p->folio_garantia])));
    $esHoy = app(\App\Support\HoraLocal::class)->formatear($p->prestado_en, 'Y-m-d') === $hoy;
@endphp
@php
    // ADR-0014: semáforo de llaves fuera (solo si la empresa lo configuró): ['horas' => float, 'nivel' => null|ambar|rojo]
    $semaforoPrestamo = $semaforoPrestamo ?? null;
@endphp
<div class="ficha-card ficha-prestamo {{ $noRegreso ? 'no-regreso' : 'fuera' }}{{ ! empty($semaforoPrestamo['nivel']) ? ' semaforo-'.$semaforoPrestamo['nivel'] : '' }}" id="prestamo-{{ $p->id }}" data-ficha-prestamo data-sede="{{ $p->sede_id }}" data-texto="{{ $texto }}">
    <div>
        <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
            @if ($noRegreso)
                <span class="badge-prestamo no-regreso"><i class="bi bi-exclamation-octagon-fill me-1" aria-hidden="true"></i>NO REGRESÓ</span>
            @else
                <span class="badge-prestamo fuera"><i class="bi bi-key-fill me-1" aria-hidden="true"></i>LLAVE FUERA</span>
            @endif
            <span class="folio-prestamo">ID: {{ $p->folio() }}</span>
        </div>
        <h2 class="prestamo-colaborador">{{ $nombre }}</h2>
        @if ($p->colaborador_id !== null)
            <div class="prestamo-nomina">No. Nómina: {{ $p->colaborador?->num_empleado ?? 'Provisional' }}</div>
        @else
            <div class="prestamo-nomina">{{ \App\Support\Receptor::TIPOS[$p->receptor_tipo] ?? '' }}</div>
        @endif

        <div class="llave-box">
            <span>{{ $p->llave?->nomenclatura }}</span>
            <button type="button" class="btn-historial-llave" title="Ver historial de esta llave" aria-label="Ver historial de la llave {{ $p->llave?->nomenclatura }}"
                    data-historial-llave="{{ route('prestamo_llaves.historial', $p->llave_id) }}">
                <i class="bi bi-clock-history" aria-hidden="true"></i>
            </button>
        </div>
        <div class="prestamo-descripcion">{{ $p->llave?->descripcion }}</div>
        <div class="id-box"><i class="bi bi-person-vcard me-1" aria-hidden="true"></i>Garantía: {{ $p->textoGarantia() }}</div>
        @if ($p->excepcion_motivo)
            {{-- C-17: se prestó aunque no cumplía las reglas de la llave --}}
            <div class="prestamo-excepcion"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Prestada por excepción: {{ $p->excepcion_motivo }}</div>
        @endif
        <div class="prestamo-salio">
            <strong>Salió:</strong> @if ($esHoy) @fecha($p->prestado_en, 'H:i') @else @fecha($p->prestado_en, 'd/m/Y H:i') @endif
            (por {{ $p->entrego?->name ?? '—' }})
        </div>
        @if ($noRegreso)
            <div class="reloj-llave nivel-rojo"><i class="bi bi-exclamation-octagon me-1" aria-hidden="true"></i>Marcada «No regresó»: no se puede prestar. Si aparece, recíbela; si no, se da de baja con voucher.</div>
        @endif
        @if ($semaforoPrestamo && ! $noRegreso)
            <div class="reloj-llave {{ $semaforoPrestamo['nivel'] ? 'nivel-'.$semaforoPrestamo['nivel'] : 'en-tiempo' }}">
                <i class="bi bi-stopwatch me-1" aria-hidden="true"></i>Fuera hace {{ \App\Services\Activos\CampanaActivos::textoHoras($semaforoPrestamo['horas']) }}{{ $semaforoPrestamo['nivel'] === 'rojo' ? ' · pasó del límite' : ($semaforoPrestamo['nivel'] === 'ambar' ? ' · pide que la regresen' : '') }}
            </div>
        @endif
        @if ($variasSedes ?? false)
            <div class="prestamo-sede"><i class="bi bi-geo-alt-fill me-1" aria-hidden="true"></i>{{ $p->sede?->nombre }}</div>
        @endif
    </div>

    @if ($puedeRecibir || $puedeAnular)
        <div class="prestamo-acciones">
            @if ($puedeRecibir)
                <form action="{{ route('prestamo_llaves.recibir', $p->id) }}" method="POST" class="flex-grow-1 m-0" data-confirmar="¿Confirmar que recibe la llave {{ $p->llave?->nomenclatura }} y devuelve la ID en garantía?">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn-recibir-llave"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>Recibir Llave a Caseta</button>
                </form>
                @unless ($noRegreso)
                    {{-- C-14: la llave no regresó (se pierde, se la llevó): queda NO REGRESÓ y no se vuelve a prestar --}}
                    <form action="{{ route('prestamo_llaves.no-regreso', $p->id) }}" method="POST" class="m-0" data-confirmar="¿La llave {{ $p->llave?->nomenclatura }} no regresó? Ya no se podrá prestar; si aparece, la recibes aquí. Si no, se da de baja con voucher a nombre de {{ $nombre }}.">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="btn-no-regreso" title="No regresó" aria-label="La llave {{ $p->llave?->nomenclatura }} no regresó"><i class="bi bi-exclamation-octagon me-1" aria-hidden="true"></i>No regresó</button>
                    </form>
                @endunless
            @endif
            @if ($puedeAnular && ! $noRegreso)
                <form action="{{ route('prestamo_llaves.anular', $p->id) }}" method="POST" class="m-0" data-confirmar="¿Anular este préstamo? Úsalo solo si fue un error de captura (llave o colaborador equivocado) — la llave quedará disponible de inmediato.">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn-icono btn-anular-prestamo" title="Anular préstamo (error de captura)" aria-label="Anular el préstamo {{ $p->folio() }}"><i class="bi bi-slash-circle" aria-hidden="true"></i></button>
                </form>
            @endif
        </div>
    @endif
</div>
