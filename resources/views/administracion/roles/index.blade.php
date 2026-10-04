@extends('layouts.app')

@section('titulo', 'Roles y Jerarquía')

@php
    // Si una edición regresó con errores, el diálogo se vuelve a abrir con lo capturado
    $dialogo = old('_dialogo');
    $editandoId = is_string($dialogo) && str_starts_with($dialogo, 'editar-') ? (int) substr($dialogo, 7) : null;

    // Nivel mínimo que puede usar quien captura (R-02): se explica en pantalla y en el aviso del navegador
    $esSuper = auth()->user()->es_superadmin;
    $nivelMinimo = $esSuper ? 1 : $nivelPropio + 1;
    $escala = $roles->where('nivel_jerarquia', '>=', $nivelMinimo)->map(fn ($r) => $r->nivel_jerarquia.' '.$r->nombre)->implode(', ');
    $mensajeMinimo = $esSuper
        ? 'El nivel jerárquico debe ser 1 o mayor.'
        : "Tu nivel es {$nivelPropio}: el nivel del rol debe ser {$nivelMinimo} o mayor (número mayor = menos autoridad).";
@endphp

@section('contenido')
    @include('administracion.partes.avisos')

    @include('administracion.partes.selector-empresa')

    <div class="encabezado-pantalla">
        <div class="icono"><i class="bi bi-diagram-3-fill text-indigo" aria-hidden="true"></i></div>
        <div>
            <h1>Roles y Jerarquía</h1>
            <p>{{ $esPlantillas ? 'Plantillas que recibe cada empresa nueva al darse de alta.' : 'Roles de «'.$empresaNombre.'»: perfiles disponibles para asignar a sus usuarios.' }}</p>
        </div>
    </div>
    <p class="text-muted small mb-4">
        <i class="bi bi-info-circle" aria-hidden="true"></i>
        Los niveles van de menor (más privilegios) a mayor (menos privilegios). El Super Administrador está por encima de todos y no aparece aquí.
        @if ($puede['permisos'])
            Los permisos de cada módulo se configuran desde <a href="{{ route('permisos.index') }}">Permisos</a>.
        @endif
    </p>

    <div class="fichas-grid">
        @if ($puede['crear'])
            <button type="button" class="ficha-card ficha-create" data-abrir-dialogo="dialogoNuevoRol">
                <i class="bi bi-plus-circle-fill" aria-hidden="true"></i>
                <span class="h6 fw-bold m-0 mt-2 text-indigo">Nuevo Rol</span>
            </button>
        @endif

        @forelse ($roles as $r)
            @php $administrable = auth()->user()->es_superadmin || $r->nivel_jerarquia > $nivelPropio; @endphp
            <div class="ficha-card {{ $r->activo ? '' : 'inactiva' }}">
                <div>
                    <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                        <h2 class="ficha-title m-0">{{ $r->nombre }}</h2>
                        <span class="badge-nivel">Nivel {{ $r->nivel_jerarquia }}</span>
                    </div>
                    @unless ($r->activo)<span class="badge-estado mb-2 d-inline-block">Inactivo</span>@endunless
                    <p class="text-muted small mb-2">{{ $r->descripcion ?: 'Sin descripción.' }}</p>
                    <div class="text-muted small"><i class="bi bi-people" aria-hidden="true"></i> {{ $r->total_usuarios }} {{ $r->total_usuarios == 1 ? 'usuario' : 'usuarios' }} con este rol</div>
                    @if ($r->creado_por_nombre)
                        <div class="texto-traza mt-2"><i class="bi bi-plus-circle" aria-hidden="true"></i> Creado por {{ $r->creado_por_nombre }} · @fecha($r->created_at, 'd/m/Y H:i')</div>
                    @endif
                    @if ($r->actualizado_por_nombre && $r->updated_at !== $r->created_at)
                        <div class="texto-traza"><i class="bi bi-clock-history" aria-hidden="true"></i> Editado por {{ $r->actualizado_por_nombre }} · @fecha($r->updated_at, 'd/m/Y H:i')</div>
                    @endif
                </div>
                <div class="ficha-footer">
                    @if ($puede['permisos'])
                        <a href="{{ route('permisos.index', ['rol' => $r->id]) }}"><i class="bi bi-shield-lock" aria-hidden="true"></i> Ver permisos</a>
                    @else
                        <span></span>
                    @endif
                    <div class="d-flex gap-2">
                        @if ($puede['editar'] && $administrable)
                            <button type="button" class="btn-icono editar" title="Editar" aria-label="Editar {{ $r->nombre }}"
                                    data-accion="editar-rol"
                                    data-url="{{ route('roles.update', $r->id) }}"
                                    data-id="{{ $r->id }}"
                                    data-nombre="{{ $r->nombre }}"
                                    data-descripcion="{{ $r->descripcion }}"
                                    data-nivel="{{ $r->nivel_jerarquia }}"
                                    data-activo="{{ $r->activo ? 1 : 0 }}">
                                <i class="bi bi-pencil-square" aria-hidden="true"></i>
                            </button>
                        @endif
                        @if ($puede['eliminar'] && $administrable)
                            <form action="{{ route('roles.destroy', $r->id) }}" method="POST" class="m-0" data-confirmar="¿Eliminar el rol «{{ $r->nombre }}»? Solo se puede si ningún usuario lo tiene asignado.">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn-icono eliminar" title="{{ $r->total_usuarios > 0 ? 'Tiene usuarios asignados' : 'Eliminar' }}" aria-label="Eliminar {{ $r->nombre }}" @disabled($r->total_usuarios > 0)>
                                    <i class="bi bi-trash" aria-hidden="true"></i>
                                </button>
                            </form>
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <div class="tarjeta estado-vacio">
                <div class="icono"><i class="bi bi-diagram-3" aria-hidden="true"></i></div>
                <p class="text-muted small m-0">Aún no hay roles.</p>
            </div>
        @endforelse
    </div>

    {{-- ===== Nuevo rol ===== --}}
    @if ($puede['crear'])
        <dialog id="dialogoNuevoRol" class="dialogo" aria-labelledby="tituloNuevoRol" @if ($dialogo === 'crear') data-abrir-al-cargar @endif>
            <div class="dialogo-cabecera">
                <h2 id="tituloNuevoRol"><i class="bi bi-diagram-3 me-2 text-indigo" aria-hidden="true"></i>Nuevo Rol</h2>
                <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </div>
            <div class="dialogo-cuerpo">
                <form action="{{ route('roles.store') }}" method="POST" autocomplete="off">
                    @csrf
                    <input type="hidden" name="_dialogo" value="crear">

                    <label class="campo-etiqueta" for="nuevo_nombre">Nombre del Rol</label>
                    <input type="text" id="nuevo_nombre" name="nombre" class="campo" maxlength="80" value="{{ $dialogo === 'crear' ? old('nombre') : '' }}" required>

                    <label class="campo-etiqueta" for="nuevo_descripcion">Descripción</label>
                    <input type="text" id="nuevo_descripcion" name="descripcion" class="campo" maxlength="255" placeholder="Ej. Supervisa dos hoteles, sin acceso a nómina" value="{{ $dialogo === 'crear' ? old('descripcion') : '' }}">

                    <label class="campo-etiqueta" for="nuevo_nivel">Nivel Jerárquico</label>
                    <input type="number" id="nuevo_nivel" name="nivel_jerarquia" class="campo" min="{{ $nivelMinimo }}" max="999" step="1" value="{{ $dialogo === 'crear' ? old('nivel_jerarquia') : '' }}" required
                           aria-describedby="nuevo_nivel_ayuda" data-mensaje-min="{{ $mensajeMinimo }}">
                    <p class="campo-ayuda mb-1" id="nuevo_nivel_ayuda" data-ayuda-nivel>
                        <i class="bi bi-info-circle" aria-hidden="true"></i>
                        @if ($esSuper)
                            Número menor = más autoridad. {{ $escala !== '' ? 'Escala actual: '.$escala.'.' : '' }}
                        @else
                            Tu nivel es <strong>{{ $nivelPropio }}</strong>. Solo puedes crear roles de nivel <strong>{{ $nivelMinimo }}</strong> en adelante (número mayor = menos autoridad{{ $escala !== '' ? ': '.$escala : '' }}).
                        @endif
                    </p>
                    <p class="campo-ayuda mt-1">
                        Niveles ya usados: {{ $nivelesUsados ? implode(', ', $nivelesUsados) : 'ninguno' }}. Elige un número distinto; entre dos niveles existentes para insertarlo "en medio" (ej. entre 20 y 30, usa 25).
                    </p>

                    <div class="dialogo-acciones">
                        <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                        <button type="submit" class="btn-indigo">Crear Rol</button>
                    </div>
                </form>
            </div>
        </dialog>
    @endif

    {{-- ===== Editar rol (se llena al tocar el lápiz) ===== --}}
    @if ($puede['editar'])
        @php $editando = $editandoId ? $roles->firstWhere('id', $editandoId) : null; @endphp
        <dialog id="dialogoEditarRol" class="dialogo" aria-labelledby="tituloEditarRol" @if ($editando) data-abrir-al-cargar @endif>
            <div class="dialogo-cabecera">
                <h2 id="tituloEditarRol"><i class="bi bi-pencil-square me-2 text-indigo" aria-hidden="true"></i>Editar Rol</h2>
                <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </div>
            <div class="dialogo-cuerpo">
                <form action="{{ $editando ? route('roles.update', $editando->id) : '' }}" method="POST" autocomplete="off" id="formEditarRol">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="_dialogo" value="{{ $editando ? 'editar-'.$editando->id : '' }}" data-campo="dialogo">

                    <label class="campo-etiqueta" for="editar_nombre">Nombre del Rol</label>
                    <input type="text" id="editar_nombre" name="nombre" class="campo" maxlength="80" value="{{ $editando ? old('nombre') : '' }}" data-campo="nombre" required>

                    <label class="campo-etiqueta" for="editar_descripcion">Descripción</label>
                    <input type="text" id="editar_descripcion" name="descripcion" class="campo" maxlength="255" value="{{ $editando ? old('descripcion') : '' }}" data-campo="descripcion">

                    <label class="campo-etiqueta" for="editar_nivel">Nivel Jerárquico</label>
                    <input type="number" id="editar_nivel" name="nivel_jerarquia" class="campo" min="{{ $nivelMinimo }}" max="999" step="1" value="{{ $editando ? old('nivel_jerarquia') : '' }}" data-campo="nivel" required
                           data-mensaje-min="{{ $mensajeMinimo }}">
                    <p class="campo-ayuda">
                        <i class="bi bi-exclamation-triangle text-warning" aria-hidden="true"></i> Cambiar este número reordena a quién puede asignarle este rol a quién, y qué ve en el menú. Solo ajústalo si sabes lo que implica.
                    </p>

                    <input type="hidden" name="activo" value="0">
                    <div class="form-check form-switch mb-3">
                        <input class="form-check-input" type="checkbox" role="switch" id="editar_activo" name="activo" value="1" data-campo="activo" @checked($editando ? old('activo') : true) data-por-defecto>
                        <label class="form-check-label small fw-semibold" for="editar_activo">Rol activo (si lo desactivas, quienes lo tienen pierden sus permisos)</label>
                    </div>

                    <div class="dialogo-acciones">
                        <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                        <button type="submit" class="btn-indigo">Guardar Cambios</button>
                    </div>
                </form>
            </div>
        </dialog>
    @endif
@endsection
