{{--
    Botón "Verificar" de una ficha pendiente (ADR-0006). Solo si quien mira
    puede verificarla (editar el padrón, dentro de su alcance). Abre el
    diálogo compartido _dialogo con los datos de esta ficha.
    $altas = AltasPorVerificar::paraLista(...); $registro
--}}
@if (isset($altas['dialogos'][(int) $registro->id]))
    <button type="button" class="btn-verificar-alta" data-verificar-alta="{{ json_encode($altas['dialogos'][(int) $registro->id]) }}"
            aria-label="Verificar el alta de {{ $altas['dialogos'][(int) $registro->id]['titulo'] }}">
        <i class="bi bi-patch-check me-1" aria-hidden="true"></i>Verificar
    </button>
@endif
