{{--
    Accidente / Lesión (SEGCAT: frag_accidente.php). POR INDICACIÓN DEL
    RESPONSABLE DEL PROYECTO ESTE FORMULARIO NO SE CAMBIA: mismas secciones,
    campos, etiquetas, opciones y orden. Solo correcciones técnicas: sin
    JavaScript en línea, colaborador con el lector universal, firmas con
    componentes.firma (disco privado) y validación en el servidor.
--}}
@php
    $tipoAfectado = $v['acc_tipo_afectado'];
    $zonasMarcadas = array_filter(array_map('trim', explode(',', (string) $v['m_parte'])));
    $z = fn (string $zona) => in_array($zona, $zonasMarcadas, true) ? ' selected' : '';
    $sel = fn ($actual, $valor) => (string) $actual === (string) $valor ? 'selected' : '';
    $testigos = array_values((array) $v['acc_testigos']);
    $firmasGuardadas = $n->firmas->keyBy('rol');
@endphp
<div class="accordion-item" id="moduloSeguridadAccidente">
    <h2 class="accordion-header"><a class="accordion-button" role="button" data-bs-toggle="collapse" href="#colSeguridad" aria-expanded="true"><i class="bi bi-shield-lock-fill me-2 text-primary" aria-hidden="true"></i>1. Seguridad: Formato de Afectado</a></h2>
    <div id="colSeguridad" class="accordion-collapse collapse show" data-bs-parent="#accordionExpediente">
        <div class="accordion-body">
            <label class="campo-etiqueta" for="acc_tipo_afectado">Tipo de Afectado</label>
            <select id="acc_tipo_afectado" name="acc_tipo_afectado" class="campo fw-bold border-primary">
                <option value="">-- Seleccionar formato a desplegar --</option>
                <option value="HUESPED" {{ $sel($tipoAfectado, 'HUESPED') }}>Huésped / Cliente</option>
                <option value="COLABORADOR" {{ $sel($tipoAfectado, 'COLABORADOR') }}>Colaborador Interno</option>
            </select>

            <div id="divCamposHuesped" class="p-3 bg-light border rounded mt-2 caja-formato" data-nov-mostrar-si='{"acc_tipo_afectado":["HUESPED"]}' @if ($tipoAfectado !== 'HUESPED') hidden @endif>
                <h6 class="fw-bold text-dark border-bottom pb-2">Guest Injury Report</h6>
                <div class="row bg-white p-2 border rounded mb-3 mx-0">
                    <div class="col-md-6"><label class="campo-etiqueta text-primary" for="h_fecha_accidente">Fecha del Accidente</label><input type="date" id="h_fecha_accidente" name="h_fecha_accidente" class="campo" value="{{ $v['h_fecha_accidente'] }}"></div>
                    <div class="col-md-6"><label class="campo-etiqueta text-primary" for="h_hora_accidente">Hora del Accidente</label><input type="time" id="h_hora_accidente" name="h_hora_accidente" class="campo" value="{{ $v['h_hora_accidente'] }}"></div>
                </div>
                <div class="row">
                    <div class="col-md-6"><label class="campo-etiqueta" for="h_nombre">Nombre del Huésped</label><input type="text" id="h_nombre" name="h_nombre" class="campo text-uppercase" maxlength="150" value="{{ $v['h_nombre'] }}"></div>
                    <div class="col-md-3"><label class="campo-etiqueta" for="h_hab">No. Habitación</label><input type="text" id="h_hab" name="h_hab" class="campo" maxlength="20" value="{{ $v['h_hab'] }}"></div>
                    <div class="col-md-3"><label class="campo-etiqueta" for="h_agencia">Agencia</label><input type="text" id="h_agencia" name="h_agencia" class="campo text-uppercase" maxlength="150" value="{{ $v['h_agencia'] }}"></div>
                </div>
                <div class="row">
                    <div class="col-md-3"><label class="campo-etiqueta" for="h_checkin">Check-in</label><input type="date" id="h_checkin" name="h_checkin" class="campo" value="{{ $v['h_checkin'] }}"></div>
                    <div class="col-md-3"><label class="campo-etiqueta" for="h_checkout">Check-out</label><input type="date" id="h_checkout" name="h_checkout" class="campo" value="{{ $v['h_checkout'] }}"></div>
                    <div class="col-md-3"><label class="campo-etiqueta" for="h_pais">País</label><input type="text" id="h_pais" name="h_pais" class="campo text-uppercase" maxlength="100" value="{{ $v['h_pais'] }}"></div>
                    <div class="col-md-3"><label class="campo-etiqueta" for="h_sexo">Sexo/Edad</label>
                        <div class="d-flex gap-1"><select id="h_sexo" name="h_sexo" class="campo px-1" aria-label="Sexo"><option value="M" {{ $sel($v['h_sexo'], 'M') }}>M</option><option value="F" {{ $sel($v['h_sexo'], 'F') }}>F</option></select><input type="number" name="h_edad" class="campo px-1" min="0" max="120" placeholder="Edad" aria-label="Edad" value="{{ $v['h_edad'] }}"></div>
                    </div>
                </div>
                <div class="row mt-2">
                    <div class="col-md-12"><label class="campo-etiqueta" for="h_lugar">Lugar o área del accidente</label><input type="text" id="h_lugar" name="h_lugar" class="campo text-uppercase" maxlength="255" value="{{ $v['h_lugar'] }}"></div>
                </div>
                <label class="campo-etiqueta mt-2" for="h_explicacion">Explique cómo sucedió (Qué, cómo, objeto o sustancia)</label>
                <textarea id="h_explicacion" name="h_explicacion" class="campo" rows="2" maxlength="5000">{{ $v['h_explicacion'] }}</textarea>
                <div class="row mt-2">
                    <div class="col-md-4"><label class="campo-etiqueta" for="h_req_medico">¿Asistencia médica?</label><select id="h_req_medico" name="h_req_medico" class="campo"><option value="1" {{ $sel($v['h_req_medico'], '1') }}>Sí</option><option value="0" {{ $sel($v['h_req_medico'], '0') }}>No</option></select></div>
                    <div class="col-md-4"><label class="campo-etiqueta" for="h_motivo">¿Por qué?</label><input type="text" id="h_motivo" name="h_motivo" class="campo" maxlength="255" value="{{ $v['h_motivo'] }}"></div>
                    <div class="col-md-4">
                        <label class="campo-etiqueta" for="h_testigos">¿Hubo testigos?</label>
                        <select id="h_testigos" name="h_testigos" class="campo"><option value="0" {{ $sel($v['h_testigos'], '0') }}>No</option><option value="1" {{ $sel($v['h_testigos'], '1') }}>Sí</option></select>
                    </div>
                </div>
                <div class="mt-2" id="divTestigosHuesped" data-nov-mostrar-si='{"h_testigos":["1"]}' @if ((string) $v['h_testigos'] !== '1') hidden @endif><label class="campo-etiqueta" for="h_detalles_testigos">Nombres/Datos de los Testigos</label><input type="text" id="h_detalles_testigos" name="h_detalles_testigos" class="campo text-uppercase" maxlength="255" placeholder="Nombre completo, habitación, etc." value="{{ $v['h_detalles_testigos'] }}"></div>
            </div>

            <div id="divCamposColaborador" class="p-3 bg-light border rounded mt-2 caja-formato" data-nov-mostrar-si='{"acc_tipo_afectado":["COLABORADOR"]}' @if ($tipoAfectado !== 'COLABORADOR') hidden @endif>
                <h6 class="fw-bold text-dark border-bottom pb-2">Reporte Accidente de Colaborador</h6>
                <div class="row bg-white p-2 border rounded mb-3 mx-0">
                    <div class="col-md-6"><label class="campo-etiqueta text-primary" for="c_fecha_accidente">Fecha del Accidente</label><input type="date" id="c_fecha_accidente" name="c_fecha_accidente" class="campo" value="{{ $v['c_fecha_accidente'] }}"></div>
                    <div class="col-md-6"><label class="campo-etiqueta text-primary" for="c_hora_accidente">Hora del Accidente</label><input type="time" id="c_hora_accidente" name="c_hora_accidente" class="campo" value="{{ $v['c_hora_accidente'] }}"></div>
                </div>
                @include('componentes.lector', ['id' => 'c_colaborador_lector', 'etiqueta' => 'Buscar Colaborador Afectado', 'tipos' => 'colaborador',
                    'nombre' => 'c_id_colaborador', 'valor' => $v['c_id_colaborador'], 'elegido' => $v['c_colaborador_texto'] ?? null,
                    'ayuda' => 'Escanea su gafete o escribe su número de empleado.'])
                <div class="row mt-2" data-nov-colaborador-datos>
                    <div class="col-md-3"><label class="campo-etiqueta" for="c_depto_fill">Departamento</label><input type="text" id="c_depto_fill" name="c_depto_colaborador" class="campo bg-white" readonly value="{{ $v['c_depto_colaborador'] }}" data-nov-depto></div>
                    <div class="col-md-3"><label class="campo-etiqueta" for="c_puesto_fill">Puesto</label><input type="text" id="c_puesto_fill" name="c_puesto_colaborador" class="campo bg-white" readonly value="{{ $v['c_puesto_colaborador'] }}" data-nov-puesto></div>
                    <div class="col-md-3"><label class="campo-etiqueta" for="c_turno_colaborador">Turno</label><input type="text" id="c_turno_colaborador" name="c_turno_colaborador" class="campo text-uppercase" maxlength="50" placeholder="Ej. Matutino" value="{{ $v['c_turno_colaborador'] }}"></div>
                    <div class="col-md-3"><label class="campo-etiqueta" for="c_area_trabajo">Área de Trabajo</label><input type="text" id="c_area_trabajo" name="c_area_trabajo" class="campo text-uppercase" maxlength="150" placeholder="Ej. Cocina Caliente" value="{{ $v['c_area_trabajo'] }}"></div>
                </div>
                <div class="row mt-2 border-top pt-2">
                    <div class="col-md-6"><label class="campo-etiqueta" for="c_jefe">Nombre del jefe inmediato <span class="text-lowercase fw-normal">(buscar o escribir)</span></label><input type="text" id="c_jefe" name="c_jefe" class="campo text-uppercase" maxlength="150" list="novColaboradores" value="{{ $v['c_jefe'] }}"></div>
                    <div class="col-md-6"><label class="campo-etiqueta" for="c_puesto_jefe">Puesto del jefe</label><input type="text" id="c_puesto_jefe" name="c_puesto_jefe" class="campo text-uppercase" maxlength="150" value="{{ $v['c_puesto_jefe'] }}"></div>
                </div>
                <div class="row mt-2">
                    <div class="col-md-3"><label class="campo-etiqueta" for="c_primera_vez">1ra vez accidentado</label><select id="c_primera_vez" name="c_primera_vez" class="campo"><option value="1" {{ $sel($v['c_primera_vez'], '1') }}>Sí</option><option value="0" {{ $sel($v['c_primera_vez'], '0') }}>No</option></select></div>
                    <div class="col-md-9">
                        <span class="campo-etiqueta">Causas (Marcar aplicables):</span>
                        <div class="d-flex flex-wrap gap-3 mt-1 casillas-formato">
                            <label><input type="checkbox" name="c_causa_terceros" value="1" @checked($v['c_causa_terceros'])> Terceras personas</label>
                            <label><input type="checkbox" name="c_causa_acto" value="1" @checked($v['c_causa_acto'])> Acto inseguro</label>
                            <label><input type="checkbox" name="c_causa_condicion" value="1" @checked($v['c_causa_condicion'])> Condición insegura</label>
                        </div>
                    </div>
                </div>
                <label class="campo-etiqueta mt-2" for="c_explicacion">Explique cualquiera de los anteriores o ambos</label><textarea id="c_explicacion" name="c_explicacion" class="campo" rows="2" maxlength="5000">{{ $v['c_explicacion'] }}</textarea>
                <span class="campo-etiqueta mt-2">Testigos <span class="text-lowercase fw-normal">(buscar o escribir libre — útil si el accidentado es personal de una empresa externa)</span></span>
                <div data-filas="acc_testigos" data-siguiente="{{ count($testigos) }}">
                    @foreach ($testigos as $i => $t)
                        @include('seguridad.novedades.formatos._fila-testigo-accidente', ['i' => $i, 't' => $t])
                    @endforeach
                </div>
                <template data-plantilla="acc_testigos">@include('seguridad.novedades.formatos._fila-testigo-accidente', ['i' => '__i__', 't' => []])</template>
                <button type="button" class="btn-ver-detalle mb-2" data-agregar-fila="acc_testigos"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Agregar testigo</button>
                <div class="row mt-2">
                    <div class="col-md-6"><label class="campo-etiqueta" for="c_aviso_por">Aviso dado por (Nombre) <span class="text-lowercase fw-normal">(se toma de "¿Quién reporta?", puedes corregirlo)</span></label><input type="text" name="c_aviso_por" id="c_aviso_por" class="campo text-uppercase" maxlength="150" list="novColaboradores" value="{{ $v['c_aviso_por'] }}"></div>
                    <div class="col-md-6"><label class="campo-etiqueta" for="c_depto_aviso">Depto de quien avisa</label><input type="text" id="c_depto_aviso" name="c_depto_aviso" class="campo text-uppercase" maxlength="100" value="{{ $v['c_depto_aviso'] }}"></div>
                </div>
                <label class="campo-etiqueta mt-2" for="c_actividades">Actividades cotidianas</label><textarea id="c_actividades" name="c_actividades" class="campo" rows="2" maxlength="5000">{{ $v['c_actividades'] }}</textarea>
                <label class="campo-etiqueta mt-2" for="c_mismas_actividades">¿Mismas actividades al momento del accidente?</label>
                <select id="c_mismas_actividades" name="c_mismas_actividades" class="campo campo-corto"><option value="1" {{ $sel($v['c_mismas_actividades'], '1') }}>Sí</option><option value="0" {{ $sel($v['c_mismas_actividades'], '0') }}>No</option></select>
            </div>
        </div>
    </div>
