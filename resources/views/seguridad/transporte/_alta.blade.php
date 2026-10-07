{{--
    "Registrar Bitácora Logística" (SEGCAT: dialogAlta de bitacora_transporte.php).
    Lo dinámico (rutas por sede y sentido, horario sugerido, taxis, pasajeros,
    tope de la ruta) vive en el bloque "Bitácora de transporte" de plataforma.js.
--}}
@php
    $trasError = old('_dialogo') === 'crear';
    $siguiente = session('capturar_siguiente');
    $unaSede = $alta['sedes']->count() === 1;
    $sedePorDefecto = $unaSede ? (string) $alta['sedes']->first()->id : '';
    $sedeElegida = $trasError ? (string) old('sede_id') : (string) ($siguiente['sede_id'] ?? $sedePorDefecto);
    $sugerencia = $alta['sugerencias'][(int) $sedeElegida] ?? null;
    $sentidoPorDefecto = $sugerencia['sentido'] ?? 'llegada';
    $tipoElegido = $trasError ? (string) old('tipo_movimiento') : (string) ($siguiente['tipo_movimiento'] ?? $sentidoPorDefecto);
    $estatusElegido = $trasError ? (string) old('estatus', 'a_tiempo') : 'a_tiempo';
    $horarioElegido = $trasError ? (string) old('ruta_horario_id') : (string) ($sugerencia[$tipoElegido] ?? '');
    $taxisAnteriores = $trasError ? array_values(array_filter((array) old('taxis', []), 'is_array')) : [];
    $conFirmas = $puede['firmar'];
    $altaColaborador = auth()->user()->can('colaboradores.crear') || auth()->user()->can('colaboradores.provisional');
    $anterior = fn (string $campo) => $trasError && is_scalar(old($campo)) ? (string) old($campo) : '';
    $tipoUnidad = $anterior('tipo_unidad') ?: 'autobus';
@endphp
<datalist id="unidadesConocidas">
    @foreach ($alta['unidades'] as $placas => $datos)
        <option value="{{ $placas }}"></option>
    @endforeach
</datalist>
<datalist id="taxisConocidos">
    @foreach ($alta['taxis'] as $placas => $datos)
        <option value="{{ $placas }}"></option>
    @endforeach
</datalist>
<datalist id="choferesConocidos">
    @foreach ($alta['choferes'] as $nombre => $telefono)
        <option value="{{ $nombre }}"></option>
    @endforeach
</datalist>

<template data-plantilla-taxi>
    @include('seguridad.transporte._taxi', ['i' => '__T__', 't' => [], 'pasajerosAnteriores' => [], 'conFirmas' => $conFirmas, 'altaColaborador' => $altaColaborador, 'sedeId' => $sedeElegida ?: '0'])
</template>

