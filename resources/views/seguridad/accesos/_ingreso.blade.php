{{--
    "Registro Inteligente de Ingreso" (SEGCAT: #dialogAlta de accesos_lista.php).
    Un solo formulario que cambia según el Tipo de Persona. Los bloques con
    data-condicion se muestran según el tipo, la forma de llegada, el motivo o
    la reserva; sus campos se deshabilitan al ocultarse (no se envían).
    Escaneos con el lector universal: gafete, colaborador, host, a quién visita y placas.
    "Dar Salida" cambia a la búsqueda de alguien en sitio para darle salida.
--}}
@use('App\Models\Acceso')
@php
    $trasError = old('_dialogo') === 'ingreso';
    $sig = is_array($siguiente) ? $siguiente : null;
    $previo = fn (string $campo, $defecto = '') => $trasError ? old($campo, $defecto) : $defecto;
    $sedeElegida = (int) ($trasError ? old('sede_id') : ($sig['sede_id'] ?? ($sedesAlta->count() === 1 ? $sedesAlta->first()->id : 0)));
    $tipoElegido = $trasError ? old('tipo', 'colaborador') : ($sig['tipo'] ?? 'colaborador');
    $modoElegido = $previo('modo_arribo', 'a_pie') === 'a_pie' ? 'a_pie' : 'auto';
    $reservaElegida = (string) $previo('tiene_reserva', '1');
    $acompanantesPrevios = $trasError ? array_values((array) old('acompanantes', [])) : [];
    $repetida = $trasError && $errors->has('persona_repetida');
    $otrosErrores = collect($errors->getMessages())->except('persona_repetida')->flatten();
@endphp
<dialog id="dialogoIngreso" class="dialogo ancho dialogo-acceso" aria-labelledby="titulo-ingreso" data-dialogo-ingreso
        data-url-buscar="{{ route('accesos.buscar') }}" data-url-gafetes="{{ route('accesos.gafetes') }}" data-url-en-sitio="{{ route('accesos.en-sitio') }}"
        @if ($trasError || $sig) data-abrir-al-cargar @endif>
    <div class="dialogo-cabecera">
        <h2 id="titulo-ingreso">
            <span data-titulo-entrada><i class="bi bi-person-plus-fill me-2 text-danger" aria-hidden="true"></i>Registro Inteligente de Ingreso</span>
            <span data-titulo-salida hidden><i class="bi bi-box-arrow-right me-2 text-danger" aria-hidden="true"></i>Buscar y Dar Salida</span>
        </h2>
        <div class="d-flex align-items-center gap-2">
            @if ($puede['editar'])
                <button type="button" class="btn-salida-rapida" data-alternar-salida-rapida>
                    <span data-texto-entrada><i class="bi bi-box-arrow-right me-1" aria-hidden="true"></i>Dar Salida</span>
                    <span data-texto-salida hidden><i class="bi bi-person-plus-fill me-1" aria-hidden="true"></i>Registrar Ingreso</span>
                </button>
            @endif
            <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
    </div>
    <div class="dialogo-cuerpo">
        {{-- ===== Buscar y Dar Salida ===== --}}
        @if ($puede['editar'])
            <div class="vista-salida-rapida" data-vista-salida hidden>
                <label class="campo-etiqueta" for="salida_rapida_texto">Buscar por placas, nombre o gafete</label>
                <input type="search" id="salida_rapida_texto" class="campo" maxlength="100" autocomplete="off" placeholder="Ej: ABC123, Juan Pérez, HOT-CEN-VIS-001..." data-buscar-en-sitio>
                <div data-salida-gafete>
                    @include('componentes.lector', ['id' => 'salida_rapida_gafete', 'etiqueta' => 'O escanea el gafete que te devuelven', 'tipos' => 'gafete', 'nombre' => ''])
                </div>
                <div class="resultados-salida" data-resultados-salida aria-live="polite"></div>
            </div>
        @endif

        {{-- ===== Registro de ingreso ===== --}}
        <div data-vista-entrada>
            <form action="{{ route('accesos.store') }}" method="POST" autocomplete="off" data-form-acceso novalidate>
                @csrf
                <input type="hidden" name="_dialogo" value="ingreso">
                <input type="hidden" name="persona_id" value="{{ $previo('persona_id') }}" data-persona-id>
                <span hidden data-alta-sugerencias="{{ route('altas_por_verificar.parecidos') }}"></span>{{-- Altas por verificar: "¿Es alguno de estos?" (ADR-0006) --}}

                @if ($sig && ! $trasError)
                    <div class="alert alert-success small py-2 px-3" role="status"><i class="bi bi-check-circle-fill me-1" aria-hidden="true"></i>{{ $sig['mensaje'] ?? 'Ingreso registrado.' }} Captura el siguiente.</div>
                @endif
                @if ($trasError && $otrosErrores->isNotEmpty())
                    <div class="alert alert-danger small py-2 px-3" role="alert">
                        <i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>Revisa los datos:
                        <ul class="mb-0 ps-3">@foreach ($otrosErrores as $mensaje)<li>{{ $mensaje }}</li>@endforeach</ul>
                    </div>
                @endif

                {{-- Sede y tipo de persona --}}
                @if ($sedesAlta->count() === 1)
                    <input type="hidden" name="sede_id" value="{{ $sedesAlta->first()->id }}" data-acceso-sede>
                    <p class="linea-empresa mb-3"><i class="bi bi-geo-alt-fill me-1" aria-hidden="true"></i>Sede: <strong>{{ $sedesAlta->first()->nombre }}</strong></p>
                @else
                    <label class="campo-etiqueta" for="ingreso_sede">Sede</label>
                    <select id="ingreso_sede" name="sede_id" class="campo" required data-acceso-sede>
                        <option value="">-- Seleccionar --</option>
                        @foreach ($sedesAlta as $s)
                            <option value="{{ $s->id }}" @selected($sedeElegida === $s->id)>{{ $s->nombre }}</option>
                        @endforeach
                    </select>
                @endif

                <fieldset class="tipos-persona">
                    <legend class="campo-etiqueta">Tipo de Persona</legend>
                    <div class="opciones-tipo">
                        @foreach (Acceso::OPCIONES_TIPO as $clave => $texto)
                            <label class="opcion-tipo tipo-{{ $clave }}">
                                <input type="radio" name="tipo" value="{{ $clave }}" @checked($tipoElegido === $clave) @if ($clave === 'colaborador') data-por-defecto @endif data-acceso-tipo>
                                <i class="bi {{ Acceso::ICONOS_TIPO[$clave] }}" aria-hidden="true"></i>
                                <span>{{ mb_strtoupper($texto) }}</span>
                            </label>
                        @endforeach
                    </div>
                </fieldset>

                {{-- Gafete (personal externo, proveedor y contratista) --}}
                <div class="bloque-perfil" data-condicion data-solo-tipos="visitante proveedor contratista">
                    <div class="row">
                        <div class="col-md-5">
                            <label class="campo-etiqueta" for="ingreso_tipo_gafete">Tipo de Gafete</label>
                            <select id="ingreso_tipo_gafete" class="campo" data-filtro-tipo-gafete>
                                <option value="">-- Todos --</option>
                                @foreach ($tiposGafete as $tg)
                                    <option value="{{ $tg->id }}" data-nombre="{{ mb_strtolower($tg->nombre) }}">{{ $tg->nombre }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-7">
                        <div class="caja-gafete" data-sugerir="gafete">
                            @include('componentes.lector', ['id' => 'ingreso_gafete', 'etiqueta' => 'Gafete Asignado', 'tipos' => 'gafete', 'nombre' => 'gafete_id',
                                'valor' => $previo('gafete_id'), 'elegido' => $previos['gafete_id'] ?? null,
                                'ayuda' => 'Escanéalo con la pistola o la cámara, o escribe y elige de la lista. Sin gafete queda «S/G».'])
                            <div class="acceso-sugerencias" data-sugerencias hidden></div>
                        </div>
                        </div>
                    </div>
                </div>

                {{-- Colaborador que ingresa --}}
                <div class="bloque-perfil" data-condicion data-solo-tipos="colaborador">
                    <div data-sugerir="colaborador">
                        @include('componentes.lector', ['id' => 'ingreso_colaborador', 'etiqueta' => 'Colaborador que Ingresa', 'tipos' => 'colaborador', 'nombre' => 'colaborador_id',
                            'valor' => $previo('colaborador_id'), 'elegido' => $previos['colaborador_id'] ?? null,
                            'ayuda' => 'Escanea su gafete de empleado o escribe su nombre o número.'])
                        <div class="acceso-sugerencias" data-sugerencias hidden></div>
                    </div>
                    @if ($puede['provisional'])
                        <button type="button" class="btn-provisional" data-abrir-dialogo="dialogoRegistroRapidoColaborador" data-provisional-para="ingreso_colaborador">
                            <i class="bi bi-person-plus me-1" aria-hidden="true"></i>¿No aparece? Darlo de alta provisional
                        </button>
                    @endif
                </div>

                {{-- Nombre del titular --}}
                <div class="bloque-perfil" data-condicion data-solo-tipos="huesped visitante proveedor contratista emergencia">
                    <label class="campo-etiqueta" for="ingreso_nombre">
                        <span data-etiqueta-tipo="huesped">Nombre del Huésped</span>
                        <span data-etiqueta-tipo="visitante">Nombre de la Visita <span class="text-lowercase fw-normal">(buscar o nuevo)</span></span>
                        <span data-etiqueta-tipo="proveedor contratista">Nombre del Visitante / Conductor <span class="text-lowercase fw-normal">(buscar o nuevo)</span></span>
                        <span data-etiqueta-tipo="emergencia">Nombre / Unidad <span class="text-lowercase fw-normal">(opcional)</span></span>
                    </label>
                    <div class="buscador-acceso" data-sugerir-persona>
                        <input type="text" id="ingreso_nombre" name="nombre" class="campo text-uppercase" maxlength="150" value="{{ $previo('nombre') }}" placeholder="Nombre completo" data-nombre-titular>
                        <div class="acceso-sugerencias" data-sugerencias hidden></div>
                    </div>
                    @if ($repetida)
                        <div class="alert alert-danger caja-persona-repetida" role="alert" data-caja-persona-repetida>
                            <p class="mb-2"><i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>{{ $errors->first('persona_repetida') }}</p>
                            <div class="d-flex gap-2 flex-wrap">
                                <button type="submit" name="persona_decision" value="misma" class="btn-decision misma">Sí, es la misma</button>
                                <button type="submit" name="persona_decision" value="distinta" class="btn-decision distinta">No, es alguien distinto</button>
                            </div>
                        </div>
                    @endif
                    @if ($puede['persona'])
                        <div data-condicion data-solo-tipos="visitante proveedor contratista">
                            <button type="button" class="btn-provisional" data-abrir-dialogo="dialogoRegistroRapidoPersona">
                                <i class="bi bi-person-vcard me-1" aria-hidden="true"></i>Registrarla con su identificación en el Padrón de personas
                            </button>
                        </div>
                    @endif
                </div>

                {{-- Servicio de emergencia --}}
                <div class="bloque-perfil" data-condicion data-solo-tipos="emergencia">
                    <p class="text-muted small mb-2"><i class="bi bi-info-circle" aria-hidden="true"></i> Registro de fricción mínima — todo es opcional. En plena contingencia, lo importante es dejar constancia rápida; los datos se pueden completar después.</p>
                    <label class="campo-etiqueta" for="ingreso_tipo_emergencia">Tipo de Emergencia <span class="text-lowercase fw-normal">(opcional)</span></label>
                    <select id="ingreso_tipo_emergencia" name="tipo_emergencia" class="campo">
                        <option value="">-- Sin especificar --</option>
                        @foreach (Acceso::TIPOS_EMERGENCIA as $clave => $texto)
                            <option value="{{ $clave }}" @selected($previo('tipo_emergencia') === $clave)>{{ $texto }}</option>
                        @endforeach
                    </select>
                    <label class="campo-etiqueta" for="ingreso_observaciones">Observaciones <span class="text-lowercase fw-normal">(opcional)</span></label>
                    <textarea id="ingreso_observaciones" name="observaciones" class="campo" rows="2" maxlength="1000" placeholder="Detalle breve de la situación, si hay tiempo de anotarlo...">{{ $previo('observaciones') }}</textarea>
                </div>

                {{-- Personal externo: motivo y a quién visita --}}
                <div class="bloque-perfil" data-condicion data-solo-tipos="visitante">
                    <span class="campo-etiqueta">Motivo de la Visita</span>
                    <div class="modo-toggle">
                        <label class="modo-opt"><input type="radio" name="motivo_visita" value="rh" @checked($previo('motivo_visita', 'rh') === 'rh') data-por-defecto data-acceso-motivo><i class="bi bi-person-vcard" aria-hidden="true"></i><span>Recursos Humanos</span></label>
                        <label class="modo-opt"><input type="radio" name="motivo_visita" value="colaborador" @checked($previo('motivo_visita') === 'colaborador') data-acceso-motivo><i class="bi bi-people-fill" aria-hidden="true"></i><span>Visita a Colaborador</span></label>
                    </div>
                    <div data-condicion data-solo-motivo="colaborador" data-sugerir="colaborador">
                        @include('componentes.lector', ['id' => 'ingreso_visita', 'etiqueta' => '¿A quién visita?', 'tipos' => 'colaborador', 'nombre' => 'visita_colaborador_id',
                            'valor' => $previo('visita_colaborador_id'), 'elegido' => $previos['visita_colaborador_id'] ?? null])
                        <div class="acceso-sugerencias" data-sugerencias hidden></div>
                    </div>
                </div>

                {{-- Proveedor / contratista --}}
                <div class="bloque-perfil" data-condicion data-solo-tipos="proveedor contratista">
                    <label class="campo-etiqueta" for="ingreso_empresa">Empresa / Procedencia</label>
                    <div class="buscador-acceso" data-sugerir-proveedor="proveedor_id">
                        <input type="text" id="ingreso_empresa" name="empresa_procedencia" class="campo text-uppercase" maxlength="150" value="{{ $previo('empresa_procedencia') }}" placeholder="Buscar o nueva...">
                        <input type="hidden" name="proveedor_id" value="{{ $previo('proveedor_id') }}" data-proveedor-id>
                        <div class="acceso-sugerencias" data-sugerencias hidden></div>
                    </div>
                    <div data-sugerir="colaborador">
                        @include('componentes.lector', ['id' => 'ingreso_host', 'etiqueta' => 'Host — ¿Quién lo citó?', 'tipos' => 'colaborador', 'nombre' => 'host_colaborador_id',
                            'valor' => $previo('host_colaborador_id'), 'elegido' => $previos['host_colaborador_id'] ?? null, 'requerido' => true])
                        <div class="acceso-sugerencias" data-sugerencias hidden></div>
                    </div>
                    <details class="mas-detalles" @if ($previo('departamento_id') || $previo('area_trabajo') || $previo('actividad') || ($previo('tipo_visita', 'cortesia') !== 'cortesia')) open @endif>
                        <summary class="campo-etiqueta">Más detalles <span class="text-lowercase fw-normal">(opcional)</span></summary>
                        <span class="campo-etiqueta mt-2">Tipo de Visita</span>
                        <div class="modo-toggle tres">
                            @foreach (Acceso::TIPOS_VISITA as $clave => $texto)
                                <label class="modo-opt"><input type="radio" name="tipo_visita" value="{{ $clave }}" @checked($previo('tipo_visita', 'cortesia') === $clave) @if ($clave === 'cortesia') data-por-defecto @endif>
                                    <i class="bi {{ ['cortesia' => 'bi-cup-hot', 'levantamiento' => 'bi-clipboard-data', 'ejecucion' => 'bi-tools'][$clave] }}" aria-hidden="true"></i><span>{{ $texto }}</span></label>
                            @endforeach
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <label class="campo-etiqueta" for="ingreso_departamento">Departamento</label>
                                <select id="ingreso_departamento" name="departamento_id" class="campo" data-departamento-sede>
                                    <option value="">-- Seleccionar --</option>
                                    @foreach ($departamentos as $dep)
                                        <option value="{{ $dep->id }}" data-todas="{{ $dep->todas_las_sedes ? 1 : 0 }}" data-sedes="{{ $dep->sedes->pluck('id')->join(',') }}" @selected((string) $previo('departamento_id') === (string) $dep->id)>{{ $dep->nombre }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="campo-etiqueta" for="ingreso_area">Área de Trabajo Específica</label>
                                <input type="text" id="ingreso_area" name="area_trabajo" class="campo" maxlength="150" value="{{ $previo('area_trabajo') }}" placeholder="Ej: Cuarto de máquinas, Alberca...">
                            </div>
                        </div>
                        <label class="campo-etiqueta" for="ingreso_actividad">Actividad a Realizar</label>
                        <input type="text" id="ingreso_actividad" name="actividad" class="campo" maxlength="500" value="{{ $previo('actividad') }}" placeholder="Describe brevemente la actividad">
                    </details>
                    <p class="nota-pendiente"><i class="bi bi-info-circle" aria-hidden="true"></i> El registro quedará <strong>Pendiente</strong> — el acceso físico no se autoriza hasta confirmar en el sistema que el Host lo aprobó.</p>
                </div>

                {{-- ID custodiada (personal externo, proveedor y contratista) --}}
                <div class="bloque-perfil" data-condicion data-solo-tipos="visitante proveedor contratista">
                    <label class="campo-etiqueta" for="ingreso_identificacion">ID Custodiada</label>
                    <select id="ingreso_identificacion" name="identificacion" class="campo">
                        @foreach (Acceso::IDENTIFICACIONES as $clave => $texto)
                            <option value="{{ $clave }}" @selected($previo('identificacion', 'ine') === $clave) @if ($clave === 'ine') data-por-defecto @endif>{{ $texto }}</option>
                        @endforeach
                    </select>
                </div>

                {{-- Forma de llegada --}}
                <div class="bloque-perfil" data-condicion data-solo-tipos="colaborador huesped visitante proveedor contratista">
                    <span class="campo-etiqueta"><span data-etiqueta-tipo="huesped">¿Cómo llegó?</span><span data-etiqueta-tipo="colaborador visitante proveedor contratista">Forma de Llegada</span></span>
                    <div class="modo-toggle">
                        <label class="modo-opt"><input type="radio" name="modo_arribo" value="a_pie" @checked($modoElegido === 'a_pie') data-por-defecto data-acceso-modo><i class="bi bi-person-walking" aria-hidden="true"></i><span>A Pie</span></label>
                        <label class="modo-opt"><input type="radio" name="modo_arribo" value="auto" @checked($modoElegido === 'auto') data-acceso-modo><i class="bi bi-car-front-fill" aria-hidden="true"></i>
                            <span data-etiqueta-tipo="colaborador">Vehículo Propio / Moto</span><span data-etiqueta-tipo="huesped">En Vehículo</span><span data-etiqueta-tipo="visitante proveedor contratista">Vehículo (Auto / Moto)</span></label>
                    </div>
                </div>

                {{-- Vehículo --}}
                <div class="bloque-perfil bloque-vehiculo" data-condicion data-solo-vehiculo>
                    <div data-sugerir="vehiculo" data-placas-de="ingreso_placas_valor">
                        @include('componentes.lector', ['id' => 'ingreso_placas', 'etiqueta' => 'Placas', 'tipos' => 'vehiculo', 'nombre' => 'vehiculo_id',
                            'valor' => $previo('vehiculo_id'), 'elegido' => $previos['vehiculo_id'] ?? null,
                            'ayuda' => 'Escribe las placas o escanea la calcomanía. Si es un vehículo nuevo, llena marca y color.'])
                        <input type="hidden" id="ingreso_placas_valor" name="placas" value="{{ $previo('placas') }}" data-placas>
                        <div class="acceso-sugerencias" data-sugerencias hidden></div>
                    </div>
                    <div class="row">
                        <div class="col-6 col-md-3">
                            <label class="campo-etiqueta" for="ingreso_tipo_vehiculo">Tipo</label>
                            <select id="ingreso_tipo_vehiculo" name="tipo_vehiculo" class="campo" data-vehiculo-campo="tipo">
                                @foreach (\App\Models\Vehiculo::TIPOS as $clave => $texto)
                                    <option value="{{ $clave }}" @selected($previo('tipo_vehiculo', 'sedan') === $clave) @if ($clave === 'sedan') data-por-defecto @endif>{{ $texto }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="campo-etiqueta" for="ingreso_marca">Marca</label>
                            <input type="text" id="ingreso_marca" name="marca" class="campo text-uppercase" maxlength="50" value="{{ $previo('marca') }}" data-vehiculo-campo="marca">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="campo-etiqueta" for="ingreso_modelo">Modelo</label>
                            <input type="text" id="ingreso_modelo" name="modelo" class="campo text-uppercase" maxlength="60" value="{{ $previo('modelo') }}" data-vehiculo-campo="modelo">
                        </div>
                        <div class="col-6 col-md-3">
                            <label class="campo-etiqueta" for="ingreso_color">Color</label>
                            <input type="text" id="ingreso_color" name="color" class="campo text-uppercase" maxlength="30" value="{{ $previo('color') }}" data-vehiculo-campo="color">
                        </div>
                    </div>
                    <label class="campo-etiqueta" for="ingreso_zona">Enviar a <span class="text-lowercase fw-normal">(opcional)</span></label>
                    <select id="ingreso_zona" name="zona_estacionamiento_id" class="campo" data-zona-sede>
                        <option value="">-- Sin asignar --</option>
                        @foreach ($zonas as $z)
                            <option value="{{ $z['id'] }}" data-sede="{{ $z['sede_id'] }}" @selected((string) $previo('zona_estacionamiento_id') === (string) $z['id'])>{{ $z['texto'] }}</option>
                        @endforeach
                    </select>
                    <div data-condicion data-solo-tipos="huesped">
                        <label class="campo-etiqueta" for="ingreso_conductor">Conductor <span class="text-lowercase fw-normal">(opcional — solo si NO maneja el propio huésped, ej. taxi/Uber)</span></label>
                        <input type="text" id="ingreso_conductor" name="conductor" class="campo text-uppercase" maxlength="150" value="{{ $previo('conductor') }}" placeholder="Nombre de quien maneja la unidad">
                    </div>
                </div>

                {{-- Acompañantes --}}
                <div class="bloque-perfil">
                    <label class="campo-etiqueta" for="ingreso_num_acompanantes">Acompañantes <span class="text-lowercase fw-normal">(cualquier persona que llegue con él; cada uno puede llevar su gafete)</span></label>
                    <div class="contador-acompanantes">
                        <button type="button" class="btn-contador" data-acompanantes-menos aria-label="Un acompañante menos"><i class="bi bi-dash-lg" aria-hidden="true"></i></button>
                        <input type="number" id="ingreso_num_acompanantes" name="num_acompanantes" class="campo" min="0" max="{{ \App\Services\Accesos\RegistroAccesos::MAX_ACOMPANANTES }}" inputmode="numeric"
                               value="{{ $previo('num_acompanantes', 0) ?: 0 }}" data-num-acompanantes>
                        <button type="button" class="btn-contador" data-acompanantes-mas aria-label="Un acompañante más"><i class="bi bi-plus-lg" aria-hidden="true"></i></button>
                    </div>
                    <div class="filas-acompanantes" data-filas-acompanantes>
                        @foreach ($acompanantesPrevios as $i => $fila)
                            @include('seguridad.accesos._acompanante', ['i' => $i, 'fila' => $fila])
                        @endforeach
                    </div>
                    <template data-plantilla-acompanante>
                        @include('seguridad.accesos._acompanante', ['i' => '__i__', 'fila' => []])
                    </template>
                </div>

                {{-- Huésped: datos de recepción (opcionales) --}}
                <div class="bloque-perfil" data-condicion data-solo-tipos="huesped">
                    <details class="mas-detalles" @if ($previo('habitacion') || $previo('numero_reserva') || $previo('empresa_procedencia') || $reservaElegida === '0') open @endif>
                        <summary class="campo-etiqueta">Más detalles <span class="text-lowercase fw-normal">(opcional)</span></summary>
                        <label class="campo-etiqueta mt-2" for="ingreso_habitacion">Número de Habitación</label>
                        <input type="text" id="ingreso_habitacion" name="habitacion" class="campo text-uppercase campo-corto" maxlength="20" value="{{ $previo('habitacion') }}" placeholder="Ej: 204">
                        <span class="campo-etiqueta">¿Tiene Reserva?</span>
                        <div class="modo-toggle">
                            <label class="modo-opt"><input type="radio" name="tiene_reserva" value="1" @checked($reservaElegida === '1') data-por-defecto data-acceso-reserva><i class="bi bi-journal-check" aria-hidden="true"></i><span>Sí</span></label>
                            <label class="modo-opt"><input type="radio" name="tiene_reserva" value="0" @checked($reservaElegida === '0') data-acceso-reserva><i class="bi bi-journal-x" aria-hidden="true"></i><span>No</span></label>
                        </div>
                        <div class="row">
                            <div class="col-md-6" data-condicion data-solo-reserva="1">
                                <label class="campo-etiqueta" for="ingreso_reserva">Número de Reserva</label>
                                <input type="text" id="ingreso_reserva" name="numero_reserva" class="campo text-uppercase" maxlength="50" value="{{ $previo('numero_reserva') }}" placeholder="Folio de reserva">
                            </div>
                            <div class="col-md-6" data-condicion data-solo-reserva="1">
                                <span class="campo-etiqueta">Tipo de Pase</span>
                                <p class="pase-fijo"><i class="bi bi-lock-fill me-1" aria-hidden="true"></i>Estancia <span class="text-muted small">(con reserva siempre es Estancia)</span></p>
                            </div>
                            <div class="col-md-6" data-condicion data-solo-reserva="0">
                                <label class="campo-etiqueta" for="ingreso_pase">Tipo de Pase</label>
                                <select id="ingreso_pase" name="tipo_pase" class="campo">
                                    @foreach (['daypass', 'nightpass', 'estancia'] as $clave)
                                        <option value="{{ $clave }}" @selected($previo('tipo_pase', 'daypass') === $clave) @if ($clave === 'daypass') data-por-defecto @endif>{{ Acceso::PASES[$clave] }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <label class="campo-etiqueta" for="ingreso_agencia">Agencia Vinculada</label>
                        <div class="buscador-acceso" data-sugerir-proveedor="agencia_id">
                            <input type="text" id="ingreso_agencia" name="empresa_procedencia" class="campo text-uppercase" maxlength="150" value="{{ $previo('empresa_procedencia') }}" placeholder="Buscar o nueva agencia de viajes...">
                            <input type="hidden" name="agencia_id" value="{{ $previo('agencia_id') }}" data-proveedor-id>
                            <div class="acceso-sugerencias" data-sugerencias hidden></div>
                        </div>
                    </details>
                </div>

                <div class="acciones-ingreso">
                    <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                    <button type="submit" class="btn-guardar-ingreso" name="siguiente" value="0"><i class="bi bi-shield-check me-2" aria-hidden="true"></i>Autorizar Ingreso y Guardar Datos</button>
                    <button type="submit" class="btn-siguiente-ingreso" name="siguiente" value="1"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Guardar y capturar siguiente</button>
                </div>
            </form>
        </div>
    </div>
</dialog>