</div>

<div class="accordion-item" id="moduloMedico">
    <h2 class="accordion-header"><a class="accordion-button collapsed" role="button" data-bs-toggle="collapse" href="#colMedico" aria-expanded="false"><i class="bi bi-heart-pulse-fill me-2 text-danger" aria-hidden="true"></i>2. Servicio Médico: Dictamen Clínico</a></h2>
    <div id="colMedico" class="accordion-collapse collapse" data-bs-parent="#accordionExpediente">
        <div class="accordion-body">
            <span class="campo-etiqueta">Tipo de Herida</span>
            <div class="checkbox-grid mb-3 pb-2 border-bottom casillas-formato">
                @foreach (\App\Services\Novedades\Formatos\Accidente::HERIDAS as $herida)
                    <label><input type="checkbox" name="m_herida[]" value="{{ $herida }}" @checked(in_array($herida, (array) $v['m_herida'], true))> {{ $herida }}</label>
                @endforeach
            </div>
            <div class="row">
                <div class="col-md-5">
                    <label class="campo-etiqueta text-danger" for="inputParteCuerpo">Zonas Afectadas</label>
                    <input type="text" name="m_parte" id="inputParteCuerpo" class="campo fw-bold text-danger bg-danger-subtle" readonly placeholder="Zonas aparecerán aquí..." value="{{ $v['m_parte'] }}" data-nov-zonas>
                </div>
                <div class="col-md-7 border-start body-map bg-light rounded p-2" data-nov-mapa-corporal>
                    <div class="row">
                        <div class="col-6 col-md-3 p-1 position-relative">
                            <svg viewBox="0 0 100 140" role="img" aria-label="Frente">
                                <rect x="46" y="27" width="8" height="11" rx="2" fill="#e2e8f0" stroke="#94a3b8" stroke-width="1.5" class="cuello" />
                                <ellipse class="body-part{{ $z('Cabeza Frontal') }}" data-zona="Cabeza Frontal" cx="50" cy="17" rx="10.5" ry="14"/>
                                <circle class="node-dot{{ $z('Ojo Der') }}" data-zona="Ojo Der" cx="45.5" cy="15" r="1.4"/>
                                <circle class="node-dot{{ $z('Ojo Izq') }}" data-zona="Ojo Izq" cx="54.5" cy="15" r="1.4"/>
                                <circle class="node-dot{{ $z('Nariz') }}" data-zona="Nariz" cx="50" cy="18.5" r="1.4"/>
                                <ellipse class="node-dot{{ $z('Boca') }}" data-zona="Boca" cx="50" cy="23" rx="3" ry="1"/>
                                <circle class="node-dot{{ $z('Mentón') }}" data-zona="Mentón" cx="50" cy="27.5" r="1.8"/>
                                <path class="body-part{{ $z('Pecho') }}" data-zona="Pecho" d="M33,35 C34,30 41,26 50,26 C59,26 66,30 67,35 C69,41 68,48 65,54 L35,54 C32,48 31,41 33,35 Z"/>
                                <path class="body-part{{ $z('Abdomen') }}" data-zona="Abdomen" d="M35,53 L65,53 C64.5,64 63.5,75 60,85 C58,89 42,89 40,85 C36.5,75 35.5,64 35,53 Z"/>
                                <path class="body-part{{ $z('Brazo Der') }}" data-zona="Brazo Der" d="M20.5,42 A5.5,5.5 0 1 1 31.5,42 L29,76 A3,3 0 1 1 23,76 Z" transform="rotate(13 26 38)"/>
                                <path class="body-part{{ $z('Brazo Izq') }}" data-zona="Brazo Izq" d="M68.5,42 A5.5,5.5 0 1 1 79.5,42 L77,76 A3,3 0 1 1 71,76 Z" transform="rotate(-13 74 38)"/>
                                <ellipse class="node-dot{{ $z('Mano Der') }}" data-zona="Mano Der" cx="14.5" cy="79" rx="3.7" ry="5.2" transform="rotate(-10 14.5 79)"/>
                                <ellipse class="node-dot{{ $z('Mano Izq') }}" data-zona="Mano Izq" cx="85.5" cy="79" rx="3.7" ry="5.2" transform="rotate(10 85.5 79)"/>
                                <circle class="node-dot{{ $z('Dedos Mano Der') }}" data-zona="Dedos Mano Der" cx="11" cy="87" r="2.3"/>
                                <circle class="node-dot{{ $z('Dedos Mano Izq') }}" data-zona="Dedos Mano Izq" cx="89" cy="87" r="2.3"/>
                                <path class="body-part{{ $z('Pierna Der') }}" data-zona="Pierna Der" d="M36.5,92.5 A6.5,6.5 0 1 1 49.5,92.5 L46.5,128.5 A3.5,3.5 0 1 1 39.5,128.5 Z"/>
                                <path class="body-part{{ $z('Pierna Izq') }}" data-zona="Pierna Izq" d="M50.5,92.5 A6.5,6.5 0 1 1 63.5,92.5 L60.5,128.5 A3.5,3.5 0 1 1 53.5,128.5 Z"/>
                                <ellipse class="node-dot{{ $z('Pie Der') }}" data-zona="Pie Der" cx="42.5" cy="134.5" rx="7.5" ry="3.4" transform="rotate(-6 42.5 134.5)"/>
                                <ellipse class="node-dot{{ $z('Pie Izq') }}" data-zona="Pie Izq" cx="57.5" cy="134.5" rx="7.5" ry="3.4" transform="rotate(6 57.5 134.5)"/>
                            </svg>
                            <div class="body-label">Frente</div>
                        </div>
                        <div class="col-6 col-md-3 p-1">
                            <svg viewBox="0 0 100 140" role="img" aria-label="Espalda">
                                <rect x="46" y="27" width="8" height="11" rx="2" fill="#e2e8f0" stroke="#94a3b8" stroke-width="1.5" class="cuello" />
                                <ellipse class="body-part{{ $z('Nuca') }}" data-zona="Nuca" cx="50" cy="17" rx="10.5" ry="14"/>
                                <path class="body-part{{ $z('Espalda') }}" data-zona="Espalda" d="M33,35 C34,30 41,26 50,26 C59,26 66,30 67,35 C69,45 69,68 63,80 C61,86 57,89 50,89 C43,89 39,86 37,80 C31,68 31,45 33,35 Z"/>
                                <path class="body-part{{ $z('Brazo Izq') }}" data-zona="Brazo Izq" d="M20.5,42 A5.5,5.5 0 1 1 31.5,42 L29,76 A3,3 0 1 1 23,76 Z" transform="rotate(13 26 38)"/>
                                <path class="body-part{{ $z('Brazo Der') }}" data-zona="Brazo Der" d="M68.5,42 A5.5,5.5 0 1 1 79.5,42 L77,76 A3,3 0 1 1 71,76 Z" transform="rotate(-13 74 38)"/>
                                <path class="body-part{{ $z('Pierna Post Izq') }}" data-zona="Pierna Post Izq" d="M36.5,92.5 A6.5,6.5 0 1 1 49.5,92.5 L46.5,128.5 A3.5,3.5 0 1 1 39.5,128.5 Z"/>
                                <path class="body-part{{ $z('Pierna Post Der') }}" data-zona="Pierna Post Der" d="M50.5,92.5 A6.5,6.5 0 1 1 63.5,92.5 L60.5,128.5 A3.5,3.5 0 1 1 53.5,128.5 Z"/>
                            </svg>
                            <div class="body-label">Espalda</div>
                        </div>
                        <div class="col-6 col-md-3 p-1">
                            <svg viewBox="0 0 100 140" role="img" aria-label="Lateral derecho">
                                <rect x="47" y="27" width="6" height="11" rx="2" fill="#e2e8f0" stroke="#94a3b8" stroke-width="1.5" class="cuello" />
                                <ellipse class="body-part{{ $z('Perfil Der') }}" data-zona="Perfil Der" cx="49" cy="17" rx="9.5" ry="14"/>
                                <path class="body-part{{ $z('Tronco Der') }}" data-zona="Tronco Der" d="M42,34 C50,33 56,37 57,45 C58.5,55 58.5,70 56,82 C55,87 50,89 44,89 C41,89 40,84 40,75 C40,60 40,45 42,34 Z"/>
                                <path class="body-part{{ $z('Brazo Der') }}" data-zona="Brazo Der" d="M43.5,42 A5,5 0 1 1 53.5,42 L51.5,74 A3,3 0 1 1 45.5,74 Z"/>
                                <path class="body-part{{ $z('Muslo/Pierna Der') }}" data-zona="Muslo/Pierna Der" d="M40,92.5 A7,7 0 1 1 54,92.5 L51,128.5 A3.5,3.5 0 1 1 44,128.5 Z"/>
                                <ellipse class="node-dot{{ $z('Pie Der') }}" data-zona="Pie Der" cx="55" cy="134.5" rx="9" ry="3.4" transform="rotate(-4 55 134.5)"/>
                            </svg>
                            <div class="body-label">Lat. Der</div>
                        </div>
                        <div class="col-6 col-md-3 p-1">
                            <svg viewBox="0 0 100 140" role="img" aria-label="Lateral izquierdo">
                                <rect x="47" y="27" width="6" height="11" rx="2" fill="#e2e8f0" stroke="#94a3b8" stroke-width="1.5" class="cuello" />
                                <ellipse class="body-part{{ $z('Perfil Izq') }}" data-zona="Perfil Izq" cx="51" cy="17" rx="9.5" ry="14"/>
                                <path class="body-part{{ $z('Tronco Izq') }}" data-zona="Tronco Izq" d="M58,34 C50,33 44,37 43,45 C41.5,55 41.5,70 44,82 C45,87 50,89 56,89 C59,89 60,84 60,75 C60,60 60,45 58,34 Z"/>
                                <path class="body-part{{ $z('Brazo Izq') }}" data-zona="Brazo Izq" d="M46.5,42 A5,5 0 1 1 56.5,42 L54.5,74 A3,3 0 1 1 48.5,74 Z"/>
                                <path class="body-part{{ $z('Muslo/Pierna Izq') }}" data-zona="Muslo/Pierna Izq" d="M46,92.5 A7,7 0 1 1 60,92.5 L56,128.5 A3.5,3.5 0 1 1 49,128.5 Z"/>
                                <ellipse class="node-dot{{ $z('Pie Izq') }}" data-zona="Pie Izq" cx="45" cy="134.5" rx="9" ry="3.4" transform="rotate(4 45 134.5)"/>
                            </svg>
                            <div class="body-label">Lat. Izq</div>
                        </div>
                    </div>
                </div>
            </div>

            <h6 class="fw-bold mt-4 border-bottom pb-2">Información Complementaria</h6>
            <div class="row">
                <div class="col-md-6">
                    <label class="campo-etiqueta" for="m_primeros_aux">Primeros Auxilios</label>
                    <div class="d-flex gap-2"><select id="m_primeros_aux" name="m_primeros_aux" class="campo w-25"><option value="1" {{ $sel($v['m_primeros_aux'], '1') }}>Sí</option><option value="0" {{ $sel($v['m_primeros_aux'], '0') }}>No</option></select><input type="text" name="m_primeros_cuales" class="campo w-75" maxlength="255" placeholder="Cuáles..." aria-label="Primeros auxilios: cuáles" value="{{ $v['m_primeros_cuales'] }}"></div>
                </div>
                <div class="col-md-6">
                    <label class="campo-etiqueta" for="m_atencion_med">Atención Médica</label>
                    <div class="d-flex gap-2"><select id="m_atencion_med" name="m_atencion_med" class="campo w-25"><option value="1" {{ $sel($v['m_atencion_med'], '1') }}>Sí</option><option value="0" {{ $sel($v['m_atencion_med'], '0') }}>No</option></select><input type="text" name="m_atencion_cuales" class="campo w-75" maxlength="255" placeholder="Cuáles..." aria-label="Atención médica: cuáles" value="{{ $v['m_atencion_cuales'] }}"></div>
                </div>
            </div>
            <label class="campo-etiqueta mt-2" for="m_diagnostico">Diagnóstico preliminar</label><textarea id="m_diagnostico" name="m_diagnostico" class="campo" rows="2" maxlength="5000">{{ $v['m_diagnostico'] }}</textarea>
            <div class="row mt-2">
                <div class="col-md-6">
                    <label class="campo-etiqueta" for="m_hosp">Hospitalización</label>
                    <div class="d-flex gap-2"><select id="m_hosp" name="m_hosp" class="campo w-25"><option value="1" {{ $sel($v['m_hosp'], '1') }}>Sí</option><option value="0" {{ $sel($v['m_hosp'], '0') }}>No</option></select><input type="text" name="m_hosp_nombre" class="campo w-75" maxlength="255" placeholder="Nombre Hospital" aria-label="Nombre del hospital" value="{{ $v['m_hosp_nombre'] }}"></div>
                </div>
                <div class="col-md-6"><label class="campo-etiqueta" for="m_traslado">Trasladado en (Ambulancia, Taxi, etc.)</label><input type="text" id="m_traslado" name="m_traslado" class="campo text-uppercase" maxlength="150" value="{{ $v['m_traslado'] }}"></div>
            </div>
            <div class="row mt-2">
                <div class="col-md-6"><label class="campo-etiqueta" for="m_doctor">Nombre del Doctor que atendió</label><input type="text" id="m_doctor" name="m_doctor" class="campo text-uppercase" maxlength="150" value="{{ $v['m_doctor'] }}"></div>
            </div>
            <label class="campo-etiqueta mt-2 text-primary" for="m_observaciones">Observaciones Generales Médicas</label><textarea id="m_observaciones" name="m_observaciones" class="campo bg-light" rows="2" maxlength="5000">{{ $v['m_observaciones'] }}</textarea>
        </div>
    </div>
