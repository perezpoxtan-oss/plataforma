{{-- Justificar una falta, un retardo o una salida anticipada (Guardar; queda en la Bitácora de auditoría) --}}
@if ($puedeJustificar)
    <dialog id="dialogoJustificar" class="dialogo" aria-labelledby="titulo-justificar">
        <div class="dialogo-cabecera">
            <h2 id="titulo-justificar"><i class="bi bi-check2-square me-2 text-primary" aria-hidden="true"></i>Justificar</h2>
            <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div class="dialogo-cuerpo">
            <form action="" method="POST" autocomplete="off">
                @csrf
                <input type="hidden" name="_volver" value="{{ request()->getRequestUri() }}">
                <label class="campo-etiqueta" for="justificar_persona">Colaborador y día</label>
                <input type="text" id="justificar_persona" name="persona" class="campo" readonly tabindex="-1">

                <label class="campo-etiqueta" for="justificar_tipo">Qué se justifica</label>
                <select id="justificar_tipo" name="tipo" class="campo" required>
                    @foreach ($tipos as $clave => $texto)
                        <option value="{{ $clave }}">{{ $texto }}</option>
                    @endforeach
                </select>

                <label class="campo-etiqueta" for="justificar_motivo">Motivo</label>
                <select id="justificar_motivo" name="motivo" class="campo" required>
                    <option value="">Elige el motivo</option>
                    @foreach ($motivos as $clave => $texto)
                        <option value="{{ $clave }}">{{ $texto }}</option>
                    @endforeach
                </select>

                <label class="campo-etiqueta" for="justificar_nota">Nota (opcional; obligatoria con «Otro»)</label>
                <textarea id="justificar_nota" name="nota" class="campo" maxlength="255" rows="2" placeholder="Ej. Permiso firmado por su jefe"></textarea>
                <p class="small text-muted"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Si ya estaba justificado, guardar cambia el motivo. Queda registrado quién lo justificó y cuándo.</p>

                <div class="dialogo-acciones">
                    <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                    <button type="submit" class="btn-filtrar">Guardar</button>
                </div>
            </form>
        </div>
    </dialog>
@endif
