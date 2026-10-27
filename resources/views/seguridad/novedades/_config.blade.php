{{-- Configuración → «Bitácora de novedades» (ADR-0013): nombre del módulo y categoría del ticket de un hallazgo de recorrido. $empresa, $puedeEditar --}}
@php
    $ajustesNov = app(\App\Services\Novedades\AjustesNovedades::class);
    $nombreActual = $ajustesNov->nombreMenu($empresa->id);
    $categoriaActual = $ajustesNov->categoriaHallazgoPc($empresa);
    $trasErrorNov = $errors->hasAny(['nombre_opcion', 'nombre_menu', 'categoria_hallazgo_pc', 'departamento_accidentes']);
@endphp
<section class="tarjeta p-4" id="novedades" aria-labelledby="t-novedades">
    <h3 id="t-novedades" class="h6 fw-bold"><i class="bi bi-headset me-2 text-danger" aria-hidden="true"></i>{{ $ajustesNov->bitacora($empresa->id) }}: nombre y hallazgos</h3>
    <p class="small text-muted">Ajustes de {{ $empresa->nombre_comercial }}. Si no cambias nada, todo se ve y funciona como siempre.</p>
    @unless ($puedeEditar)
        <div class="alert alert-secondary small py-2"><i class="bi bi-lock-fill me-1" aria-hidden="true"></i>Tu rol solo puede consultar esta configuración, no modificarla.</div>
    @endunless
    <form action="{{ route('configuracion.novedades') }}" method="POST">
        @csrf
        @method('PUT')
        {{-- Nombre del módulo (decisión del dueño): «Novedades» por omisión o «Incidentes»; otro nombre, en Avanzado --}}
        @php
            $opcionActual = $trasErrorNov ? old('nombre_opcion', $ajustesNov->opcion($empresa->id)) : $ajustesNov->opcion($empresa->id);
            $otroActual = $trasErrorNov ? old('nombre_menu') : ($opcionActual === \App\Services\Novedades\AjustesNovedades::OPCION_OTRO ? $nombreActual : '');
        @endphp
        <label class="campo-etiqueta" for="nov_nombre_opcion">Nombre del módulo</label>
        <select id="nov_nombre_opcion" name="nombre_opcion" class="campo @error('nombre_opcion') is-invalid @enderror" @disabled(! $puedeEditar)>
            <option value="Novedades" @selected($opcionActual === 'Novedades')>Novedades (como siempre)</option>
            <option value="Incidentes" @selected($opcionActual === 'Incidentes')>Incidentes</option>
            <option value="{{ \App\Services\Novedades\AjustesNovedades::OPCION_OTRO }}" @selected($opcionActual === \App\Services\Novedades\AjustesNovedades::OPCION_OTRO)>Otro nombre (escríbelo en Avanzado)</option>
        </select>
        @error('nombre_opcion')<p class="text-danger small mb-2">{{ $message }}</p>@enderror
        <p class="campo-ayuda mt-0">«Novedades» es donde va todo lo que pasa en el día, sin importar su tipo. Si tu empresa le dice «Incidentes», elígelo: cambia el menú, el botón de abajo del celular, los títulos, los avisos, los informes y el manual. Los tickets, los permisos y las direcciones no cambian.</p>
        <details class="mb-3" @if ($opcionActual === \App\Services\Novedades\AjustesNovedades::OPCION_OTRO || $errors->has('nombre_menu')) open @endif>
            <summary class="small fw-bold">Avanzado: otro nombre</summary>
            <label class="campo-etiqueta mt-2" for="nov_nombre_menu">Otro nombre <span class="text-lowercase fw-normal">(solo si elegiste «Otro nombre»)</span></label>
            <input type="text" id="nov_nombre_menu" name="nombre_menu" class="campo @error('nombre_menu') is-invalid @enderror" maxlength="40"
                   value="{{ $otroActual }}" placeholder="Por ejemplo «Despacho»" @disabled(! $puedeEditar)>
            @error('nombre_menu')<p class="text-danger small mb-2">{{ $message }}</p>@enderror
        </details>

        <label class="campo-etiqueta" for="nov_categoria_hallazgo">Cuando un Recorrido de Protección Civil encuentra una falla, abrir el ticket como</label>
        <select id="nov_categoria_hallazgo" name="categoria_hallazgo_pc" class="campo" @disabled(! $puedeEditar)>
            @foreach (\App\Services\Novedades\AjustesNovedades::CATEGORIAS_HALLAZGO as $clave => $texto)
                <option value="{{ $clave }}" @selected(($trasErrorNov ? old('categoria_hallazgo_pc') : $categoriaActual) === $clave)>{{ $texto }}</option>
            @endforeach
        </select>
        <p class="campo-ayuda mt-0">«Mantenimiento de equipo PC» evita que un extintor despresurizado parezca una emergencia real en el Tablero y en Tendencias. Elige «Siniestro Protección Civil» solo si tu empresa quiere tratar cada falla como emergencia. <span class="valor-original">Valor original: Mantenimiento de equipo PC.</span></p>
        {{-- C-24 (D-04): reloj de los tickets canalizados por categoría --}}
        @php $horasReloj = $ajustesNov->relojHoras($empresa); @endphp
        <fieldset class="mt-3">
            <legend class="campo-etiqueta">Reloj de los tickets canalizados (horas hasta el recordatorio)</legend>
            <div class="campo-grupo">
                @foreach (['accidente' => 'Accidente', 'robo' => 'Robo', 'otras' => 'Las demás categorías'] as $claveReloj => $textoReloj)
                    <div class="flex-fill">
                        <label class="campo-etiqueta" for="nov_reloj_{{ $claveReloj }}">{{ $textoReloj }}</label>
                        <div class="d-flex align-items-center gap-2"><input type="number" id="nov_reloj_{{ $claveReloj }}" name="reloj_horas[{{ $claveReloj }}]" class="campo campo-horas-caseta" min="1" max="{{ \App\Services\Novedades\AjustesNovedades::RELOJ_MAX_HORAS }}" inputmode="numeric" value="{{ old('reloj_horas.'.$claveReloj, $horasReloj[$claveReloj]) }}" @disabled(! $puedeEditar)><span class="small text-muted">h</span></div>
                        @error('reloj_horas.'.$claveReloj)<p class="text-danger small mb-1">{{ $message }}</p>@enderror
                    </div>
                @endforeach
            </div>
            <p class="campo-ayuda mt-0">El ticket muestra cuánto lleva con la persona canalizada y cambia de color. A esas horas se le recuerda; al triple pasa al jefe de seguridad de la sede. <span class="valor-original">Valor original: Accidente 2 h, Robo 4 h y las demás 24 h.</span></p>
        </fieldset>
        {{-- Revisión Etapa 5 (C-28): a quién va el Accidente de un colaborador (antes se reconocía por el nombre del departamento) --}}
        @php
            $deptoAccidentes = $ajustesNov->departamentoAccidentes($empresa);
            $deptosEmpresa = \App\Models\Departamento::withoutGlobalScopes()->where('empresa_id', $empresa->id)->where('activo', true)->orderBy('nombre')->get(['id', 'nombre']);
            $deptoElegido = (string) ($trasErrorNov ? old('departamento_accidentes') : ($deptoAccidentes ?? 0));
        @endphp
        <label class="campo-etiqueta mt-3" for="nov_departamento_accidentes">Departamento que recibe accidentes de personal</label>
        <select id="nov_departamento_accidentes" name="departamento_accidentes" class="campo @error('departamento_accidentes') is-invalid @enderror" @disabled(! $puedeEditar)>
            <option value="0" @selected($deptoElegido === '0')>Ninguno (no avisar a un departamento)</option>
            @foreach ($deptosEmpresa as $depto)
                <option value="{{ $depto->id }}" @selected($deptoElegido === (string) $depto->id)>{{ $depto->nombre }}</option>
            @endforeach
        </select>
        @error('departamento_accidentes')<p class="text-danger small mb-2">{{ $message }}</p>@enderror
        <p class="campo-ayuda mt-0">Cuando se registra el Accidente de alguien del personal, su responsable recibe el ticket en «Tickets de mi departamento» para llenar su parte. El formato del accidente no cambia. <span class="valor-original">Valor original: el departamento que se llama Recursos Humanos.</span></p>
        @if ($puedeEditar)
            <button type="submit" class="btn-azul mt-2" style="min-height:44px;border-radius:10px;padding:0 1.25rem;">Guardar ajustes de la bitácora</button>
        @endif
    </form>
    {{-- C-31 (D-20): «¿Qué hacer? Ver procedimiento» por categoría y por tipo de equipo (en Avanzado; solo con Procedimientos) --}}
    @php
        $vinculosProc = app(\App\Services\Procedimientos\ProcedimientosVinculados::class);
        $opcionesProc = $vinculosProc->disponible((int) $empresa->id) ? app(\App\Support\Tenancy\Tenant::class)->conEmpresa($empresa->id, fn () => $vinculosProc->opciones()) : collect();
        $mapaProc = $vinculosProc->mapa($empresa);
    @endphp
    @if ($opcionesProc->isNotEmpty())
        <details class="mt-3" id="novedades-procedimientos" @if ($errors->has('vinculos.*')) open @endif>
            <summary class="small fw-bold">Avanzado: «¿Qué hacer?» — procedimiento de cada categoría y tipo de equipo @if ($mapaProc) ({{ count($mapaProc) }}) @endif</summary>
            <p class="small mt-2 mb-2">El ticket de esa categoría y el punto del recorrido de ese equipo muestran «¿Qué hacer? Ver procedimiento» a quien puede ver procedimientos. Solo se ofrecen los publicados.</p>
            <form action="{{ route('configuracion.novedades.procedimientos') }}" method="POST" class="m-0">
                @csrf
                @method('PUT')
                <div class="row g-2">
                    @foreach (\App\Services\Procedimientos\ProcedimientosVinculados::claves() as $claveProc => $textoProc)
                        @if ($loop->first)<div class="col-12 campo-etiqueta mb-0">Categorías del ticket</div>@endif
                        @if ($claveProc === 'equipo:'.array_key_first(\App\Models\EquipoPc::CATEGORIAS))<div class="col-12 campo-etiqueta mb-0 mt-2">Tipos de equipo de Protección Civil</div>@endif
                        <div class="col-md-6">
                            <label class="small" for="proc_{{ str_replace(':', '_', $claveProc) }}">{{ $textoProc }}</label>
                            <select id="proc_{{ str_replace(':', '_', $claveProc) }}" name="vinculos[{{ $claveProc }}]" class="campo" @disabled(! $puedeEditar)>
                                <option value="">— Ninguno —</option>
                                @foreach ($opcionesProc as $op)
                                    <option value="{{ $op->id }}" @selected(($mapaProc[$claveProc] ?? null) === $op->id)>{{ $op->clave ? $op->clave.' · ' : '' }}{{ $op->titulo }}</option>
                                @endforeach
                            </select>
                        </div>
                    @endforeach
                </div>
                @error('vinculos.*')<p class="text-danger small mb-2">{{ $message }}</p>@enderror
                @if ($puedeEditar)
                    <button type="submit" class="btn-azul mt-2" style="min-height:44px;border-radius:10px;padding:0 1.25rem;">Guardar procedimientos</button>
                @endif
            </form>
        </details>
    @endif
    {{-- C-29 (D-06): reclasificación opcional de los hallazgos que se abrieron como siniestro (en Avanzado) --}}
    @php $hallazgosSiniestro = $puedeEditar ? $ajustesNov->hallazgosComoSiniestro()->where('novedades.empresa_id', $empresa->id)->count() : 0; @endphp
    @if ($hallazgosSiniestro > 0)
        <details class="mt-3">
            <summary class="small fw-bold">Avanzado: hallazgos anteriores registrados como siniestro ({{ $hallazgosSiniestro }})</summary>
            <p class="small mt-2 mb-2">{{ $hallazgosSiniestro === 1 ? 'Hay 1 ticket que abrió' : 'Hay '.$hallazgosSiniestro.' tickets que abrió' }} un recorrido como «Siniestro Protección Civil» y nadie lo documentó como siniestro. Puedes pasarlos a «Mantenimiento de equipo PC» para que no cuenten como emergencias. Cada ticket queda con una nota que lo explica.</p>
            <form action="{{ route('configuracion.novedades.reclasificar') }}" method="POST" class="m-0" data-confirmar="¿Pasar {{ $hallazgosSiniestro }} {{ $hallazgosSiniestro === 1 ? 'ticket' : 'tickets' }} a «Mantenimiento de equipo PC»?">
                @csrf
                @method('PUT')
                <button type="submit" class="btn-azul" style="min-height:44px;border-radius:10px;padding:0 1.25rem;">Guardar como Mantenimiento de equipo PC</button>
            </form>
        </details>
    @endif
    {{-- Configuración por secciones: «Restablecer valor original» (Novedades y Mantenimiento de equipo PC) --}}
    @if ($puedeEditar && ($ajustesNov->nombrePropio($empresa->id) || $categoriaActual !== \App\Services\Novedades\AjustesNovedades::CATEGORIA_HALLAZGO_OMISION || $horasReloj !== \App\Services\Novedades\AjustesNovedades::RELOJ_HORAS))
        @include('administracion.configuracion._restablecer', ['ajuste' => 'novedades', 'que' => 'el nombre del módulo, la categoría del hallazgo y el reloj de los tickets'])
    @endif
</section>
