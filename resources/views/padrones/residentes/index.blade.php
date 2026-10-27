@extends('layouts.app')

@section('titulo', 'Residentes')

@section('contenido')
{{-- C-10 (DD-14): residentes con su vivienda. El personal de la administración está en Estructura → Organización. --}}
<div class="tema-cian">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    @if ($sinEmpresa)
        <div class="encabezado-pantalla mb-4">
            <div class="icono"><i class="bi bi-house-heart-fill text-success" aria-hidden="true"></i></div>
            <div><h1>Residentes</h1><p>Quién vive en cada vivienda.</p></div>
        </div>
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-buildings" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong> para ver sus residentes.</p>
        </div>
    @else
        @php
            $dialogo = old('_dialogo');
            $editandoId = is_string($dialogo) && str_starts_with($dialogo, 'editar-') ? (int) substr($dialogo, 7) : null;
            $vivienda = mb_strtolower($terminos->espacio());
        @endphp

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
            <div class="encabezado-pantalla m-0">
                <div class="icono"><i class="bi bi-house-heart-fill text-success" aria-hidden="true"></i></div>
                <div>
                    <h1>Residentes</h1>
                    <p>Quién vive en cada {{ $vivienda }}. La caseta los encuentra en «¿A quién viene?» y les llama para autorizar a sus visitas.</p>
                    <p class="campo-ayuda m-0"><i class="bi bi-lightbulb" aria-hidden="true"></i> El personal de la administración (guardias, mantenimiento) se registra en <strong>Estructura → Personal</strong>.</p>
                </div>
            </div>
            @if ($residentes->count() > 1)
                <div class="buscador">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="search" placeholder="Buscar residente o {{ $vivienda }}..." aria-label="Buscar residente" data-filtro-texto="residentes">
                </div>
            @endif
        </div>

        <div class="fichas-grid" data-fichas="residentes">
            @if ($puede['crear'])
                <button type="button" class="ficha-card ficha-create" data-abrir-dialogo="dialogoNuevoResidente">
                    <i class="bi bi-plus-circle-fill" aria-hidden="true"></i>
                    <span class="h6 fw-bold m-0 mt-2 titulo-crear">Nuevo residente</span>
                </button>
            @endif

            @forelse ($residentes as $r)
                @php
                    $camino = $r->espacio_id ? ($caminos[$r->espacio_id]['ruta'] ?? $r->vivienda?->nombre) : null;
                    $valores = json_encode(['nombre' => $r->nombre, 'apellido_paterno' => $r->apellido_paterno, 'apellido_materno' => $r->apellido_materno,
                        'telefono' => $r->telefono, 'espacio_id' => $r->espacio_id]);
                    $editable = $puede['editar'] && ($editables === null || in_array($r->id, $editables, true));
                    $desactivable = $puede['estado'] && ($desactivables === null || in_array($r->id, $desactivables, true));
                @endphp
                <div class="ficha-card" id="residente-{{ $r->id }}" data-ficha data-estado="{{ $r->activo ? 1 : 0 }}"
                     data-texto="{{ mb_strtolower($r->nombreCompleto().' '.$camino.' '.$r->telefono) }}">
                    <div>
                        <h2 class="ficha-title m-0">{{ $r->nombreCompleto() }}</h2>
                        <div class="d-flex flex-wrap gap-1 mt-2">
                            @if ($camino)
                                <span class="chip-catalogo"><i class="bi bi-house-door" aria-hidden="true"></i> {{ $camino }}</span>
                            @else
                                <span class="chip-catalogo inactivo"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Sin {{ $vivienda }}: complétala</span>
                            @endif
                            @if ($r->sede)
                                <span class="chip-catalogo"><i class="bi bi-geo-alt" aria-hidden="true"></i> {{ $r->sede->nombre }}</span>
                            @endif
                        </div>
                        @if ($r->telefono)
                            <div class="small mt-2"><i class="bi bi-telephone" aria-hidden="true"></i> <a href="tel:{{ preg_replace('/[^0-9+]/', '', $r->telefono) }}">{{ $r->telefono }}</a></div>
                        @endif
                    </div>
                    <div class="ficha-footer">
                        <span class="etiqueta-estado {{ $r->activo ? 'activo' : 'inactivo' }}">{{ $r->activo ? 'ACTIVO' : 'DE BAJA' }}</span>
                        <div class="d-flex gap-2">
                            @include('componentes.ver-historial', ['registro' => \App\Models\Colaborador::class, 'id' => $r->id, 'nombre' => $r->nombreCompleto()])
                            @if ($editable)
                                <button type="button" class="btn-icono editar" title="Editar" aria-label="Editar {{ $r->nombreCompleto() }}"
                                        data-accion="editar-registro" data-dialogo="dialogoEditarResidente"
                                        data-url="{{ route('residentes.update', $r->id) }}" data-id="{{ $r->id }}" data-valores="{{ $valores }}">
                                    <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                </button>
                            @endif
                            @if ($desactivable)
                                <form action="{{ route('residentes.estado', $r->id) }}" method="POST" class="m-0"
                                      data-confirmar="{{ $r->activo ? '¿Dar de baja a «'.$r->nombreCompleto().'»? La caseta ya no lo ofrecerá. Podrás reactivarlo con el mismo botón.' : '¿Reactivar a «'.$r->nombreCompleto().'»?' }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="activo" value="{{ $r->activo ? 0 : 1 }}">
                                    @if ($r->activo)
                                        <button type="submit" class="btn-icono eliminar" title="Dar de baja" aria-label="Dar de baja {{ $r->nombreCompleto() }}"><i class="bi bi-slash-circle" aria-hidden="true"></i></button>
                                    @else
                                        <button type="submit" class="btn-icono reactivar" title="Reactivar" aria-label="Reactivar {{ $r->nombreCompleto() }}"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></button>
                                    @endif
                                </form>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                @unless ($puede['crear'])
                    <div class="tarjeta estado-vacio"><p class="text-muted small m-0">Todavía no hay residentes registrados.</p></div>
                @endunless
            @endforelse
        </div>
        <p class="text-muted text-center p-4" data-sin-resultados="residentes" hidden><i class="bi bi-search me-2" aria-hidden="true"></i>No hay residentes que coincidan con tu búsqueda.</p>

        {{-- ===== Alta corta (regla 8) y edición ===== --}}
        @foreach (['nuevo' => $puede['crear'], 'editar' => $puede['editar']] as $modo => $permitido)
            @continue (! $permitido)
            @php
                $esNuevo = $modo === 'nuevo';
                $reabrir = $esNuevo ? $dialogo === 'crear' : $editandoId !== null;
                $viviendas = $esNuevo ? $viviendasAlta : $viviendasEdicion;
                $varias = $viviendas->pluck('sede_id')->unique()->count() > 1;
            @endphp
            <dialog id="{{ $esNuevo ? 'dialogoNuevoResidente' : 'dialogoEditarResidente' }}" class="dialogo" aria-labelledby="titulo-res-{{ $modo }}" @if ($reabrir) data-abrir-al-cargar @endif>
                <div class="dialogo-cabecera">
                    <h2 id="titulo-res-{{ $modo }}"><i class="bi {{ $esNuevo ? 'bi-house-add' : 'bi-pencil-square' }} me-2 text-success" aria-hidden="true"></i>{{ $esNuevo ? 'Nuevo residente' : 'Editar residente' }}</h2>
                    <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
                <div class="dialogo-cuerpo">
                    <form action="{{ $esNuevo ? route('residentes.store') : ($editandoId ? route('residentes.update', $editandoId) : '') }}" method="POST" autocomplete="off">
                        @csrf
                        @unless ($esNuevo) @method('PUT') @endunless
                        <input type="hidden" name="_dialogo" value="{{ $esNuevo ? 'crear' : ($editandoId ? 'editar-'.$editandoId : '') }}" data-campo-dialogo>
                        @if ($reabrir && $errors->any())
                            <div class="alert alert-danger small py-2 px-3" role="alert"><ul class="mb-0 ps-3">@foreach ($errors->all() as $m)<li>{{ $m }}</li>@endforeach</ul></div>
                        @endif

                        <label class="campo-etiqueta" for="{{ $modo }}_res_vivienda">{{ $terminos->espacio() }} *</label>
                        @if ($viviendas->isEmpty())
                            <p class="small text-muted">Aún no hay {{ mb_strtolower($terminos->espacios()) }} en Zonas y edificios de tus sedes. Créalas primero en Estructura → Zonas y edificios.</p>
                        @endif
                        <select id="{{ $modo }}_res_vivienda" name="espacio_id" class="campo" required>
                            <option value="">-- Elige la {{ $vivienda }} --</option>
                            @foreach ($viviendas as $v)
                                <option value="{{ $v['id'] }}" @selected($reabrir && (string) old('espacio_id') === (string) $v['id'])>{{ $v['ruta'] }}</option>
                            @endforeach
                        </select>
                        @if ($varias)<p class="campo-ayuda mt-0">La sede sale sola de la {{ $vivienda }}.</p>@endif

                        <div class="row">
                            <div class="col-md-4">
                                <label class="campo-etiqueta" for="{{ $modo }}_res_nombre">Nombre *</label>
                                <input type="text" id="{{ $modo }}_res_nombre" name="nombre" class="campo" maxlength="100" required value="{{ $reabrir ? old('nombre') : '' }}">
                            </div>
                            <div class="col-md-4">
                                <label class="campo-etiqueta" for="{{ $modo }}_res_paterno">Primer apellido *</label>
                                <input type="text" id="{{ $modo }}_res_paterno" name="apellido_paterno" class="campo" maxlength="100" required value="{{ $reabrir ? old('apellido_paterno') : '' }}">
                            </div>
                            <div class="col-md-4">
                                <label class="campo-etiqueta" for="{{ $modo }}_res_materno">Segundo apellido</label>
                                <input type="text" id="{{ $modo }}_res_materno" name="apellido_materno" class="campo" maxlength="100" value="{{ $reabrir ? old('apellido_materno') : '' }}">
                            </div>
                        </div>
                        <label class="campo-etiqueta" for="{{ $modo }}_res_telefono">Teléfono <span class="text-lowercase fw-normal">(la caseta le llama para autorizar a sus visitas)</span></label>
                        <input type="tel" id="{{ $modo }}_res_telefono" name="telefono" class="campo campo-corto" maxlength="20" inputmode="tel" value="{{ $reabrir ? old('telefono') : '' }}">

                        <div class="dialogo-acciones">
                            <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                            <button type="submit" class="btn-cian">Guardar</button>
                        </div>
                    </form>
                </div>
            </dialog>
        @endforeach
    @endif
</div>
@endsection
