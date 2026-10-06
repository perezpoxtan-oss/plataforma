{{--
    Píldora "Pendientes de verificar" de la lista de un padrón (ADR-0006).
    Solo para quien puede verificar (editar el padrón) y si hay pendientes.
    Al tocarla se muestran solo las fichas con la insignia "Pendiente de
    verificar"; llega encendida desde el aviso de Inicio (?verificacion=pendiente).
    $altas = AltasPorVerificar::paraLista(...)
--}}
@if ($altas['puede'] && $altas['pendientes'] > 0)
    <button type="button" class="btn-pill-tipo btn-pill-verificar" data-filtro-verificacion aria-pressed="false"
            @if (request()->query('verificacion') === 'pendiente') data-encender @endif>
        <i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>Pendientes de verificar <span class="conteo-pill">{{ $altas['pendientes'] }}</span>
    </button>
@endif