</div>

<div class="accordion-item" id="moduloGuardavidas">
    <h2 class="accordion-header"><a class="accordion-button collapsed" role="button" data-bs-toggle="collapse" href="#colGuardavidas" aria-expanded="false"><i class="bi bi-life-preserver me-2 text-info" aria-hidden="true"></i>3. Guardavidas: Anexo Acuático</a></h2>
    <div id="colGuardavidas" class="accordion-collapse collapse" data-bs-parent="#accordionExpediente">
        <div class="accordion-body">
            <div class="row bg-light border p-2 rounded mb-3 mx-0">
                <div class="col-md-3"><label class="campo-etiqueta" for="g_fecha">Fecha Suceso</label><input type="date" id="g_fecha" name="g_fecha" class="campo bg-white" value="{{ $v['g_fecha'] }}"></div>
                <div class="col-md-3"><label class="campo-etiqueta" for="g_hora">Hora Suceso</label><input type="time" id="g_hora" name="g_hora" class="campo bg-white" value="{{ $v['g_hora'] }}"></div>
                <div class="col-md-3"><label class="campo-etiqueta" for="g_turno">Turno</label><input type="text" id="g_turno" name="g_turno" class="campo bg-white text-uppercase" maxlength="50" placeholder="Matutino/Vespertino" value="{{ $v['g_turno'] }}"></div>
                <div class="col-md-3"><label class="campo-etiqueta" for="g_lugar">Lugar Exacto</label><input type="text" id="g_lugar" name="g_lugar" class="campo bg-white text-uppercase" maxlength="150" placeholder="Ej. Alberca Principal" value="{{ $v['g_lugar'] }}"></div>
            </div>
            <div class="row">
                <div class="col-md-4"><label class="campo-etiqueta" for="g_nombre">Nombre Guardavidas</label><input type="text" id="g_nombre" name="g_nombre" class="campo text-uppercase" maxlength="150" value="{{ $v['g_nombre'] }}"></div>
                <div class="col-md-4"><label class="campo-etiqueta" for="g_puesto">Puesto</label><input type="text" id="g_puesto" name="g_puesto" class="campo text-uppercase" maxlength="100" value="{{ $v['g_puesto'] }}"></div>
                <div class="col-md-4"><label class="campo-etiqueta" for="g_supervisor">Supervisor</label><input type="text" id="g_supervisor" name="g_supervisor" class="campo text-uppercase" maxlength="150" value="{{ $v['g_supervisor'] }}"></div>
            </div>
            <div class="row mt-2">
                <div class="col-md-4">
                    <span class="campo-etiqueta">Condiciones del Sujeto</span>
                    <div class="d-flex gap-3 mt-2 casillas-formato"><label><input type="checkbox" name="g_alcohol" value="1" @checked($v['g_alcohol'])> Alcoholizado</label></div>
                </div>
                <div class="col-md-4"><label class="campo-etiqueta" for="g_descalzo">¿Estaba Descalzo?</label><select id="g_descalzo" name="g_descalzo" class="campo"><option value="0" {{ $sel($v['g_descalzo'], '0') }}>No</option><option value="1" {{ $sel($v['g_descalzo'], '1') }}>Sí</option></select></div>
                <div class="col-md-4"><label class="campo-etiqueta" for="g_calzado">Tipo de calzado</label><input type="text" id="g_calzado" name="g_calzado" class="campo" maxlength="100" value="{{ $v['g_calzado'] }}"></div>
            </div>
            <div class="row mt-2 border-top pt-2">
                <div class="col-md-6">
                    <span class="campo-etiqueta">Apreciación Visual del Guardavidas</span>
                    <input type="text" name="g_tipo_herida" class="campo mb-1" maxlength="150" placeholder="Tipo de Herida Apreciada (Ej. Raspon)" aria-label="Tipo de Herida Apreciada" value="{{ $v['g_tipo_herida'] }}">
                    <input type="text" name="g_parte_afectada" class="campo" maxlength="150" placeholder="Parte Afectada Apreciada (Ej. Rodilla)" aria-label="Parte Afectada Apreciada" value="{{ $v['g_parte_afectada'] }}">
                </div>
                <div class="col-md-6">
                    <span class="campo-etiqueta">Riesgos Identificados</span>
                    <div class="d-flex flex-wrap gap-2 mb-1 casillas-formato"><label><input type="checkbox" name="g_acto" value="1" @checked($v['g_acto'])> Acto Inseguro</label><label><input type="checkbox" name="g_condicion" value="1" @checked($v['g_condicion'])> Cond. Insegura</label></div>
                    <input type="text" name="g_especifique" class="campo" maxlength="2000" placeholder="Especifique riesgo..." aria-label="Especifique riesgo" value="{{ $v['g_especifique'] }}">
                </div>
            </div>
            <label class="campo-etiqueta mt-2" for="g_desc">Descripción de los hechos</label><textarea id="g_desc" name="g_desc" class="campo" rows="2" maxlength="5000">{{ $v['g_desc'] }}</textarea>
            <div class="row mt-2 border-top pt-2">
                <div class="col-md-4">
                    <label class="campo-etiqueta" for="g_acudio_medico">¿Acudió a servicio médico?</label>
                    <select id="g_acudio_medico" name="g_acudio_medico" class="campo"><option value="0" {{ $sel($v['g_acudio_medico'], '0') }}>No</option><option value="1" {{ $sel($v['g_acudio_medico'], '1') }}>Sí</option></select>
                </div>
                <div class="col-md-4"><label class="campo-etiqueta" for="g_material">Material de curación utilizado</label><input type="text" id="g_material" name="g_material" class="campo" maxlength="255" placeholder="Ej. Gasas, vendas, hielo..." value="{{ $v['g_material'] }}"></div>
                <div class="col-md-4"><label class="campo-etiqueta" for="g_informa">Se informa a (Nombre)</label><input type="text" id="g_informa" name="g_informa" class="campo text-uppercase" maxlength="150" placeholder="Supervisor o jefe informado" value="{{ $v['g_informa'] }}"></div>
            </div>
        </div>
    </div>
