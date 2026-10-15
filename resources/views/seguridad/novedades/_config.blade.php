{{-- Configuración → «Bitácora de novedades» (ADR-0013): nombre del módulo y categoría del ticket de un hallazgo de recorrido. $empresa, $puedeEditar --}}
@php
    $ajustesNov = app(\App\Services\Novedades\AjustesNovedades::class);
    $nombreActual = $ajustesNov->nombreMenu($empresa->id);
    $categoriaActual = $ajustesNov->categoriaHallazgoPc($empresa);
    $trasErrorNov = $errors->hasAny(['nombre_opcion', 'nombre_menu', 'categoria_hallazgo_pc']);
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
        @if ($puedeEditar)
            <button type="submit" class="btn-azul mt-2" style="min-height:44px;border-radius:10px;padding:0 1.25rem;">Guardar ajustes de la bitácora</button>
        @endif
    </form>
    {{-- Configuración por secciones: «Restablecer valor original» (Novedades y Siniestro Protección Civil) --}}
    @if ($puedeEditar && ($ajustesNov->nombrePropio($empresa->id) || $categoriaActual !== \App\Services\Novedades\AjustesNovedades::CATEGORIA_HALLAZGO_OMISION))
        @include('administracion.configuracion._restablecer', ['ajuste' => 'novedades', 'que' => 'el nombre del módulo y la categoría del hallazgo'])
    @endif
</section>
