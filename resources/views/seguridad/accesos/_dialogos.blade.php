{{--
    Diálogos de las tarjetas (SEGCAT): "Cambiar Zona", "Salida a Tour — …" /
    "Salida Temporal — …" y "Regreso de Tour — …". plataforma.js les pone la
    dirección del acceso y el nombre al abrirlos (data-accion="acceso-dialogo").
    Si el envío regresa con un error, el diálogo se vuelve a abrir solo, con su mensaje dentro.
--}}
@php
    $reabrir = fn (string $dialogo) => old('_dialogo') === $dialogo && ctype_digit((string) old('_acceso'));
    $erroresDialogo = $errors->all();
    $dialogos = [
        'dialogoZonaAcceso' => ['zona', 'accesos.zona'],
        'dialogoSalidaTemporalAcceso' => ['salida', 'accesos.salida-temporal'],
        'dialogoRegresoAcceso' => ['regreso', 'accesos.regreso'],
    ];
@endphp
@foreach ($dialogos as $idDialogo => [$clase, $ruta])
    @php
        $otraVez = $reabrir($idDialogo);
        $esZona = $clase === 'zona';
        $esSalida = $clase === 'salida';
        // Giros: el «tour» es de los huéspedes; en otros giros la salida es «temporal»
        $conTour = ($terminos ?? \App\Support\Terminos::actual())->ofreceTipo('huesped');
        $tituloInicial = ['zona' => 'Cambiar Zona', 'salida' => $conTour ? 'Salida a Tour' : 'Salida Temporal', 'regreso' => $conTour ? 'Regreso de Tour' : 'Regreso'][$clase];
        $icono = ['zona' => 'bi-p-square-fill text-primary', 'salida' => 'bi-signpost-split text-warning', 'regreso' => 'bi-arrow-return-left text-success'][$clase];
    @endphp
    <dialog id="{{ $idDialogo }}" class="dialogo dialogo-acceso" aria-labelledby="titulo-{{ $idDialogo }}" @if ($otraVez) data-abrir-al-cargar @endif>
        <div class="dialogo-cabecera">
            <h2 id="titulo-{{ $idDialogo }}">
                <i class="bi {{ $icono }} me-2" aria-hidden="true"></i><span data-dialogo-titulo>{{ $otraVez && old('_titulo') ? old('_titulo') : $tituloInicial }}</span>@unless ($esZona) — <span data-dialogo-nombre>{{ $otraVez ? old('_nombre') : '' }}</span>@endunless
            </h2>
            <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div class="dialogo-cuerpo">
            @if ($esSalida)
                <p class="text-muted small mb-2"><i class="bi bi-info-circle" aria-hidden="true"></i> Sale temporalmente sin cerrar su visita — el registro sigue EN SITIO hasta que confirmes el regreso.</p>
            @elseif (! $esZona)
                <p class="text-muted small mb-2"><i class="bi bi-info-circle" aria-hidden="true"></i> El vehículo y chofer de regreso pueden ser distintos a los de salida.</p>
            @endif
            <form method="POST" action="{{ $otraVez ? route($ruta, (int) old('_acceso')) : '' }}" data-form-dialogo-acceso data-conservar-ruta>
                @csrf
                @unless ($esSalida) @method('PATCH') @endunless
                <input type="hidden" name="_dialogo" value="{{ $idDialogo }}">
                <input type="hidden" name="_acceso" value="{{ $otraVez ? old('_acceso') : '' }}" data-dialogo-acceso-id>
                <input type="hidden" name="_nombre" value="{{ $otraVez ? old('_nombre') : '' }}" data-dialogo-nombre-campo>
                <input type="hidden" name="_titulo" value="{{ $otraVez ? old('_titulo') : '' }}" data-dialogo-titulo-campo>
                @if ($otraVez && $erroresDialogo)
                    <div class="alert alert-danger small py-2" role="alert">@foreach ($erroresDialogo as $e)<div>{{ $e }}</div>@endforeach</div>
                @endif

                @if ($esZona)
                    <p class="small text-muted mb-2" data-dialogo-nombre>{{ $otraVez ? old('_nombre') : '' }}</p>
                    <label class="campo-etiqueta" for="zona_nueva">Nueva Zona</label>
                    <select id="zona_nueva" name="zona_estacionamiento_id" class="campo" data-zona-sede>
                        <option value="">-- Sin asignar (liberar) --</option>
                        @foreach ($zonas as $z)
                            <option value="{{ $z['id'] }}" data-sede="{{ $z['sede_id'] }}" @selected($otraVez && (string) old('zona_estacionamiento_id') === (string) $z['id'])>{{ $z['texto'] }}</option>
                        @endforeach
                    </select>
                    @if ($otraVez && $errors->has('zona_llena'))
                        {{-- C-21: «Zona llena, ¿enviar de todos modos?» --}}
                        <label class="casilla-devolucion mt-2"><input type="checkbox" name="zona_llena" value="si"> Enviar aunque esté llena</label>
                    @endif
                @else
                    @unless ($esSalida)
                        <label class="campo-etiqueta" for="{{ $idDialogo }}_conductor">Conductor de Regreso <span class="text-lowercase fw-normal">(opcional — si no lo llenas, se usa el nombre del titular)</span></label>
                        <input type="text" id="{{ $idDialogo }}_conductor" name="conductor" class="campo text-uppercase" maxlength="150" placeholder="Nombre de quien conduce" value="{{ $otraVez ? old('conductor') : '' }}">
                    @endunless
                    <div data-sugerir="vehiculo">
                        @include('componentes.lector', ['id' => $idDialogo.'_lector', 'etiqueta' => 'Placas (opcional)', 'tipos' => 'vehiculo', 'nombre' => 'vehiculo_id'])
                        <input type="hidden" name="placas" value="{{ $otraVez ? old('placas') : '' }}" data-placas>
                        <div class="acceso-sugerencias" data-sugerencias hidden></div>
                    </div>
                    <label class="campo-etiqueta" for="{{ $idDialogo }}_marca">Marca</label>
                    <input type="text" id="{{ $idDialogo }}_marca" name="marca" class="campo text-uppercase" maxlength="50" data-vehiculo-campo="marca" value="{{ $otraVez ? old('marca') : '' }}">
                    @if ($esSalida)
                        {{-- C-21: si el auto se va con él, su lugar queda libre --}}
                        <label class="casilla-devolucion mt-2"><input type="checkbox" name="libera_lugar" value="1" @checked($otraVez && old('libera_lugar'))> Libera su lugar de estacionamiento (el auto se va)</label>
                        <label class="campo-etiqueta" for="{{ $idDialogo }}_conductor">Conductor <span class="text-lowercase fw-normal">(opcional — si no lo llenas, se usa el nombre del titular)</span></label>
                        <input type="text" id="{{ $idDialogo }}_conductor" name="conductor" class="campo text-uppercase" maxlength="150" placeholder="Nombre de quien conduce" value="{{ $otraVez ? old('conductor') : '' }}">
                    @endif
                @endif

                <div class="dialogo-acciones">
                    <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                    <button type="submit" class="{{ $esZona ? 'btn-azul' : ($esSalida ? 'btn-ambar-acceso' : 'btn-verde') }}">{{ $esZona ? 'Guardar' : ($esSalida ? 'Confirmar Salida' : 'Confirmar Regreso') }}</button>
                </div>
            </form>
        </div>
    </dialog>
