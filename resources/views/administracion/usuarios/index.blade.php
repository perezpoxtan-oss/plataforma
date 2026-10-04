@extends('layouts.app')

@section('titulo', 'Usuarios del Sistema')

@section('contenido')
<div class="tema-rojo">
    @include('administracion.partes.avisos')

    @include('administracion.partes.selector-empresa')

    @if ($sinEmpresa)
        <div class="encabezado-pantalla mb-4">
            <div class="icono"><i class="bi bi-people-fill text-danger" aria-hidden="true"></i></div>
            <div><h1>Usuarios Operativos</h1><p>Control de credenciales y visibilidad del sistema.</p></div>
        </div>
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-buildings" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong> para ver y administrar sus usuarios.</p>
        </div>
    @else
        @php
            $dialogo = old('_dialogo');
            $editandoId = is_string($dialogo) && str_starts_with($dialogo, 'editar-') ? (int) substr($dialogo, 7) : null;
            $nombreSedes = $sedes->count() === 1 ? 'su sede' : 'todas las sedes';
        @endphp

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
            <div class="encabezado-pantalla m-0">
                <div class="icono"><i class="bi bi-people-fill text-danger" aria-hidden="true"></i></div>
                <div><h1>Usuarios Operativos</h1><p>Usuarios de «{{ $empresaNombre }}»: control de credenciales y visibilidad del sistema.</p></div>
            </div>
            <div class="barra-filtros justify-content-md-end">
                @if ($sedes->count() > 1)
                    <select id="filtroSede" class="filtro-select" aria-label="Filtrar por sede" data-filtro-usuarios>
                        <option value="">Todas las sedes</option>
                        @foreach ($sedes as $sede)
                            <option value="{{ $sede->id }}">{{ $sede->nombre }}</option>
                        @endforeach
                    </select>
                @endif
                <div class="buscador">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="search" id="filtroTexto" placeholder="Buscar por nombre, correo o rol..." aria-label="Buscar usuarios" data-filtro-usuarios>
                </div>
            </div>
        </div>

        <div class="fichas-grid" id="listaUsuarios">
            @if ($puede['crear'] && $roles->isNotEmpty())
                <button type="button" class="ficha-card ficha-create" data-abrir-dialogo="dialogoNuevoUsuario">
                    <i class="bi bi-person-plus-fill" aria-hidden="true"></i>
                    <span class="h6 fw-bold m-0 mt-2 titulo-crear">Nuevo Usuario</span>
                </button>
            @endif

            @foreach ($usuarios as $u)
                @php
                    $asignacion = $u->roles->first();
                    $sedeId = $asignacion?->pivot->sede_id;
                    $esPropio = $u->id === auth()->id();
                    $administrable = ! $esPropio && (auth()->user()->es_superadmin || ($asignacion?->nivel_jerarquia ?? 65535) > $nivelPropio);
                    $valoresEdicion = json_encode([
                        'name' => $u->name, 'numero_colaborador' => $u->numero_colaborador, 'colaborador_id' => $u->colaborador_id, 'username' => $u->username,
                        'email' => $u->email, 'rol_id' => $asignacion?->id, 'sede_id' => $sedeId, 'activo' => $u->activo,
                    ]);
                @endphp
                <div class="ficha-card" data-usuario data-sede="{{ $sedeId ?? '' }}"
                     data-texto="{{ mb_strtolower($u->name.' '.$u->username.' '.$u->email.' '.($asignacion?->nombre ?? '').' '.$u->numero_colaborador) }}">
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <div class="avatar-letra" aria-hidden="true">{{ mb_strtoupper(mb_substr($u->name, 0, 1)) }}</div>
                            <div class="text-truncate">
                                <h2 class="ficha-title m-0" style="font-size: 1.05rem;">{{ $u->name }}@if ($esPropio) <small class="text-muted fw-semibold">(tú)</small>@endif</h2>
                                <span class="text-muted small">{{ '@'.$u->username }}@if ($u->numero_colaborador) · #{{ $u->numero_colaborador }}@endif</span>
                            </div>
                        </div>

                        <div class="ficha-meta">
                            <div><i class="bi bi-shield-lock text-danger" aria-hidden="true"></i> <strong>Rol:</strong> {{ $asignacion?->nombre ?? 'Sin rol' }}</div>
                            <div class="text-muted small"><i class="bi bi-envelope" aria-hidden="true"></i> {{ $u->email }}</div>
                            @if ($u->colaborador)
                                <div class="{{ $u->colaborador->activo ? 'text-success' : 'text-danger' }}" style="font-size: 0.75rem;">
                                    <i class="bi bi-link-45deg" aria-hidden="true"></i> Vinculado a Colaborador {{ $u->colaborador->activo ? '(activo)' : '(¡inactivo! revisar)' }}
                                </div>
                            @endif
                            <div class="text-muted small border-top pt-2 mt-1"><i class="bi bi-geo-alt-fill" aria-hidden="true"></i>
                                {{ $sedeId ? ($sedes[$sedeId]->nombre ?? 'Sede inactiva') : 'Ve '.$nombreSedes.' de la empresa' }}
                            </div>
                            @if ($u->ultimo_acceso_en)
                                <div class="text-muted" style="font-size: 0.72rem;"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> Último acceso: @fecha($u->ultimo_acceso_en)</div>
                            @endif
                        </div>
                    </div>
                    <div class="mt-2">
                        @if ($u->estaBloqueado())
                            <div class="mb-2">
                                <span class="etiqueta-estado bloqueado" title="Bloqueado por intentos fallidos de inicio de sesión">
                                    <i class="bi bi-lock-fill" aria-hidden="true"></i> BLOQUEADO hasta @fecha($u->bloqueado_hasta, 'H:i')
                                </span>
                            </div>
                        @endif
                        @if ($u->creado_por_nombre)
                            <div class="texto-traza"><i class="bi bi-plus-circle" aria-hidden="true"></i> Creado por {{ $u->creado_por_nombre }} · @fecha($u->created_at)</div>
                        @endif
                        @if ($u->actualizado_por_nombre && $u->updated_at?->ne($u->created_at))
                            <div class="texto-traza"><i class="bi bi-clock-history" aria-hidden="true"></i> Editado por {{ $u->actualizado_por_nombre }} · @fecha($u->updated_at)</div>
                        @endif
                    </div>
                    <div class="ficha-footer">
                        <span class="etiqueta-estado {{ $u->activo ? 'activo' : 'inactivo' }}">{{ $u->activo ? 'ACTIVO' : 'INACTIVO' }}</span>
                        <div class="d-flex gap-2">
                            @if ($puede['desbloquear'] && $administrable && in_array($u->id, $desbloqueables, true))
                                <form action="{{ route('usuarios.desbloquear', $u->id) }}" method="POST" class="m-0"
                                      data-confirmar="¿Desbloquear a «{{ $u->name }}»? Podrá volver a iniciar sesión de inmediato.">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn-icono desbloquear" title="Desbloquear" aria-label="Desbloquear a {{ $u->name }}"><i class="bi bi-unlock-fill" aria-hidden="true"></i></button>
                                </form>
                            @endif
                            @if ($puede['editar'] && $administrable)
                                <button type="button" class="btn-icono editar" title="Editar" aria-label="Editar a {{ $u->name }}"
                                        data-accion="editar-registro" data-dialogo="dialogoEditarUsuario"
                                        data-url="{{ route('usuarios.update', $u->id) }}" data-id="{{ $u->id }}"
                                        data-valores="{{ $valoresEdicion }}">
                                    <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                </button>
                            @endif
                            @if ($puede['eliminar'] && $administrable)
                                <form action="{{ route('usuarios.estado', $u->id) }}" method="POST" class="m-0"
                                      data-confirmar="{{ $u->activo ? '¿Desactivar esta cuenta? No podrá volver a iniciar sesión hasta que la reactives con el mismo botón.' : '¿Reactivar esta cuenta? Podrá volver a iniciar sesión.' }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="activo" value="{{ $u->activo ? 0 : 1 }}">
                                    @if ($u->activo)
                                        <button type="submit" class="btn-icono desactivar" title="Desactivar" aria-label="Desactivar a {{ $u->name }}"><i class="bi bi-slash-circle" aria-hidden="true"></i></button>
                                    @else
                                        <button type="submit" class="btn-icono reactivar" title="Reactivar" aria-label="Reactivar a {{ $u->name }}"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></button>
                                    @endif
                                </form>
                            @endif
                        </div>
                    </div>
                </div>
            @endforeach

            <div class="sin-resultados" id="sinResultados" hidden>
                <i class="bi bi-search d-block mb-2" aria-hidden="true"></i>
                <p class="fw-semibold m-0">No hay usuarios que coincidan con tu búsqueda.</p>
            </div>
        </div>

        {{-- ===== Formulario (alta y edición comparten campos) ===== --}}
        @foreach (['nuevo' => $puede['crear'], 'editar' => $puede['editar']] as $modo => $permitido)
            @continue (! $permitido || $roles->isEmpty())
            @php
                $esNuevo = $modo === 'nuevo';
                $reabrir = $esNuevo ? $dialogo === 'crear' : $editandoId !== null;
                $valor = fn (string $campo) => $reabrir ? old($campo) : '';
            @endphp
            <dialog id="{{ $esNuevo ? 'dialogoNuevoUsuario' : 'dialogoEditarUsuario' }}" class="dialogo ancho" aria-labelledby="titulo-{{ $modo }}" @if ($reabrir) data-abrir-al-cargar @endif>
                <div class="dialogo-cabecera">
                    <h2 id="titulo-{{ $modo }}"><i class="bi {{ $esNuevo ? 'bi-person-badge' : 'bi-pencil-square' }} me-2 text-danger" aria-hidden="true"></i>{{ $esNuevo ? 'Alta de Usuario' : 'Editar Usuario' }}</h2>
                    <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
                <div class="dialogo-cuerpo">
                    <form action="{{ $esNuevo ? route('usuarios.store') : ($editandoId ? route('usuarios.update', $editandoId) : '') }}" method="POST" autocomplete="off" data-formulario-editable>
                        @csrf
                        @unless ($esNuevo) @method('PUT') @endunless
                        <input type="hidden" name="_dialogo" value="{{ $esNuevo ? 'crear' : ($editandoId ? 'editar-'.$editandoId : '') }}" data-campo-dialogo>

                        <div class="row">
                            <div class="col-md-6">
                                <label class="campo-etiqueta" for="{{ $modo }}_numero">Núm. Colaborador <span class="text-muted text-lowercase fw-normal">(opcional)</span></label>
                                {{-- Autocompleta con Colaboradores (número o nombre) y llena el nombre; ver docs/tecnico/colaboradores.md --}}
                                <div class="buscador-colab">
                                    <input type="text" id="{{ $modo }}_numero" name="numero_colaborador" class="campo" maxlength="30" value="{{ $valor('numero_colaborador') }}"
                                           placeholder="Número o nombre del colaborador" autocomplete="off" role="combobox" aria-autocomplete="list" aria-expanded="false"
                                           aria-controls="{{ $modo }}_colab_resultados" data-buscar-colaborador="{{ route('colaboradores.buscar') }}" data-destino-nombre="{{ $modo }}_nombre">
                                    <input type="hidden" name="colaborador_id" value="{{ $valor('colaborador_id') }}" data-campo-colaborador>
                                    <div class="buscador-colab-resultados" id="{{ $modo }}_colab_resultados" role="listbox" hidden></div>
                                </div>
                                <p class="campo-ayuda text-success" data-colab-vinculo hidden><i class="bi bi-link-45deg" aria-hidden="true"></i> Vinculado a Colaboradores. Si cambias el número, se quita el vínculo.</p>
                            </div>
                            <div class="col-md-6">
                                <label class="campo-etiqueta" for="{{ $modo }}_nombre">Nombre Completo</label>
                                <input type="text" id="{{ $modo }}_nombre" name="name" class="campo" maxlength="150" value="{{ $valor('name') }}" required>
                            </div>
                        </div>

                        <p class="linea-empresa mb-3" data-empresa-usuario>
                            <i class="bi bi-buildings" aria-hidden="true"></i>
                            Empresa: <strong>{{ $empresaNombre }}</strong>
                            <span class="text-muted">· el usuario pertenece a esta empresa; la sede limita lo que ve dentro de ella.</span>
                        </p>

                        <div class="row">
                            <div class="col-md-6">
                                <label class="campo-etiqueta" for="{{ $modo }}_sede">1. Sede</label>
                                <select id="{{ $modo }}_sede" name="sede_id" class="campo">
                                    <option value="">Todas las sedes de la empresa</option>
                                    @foreach ($sedes as $sede)
                                        <option value="{{ $sede->id }}" @selected($reabrir && (string) old('sede_id') === (string) $sede->id)>{{ $sede->nombre }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="campo-etiqueta" for="{{ $modo }}_rol">2. Rol en el Sistema</label>
                                <select id="{{ $modo }}_rol" name="rol_id" class="campo" required>
                                    <option value="">-- Selecciona --</option>
                                    @foreach ($roles as $rol)
                                        <option value="{{ $rol->id }}" @selected($reabrir && (string) old('rol_id') === (string) $rol->id)>{{ $rol->nombre }} (nivel {{ $rol->nivel_jerarquia }})</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <p class="campo-ayuda mb-3"><i class="bi bi-info-circle" aria-hidden="true"></i> Solo aparecen los roles que puedes asignar (de nivel inferior al tuyo).</p>

                        <div class="row">
                            <div class="col-md-4">
                                <label class="campo-etiqueta" for="{{ $modo }}_usuario">Usuario</label>
                                <input type="text" id="{{ $modo }}_usuario" name="username" class="campo" maxlength="60" value="{{ $valor('username') }}" autocapitalize="none" required>
                            </div>
                            <div class="col-md-4">
                                <label class="campo-etiqueta" for="{{ $modo }}_correo">Correo</label>
                                <input type="email" id="{{ $modo }}_correo" name="email" class="campo" maxlength="150" value="{{ $valor('email') }}" required>
                            </div>
                            <div class="col-md-4">
                                <label class="campo-etiqueta {{ $esNuevo ? '' : 'text-warning' }}" for="{{ $modo }}_contrasena">
                                    @unless ($esNuevo)<i class="bi bi-exclamation-triangle" aria-hidden="true"></i>@endunless {{ $esNuevo ? 'Contraseña' : 'Nueva Contraseña' }}
                                </label>
                                <input type="password" id="{{ $modo }}_contrasena" name="password" class="campo" autocomplete="new-password"
                                       placeholder="{{ $esNuevo ? 'Mínimo 8, con letras y números' : 'Dejar en blanco para mantener' }}" @if ($esNuevo) required @endif>
                            </div>
                        </div>

                        @unless ($esNuevo)
                            <input type="hidden" name="activo" value="0">
                            <div class="form-check form-switch mb-2">
                                <input class="form-check-input" type="checkbox" role="switch" id="editar_activo" name="activo" value="1" @checked(! $reabrir || old('activo')) data-por-defecto>
                                <label class="form-check-label small fw-semibold" for="editar_activo">Cuenta activa (si la apagas, no podrá iniciar sesión)</label>
                            </div>
                        @endunless

                        <div class="dialogo-acciones">
                            <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                            <button type="submit" class="btn-rojo">{{ $esNuevo ? 'Registrar Usuario' : 'Guardar Cambios' }}</button>
                        </div>
                    </form>
                </div>
            </dialog>
        @endforeach
    @endif
</div>
@endsection
