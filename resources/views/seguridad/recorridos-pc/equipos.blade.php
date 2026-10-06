@extends('layouts.app')

@section('titulo', 'Catálogo de Equipos PC')

@section('contenido')
@use('App\Models\EquipoPc')
<div class="tema-recorridos-pc pantalla-equipos-pc">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    @if ($sinEmpresa)
        <div class="encabezado-pantalla mb-4">
            <div class="icono"><i class="bi bi-shield-fill-check text-success" aria-hidden="true"></i></div>
            <div><h1>Catálogo de Equipos de Protección Civil</h1><p>Extintores, hidrantes y demás infraestructura fija — separado del equipo prestable de guardia.</p></div>
        </div>
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-buildings" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong> para ver su catálogo de equipos de Protección Civil.</p>
        </div>
    @else
        @php
            $dialogo = old('_dialogo');
            $editandoId = is_string($dialogo) && str_starts_with($dialogo, 'editar-') ? (int) substr($dialogo, 7) : null;
            $conteoActivos = $equipos->where('activo', true)->count();
            $zonas = $nodos->whereIn('nivel', [\App\Models\Espacio::EDIFICIO, \App\Models\Espacio::AREA]);
            $areas = $nodos->where('nivel', \App\Models\Espacio::AREA_ESPECIFICA);
        @endphp

        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-3">
            <div class="encabezado-pantalla m-0">
                <div class="icono"><i class="bi bi-shield-fill-check text-success" aria-hidden="true"></i></div>
                <div>
                    <h1>Catálogo de Equipos de Protección Civil</h1>
                    <p>Extintores, hidrantes y demás infraestructura fija — separado del equipo prestable de guardia.</p>
                    <p class="texto-padron-de"><i class="bi bi-building-check" aria-hidden="true"></i> Inventario de: <strong>{{ $empresaNombre }}</strong></p>
                </div>
            </div>
            <div class="rpc-acciones-cabecera">
                <a href="{{ route('recorridos_pc.index') }}" class="btn-rpc btn-rpc-oscuro-contorno"><i class="bi bi-arrow-left me-2" aria-hidden="true"></i>Recorridos PC</a>
            </div>
        </div>

        <div class="rpc-filtros">
            <div class="pildoras-tipo m-0" role="group" aria-label="Filtrar por estatus">
                <button type="button" class="btn-pill-tipo active" data-filtro-epc-estado="1" aria-pressed="true">Activos <span class="conteo-pill">{{ $conteoActivos }}</span></button>
                <button type="button" class="btn-pill-tipo" data-filtro-epc-estado="0" aria-pressed="false">De baja <span class="conteo-pill">{{ $equipos->count() - $conteoActivos }}</span></button>
                <button type="button" class="btn-pill-tipo" data-filtro-epc-estado="" aria-pressed="false">Todos <span class="conteo-pill">{{ $equipos->count() }}</span></button>
            </div>
            <div class="rpc-filtros-campos">
                @if ($sedesFiltro->count() > 1)
                    <select class="filtro-select" aria-label="Filtrar por sede" data-filtro-epc="sede">
                        <option value="">Todas las sedes</option>
                        @foreach ($sedesFiltro as $s)
                            <option value="{{ $s->id }}">{{ $s->nombre }}</option>
                        @endforeach
                    </select>
                @endif
                @if ($categoriasFiltro->count() > 1)
                    <select class="filtro-select" aria-label="Filtrar por tipo de equipo" data-filtro-epc="categoria">
                        <option value="">Todos los tipos</option>
                        @foreach ($categoriasFiltro as $c)
                            <option value="{{ $c }}">{{ EquipoPc::CATEGORIAS[$c] ?? $c }}</option>
                        @endforeach
                    </select>
                @endif
                <div class="buscador">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="search" placeholder="Buscar por ID, tipo, ubicación, sede..." aria-label="Buscar equipo" data-filtro-epc="texto">
                </div>
            </div>
        </div>

        <div class="fichas-grid rpc-fichas" data-lista-epc>
            @if ($puede['crear'])
                <button type="button" class="ficha-card ficha-create rpc-crear" data-abrir-dialogo="dialogoNuevoEquipoPc">
                    <i class="bi bi-plus-circle-fill" aria-hidden="true"></i>
                    <span class="h6 fw-bold m-0 mt-2 titulo-crear">Nuevo Equipo</span>
                </button>
            @endif

            @forelse ($equipos as $e)
                @php
                    $editable = $puede['editar'] && ($editables === null || in_array($e->id, $editables, true));
                    $desactivable = $puede['baja'] && ($desactivables === null || in_array($e->id, $desactivables, true));
                    $ubicacion = $e->espacio_id ? ($nodos->get($e->espacio_id)['texto'] ?? null) : null;
                    $texto = mb_strtolower(implode(' ', array_filter([$e->numero_serie, $e->etiquetaCategoria(), $ubicacion, $e->referencia, $e->sede?->nombre])));
                @endphp
                <article class="ficha-card rpc-ficha-equipo {{ $e->activo ? '' : 'inactiva' }}" id="equipopc-{{ $e->id }}" data-epc
                         data-sede="{{ $e->sede_id }}" data-categoria="{{ $e->categoria }}" data-activo="{{ $e->activo ? '1' : '0' }}" data-texto="{{ $texto }}">
                    <div>
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                            <span class="rpc-badge-categoria"><i class="bi {{ $e->icono() }} me-1" aria-hidden="true"></i>{{ $e->etiquetaCategoria() }}</span>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn-icono imprimir-qr" title="Ver código QR" aria-label="Ver código QR de {{ $e->numero_serie }}"
                                        data-ver-qr-pc data-qr="{{ route('recorridos_pc.equipos.qr', $e->id) }}" data-nombre="{{ $e->etiquetaCategoria().' '.$e->numero_serie }}"
                                        data-enlace="{{ route('lector.ir', $e->codigo_qr) }}" data-imprimir="{{ $puede['imprimir'] ? route('recorridos_pc.equipos.etiqueta', $e->id) : '' }}">
                                    <i class="bi bi-qr-code" aria-hidden="true"></i>
                                </button>
                                @if ($editable)
                                    <button type="button" class="btn-icono editar" title="Editar" aria-label="Editar equipo {{ $e->numero_serie }}"
                                            data-accion="editar-registro" data-dialogo="dialogoEditarEquipoPc"
                                            data-url="{{ route('recorridos_pc.equipos.update', $e->id) }}" data-id="{{ $e->id }}"
                                            data-valores="{{ json_encode(app(\App\Services\RecorridosPc\CatalogoEquiposPc::class)->valoresEdicion($e, $nodos)) }}">
                                        <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                    </button>
                                @endif
                            </div>
                        </div>
                        <div class="rpc-rotulo">NÚM. DE SERIE / ID</div>
                        <div class="rpc-serie">{{ $e->numero_serie }}</div>
                        @if ($sedesFiltro->count() > 1)
                            <div class="small text-success fw-bold mb-1"><i class="bi bi-building me-1" aria-hidden="true"></i>{{ $e->sede?->nombre }}</div>
                        @endif
                        <div class="small text-muted"><i class="bi bi-geo-alt me-1" aria-hidden="true"></i>{{ $ubicacion ?? 'Sin ubicación' }}</div>
                        @if ($e->referencia)
                            <div class="small"><i class="bi bi-signpost me-1" aria-hidden="true"></i>{{ $e->referencia }}</div>
                        @endif
                        @if ($e->etiqueta_nfc)
                            <div class="small rpc-nfc"><i class="bi bi-broadcast-pin me-1" aria-hidden="true"></i>Etiqueta NFC / RFID asignada</div>
                        @endif
                        @unless ($e->activo)
                            <div class="small rpc-texto-hallazgo fw-bold mt-1"><i class="bi bi-slash-circle me-1" aria-hidden="true"></i>DE BAJA</div>
                        @endunless
                        @if ($e->actualizado_por_nombre && $e->updated_at?->ne($e->created_at))
                            <div class="texto-traza mt-2"><i class="bi bi-clock-history" aria-hidden="true"></i> Editado por {{ $e->actualizado_por_nombre }} · @fecha($e->updated_at)</div>
                        @elseif ($e->creado_por_nombre)
                            <div class="texto-traza mt-2"><i class="bi bi-plus-circle" aria-hidden="true"></i> Creado por {{ $e->creado_por_nombre }} · @fecha($e->created_at, 'd/m/Y')</div>
                        @endif
                    </div>
                    @if ($puede['imprimir'] || $desactivable)
                        <div class="rpc-pie-ficha">
                            @if ($puede['imprimir'])
                                <a href="{{ route('recorridos_pc.equipos.etiqueta', $e->id) }}" target="_blank" rel="noopener" class="btn-icono imprimir-qr" title="Imprimir Etiqueta" aria-label="Imprimir etiqueta de {{ $e->numero_serie }}"><i class="bi bi-printer" aria-hidden="true"></i></a>
                            @else
                                <span></span>
                            @endif
                            @if ($desactivable)
                                @if ($e->activo)
                                    <form action="{{ route('recorridos_pc.equipos.desactivar', $e->id) }}" method="POST" class="m-0" data-confirmar="¿Dar de baja el equipo {{ $e->numero_serie }}? Ya no aparecerá en los recorridos.">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="btn-icono desactivar" title="Dar de baja" aria-label="Dar de baja {{ $e->numero_serie }}"><i class="bi bi-slash-circle" aria-hidden="true"></i></button>
                                    </form>
                                @else
                                    <form action="{{ route('recorridos_pc.equipos.reactivar', $e->id) }}" method="POST" class="m-0" data-confirmar="¿Reactivar el equipo {{ $e->numero_serie }}? Volverá a aparecer en los recorridos.">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="btn-icono reactivar" title="Reactivar" aria-label="Reactivar {{ $e->numero_serie }}"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></button>
                                    </form>
                                @endif
                            @endif
                        </div>
                    @endif
                </article>
            @empty
                @unless ($puede['crear'])
                    <div class="tarjeta estado-vacio rpc-vacio">
                        <div class="icono"><i class="bi bi-shield-check" aria-hidden="true"></i></div>
                        <p class="text-muted small m-0">Aún no hay equipos de Protección Civil registrados{{ $editables !== null ? ' en tus sedes' : '' }}.</p>
                    </div>
                @endunless
            @endforelse

            <div class="sin-resultados" data-sin-resultados-epc hidden>
                <i class="bi bi-search d-block mb-2" aria-hidden="true"></i>
                <p class="fw-semibold m-0">No hay equipos que coincidan con tu búsqueda.</p>
            </div>
        </div>

        {{-- ===== Alta y edición (comparten campos) ===== --}}
        @foreach (['nuevo' => $puede['crear'], 'editar' => $puede['editar']] as $modo => $permitido)
            @continue (! $permitido)
            @php
                $esNuevo = $modo === 'nuevo';
                $trasError = $esNuevo ? $dialogo === 'crear' : $editandoId !== null;
                $previo = $esNuevo && ! $trasError && is_array($siguiente) ? $siguiente : [];
                $valor = fn (string $campo, string $porDefecto = '') => $trasError ? (string) old($campo, $porDefecto) : (string) ($previo[$campo] ?? $porDefecto);
                $sedes = $esNuevo ? $sedesAlta : $sedesEdicion;
                $sedesExtra = $esNuevo ? collect() : $sedesFiltro->whereNotIn('id', $sedes->pluck('id'));
                $unaSede = $esNuevo && $sedes->count() === 1 ? (string) $sedes->first()->id : '';
            @endphp
            <dialog id="{{ $esNuevo ? 'dialogoNuevoEquipoPc' : 'dialogoEditarEquipoPc' }}" class="dialogo ancho dialogo-recorrido-pc" aria-labelledby="titulo-epc-{{ $modo }}"
                    @if ($trasError || ($esNuevo && $previo !== [])) data-abrir-al-cargar @endif>
                <div class="dialogo-cabecera">
                    <h2 id="titulo-epc-{{ $modo }}"><i class="bi {{ $esNuevo ? 'bi-shield-fill-check text-success' : 'bi-pencil-square text-success' }} me-2" aria-hidden="true"></i>{{ $esNuevo ? 'Nuevo Equipo de Protección Civil' : 'Editar Equipo' }}</h2>
                    <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
                <div class="dialogo-cuerpo">
                    <form action="{{ $esNuevo ? route('recorridos_pc.equipos.store') : ($editandoId ? route('recorridos_pc.equipos.update', $editandoId) : '') }}" method="POST" autocomplete="off" data-form-epc>
                        @csrf
                        @unless ($esNuevo) @method('PUT') @endunless
                        <input type="hidden" name="_dialogo" value="{{ $esNuevo ? 'crear' : ($editandoId ? 'editar-'.$editandoId : '') }}" data-campo-dialogo>
                        @if ($esNuevo && $previo !== [])
                            <p class="rpc-ayuda"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Capturando el siguiente: se conservaron la sede, el tipo y la ubicación del anterior.</p>
                        @endif

                        <div class="row">
                            <div class="col-md-6">
                                <label class="campo-etiqueta" for="{{ $modo }}_epc_sede">Sede</label>
                                <select id="{{ $modo }}_epc_sede" name="sede_id" class="campo" required data-epc-sede>
                                    <option value="" @if ($unaSede === '') data-por-defecto @endif>-- Seleccionar --</option>
                                    @foreach ($sedes as $s)
                                        <option value="{{ $s->id }}" @selected($valor('sede_id', $unaSede) === (string) $s->id) @if ($unaSede === (string) $s->id) data-por-defecto @endif>{{ $s->nombre }}</option>
                                    @endforeach
                                    @foreach ($sedesExtra as $s)
                                        <option value="{{ $s->id }}" hidden @selected($valor('sede_id') === (string) $s->id)>{{ $s->nombre }} (no disponible para cambiar)</option>
                                    @endforeach
                                </select>
                                @if ($esNuevo && $sedes->isEmpty())
                                    <p class="campo-ayuda mb-3">No tienes sedes activas donde registrar equipos. Pide al administrador que te asigne una.</p>
                                @endif
                            </div>
                            <div class="col-md-6">
                                <label class="campo-etiqueta" for="{{ $modo }}_epc_categoria">Tipo de Equipo</label>
                                <select id="{{ $modo }}_epc_categoria" name="categoria" class="campo" required>
                                    <option value="" data-por-defecto>-- Seleccionar --</option>
                                    @foreach (EquipoPc::CATEGORIAS as $clave => $texto)
                                        <option value="{{ $clave }}" @selected($valor('categoria') === $clave)>{{ $texto }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <label class="campo-etiqueta text-primary" for="{{ $modo }}_epc_serie">Núm. de Serie / ID (para QR)</label>
                        <input type="text" id="{{ $modo }}_epc_serie" name="numero_serie" class="campo text-uppercase fw-bold" maxlength="100" required autocapitalize="characters"
                               placeholder="Ej: EXT-01" value="{{ $trasError ? old('numero_serie') : '' }}">

                        <div class="row">
                            <div class="col-md-6">
                                <label class="campo-etiqueta" for="{{ $modo }}_epc_zona">Zona / Piso <span class="text-lowercase fw-normal">(opcional)</span></label>
                                <select id="{{ $modo }}_epc_zona" name="zona_id" class="campo" data-epc-zona>
                                    <option value="" data-por-defecto>-- Elige sede primero --</option>
                                    @foreach ($zonas as $n)
                                        <option value="{{ $n['id'] }}" data-sede="{{ $n['sede_id'] }}" @selected($valor('zona_id') === (string) $n['id']) @unless ($n['activo']) data-inactivo @endunless>{{ $n['texto'] }}{{ $n['activo'] ? '' : ' (desactivada)' }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="campo-etiqueta" for="{{ $modo }}_epc_area">Área Específica <span class="text-lowercase fw-normal">(opcional)</span></label>
                                <select id="{{ $modo }}_epc_area" name="area_especifica_id" class="campo" data-epc-area>
                                    <option value="" data-por-defecto>-- Opcional --</option>
                                    @foreach ($areas as $n)
                                        <option value="{{ $n['id'] }}" data-sede="{{ $n['sede_id'] }}" data-ancestros="{{ implode(' ', $n['ancestros']) }}" @selected($valor('area_especifica_id') === (string) $n['id']) @unless ($n['activo']) data-inactivo @endunless>{{ $n['nombre'] }}{{ $n['activo'] ? '' : ' (desactivada)' }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <label class="campo-etiqueta" for="{{ $modo }}_epc_ref">Referencia <span class="text-lowercase fw-normal">(opcional, para encontrarlo rápido)</span></label>
                        <input type="text" id="{{ $modo }}_epc_ref" name="referencia" class="campo" maxlength="150" placeholder="Ej: Junto al elevador de servicio" value="{{ $trasError ? old('referencia') : '' }}">

                        @include('componentes.lector', ['id' => $modo.'_epc_nfc', 'etiqueta' => 'Etiqueta NFC / RFID (opcional)', 'modo' => 'capturar',
                            'nombre' => 'etiqueta_nfc', 'valor' => $trasError ? old('etiqueta_nfc') : '',
                            'ayuda' => 'Acerca la etiqueta pegada al equipo para que el lector la reconozca. Puedes dejarlo vacío: el equipo siempre se encuentra con su QR o su ID.'])

                        <div class="dialogo-acciones">
                            <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                            @if ($esNuevo)
                                <button type="submit" name="_siguiente" value="1" class="btn-rpc-claro">Guardar y capturar siguiente</button>
                            @endif
                            <button type="submit" class="btn-rpc-verde">{{ $esNuevo ? 'Guardar' : 'Guardar Cambios' }}</button>
                        </div>
                    </form>
                    @unless ($esNuevo)
                        @include('componentes.borrar', ['registro' => 'equipos_pc', 'id' => $editandoId])
                    @endunless
                </div>
            </dialog>
        @endforeach

        {{-- ===== Ver QR ===== --}}
        <dialog id="dialogoQrEquipoPc" class="dialogo dialogo-qr-eq" aria-labelledby="titulo-epc-qr">
            <div class="dialogo-cuerpo text-center">
                <h2 class="h5 fw-bold mb-3" id="titulo-epc-qr" data-qr-nombre></h2>
                <img src="" alt="Código QR del equipo" width="220" height="220" class="qr-eq-imagen" data-qr-imagen>
                <p class="text-muted small mt-3 mb-1">Dirección vinculada (para grabar en la etiqueta NFC):</p>
                <p class="qr-eq-codigo" data-qr-enlace></p>
                <a href="#" target="_blank" rel="noopener" class="btn-qr-eq imprimir" data-qr-imprimir hidden><i class="bi bi-printer me-1" aria-hidden="true"></i>Imprimir Etiqueta</a>
                <button type="button" class="btn-qr-eq" data-cerrar-dialogo>Cerrar</button>
            </div>
        </dialog>
    @endif
</div>
@endsection