</div>

<div class="accordion-item" id="moduloRH" data-nov-mostrar-si='{"acc_tipo_afectado":["COLABORADOR"]}' @if ($tipoAfectado !== 'COLABORADOR') hidden @endif>
    <h2 class="accordion-header"><a class="accordion-button collapsed" role="button" data-bs-toggle="collapse" href="#colRH" aria-expanded="false"><i class="bi bi-people-fill me-2 text-success" aria-hidden="true"></i>4. Uso Exclusivo Recursos Humanos</a></h2>
    <div id="colRH" class="accordion-collapse collapse" data-bs-parent="#accordionExpediente">
        <div class="accordion-body row">
            <div class="col-md-6"><label class="campo-etiqueta" for="rh_dias">Días de Incapacidad</label><input type="number" id="rh_dias" name="rh_dias" class="campo" min="0" max="999" value="{{ $v['rh_dias'] }}"></div>
            <div class="col-md-6"><label class="campo-etiqueta" for="rh_fecha">Se presenta a laborar:</label><input type="date" id="rh_fecha" name="rh_fecha" class="campo" value="{{ $v['rh_fecha'] }}"></div>
        </div>
    </div>
</div>

<div class="accordion-item">
    <h2 class="accordion-header"><a class="accordion-button collapsed" role="button" data-bs-toggle="collapse" href="#colFirmas" aria-expanded="false"><i class="bi bi-pen-fill me-2 text-dark" aria-hidden="true"></i>Firmas Digitales de Cierre</a></h2>
    <div id="colFirmas" class="accordion-collapse collapse" data-bs-parent="#accordionExpediente">
        <div class="accordion-body">
            <div class="p-3 bg-light border rounded text-center firmas-accidente" data-nov-firmas>
                <label class="campo-etiqueta mb-2" for="selectorFirma">Seleccione quién va a firmar en este momento:</label>
                {{-- Ronda 8 (NV-03): los firmantes cambian según el Tipo de Afectado (huésped o colaborador) --}}
                <select id="selectorFirma" class="campo fw-bold mx-auto mb-3 text-center border-primary selector-firma" data-nov-selector-firma
                        data-roles-por-tipo="{{ json_encode(\App\Models\AccidenteFirma::rolesParaPantalla()) }}">
                    @foreach (\App\Models\AccidenteFirma::rolesPara($tipoAfectado) as $rol => $texto)
                        <option value="{{ $rol }}">{{ $texto }}</option>
                    @endforeach
                </select>
                <div class="text-start">
                    @include('componentes.firma', ['id' => 'canvasFirma', 'nombre' => 'firma_en_curso', 'etiqueta' => 'Firma'])
                </div>
                <div class="mt-2 d-flex justify-content-center gap-2">
                    <button type="button" class="btn btn-sm btn-dark" data-accion="novedad-guardar-firma"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> Guardar Esta Firma</button>
                </div>
                <div id="estadoFirmas" class="mt-3 text-start small fw-bold text-success" data-nov-estado-firmas aria-live="polite">
                    @foreach ($firmasGuardadas as $rol => $f)
                        <span class="badge bg-success me-1 mb-1"><i class="bi bi-check-circle-fill me-1" aria-hidden="true"></i> {{ \App\Models\AccidenteFirma::etiqueta($rol, $tipoAfectado) }} (previamente guardada)</span>
                    @endforeach
                </div>
                @if ($firmasGuardadas->isNotEmpty())
                    <div class="firmas-guardadas">
                        @foreach ($firmasGuardadas as $rol => $f)
                            <figure>
                                <img src="{{ route('novedades.firma', [$n->id, $rol]) }}" alt="Firma: {{ \App\Models\AccidenteFirma::etiqueta($rol, $tipoAfectado) }}" loading="lazy">
                                <figcaption>{{ \App\Models\AccidenteFirma::etiqueta($rol, $tipoAfectado) }}</figcaption>
                            </figure>
                        @endforeach
                    </div>
                @endif
                @foreach (array_keys(\App\Models\AccidenteFirma::ROLES) as $rol)
                    <input type="hidden" name="f_{{ $rol }}" id="val_firma_{{ $rol }}" value="">
                @endforeach
            </div>
        </div>
    </div>
</div>
