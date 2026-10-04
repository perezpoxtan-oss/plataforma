@extends('layouts.app')

@section('titulo', 'Puestos')

@section('contenido')
<div class="tema-cian">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    @if ($sinEmpresa)
        <div class="encabezado-pantalla mb-4">
            <div class="icono"><i class="bi bi-briefcase-fill text-info" aria-hidden="true"></i></div>
            <div><h1>Puestos</h1><p>Catálogo de rangos/posiciones, independiente del Departamento.</p></div>
        </div>
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-buildings" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong> para ver sus puestos.</p>
        </div>
    @else
        @php
            $dialogo = old('_dialogo');
            $editandoId = is_string($dialogo) && str_starts_with($dialogo, 'editar-') ? (int) substr($dialogo, 7) : null;
            $existentes = json_encode($puestos->map(fn ($p) => mb_strtolower($p->nombre))->values());
        @endphp

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
            <div class="encabezado-pantalla m-0">
                <div class="icono"><i class="bi bi-briefcase-fill text-info" aria-hidden="true"></i></div>
                <div>
                    <h1>Puestos</h1>
                    <p>Catálogo de rangos/posiciones, independiente del Departamento.</p>
                </div>
            </div>
            @if ($puestos->count() > 1)
                <div class="buscador">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="search" placeholder="Buscar puesto o departamento..." aria-label="Buscar puesto" data-filtro-texto="puestos">
                </div>
            @endif
        </div>

        <div class="pildoras-tipo" role="group" aria-label="Filtrar por tipo de puesto">
            <button type="button" class="btn-pill-tipo active" data-filtro-tipo="puestos" data-valor="" aria-pressed="true">Todos</button>
            <button type="button" class="btn-pill-tipo" data-filtro-tipo="puestos" data-valor="administrativo" aria-pressed="false"><i class="bi bi-person-badge me-1" aria-hidden="true"></i>Administrativos</button>
            <button type="button" class="btn-pill-tipo" data-filtro-tipo="puestos" data-valor="operativo" aria-pressed="false"><i class="bi bi-cone-striped me-1" aria-hidden="true"></i>Operativos</button>
        </div>

        <div class="fichas-grid" data-fichas="puestos">
            @if ($puede['crear'])
                <button type="button" class="ficha-card ficha-create" data-abrir-dialogo="dialogoNuevoPuesto">
                    <i class="bi bi-plus-circle-fill" aria-hidden="true"></i>
                    <span class="h6 fw-bold m-0 mt-2 titulo-crear">Nuevo Puesto</span>
                </button>
            @endif

            @forelse ($puestos as $p)
                @php $valores = json_encode(['nombre' => $p->nombre, 'tipo' => $p->tipo, 'departamentos' => $p->departamentos->pluck('id')]); @endphp
                <div class="ficha-card" id="puesto-{{ $p->id }}" data-ficha data-estado="{{ $p->activo ? 1 : 0 }}" data-tipo="{{ $p->tipo }}"
                     data-texto="{{ mb_strtolower($p->nombre.' '.$p->departamentos->pluck('nombre')->join(' ')) }}">
                    <div>
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <h2 class="ficha-title m-0">{{ $p->nombre }}</h2>
                            <span class="{{ $p->tipo === 'administrativo' ? 'badge-tipo-admin' : 'badge-tipo-op' }}">{{ $p->tipo === 'administrativo' ? 'ADMIN' : 'OPERATIVO' }}</span>
                        </div>
                        <div class="d-flex flex-wrap gap-1 mt-2">
                            @forelse ($p->departamentos as $dep)
                                <span class="chip-catalogo {{ $dep->activo ? '' : 'inactivo' }}" @unless ($dep->activo) title="Departamento desactivado" @endunless><i class="bi bi-diagram-3" aria-hidden="true"></i> {{ $dep->nombre }}</span>
                            @empty
                                <span class="small text-muted"><i class="bi bi-diagram-3" aria-hidden="true"></i> Aplica en cualquier departamento</span>
                            @endforelse
                        </div>
                        @if ($p->creado_por_nombre)
                            <div class="texto-traza mt-2"><i class="bi bi-plus-circle" aria-hidden="true"></i> Creado por {{ $p->creado_por_nombre }} · {{ $p->created_at?->format('d/m/Y H:i') }}</div>
                        @endif
                        @if ($p->actualizado_por_nombre && $p->updated_at?->ne($p->created_at))
                            <div class="texto-traza"><i class="bi bi-clock-history" aria-hidden="true"></i> Editado por {{ $p->actualizado_por_nombre }} · {{ $p->updated_at->format('d/m/Y H:i') }}</div>
                        @endif
                    </div>
                    <div class="ficha-footer">
                        <span class="etiqueta-estado {{ $p->activo ? 'activo' : 'inactivo' }}">{{ $p->activo ? 'ACTIVO' : 'INACTIVO' }}</span>
                        <div class="d-flex gap-2">
                            @if ($puede['editar'])
                                <button type="button" class="btn-icono editar" title="Editar" aria-label="Editar {{ $p->nombre }}"
                                        data-accion="editar-registro" data-dialogo="dialogoEditarPuesto"
                                        data-url="{{ route('puestos.update', $p->id) }}" data-id="{{ $p->id }}" data-valores="{{ $valores }}">
                                    <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                </button>
                            @endif
                            @if ($puede['estado'])
                                <form action="{{ route('puestos.estado', $p->id) }}" method="POST" class="m-0"
                                      data-confirmar="{{ $p->activo ? '¿Desactivar el puesto «'.$p->nombre.'»? Podrás reactivarlo con el mismo botón.' : '¿Reactivar el puesto «'.$p->nombre.'»?' }}">
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
                    <div class="tarjeta estado-vacio"><p class="text-muted small m-0">Todavía no hay puestos registrados.</p></div>
                @endunless
            @endforelse
        </div>
        <p class="text-muted text-center p-4" data-sin-resultados="puestos" hidden><i class="bi bi-search me-2" aria-hidden="true"></i>No hay puestos que coincidan con tu búsqueda.</p>

        {{-- ===== Alta y edición ===== --}}
        @foreach (['nuevo' => $puede['crear'], 'editar' => $puede['editar']] as $modo => $permitido)
            @continue (! $permitido)
            @php
                $esNuevo = $modo === 'nuevo';
                $reabrir = $esNuevo ? $dialogo === 'crear' : $editandoId !== null;
                $marcados = $reabrir ? array_map('intval', (array) old('departamentos', [])) : [];
                $tipo = $reabrir ? old('tipo', 'operativo') : 'operativo';
            @endphp
            <dialog id="{{ $esNuevo ? 'dialogoNuevoPuesto' : 'dialogoEditarPuesto' }}" class="dialogo" aria-labelledby="titulo-pu-{{ $modo }}" @if ($reabrir) data-abrir-al-cargar @endif>
                <div class="dialogo-cabecera">
                    <h2 id="titulo-pu-{{ $modo }}"><i class="bi {{ $esNuevo ? 'bi-briefcase' : 'bi-pencil-square' }} me-2 text-info" aria-hidden="true"></i>{{ $esNuevo ? 'Alta de Puesto' : 'Actualizar Puesto' }}</h2>
                    <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
                <div class="dialogo-cuerpo">
                    <form action="{{ $esNuevo ? route('puestos.store') : ($editandoId ? route('puestos.update', $editandoId) : '') }}" method="POST" autocomplete="off">
                        @csrf
                        @unless ($esNuevo) @method('PUT') @endunless
                        <input type="hidden" name="_dialogo" value="{{ $esNuevo ? 'crear' : ($editandoId ? 'editar-'.$editandoId : '') }}" data-campo-dialogo>

                        <label class="campo-etiqueta" for="{{ $modo }}_pu_tipo">Tipo de Puesto</label>
                        <select id="{{ $modo }}_pu_tipo" name="tipo" class="campo" required>
                            @foreach ($tipos as $clave => $etiqueta)
                                <option value="{{ $clave }}" @selected($tipo === $clave) @if ($clave === 'operativo') data-por-defecto @endif>{{ $etiqueta }}</option>
                            @endforeach
                        </select>

                        <label class="campo-etiqueta" for="{{ $modo }}_pu_nombre">Nombre del Puesto</label>
                        <input type="text" id="{{ $modo }}_pu_nombre" name="nombre" class="campo" maxlength="100" placeholder="Ej. Jefe"
                               value="{{ $reabrir ? old('nombre') : '' }}" data-nombres-existentes="{{ $existentes }}" required>
                        <p data-aviso-nombre hidden></p>

                        <span class="campo-etiqueta d-block">Departamentos donde aplica <span class="text-lowercase fw-normal">(opcional — si no marcas ninguno, aplica en cualquiera)</span></span>
                        @if ($departamentos->isEmpty())
                            <p class="small text-muted">Aún no hay departamentos activos. Puedes crearlos en Estructura → Departamentos.</p>
                        @else
                            <div class="caja-checks">
                                @foreach ($departamentos as $dep)
                                    <label class="fila-check"><input type="checkbox" name="departamentos[]" value="{{ $dep->id }}" @checked(in_array($dep->id, $marcados, true))> {{ $dep->nombre }}</label>
                                @endforeach
                            </div>
                        @endif
                        <p class="campo-ayuda mb-3"><i class="bi bi-info-circle" aria-hidden="true"></i> El Departamento con el que trabaja cada Colaborador se elige aparte, junto con su Puesto y su Sede.</p>

                        <div class="dialogo-acciones">
                            <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                            <button type="submit" class="btn-cian">{{ $esNuevo ? 'Guardar Puesto' : 'Guardar Cambios' }}</button>
                        </div>
                    </form>
                </div>
            </dialog>
        @endforeach
    @endif
</div>
@endsection
