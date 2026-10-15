@extends('layouts.app')

@section('titulo', 'Empresas')

@php
    $dialogo = old('_dialogo');
    $editandoId = is_string($dialogo) && str_starts_with($dialogo, 'editar-') ? (int) substr($dialogo, 7) : null;
@endphp

@section('contenido')
<div class="tema-azul">
    @include('administracion.partes.avisos')

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div class="encabezado-pantalla m-0">
            <div class="icono"><i class="bi bi-building-fill text-primary" aria-hidden="true"></i></div>
            <div>
                {{-- Mejoras estructura (ADR-0016): un solo nombre para el módulo --}}
                <h1>Empresas</h1>
                <p>{{ $esSuperadmin ? 'Las empresas de la plataforma: alta, módulos que tiene cada una, dar de baja y reactivar.' : 'Los datos de tu empresa: razón social, RFC, zona horaria y logo para las impresiones.' }}</p>
            </div>
        </div>
        @if ($empresas->count() > 1)
            <div class="buscador">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="search" placeholder="Buscar por nombre o RFC..." aria-label="Buscar empresas" data-filtro-texto="empresas">
            </div>
        @endif
    </div>

    @if ($empresas->count() > 1)
        <div class="filtros-estado" role="group" aria-label="Filtrar por estado">
            <button type="button" class="btn btn-dark btn-sm fw-bold" data-filtro-estado="empresas" data-valor="todas" aria-pressed="true">Todas</button>
            <button type="button" class="btn btn-outline-success btn-sm fw-bold" data-filtro-estado="empresas" data-valor="1" aria-pressed="false">Activas</button>
            <button type="button" class="btn btn-outline-danger btn-sm fw-bold" data-filtro-estado="empresas" data-valor="0" aria-pressed="false">De baja</button>
        </div>
    @endif

    @if ($suscripcion)
        @include('administracion.empresas._tu-suscripcion')
    @endif

    <div class="fichas-grid" data-fichas="empresas">
        @if ($puede['crear'])
            <button type="button" class="ficha-card ficha-create" data-abrir-dialogo="dialogoNuevaEmpresa">
                <i class="bi bi-plus-circle-fill" aria-hidden="true"></i>
                <span class="h6 fw-bold m-0 mt-2 titulo-crear">Nueva Empresa</span>
            </button>
        @endif

        @foreach ($empresas as $emp)
            @php
                $termino = $emp->rubro?->terminologia['sedes'] ?? 'Sedes';
                $valores = json_encode([
                    'nombre_comercial' => $emp->nombre_comercial, 'razon_social' => $emp->razon_social, 'rfc' => $emp->rfc,
                    'rubro_id' => $emp->rubro_id, 'zona_horaria' => $emp->zona_horaria,
                ]);
            @endphp
            <div class="ficha-card" id="empresa-{{ $emp->id }}" data-ficha data-estado="{{ $emp->activo ? 1 : 0 }}"
                 data-texto="{{ mb_strtolower($emp->nombre_comercial.' '.$emp->razon_social.' '.$emp->rfc) }}">
                <div>
                    <div class="d-flex align-items-center gap-3">
                        @if ($emp->logo_ruta)
                            <img src="{{ asset($emp->logo_ruta) }}" alt="Logo de {{ $emp->nombre_comercial }}" class="logo-empresa-ficha">
                        @endif
                        <h2 class="ficha-title m-0">{{ $emp->nombre_comercial }}</h2>
                    </div>
                    <div class="ficha-subtitulo">{{ $emp->razon_social }}</div>
                    <div class="d-flex flex-wrap gap-2">
                        <span class="ficha-dato"><i class="bi bi-card-text text-primary" aria-hidden="true"></i> RFC: {{ $emp->rfc ?: '—' }}</span>
                        <span class="ficha-dato"><i class="bi bi-tags text-primary" aria-hidden="true"></i> {{ $emp->rubro?->nombre }}</span>
                        <span class="ficha-dato"><i class="bi bi-clock text-primary" aria-hidden="true"></i> {{ \App\Support\ZonasHorarias::etiqueta($emp->zona_horaria) }}</span>
                    </div>

                    <div class="mt-3 pt-2 border-top">
                        <div class="small fw-bold text-muted mb-1"><i class="bi bi-buildings me-1" aria-hidden="true"></i>{{ $termino }} ({{ $emp->sedes->count() }})</div>
                        @if ($emp->sedes->isEmpty())
                            <div class="small text-muted">Todavía sin {{ mb_strtolower($termino) }}.</div>
                        @else
                            <div class="d-flex flex-column gap-1">
                                @foreach ($emp->sedes->take(5) as $s)
                                    @if ($puede['sedes'] && ! $esSuperadmin)
                                        <a href="{{ route('sedes.index') }}#sede-{{ $s->id }}" class="small text-decoration-none"><i class="bi bi-arrow-right-short" aria-hidden="true"></i> {{ $s->nombre }}</a>
                                    @else
                                        <span class="small"><i class="bi bi-arrow-right-short" aria-hidden="true"></i> {{ $s->nombre }}@unless ($s->activo) <span class="text-muted">(de baja)</span>@endunless</span>
                                    @endif
                                @endforeach
                                @if ($emp->sedes->count() > 5)
                                    <span class="small text-muted">+ {{ $emp->sedes->count() - 5 }} más</span>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
                <div class="mt-2">
                    <div class="texto-traza">Desde @fecha($emp->created_at, 'm/Y')@if ($emp->creado_por_nombre) · creada por {{ $emp->creado_por_nombre }}@endif</div>
                    @if ($emp->actualizado_por_nombre && $emp->updated_at?->ne($emp->created_at))
                        <div class="texto-traza"><i class="bi bi-clock-history" aria-hidden="true"></i> Editado por {{ $emp->actualizado_por_nombre }} · @fecha($emp->updated_at)</div>
                    @endif
                </div>
                @if ($esSuperadmin)
                    <div class="small text-muted mt-2"><i class="bi bi-grid-3x3-gap me-1" aria-hidden="true"></i>{{ count($contratados[$emp->id] ?? []) }} módulos contratados</div>
                    {{-- Suscripciones: sin suscripción = sin límites ni vencimiento --}}
                    @if ($sus = $suscripciones[$emp->id] ?? null)
                        <a href="{{ route('catalogo-comercial.clientes.show', $sus->id) }}" class="small d-inline-block mt-1"><i class="bi bi-calendar2-check me-1" aria-hidden="true"></i>Suscripción: {{ $sus->estadoTexto($sus->estadoEfectivo()) }}, vence el {{ $sus->vence_en->format('d/m/Y') }}</a>
                    @else
                        <div class="small text-muted mt-1"><i class="bi bi-infinity me-1" aria-hidden="true"></i>Sin suscripción: sin límites ni vencimiento</div>
                    @endif
                @endif
                <div class="ficha-footer">
                    <span class="etiqueta-estado {{ $emp->activo ? 'activo' : 'inactivo' }}">{{ $emp->activo ? 'ACTIVA' : 'DE BAJA' }}</span>
                    <div class="d-flex gap-2">
                        @include('componentes.ver-historial', ['registro' => \App\Models\Empresa::class, 'id' => $emp->id, 'nombre' => $emp->nombre_comercial])
                        @if ($esSuperadmin)
                            <button type="button" class="btn-icono editar" title="Módulos contratados" aria-label="Módulos contratados de {{ $emp->nombre_comercial }}" data-abrir-dialogo="dialogoModulos{{ $emp->id }}">
                                <i class="bi bi-grid-3x3-gap" aria-hidden="true"></i>
                            </button>
                        @endif
                        @if ($puede['editar'])
                            <button type="button" class="btn-icono editar" title="Editar" aria-label="Editar {{ $emp->nombre_comercial }}"
                                    data-accion="editar-registro" data-dialogo="dialogoEditarEmpresa"
                                    data-url="{{ route('empresas.update', $emp->id) }}" data-id="{{ $emp->id }}" data-valores="{{ $valores }}">
                                <i class="bi bi-pencil-square" aria-hidden="true"></i>
                            </button>
                        @endif
                        @if ($puede['estado'])
                            <form action="{{ route('empresas.estado', $emp->id) }}" method="POST" class="m-0"
                                  data-confirmar="{{ $emp->activo ? '¿Dar de baja la empresa «'.$emp->nombre_comercial.'»? Sus usuarios ya no podrán entrar. Podrás reactivarla después con el mismo botón.' : '¿Reactivar la empresa «'.$emp->nombre_comercial.'»?' }}">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="activo" value="{{ $emp->activo ? 0 : 1 }}">
                                @if ($emp->activo)
                                    <button type="submit" class="btn-icono eliminar" title="Dar de baja" aria-label="Dar de baja {{ $emp->nombre_comercial }}"><i class="bi bi-slash-circle" aria-hidden="true"></i></button>
                                @else
                                    <button type="submit" class="btn-icono reactivar" title="Reactivar" aria-label="Reactivar {{ $emp->nombre_comercial }}"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></button>
                                @endif
                            </form>
                        @endif
                    </div>
                </div>
            </div>
        @endforeach
    </div>
    {{-- ===== Módulos contratados (ADR-0016, solo Super Administrador) ===== --}}
    @if ($esSuperadmin)
        @php $sinPantalla = \App\Http\Controllers\Administracion\PermisoController::sinPantalla(); @endphp
        @foreach ($empresas as $emp)
            @php $activos = $contratados[$emp->id] ?? []; @endphp
            <dialog id="dialogoModulos{{ $emp->id }}" class="dialogo ancho" aria-labelledby="titulo-modulos-{{ $emp->id }}">
                <div class="dialogo-cabecera">
                    <h2 id="titulo-modulos-{{ $emp->id }}"><i class="bi bi-grid-3x3-gap me-2 text-primary" aria-hidden="true"></i>Módulos de «{{ $emp->nombre_comercial }}»</h2>
                    <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
                <div class="dialogo-cuerpo">
                    <p class="small text-muted">Marca lo que la empresa usa. Lo que quites deja de verse en el menú, en la Matriz de permisos y en el Manual de sus usuarios; sus datos y permisos se conservan por si lo vuelves a activar.</p>
                    <form action="{{ route('empresas.modulos', $emp->id) }}" method="POST" data-confirmar="¿Guardar los módulos de «{{ $emp->nombre_comercial }}»? Sus usuarios lo verán al recargar la pantalla.">
                        @csrf
                        @method('PUT')
                        @foreach ($catalogoModulos as $grupo)
                            <fieldset class="modulos-contratados">
                                <legend class="small fw-bold">{{ $grupo['area'] }}@if ($grupo['siempre']) <span class="text-muted fw-normal">(siempre incluidos)</span>@endif</legend>
                                @foreach ($grupo['modulos'] as $m)
                                    <label class="fila-check {{ $m->padre_id ? 'ms-4' : '' }}">
                                        <input type="checkbox" name="modulos[]" value="{{ $m->id }}" @checked($grupo['siempre'] || in_array($m->id, $activos, true)) @disabled($grupo['siempre'])>
                                        {{ $m->nombre }}@if (in_array($m->clave, $sinPantalla, true)) <span class="etiqueta-proximamente">Próximamente</span>@endif
                                    </label>
                                @endforeach
                            </fieldset>
                        @endforeach
                        <div class="dialogo-acciones">
                            <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                            <button type="submit" class="btn-azul">Guardar</button>
                        </div>
                    </form>
                </div>
            </dialog>
        @endforeach
    @endif

    <p class="text-muted text-center p-4" data-sin-resultados="empresas" hidden><i class="bi bi-inbox me-2" aria-hidden="true"></i>No se encontraron empresas con ese criterio.</p>

    {{-- ===== Alta y edición ===== --}}
    @foreach (['nueva' => $puede['crear'], 'editar' => $puede['editar']] as $modo => $permitido)
        @continue (! $permitido)
        @php
            $esNueva = $modo === 'nueva';
            $reabrir = $esNueva ? $dialogo === 'crear' : $editandoId !== null;
            $valor = fn (string $c, $defecto = '') => $reabrir ? old($c, $defecto) : $defecto;
        @endphp
        <dialog id="{{ $esNueva ? 'dialogoNuevaEmpresa' : 'dialogoEditarEmpresa' }}" class="dialogo" aria-labelledby="titulo-emp-{{ $modo }}" @if ($reabrir) data-abrir-al-cargar @endif>
            <div class="dialogo-cabecera">
                <h2 id="titulo-emp-{{ $modo }}"><i class="bi {{ $esNueva ? 'bi-building-add' : 'bi-pencil-square' }} me-2 text-primary" aria-hidden="true"></i>{{ $esNueva ? 'Nueva Empresa' : 'Editar Empresa' }}</h2>
                <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </div>
            <div class="dialogo-cuerpo">
                <form action="{{ $esNueva ? route('empresas.store') : ($editandoId ? route('empresas.update', $editandoId) : '') }}" method="POST" autocomplete="off" enctype="multipart/form-data">
                    @csrf
                    @unless ($esNueva) @method('PUT') @endunless
                    <input type="hidden" name="_dialogo" value="{{ $esNueva ? 'crear' : ($editandoId ? 'editar-'.$editandoId : '') }}" data-campo-dialogo>

                    <label class="campo-etiqueta" for="{{ $modo }}_nombre_comercial">Nombre Comercial</label>
                    <input type="text" id="{{ $modo }}_nombre_comercial" name="nombre_comercial" class="campo" maxlength="150" value="{{ $valor('nombre_comercial') }}" required>

                    <label class="campo-etiqueta" for="{{ $modo }}_razon_social">Razón Social</label>
                    <input type="text" id="{{ $modo }}_razon_social" name="razon_social" class="campo" maxlength="200" value="{{ $valor('razon_social') }}" required>

                    <div class="campo-grupo">
                        <div style="flex: 1">
                            <label class="campo-etiqueta" for="{{ $modo }}_rfc">RFC</label>
                            <input type="text" id="{{ $modo }}_rfc" name="rfc" class="campo text-uppercase" maxlength="13" value="{{ $valor('rfc') }}" required>
                        </div>
                        @if ($esSuperadmin)
                            <div style="flex: 1">
                                <label class="campo-etiqueta" for="{{ $modo }}_rubro">Rubro</label>
                                <select id="{{ $modo }}_rubro" name="rubro_id" class="campo" required>
                                    <option value="">-- Selecciona --</option>
                                    @foreach ($rubros as $rubro)
                                        <option value="{{ $rubro->id }}" @selected((string) $valor('rubro_id') === (string) $rubro->id)>{{ $rubro->nombre }}</option>
                                    @endforeach
                                </select>
                            </div>
                        @endif
                    </div>
                    @if ($esSuperadmin && $esNueva)
                        <p class="campo-ayuda mb-3"><i class="bi bi-info-circle" aria-hidden="true"></i> El rubro define la terminología de la operación (Habitación, Oficina, Departamento, Casa…). Al crearla se activan sus módulos y se copian los roles base.</p>
                    @endif

                    <label class="campo-etiqueta" for="{{ $modo }}_zona">Zona Horaria</label>
                    <select id="{{ $modo }}_zona" name="zona_horaria" class="campo" required>
                        @foreach ($zonas as $grupo => $opciones)
                            <optgroup label="{{ $grupo }}">
                                @foreach ($opciones as $zona => $etiqueta)
                                    <option value="{{ $zona }}" @selected($valor('zona_horaria', 'America/Cancun') === $zona) @if ($zona === 'America/Cancun') data-por-defecto @endif>{{ $etiqueta }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>

                    <label class="campo-etiqueta" for="{{ $modo }}_logo">Logo de la Empresa <span class="text-lowercase fw-normal">(opcional)</span></label>
                    <input type="file" id="{{ $modo }}_logo" name="logo" class="form-control mb-1" accept="image/png,image/jpeg,image/webp">
                    <p class="campo-ayuda mb-2"><i class="bi bi-info-circle" aria-hidden="true"></i> PNG, JPG o WEBP de hasta 512 KB. Sale en gafetes, vouchers y demás impresiones.</p>
                    @unless ($esNueva)
                        <label class="fila-check mb-3"><input type="checkbox" name="quitar_logo" value="1"> Quitar el logo actual</label>
                    @endunless

                    <div class="dialogo-acciones">
                        <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                        <button type="submit" class="btn-azul">{{ $esNueva ? 'Registrar Empresa' : 'Guardar Cambios' }}</button>
                    </div>
                </form>
            </div>
        </dialog>
    @endforeach
</div>
@endsection
