@extends('layouts.app')

@section('titulo', 'Padrón Vehicular')

@section('contenido')
<div class="tema-pizarra">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    @if ($sinEmpresa)
        <div class="encabezado-pantalla mb-4">
            <div class="icono"><i class="bi bi-car-front-fill text-secondary" aria-hidden="true"></i></div>
            <div><h1>Padrón Vehicular</h1><p>Catálogo de autos, flotillas y unidades registradas.</p></div>
        </div>
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-buildings" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong> para ver su padrón vehicular.</p>
        </div>
    @else
        @php
            $grupos = \App\Services\Vehiculos\AdministradorVehiculos::GRUPOS;
            $conteo = $vehiculos->countBy(fn ($v) => $grupos[$v->propiedad] ?? 'propios');
            $dialogo = old('_dialogo');
            $editandoId = is_string($dialogo) && str_starts_with($dialogo, 'editar-') ? (int) substr($dialogo, 7) : null;
            $placasExistentes = json_encode($vehiculos->pluck('placas')->values());
            $altas = app(\App\Services\Padrones\AltasPorVerificar::class)->paraLista('vehiculos', $vehiculos, auth()->user());
            $colores = [
                'agencia_renta' => 'bg-renta', 'empresa_proveedor' => 'bg-proveedor', 'propio_huesped' => 'bg-huesped',
                'taxi_app' => 'bg-taxi', 'transporte_personal' => 'bg-personal', 'propio_colaborador' => 'bg-colaborador',
            ];
        @endphp

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
            <div class="encabezado-pantalla m-0">
                <div class="icono"><i class="bi bi-car-front-fill text-secondary" aria-hidden="true"></i></div>
                <div>
                    <h1>Padrón Vehicular</h1>
                    <p>Catálogo de autos, flotillas y unidades registradas.</p>
                    <p class="texto-padron-de"><i class="bi bi-building-check" aria-hidden="true"></i> Padrón de: <strong>{{ $empresaNombre }}</strong></p>
                </div>
            </div>
            <div class="buscador">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="search" placeholder="Buscar por placas, marca, color o proveedor..." aria-label="Buscar vehículo" data-filtro-vehiculos>
            </div>
        </div>

        {{-- Ronda 5 (VE-03): Categoría (de quién es: píldoras) y Tipo / Estilo (la forma: lista) en la misma línea --}}
        <div class="pildoras-tipo filtros-vehiculo" role="group" aria-label="Filtrar por categoría">
            <span class="filtro-titulo">Categoría:</span>
            <button type="button" class="btn-pill-tipo active" data-filtro-tipo="vehiculos" data-valor="" aria-pressed="true">Todos <span class="conteo-pill">{{ $vehiculos->count() }}</span></button>
            <button type="button" class="btn-pill-tipo" data-filtro-tipo="vehiculos" data-valor="propios" aria-pressed="false"><i class="bi bi-person me-1" aria-hidden="true"></i>Propios <span class="conteo-pill">{{ $conteo['propios'] ?? 0 }}</span></button>
            <button type="button" class="btn-pill-tipo" data-filtro-tipo="vehiculos" data-valor="flotillas" aria-pressed="false"><i class="bi bi-truck me-1" aria-hidden="true"></i>Flotillas <span class="conteo-pill">{{ $conteo['flotillas'] ?? 0 }}</span></button>
            <button type="button" class="btn-pill-tipo" data-filtro-tipo="vehiculos" data-valor="taxis" aria-pressed="false"><i class="bi bi-taxi-front me-1" aria-hidden="true"></i>Taxis <span class="conteo-pill">{{ $conteo['taxis'] ?? 0 }}</span></button>
            @include('padrones.altas-por-verificar._pildora', ['altas' => $altas])
            <label class="filtro-estilo">
                <span class="filtro-titulo">Tipo / Estilo:</span>
                <select class="filtro-select" aria-label="Filtrar por tipo o estilo de vehículo" data-filtro-estilo-vehiculo>
                    <option value="">Todos</option>
                    @foreach (\App\Models\Vehiculo::TIPOS as $clave => $texto)
                        <option value="{{ $clave }}">{{ $texto }}</option>
                    @endforeach
                </select>
            </label>
        </div>

        <div class="fichas-grid" data-vehiculos>
            @if ($puede['crear'])
                <button type="button" class="ficha-card ficha-create" data-abrir-dialogo="dialogoNuevoVehiculo">
                    <i class="bi bi-plus-circle-fill" aria-hidden="true"></i>
                    <span class="h6 fw-bold m-0 mt-2 titulo-crear">Registrar Vehículo</span>
                </button>
            @endif

            @forelse ($vehiculos as $v)
                @php
                    $editable = $puede['editar'] && ($editables === null || in_array($v->id, $editables, true)) && ! $v->estaRechazado();
                    $desactivable = $puede['estado'] && ($desactivables === null || in_array($v->id, $desactivables, true)) && ! $v->estaRechazado();
                    $etiqueta = \App\Models\Vehiculo::PROPIEDADES[$v->propiedad] ?? $v->propiedad;
                    $tipo = $v->tipo === 'otro' && $v->descripcion_otro ? $v->descripcion_otro : (\App\Models\Vehiculo::TIPOS[$v->tipo] ?? '—');
                    $marcaModelo = trim(($v->marca ?? '').' '.($v->modelo ?? '')) ?: '—';
                    $valores = json_encode($v->only(['placas', 'propiedad', 'tipo', 'descripcion_otro', 'marca', 'modelo', 'color', 'capacidad', 'numero_economico', 'proveedor_id', 'colaborador_id']));
                    $texto = mb_strtolower(implode(' ', array_filter([
                        $v->placas, $v->marca, $v->modelo, $v->color, $v->numero_economico, $v->proveedor?->nombre, $v->colaborador?->nombreCompleto(), $etiqueta, $tipo,
                    ])));
                @endphp
                <div class="ficha-card {{ $v->activo ? '' : 'inactiva' }}" id="vehiculo-{{ $v->id }}" data-vehiculo
                     data-grupo="{{ $grupos[$v->propiedad] ?? 'propios' }}" data-estilo="{{ $v->tipo }}" data-texto="{{ $texto }}">
                    <div>
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-3">
                            <span class="badge-prop {{ $colores[$v->propiedad] ?? 'bg-visitante' }}"><i class="bi bi-info-circle-fill me-1" aria-hidden="true"></i>{{ $etiqueta }}</span>
                            <span class="etiqueta-estado {{ $v->activo ? 'activo' : 'inactivo' }}">{{ $v->activo ? 'ACTIVO' : 'BAJA' }}</span>
                        </div>
                        @include('padrones.altas-por-verificar._insignia', ['registro' => $v])
                        <div class="text-center mb-2">
                            <h2 class="veh-placas">{{ $v->placas }}</h2>
                        </div>

                        <div class="meta-vehiculo">
                            <div><span>Marca/Modelo:</span> <strong>{{ $marcaModelo }}</strong></div>
                            <div><span>Color:</span> <strong>{{ $v->color ?: '—' }}</strong></div>
                            <div><span>Tipo:</span> <strong>{{ $tipo }}</strong></div>
                            @if ($v->numero_economico)
                                <div><span>No. Económico:</span> <strong>{{ $v->numero_economico }}</strong></div>
                            @endif
                            @if ($v->capacidad)
                                <div><span>Capacidad:</span> <strong>{{ $v->capacidad }} {{ $v->capacidad === 1 ? 'persona' : 'personas' }}</strong></div>
                            @endif
                            @if ($v->proveedor)
                                <div class="texto-asociado"><i class="bi bi-building me-1" aria-hidden="true"></i><strong>Asociado a:</strong> {{ $v->proveedor->nombre }}</div>
                            @endif
                            @if ($v->colaborador)
                                <div class="texto-asociado"><i class="bi bi-person-vcard me-1" aria-hidden="true"></i><strong>Colaborador:</strong> {{ $v->colaborador->nombreCompleto() }}@if ($v->colaborador->num_empleado) · #{{ $v->colaborador->num_empleado }}@endif</div>
                            @endif
                            @if ($v->actualizado_por_nombre && $v->updated_at?->ne($v->created_at))
                                <div class="texto-traza mt-1"><i class="bi bi-clock-history" aria-hidden="true"></i> Editado por {{ $v->actualizado_por_nombre }} · @fecha($v->updated_at)</div>
                            @elseif ($v->creado_por_nombre)
                                <div class="texto-traza mt-1"><i class="bi bi-plus-circle" aria-hidden="true"></i> Creado por {{ $v->creado_por_nombre }} · @fecha($v->created_at)</div>
                            @endif
                        </div>
                    </div>

                    <div class="ficha-footer">
                        {{-- Ronda 5 (VE-04): el QR se ve en un diálogo; "Imprimir calcomanía" abre la página de impresión --}}
                        @include('componentes.boton-identificacion', ['identTipo' => 'vehiculo', 'identRegistro' => $v, 'identTitulo' => $v->placas,
                            'identDetalle' => trim($v->marca.' '.$v->modelo.' · '.$v->color, ' ·'),
                            'identImprimir' => $puede['imprimir'] ? route('vehiculos.calcomania', $v->id) : null, 'identImprimirTexto' => 'Imprimir calcomanía',
                            'identEditable' => $editable])
                        <div class="d-flex gap-2">
                            @include('padrones.altas-por-verificar._boton', ['registro' => $v])
                            @if ($editable)
                                <button type="button" class="btn-icono editar" title="Editar" aria-label="Editar vehículo {{ $v->placas }}"
                                        data-accion="editar-registro" data-dialogo="dialogoEditarVehiculo"
                                        data-url="{{ route('vehiculos.update', $v->id) }}" data-id="{{ $v->id }}" data-valores="{{ $valores }}">
                                    <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                </button>
                            @endif
                            @if ($desactivable)
                                <form action="{{ route('vehiculos.estado', $v->id) }}" method="POST" class="m-0"
                                      data-confirmar="{{ $v->activo ? '¿Dar de baja este vehículo? Podrás reactivarlo con un clic.' : '¿Reactivar este vehículo?' }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="activo" value="{{ $v->activo ? 0 : 1 }}">
                                    @if ($v->activo)
                                        <button type="submit" class="btn-icono eliminar" title="Dar de baja" aria-label="Dar de baja {{ $v->placas }}"><i class="bi bi-slash-circle" aria-hidden="true"></i></button>
                                    @else
                                        <button type="submit" class="btn-icono reactivar" title="Reactivar" aria-label="Reactivar {{ $v->placas }}"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></button>
                                    @endif
                                </form>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                @unless ($puede['crear'])
                    <div class="tarjeta estado-vacio"><p class="text-muted small m-0">Todavía no hay vehículos registrados.</p></div>
                @endunless
            @endforelse

            <div class="sin-resultados" data-sin-resultados-vehiculos hidden>
                <i class="bi bi-search d-block mb-2" aria-hidden="true"></i>
                <p class="fw-semibold m-0">No hay vehículos que coincidan con tu búsqueda.</p>
            </div>
        </div>

        {{-- ===== Alta y edición (comparten campos) ===== --}}
        @foreach (['nuevo' => $puede['crear'], 'editar' => $puede['editar']] as $modo => $permitido)
            @continue (! $permitido)
            @php
                $esNuevo = $modo === 'nuevo';
                $trasError = $esNuevo ? $dialogo === 'crear' : $editandoId !== null;
                $desdeProveedor = $esNuevo && ! $trasError && $prefijado !== null;
                $valor = function (string $campo, string $porDefecto = '') use ($trasError, $desdeProveedor, $prefijado) {
                    if ($trasError) {
                        return (string) old($campo, $porDefecto);
                    }

                    return $desdeProveedor && isset($prefijado[$campo]) && $prefijado[$campo] ? (string) $prefijado[$campo] : $porDefecto;
                };
                // Ronda 5 (PV-05): desde la ficha de una empresa externa, su empresa queda fija y al cerrar se regresa a la ficha
                $proveedorFijo = $esNuevo && ($trasError ? old('volver') === 'proveedor' : $desdeProveedor)
                    ? $proveedores->where('activo', true)->firstWhere('id', (int) $valor('proveedor_id')) : null;
                $alCerrar = $proveedorFijo && Route::has('proveedores.show') ? route('proveedores.show', ['proveedor' => $proveedorFijo->id, 'tab' => 'flotilla']) : null;
            @endphp
            <dialog id="{{ $esNuevo ? 'dialogoNuevoVehiculo' : 'dialogoEditarVehiculo' }}" class="dialogo ancho" aria-labelledby="titulo-ve-{{ $modo }}"
                    @if ($trasError || $desdeProveedor) data-abrir-al-cargar @endif @if ($alCerrar) data-al-cerrar-ir="{{ $alCerrar }}" @endif>
                <div class="dialogo-cabecera">
                    <h2 id="titulo-ve-{{ $modo }}"><i class="bi {{ $esNuevo ? 'bi-car-front' : 'bi-pencil-square' }} me-2 text-secondary" aria-hidden="true"></i>{{ $esNuevo ? 'Alta de Vehículo' : 'Actualizar Vehículo' }}</h2>
                    <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
                <div class="dialogo-cuerpo">
                    <form action="{{ $esNuevo ? route('vehiculos.store') : ($editandoId ? route('vehiculos.update', $editandoId) : '') }}" method="POST" autocomplete="off" data-form-vehiculo>
                        @csrf
                        @unless ($esNuevo) @method('PUT') @endunless
                        <input type="hidden" name="_dialogo" value="{{ $esNuevo ? 'crear' : ($editandoId ? 'editar-'.$editandoId : '') }}" data-campo-dialogo>
                        <input type="hidden" name="volver" value="{{ $trasError ? old('volver', '') : $volver }}" data-volver>
                        <p class="linea-empresa mb-3"><i class="bi bi-building-check me-1" aria-hidden="true"></i>Padrón de: <strong>{{ $empresaNombre }}</strong></p>
                        @if ($proveedorFijo)
                            <p class="aviso-desde-proveedor"><i class="bi bi-building me-1" aria-hidden="true"></i>Registrando unidad de <strong>{{ $proveedorFijo->nombre }}</strong>.@if ($alCerrar) Al guardar o cerrar regresarás a su ficha.@endif</p>
                        @endif

                        <div class="row">
                            <div class="col-md-5">
                                <label class="campo-etiqueta" for="{{ $modo }}_ve_placas">Placas (Únicas)</label>
                                <input type="text" id="{{ $modo }}_ve_placas" name="placas" class="campo campo-placas mb-1" maxlength="25" placeholder="Ej: ABC-123-A"
                                       value="{{ $valor('placas') }}" autocapitalize="characters" data-placas-existentes="{{ $placasExistentes }}" required>
                                <p class="small mb-2" data-aviso-placas hidden></p>
                            </div>
                            <div class="col-md-7">
                                <label class="campo-etiqueta" for="{{ $modo }}_ve_propiedad">Categoría <span class="text-lowercase fw-normal">(¿de quién es?)</span></label>
                                <select id="{{ $modo }}_ve_propiedad" name="propiedad" class="campo" required>
                                    @foreach (\App\Services\Vehiculos\AdministradorVehiculos::OPCIONES_PROPIEDAD as $clave => $texto)
                                        @continue ($proveedorFijo && ! in_array($clave, \App\Services\Vehiculos\AdministradorVehiculos::CON_PROVEEDOR, true))
                                        <option value="{{ $clave }}" @selected($valor('propiedad', 'propio_huesped') === $clave) @if ($clave === 'propio_huesped') data-por-defecto @endif>{{ $texto }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div class="caja-propietario" data-mostrar-si='{"propiedad":{{ json_encode(\App\Services\Vehiculos\AdministradorVehiculos::CON_PROVEEDOR) }}}'>
                            <label class="campo-etiqueta" for="{{ $modo }}_ve_proveedor"><i class="bi bi-building me-1" aria-hidden="true"></i> Agencia o Empresa Propietaria
                                <span class="text-lowercase fw-normal" data-mostrar-si='{"propiedad":["agencia_renta","taxi_app","transporte_personal"]}'>(opcional)</span></label>
                            @if ($proveedorFijo)
                                <input type="hidden" name="proveedor_id" value="{{ $proveedorFijo->id }}">
                                <p class="campo-fijo mb-0" id="{{ $modo }}_ve_proveedor"><i class="bi bi-lock-fill me-1" aria-hidden="true"></i><strong>{{ $proveedorFijo->nombre }}</strong> <small>(la empresa de la ficha)</small></p>
                            @else
                            <select id="{{ $modo }}_ve_proveedor" name="proveedor_id" class="campo mb-0" data-requerido-si='{"propiedad":{{ json_encode(\App\Services\Vehiculos\AdministradorVehiculos::PROVEEDOR_OBLIGATORIO) }}}'>
                                <option value="">-- Seleccionar del Directorio --</option>
                                @foreach ($proveedores as $pr)
                                    @continue ($esNuevo && ! $pr->activo)
                                    <option value="{{ $pr->id }}" @selected($valor('proveedor_id') === (string) $pr->id) @unless ($pr->activo) hidden @endunless>{{ $pr->nombre }}{{ $pr->activo ? '' : ' (inactivo)' }}</option>
                                @endforeach
                            </select>
                            @endif
                            @if (! $proveedorFijo && $proveedores->where('activo', true)->isEmpty())
                                <p class="campo-ayuda mt-2 mb-0"><i class="bi bi-info-circle" aria-hidden="true"></i> Todavía no hay proveedores registrados: dalos de alta en el módulo Proveedores.</p>
                            @endif
                        </div>

                        <div data-mostrar-si='{"propiedad":["propio_colaborador"]}'>
                            <label class="campo-etiqueta" for="{{ $modo }}_ve_colaborador"><i class="bi bi-person-vcard me-1" aria-hidden="true"></i> Colaborador (dueño del vehículo) <span class="text-lowercase fw-normal">(opcional)</span></label>
                            <select id="{{ $modo }}_ve_colaborador" name="colaborador_id" class="campo">
                                <option value="">-- Seleccionar colaborador --</option>
                                @foreach ($colaboradores as $co)
                                    @continue ($esNuevo && ! $co->activo)
                                    <option value="{{ $co->id }}" @selected($valor('colaborador_id') === (string) $co->id) @unless ($co->activo) hidden @endunless>{{ $co->nombreCompleto() }}{{ $co->num_empleado ? ' · #'.$co->num_empleado : '' }}{{ $co->activo ? '' : ' (baja)' }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <label class="campo-etiqueta" for="{{ $modo }}_ve_tipo">Tipo / Estilo <span class="text-lowercase fw-normal">(su forma)</span></label>
                                <select id="{{ $modo }}_ve_tipo" name="tipo" class="campo" required>
                                    @foreach (\App\Models\Vehiculo::TIPOS as $clave => $texto)
                                        <option value="{{ $clave }}" @selected($valor('tipo', 'sedan') === $clave) @if ($clave === 'sedan') data-por-defecto @endif>{{ $texto }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="campo-etiqueta" for="{{ $modo }}_ve_marca">Marca</label>
                                <input type="text" id="{{ $modo }}_ve_marca" name="marca" class="campo text-uppercase" maxlength="50" placeholder="Ej: Nissan" value="{{ $valor('marca') }}" required>
                            </div>
                            <div class="col-md-4">
                                <label class="campo-etiqueta" for="{{ $modo }}_ve_modelo">Modelo</label>
                                <input type="text" id="{{ $modo }}_ve_modelo" name="modelo" class="campo text-uppercase" maxlength="60" placeholder="Ej: Versa" value="{{ $valor('modelo') }}">
                            </div>
                        </div>

                        <div data-mostrar-si='{"tipo":["otro"]}'>
                            <label class="campo-etiqueta" for="{{ $modo }}_ve_otro">Describe el Tipo de Vehículo</label>
                            <input type="text" id="{{ $modo }}_ve_otro" name="descripcion_otro" class="campo" maxlength="150" placeholder="Ej: Montacargas, Carrito de golf..."
                                   value="{{ $valor('descripcion_otro') }}" data-requerido-si='{"tipo":["otro"]}'>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <label class="campo-etiqueta" for="{{ $modo }}_ve_color">Color Físico</label>
                                <input type="text" id="{{ $modo }}_ve_color" name="color" class="campo text-uppercase" maxlength="30" placeholder="Ej: Blanco" value="{{ $valor('color') }}" required>
                            </div>
                            <div class="col-md-4" data-mostrar-si='{"propiedad":{{ json_encode(\App\Models\Vehiculo::CON_NUMERO_ECONOMICO) }}}'>
                                <label class="campo-etiqueta" for="{{ $modo }}_ve_economico">Número Económico</label>
                                <input type="text" id="{{ $modo }}_ve_economico" name="numero_economico" class="campo text-uppercase" maxlength="30" placeholder="Ej: T-045" value="{{ $valor('numero_economico') }}">
                            </div>
                            <div class="col-md-4" data-mostrar-si='{"tipo":{{ json_encode(\App\Services\Vehiculos\AdministradorVehiculos::TIPOS_CON_CAPACIDAD) }},"propiedad":{{ json_encode(\App\Services\Vehiculos\AdministradorVehiculos::PROPIEDADES_CON_CAPACIDAD) }}}'>
                                <label class="campo-etiqueta" for="{{ $modo }}_ve_capacidad">Capacidad Máxima <span class="text-lowercase fw-normal">(opcional)</span></label>
                                <input type="number" id="{{ $modo }}_ve_capacidad" name="capacidad" class="campo" min="1" max="99" inputmode="numeric" placeholder="Ej: 15" value="{{ $valor('capacidad') }}">
                            </div>
                        </div>

                        <div class="dialogo-acciones">
                            <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                            <button type="submit" class="btn-pizarra">{{ $esNuevo ? 'Guardar Vehículo' : 'Actualizar Datos' }}</button>
                        </div>
                    </form>
                    @unless ($esNuevo)
                        @include('componentes.borrar', ['registro' => 'vehiculos', 'id' => $editandoId])
                    @endunless
                </div>
            </dialog>
        @endforeach

        @include('componentes.codigo-identificacion')
        @include('padrones.altas-por-verificar._dialogo', ['altas' => $altas])
    @endif
</div>
@endsection
