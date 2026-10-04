@extends('layouts.app')

@section('titulo', 'Departamentos')

@section('contenido')
<div class="tema-ambar">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    @if ($sinEmpresa)
        <div class="encabezado-pantalla mb-4">
            <div class="icono"><i class="bi bi-diagram-3-fill text-warning" aria-hidden="true"></i></div>
            <div><h1>Departamentos</h1><p>Áreas operativas de la Empresa.</p></div>
        </div>
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-buildings" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong> para ver sus departamentos.</p>
        </div>
    @else
        @php
            $dialogo = old('_dialogo');
            $editandoId = is_string($dialogo) && str_starts_with($dialogo, 'editar-') ? (int) substr($dialogo, 7) : null;
            $existentes = json_encode($departamentos->map(fn ($d) => mb_strtolower($d->nombre))->values());
            $variasSedes = $sedes->count() > 1;
        @endphp

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
            <div class="encabezado-pantalla m-0">
                <div class="icono"><i class="bi bi-diagram-3-fill text-warning" aria-hidden="true"></i></div>
                <div>
                    <h1>Departamentos</h1>
                    <p>Áreas operativas de la Empresa.</p>
                </div>
            </div>
            @if ($departamentos->count() > 1)
                <div class="buscador">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="search" placeholder="Buscar departamento..." aria-label="Buscar departamento" data-filtro-texto="departamentos">
                </div>
            @endif
        </div>

        <div class="fichas-grid" data-fichas="departamentos">
            @if ($puede['crear'])
                <button type="button" class="ficha-card ficha-create" data-abrir-dialogo="dialogoNuevoDepartamento">
                    <i class="bi bi-plus-circle-fill" aria-hidden="true"></i>
                    <span class="h6 fw-bold m-0 mt-2 titulo-crear">Nuevo Departamento</span>
                </button>
            @endif

            @forelse ($departamentos as $d)
                @php $valores = json_encode(['nombre' => $d->nombre, 'todas_las_sedes' => $d->todas_las_sedes, 'sedes' => $d->sedes->pluck('id')]); @endphp
                <div class="ficha-card" id="departamento-{{ $d->id }}" data-ficha data-estado="{{ $d->activo ? 1 : 0 }}" data-texto="{{ mb_strtolower($d->nombre) }}">
                    <div>
                        <h2 class="ficha-title">{{ $d->nombre }}</h2>
                        @if ($variasSedes || ! $d->todas_las_sedes)
                            <div class="small text-muted"><i class="bi bi-signpost-split" aria-hidden="true"></i>
                                @if ($d->todas_las_sedes)
                                    Todas las sedes
                                @else
                                    Solo en: {{ $d->sedes->pluck('nombre')->join(', ') ?: 'ninguna sede activa' }}
                                @endif
                            </div>
                        @endif
                        <div class="small text-muted"><i class="bi bi-person-badge" aria-hidden="true"></i> {{ $d->puestos_count }} puesto(s) ligado(s)</div>
                        @if ($d->creado_por_nombre)
                            <div class="texto-traza mt-2"><i class="bi bi-plus-circle" aria-hidden="true"></i> Creado por {{ $d->creado_por_nombre }} · @fecha($d->created_at)</div>
                        @endif
                        @if ($d->actualizado_por_nombre && $d->updated_at?->ne($d->created_at))
                            <div class="texto-traza"><i class="bi bi-clock-history" aria-hidden="true"></i> Editado por {{ $d->actualizado_por_nombre }} · @fecha($d->updated_at)</div>
                        @endif
                    </div>
                    <div class="ficha-footer">
                        <span class="etiqueta-estado {{ $d->activo ? 'activo' : 'inactivo' }}">{{ $d->activo ? 'ACTIVO' : 'INACTIVO' }}</span>
                        <div class="d-flex gap-2">
                            @if ($puede['editar'])
                                <button type="button" class="btn-icono editar" title="Editar" aria-label="Editar {{ $d->nombre }}"
                                        data-accion="editar-registro" data-dialogo="dialogoEditarDepartamento"
                                        data-url="{{ route('departamentos.update', $d->id) }}" data-id="{{ $d->id }}" data-valores="{{ $valores }}">
                                    <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                </button>
                            @endif
                            @if ($puede['estado'])
                                <form action="{{ route('departamentos.estado', $d->id) }}" method="POST" class="m-0"
                                      data-confirmar="{{ $d->activo ? '¿Desactivar el departamento «'.$d->nombre.'»? Podrás reactivarlo con el mismo botón.' : '¿Reactivar el departamento «'.$d->nombre.'»?' }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="activo" value="{{ $d->activo ? 0 : 1 }}">
                                    @if ($d->activo)
                                        <button type="submit" class="btn-icono eliminar" title="Desactivar" aria-label="Desactivar {{ $d->nombre }}"><i class="bi bi-slash-circle" aria-hidden="true"></i></button>
                                    @else
                                        <button type="submit" class="btn-icono reactivar" title="Reactivar" aria-label="Reactivar {{ $d->nombre }}"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></button>
                                    @endif
                                </form>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                @unless ($puede['crear'])
                    <div class="tarjeta estado-vacio"><p class="text-muted small m-0">Todavía no hay departamentos registrados.</p></div>
                @endunless
            @endforelse
        </div>
        <p class="text-muted text-center p-4" data-sin-resultados="departamentos" hidden><i class="bi bi-search me-2" aria-hidden="true"></i>No hay departamentos que coincidan con tu búsqueda.</p>

        {{-- ===== Alta y edición ===== --}}
        @foreach (['nuevo' => $puede['crear'], 'editar' => $puede['editar']] as $modo => $permitido)
            @continue (! $permitido)
            @php
                $esNuevo = $modo === 'nuevo';
                $reabrir = $esNuevo ? $dialogo === 'crear' : $editandoId !== null;
                $todas = $reabrir ? (bool) old('todas_las_sedes') : true;
                $marcadas = $reabrir ? array_map('intval', (array) old('sedes', [])) : [];
            @endphp
            <dialog id="{{ $esNuevo ? 'dialogoNuevoDepartamento' : 'dialogoEditarDepartamento' }}" class="dialogo" aria-labelledby="titulo-dep-{{ $modo }}" @if ($reabrir) data-abrir-al-cargar @endif>
                <div class="dialogo-cabecera">
                    <h2 id="titulo-dep-{{ $modo }}"><i class="bi {{ $esNuevo ? 'bi-diagram-3' : 'bi-pencil-square' }} me-2 text-warning" aria-hidden="true"></i>{{ $esNuevo ? 'Alta Departamento' : 'Actualizar Departamento' }}</h2>
                    <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
                <div class="dialogo-cuerpo">
                    <form action="{{ $esNuevo ? route('departamentos.store') : ($editandoId ? route('departamentos.update', $editandoId) : '') }}" method="POST" autocomplete="off">
                        @csrf
                        @unless ($esNuevo) @method('PUT') @endunless
                        <input type="hidden" name="_dialogo" value="{{ $esNuevo ? 'crear' : ($editandoId ? 'editar-'.$editandoId : '') }}" data-campo-dialogo>

                        <label class="campo-etiqueta" for="{{ $modo }}_dep_nombre">Nombre del Departamento</label>
                        <input type="text" id="{{ $modo }}_dep_nombre" name="nombre" class="campo" maxlength="100" placeholder="Ej. Recursos Humanos"
                               value="{{ $reabrir ? old('nombre') : '' }}" data-nombres-existentes="{{ $existentes }}" required>
                        <p data-aviso-nombre hidden></p>

                        @if ($variasSedes)
                            <span class="campo-etiqueta d-block">Sedes donde aplica</span>
                            <input type="hidden" name="todas_las_sedes" value="0">
                            <label class="opcion-todas">
                                <input type="checkbox" name="todas_las_sedes" value="1" @checked($todas) data-por-defecto data-oculta-si-marcado="#{{ $modo }}_lista_sedes">
                                <span><strong>Todas las sedes</strong><br><span class="small text-muted">También las que se abran después. Desmárcalo para elegir solo algunas.</span></span>
                            </label>
                            <div class="caja-checks" id="{{ $modo }}_lista_sedes" @if ($todas) hidden @endif>
                                @foreach ($sedes as $sede)
                                    <label class="fila-check"><input type="checkbox" name="sedes[]" value="{{ $sede->id }}" @checked(in_array($sede->id, $marcadas, true))> {{ $sede->nombre }}</label>
                                @endforeach
                            </div>
                        @else
                            <input type="hidden" name="todas_las_sedes" value="1">
                        @endif

                        <div class="dialogo-acciones">
                            <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                            <button type="submit" class="btn-ambar-degradado">{{ $esNuevo ? 'Guardar Departamento' : 'Guardar Cambios' }}</button>
                        </div>
                    </form>
                </div>
            </dialog>
        @endforeach
    @endif
</div>
@endsection
