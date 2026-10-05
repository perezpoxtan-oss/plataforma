{{--
    "Editar Registro #" (SEGCAT: bitacora_modal_editar.php). Se llena con
    data-accion="editar-movimiento" (bloque "Bitácora de transporte" de plataforma.js).
    Parámetros: $editando (movimiento cuya edición regresó con errores, o null), $pasajerosAnteriores.
--}}
@php
    $e = $editando;
    $esTaxi = $e?->esTaxi() ?? false;
    $old = fn (string $campo, mixed $actual) => $e ? (is_scalar(old($campo)) ? (string) old($campo) : (string) $actual) : '';
    $elegidos = $e ? collect((array) old('pasajeros', []))->filter(fn ($id) => is_numeric($id) && isset($pasajerosAnteriores[(int) $id]))->map(fn ($id) => (int) $id)->unique() : collect();
    $estatus = $e ? (string) old('estatus', $e->estatus) : 'a_tiempo';
@endphp
<dialog id="dialogoEditarMovimiento" class="dialogo ancho transporte-dialogo" aria-labelledby="titulo-editar-mov" @if ($e) data-abrir-al-cargar @endif>
    <div class="dialogo-cabecera">
        <h2 id="titulo-editar-mov"><i class="bi bi-pencil-square me-2 text-primary" aria-hidden="true"></i>Editar Registro <span data-editar-folio>{{ $e?->folio() }}</span>
            <span class="badge-tipo-registro {{ $esTaxi ? 'taxi' : 'normal' }}" data-editar-tipo>{{ $e ? ($esTaxi ? 'TAXI' : 'NORMAL') : '' }}</span></h2>
        <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </div>
    <div class="dialogo-cuerpo">
        <p class="campo-ayuda mt-0 mb-3"><i class="bi bi-info-circle" aria-hidden="true"></i> Solo se pueden corregir los datos de abajo — la empresa, sede, ruta y vehículo/chofer no cambian aquí (si se equivocaron en eso, anula este registro y crea uno nuevo).</p>
        <form action="{{ $e ? route('transporte.update', $e->id) : '' }}" method="POST" autocomplete="off" data-form-editar-transporte
              data-tope="{{ $e?->ruta?->costo_maximo_taxi }}">
            @csrf
            @method('PUT')
            <input type="hidden" name="_dialogo" value="{{ $e ? 'editar-'.$e->id : '' }}" data-campo-dialogo>

            <div data-solo-normal @if ($e && $esTaxi) hidden @endif>
                <span class="campo-etiqueta">Estatus del Servicio</span>
                <div class="transporte-opciones dos" role="radiogroup" aria-label="Estatus del servicio">
                    @foreach (['a_tiempo' => ['bi-check-circle-fill', 'A TIEMPO'], 'retraso' => ['bi-hourglass-split', 'RETRASO']] as $clave => [$icono, $texto])
                        <label class="transporte-opcion estatus-{{ str_replace('_', '-', $clave) }}">
                            <input type="radio" name="estatus" value="{{ $clave }}" @checked($estatus === $clave) @if ($clave === 'a_tiempo') data-por-defecto @endif @disabled($e && $esTaxi)>
                            <i class="bi {{ $icono }}" aria-hidden="true"></i><strong>{{ $texto }}</strong>
                        </label>
                    @endforeach
                </div>
                <label class="campo-etiqueta" for="ed_pax">Cantidad de Pasajeros</label>
                <input type="number" id="ed_pax" name="cantidad_pax" class="campo transporte-pax-campo" min="0" max="999" inputmode="numeric" placeholder="Ej: 18"
                       value="{{ $old('cantidad_pax', $e?->cantidad_pax) }}" @disabled($e && $esTaxi)>
            </div>

            <div data-solo-taxi @unless ($e && $esTaxi) hidden @endunless>
                <div class="row">
                    <div class="col-md-6">
                        <label class="campo-etiqueta text-danger" for="ed_monto">Monto Vale ($)</label>
                        <input type="number" id="ed_monto" name="monto" class="campo fw-bold text-danger" step="0.01" min="0.01" max="99999.99" inputmode="decimal" required
                               value="{{ $old('monto', $e?->monto) }}" data-monto-taxi @disabled(! ($e && $esTaxi))>
                    </div>
                    <div class="col-md-6">
                        <label class="campo-etiqueta text-danger" for="ed_destino">Destino Específico</label>
                        <input type="text" id="ed_destino" name="destino" class="campo text-uppercase" maxlength="150" required list="paraderosTransporte-{{ $e?->sede_id ?? 0 }}"
                               value="{{ $old('destino', $e?->paradero?->nombre) }}" data-destino-taxi @disabled(! ($e && $esTaxi))>
                    </div>
                </div>
                <div class="aviso-tope" data-aviso-tope hidden>
                    <p class="mb-1"><i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i><span data-texto-tope>Este monto supera el tope autorizado para esta ruta.</span></p>
                </div>
                <label class="campo-etiqueta" for="ed_justificacion">Justificación de Costo <span class="text-lowercase fw-normal">(si supera el tope de la ruta)</span></label>
                <textarea id="ed_justificacion" name="justificacion" class="campo" rows="2" maxlength="1000" placeholder="Solo obligatoria si el monto supera el tope autorizado para esta ruta."
                          data-justificacion-taxi @disabled(! ($e && $esTaxi))>{{ $old('justificacion', $e?->justificacion) }}</textarea>

                <div class="taxi-pasajeros" data-pasajeros-taxi data-nombre="pasajeros[]">
                    <span class="campo-etiqueta">Pasajeros <span class="pax-contador" data-contador-pax>{{ $elegidos->count() }} PAX</span></span>
                    @include('componentes.lector', ['id' => 'ed_pasajero', 'tipos' => 'colaborador', 'nombre' => '', 'etiqueta' => null, 'valor' => null, 'elegido' => null, 'requerido' => false, 'modo' => 'buscar',
                        'ayuda' => 'Escanea el gafete o escribe el número de empleado para agregar a alguien.'])
                    <div class="pasajeros-elegidos" data-pasajeros>
                        @foreach ($elegidos as $id)
                            <span class="chip-pasajero" data-id="{{ $id }}">{{ $pasajerosAnteriores[$id] }}<input type="hidden" name="pasajeros[]" value="{{ $id }}"><button type="button" data-quitar-pasajero aria-label="Quitar a {{ $pasajerosAnteriores[$id] }}">×</button></span>
                        @endforeach
                    </div>
                </div>
            </div>

            <label class="campo-etiqueta mt-2" for="ed_observaciones">Observaciones</label>
            <textarea id="ed_observaciones" name="observaciones" class="campo" rows="2" maxlength="1000">{{ $old('observaciones', $e?->observaciones) }}</textarea>

            <div class="dialogo-acciones">
                <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                <button type="submit" class="btn-transporte">Guardar Cambios</button>
            </div>
        </form>
    </div>
</dialog>