<dialog id="dialogoAltaTransporte" class="dialogo ancho transporte-dialogo" aria-labelledby="titulo-alta-transporte"
        data-horarios-sugeridos="{{ json_encode($alta['sugerencias']) }}" @if ($trasError || $siguiente) data-abrir-al-cargar @endif>
    <div class="dialogo-cabecera">
        <h2 id="titulo-alta-transporte"><i class="bi bi-clipboard2-pulse me-2 text-primary" aria-hidden="true"></i>Registrar Bitácora Logística</h2>
        <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </div>
    <div class="dialogo-cuerpo">
        <form action="{{ route('transporte.store') }}" method="POST" autocomplete="off" data-form-transporte
              data-unidades="{{ json_encode($alta['unidades']) }}" data-taxis="{{ json_encode($alta['taxis']) }}" data-choferes="{{ json_encode($alta['choferes']) }}">
            @csrf
            <input type="hidden" name="_dialogo" value="crear">
            @if ($siguiente && ! $trasError)
                <div class="alert alert-success aviso py-2 small" role="status"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Guardado. Captura el siguiente movimiento.</div>
            @endif
            <p class="linea-empresa mb-3"><i class="bi bi-building-check me-1" aria-hidden="true"></i>Bitácora de: <strong>{{ $empresaNombre }}</strong></p>

            @if ($unaSede)
                <input type="hidden" name="sede_id" value="{{ $sedePorDefecto }}" data-sede-transporte>
                <p class="transporte-sede-fija"><i class="bi bi-house-door me-1" aria-hidden="true"></i>Sede: <strong>{{ $alta['sedes']->first()->nombre }}</strong></p>
            @else
                <label class="campo-etiqueta" for="tr_sede">Sede</label>
                <select id="tr_sede" name="sede_id" class="campo" required data-sede-transporte>
                    <option value="">-- Seleccionar Sede --</option>
                    @foreach ($alta['sedes'] as $s)
                        <option value="{{ $s->id }}" @selected($sedeElegida === (string) $s->id)>{{ $s->nombre }}</option>
                    @endforeach
                </select>
            @endif

            <span class="campo-etiqueta">Tipo de Movimiento</span>
            <div class="transporte-opciones dos" role="radiogroup" aria-label="Tipo de movimiento">
                @foreach (['llegada' => ['bi-box-arrow-in-right', 'LLEGADA', '(A la Sede)'], 'salida' => ['bi-box-arrow-up-right', 'SALIDA', '(Hacia Paraderos)']] as $clave => [$icono, $texto, $detalle])
                    <label class="transporte-opcion {{ $clave }}">
                        <input type="radio" name="tipo_movimiento" value="{{ $clave }}" required @checked($tipoElegido === $clave) @if ($clave === 'llegada') data-por-defecto @endif>
                        <i class="bi {{ $icono }}" aria-hidden="true"></i><strong>{{ $texto }}</strong><small>{{ $detalle }}</small>
                    </label>
                @endforeach
            </div>

            <span class="campo-etiqueta">Estatus del Servicio</span>
            <div class="transporte-opciones tres" role="radiogroup" aria-label="Estatus del servicio">
                @foreach (['a_tiempo' => ['bi-check-circle-fill', 'SERVICIO NORMAL', '(A Tiempo)'], 'retraso' => ['bi-hourglass-split', 'SERVICIO NORMAL', '(Con Retraso)'], 'no_llego' => ['bi-exclamation-triangle-fill', 'FALLA DE FLETERA', '(Uso de Taxis)']] as $clave => [$icono, $texto, $detalle])
                    <label class="transporte-opcion estatus-{{ str_replace('_', '-', $clave) }}">
                        <input type="radio" name="estatus" value="{{ $clave }}" required @checked($estatusElegido === $clave) @if ($clave === 'a_tiempo') data-por-defecto @endif>
                        <i class="bi {{ $icono }}" aria-hidden="true"></i><strong>{{ $texto }}</strong><small>{{ $detalle }}</small>
                    </label>
                @endforeach
            </div>

            <label class="campo-etiqueta" for="tr_ruta">Ruta Afectada / Programada</label>
            <select id="tr_ruta" name="ruta_horario_id" class="campo mb-1" required data-ruta-transporte>
                <option value="">-- Seleccionar Ruta --</option>
                @foreach ($alta['horarios'] as $h)
                    @php
                        $visible = (string) $h->ruta->sede_id === $sedeElegida && $h->ruta->sentido === $tipoElegido;
                        $hora = $h->ruta->sentido === 'llegada' ? 'llega '.$h->fin() : 'sale '.$h->inicio();
                    @endphp
                    <option value="{{ $h->id }}" data-sede="{{ $h->ruta->sede_id }}" data-sentido="{{ $h->ruta->sentido }}"
                            data-tope="{{ $h->ruta->costo_maximo_taxi }}" data-transportista="{{ $h->ruta->proveedor?->nombre }}"
                            @selected($horarioElegido === (string) $h->id) @unless ($visible) hidden disabled @endunless>{{ $h->ruta->nombre }} · {{ $hora }} · {{ $h->patronDias() }}</option>
                @endforeach
            </select>
            <p class="campo-ayuda transporte-info-ruta mt-0" data-info-ruta hidden></p>
            <p class="campo-ayuda transporte-sin-rutas mt-0" data-sin-rutas hidden><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>Esta sede no tiene rutas activas de este tipo. Pide que las den de alta en Padrones → Rutas de transporte.</p>

            {{-- ===== Servicio normal ===== --}}
            <div class="caja-normal" data-caja-normal @if ($estatusElegido === 'no_llego') hidden @endif>
                <h3 class="caja-titulo text-success"><i class="bi bi-bus-front me-2" aria-hidden="true"></i>Datos de la Unidad</h3>
                <div class="row">
                    <div class="col-md-6">
                        <label class="campo-etiqueta text-success" for="tr_placas">Placas <span class="text-muted text-lowercase fw-normal">(buscar o nueva)</span></label>
                        <input type="text" id="tr_placas" name="placas" class="campo text-uppercase" maxlength="20" list="unidadesConocidas" placeholder="Ej: ABC-123"
                               autocapitalize="characters" value="{{ $anterior('placas') }}" data-autollenar="unidad"
                               data-parecidos-vivo="vehiculos" data-parecidos-url="{{ route('altas_por_verificar.parecidos') }}" data-parecidos-origen="transporte">
                    </div>
                    <div class="col-md-6">
                        <label class="campo-etiqueta text-success" for="tr_chofer">Chofer <span class="text-muted text-lowercase fw-normal">(buscar o nuevo)</span></label>
                        <input type="text" id="tr_chofer" name="chofer" class="campo text-uppercase" maxlength="150" list="choferesConocidos" placeholder="Nombre del chofer"
                               autocapitalize="characters" value="{{ $anterior('chofer') }}" data-autollenar-chofer
                               data-parecidos-vivo="personas" data-parecidos-url="{{ route('altas_por_verificar.parecidos') }}" data-parecidos-origen="transporte">
                    </div>
                </div>
                <label class="campo-etiqueta text-success" for="tr_pax">Pasajeros</label>
                <input type="number" id="tr_pax" name="cantidad_pax" class="campo transporte-pax-campo" min="0" max="999" inputmode="numeric" placeholder="Ej: 18"
                       value="{{ $anterior('cantidad_pax') }}" data-pax-normal>
                <div class="alert alert-danger py-2 px-3 small" data-aviso-sobrecupo hidden><i class="bi bi-shield-exclamation me-1" aria-hidden="true"></i><span data-texto-sobrecupo></span></div>
                <details class="transporte-detalles">
                    <summary>Más detalles <span class="text-muted fw-normal">(opcional)</span></summary>
                    <div class="row mt-2">
                        <div class="col-6 col-md-3">
                            <label class="campo-etiqueta" for="tr_tipo">Tipo Vehículo</label>
                            <select id="tr_tipo" name="tipo_unidad" class="campo" data-campo-unidad="tipo">
                                @foreach (\App\Models\MovimientoTransporte::TIPOS_UNIDAD as $clave => [$etiquetaTipo])
                                    <option value="{{ $clave }}" @selected($tipoUnidad === $clave) @if ($clave === 'autobus') data-por-defecto @endif>{{ $etiquetaTipo }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="campo-etiqueta" for="tr_marca">Marca</label>
                            <input type="text" id="tr_marca" name="marca" class="campo text-uppercase" maxlength="50" placeholder="Se autocompleta" value="{{ $anterior('marca') }}" data-campo-unidad="marca">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="campo-etiqueta" for="tr_modelo">Modelo</label>
                            <input type="text" id="tr_modelo" name="modelo" class="campo text-uppercase" maxlength="60" placeholder="Se autocompleta" value="{{ $anterior('modelo') }}" data-campo-unidad="modelo">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="campo-etiqueta" for="tr_economico">Núm. Económico</label>
                            <input type="text" id="tr_economico" name="economico" class="campo text-uppercase" maxlength="30" placeholder="Ej: ECO-045" value="{{ $anterior('economico') }}" data-campo-unidad="economico">
                        </div>
                        <div class="col-6">
                            <label class="campo-etiqueta" for="tr_capacidad">Capacidad PAX</label>
                            <input type="number" id="tr_capacidad" name="capacidad" class="campo" min="1" max="99" inputmode="numeric" placeholder="Ej: 20" value="{{ $anterior('capacidad') }}" data-campo-unidad="capacidad" data-capacidad-normal>
                        </div>
                        <div class="col-6">
                            <label class="campo-etiqueta" for="tr_tel">Tel. Chofer</label>
                            <input type="tel" id="tr_tel" name="chofer_telefono" class="campo" maxlength="20" inputmode="tel" placeholder="Opcional" value="{{ $anterior('chofer_telefono') }}" data-telefono-chofer>
                        </div>
                    </div>
                </details>
            </div>

            {{-- ===== Taxis de emergencia ===== --}}
            <div class="caja-taxi" data-caja-taxi @unless ($estatusElegido === 'no_llego') hidden @endunless>
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2">
                    <h3 class="caja-titulo text-danger m-0"><i class="bi bi-exclamation-triangle-fill me-2" aria-hidden="true"></i>Despacho de Unidades de Emergencia (Taxis)</h3>
                    <button type="button" class="btn-agregar-taxi" data-agregar-taxi><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Añadir otro Taxi</button>
                </div>
                <p class="small text-muted mb-2"><i class="bi bi-info-circle" aria-hidden="true"></i>
                    @if ($conFirmas)
                        Cada taxi lleva su vale de caja chica: captura el monto, el destino, los colaboradores que viajan y la firma del conductor.
                    @else
                        Cada taxi lleva su vale de caja chica. El vale impreso trae los espacios para firmar a mano (Seguridad, Conductor, Autorización).
                    @endif
                </p>
                <div data-taxis data-siguiente="{{ count($taxisAnteriores) }}">
                    @foreach ($taxisAnteriores as $i => $t)
                        @include('seguridad.transporte._taxi', ['i' => $i, 't' => $t, 'pasajerosAnteriores' => $pasajerosAnteriores, 'conFirmas' => $conFirmas, 'altaColaborador' => $altaColaborador, 'sedeId' => $sedeElegida ?: '0'])
                    @endforeach
                </div>
            </div>

            @if ($conFirmas)
                @include('componentes.firma', ['id' => 'tr_firma_guardia', 'nombre' => 'firma_guardia', 'etiqueta' => 'Firma del guardia (Seguridad)',
                    'ayuda' => 'Obligatoria cuando se usan taxis; en un servicio normal es opcional.'])
            @endif

            <label class="campo-etiqueta" for="tr_observaciones">Observaciones de Caseta <span class="text-lowercase fw-normal">(opcional)</span></label>
            <input type="text" id="tr_observaciones" name="observaciones" class="campo" maxlength="1000" placeholder="Detalles mecánicos de la fletera, retrasos de tráfico, etc." value="{{ $anterior('observaciones') }}">

            <div class="dialogo-acciones transporte-acciones">
                <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                <button type="submit" name="_siguiente" value="1" class="btn-siguiente"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i><span class="d-none d-sm-inline">Registrar y capturar siguiente</span><span class="d-sm-none">Y capturar otro</span></button>
                <button type="submit" class="btn-transporte">Guardar Registro Operativo</button>
            </div>
        </form>
    </div>
</dialog>
