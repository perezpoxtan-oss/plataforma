{{--
    Diálogo de alta o edición de un espacio.
    Parámetros: $id, $titulo, $icono, $nivel, $padreId (o $sedes para edificios), $tipos, $conCodigo, $secciones (opcional),
                $editar (bool), $boton, $claseBoton, $etiquetaNombre, $extra (campos ocultos opcionales),
                $tipoObligatorio, $nombreOpcional (si va vacío se usa el nombre del tipo), $padreNombre (texto visible del contenedor)
--}}
@php
    $dialogo = old('_dialogo');
    $reabrir = $editar ? (is_string($dialogo) && str_starts_with($dialogo, 'editar-') && old('_form') === $id) : $dialogo === 'crear-'.$id;
    $editId = $reabrir && $editar ? (int) substr($dialogo, 7) : null;
    $v = fn ($c) => $reabrir ? old($c) : '';
@endphp
<dialog id="{{ $id }}" class="dialogo" aria-labelledby="titulo-{{ $id }}" @if ($reabrir) data-abrir-al-cargar @endif>
    <div class="dialogo-cabecera">
        <h2 id="titulo-{{ $id }}"><i class="bi {{ $icono }} me-2" aria-hidden="true"></i>{{ $titulo }}</h2>
        <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </div>
    <div class="dialogo-cuerpo">
        <form action="{{ $editar ? ($editId ? route('espacios.update', $editId) : '') : route('espacios.store') }}" method="POST" autocomplete="off">
            @csrf
            @if ($editar) @method('PUT') @endif
            <input type="hidden" name="_form" value="{{ $id }}">
            <input type="hidden" name="_dialogo" value="{{ $editar ? ($editId ? 'editar-'.$editId : '') : 'crear-'.$id }}" @if ($editar) data-campo-dialogo @endif>
            @unless ($editar)
                <input type="hidden" name="nivel" value="{{ $nivel }}">
                @if (! empty($padreId))
                    <input type="hidden" name="padre_id" value="{{ $padreId }}" data-campo-padre>
                @endif
            @endunless
            {!! $extra ?? '' !!}
            @if (isset($padreNombre))
                <p class="campo-ayuda mb-3"><i class="bi bi-arrow-return-right" aria-hidden="true"></i> Dentro de: <strong data-padre-nombre>{{ $padreNombre }}</strong></p>
            @endif

            @if (! $editar && isset($sedes))
                @if ($sedes->count() > 1)
                    <label class="campo-etiqueta" for="{{ $id }}_sede">Sede</label>
                    <select id="{{ $id }}_sede" name="sede_id" class="campo" required>
                        @foreach ($sedes as $sede)
                            <option value="{{ $sede->id }}" @selected((string) $v('sede_id') === (string) $sede->id)>{{ $sede->nombre }}</option>
                        @endforeach
                    </select>
                @else
                    <input type="hidden" name="sede_id" value="{{ $sedes->first()?->id }}">
                @endif
            @endif

            <div class="campo-grupo">
                <div style="flex: 3">
                    <label class="campo-etiqueta" for="{{ $id }}_nombre">{{ $etiquetaNombre ?? 'Nombre' }}</label>
                    <input type="text" id="{{ $id }}_nombre" name="nombre" class="campo" maxlength="100" value="{{ $v('nombre') }}" @if ($nombreOpcional ?? false) placeholder="Si lo dejas vacío se usa el tipo" @else required @endif>
                </div>
                @if ($conCodigo ?? false)
                    <div style="flex: 1">
                        <label class="campo-etiqueta" for="{{ $id }}_codigo">Código <span class="text-lowercase fw-normal">(opcional)</span></label>
                        <input type="text" id="{{ $id }}_codigo" name="codigo" class="campo text-uppercase" maxlength="20" pattern="[A-Za-z0-9\-]+" title="Solo letras, números y guiones" value="{{ $v('codigo') }}">
                    </div>
                @endif
            </div>

            @if ($tipos->isNotEmpty())
                <label class="campo-etiqueta" for="{{ $id }}_tipo">Tipo</label>
                <select id="{{ $id }}_tipo" name="tipo_espacio_id" class="campo" @if ($tipoObligatorio ?? false) required @endif>
                    @unless ($tipoObligatorio ?? false)<option value="">— Sin especificar —</option>@endunless
                    @foreach ($tipos as $tipo)
                        <option value="{{ $tipo->id }}" @selected((string) $v('tipo_espacio_id') === (string) $tipo->id)>{{ $tipo->nombre }}</option>
                    @endforeach
                </select>
            @endif

            @if (isset($secciones))
                <label class="campo-etiqueta" for="{{ $id }}_seccion">Sección <span class="text-lowercase fw-normal">(opcional)</span></label>
                <select id="{{ $id }}_seccion" name="grupo_espacio_id" class="campo">
                    <option value="">— Sin sección —</option>
                    @foreach ($secciones as $seccion)
                        <option value="{{ $seccion->id }}" @selected((string) $v('grupo_espacio_id') === (string) $seccion->id)>{{ $seccion->nombre }}</option>
                    @endforeach
                </select>
                <p class="campo-ayuda mb-3">Las secciones agrupan {{ mb_strtolower($etiquetas['area_especifica']['plural']) }} (Torre Norte, Villas…). Se crean en Zonas y áreas → pestaña Secciones.</p>
            @endif

            <div class="dialogo-acciones">
                <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                <button type="submit" class="{{ $claseBoton }}">{{ $boton }}</button>
            </div>
        </form>
        @if ($editar)
            @include('componentes.borrar', ['registro' => 'espacios', 'id' => $editId])
        @endif
    </div>
</dialog>
