@extends('layouts.app')

@php
    $sing = $etiquetas['area_especifica']['singular'];
    // Al reabrir el diálogo de elemento tras un error, conserva el área elegida
    $reabrirElemento = old('_form') === 'dialogoNuevoElemento';
    $padreElemento = $reabrirElemento ? (int) old('padre_id') : ($areas->first()?->id ?? $nodo->id);
    $nombrePadreElemento = $areas->firstWhere('id', $padreElemento)?->nombre ?? $nodo->nombre;
@endphp

@section('titulo', $sing.' '.$nodo->nombre)

@section('contenido')
<div class="tema-rojo">
    @include('administracion.partes.avisos')
    @include('organizacion.espacios.partes.migas')

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div class="encabezado-pantalla m-0">
            <div class="icono"><i class="bi bi-door-open-fill text-danger" aria-hidden="true"></i></div>
            <div>
                <h1>{{ $sing }} {{ $nodo->nombre }} @unless ($nodo->activo)<span class="etiqueta-inactiva">INACTIVA</span>@endunless</h1>
                <p>
                    {{ $migas->pluck('nombre')->join(' · ') }}, {{ $nodo->sede->nombre }}
                    @if ($nodo->grupo) · <span class="chip-seccion"><i class="bi bi-bookmark-fill" aria-hidden="true"></i> {{ $nodo->grupo->nombre }}</span>@endif
                    @if ($nodo->tipo) · {{ $nodo->tipo->nombre }}@endif
                </p>
            </div>
        </div>
        <div class="d-flex gap-2 align-items-center">
            @include('organizacion.espacios.partes.acciones', ['e' => $nodo, 'dialogo' => 'dialogoEditarHabitacion', 'valores' => $nodo->only(['nombre', 'tipo_espacio_id', 'grupo_espacio_id'])])
        </div>
    </div>

    <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h5 fw-bold m-0">Áreas de esta {{ $sing }}</h2>
        @if ($puede['crear'] && $nodo->activo)
            <button type="button" class="btn btn-dark fw-bold" style="min-height:44px;border-radius:10px;" data-abrir-dialogo="dialogoNuevaArea"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i> Agregar Área</button>
        @endif
    </div>

    @if ($areas->isEmpty() && $elementosSueltos->isEmpty())
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-diagram-3" aria-hidden="true"></i></div>
            <p class="fw-semibold m-0">Esta {{ mb_strtolower($sing) }} aún no tiene áreas.</p>
            <p class="text-muted small m-0">Agrega sus áreas (Baño, Terraza, Recámara…) y dentro de cada una sus elementos (Lavabo, Cama, Minibar…).</p>
        </div>
    @endif

    <div class="fichas-grid" style="grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));">
        @foreach ($areas as $area)
            <section class="area-habitacion {{ $area->activo ? '' : 'inactiva' }}" aria-labelledby="area-{{ $area->id }}">
                <div class="area-cabecera">
                    <h3 id="area-{{ $area->id }}" class="h6 fw-bold m-0">
                        <i class="bi bi-bounding-box text-danger me-1" aria-hidden="true"></i>{{ $area->nombre }}
                        @if ($area->tipo && $area->tipo->nombre !== $area->nombre)<span class="text-muted small fw-semibold">({{ $area->tipo->nombre }})</span>@endif
                        @unless ($area->activo)<span class="etiqueta-inactiva ms-1">INACTIVA</span>@endunless
                    </h3>
                    @include('organizacion.espacios.partes.acciones', ['e' => $area, 'dialogo' => 'dialogoEditarArea', 'valores' => $area->only(['nombre', 'tipo_espacio_id'])])
                </div>

                @forelse ($area->hijos as $el)
                    <div class="elemento {{ $el->activo ? '' : 'inactivo' }}">
                        <span><i class="bi bi-dot" aria-hidden="true"></i>{{ $el->nombre }}
                            @if ($el->tipo && $el->tipo->nombre !== $el->nombre && ! str_starts_with($el->nombre, $el->tipo->nombre))<span class="text-muted">· {{ $el->tipo->nombre }}</span>@endif
                        </span>
                        @include('organizacion.espacios.partes.acciones', ['e' => $el, 'dialogo' => 'dialogoEditarElemento', 'valores' => $el->only(['nombre', 'tipo_espacio_id'])])
                    </div>
                @empty
                    <p class="text-muted small m-0">Sin elementos registrados.</p>
                @endforelse

                @if ($puede['crear'] && $area->activo)
                    <button type="button" class="btn-agregar-elemento" data-abrir-dialogo="dialogoNuevoElemento" data-padre="{{ $area->id }}" data-padre-nombre="{{ $area->nombre }}">
                        <i class="bi bi-plus" aria-hidden="true"></i> Agregar Elemento
                    </button>
                @endif
            </section>
        @endforeach

        @if ($elementosSueltos->isNotEmpty())
            <section class="area-habitacion" aria-labelledby="area-general">
                <div class="area-cabecera">
                    <h3 id="area-general" class="h6 fw-bold m-0"><i class="bi bi-box-seam text-danger me-1" aria-hidden="true"></i>Elementos generales</h3>
                </div>
                @foreach ($elementosSueltos as $el)
                    <div class="elemento {{ $el->activo ? '' : 'inactivo' }}">
                        <span><i class="bi bi-dot" aria-hidden="true"></i>{{ $el->nombre }}</span>
                        @include('organizacion.espacios.partes.acciones', ['e' => $el, 'dialogo' => 'dialogoEditarElemento', 'valores' => $el->only(['nombre', 'tipo_espacio_id'])])
                    </div>
                @endforeach
            </section>
        @endif
    </div>

    @if ($puede['crear'])
        {{-- Tipos propios: reemplaza el texto libre de SEGCAT por un catálogo por empresa --}}
        <details class="tarjeta mt-4 p-3">
            <summary class="fw-bold small"><i class="bi bi-tags me-1" aria-hidden="true"></i> ¿No encuentras el tipo de área o elemento? Agrégalo al catálogo de tu empresa</summary>
            <form action="{{ route('espacios.tipo') }}" method="POST" class="campo-grupo mt-3 align-items-end" autocomplete="off">
                @csrf
                <div style="flex:1">
                    <label class="campo-etiqueta" for="tipo_nivel">Es un</label>
                    <select id="tipo_nivel" name="nivel" class="campo">
                        <option value="subarea">Tipo de área</option>
                        <option value="elemento">Tipo de elemento</option>
                    </select>
                </div>
                <div style="flex:2">
                    <label class="campo-etiqueta" for="tipo_nombre">Nombre</label>
                    <input type="text" id="tipo_nombre" name="nombre" class="campo" maxlength="60" placeholder="Ej. Jacuzzi" required>
                </div>
                <div><button type="submit" class="btn-rojo" style="min-height:44px">Agregar tipo</button></div>
            </form>
        </details>

        @include('organizacion.espacios.partes.formulario', [
            'id' => 'dialogoNuevaArea', 'titulo' => 'Agregar Área', 'icono' => 'bi-bounding-box text-danger',
            'nivel' => 'subarea', 'padreId' => $nodo->id, 'tipos' => $tiposArea, 'tipoObligatorio' => true, 'nombreOpcional' => true,
            'editar' => false, 'boton' => 'Agregar Área', 'claseBoton' => 'btn-rojo', 'etiquetaNombre' => 'Etiqueta (opcional, ej. Baño principal)',
        ])
        @include('organizacion.espacios.partes.formulario', [
            'id' => 'dialogoNuevoElemento', 'titulo' => 'Agregar Elemento', 'icono' => 'bi-box-seam text-danger',
            'nivel' => 'elemento', 'padreId' => $padreElemento, 'padreNombre' => $nombrePadreElemento,
            'tipos' => $tiposElemento, 'tipoObligatorio' => true, 'nombreOpcional' => true,
            'editar' => false, 'boton' => 'Agregar Elemento', 'claseBoton' => 'btn-rojo', 'etiquetaNombre' => 'Etiqueta (opcional, ej. Cama King)',
        ])
    @endif
    @if ($puede['editar'])
        @include('organizacion.espacios.partes.formulario', [
            'id' => 'dialogoEditarHabitacion', 'titulo' => 'Editar '.$sing, 'icono' => 'bi-pencil-square text-danger',
            'nivel' => 'area_especifica', 'tipos' => $tiposHabitacion, 'secciones' => $secciones,
            'editar' => true, 'boton' => 'Guardar Cambios', 'claseBoton' => 'btn-rojo', 'etiquetaNombre' => 'Nombre o número',
            'extra' => '<input type="hidden" name="desde_detalle" value="1">',
        ])
        @include('organizacion.espacios.partes.formulario', [
            'id' => 'dialogoEditarArea', 'titulo' => 'Editar Área', 'icono' => 'bi-pencil-square text-danger',
            'nivel' => 'subarea', 'tipos' => $tiposArea, 'tipoObligatorio' => true, 'nombreOpcional' => true,
            'editar' => true, 'boton' => 'Guardar Cambios', 'claseBoton' => 'btn-rojo', 'etiquetaNombre' => 'Etiqueta',
        ])
        @include('organizacion.espacios.partes.formulario', [
            'id' => 'dialogoEditarElemento', 'titulo' => 'Editar Elemento', 'icono' => 'bi-pencil-square text-danger',
            'nivel' => 'elemento', 'tipos' => $tiposElemento, 'tipoObligatorio' => true, 'nombreOpcional' => true,
            'editar' => true, 'boton' => 'Guardar Cambios', 'claseBoton' => 'btn-rojo', 'etiquetaNombre' => 'Etiqueta',
        ])
    @endif
</div>
@endsection
