{{--
    C-13 (DD-07): diálogo «Restringir acceso» (uno por pantalla; los botones de
    cada ficha le ponen la dirección). Motivo obligatorio y fecha de fin opcional.
--}}
<dialog id="dialogoRestringirAcceso" class="dialogo" aria-labelledby="titulo-restringir">
    <div class="dialogo-cabecera">
        <h2 id="titulo-restringir"><i class="bi bi-shield-x me-2 text-danger" aria-hidden="true"></i>Restringir acceso: <span data-titulo-registro></span></h2>
        <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </div>
    <div class="dialogo-cuerpo">
        <form method="POST" action="" autocomplete="off">
            @csrf
            @method('PATCH')
            <input type="hidden" name="accion" value="restringir">
            <p class="campo-ayuda mt-0">No es una baja: sigue en el padrón, pero la caseta <strong>no lo podrá registrar</strong> (ni con un nombre parecido sin confirmar que es otro). Si ya no se usa, mejor «Dar de baja».</p>
            <label class="campo-etiqueta" for="restringir_motivo">Motivo (lo verá la caseta)</label>
            <textarea id="restringir_motivo" name="motivo" class="campo" rows="2" maxlength="255" required placeholder="Ej: robo de material el 12/10; lo autoriza solo Gerencia"></textarea>
            <label class="campo-etiqueta" for="restringir_hasta">Hasta <span class="text-lowercase fw-normal">(opcional; vacío = hasta que se quite)</span></label>
            <input type="date" id="restringir_hasta" name="hasta" class="campo">
            <div class="dialogo-acciones">
                <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                <button type="submit" class="btn-rojo-acceso">Restringir acceso</button>
            </div>
        </form>
    </div>
</dialog>
