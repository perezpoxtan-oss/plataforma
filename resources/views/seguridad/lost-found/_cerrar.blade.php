{{--
    "Cerrar Artículo" (SEGCAT: lf_articulo_cerrar_modal.php). Un solo diálogo
    por pantalla: en el archivo, el botón "Cerrar / Entregar" de cada artículo
    lo llena (plataforma.js, bloque "Lost & Found y Robo"); en la ficha del
    artículo ya viene lleno. Si el servidor rechaza el cierre, se vuelve a
    abrir con lo capturado y los errores adentro.

    Parámetros: $articulo (o null), $volver ('archivo' | 'ficha'), $abrir (bool)
--}}
@use('App\Models\LostFoundEntrega')
@use('App\Models\Persona')
@php
    $trasError = $abrir && $errors->any();
    $previo = fn (string $campo, $omision = null) => $trasError ? old($campo, $omision) : $omision;
    $tipoPrevio = $previo('tipo_cierre');
    $recibePrevio = $previo('recibe_es', 'externo');
    $vinculo = $articulo?->reportesVinculados?->first();
    $nombrePropuesto = $vinculo?->nombre_huesped;
    $identificaciones = collect(Persona::IDENTIFICACIONES)->map(fn ($t) => mb_strtoupper($t))->values();
    // Tras un error, el colaborador ya elegido se conserva en el lector
    $colaboradorPrevio = $trasError && ctype_digit((string) old('colaborador_id')) ? \App\Models\Colaborador::find((int) old('colaborador_id')) : null;
    $lectorPrevio = $colaboradorPrevio ? ['valor' => $colaboradorPrevio->id, 'elegido' => $colaboradorPrevio->nombreCompleto().' · Núm. '.$colaboradorPrevio->num_empleado] : [];
