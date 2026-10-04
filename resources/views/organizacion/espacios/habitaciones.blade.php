@extends('layouts.app')

@php
    $sing = $etiquetas['area_especifica']['singular'];
    $plur = $etiquetas['area_especifica']['plural'];
@endphp

@section('titulo', $plur.' — '.$nodo->nombre)

@section('contenido')
<div class="tema-ambar">
    @include('administracion.partes.avisos')
    @include('organizacion.espacios.partes.migas')

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div class="encabezado-pantalla m-0">
            <div class="icono"><i class="bi bi-door-closed-fill" style="color:#b45309" aria-hidden="true"></i></div>
            <div>
                <h1>{{ $plur }} — {{ $nodo->nombre }} @unless ($nodo->activo)<span class="etiqueta-inactiva">INACTIVO</span>@endunless</h1>
                <p>{{ $migas->last()?->nombre }}, {{ $nodo->sede->nombre }}</p>
            </div>
        </div>
        @if ($habitaciones->count() > 6)
            <div class="buscador">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="search" placeholder="Buscar {{ mb_strtolower($sing) }} o sección..." aria-label="Buscar" data-filtro-texto="habitaciones">
            </div>
        @endif
    </div>

    <div class="fichas-grid" style="grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));" data-fichas="habitaciones">
        @if ($puede['crear'] && $nodo->activo)
            <button type="button" class="ficha-card ficha-create" data-abrir-dialogo="dialogoNuevaHabitacion">
                <i class="bi bi-plus-circle-fill" aria-hidden="true"></i>
                <span class="h6 fw-bold m-0 mt-2 titulo-crear">Nueva {{ $sing }}</span>
            </button>
            <button type="button" class="ficha-card ficha-create" style="border-color:rgba(15,23,42,.25);background:rgba(15,23,42,.02)" data-abrir-dialogo="dialogoLote">
                <i class="bi bi-stack" style="color:#0f172a" aria-hidden="true"></i>
                <span class="h6 fw-bold m-0 mt-2 text-dark">Crear por Lote</span>
            </button>
        @endif

        @forelse ($habitaciones as $h)
            <div class="ficha-card ficha-compacta" data-ficha data-estado="{{ $h->activo ? 1 : 0 }}" data-texto="{{ mb_strtolower($h->nombre.' '.$h->grupo?->nombre) }}" @unless ($h->activo) style="opacity:.6" @endunless>
                <div>
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <h2 class="h6 fw-bold m-0"><i class="bi bi-door-closed me-1" style="color:#b45309" aria-hidden="true"></i>{{ $h->nombre }}
                            @unless ($h->activo)<span class="etiqueta-inactiva ms-1">INACTIVA</span>@endunless
                        </h2>
                        @include('organizacion.espacios.partes.acciones', ['e' => $h, 'dialogo' => 'dialogoEditarHabitacion', 'valores' => $h->only(['nombre', 'tipo_espacio_id', 'grupo_espacio_id'])])
                    </div>
                    <div class="d-flex flex-wrap gap-1 mt-2">
                        @if ($h->grupo)<span class="chip-seccion"><i class="bi bi-bookmark-fill" aria-hidden="true"></i> {{ $h->grupo->nombre }}</span>@endif
                        @if ($h->tipo)<span class="chip-seccion">{{ $h->tipo->nombre }}</span>@endif
                    </div>
                    <div class="small text-muted mt-2"><i class="bi bi-diagram-3" aria-hidden="true"></i> {{ $h->areas_count }} área(s)</div>
                </div>
                <a href="{{ route('espacios.show', $h->id) }}" class="btn-entrar"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> Ver detalle</a>
            </div>
        @empty
            @unless ($puede['crear'])
                <div class="tarjeta estado-vacio"><p class="text-muted small m-0">Este piso todavía no tiene {{ mb_strtolower($plur) }}.</p></div>
            @endunless
        @endforelse
    </div>
    <p class="text-muted text-center p-4" data-sin-resultados="habitaciones" hidden>No hay coincidencias.</p>

    @if ($puede['crear'])
        @include('organizacion.espacios.partes.formulario', [
            'id' => 'dialogoNuevaHabitacion', 'titulo' => 'Nueva '.$sing, 'icono' => 'bi-door-closed',
            'nivel' => 'area_especifica', 'padreId' => $nodo->id, 'tipos' => $tiposHabitacion, 'secciones' => $secciones,
            'editar' => false, 'boton' => 'Agregar', 'claseBoton' => 'btn-ambar', 'etiquetaNombre' => 'Nombre o número (ej. 101)',
        ])

        @php $loteReabrir = old('_dialogo') === 'lote'; $modo = $loteReabrir ? old('modo', 'rango') : 'rango'; @endphp
        <dialog id="dialogoLote" class="dialogo" aria-labelledby="tituloLote" @if ($loteReabrir) data-abrir-al-cargar @endif>
            <div class="dialogo-cabecera">
                <h2 id="tituloLote"><i class="bi bi-stack me-2" aria-hidden="true"></i>Crear {{ $plur }} por Lote</h2>
                <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </div>
            <div class="dialogo-cuerpo">
                <form action="{{ route('espacios.lote', $nodo->id) }}" method="POST" autocomplete="off" data-lote>
                    @csrf
                    <input type="hidden" name="_dialogo" value="lote">
                    <input type="hidden" name="modo" value="{{ $modo }}" data-modo-actual>
                    <div class="lote-modos" role="group" aria-label="Forma de capturar">
                        <button type="button" class="btn btn-sm fw-bold {{ $modo === 'rango' ? 'btn-dark' : 'btn-outline-dark' }}" data-modo-lote="rango">Rango numérico</button>
                        <button type="button" class="btn btn-sm fw-bold {{ $modo === 'lista' ? 'btn-dark' : 'btn-outline-dark' }}" data-modo-lote="lista">Lista de nombres</button>
                    </div>

                    <div data-panel-lote="rango" @if ($modo !== 'rango') hidden @endif>
                        <div class="campo-grupo">
                            <div style="flex:1"><label class="campo-etiqueta" for="lote_prefijo">Prefijo <span class="text-lowercase fw-normal">(opcional)</span></label><input type="text" id="lote_prefijo" name="prefijo" class="campo" maxlength="20" value="{{ $loteReabrir ? old('prefijo') : '' }}"></div>
                            <div style="flex:1"><label class="campo-etiqueta" for="lote_desde">Desde</label><input type="number" id="lote_desde" name="rango_desde" class="campo" min="0" placeholder="1" value="{{ $loteReabrir ? old('rango_desde') : '' }}"></div>
                            <div style="flex:1"><label class="campo-etiqueta" for="lote_hasta">Hasta</label><input type="number" id="lote_hasta" name="rango_hasta" class="campo" min="0" placeholder="20" value="{{ $loteReabrir ? old('rango_hasta') : '' }}"></div>
                        </div>
                        <div class="form-check mb-2">
                            <input type="checkbox" class="form-check-input" id="lote_ceros" name="relleno_ceros" value="1" @checked(! $loteReabrir || old('relleno_ceros'))>
                            <label class="form-check-label small" for="lote_ceros">Rellenar con ceros (01, 02…)</label>
                        </div>
                        <p class="campo-ayuda mb-3">Ejemplo: prefijo "10", desde 1 hasta 5, con ceros → 1001, 1002, 1003, 1004, 1005. Máximo 500 por lote.</p>
                    </div>
                    <div data-panel-lote="lista" @if ($modo !== 'lista') hidden @endif>
                        <label class="campo-etiqueta" for="lote_lista">Un nombre por renglón (o separados por coma)</label>
                        <textarea id="lote_lista" name="lista_nombres" class="campo" rows="6" placeholder="Suite A&#10;Suite B&#10;Suite C">{{ $loteReabrir ? old('lista_nombres') : '' }}</textarea>
                    </div>

                    <div class="campo-grupo">
                        <div style="flex:1">
                            <label class="campo-etiqueta" for="lote_seccion">Sección <span class="text-lowercase fw-normal">(opcional)</span></label>
                            <select id="lote_seccion" name="grupo_espacio_id" class="campo">
                                <option value="">— Sin sección —</option>
                                @foreach ($secciones as $s)<option value="{{ $s->id }}">{{ $s->nombre }}</option>@endforeach
                            </select>
                        </div>
                        <div style="flex:1">
                            <label class="campo-etiqueta" for="lote_tipo">Tipo</label>
                            <select id="lote_tipo" name="tipo_espacio_id" class="campo">
                                <option value="">— Sin especificar —</option>
                                @foreach ($tiposHabitacion as $t)<option value="{{ $t->id }}">{{ $t->nombre }}</option>@endforeach
                            </select>
                        </div>
                    </div>
                    <p class="campo-ayuda mb-3">Los nombres que ya existen en este piso se omiten; no detienen el lote.</p>
                    <div class="dialogo-acciones">
                        <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                        <button type="submit" class="btn-ambar">Crear {{ $plur }}</button>
                    </div>
                </form>
            </div>
        </dialog>
    @endif
    @if ($puede['editar'])
        @include('organizacion.espacios.partes.formulario', [
            'id' => 'dialogoEditarHabitacion', 'titulo' => 'Editar '.$sing, 'icono' => 'bi-pencil-square',
            'nivel' => 'area_especifica', 'tipos' => $tiposHabitacion, 'secciones' => $secciones,
            'editar' => true, 'boton' => 'Guardar Cambios', 'claseBoton' => 'btn-ambar', 'etiquetaNombre' => 'Nombre o número',
        ])
    @endif
</div>
@endsection