@endforeach

{{--
    «Dar salida» a la vista: confirmación corta de la persona encontrada con el campo de escaneo o búsqueda
    (o con «Dar Salida» del formulario). plataforma.js llena la tarjeta y la dirección del envío; si la persona
    está fuera (salida temporal o tour), ofrece «Registrar regreso» en lugar de la salida.
--}}
<dialog id="dialogoDarSalida" class="dialogo dialogo-acceso dialogo-dar-salida" aria-labelledby="titulo-dialogoDarSalida">
    <div class="dialogo-cabecera">
        <h2 id="titulo-dialogoDarSalida">
            <i class="bi bi-box-arrow-right me-2 text-danger" aria-hidden="true"></i><span data-salida-titulo>Dar salida</span> — <span data-salida-nombre></span>
        </h2>
        <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </div>
    <div class="dialogo-cuerpo">
        <div class="tarjeta-dar-salida" data-salida-tarjeta></div>
        <form method="POST" action="" data-salida-form class="m-0">
            @csrf
            @method('PATCH')
            {{-- C-15: casillas ya marcadas «Devolvió el gafete …» y «Le devolví su identificación» (las llena plataforma.js) --}}
            <fieldset class="devolucion-salida" data-salida-devolucion hidden></fieldset>
            <div class="dialogo-acciones">
                <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                <button type="button" class="btn-ambar-acceso" data-salida-regreso hidden>Registrar regreso</button>
                <button type="submit" class="btn-rojo-acceso" data-salida-boton>Dar salida</button>
            </div>
        </form>
    </div>
</dialog>
