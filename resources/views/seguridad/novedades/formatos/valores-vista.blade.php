{{-- Valores a la Vista (SEGCAT: HABITACION, frag_habitacion.php) --}}
@php
    $sel = fn ($actual, $valor) => (string) $actual === (string) $valor ? 'selected' : '';
    $personas = array_values((array) $v['hab_personas']);
    $aperturas = array_values((array) $v['hab_aperturas']);
    $zonasValores = array_values((array) $v['hab_valores']);
    $edificioTicket = $n->area ? ($n->area->nivel === \App\Models\Espacio::AREA ? $n->area->padre?->nombre : $n->area->nombre) : null;
    $zonaTicket = $n->area?->nivel === \App\Models\Espacio::AREA ? $n->area->nombre : null;
@endphp
<div class="accordion-item" id="moduloHabitacion">
    <h2 class="accordion-header"><a class="accordion-button" role="button" data-bs-toggle="collapse" href="#colHabitacion" aria-expanded="true"><i class="bi bi-door-open-fill me-2 text-warning" aria-hidden="true"></i>Detalle: Valores a la Vista</a></h2>
    <div id="colHabitacion" class="accordion-collapse collapse show">
        <div class="accordion-body">
            <x-seccion clave="nov-valores-vista-1" :abierta="true">
                <x-slot:titulo><i class="bi bi-info-square text-primary me-2" aria-hidden="true"></i>1. Identificación</x-slot:titulo>
            <div class="row bg-light border p-2 rounded mb-3 mx-0">
                <div class="col-md-4"><label class="campo-etiqueta" for="hab_edificio">Edificio <span class="text-lowercase fw-normal">(del reporte inicial)</span></label><input type="text" id="hab_edificio" class="campo bg-white" readonly value="{{ $edificioTicket }}" data-nov-texto-edificio></div>
                <div class="col-md-4"><label class="campo-etiqueta" for="hab_zona">Zona / Piso <span class="text-lowercase fw-normal">(del reporte inicial)</span></label><input type="text" id="hab_zona" class="campo bg-white" readonly value="{{ $zonaTicket }}" data-nov-texto-piso></div>
                <div class="col-md-4">
                    <label class="campo-etiqueta" for="hab_id_area_especifica">Número de Habitación</label>
                    <select id="hab_id_area_especifica" name="hab_id_area_especifica" class="campo" data-nov-habitaciones data-vacio="-- Elige edificio/piso en el ticket --">
                        <option value="">-- Elige edificio/piso en el ticket --</option>
                        @foreach ($habitaciones as $h)
                            <option value="{{ $h->id }}" data-ruta="{{ $h->ruta }}" {{ $sel($v['hab_id_area_especifica'], $h->id) }}>{{ $h->nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-12 mt-1"><label class="campo-etiqueta" for="hab_numero">Habitación (si no está en el catálogo) <span class="text-lowercase fw-normal">(opcional)</span></label><input type="text" id="hab_numero" name="hab_numero" class="campo text-uppercase" maxlength="30" placeholder="Ej. 204-B, suite anexa..." value="{{ $v['hab_numero'] }}"></div>
            </div>

            </x-seccion>
            <x-seccion clave="nov-valores-vista-2" :abierta="false">
                <x-slot:titulo><i class="bi bi-people-fill text-success me-2" aria-hidden="true"></i>2. Personal Involucrado</x-slot:titulo>
            <div class="row mb-2" data-nov-autocompletar>
                <div class="col-md-4"><label class="campo-etiqueta text-primary" for="hab_quien_reporta">Quién Reporta <span class="text-lowercase fw-normal">(del reporte inicial)</span></label><input type="text" id="hab_quien_reporta" name="hab_quien_reporta" class="campo text-uppercase" maxlength="150" list="novColaboradores" placeholder="Buscar colaborador..." value="{{ $v['hab_quien_reporta'] }}" data-nov-nombre></div>
                <div class="col-md-4"><label class="campo-etiqueta" for="hab_depto_reporta">Departamento (Reporta)</label><input type="text" id="hab_depto_reporta" name="hab_depto_reporta" class="campo bg-white text-uppercase" maxlength="100" placeholder="Autocompleta o escribe..." value="{{ $v['hab_depto_reporta'] }}" data-nov-depto></div>
                <div class="col-md-4"><label class="campo-etiqueta" for="hab_puesto_reporta">Puesto (Reporta)</label><input type="text" id="hab_puesto_reporta" name="hab_puesto_reporta" class="campo bg-white text-uppercase" maxlength="100" placeholder="Autocompleta o escribe..." value="{{ $v['hab_puesto_reporta'] }}" data-nov-puesto></div>
            </div>
            <label class="campo-etiqueta" for="hab_actividad_reporta">Actividad que realiza(ba) dentro de la habitación</label>
            <input type="text" id="hab_actividad_reporta" name="hab_actividad_reporta" class="campo" maxlength="255" placeholder="Ej. Limpieza de rutina, Mantenimiento preventivo, Reposición de minibar..." value="{{ $v['hab_actividad_reporta'] }}">
            <div class="row mb-3 bg-light p-2 rounded border mx-0">
                <div class="col-md-4"><label class="campo-etiqueta text-success" for="hab_quien_atiende">Nombre del Agente <span class="text-lowercase fw-normal">(quien atiende)</span></label><input type="text" id="hab_quien_atiende" name="hab_quien_atiende" class="campo fw-bold border-success text-uppercase" maxlength="150" value="{{ $v['hab_quien_atiende'] }}"></div>
                <div class="col-md-4"><label class="campo-etiqueta" for="hab_depto_atiende">Depto (Atiende)</label><input type="text" id="hab_depto_atiende" name="hab_depto_atiende" class="campo bg-white text-uppercase" maxlength="100" value="{{ $v['hab_depto_atiende'] }}"></div>
                <div class="col-md-4"><label class="campo-etiqueta" for="hab_puesto_atiende">Puesto (Atiende)</label><input type="text" id="hab_puesto_atiende" name="hab_puesto_atiende" class="campo bg-white text-uppercase" maxlength="100" value="{{ $v['hab_puesto_atiende'] }}"></div>
            </div>
            <span class="campo-etiqueta text-dark">¿Hay más personas en la habitación? <span class="text-lowercase fw-normal">(agrega tantas como haga falta)</span></span>
            <div data-filas="hab_personas" data-siguiente="{{ count($personas) }}">
                @foreach ($personas as $i => $p)
                    @include('seguridad.novedades.formatos._fila-persona-habitacion', ['i' => $i, 'p' => $p])
                @endforeach
            </div>
            <template data-plantilla="hab_personas">@include('seguridad.novedades.formatos._fila-persona-habitacion', ['i' => '__i__', 'p' => ['se_retira' => true]])</template>
            <button type="button" class="btn-agregar-fila mb-3" data-agregar-fila="hab_personas"><i class="bi bi-person-plus-fill me-1" aria-hidden="true"></i>Añadir Persona</button>

            </x-seccion>
            <x-seccion clave="nov-valores-vista-3" :abierta="false">
                <x-slot:titulo><i class="bi bi-door-closed text-warning me-2" aria-hidden="true"></i>3. Puertas, Ventanas y Terrazas</x-slot:titulo>
            <p class="text-muted small mb-1">Agrega cada una que aplique — marca si es de conexión (puerta) o de baño (ventana/terraza).</p>
            <div data-filas="hab_aperturas" data-siguiente="{{ count($aperturas) }}">
                @foreach ($aperturas as $i => $a)
                    @include('seguridad.novedades.formatos._fila-apertura', ['i' => $i, 'a' => $a])
                @endforeach
            </div>
            @foreach (\App\Services\Novedades\Formatos\ValoresVista::TIPOS_APERTURA as $tipo => $texto)
                <template data-plantilla="hab_aperturas_{{ $tipo }}">@include('seguridad.novedades.formatos._fila-apertura', ['i' => '__i__', 'a' => ['tipo' => $tipo, 'estado' => 'cerrada']])</template>
            @endforeach
            <div class="d-flex flex-wrap gap-2 mb-3">
                <button type="button" class="btn-agregar-fila" data-agregar-fila="hab_aperturas" data-plantilla-de="hab_aperturas_puerta"><i class="bi bi-door-closed me-1" aria-hidden="true"></i>Añadir Puerta</button>
                <button type="button" class="btn-agregar-fila" data-agregar-fila="hab_aperturas" data-plantilla-de="hab_aperturas_ventana"><i class="bi bi-window me-1" aria-hidden="true"></i>Añadir Ventana</button>
                <button type="button" class="btn-agregar-fila" data-agregar-fila="hab_aperturas" data-plantilla-de="hab_aperturas_terraza"><i class="bi bi-sun me-1" aria-hidden="true"></i>Añadir Terraza</button>
            </div>

            </x-seccion>
            <x-seccion clave="nov-valores-vista-4" :abierta="false">
                <x-slot:titulo><i class="bi bi-search text-warning me-2" aria-hidden="true"></i>4. Caja Fuerte y Valores</x-slot:titulo>
            <label class="campo-etiqueta text-danger" for="hab_caja_estado">Estado de la Caja Fuerte</label>
            <select id="hab_caja_estado" name="hab_caja_estado" class="campo border-danger fw-bold">
                @foreach (\App\Models\ValoresVistaDetalle::CAJA_FUERTE as $clave => $texto)
                    <option value="{{ $clave }}" {{ $sel($v['hab_caja_estado'], $clave) }}>{{ $texto }}</option>
                @endforeach
            </select>
            <div class="row mb-2" id="divCajaFuerteValores" data-nov-mostrar-si='{"hab_caja_estado":["abierta_con_valores"]}' @if ($v['hab_caja_estado'] !== 'abierta_con_valores') hidden @endif>
                <div class="col-md-12">
                    <div class="p-3 bg-warning-subtle border border-warning rounded">
                        <label class="campo-etiqueta text-dark" for="hab_caja_accion">Si la caja tenía valores y estaba abierta, ¿Cómo se dejó después de la inspección?</label>
                        <select id="hab_caja_accion" name="hab_caja_accion" class="campo border-warning mb-0 fw-bold">
                            <option value="">-- Seleccionar Acción Aplicada --</option>
                            @foreach (\App\Models\ValoresVistaDetalle::CAJA_ACCION as $clave => $texto)
                                <option value="{{ $clave }}" {{ $sel($v['hab_caja_accion'], $clave) }}>{{ $texto }}</option>
                            @endforeach
                        </select>
                        <label class="campo-etiqueta text-dark mt-2" for="hab_valores_dentro">Valores encontrados dentro de la caja fuerte (Descripción)</label>
                        <textarea id="hab_valores_dentro" name="hab_valores_dentro" class="campo border-warning mb-0" rows="2" maxlength="5000" placeholder="Describa de forma clara: dinero en efectivo, joyas, documentos u otros valores encontrados dentro de la caja...">{{ $v['hab_valores_dentro'] }}</textarea>
                    </div>
                </div>
            </div>

            <span class="campo-etiqueta mt-3">Valores a la vista, por zona <span class="text-lowercase fw-normal">(agrega tantas zonas como haga falta)</span></span>
            <div data-filas="hab_valores" data-siguiente="{{ count($zonasValores) }}">
                @foreach ($zonasValores as $i => $zv)
                    @include('seguridad.novedades.formatos._fila-valor-zona', ['i' => $i, 'zv' => $zv])
                @endforeach
            </div>
            <div class="d-flex gap-2 flex-wrap mb-2">
                @foreach (\App\Services\Novedades\Formatos\ValoresVista::ZONAS as $zona)
                    <template data-plantilla="hab_valores_{{ \Illuminate\Support\Str::slug($zona) }}">@include('seguridad.novedades.formatos._fila-valor-zona', ['i' => '__i__', 'zv' => ['zona' => $zona]])</template>
                    <button type="button" class="btn-agregar-fila ambar" data-agregar-fila="hab_valores" data-plantilla-de="hab_valores_{{ \Illuminate\Support\Str::slug($zona) }}">+ {{ $zona }}</button>
                @endforeach
            </div>

            <div class="row mt-2">
                <div class="col-md-4"><label class="campo-etiqueta" for="hab_personal_retiro">¿El personal se retiró al terminar?</label><select id="hab_personal_retiro" name="hab_personal_retiro" class="campo"><option value="si" {{ $sel($v['hab_personal_retiro'], 'si') }}>Sí, se retiraron al terminar</option><option value="no" {{ $sel($v['hab_personal_retiro'], 'no') }}>No, continuaron trabajando adentro</option></select></div>
                <div class="col-md-4"><label class="campo-etiqueta" for="hab_cliente_llega">¿Cliente llega durante los valores?</label><select id="hab_cliente_llega" name="hab_cliente_llega" class="campo"><option value="0" {{ $sel($v['hab_cliente_llega'], '0') }}>No</option><option value="1" {{ $sel($v['hab_cliente_llega'], '1') }}>Sí</option></select></div>
                <div class="col-md-4">
                    <label class="campo-etiqueta" for="hab_tomo_fotos">¿Se tomaron fotografías?</label>
                    <select id="hab_tomo_fotos" name="hab_tomo_fotos" class="campo"><option value="si" {{ $sel($v['hab_tomo_fotos'], 'si') }}>Sí (Anexadas en bitácora fotográfica)</option><option value="no" {{ $sel($v['hab_tomo_fotos'], 'no') }}>No</option></select>
                </div>
            </div>
            <label class="campo-etiqueta mt-2" for="hab_observaciones_grales">Condiciones generales, observaciones y notificaciones</label>
            <textarea id="hab_observaciones_grales" name="hab_observaciones_grales" class="campo" rows="2" maxlength="5000" placeholder="Ej. Se notificó al Gerente en Turno. Habitación en orden general, sin señales de forzadura...">{{ $v['hab_observaciones_grales'] }}</textarea>
            </x-seccion>
        </div>
    </div>
</div>
