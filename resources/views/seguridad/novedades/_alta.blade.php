{{--
    "Generar Ticket Rápido" (SEGCAT: dialogAltaTicket). Mismos campos y
    textos; la sede del usuario viene elegida, "¿Cuándo sucedió?" trae la
    hora actual y quien reporta se puede escanear con su gafete.
--}}
@php
    $trasError = old('_dialogo') === 'crear';
    $val = fn (string $campo, $porDefecto = '') => $trasError ? old($campo, $porDefecto) : $porDefecto;
    $sedePorDefecto = $sedesAlta->count() === 1 ? (string) $sedesAlta->first()->id : '';
    $edificios = $catalogos['espacios']->where('nivel', \App\Models\Espacio::EDIFICIO);
    $pisos = $catalogos['espacios']->where('nivel', \App\Models\Espacio::AREA);
    $reportadoAnterior = $trasError && old('reportado_colaborador_id') ? \App\Models\Colaborador::find((int) old('reportado_colaborador_id')) : null;
@endphp
<dialog id="dialogoAltaTicket" class="dialogo dialogo-novedad" aria-labelledby="titulo-alta-ticket" @if ($trasError) data-abrir-al-cargar @endif>
    <div class="dialogo-cabecera">
        <h2 id="titulo-alta-ticket"><i class="bi bi-lightning-fill me-2 text-danger" aria-hidden="true"></i>Generar Ticket Rápido</h2>
        <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </div>
    <div class="dialogo-cuerpo">
        <form action="{{ route('novedades.store') }}" method="POST" autocomplete="off" data-form-novedad="alta">
            @csrf
            <input type="hidden" name="_dialogo" value="crear">
            <p class="linea-empresa mb-3"><i class="bi bi-building-check me-1" aria-hidden="true"></i>Bitácora de: <strong>{{ $empresaNombre }}</strong></p>

            <div class="row">
                <div class="col-md-6">
                    <label class="campo-etiqueta" for="alta_sede">Sede</label>
                    <select id="alta_sede" name="sede_id" class="campo" required data-nov-sede>
                        <option value="">-- Seleccionar --</option>
                        @foreach ($sedesAlta as $s)
                            <option value="{{ $s->id }}" @selected((string) $val('sede_id', $sedePorDefecto) === (string) $s->id) @if ($sedePorDefecto === (string) $s->id) data-por-defecto @endif>{{ $s->nombre }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="campo-etiqueta" for="alta_categoria">Categoría <span class="text-lowercase fw-normal">(si aún no se sabe, se confirma al atender)</span></label>
                    <select id="alta_categoria" name="categoria" class="campo campo-categoria">
                        @foreach ($categoriasAlta as $clave)
                            <option value="{{ $clave }}" @selected($val('categoria', $categoriasAlta[0]) === $clave) @if ($loop->first) data-por-defecto @endif>{{ \App\Models\Novedad::OPCIONES_CATEGORIA[$clave] }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <label class="campo-etiqueta" for="alta_reporta">¿Quién reporta? <span class="text-lowercase fw-normal">(buscar colaborador o escribir)</span></label>
            <div class="campo-con-boton">
                <input type="text" id="alta_reporta" name="reportado_por" class="campo text-uppercase" maxlength="150" list="novColaboradores" required
                       placeholder="Nombre de quien reporta" value="{{ $val('reportado_por') }}" data-nov-reporta>
                <button type="button" class="btn-fui-yo" data-accion="novedad-fui-yo" data-nombre="{{ mb_strtoupper(auth()->user()->name) }}" data-objetivo="alta_reporta">
                    <i class="bi bi-person-check-fill me-1" aria-hidden="true"></i>Fui yo quien lo observó
                </button>
            </div>
            <div class="lector-compacto" data-nov-lector-reporta>
                @include('componentes.lector', ['id' => 'alta_reporta_gafete', 'etiqueta' => 'o escanea el gafete de quien reporta (opcional)',
                    'tipos' => 'colaborador', 'nombre' => 'reportado_colaborador_id', 'valor' => $trasError ? old('reportado_colaborador_id') : null,
                    'elegido' => $reportadoAnterior ? $reportadoAnterior->nombreCompleto().' · Núm. '.$reportadoAnterior->num_empleado : null])
            </div>

            <div class="row">
                <div class="col-md-6">
                    <label class="campo-etiqueta" for="alta_asignado">¿A quién se canaliza? <span class="text-lowercase fw-normal">(opcional)</span></label>
                    <select id="alta_asignado" name="asignado_a" class="campo" data-nov-depende="sede">
                        <option value="">-- Sin asignar todavía --</option>
                        @foreach ($catalogos['usuarios'] as $u)
                            <option value="{{ $u['id'] }}" data-de="{{ $u['sedes'] }}" @selected((string) $val('asignado_a') === (string) $u['id'])>{{ $u['nombre'] }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <span class="campo-etiqueta">Área General <span class="text-lowercase fw-normal">(edificio y piso)</span></span>
                    <div class="campo-grupo">
                        <select name="area_edificio_id" class="campo" aria-label="Edificio" data-nov-depende="sede" data-nov-edificio>
                            <option value="">-- Edificio --</option>
                            @foreach ($edificios as $e)
                                <option value="{{ $e->id }}" data-de="{{ $e->sede_id }}" @selected((string) $val('area_edificio_id') === (string) $e->id)>{{ $e->nombre }}</option>
                            @endforeach
                        </select>
                        <select name="area_piso_id" class="campo" aria-label="Piso" data-nov-depende="edificio">
                            <option value="">-- Piso --</option>
                            @foreach ($pisos as $p)
                                <option value="{{ $p->id }}" data-de="{{ $p->padre_id }}" @selected((string) $val('area_piso_id') === (string) $p->id)>{{ $p->nombre }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <label class="campo-etiqueta" for="alta_ubicacion">Ubicación específica</label>
                    <input type="text" id="alta_ubicacion" name="ubicacion" class="campo text-uppercase" maxlength="255" placeholder="Ej: Piso 2, cerca del elevador" required value="{{ $val('ubicacion') }}">
                </div>
                <div class="col-md-6">
                    <label class="campo-etiqueta" for="alta_involucrados">¿Involucra a más personas o áreas? <span class="text-lowercase fw-normal">(opcional, buscar o escribir)</span></label>
                    <input type="text" id="alta_involucrados" name="involucrados" class="campo text-uppercase" maxlength="255" list="novColaboradores" placeholder="Nombres o áreas, si aplica" value="{{ $val('involucrados') }}">
                </div>
            </div>

            <label class="campo-etiqueta" for="alta_cuando">¿Cuándo sucedió? <span class="text-lowercase fw-normal">(fecha y hora del hecho, no de cuando se reporta)</span></label>
            <input type="datetime-local" id="alta_cuando" name="ocurrio_en" class="campo" value="{{ $val('ocurrio_en', $ahoraLocal) }}" max="{{ substr($ahoraLocal, 0, 10) }}T23:59">

            <label class="campo-etiqueta" for="alta_que">¿Qué sucedió?</label>
            <textarea id="alta_que" name="descripcion" class="campo" rows="3" maxlength="5000" required>{{ $val('descripcion') }}</textarea>

            <label class="campo-etiqueta" for="alta_como">¿Cómo sucedió? <span class="text-lowercase fw-normal">(opcional, si ya se sabe)</span></label>
            <textarea id="alta_como" name="como_sucedio" class="campo" rows="2" maxlength="5000">{{ $val('como_sucedio') }}</textarea>

            <div class="dialogo-acciones">
                <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                <button type="submit" class="btn-despachar"><i class="bi bi-send-fill me-1" aria-hidden="true"></i>Despachar Ticket</button>
            </div>
        </form>
    </div>
</dialog>
