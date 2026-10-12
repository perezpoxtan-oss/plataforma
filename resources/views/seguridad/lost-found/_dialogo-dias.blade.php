{{--
    «Días de resguardo» desde el propio archivo de Lost & Found (decisión del
    dueño, 2026-10-12). Mismo dato y mismas reglas que Estructura →
    Configuración (ArchivoLostFound::guardarUmbrales). Editable con
    «lost_found.configurar» de alcance empresa; con alcance de sede, solo consulta.
    $diasResguardo = ['dias' => [...], 'guardados' => Collection, 'puedeEditar' => bool]
--}}
@php $trasErrorDias = old('_dialogo') === 'dias-lf'; @endphp
<dialog id="dialogoDiasLf" class="dialogo dialogo-novedad" aria-labelledby="titulo-dias-lf" @if ($trasErrorDias) data-abrir-al-cargar @endif>
    <div class="dialogo-cabecera">
        <h2 id="titulo-dias-lf"><i class="bi bi-hourglass-split me-2 text-warning" aria-hidden="true"></i>Días de resguardo</h2>
        <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </div>
    <div class="dialogo-cuerpo config-lost-found">
        {{-- Sin permiso de editar no hay formulario: solo la consulta --}}
        @if ($diasResguardo['puedeEditar'])
        <form action="{{ route('lost_found.umbrales.guardar') }}" method="POST" autocomplete="off">
            @csrf
            @method('PUT')
            <input type="hidden" name="_dialogo" value="dias-lf">
        @else
        <div>
        @endif
            @if ($trasErrorDias && $errors->any())
                <div class="alert alert-danger small py-2" role="alert">
                    @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
                </div>
            @endif
            <p class="small text-muted">Cuántos días puede estar un artículo en resguardo, según su clasificación, antes de que el semáforo lo marque. Aplica a todas las sedes de la empresa (es el mismo dato que en Estructura → Configuración).</p>
            @unless ($diasResguardo['puedeEditar'])
                <div class="alert alert-secondary small py-2"><i class="bi bi-lock-fill me-1" aria-hidden="true"></i>Los días son de toda la empresa: tu rol solo puede consultarlos. Para cambiarlos hace falta «Configurar» de Lost &amp; Found en toda la empresa.</div>
            @endunless
            @include('seguridad.lost-found._dias-campos', ['dias' => $diasResguardo['dias'], 'guardados' => $diasResguardo['guardados'], 'puedeEditar' => $diasResguardo['puedeEditar'], 'prefijo' => 'lf_'])
            <div class="dialogo-acciones">
                <button type="button" class="btn-cancelar" data-cerrar-dialogo>{{ $diasResguardo['puedeEditar'] ? 'Cancelar' : 'Cerrar' }}</button>
                @if ($diasResguardo['puedeEditar'])
                    <button type="submit" class="btn-despachar"><i class="bi bi-save-fill me-1" aria-hidden="true"></i>Guardar</button>
                @endif
            </div>
        @if ($diasResguardo['puedeEditar'])
        </form>
        @else
        </div>
        @endif
    </div>
</dialog>
