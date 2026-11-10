{{--
    C-27 · Los tres caminos de un alta de colaborador por verificar (los
    comparten Colaboradores y «Altas por verificar»): Es correcto (Aprobar),
    Es el mismo (Aprobar · es el mismo) y Rechazar (con motivo).
    $validacion: catálogos de AdministradorColaboradores::catalogos(…, 'colaboradores.aprobar');
    $validados: colaboradores validados y activos; $volver: 'altas' | null
--}}
@php
    $dialogo = old('_dialogo');
    $reabrirValidar = is_string($dialogo) && str_starts_with($dialogo, 'validar-');
    $validarId = $reabrirValidar ? (int) substr($dialogo, 8) : null;
    $v = fn (string $campo) => $reabrirValidar ? old($campo) : '';
@endphp
<dialog id="dialogoValidarColaborador" class="dialogo ancho" aria-labelledby="titulo-co-validar" @if ($reabrirValidar) data-abrir-al-cargar @endif>
    <div class="dialogo-cabecera">
        <h2 id="titulo-co-validar"><i class="bi bi-patch-check me-2 text-success" aria-hidden="true"></i>Es correcto: verificar alta</h2>
        <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </div>
    <div class="dialogo-cuerpo">
        <p class="small text-muted">Revisa los datos que capturó la caseta, corrige lo necesario y asígnale su número de empleado. Desde ese momento es un colaborador normal.</p>
        <form action="{{ $validarId ? route('colaboradores.validar', $validarId) : '' }}" method="POST" autocomplete="off" data-form-colaborador>
            @csrf
            @method('PUT')
            <input type="hidden" name="_dialogo" value="{{ $validarId ? 'validar-'.$validarId : '' }}" data-campo-dialogo data-prefijo-dialogo="validar-">
            @if ($volver)<input type="hidden" name="volver" value="{{ $volver }}">@endif
            <div class="row">
                <div class="col-md-6">
                    <label class="campo-etiqueta" for="validar_co_num">Núm. Empleado</label>
                    <input type="text" id="validar_co_num" name="num_empleado" class="campo mb-1" maxlength="20" value="{{ $v('num_empleado') }}"
                           autocapitalize="characters" required data-duplicado="{{ route('colaboradores.duplicado') }}" data-duplicado-min="1">
                </div>
                <div class="col-md-6">
                    <label class="campo-etiqueta" for="validar_co_sede">Sede Física</label>
                    <select id="validar_co_sede" name="sede_id" class="campo" data-colab-sede>
                        <option value="">-- Corporativo (todas las sedes) --</option>
                        @foreach ($validacion['sedes']->where('activo', true) as $sede)
                            <option value="{{ $sede->id }}" @selected((string) $v('sede_id') === (string) $sede->id)>{{ $sede->nombre }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6">
                    <label class="campo-etiqueta" for="validar_co_depto">Departamento</label>
                    <select id="validar_co_depto" name="departamento_id" class="campo" data-colab-depto>
                        <option value="">-- Sin Departamento --</option>
                        @foreach ($validacion['departamentos']->where('activo', true) as $dep)
                            <option value="{{ $dep->id }}" data-todas="{{ $dep->todas_las_sedes ? 1 : 0 }}" data-sedes="{{ $dep->sedes->pluck('id')->join(',') }}" @selected((string) $v('departamento_id') === (string) $dep->id)>{{ $dep->nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="campo-etiqueta" for="validar_co_puesto">Puesto</label>
                    <select id="validar_co_puesto" name="puesto_id" class="campo" data-colab-puesto>
                        <option value="">-- Sin Puesto --</option>
                        @foreach ($validacion['puestos']->where('activo', true) as $pu)
                            <option value="{{ $pu->id }}" data-deps="{{ $pu->departamentos->pluck('id')->join(',') }}" @selected((string) $v('puesto_id') === (string) $pu->id)>{{ $pu->nombre }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="row">
                <div class="col-md-4"><label class="campo-etiqueta" for="validar_co_nombre">Nombre(s)</label><input type="text" id="validar_co_nombre" name="nombre" class="campo" maxlength="60" value="{{ $v('nombre') }}" required></div>
                <div class="col-md-4"><label class="campo-etiqueta" for="validar_co_paterno">Apellido Paterno</label><input type="text" id="validar_co_paterno" name="apellido_paterno" class="campo" maxlength="60" value="{{ $v('apellido_paterno') }}" required></div>
                <div class="col-md-4"><label class="campo-etiqueta" for="validar_co_materno">Apellido Materno</label><input type="text" id="validar_co_materno" name="apellido_materno" class="campo" maxlength="60" value="{{ $v('apellido_materno') }}"></div>
            </div>
            <label class="campo-etiqueta" for="validar_co_tel">Teléfono <span class="text-lowercase fw-normal">(opcional)</span></label>
            <input type="tel" inputmode="tel" id="validar_co_tel" name="telefono" class="campo" maxlength="20" value="{{ $v('telefono') }}">
            <div class="dialogo-acciones">
                <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                <button type="submit" class="btn-esmeralda">Aprobar</button>
            </div>
        </form>
    </div>
</dialog>

<dialog id="dialogoFusionarColaborador" class="dialogo" aria-labelledby="titulo-co-fusionar">
    <div class="dialogo-cabecera">
        <h2 id="titulo-co-fusionar"><i class="bi bi-people me-2 text-warning" aria-hidden="true"></i>Es el mismo</h2>
        <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </div>
    <div class="dialogo-cuerpo">
        <p class="small text-muted">Si esta persona ya estaba registrada, elige su registro correcto. Todo lo que la caseta registró con esta alta pasa a ese colaborador y el alta queda dada de baja (unida).</p>
        <form action="" method="POST">
            @csrf
            @method('PUT')
            @if ($volver)<input type="hidden" name="volver" value="{{ $volver }}">@endif
            <label class="campo-etiqueta" for="fusionar_destino">Colaborador correcto</label>
            <select id="fusionar_destino" name="destino_id" class="campo" required data-select-buscable="Escribe nombre o número de empleado…">
                <option value="">-- Selecciona --</option>
                @foreach ($validados as $op)
                    <option value="{{ $op->id }}">{{ $op->nombreCompleto() }} · #{{ $op->num_empleado }}{{ $op->sede ? ' · '.$op->sede->nombre : '' }}</option>
                @endforeach
            </select>
            <div class="dialogo-acciones">
                <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                <button type="submit" class="btn-ambar">Aprobar · es el mismo</button>
            </div>
        </form>
    </div>
</dialog>

{{-- C-27: «Rechazar» con motivo y aviso a quien la registró --}}
<dialog id="dialogoRechazarColaborador" class="dialogo" aria-labelledby="titulo-co-rechazar">
    <div class="dialogo-cabecera">
        <h2 id="titulo-co-rechazar"><i class="bi bi-x-octagon me-2 text-danger" aria-hidden="true"></i>Rechazar alta</h2>
        <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </div>
    <div class="dialogo-cuerpo">
        <p class="small text-muted">Úsalo si el alta no procede (datos inventados, capturada por error). Queda dada de baja, la caseta ya no la puede usar y avisamos a quien la registró.</p>
        <form action="" method="POST">
            @csrf
            @method('PUT')
            @if ($volver)<input type="hidden" name="volver" value="{{ $volver }}">@endif
            <label class="campo-etiqueta" for="rechazar_co_motivo">Motivo del rechazo *</label>
            <textarea id="rechazar_co_motivo" name="motivo_rechazo" class="campo" rows="3" maxlength="255" minlength="5" required placeholder="Ej: No trabaja aquí; capturado por error..."></textarea>
            @error('motivo_rechazo')<p class="texto-error-rh">{{ $message }}</p>@enderror
            <div class="dialogo-acciones">
                <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                <button type="submit" class="btn-rojo">Rechazar</button>
            </div>
        </form>
    </div>
</dialog>

