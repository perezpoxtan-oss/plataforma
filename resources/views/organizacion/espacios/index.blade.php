@extends('layouts.app')

@section('titulo', 'Zonas y áreas')

@section('contenido')
<div class="tema-rojo">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    @if ($sinEmpresa)
        <div class="encabezado-pantalla mb-4">
            <div class="icono"><i class="bi bi-geo-alt-fill text-danger" aria-hidden="true"></i></div>
            <div><h1>Zonas / Edificios</h1><p>Estructura física de cada sede.</p></div>
        </div>
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-buildings" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong> para ver sus zonas y áreas.</p>
        </div>
    @else
        @php $enSecciones = request('pestana') === 'secciones'; @endphp

        <div class="pestanas" role="tablist">
            <a href="{{ route('espacios.index') }}" class="btn {{ $enSecciones ? 'btn-outline-dark' : 'btn-dark' }}" role="tab" aria-selected="{{ $enSecciones ? 'false' : 'true' }}"><i class="bi bi-building me-1" aria-hidden="true"></i> Zonas y Edificios</a>
            <a href="{{ route('espacios.index', ['pestana' => 'secciones']) }}" class="btn {{ $enSecciones ? 'btn-dark' : 'btn-outline-dark' }}" role="tab" aria-selected="{{ $enSecciones ? 'true' : 'false' }}"><i class="bi bi-bookmark me-1" aria-hidden="true"></i> Secciones</a>
        </div>

        @if ($sedes->isEmpty())
            <div class="tarjeta estado-vacio">
                <div class="icono"><i class="bi bi-house-door" aria-hidden="true"></i></div>
                <p class="text-muted small m-0">Primero registra una sede en <a href="{{ route('sedes.index') }}">Estructura → Sedes</a>.</p>
            </div>
        @elseif (! $enSecciones)
            {{-- ================= Zonas / Edificios ================= --}}
            <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
                <div class="encabezado-pantalla m-0">
                    <div class="icono"><i class="bi bi-geo-alt-fill text-danger" aria-hidden="true"></i></div>
                    <div>
                        <h1>Zonas / Edificios</h1>
                        <p>Nivel 1 — entra a una zona para ver sus Pisos y {{ $etiquetas['area_especifica']['plural'] }}.</p>
                    </div>
                </div>
                <div class="barra-filtros justify-content-md-end">
                    @if ($sedes->count() > 1)
                        <select class="filtro-select" aria-label="Filtrar por sede" data-filtro-sede="zonas">
                            <option value="">Todas las sedes</option>
                            @foreach ($sedes as $sede)
                                <option value="{{ $sede->id }}">{{ $sede->nombre }}</option>
                            @endforeach
                        </select>
                    @endif
                    <div class="buscador">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <input type="search" placeholder="Buscar zona o sede..." aria-label="Buscar zonas" data-filtro-texto="zonas">
                    </div>
                </div>
            </div>

            <div class="fichas-grid" data-fichas="zonas">
                @if ($puede['crear'])
                    <button type="button" class="ficha-card ficha-create" data-abrir-dialogo="dialogoNuevaZona">
                        <i class="bi bi-plus-circle-fill" aria-hidden="true"></i>
                        <span class="h6 fw-bold m-0 mt-2 titulo-crear">Nueva Zona / Edificio</span>
                    </button>
                @endif

                @foreach ($edificios as $ed)
                    @php $sede = $sedes->firstWhere('id', $ed->sede_id); @endphp
                    <div class="ficha-card" data-ficha data-estado="{{ $ed->activo ? 1 : 0 }}" data-sede="{{ $ed->sede_id }}"
                         data-texto="{{ mb_strtolower($ed->nombre.' '.$ed->codigo.' '.$sede?->nombre) }}" @unless ($ed->activo) style="opacity:.6" @endunless>
                        <div>
                            <div class="d-flex justify-content-between align-items-start gap-2">
                                <h2 class="h5 fw-bold m-0"><i class="bi bi-building text-danger me-2" aria-hidden="true"></i>{{ $ed->nombre }}
                                    @if ($ed->codigo)<span class="text-muted small fw-semibold">({{ $ed->codigo }})</span>@endif
                                    @unless ($ed->activo)<span class="etiqueta-inactiva ms-1">INACTIVA</span>@endunless
                                </h2>
                                @include('organizacion.espacios.partes.acciones', ['e' => $ed, 'dialogo' => 'dialogoEditarZona', 'valores' => $ed->only(['nombre', 'codigo', 'tipo_espacio_id'])])
                            </div>
                            <div class="text-muted small mb-2 border-bottom pb-2 mt-1 d-flex justify-content-between gap-2 flex-wrap">
                                <span><i class="bi bi-geo-alt" aria-hidden="true"></i> Sede: {{ $sede?->nombre }}@if ($ed->tipo) · {{ $ed->tipo->nombre }}@endif</span>
                                <span>Desde @fecha($ed->created_at, 'm/Y')</span>
                            </div>
                            <div class="small text-muted"><i class="bi bi-layers" aria-hidden="true"></i> {{ $ed->pisos_count }} Piso(s) registrado(s)</div>
                            @if ($ed->actualizado_por_nombre && $ed->updated_at?->ne($ed->created_at))
                                <div class="texto-traza mt-1"><i class="bi bi-clock-history" aria-hidden="true"></i> Editado por {{ $ed->actualizado_por_nombre }} · @fecha($ed->updated_at)</div>
                            @endif
                        </div>
                        <a href="{{ route('espacios.show', $ed->id) }}" class="btn-entrar"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> Entrar a Pisos</a>
                    </div>
                @endforeach
            </div>
            <p class="text-muted text-center p-4" data-sin-resultados="zonas" hidden><i class="bi bi-search me-2" aria-hidden="true"></i>No hay zonas que coincidan con tu búsqueda.</p>

            @if ($puede['crear'])
                @include('organizacion.espacios.partes.formulario', [
                    'id' => 'dialogoNuevaZona', 'titulo' => 'Alta de Zona / Edificio', 'icono' => 'bi-building text-danger',
                    'nivel' => 'edificio', 'padreId' => null, 'sedes' => $sedes, 'tipos' => $tiposEdificio, 'conCodigo' => true,
                    'editar' => false, 'boton' => 'Registrar Zona', 'claseBoton' => 'btn-rojo', 'etiquetaNombre' => 'Nombre de la Zona / Edificio',
                    // Las zonas no llevan sección; sin esto heredan $secciones (agrupadas por sede) de la pantalla
                    'secciones' => null,
                ])
            @endif
            @if ($puede['editar'])
                @include('organizacion.espacios.partes.formulario', [
                    'id' => 'dialogoEditarZona', 'titulo' => 'Editar Zona / Edificio', 'icono' => 'bi-pencil-square text-danger',
                    'nivel' => 'edificio', 'tipos' => $tiposEdificio, 'conCodigo' => true,
                    'editar' => true, 'boton' => 'Guardar Cambios', 'claseBoton' => 'btn-rojo', 'etiquetaNombre' => 'Nombre de la Zona / Edificio',
                    // Las zonas no llevan sección; sin esto heredan $secciones (agrupadas por sede) de la pantalla
                    'secciones' => null,
                ])
            @endif
        @else
            {{-- ================= Secciones ================= --}}
            <div class="encabezado-pantalla mb-3">
                <div class="icono"><i class="bi bi-bookmark-fill text-danger" aria-hidden="true"></i></div>
                <div>
                    <h1>Secciones</h1>
                    <p>Agrupan {{ mb_strtolower($etiquetas['area_especifica']['plural']) }} de una sede (Torre Norte, Villas, Ala Mar…). Se asignan al dar de alta o editar cada una.</p>
                </div>
            </div>

            @if ($puede['crear'])
                <button type="button" class="btn btn-dark fw-bold mb-3" style="min-height:44px;border-radius:10px;" data-abrir-dialogo="dialogoNuevaSeccion"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i> Nueva Sección</button>
            @endif

            @if ($secciones->isEmpty())
                <div class="tarjeta estado-vacio">
                    <div class="icono"><i class="bi bi-bookmark" aria-hidden="true"></i></div>
                    <p class="fw-semibold m-0">Todavía no hay ninguna Sección registrada.</p>
                </div>
            @else
                @foreach ($secciones as $sedeId => $lista)
                    @php $sede = $sedes->firstWhere('id', $sedeId); @endphp
                    <h2 class="h6 fw-bold text-dark mb-2 mt-3"><i class="bi bi-geo-alt me-1 text-danger" aria-hidden="true"></i>{{ $sede?->nombre }}</h2>
                    <div class="fichas-grid" style="margin-top:.5rem">
                        @foreach ($lista as $seccion)
                            <div class="ficha-card ficha-compacta">
                                <div class="d-flex justify-content-between align-items-start gap-2">
                                    <h3 class="h6 fw-bold m-0"><i class="bi bi-bookmark-fill text-danger me-1" aria-hidden="true"></i>{{ $seccion->nombre }}</h3>
                                    <span class="chip-seccion">{{ $sede?->codigo }}-{{ $seccion->nombre }}</span>
                                </div>
                                <div class="small text-muted mt-2"><i class="bi bi-door-closed me-1" aria-hidden="true"></i>{{ $seccion->total }} {{ $seccion->total == 1 ? $etiquetas['area_especifica']['singular'] : $etiquetas['area_especifica']['plural'] }}</div>
                                @if ($puede['editar'])
                                    <button type="button" class="btn-entrar border-0 w-100" data-abrir-dialogo="dialogoAsignar{{ $seccion->id }}"><i class="bi bi-check2-square" aria-hidden="true"></i> Asignar {{ $etiquetas['area_especifica']['plural'] }}</button>
                                @endif
                            </div>

                            @if ($puede['editar'])
                                @php $lista = $porAsignar[$sedeId] ?? collect(); @endphp
                                <dialog id="dialogoAsignar{{ $seccion->id }}" class="dialogo" aria-labelledby="tituloAsignar{{ $seccion->id }}">
                                    <div class="dialogo-cabecera">
                                        <h2 id="tituloAsignar{{ $seccion->id }}"><i class="bi bi-bookmark-fill me-2 text-danger" aria-hidden="true"></i>{{ $etiquetas['area_especifica']['plural'] }} en «{{ $seccion->nombre }}»</h2>
                                        <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                                    </div>
                                    <div class="dialogo-cuerpo">
                                        <form action="{{ route('espacios.seccion.asignar', $seccion->id) }}" method="POST">
                                            @csrf
                                            @method('PUT')
                                            @if ($lista->isEmpty())
                                                <p class="text-muted small">Esta sede todavía no tiene {{ mb_strtolower($etiquetas['area_especifica']['plural']) }} activas.</p>
                                            @else
                                                <div class="buscador mb-2">
                                                    <i class="bi bi-search" aria-hidden="true"></i>
                                                    <input type="search" placeholder="Buscar por número o piso..." aria-label="Buscar" data-filtro-texto="asignar{{ $seccion->id }}">
                                                </div>
                                                <p class="campo-ayuda mb-2">Marca las que pertenecen a esta sección. Si una ya estaba en otra sección, se cambia a esta.</p>
                                                <div class="lista-asignar" data-fichas="asignar{{ $seccion->id }}">
                                                    @foreach ($lista as $h)
                                                        @php $donde = collect([$h->padre?->padre?->nombre, $h->padre?->nombre])->filter()->join(' · '); @endphp
                                                        <label class="opcion-asignar" data-ficha data-texto="{{ mb_strtolower($h->nombre.' '.$donde) }}">
                                                            <input type="checkbox" class="form-check-input" name="espacios[]" value="{{ $h->id }}" @checked($h->grupo_espacio_id === $seccion->id)>
                                                            <span><strong>{{ $h->nombre }}</strong> <span class="text-muted small">{{ $donde }}</span></span>
                                                            @if ($h->grupo_espacio_id && $h->grupo_espacio_id !== $seccion->id)<span class="chip-seccion ms-auto">otra sección</span>@endif
                                                        </label>
                                                    @endforeach
                                                </div>
                                            @endif
                                            <div class="dialogo-acciones">
                                                <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                                                <button type="submit" class="btn-rojo">Guardar</button>
                                            </div>
                                        </form>
                                    </div>
                                </dialog>
                            @endif
                        @endforeach
                    </div>
                @endforeach
            @endif

            @if ($puede['crear'])
                <dialog id="dialogoNuevaSeccion" class="dialogo" aria-labelledby="tituloNuevaSeccion" @if (old('_dialogo') === 'seccion') data-abrir-al-cargar @endif>
                    <div class="dialogo-cabecera">
                        <h2 id="tituloNuevaSeccion"><i class="bi bi-bookmark-fill me-2 text-danger" aria-hidden="true"></i>Nueva Sección</h2>
                        <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                    </div>
                    <div class="dialogo-cuerpo">
                        <form action="{{ route('espacios.seccion') }}" method="POST" autocomplete="off">
                            @csrf
                            <input type="hidden" name="_dialogo" value="seccion">
                            @if ($sedes->count() > 1)
                                <label class="campo-etiqueta" for="seccion_sede">Sede</label>
                                <select id="seccion_sede" name="sede_id" class="campo" required>
                                    @foreach ($sedes as $sede)
                                        <option value="{{ $sede->id }}">{{ $sede->nombre }}</option>
                                    @endforeach
                                </select>
                            @else
                                <input type="hidden" name="sede_id" value="{{ $sedes->first()->id }}">
                            @endif
                            <label class="campo-etiqueta" for="seccion_nombre">Nombre de la Sección</label>
                            <input type="text" id="seccion_nombre" name="nombre" class="campo" maxlength="50" value="{{ old('_dialogo') === 'seccion' ? old('nombre') : '' }}" required
                                   data-duplicado="{{ route('espacios.duplicado') }}" data-duplicado-campo="seccion" data-duplicado-con="sede_id">
                            <div class="dialogo-acciones">
                                <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                                <button type="submit" class="btn-rojo">Crear Sección</button>
                            </div>
                        </form>
                    </div>
                </dialog>
            @endif
        @endif
    @endif
</div>
@endsection
