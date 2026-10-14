{{--
    Avisos del navegador (avisos del sistema mientras haya una pestaña de la
    plataforma abierta). El permiso se pide solo al tocar el botón; el estado
    y la preferencia son de cada navegador (bloque «Avisos del navegador» de
    plataforma.js). Sin JavaScript el bloque queda oculto.
--}}
<div class="avisos-navegador {{ ($enCampana ?? false) ? 'en-campana' : '' }}" data-avisos-navegador hidden>
    <p class="avisos-navegador-estado" data-avisos-estado aria-live="polite"></p>
    <div class="avisos-navegador-botones">
        <button type="button" class="avisos-navegador-activar" data-avisos-activar hidden><i class="bi bi-bell-fill me-1" aria-hidden="true"></i>Activar avisos en este navegador</button>
        <button type="button" class="avisos-navegador-apagar" data-avisos-apagar hidden>Desactivar avisos en este navegador</button>
    </div>
</div>
