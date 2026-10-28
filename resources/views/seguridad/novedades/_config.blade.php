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
        <p class="campo-ayuda mt-0">«Mantenimiento de equipo PC» evita que un extintor despresurizado parezca una emergencia real. Los tickets que ya existen no cambian. <span class="valor-original">Valor original: Siniestro Protección Civil.</span></p>
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
    {{-- Configuración por secciones: «Restablecer valor original» (Novedades y Siniestro Protección Civil) --}}
    @if ($puedeEditar && ($ajustesNov->nombrePropio($empresa->id) || $categoriaActual !== \App\Services\Novedades\AjustesNovedades::CATEGORIA_HALLAZGO_OMISION || $horasReloj !== \App\Services\Novedades\AjustesNovedades::RELOJ_HORAS))
        @include('administracion.configuracion._restablecer', ['ajuste' => 'novedades', 'que' => 'el nombre del módulo, la categoría del hallazgo y el reloj de los tickets'])
    @endif
</section>
