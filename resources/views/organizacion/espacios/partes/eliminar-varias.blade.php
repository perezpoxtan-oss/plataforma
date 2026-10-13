{{--
    «Eliminar definitivamente las marcadas» (lista de habitaciones de un piso o de pisos de una zona).
    Parámetros: $padre (el piso o la zona), $plural (cómo se llaman los de la lista, en minúsculas), $total.
    Solo se incluye con el permiso espacios.borrar. Las casillas de cada ficha llevan
    name="espacios[]" form="formEliminarVarias" data-marcar-espacio; las maneja public/js/espacios.js.
    En el servidor cada una pasa por el mismo borrado seguro que el botón individual.
--}}
<div class="d-flex flex-wrap align-items-center gap-2 mb-3" data-eliminar-varias>
    <label class="form-check m-0 d-flex align-items-center gap-2" style="min-height:40px">
        <input type="checkbox" class="form-check-input m-0" data-marcar-todas-espacios @disabled($total === 0)>
        <span class="small fw-bold">Marcar todas</span>
    </label>
    <span class="small text-muted" data-marcadas-texto aria-live="polite">Ninguna marcada</span>
    <button type="button" class="btn-borrar-definitivo ms-md-auto" data-abrir-eliminar-varias disabled>
        <i class="bi bi-trash3" aria-hidden="true"></i> Eliminar definitivamente las marcadas
    </button>
</div>

<dialog id="dialogoEliminarVarias" class="dialogo dialogo-borrar" aria-labelledby="tituloEliminarVarias">
    <div class="dialogo-cabecera">
        <h2 id="tituloEliminarVarias"><i class="bi bi-trash3 me-2 text-danger" aria-hidden="true"></i>Eliminar definitivamente las marcadas</h2>
        <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </div>
    <div class="dialogo-cuerpo">
        <form id="formEliminarVarias" action="{{ route('espacios.eliminar_varias', $padre->id) }}" method="POST" class="m-0" autocomplete="off">
            @csrf
            @method('DELETE')
            <div class="borrar-aviso">
                <i class="bi bi-exclamation-octagon-fill" aria-hidden="true"></i>
                <div>
                    <strong class="d-block mb-1">Esto no se puede deshacer.</strong>
                    Se borrará lo marcado (<strong data-marcadas-cantidad>0</strong> {{ $plural }}) con todo lo que tiene dentro, solo si nadie lo ha usado.
                    Lo que esté en uso (una llave lo abre, tiene equipos, recorridos o registros) no se borra y te diremos por qué.
                    Solo queda una copia en la Bitácora de auditoría.
                </div>
            </div>
            <label class="campo-etiqueta mt-3" for="eliminarVariasConfirmacion">Para confirmar escribe <span class="borrar-clave" data-marcadas-clave>0</span> o <span class="borrar-clave">ELIMINAR</span></label>
            <input type="text" id="eliminarVariasConfirmacion" name="confirmacion" class="campo" maxlength="20" required
                   autocomplete="off" autocapitalize="off" spellcheck="false" data-eliminar-varias-entrada>
            <p class="campo-ayuda"><i class="bi bi-info-circle" aria-hidden="true"></i> Máximo {{ \App\Services\Espacios\AdministradorEspacios::MAX_ELIMINAR }} a la vez. No importan mayúsculas ni minúsculas.</p>
            <div class="dialogo-acciones">
                <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                <button type="submit" class="btn-rojo" data-eliminar-varias-enviar disabled><i class="bi bi-trash3 me-1" aria-hidden="true"></i>Eliminar</button>
            </div>
        </form>
    </div>
</dialog>
@pushOnce('scripts', 'js-espacios')
    <script src="{{ asset('js/espacios.js') }}?v={{ @filemtime(public_path('js/espacios.js')) ?: '1' }}"></script>
@endPushOnce
