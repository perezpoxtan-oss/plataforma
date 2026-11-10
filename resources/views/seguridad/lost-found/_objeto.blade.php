{{--
    Mejoras Incidentes (ADR-0013): «Registrar objeto encontrado». Alta corta
    (criterio 8): qué se encontró, dónde y quién lo trajo. El ticket de Lost &
    Found se crea por detrás; el expediente sigue disponible para completarlo.
--}}
@php
    $trasErrorObj = old('_dialogo') === 'objeto-lf';
    $valObj = fn (string $campo, $porDefecto = '') => $trasErrorObj ? old($campo, $porDefecto) : $porDefecto;
    $sedeUnica = $sedes->count() === 1 ? (string) $sedes->first()->id : '';
@endphp
<dialog id="dialogoObjetoLf" class="dialogo dialogo-novedad" aria-labelledby="titulo-objeto-lf" @if ($trasErrorObj) data-abrir-al-cargar @endif>
    <div class="dialogo-cabecera">
        <h2 id="titulo-objeto-lf"><i class="bi bi-bag-plus-fill me-2 text-warning" aria-hidden="true"></i>Registrar objeto encontrado</h2>
        <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </div>
    <div class="dialogo-cuerpo">
        <form action="{{ route('lost_found.objetos.store') }}" method="POST" autocomplete="off" data-form-novedad="objeto">
            @csrf
            <input type="hidden" name="_dialogo" value="objeto-lf">
            @if ($trasErrorObj && $errors->any())
                <div class="alert alert-danger small py-2" role="alert">
                    @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
                </div>
            @endif
            <p class="small text-muted">Queda en resguardo con su folio LF y su ticket de Lost &amp; Found. Si hay más datos (habitación, señas, reporte de pérdida), complétalos después en el expediente.</p>
            @if ($sedes->count() > 1)
                <label class="campo-etiqueta" for="obj_sede">Sede</label>
                <select id="obj_sede" name="sede_id" class="campo" required data-nov-sede>
                    <option value="">-- Seleccionar --</option>
                    @foreach ($sedes as $s)
                        <option value="{{ $s->id }}" @selected((string) $valObj('sede_id') === (string) $s->id)>{{ $s->nombre }}</option>
                    @endforeach
                </select>
            @else
                <input type="hidden" name="sede_id" value="{{ $sedeUnica }}" data-nov-sede>
            @endif
            <div class="row">
                <div class="col-md-8">
                    <label class="campo-etiqueta" for="obj_objeto">¿Qué se encontró?</label>
                    <input type="text" id="obj_objeto" name="lf_objeto" class="campo text-uppercase" maxlength="150" required placeholder="Ej: Paraguas negro, cartera café" value="{{ $valObj('lf_objeto') }}">
                </div>
                <div class="col-md-4">
                    <label class="campo-etiqueta" for="obj_tipo">Tipo de valor</label>
                    <select id="obj_tipo" name="lf_tipo_valor" class="campo">
                        @foreach (\App\Models\LostFoundArticulo::TIPOS_VALOR as $claveTipo => $nombreTipo)
                            <option value="{{ $claveTipo }}" @selected($valObj('lf_tipo_valor', 'OTRO') === $claveTipo)>{{ $nombreTipo }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6"><label class="campo-etiqueta" for="obj_marca">Marca <span class="text-lowercase fw-normal">(opcional)</span></label>
                    <input type="text" id="obj_marca" name="lf_marca" class="campo text-uppercase" maxlength="100" value="{{ $valObj('lf_marca') }}"></div>
                <div class="col-md-6"><label class="campo-etiqueta" for="obj_color">Color <span class="text-lowercase fw-normal">(opcional)</span></label>
                    <input type="text" id="obj_color" name="lf_color" class="campo text-uppercase" maxlength="50" value="{{ $valObj('lf_color') }}"></div>
            </div>
            <div class="row">
                <div class="col-md-6"><label class="campo-etiqueta" for="obj_donde">¿Dónde se encontró?</label>
                    <input type="text" id="obj_donde" name="ubicacion" class="campo text-uppercase" maxlength="255" required placeholder="Ej: Lobby, junto a los sillones" value="{{ $valObj('ubicacion') }}"></div>
                <div class="col-md-6"><label class="campo-etiqueta" for="obj_bodega">Ubicación en bodega <span class="text-lowercase fw-normal">(opcional)</span></label>
                    <input type="text" id="obj_bodega" name="lf_ubicacion_bodega" class="campo text-uppercase" maxlength="100" placeholder="Ej: Anaquel 3, Caja B" value="{{ $valObj('lf_ubicacion_bodega') }}"></div>
            </div>
            <label class="campo-etiqueta" for="obj_reporta">¿Quién lo encontró o lo entregó?</label>
            <div class="campo-con-boton">
                <input type="text" id="obj_reporta" name="reportado_por" class="campo text-uppercase" maxlength="150" required placeholder="Nombre de quien lo trajo" value="{{ $valObj('reportado_por') }}" data-nov-reporta>
                <button type="button" class="btn-fui-yo" data-accion="novedad-fui-yo" data-nombre="{{ mb_strtoupper(auth()->user()->name) }}" data-objetivo="obj_reporta">
                    <i class="bi bi-person-check-fill me-1" aria-hidden="true"></i>Fui yo
                </button>
            </div>
            <label class="campo-etiqueta" for="obj_cuando">¿Cuándo se encontró?</label>
            <input type="datetime-local" id="obj_cuando" name="ocurrio_en" class="campo" value="{{ $valObj('ocurrio_en', $ahoraLocal) }}" max="{{ substr($ahoraLocal, 0, 10) }}T23:59">
            <div class="dialogo-acciones">
                <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                <button type="submit" class="btn-despachar"><i class="bi bi-save-fill me-1" aria-hidden="true"></i>Guardar</button>
            </div>
        </form>
    </div>
</dialog>
