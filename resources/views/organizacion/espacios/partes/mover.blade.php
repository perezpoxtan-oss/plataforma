{{--
    Botón «Mover a…» de un espacio (no aplica a zonas / edificios, que dependen de la sede).
    Parámetro: $e (espacio). El diálogo es uno por página; public/js/espacios.js pide al
    servidor los lugares válidos (GET espacios.destinos) y arma la lista.
--}}
<button type="button" class="btn-icono editar" title="Mover a…" aria-label="Mover {{ $e->nombre }} a otro lugar"
        data-accion="mover-espacio" data-nombre="{{ $e->nombre }}"
        data-url="{{ route('espacios.mover', $e->id) }}" data-destinos="{{ route('espacios.destinos', $e->id) }}">
    <i class="bi bi-arrows-move" aria-hidden="true"></i>
</button>

@once
    @push('scripts')
        <dialog id="dialogoMoverEspacio" class="dialogo" aria-labelledby="tituloMoverEspacio">
            <div class="dialogo-cabecera">
                <h2 id="tituloMoverEspacio"><i class="bi bi-arrows-move me-2" aria-hidden="true"></i>Mover a…</h2>
                <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </div>
            <div class="dialogo-cuerpo">
                <form method="POST" class="m-0" data-mover-form autocomplete="off">
                    @csrf
                    @method('PATCH')
                    <p class="mb-3">¿A dónde quieres mover «<strong data-mover-nombre></strong>»?</p>
                    <p class="text-muted m-0" data-mover-paso="cargando" role="status">
                        <span class="spinner-border spinner-border-sm me-2" aria-hidden="true"></span>Buscando lugares…
                    </p>
                    <div class="alert alert-warning m-0" data-mover-paso="vacio" role="status" hidden>
                        <i class="bi bi-info-circle-fill" aria-hidden="true"></i> <span data-mover-mensaje>No hay otro lugar activo del nivel correcto en esta sede.</span>
                    </div>
                    <div data-mover-paso="elegir" hidden>
                        <label class="campo-etiqueta" for="moverDestino">Nuevo lugar</label>
                        <select id="moverDestino" name="destino_id" class="campo" required data-mover-destino></select>
                        <p class="campo-ayuda mb-3"><i class="bi bi-info-circle" aria-hidden="true"></i> Se mueve con todo lo que tiene dentro. Sus llaves, equipos, recorridos e historial se conservan.</p>
                    </div>
                    <div class="dialogo-acciones">
                        <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                        <button type="submit" class="btn-ambar" data-mover-enviar disabled>Guardar</button>
                    </div>
                </form>
            </div>
        </dialog>
    @endpush
@endonce
@pushOnce('scripts', 'js-espacios')
    <script src="{{ asset('js/espacios.js') }}?v={{ @filemtime(public_path('js/espacios.js')) ?: '1' }}"></script>
@endPushOnce
