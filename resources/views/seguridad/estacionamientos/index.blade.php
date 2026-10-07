@extends('layouts.app')

@section('titulo', 'Estacionamientos y Zonas')

@section('contenido')
<div class="tema-azul pantalla-estacionamientos">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    @if ($sinEmpresa)
        <div class="encabezado-pantalla mb-4">
            <div class="icono"><i class="bi bi-p-square-fill text-primary" aria-hidden="true"></i></div>
            <div><h1>Estacionamientos y Zonas</h1><p>Cupos por sede.</p></div>
        </div>
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-buildings" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong> para ver sus estacionamientos.</p>
        </div>
    @else
        @php
            $dialogo = old('_dialogo');
            $editandoId = is_string($dialogo) && str_starts_with($dialogo, 'editar-') ? (int) substr($dialogo, 7) : null;
            $todas = $porSede->flatten(1);
        @endphp

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
            <div class="encabezado-pantalla m-0">
                <div class="icono"><i class="bi bi-p-square-fill text-primary" aria-hidden="true"></i></div>
                <div>
                    <h1>Estacionamientos y Zonas</h1>
                    <p>Cupos por sede — Estacionamiento (cuenta espacios) o Zona de Descarga (Lobby, Almacenes, Andén, Patio de maniobras; capacidad opcional).</p>
                </div>
            </div>
        </div>

        @if ($total > 0)
            <div class="filtros-zonas">
                <div class="buscador">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="search" placeholder="Buscar zona o sede..." aria-label="Buscar zona" data-filtro-texto="zonas">
                </div>
                @if ($sedesFiltro->count() > 1)
                    <select class="filtro-select" aria-label="Filtrar por sede" data-filtro-sede="zonas">
                        <option value="">Todas las sedes</option>
                        @foreach ($sedesFiltro as $s)
                            <option value="{{ $s->id }}">{{ $s->nombre }}</option>
                        @endforeach
                    </select>
                @endif
            </div>
            <div class="pildoras-tipo" role="group" aria-label="Filtrar por tipo de zona">
                <button type="button" class="btn-pill-tipo active" data-filtro-tipo="zonas" data-valor="" aria-pressed="true">Todas <span class="conteo-pill">{{ $total }}</span></button>
                <button type="button" class="btn-pill-tipo" data-filtro-tipo="zonas" data-valor="estacionamiento" aria-pressed="false"><i class="bi bi-p-circle me-1" aria-hidden="true"></i>Estacionamientos <span class="conteo-pill">{{ $todas->where('tipo', 'estacionamiento')->count() }}</span></button>
                <button type="button" class="btn-pill-tipo" data-filtro-tipo="zonas" data-valor="zona_descarga" aria-pressed="false"><i class="bi bi-truck me-1" aria-hidden="true"></i>Zonas de descarga <span class="conteo-pill">{{ $todas->where('tipo', 'zona_descarga')->count() }}</span></button>
            </div>
        @endif

        <div data-fichas="zonas">
            {{-- Ronda 6 (ES-02): «Nueva Zona» como la tarjeta de alta de los demás padrones --}}
            @if ($puede['crear'])
                <div class="fichas-grid zonas-grid mb-3">
                    <button type="button" class="ficha-card ficha-create" data-abrir-dialogo="dialogoNuevaZona">
                        <i class="bi bi-plus-circle-fill" aria-hidden="true"></i>
                        <span class="h6 fw-bold m-0 mt-2 titulo-crear">Nueva Zona</span>
                    </button>
                </div>
            @endif
            @forelse ($porSede as $sedeId => $zonas)
                <section class="grupo-sede-zonas" data-grupo-zonas aria-label="Zonas de {{ $zonas->first()->sede->nombre ?? 'sede' }}">
                    <h2 class="titulo-sede-zonas"><i class="bi bi-geo-alt-fill text-danger me-1" aria-hidden="true"></i>{{ $zonas->first()->sede->nombre ?? '—' }}
                        <span class="conteo-pill">{{ $zonas->count() }}</span></h2>
                    <div class="fichas-grid zonas-grid">
                        @foreach ($zonas as $z)
                            @php
                                $esDescarga = $z->esDescarga();
                                $ocupadas = $ocupados[$z->id] ?? 0;
                                $pct = $z->tieneCupo() ? min(100, (int) round($ocupadas / $z->cupo_total * 100)) : 0;
                                $lleno = $z->estaLlena($ocupadas);
                                $editable = $puede['editar'] && ($editables === null || in_array($z->id, $editables, true));
                                $desactivable = $puede['estado'] && ($desactivables === null || in_array($z->id, $desactivables, true));
                                $valores = json_encode($z->only(['sede_id', 'nombre', 'tipo', 'cupo_total']));
                            @endphp
                            <div class="ficha-card ficha-zona {{ $esDescarga ? 'tipo-descarga' : 'tipo-estac' }} {{ $z->activo ? '' : 'inactiva' }}" id="zona-{{ $z->id }}"
                                 data-ficha data-estado="{{ $z->activo ? '1' : '0' }}" data-sede="{{ $z->sede_id }}" data-tipo="{{ $z->tipo }}"
                                 data-texto="{{ mb_strtolower($z->nombre.' '.($z->sede->nombre ?? '').' '.\App\Models\ZonaEstacionamiento::DISTINTIVOS[$z->tipo]) }}">
                                <div>
                                    <div class="d-flex justify-content-between align-items-start gap-2">
                                        <div class="min-w-0">
                                            <span class="distintivo-zona {{ $esDescarga ? 'descarga' : 'estac' }}">{{ \App\Models\ZonaEstacionamiento::DISTINTIVOS[$z->tipo] }}</span>
                                            <h3 class="nombre-zona">{{ $z->nombre }}</h3>
                                            <div class="text-muted small">{{ $z->sede->nombre ?? '—' }}</div>
                                        </div>
                                        @unless ($z->activo)
                                            <span class="etiqueta-inactiva">Inactiva</span>
                                        @endunless
                                    </div>

                                    @if ($esDescarga && ! $z->tieneCupo())
                                        <div class="ocupacion-zona mt-3"><i class="bi bi-truck me-1 icono-descarga" aria-hidden="true"></i><strong>{{ $ocupadas }}</strong> {{ $ocupadas === 1 ? 'vehículo usando' : 'vehículos usando' }} el andén ahora</div>
                                    @else
                                        <div class="ocupacion-zona mt-3">
                                            <div class="d-flex justify-content-between small fw-bold">
                                                <span>@if ($esDescarga)<i class="bi bi-truck me-1 icono-descarga" aria-hidden="true"></i>{{ $ocupadas }} / {{ $z->cupo_total }} vehículos @else{{ $ocupadas }} / {{ $z->cupo_total ?? '—' }} espacios @endif</span>
                                                @if ($lleno)<span class="texto-lleno">LLENO</span>@endif
                                            </div>
                                            @if ($z->cupo_total)
                                                <div class="cupo-barra" role="progressbar" aria-label="Ocupación de {{ $z->nombre }}" aria-valuemin="0" aria-valuemax="{{ $z->cupo_total }}" aria-valuenow="{{ min($ocupadas, $z->cupo_total) }}">
                                                    <div class="cupo-barra-fill {{ $lleno ? 'lleno' : '' }}" style="width: {{ $pct }}%"></div>
                                                </div>
                                            @endif
                                        </div>
                                    @endif

                                    @if ($z->actualizado_por_nombre && $z->updated_at?->ne($z->created_at))
                                        <div class="texto-traza mt-2"><i class="bi bi-clock-history" aria-hidden="true"></i> Editado por {{ $z->actualizado_por_nombre }} · @fecha($z->updated_at)</div>
                                    @elseif ($z->creado_por_nombre)
                                        <div class="texto-traza mt-2"><i class="bi bi-plus-circle" aria-hidden="true"></i> Creado por {{ $z->creado_por_nombre }} · @fecha($z->created_at, 'd/m/Y')</div>
                                    @endif
                                </div>

                                @if ($editable || $desactivable)
                                    <div class="d-flex gap-2 mt-3 pt-2 border-top justify-content-end pie-zona">
                                        @if ($editable)
                                            <button type="button" class="btn-icono editar" title="Editar" aria-label="Editar zona {{ $z->nombre }}"
                                                    data-accion="editar-registro" data-dialogo="dialogoEditarZona"
                                                    data-url="{{ route('estacionamientos.update', $z->id) }}" data-id="{{ $z->id }}" data-valores="{{ $valores }}">
                                                <i class="bi bi-pencil-fill" aria-hidden="true"></i>
                                            </button>
                                        @endif
                                        @if ($desactivable)
                                            <form action="{{ route('estacionamientos.estado', $z->id) }}" method="POST" class="m-0"
                                                  data-confirmar="{{ $z->activo ? '¿Desactivar esta zona? Ya no se podrá asignar en Accesos.' : '¿Reactivar esta zona?' }}">
                                                @csrf
                                                @method('PATCH')
                                                <input type="hidden" name="activo" value="{{ $z->activo ? 0 : 1 }}">
                                                @if ($z->activo)
                                                    <button type="submit" class="btn-icono eliminar" title="Desactivar" aria-label="Desactivar zona {{ $z->nombre }}"><i class="bi bi-slash-circle" aria-hidden="true"></i></button>
                                                @else
                                                    <button type="submit" class="btn-icono reactivar" title="Reactivar" aria-label="Reactivar zona {{ $z->nombre }}"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></button>
                                                @endif
                                            </form>
                                        @endif
                                    </div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </section>
            @empty
                <div class="tarjeta estado-vacio">
                    <div class="icono"><i class="bi bi-p-square" aria-hidden="true"></i></div>
                    <p class="fw-semibold mb-1">Aún no hay zonas configuradas.</p>
                    <p class="text-muted small m-0">{{ $puede['crear'] ? 'Crea el primer estacionamiento o zona de descarga con la tarjeta «Nueva Zona».' : 'Cuando el administrador las configure, aquí verás el cupo de cada sede.' }}</p>
                </div>
            @endforelse

            <div class="sin-resultados" data-sin-resultados="zonas" hidden>
                <i class="bi bi-search d-block mb-2" aria-hidden="true"></i>
                <p class="fw-semibold m-0">No hay zonas que coincidan con tu búsqueda.</p>
            </div>
        </div>

        {{-- ===== Alta y edición (mismo formulario, como #formZona de SEGCAT) ===== --}}
        @foreach (['nueva' => $puede['crear'], 'editar' => $puede['editar']] as $modo => $permitido)
            @continue (! $permitido)
            @php
                $esNueva = $modo === 'nueva';
                $trasError = $esNueva ? $dialogo === 'crear' : $editandoId !== null;
                $valor = fn (string $campo, string $porDefecto = '') => $trasError ? (string) old($campo, $porDefecto) : $porDefecto;
                $sedes = $esNueva ? $sedesAlta : $sedesEdicion;
                $sedesExtra = $esNueva ? collect() : $sedesFiltro->whereNotIn('id', $sedes->pluck('id'));
                $unaSede = $esNueva && $sedes->count() === 1 ? (string) $sedes->first()->id : '';
            @endphp
            <dialog id="{{ $esNueva ? 'dialogoNuevaZona' : 'dialogoEditarZona' }}" class="dialogo" aria-labelledby="titulo-zona-{{ $modo }}" @if ($trasError) data-abrir-al-cargar @endif>
                <div class="dialogo-cabecera">
                    <h2 id="titulo-zona-{{ $modo }}"><i class="bi {{ $esNueva ? 'bi-p-square-fill' : 'bi-pencil-fill' }} me-2 text-primary" aria-hidden="true"></i>{{ $esNueva ? 'Nueva Zona' : 'Editar Zona' }}</h2>
                    <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
                <div class="dialogo-cuerpo">
                    <form action="{{ $esNueva ? route('estacionamientos.store') : ($editandoId ? route('estacionamientos.update', $editandoId) : '') }}" method="POST" autocomplete="off" data-form-zona>
                        @csrf
                        @unless ($esNueva) @method('PUT') @endunless
                        <input type="hidden" name="_dialogo" value="{{ $esNueva ? 'crear' : ($editandoId ? 'editar-'.$editandoId : '') }}" data-campo-dialogo>

                        <label class="campo-etiqueta" for="{{ $modo }}_zona_sede">Sede</label>
                        <select id="{{ $modo }}_zona_sede" name="sede_id" class="campo" required>
                            <option value="" @if ($unaSede === '') data-por-defecto @endif>-- Seleccionar --</option>
                            @foreach ($sedes as $s)
                                <option value="{{ $s->id }}" @selected($valor('sede_id', $unaSede) === (string) $s->id) @if ($unaSede === (string) $s->id) data-por-defecto @endif>{{ $s->nombre }}</option>
                            @endforeach
                            @foreach ($sedesExtra as $s)
                                <option value="{{ $s->id }}" hidden @selected($valor('sede_id') === (string) $s->id)>{{ $s->nombre }} (no disponible para cambiar)</option>
                            @endforeach
                        </select>
                        @if ($esNueva && $sedes->isEmpty())
                            <p class="campo-ayuda mb-3"><i class="bi bi-info-circle" aria-hidden="true"></i> No tienes sedes activas donde crear zonas. Pide al administrador que te asigne una.</p>
                        @endif

                        <label class="campo-etiqueta" for="{{ $modo }}_zona_nombre">Nombre de la Zona</label>
                        <input type="text" id="{{ $modo }}_zona_nombre" name="nombre" class="campo" maxlength="100" placeholder="Ej: Estacionamiento Colaboradores, Lobby, Almacén General..."
                               value="{{ $valor('nombre') }}" required>

                        <label class="campo-etiqueta" for="{{ $modo }}_zona_tipo">Tipo de Zona</label>
                        <select id="{{ $modo }}_zona_tipo" name="tipo" class="campo" required>
                            @foreach (\App\Models\ZonaEstacionamiento::TIPOS as $clave => $texto)
                                <option value="{{ $clave }}" @selected($valor('tipo', 'estacionamiento') === $clave) @if ($loop->first) data-por-defecto @endif>{{ $texto }}</option>
                            @endforeach
                        </select>

                        {{-- Ronda 6 (ES-02): obligatorio en estacionamientos; opcional en zonas de descarga --}}
                        <label class="campo-etiqueta" for="{{ $modo }}_zona_cupo">
                            <span data-mostrar-si='{"tipo":["estacionamiento"]}'>Cupo Total de Espacios</span>
                            <span data-mostrar-si='{"tipo":["zona_descarga"]}'>Capacidad máxima de vehículos <span class="text-lowercase fw-normal">(opcional)</span></span>
                        </label>
                        <input type="number" id="{{ $modo }}_zona_cupo" name="cupo_total" class="campo mb-1" min="1" max="9999" inputmode="numeric" placeholder="Ej: 40"
                               value="{{ $valor('cupo_total') }}" data-requerido-si='{"tipo":["estacionamiento"]}' data-mensaje-min="Debe ser de al menos 1 (en una zona de descarga, déjalo vacío si no tiene límite).">
                        <p class="campo-ayuda" data-mostrar-si='{"tipo":["zona_descarga"]}'>Si la escribes, verás cuántos vehículos hay (ej. 2 / 4) y te avisará cuando se llene (también en Accesos), igual que un estacionamiento. Déjala vacía si no tiene límite.</p>

                        <div class="dialogo-acciones">
                            <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                            <button type="submit" class="btn-azul">Guardar</button>
                        </div>
                    </form>
                    @unless ($esNueva)
                        @include('componentes.borrar', ['registro' => 'estacionamientos', 'id' => $editandoId])
                    @endunless
                </div>
            </dialog>
        @endforeach
    @endif
</div>
@endsection
