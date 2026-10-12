{{-- Configuración → «Bitácora de novedades» (ADR-0013): nombre en el menú y categoría del ticket de un hallazgo de recorrido. $empresa, $puedeEditar --}}
@php
    $ajustesNov = app(\App\Services\Novedades\AjustesNovedades::class);
    $nombreActual = $ajustesNov->nombreMenu($empresa->id);
    $categoriaActual = $ajustesNov->categoriaHallazgoPc($empresa);
    $trasErrorNov = $errors->hasAny(['nombre_menu', 'categoria_hallazgo_pc']);
@endphp
<section class="tarjeta p-4" id="novedades" aria-labelledby="t-novedades">
    <h2 id="t-novedades" class="h5 fw-bold"><i class="bi bi-headset me-2 text-danger" aria-hidden="true"></i>Bitácora de novedades</h2>
    <p class="small text-muted">Ajustes de {{ $empresa->nombre_comercial }}. Si no cambias nada, todo se ve y funciona como siempre.</p>
    @unless ($puedeEditar)
        <div class="alert alert-secondary small py-2"><i class="bi bi-lock-fill me-1" aria-hidden="true"></i>Tu rol solo puede consultar esta configuración, no modificarla.</div>
    @endunless
    <form action="{{ route('configuracion.novedades') }}" method="POST">
        @csrf
        @method('PUT')
        <label class="campo-etiqueta" for="nov_nombre_menu">Nombre en el menú <span class="text-lowercase fw-normal">(por omisión «Novedades»)</span></label>
        <input type="text" id="nov_nombre_menu" name="nombre_menu" class="campo @error('nombre_menu') is-invalid @enderror" maxlength="40" list="nov_nombres_sugeridos"
               value="{{ $trasErrorNov ? old('nombre_menu') : ($nombreActual === \App\Services\Novedades\AjustesNovedades::NOMBRE_OMISION ? '' : $nombreActual) }}"
               placeholder="Novedades" @disabled(! $puedeEditar)>
        <datalist id="nov_nombres_sugeridos">
            @foreach (\App\Services\Novedades\AjustesNovedades::NOMBRES_SUGERIDOS as $sugerido)
                <option value="{{ $sugerido }}">
            @endforeach
        </datalist>
        @error('nombre_menu')<p class="text-danger small mb-2">{{ $message }}</p>@enderror
        <p class="campo-ayuda mt-0">Por ejemplo «Incidentes». Cambia el nombre en el menú, en el botón de abajo del celular y en el título de la pantalla; los permisos y las direcciones no cambian.</p>

        <label class="campo-etiqueta" for="nov_categoria_hallazgo">Cuando un Recorrido de Protección Civil encuentra una falla, abrir el ticket como</label>
        <select id="nov_categoria_hallazgo" name="categoria_hallazgo_pc" class="campo" @disabled(! $puedeEditar)>
            @foreach (\App\Services\Novedades\AjustesNovedades::CATEGORIAS_HALLAZGO as $clave => $texto)
                <option value="{{ $clave }}" @selected(($trasErrorNov ? old('categoria_hallazgo_pc') : $categoriaActual) === $clave)>{{ $texto }}</option>
            @endforeach
        </select>
        <p class="campo-ayuda mt-0">«Mantenimiento de equipo PC» evita que un extintor despresurizado parezca una emergencia real. Los tickets que ya existen no cambian.</p>
        @if ($puedeEditar)
            <button type="submit" class="btn-azul mt-2" style="min-height:44px;border-radius:10px;padding:0 1.25rem;">Guardar ajustes de la bitácora</button>
        @endif
    </form>
</section>
