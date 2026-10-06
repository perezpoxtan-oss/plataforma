@extends('layouts.app')

@section('titulo', 'Empresas Externas')

@section('contenido')
<div class="tema-esmeralda pantalla-proveedores">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    @if ($sinEmpresa)
        <div class="encabezado-pantalla mb-4">
            <div class="icono"><i class="bi bi-truck text-success" aria-hidden="true"></i></div>
            <div><h1>Empresas Externas</h1><p>Directorio de Proveedores, Contratistas y Agencias.</p></div>
        </div>
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-buildings" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong> para ver sus empresas externas.</p>
        </div>
    @else
        @php
            $estilos = \App\Http\Controllers\Padrones\ProveedorController::ESTILOS;
            $dialogo = old('_dialogo');
            $editandoId = is_string($dialogo) && str_starts_with($dialogo, 'editar-') ? (int) substr($dialogo, 7) : null;
            $sedesDeId = is_string($dialogo) && str_starts_with($dialogo, 'sedes-') ? (int) substr($dialogo, 6) : null;
            $existentes = json_encode($proveedores->map(fn ($p) => mb_strtolower($p->nombre))->values());
            $idsActivas = $sedes->pluck('id');
            $totalSedes = $sedes->count();
            $altas = app(\App\Services\Padrones\AltasPorVerificar::class)->paraLista('proveedores', $proveedores, auth()->user());
        @endphp

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
            <div class="encabezado-pantalla m-0">
                <div class="icono"><i class="bi bi-truck text-success" aria-hidden="true"></i></div>
                <div>
                    <h1>Empresas Externas</h1>
                    <p>Directorio de Proveedores, Contratistas y Agencias de {{ $empresaNombre }}.</p>
                </div>
            </div>
            <div class="barra-filtros justify-content-md-end">
                @if ($sedesFiltro->count() > 1)
                    <select class="filtro-select" aria-label="Filtrar por sede" data-filtro-sede="proveedores">
                        <option value="">Todas las sedes</option>
                        @foreach ($sedesFiltro as $sede)
                            <option value="{{ $sede->id }}">{{ $sede->nombre }}</option>
                        @endforeach
                    </select>
                @endif
                <div class="buscador">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="search" placeholder="Buscar razón social..." aria-label="Buscar empresa externa" data-filtro-texto="proveedores">
                </div>
            </div>
        </div>

        <div class="pildoras-tipo" role="group" aria-label="Filtrar por categoría">
            <button type="button" class="btn-pill-tipo active" data-filtro-tipo="proveedores" data-valor="" aria-pressed="true">Todos</button>
            @foreach ($estilos as $clave => [$icono, , , $pildora])
                <button type="button" class="btn-pill-tipo" data-filtro-tipo="proveedores" data-valor="{{ $clave }}" aria-pressed="false"><i class="bi {{ $icono }} me-1" aria-hidden="true"></i>{{ $pildora }}</button>
            @endforeach
            @include('padrones.altas-por-verificar._pildora', ['altas' => $altas])
        </div>

        <div class="filtros-estado" role="group" aria-label="Filtrar por estado">
            <button type="button" class="btn btn-dark btn-sm fw-bold" data-filtro-estado="proveedores" data-valor="todas" aria-pressed="true">Todas</button>
            <button type="button" class="btn btn-outline-success btn-sm fw-bold" data-filtro-estado="proveedores" data-valor="1" aria-pressed="false">Activas</button>
            <button type="button" class="btn btn-outline-danger btn-sm fw-bold" data-filtro-estado="proveedores" data-valor="0" aria-pressed="false">Baja / Vetadas</button>
        </div>

        <div class="fichas-grid" data-fichas="proveedores">
            @if ($puede['crear'])
                <button type="button" class="ficha-card ficha-create" data-abrir-dialogo="dialogoNuevoProveedor">
                    <i class="bi bi-plus-circle-fill" aria-hidden="true"></i>
                    <span class="h6 fw-bold m-0 mt-2 titulo-crear">Registrar Empresa Externa</span>
                </button>
            @endif

            @forelse ($proveedores as $p)
                @php
                    [$icono, $clase, $insignia] = $estilos[$p->categoria] ?? ['bi-building', 'bg-agencia', $p->categoria];
                    $sedesActivas = $p->sedes->where('activo', true);
                    $operaEn = $p->todas_las_sedes ? $idsActivas : $sedesActivas->pluck('id');
                    $etiquetaSedes = $p->todas_las_sedes || ($totalSedes > 0 && $sedesActivas->count() >= $totalSedes) ? 'Todas las sedes' : $sedesActivas->count().' de '.$totalSedes.' sedes';
                    $editable = in_array($p->id, $editables, true) && ! $p->estaRechazado();
                    $desactivable = in_array($p->id, $desactivables, true) && ! $p->estaRechazado();
                    $valores = json_encode($p->only(['nombre', 'categoria', 'rfc', 'telefono', 'direccion']));
                @endphp
                <div class="ficha-card {{ $p->activo ? '' : 'inactiva' }}" id="proveedor-{{ $p->id }}" data-ficha data-estado="{{ $p->activo ? 1 : 0 }}"
                     data-tipo="{{ $p->categoria }}" data-sede="{{ $operaEn->join(' ') }}"
                     data-texto="{{ mb_strtolower($p->nombre.' '.$p->rfc.' '.$p->telefono.' '.$insignia) }}">
                    <div>
                        <div class="mb-2"><span class="ext-badge {{ $clase }}"><i class="bi {{ $icono }} me-1" aria-hidden="true"></i>{{ $insignia }}</span></div>
                        @include('padrones.altas-por-verificar._insignia', ['registro' => $p, 'femenino' => true])
                        <h2 class="ficha-title ext-title">{{ $p->nombre }}</h2>

                        <div class="meta-lista">
                            @if ($p->rfc)
                                <div><i class="bi bi-card-text me-2" aria-hidden="true"></i><strong>RFC:</strong> <span class="fw-bold">{{ $p->rfc }}</span></div>
                            @endif
                            @if ($p->telefono)
                                <div><i class="bi bi-telephone me-2" aria-hidden="true"></i><strong>Tel:</strong> {{ $p->telefono }}</div>
                            @endif
                            <div><i class="bi bi-people me-2" aria-hidden="true"></i>{{ $p->personas_count }} {{ $p->personas_count === 1 ? 'persona' : 'personas' }} · {{ $p->vehiculos_count }} {{ $p->vehiculos_count === 1 ? 'vehículo' : 'vehículos' }}</div>
                            <div>
                                @if ($puede['sedes'])
                                    <button type="button" class="badge-sedes" data-abrir-dialogo="dialogoSedesProveedor{{ $p->id }}" aria-label="Sedes donde opera {{ $p->nombre }}: {{ $etiquetaSedes }}">
                                        <i class="bi bi-signpost-split" aria-hidden="true"></i> {{ $etiquetaSedes }}
                                    </button>
                                @else
                                    <span class="badge-sedes"><i class="bi bi-signpost-split" aria-hidden="true"></i> {{ $etiquetaSedes }}</span>
                                @endif
                            </div>
                            @if ($p->actualizado_por_nombre && $p->updated_at?->ne($p->created_at))
                                <div class="texto-traza mt-1"><i class="bi bi-clock-history" aria-hidden="true"></i> Editado por {{ $p->actualizado_por_nombre }} · @fecha($p->updated_at)</div>
                            @elseif ($p->creado_por_nombre)
                                <div class="texto-traza mt-1"><i class="bi bi-plus-circle" aria-hidden="true"></i> Creado por {{ $p->creado_por_nombre }} · @fecha($p->created_at)</div>
                            @endif
                        </div>
                    </div>

                    <div class="ficha-footer">
                        <span class="etiqueta-estado {{ $p->activo ? 'activo' : 'inactivo' }}">{{ $p->activo ? 'ACTIVA' : 'BAJA / VETADA' }}</span>
                        <div class="d-flex gap-2">
                            @include('padrones.altas-por-verificar._boton', ['registro' => $p])
                            <a href="{{ route('proveedores.show', $p->id) }}" class="btn-ficha" aria-label="Ficha de {{ $p->nombre }}"><i class="bi bi-person-lines-fill me-1" aria-hidden="true"></i>Ficha</a>
                            @if ($editable)
                                <button type="button" class="btn-icono editar" title="Editar" aria-label="Editar {{ $p->nombre }}"
                                        data-accion="editar-registro" data-dialogo="dialogoEditarProveedor"
                                        data-url="{{ route('proveedores.update', $p->id) }}" data-id="{{ $p->id }}" data-valores="{{ $valores }}">
                                    <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                </button>
                            @endif
                            @if ($desactivable)
                                <form action="{{ route('proveedores.estado', $p->id) }}" method="POST" class="m-0"
                                      data-confirmar="{{ $p->activo ? '¿Desactivar «'.$p->nombre.'»? Quedará como baja / vetada y podrás reactivarla después con un clic.' : '¿Reactivar «'.$p->nombre.'»?' }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="activo" value="{{ $p->activo ? 0 : 1 }}">
                                    @if ($p->activo)
                                        <button type="submit" class="btn-icono eliminar" title="Desactivar" aria-label="Desactivar {{ $p->nombre }}"><i class="bi bi-slash-circle" aria-hidden="true"></i></button>
                                    @else
                                        <button type="submit" class="btn-icono reactivar" title="Reactivar" aria-label="Reactivar {{ $p->nombre }}"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></button>
                                    @endif
                                </form>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                @unless ($puede['crear'])
                    <div class="tarjeta estado-vacio"><p class="text-muted small m-0">Todavía no hay empresas externas registradas.</p></div>
                @endunless
            @endforelse
        </div>
        <p class="sin-resultados" data-sin-resultados="proveedores" hidden><i class="bi bi-search d-block mb-2" aria-hidden="true"></i>No hay registros que coincidan con tu búsqueda.</p>

        {{-- ===== Alta ===== --}}
        @if ($puede['crear'])
            @php
                $reabrir = $dialogo === 'crear';
                $todas = $reabrir ? (bool) old('todas_las_sedes') : true;
                $marcadas = $reabrir ? array_map('intval', (array) old('sedes', [])) : [];
            @endphp
            <dialog id="dialogoNuevoProveedor" class="dialogo" aria-labelledby="titulo-proveedor-nuevo" @if ($reabrir) data-abrir-al-cargar @endif>
                <div class="dialogo-cabecera">
                    <h2 id="titulo-proveedor-nuevo"><i class="bi bi-building-add me-2 text-success" aria-hidden="true"></i>Nueva Empresa Externa</h2>
                    <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
                <div class="dialogo-cuerpo">
                    <form action="{{ route('proveedores.store') }}" method="POST" autocomplete="off">
                        @csrf
                        <input type="hidden" name="_dialogo" value="crear">
                        @include('padrones.proveedores._campos', ['prefijo' => 'nuevo_proveedor', 'reabrir' => $reabrir, 'existentes' => $puede['crearEnEmpresa'] ? $existentes : null])

                        @if ($puede['crearEnEmpresa'])
                            @if ($totalSedes > 1)
                                <span class="campo-etiqueta d-block mt-1">Sedes donde opera</span>
                                <input type="hidden" name="todas_las_sedes" value="0">
                                <label class="opcion-todas">
                                    <input type="checkbox" name="todas_las_sedes" value="1" @checked($todas) data-por-defecto data-oculta-si-marcado="#nuevo_proveedor_sedes">
                                    <span><strong>Todas las sedes</strong><br><span class="small text-muted">Recomendado — normalmente es el mismo proveedor en todas las sedes de la empresa, incluidas las que se abran después.</span></span>
                                </label>
                                <div class="caja-checks" id="nuevo_proveedor_sedes" @if ($todas) hidden @endif>
                                    @foreach ($sedes as $sede)
                                        <label class="fila-check"><input type="checkbox" name="sedes[]" value="{{ $sede->id }}" @checked(in_array($sede->id, $marcadas, true))> {{ $sede->nombre }}</label>
                                    @endforeach
                                </div>
                            @else
                                <input type="hidden" name="todas_las_sedes" value="1">
                            @endif
                        @else
                            {{-- Alcance de sede: solo para sus sedes; si ya existe, se agrega a su sede --}}
                            @if ($sedesAlta->count() > 1)
                                <span class="campo-etiqueta d-block mt-1">Sedes donde opera</span>
                                <div class="caja-checks">
                                    @foreach ($sedesAlta as $sede)
                                        <label class="fila-check"><input type="checkbox" name="sedes[]" value="{{ $sede->id }}" @checked(! $reabrir || in_array($sede->id, $marcadas, true)) data-por-defecto> {{ $sede->nombre }}</label>
                                    @endforeach
                                </div>
                            @else
                                <p class="linea-empresa mb-3"><i class="bi bi-signpost-split me-1" aria-hidden="true"></i>Se registra para <strong>{{ $sedesAlta->first()?->nombre }}</strong>.</p>
                            @endif
                            <p class="small text-muted"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Si la empresa ya está registrada en otra sede, no se duplica: se agrega a la tuya.</p>
                        @endif

                        <div class="dialogo-acciones">
                            <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                            <button type="submit" class="btn-esmeralda">Guardar Empresa</button>
                        </div>
                    </form>
                </div>
            </dialog>
        @endif

        {{-- ===== Edición de datos generales ===== --}}
        @if ($editables !== [])
            @php $reabrir = $editandoId !== null; @endphp
            <dialog id="dialogoEditarProveedor" class="dialogo" aria-labelledby="titulo-proveedor-editar" @if ($reabrir) data-abrir-al-cargar @endif>
                <div class="dialogo-cabecera">
                    <h2 id="titulo-proveedor-editar"><i class="bi bi-pencil-square me-2 text-success" aria-hidden="true"></i>Editar Empresa Externa</h2>
                    <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
                <div class="dialogo-cuerpo">
                    <form action="{{ $editandoId ? route('proveedores.update', $editandoId) : '' }}" method="POST" autocomplete="off">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="_dialogo" value="{{ $editandoId ? 'editar-'.$editandoId : '' }}" data-campo-dialogo>
                        @include('padrones.proveedores._campos', ['prefijo' => 'editar_proveedor', 'reabrir' => $reabrir, 'existentes' => $existentes])
                        <p class="small text-muted"><i class="bi bi-signpost-split me-1" aria-hidden="true"></i>Las sedes se cambian con el botón de sedes de la tarjeta.</p>

                        <div class="dialogo-acciones">
                            <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                            <button type="submit" class="btn-esmeralda">Actualizar Datos</button>
                        </div>
                    </form>
                    @include('componentes.borrar', ['registro' => 'proveedores', 'id' => $editandoId])
                </div>
            </dialog>
        @endif

        {{-- ===== Sedes donde opera cada proveedor ===== --}}
        @if ($puede['sedes'])
            @foreach ($proveedores as $p)
                @include('padrones.proveedores._sedes', [
                    'proveedor' => $p,
                    'reabrir' => $sedesDeId === $p->id,
                    'volver' => 'lista',
                ])
            @endforeach
        @endif
        @include('padrones.altas-por-verificar._dialogo', ['altas' => $altas])
    @endif
</div>
@endsection