@endphp
<dialog id="dialogoCerrarArticulo" class="dialogo dialogo-cierre-lf" aria-labelledby="titulo-cierre-lf" data-lf-cierre @if ($abrir) data-abrir-al-cargar @endif>
    <div class="dialogo-cabecera">
        <h2 id="titulo-cierre-lf"><i class="bi bi-box-arrow-right me-2 text-warning" aria-hidden="true"></i>Cerrar Artículo — <span data-lf-folio>{{ $articulo?->folio }}</span></h2>
        <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </div>
    <div class="dialogo-cuerpo">
        <div class="resumen-cierre-lf">
            <strong data-lf-objeto>{{ $articulo?->objeto }}</strong><span data-lf-detalle>{{ $articulo && trim($articulo->marca.' '.$articulo->color) !== '' ? ' — '.trim($articulo->marca.' '.$articulo->color) : '' }}</span>
            <div class="text-muted small">Estatus actual: En Resguardo<span data-lf-bodega>{{ $articulo?->ubicacion_bodega ? ' · Bodega: '.$articulo->ubicacion_bodega : '' }}</span></div>
        </div>
        <div class="alert alert-success py-2 px-3 small" data-lf-vinculo @if (! $vinculo) hidden @endif>
            <i class="bi bi-link-45deg me-1" aria-hidden="true"></i><span data-lf-vinculo-texto>@if ($vinculo)Reporte de pérdida {{ $vinculo->folio }}@if ($vinculo->nombre_huesped) de {{ $vinculo->nombre_huesped }}@endif. Revisa que sea la misma persona antes de entregar.@endif</span>
        </div>

        <form action="{{ $articulo ? route('lost_found.articulos.cerrar', $articulo->id) : '' }}" method="POST" autocomplete="off" data-form-cierre-lf>
            @csrf
            <input type="hidden" name="_dialogo" value="{{ $articulo ? 'cerrar-'.$articulo->id : '' }}" data-lf-dialogo>
            <input type="hidden" name="volver" value="{{ $volver }}">
            @if ($trasError)
                <div class="alert alert-danger small py-2" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>
                    @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
                </div>
            @endif

            <fieldset class="tipos-cierre-lf">
                <legend class="campo-etiqueta">¿Cómo se cierra el artículo?</legend>
                @foreach (LostFoundEntrega::OPCIONES_CIERRE as $clave => $texto)
                    <label class="opcion-cierre-lf">
                        <input type="radio" name="tipo_cierre" value="{{ $clave }}" required @checked($tipoPrevio === $clave)
                               data-etiqueta-firma="{{ LostFoundEntrega::ETIQUETAS_FIRMA[$clave] }}">
                        <i class="bi {{ ['PERSONA' => 'bi-person-check', 'PAQUETERIA' => 'bi-truck', 'DONADO' => 'bi-gift', 'DESTRUIDO' => 'bi-trash3', 'BENEFICENCIA' => 'bi-heart'][$clave] }}" aria-hidden="true"></i>
                        <span>{{ $texto }}</span>
                    </label>
                @endforeach
            </fieldset>

            {{-- Devuelto en persona: huésped / persona externa o colaborador --}}
            <div class="bloque-cierre-lf" data-lf-si-tipo="PERSONA" hidden>
                <fieldset class="recibe-lf">
                    <legend class="campo-etiqueta">¿Quién recibe?</legend>
                    <label class="opcion-recibe-lf"><input type="radio" name="recibe_es" value="externo" data-por-defecto @checked($recibePrevio !== 'colaborador')> Huésped o persona externa</label>
                    <label class="opcion-recibe-lf"><input type="radio" name="recibe_es" value="colaborador" @checked($recibePrevio === 'colaborador')> Colaborador (escanear su gafete)</label>
                </fieldset>
                <div data-lf-si-tipo="PERSONA" data-lf-si-recibe="externo" hidden>
                    <label class="campo-etiqueta" for="lf_nombre_recibe">Nombre de quien recibe</label>
                    <div class="campo-con-boton">
                        <input type="text" id="lf_nombre_recibe" name="nombre_recibe" class="campo text-uppercase" maxlength="150" required
                               value="{{ $previo('nombre_recibe', $nombrePropuesto) }}" data-lf-nombre-recibe data-lf-propuesto="{{ $nombrePropuesto }}">
                        @if ($puedePersona)
                            <button type="button" class="btn-fui-yo" data-abrir-dialogo="dialogoRegistroRapidoPersona"><i class="bi bi-person-vcard me-1" aria-hidden="true"></i>Registrar en el Padrón</button>
                        @endif
                    </div>
                    <input type="hidden" name="persona_id" value="{{ $previo('persona_id') }}" data-lf-persona-id>
                    <p class="campo-ayuda" data-lf-persona-elegida @if (! $previo('persona_id')) hidden @endif><i class="bi bi-check-circle-fill text-success" aria-hidden="true"></i> Registrada en el Padrón de personas.</p>
                    <div class="row">
                        <div class="col-sm-6">
                            <label class="campo-etiqueta" for="lf_tipo_identificacion">Tipo de identificación</label>
                            <select id="lf_tipo_identificacion" name="tipo_identificacion" class="campo">
                                <option value="">-- Seleccionar --</option>
                                @foreach ($identificaciones as $texto)
                                    <option value="{{ $texto }}" @selected($previo('tipo_identificacion') === $texto)>{{ $texto }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-sm-6">
                            <label class="campo-etiqueta" for="lf_correo_recibe">Correo electrónico <span class="text-lowercase fw-normal">(opcional)</span></label>
                            <input type="email" id="lf_correo_recibe" name="correo_recibe" class="campo" maxlength="150" inputmode="email" value="{{ $previo('correo_recibe', $vinculo?->correo) }}" data-lf-correo data-lf-propuesto="{{ $vinculo?->correo }}">
                        </div>
                    </div>
                </div>
                <div data-lf-si-tipo="PERSONA" data-lf-si-recibe="colaborador" hidden>
                    @include('componentes.lector', ['id' => 'lf_cierre_colaborador', 'etiqueta' => 'Colaborador que recibe', 'tipos' => 'colaborador',
                        'nombre' => 'colaborador_id', 'requerido' => true, 'ayuda' => 'Escanea su gafete o escribe su número de empleado o nombre.'] + ($recibePrevio === 'colaborador' ? $lectorPrevio : []))
                </div>
            </div>

            {{-- Enviado por paquetería --}}
            <div class="bloque-cierre-lf" data-lf-si-tipo="PAQUETERIA" hidden>
                <div class="row">
                    <div class="col-sm-6">
                        <label class="campo-etiqueta" for="lf_nombre_recibe_paq">Nombre de quien recibe</label>
                        <input type="text" id="lf_nombre_recibe_paq" name="nombre_recibe" class="campo text-uppercase" maxlength="150" required value="{{ $previo('nombre_recibe', $nombrePropuesto) }}" data-lf-nombre-recibe data-lf-propuesto="{{ $nombrePropuesto }}">
                    </div>
                    <div class="col-sm-6">
                        <label class="campo-etiqueta" for="lf_correo_recibe_paq">Correo electrónico <span class="text-lowercase fw-normal">(opcional)</span></label>
                        <input type="email" id="lf_correo_recibe_paq" name="correo_recibe" class="campo" maxlength="150" inputmode="email" value="{{ $previo('correo_recibe', $vinculo?->correo) }}" data-lf-correo data-lf-propuesto="{{ $vinculo?->correo }}">
                    </div>
                    <div class="col-sm-6">
                        <label class="campo-etiqueta" for="lf_paqueteria">Paquetería</label>
                        <select id="lf_paqueteria" name="paqueteria" class="campo" required>
                            <option value="">-- Seleccionar --</option>
                            @foreach (LostFoundEntrega::PAQUETERIAS as $p)
                                <option value="{{ $p }}" @selected($previo('paqueteria') === $p)>{{ $p }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="col-sm-6">
                        <label class="campo-etiqueta" for="lf_numero_guia">Número de guía</label>
                        <input type="text" id="lf_numero_guia" name="numero_guia" class="campo text-uppercase" maxlength="100" required value="{{ $previo('numero_guia') }}">
                    </div>
                </div>
            </div>

            {{-- Donado a colaborador --}}
            <div class="bloque-cierre-lf" data-lf-si-tipo="DONADO" hidden>
                @include('componentes.lector', ['id' => 'lf_cierre_donado', 'etiqueta' => 'Colaborador que recibe la donación', 'tipos' => 'colaborador',
                    'nombre' => 'colaborador_id', 'requerido' => true, 'ayuda' => 'Escanea su gafete o escribe su número de empleado o nombre.'] + ($tipoPrevio === 'DONADO' ? $lectorPrevio : []))
            </div>

            {{-- Entregado a beneficencia --}}
            <div class="bloque-cierre-lf" data-lf-si-tipo="BENEFICENCIA" hidden>
                <label class="campo-etiqueta" for="lf_institucion">Institución que recibe</label>
                <input type="text" id="lf_institucion" name="nombre_recibe" class="campo text-uppercase" maxlength="150" required placeholder="Ej. Casa hogar, Cruz Roja..." value="{{ $previo('nombre_recibe') }}">
            </div>

            <div class="bloque-cierre-lf" data-lf-si-tipo="PERSONA PAQUETERIA DONADO DESTRUIDO BENEFICENCIA" hidden>
                @include('componentes.firma', ['id' => 'lf_firma', 'nombre' => 'firma', 'etiqueta' => $tipoPrevio ? LostFoundEntrega::ETIQUETAS_FIRMA[$tipoPrevio] ?? 'Firma' : 'Firma', 'requerido' => true])
                <label class="campo-etiqueta mt-2" for="lf_observaciones">Comentarios adicionales <span class="text-lowercase fw-normal">(opcional)</span></label>
                <textarea id="lf_observaciones" name="observaciones" class="campo" rows="2" maxlength="2000" placeholder="Cualquier detalle extra sobre este cierre...">{{ $previo('observaciones') }}</textarea>
            </div>

            <div class="dialogo-acciones">
                <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                <button type="submit" class="btn-registrar-cierre"><i class="bi bi-box-arrow-right me-1" aria-hidden="true"></i>Registrar Cierre</button>
            </div>
        </form>
    </div>
</dialog>
