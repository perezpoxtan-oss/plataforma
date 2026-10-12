{{--
    Configuración → Cuentas y seguridad → Bitácora de auditoría (ADR-0016): qué
    ve quien tiene la bitácora con alcance «Su sede». Por omisión, los
    movimientos de su sede (decisión del dueño); la empresa que guardó otro valor lo conserva.
--}}
<hr class="my-4">
<h3 class="h6 fw-bold" id="config-auditoria"><i class="bi bi-clipboard-data me-2 text-primary" aria-hidden="true"></i>Bitácora de auditoría: qué ve «Su sede»</h3>
<p class="small text-muted mb-2">Para quien tiene la Bitácora de auditoría con alcance «Su sede» (por ejemplo, un Jefe de seguridad). Quien la tiene con «Toda la empresa» siempre ve todo.</p>
<form action="{{ route('configuracion.auditoria') }}" method="POST">
    @csrf
    @method('PUT')
    <label class="opcion-todas">
        <input type="radio" name="auditoria_sede" value="propios" @checked($auditoriaSede !== 'sede') @disabled(! $puedeEditar)>
        <span><strong>Solo sus propios movimientos</strong><br><span class="small text-muted">Lo que esa persona hizo.</span></span>
    </label>
    <label class="opcion-todas">
        <input type="radio" name="auditoria_sede" value="sede" @checked($auditoriaSede === 'sede') @disabled(! $puedeEditar)>
        <span><strong>Los movimientos de su sede</strong><br><span class="small text-muted">Lo que se hizo en registros de su sede y lo que hizo el personal asignado a ella.</span></span>
    </label>
    @if ($puedeEditar)
        <button type="submit" class="btn-azul mt-2" style="min-height:44px;border-radius:10px;padding:0 1.25rem;">Guardar</button>
    @endif
</form>
