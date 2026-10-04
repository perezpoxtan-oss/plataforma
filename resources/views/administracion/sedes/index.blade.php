@extends('layouts.app')

@section('titulo', $termino['plural'])

@section('contenido')
<div class="tema-verde">
    @include('administracion.partes.avisos')

    @include('administracion.partes.selector-empresa')

    @if ($sinEmpresa)
        <div class="encabezado-pantalla mb-4">
            <div class="icono"><i class="bi bi-house-door-fill text-success" aria-hidden="true"></i></div>
            <div><h1>Sedes</h1><p>Centros de trabajo de cada empresa.</p></div>
        </div>
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-buildings" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong> para ver y administrar sus sedes.</p>
        </div>
    @else
        @php
            $dialogo = old('_dialogo');
            $editandoId = is_string($dialogo) && str_starts_with($dialogo, 'editar-') ? (int) substr($dialogo, 7) : null;
            $singular = $termino['singular'];
            $plural = $termino['plural'];
        @endphp

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
            <div class="encabezado-pantalla m-0">
                <div class="icono"><i class="bi bi-house-door-fill text-success" aria-hidden="true"></i></div>
                <div>
                    <h1>Alta {{ $plural }}</h1>
                    <p>{{ $plural }} de {{ $empresa->nombre_comercial }}.</p>
                </div>
            </div>
            @if ($sedes->count() > 1)
                <div class="buscador">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="search" placeholder="Buscar por nombre, código o ciudad..." aria-label="Buscar {{ mb_strtolower($plural) }}" data-filtro-texto="sedes">
                </div>
            @endif
        </div>

        @if ($sedes->count() > 1)
            <div class="filtros-estado" role="group" aria-label="Filtrar por estado">
                <button type="button" class="btn btn-dark btn-sm fw-bold" data-filtro-estado="sedes" data-valor="todas" aria-pressed="true">Todos</button>
                <button type="button" class="btn btn-outline-success btn-sm fw-bold" data-filtro-estado="sedes" data-valor="1" aria-pressed="false">Activos</button>
                <button type="button" class="btn btn-outline-danger btn-sm fw-bold" data-filtro-estado="sedes" data-valor="0" aria-pressed="false">Inactivos</button>
            </div>
        @endif

        <div class="fichas-grid" data-fichas="sedes">
            @if ($puede['crear'])
                <button type="button" class="ficha-card ficha-create" data-abrir-dialogo="dialogoNuevaSede">
                    <i class="bi bi-plus-circle-fill" aria-hidden="true"></i>
                    <span class="h6 fw-bold m-0 mt-2 titulo-crear">{{ $termino['nuevo'] }} {{ $singular }}</span>
                </button>
            @endif

            @forelse ($sedes as $s)
                @php
                    $direccion = collect([$s->direccion, $s->colonia, $s->codigo_postal ? 'C.P. '.$s->codigo_postal : null])->filter()->implode(', ');
                    $valores = json_encode($s->only(['nombre', 'codigo', 'ciudad', 'entidad', 'direccion', 'colonia', 'codigo_postal', 'telefono', 'zona_horaria']));
                @endphp
                <div class="ficha-card" id="sede-{{ $s->id }}" data-ficha data-estado="{{ $s->activo ? 1 : 0 }}"
                     data-texto="{{ mb_strtolower($s->nombre.' '.$s->codigo.' '.$s->ciudad.' '.$s->entidad) }}">
                    <div>
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <h2 class="ficha-title m-0">{{ $s->nombre }}</h2>
                            <span class="badge-nivel" style="background:#dcfce7;color:#15803d;">{{ $s->codigo }}</span>
                        </div>
                        <span class="ficha-dato w-100"><i class="bi bi-geo-alt-fill text-danger" aria-hidden="true"></i> {{ collect([$s->ciudad, $s->entidad])->filter()->implode(', ') ?: 'Sin ciudad' }}</span>
                        @if ($direccion)
                            <div class="small text-muted mt-2"><i class="bi bi-signpost" aria-hidden="true"></i> {{ $direccion }}</div>
                        @endif
                        @if ($s->telefono)
                            <div class="small text-muted"><i class="bi bi-telephone" aria-hidden="true"></i> {{ $s->telefono }}</div>
                        @endif
                        <div class="small text-muted"><i class="bi bi-clock" aria-hidden="true"></i>
                            {{ $s->zona_horaria ? \App\Support\ZonasHorarias::etiqueta($s->zona_horaria) : 'Hora de la empresa ('.\App\Support\ZonasHorarias::etiqueta($empresa->zona_horaria).')' }}
                        </div>
                    </div>
                    <div class="mt-2">
                        @if ($s->creado_por_nombre)
                            <div class="texto-traza"><i class="bi bi-plus-circle" aria-hidden="true"></i> Creado por {{ $s->creado_por_nombre }} · @fecha($s->created_at)</div>
                        @endif
                        @if ($s->actualizado_por_nombre && $s->updated_at?->ne($s->created_at))
                            <div class="texto-traza"><i class="bi bi-clock-history" aria-hidden="true"></i> Editado por {{ $s->actualizado_por_nombre }} · @fecha($s->updated_at)</div>
                        @endif
                    </div>
                    <div class="ficha-footer">
                        <span class="etiqueta-estado {{ $s->activo ? 'activo' : 'inactivo' }}">{{ $s->activo ? 'ACTIVO' : 'INACTIVO' }}</span>
                        <div class="d-flex gap-2">
                            @if ($puede['editar'])
                                <button type="button" class="btn-icono editar" title="Editar" aria-label="Editar {{ $s->nombre }}"
                                        data-accion="editar-registro" data-dialogo="dialogoEditarSede"
                                        data-url="{{ route('sedes.update', $s->id) }}" data-id="{{ $s->id }}" data-valores="{{ $valores }}">
                                    <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                </button>
                            @endif
                            @if ($puede['estado'])
                                <form action="{{ route('sedes.estado', $s->id) }}" method="POST" class="m-0"
                                      data-confirmar="{{ $s->activo ? '¿Desactivar «'.$s->nombre.'»? Ya no aparecerá para nuevas capturas; su historial se conserva.' : '¿Reactivar «'.$s->nombre.'»?' }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="activo" value="{{ $s->activo ? 0 : 1 }}">
                                    @if ($s->activo)
                                        <button type="submit" class="btn-icono eliminar" title="Desactivar" aria-label="Desactivar {{ $s->nombre }}"><i class="bi bi-slash-circle" aria-hidden="true"></i></button>
                                    @else
                                        <button type="submit" class="btn-icono reactivar" title="Reactivar" aria-label="Reactivar {{ $s->nombre }}"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></button>
                                    @endif
                                </form>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                @unless ($puede['crear'])
                    <div class="tarjeta estado-vacio"><p class="text-muted small m-0">Todavía sin {{ mb_strtolower($plural) }}.</p></div>
                @endunless
            @endforelse
        </div>
        <p class="text-muted text-center p-4" data-sin-resultados="sedes" hidden><i class="bi bi-inbox me-2" aria-hidden="true"></i>No se encontraron {{ mb_strtolower($plural) }} con ese criterio.</p>

        {{-- ===== Alta y edición ===== --}}
        @foreach (['nueva' => $puede['crear'], 'editar' => $puede['editar']] as $modo => $permitido)
            @continue (! $permitido)
            @php
                $esNueva = $modo === 'nueva';
                $reabrir = $esNueva ? $dialogo === 'crear' : $editandoId !== null;
                $valor = fn (string $c) => $reabrir ? old($c) : '';
            @endphp
            <dialog id="{{ $esNueva ? 'dialogoNuevaSede' : 'dialogoEditarSede' }}" class="dialogo" aria-labelledby="titulo-sede-{{ $modo }}" @if ($reabrir) data-abrir-al-cargar @endif>
                <div class="dialogo-cabecera">
                    <h2 id="titulo-sede-{{ $modo }}"><i class="bi {{ $esNueva ? 'bi-house-door' : 'bi-pencil-square' }} me-2 text-success" aria-hidden="true"></i>{{ $esNueva ? $termino['nuevo'].' '.$singular : 'Editar '.$singular }}</h2>
                    <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
                <div class="dialogo-cuerpo">
                    <form action="{{ $esNueva ? route('sedes.store') : ($editandoId ? route('sedes.update', $editandoId) : '') }}" method="POST" autocomplete="off">
                        @csrf
                        @unless ($esNueva) @method('PUT') @endunless
                        <input type="hidden" name="_dialogo" value="{{ $esNueva ? 'crear' : ($editandoId ? 'editar-'.$editandoId : '') }}" data-campo-dialogo>

                        <div class="campo-grupo">
                            <div style="flex: 3">
                                <label class="campo-etiqueta" for="{{ $modo }}_nombre">Nombre</label>
                                <input type="text" id="{{ $modo }}_nombre" name="nombre" class="campo" maxlength="150" value="{{ $valor('nombre') }}" required>
                            </div>
                            <div style="flex: 1">
                                <label class="campo-etiqueta" for="{{ $modo }}_codigo">Código</label>
                                <input type="text" id="{{ $modo }}_codigo" name="codigo" class="campo text-uppercase" maxlength="10" pattern="[A-Za-z0-9\-]+" title="Solo letras, números y guiones, sin espacios ni acentos" value="{{ $valor('codigo') }}" required>
                            </div>
                        </div>
                        <div class="campo-grupo">
                            <div style="flex: 1">
                                <label class="campo-etiqueta" for="{{ $modo }}_ciudad">Ciudad</label>
                                <input type="text" id="{{ $modo }}_ciudad" name="ciudad" class="campo" maxlength="100" value="{{ $valor('ciudad') }}" required>
                            </div>
                            <div style="flex: 1">
                                <label class="campo-etiqueta" for="{{ $modo }}_entidad">Estado</label>
                                <input type="text" id="{{ $modo }}_entidad" name="entidad" class="campo" maxlength="100" value="{{ $valor('entidad') }}" required>
                            </div>
                        </div>
                        <div class="campo-grupo">
                            <div style="flex: 3">
                                <label class="campo-etiqueta" for="{{ $modo }}_direccion">Calle y Número <span class="text-lowercase fw-normal">(opcional)</span></label>
                                <input type="text" id="{{ $modo }}_direccion" name="direccion" class="campo" maxlength="255" value="{{ $valor('direccion') }}">
                            </div>
                            <div style="flex: 1">
                                <label class="campo-etiqueta" for="{{ $modo }}_telefono">Teléfono</label>
                                <input type="tel" inputmode="tel" id="{{ $modo }}_telefono" name="telefono" class="campo" maxlength="20" value="{{ $valor('telefono') }}">
                            </div>
                        </div>
                        <div class="campo-grupo">
                            <div style="flex: 3">
                                <label class="campo-etiqueta" for="{{ $modo }}_colonia">Colonia <span class="text-lowercase fw-normal">(opcional)</span></label>
                                <input type="text" id="{{ $modo }}_colonia" name="colonia" class="campo" maxlength="150" value="{{ $valor('colonia') }}">
                            </div>
                            <div style="flex: 1">
                                <label class="campo-etiqueta" for="{{ $modo }}_cp">C.P.</label>
                                <input type="text" inputmode="numeric" id="{{ $modo }}_cp" name="codigo_postal" class="campo" maxlength="5" value="{{ $valor('codigo_postal') }}">
                            </div>
                        </div>

                        <label class="campo-etiqueta" for="{{ $modo }}_zona">Zona Horaria</label>
                        <select id="{{ $modo }}_zona" name="zona_horaria" class="campo">
                            <option value="">La de la empresa ({{ \App\Support\ZonasHorarias::etiqueta($empresa->zona_horaria) }})</option>
                            @foreach ($zonas as $grupo => $opciones)
                                <optgroup label="{{ $grupo }}">
                                    @foreach ($opciones as $zona => $etiqueta)
                                        <option value="{{ $zona }}" @selected($valor('zona_horaria') === $zona)>{{ $etiqueta }}</option>
                                    @endforeach
                                </optgroup>
                            @endforeach
                        </select>
                        <p class="campo-ayuda mb-3"><i class="bi bi-info-circle" aria-hidden="true"></i> Cámbiala solo si {{ $termino['este'] }} {{ mb_strtolower($singular) }} está en otra zona horaria que la empresa: las bitácoras registran la hora local de cada sede.</p>

                        <div class="dialogo-acciones">
                            <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                            <button type="submit" class="btn-verde">{{ $esNueva ? 'Registrar '.$singular : 'Guardar Cambios' }}</button>
                        </div>
                    </form>
                </div>
            </dialog>
        @endforeach
    @endif
</div>
@endsection
