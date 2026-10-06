@extends('layouts.app')

@section('titulo', 'Rutas · '.$sede->nombre)

@section('contenido')
@php
    $sentidoTab = \App\Http\Controllers\Padrones\RutaController::PESTANAS[$tab];
    $llegadas = $rutas->where('sentido', 'llegada');
    $salidas = $rutas->where('sentido', 'salida');
    $dialogo = old('_dialogo');
    $editandoId = is_string($dialogo) && str_starts_with($dialogo, 'editar-') ? (int) substr($dialogo, 7) : null;
    $editandoParadero = is_string($dialogo) && str_starts_with($dialogo, 'paradero-editar-') ? (int) substr($dialogo, 16) : null;
    $pestanas = [
        'llegadas' => ['bi-sign-turn-right-fill', 'Llegadas', $llegadas->count()],
        'salidas' => ['bi-sign-turn-left-fill', 'Salidas', $salidas->count()],
        'paraderos' => ['bi-geo-alt-fill', 'Paraderos', $paraderos->where('activo', true)->count()],
    ];
    // Horarios capturados que regresaron con un error de validación
    $horariosDeOld = collect(old('horarios', []))->filter(fn ($h) => is_array($h))->values()->map(fn ($h) => [
        'id' => $h['id'] ?? '',
        'nombre' => $h['nombre'] ?? '',
        'hora_inicio' => $h['hora_inicio'] ?? '',
        'hora_fin' => $h['hora_fin'] ?? '',
        'dias' => array_values(array_filter((array) ($h['dias'] ?? []), 'is_string')),
        'paraderos' => array_values(array_filter((array) ($h['paraderos'] ?? []), 'is_array')),
    ])->all();
@endphp
<div class="tema-rutas">
    @include('administracion.partes.avisos')

    <nav class="migas" aria-label="Ubicación">
        <a href="{{ route('rutas.index') }}"><i class="bi bi-bus-front" aria-hidden="true"></i> Rutas de Transporte</a>
        <i class="bi bi-chevron-right" aria-hidden="true"></i>
        <span class="actual" aria-current="page">{{ $sede->nombre }}</span>
    </nav>

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
        <div class="encabezado-pantalla m-0">
            <div class="icono"><i class="bi bi-building rutas-icono-titulo" aria-hidden="true"></i></div>
            <div>
                <h1>{{ $sede->nombre }}</h1>
                <p>Rutas de llegada y salida de esta sede, y sus paraderos.</p>
                <p class="texto-padron-de"><i class="bi bi-building-check" aria-hidden="true"></i> {{ $empresaNombre }}</p>
            </div>
        </div>
        <div class="rutas-acciones-sede">
            <a href="{{ route('rutas.index') }}" class="btn-cancelar rutas-boton"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Sedes</a>
            @if ($puede['imprimir'])
                <a href="{{ route('rutas.dia', $sede->id) }}" target="_blank" rel="noopener" class="btn-rutas rutas-boton"><i class="bi bi-printer me-1" aria-hidden="true"></i>Hoja del día</a>
            @endif
        </div>
    </div>

    <nav class="rutas-pestanas" aria-label="Secciones de la sede">
        @foreach ($pestanas as $clave => [$icono, $texto, $total])
            <a href="{{ route('rutas.sede', ['sede' => $sede->id, 'tab' => $clave]) }}" class="rutas-pestana {{ $tab === $clave ? 'activa' : '' }}" @if ($tab === $clave) aria-current="page" @endif>
                <i class="bi {{ $icono }} me-1" aria-hidden="true"></i>{{ $texto }} ({{ $total }})
            </a>
        @endforeach
    </nav>

    @if ($sentidoTab !== null)
        {{-- ===== Llegadas / Salidas ===== --}}
        @php
            $lista = $sentidoTab === 'llegada' ? $llegadas : $salidas;
            $clave = 'rutas-'.$sentidoTab;
            $etiquetaSentido = $sentidoTab === 'llegada' ? 'Llegada' : 'Salida';
        @endphp

        @if ($puede['crear'])
            <button type="button" class="rutas-crear-mini" data-abrir-dialogo="dialogoNuevaRuta">
                <i class="bi bi-plus-circle me-1" aria-hidden="true"></i> Nueva {{ $etiquetaSentido }}
            </button>
        @endif

        @if ($lista->isNotEmpty())
            <div class="rutas-filtros">
                <div class="buscador">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="search" placeholder="Buscar ruta, transportista o paradero..." aria-label="Buscar ruta" data-filtro-texto="{{ $clave }}">
                </div>
                <div class="filtros-estado m-0" role="group" aria-label="Filtrar por estado">
                    <button type="button" class="btn btn-dark btn-sm fw-bold" data-filtro-estado="{{ $clave }}" data-valor="todas" aria-pressed="true">Todas</button>
                    <button type="button" class="btn btn-outline-success btn-sm fw-bold" data-filtro-estado="{{ $clave }}" data-valor="1" aria-pressed="false">Activas</button>
                    <button type="button" class="btn btn-outline-danger btn-sm fw-bold" data-filtro-estado="{{ $clave }}" data-valor="0" aria-pressed="false">Suspendidas</button>
                </div>
            </div>
        @endif

        <div class="rutas-lista" data-fichas="{{ $clave }}">
            @forelse ($lista as $r)
                @php
                    $editable = in_array($r->id, $editables, true);
                    $desactivable = in_array($r->id, $desactivables, true);
                    $totalParaderos = $r->horarios->flatMap->paradas->pluck('paradero_id')->unique()->count();
                    $texto = mb_strtolower(implode(' ', array_filter([
                        $r->nombre, $r->turno?->nombre, $r->proveedor?->nombre,
                        $r->horarios->flatMap->paradas->map(fn ($p) => $p->paradero?->nombre)->unique()->implode(' '),
                    ])));
                    $valores = json_encode([
                        'sentido' => $r->sentido,
                        'nombre' => $r->nombre,
                        'turno_id' => $r->turno_id,
                        'proveedor_id' => $r->proveedor_id,
                        'costo_maximo_taxi' => $r->costo_maximo_taxi,
                        'horarios' => $r->horarios->map(fn ($h) => [
                            'id' => $h->id, 'nombre' => $h->nombre, 'dias' => $h->listaDias(),
                            'hora_inicio' => $h->inicio(), 'hora_fin' => $h->fin(),
                            'paraderos' => $h->paradas->map(fn ($p) => ['nombre' => $p->paradero?->nombre, 'hora' => $p->horaCorta()])->values()->all(),
                        ])->values()->all(),
                    ]);
                @endphp
                <article class="rutas-fila {{ $r->activo ? '' : 'inactiva' }}" id="ruta-{{ $r->id }}" data-ficha data-texto="{{ $texto }}" data-estado="{{ $r->activo ? '1' : '0' }}">
                    <div class="rutas-fila-info">
                        <h2 class="rutas-fila-nombre">{{ $r->nombre }}</h2>
                        <div class="rutas-fila-meta">
                            <span><i class="bi bi-clock" aria-hidden="true"></i> {{ substr((string) $r->hora_inicio, 0, 5) }}–{{ substr((string) $r->hora_fin, 0, 5) }}</span>
                            <span><i class="bi bi-clock-history" aria-hidden="true"></i> {{ $r->turno?->nombre ?? '—' }}</span>
                            <span><i class="bi bi-truck" aria-hidden="true"></i> {{ $r->proveedor?->nombre ?? '—' }}</span>
                            @if ($totalParaderos > 0)
                                <span title="Paraderos"><i class="bi bi-geo-alt" aria-hidden="true"></i> {{ $totalParaderos }} {{ $totalParaderos === 1 ? 'paradero' : 'paraderos' }}</span>
                            @endif
                            @if ($r->costo_maximo_taxi !== null)
                                <span title="Costo máximo por taxi"><i class="bi bi-cash-coin" aria-hidden="true"></i> Taxi máx. ${{ number_format((float) $r->costo_maximo_taxi, 2) }}</span>
                            @endif
                            <span class="etiqueta-estado {{ $r->activo ? 'activo' : 'inactivo' }}">{{ $r->activo ? 'ACTIVA' : 'SUSPENDIDA' }}</span>
                        </div>
                        <ul class="rutas-fila-horarios" aria-label="Horarios">
                            @foreach ($r->horarios as $h)
                                <li title="{{ $h->nombre }} · {{ $h->textoDias() }}">
                                    <strong>{{ $h->patronDias() }}</strong> {{ $h->inicio() }}–{{ $h->fin() }}@if ($h->cruzaMedianoche())<span class="rutas-dia-siguiente" title="Llega al día siguiente">+1</span>@endif
                                    @if ($r->horarios->count() > 1)<span class="rutas-horario-nombre">· {{ $h->nombre }}</span>@endif
                                </li>
                            @endforeach
                        </ul>
                        @if ($r->actualizado_por_nombre && $r->updated_at?->ne($r->created_at))
                            <div class="texto-traza mt-1"><i class="bi bi-clock-history" aria-hidden="true"></i> Editado por {{ $r->actualizado_por_nombre }} · @fecha($r->updated_at)</div>
                        @elseif ($r->creado_por_nombre)
                            <div class="texto-traza mt-1"><i class="bi bi-plus-circle" aria-hidden="true"></i> Creado por {{ $r->creado_por_nombre }} · @fecha($r->created_at)</div>
                        @endif
                    </div>
                    <div class="rutas-fila-acciones">
                        @if ($puede['imprimir'])
                            <a href="{{ route('rutas.itinerario', $r->id) }}" target="_blank" rel="noopener" class="btn-icono imprimir-hoja" title="Imprimir itinerario" aria-label="Imprimir itinerario de {{ $r->nombre }}"><i class="bi bi-printer" aria-hidden="true"></i></a>
                        @endif
                        @if ($editable)
                            <button type="button" class="btn-icono editar" title="Editar" aria-label="Editar ruta {{ $r->nombre }}"
                                    data-accion="editar-ruta" data-dialogo="dialogoEditarRuta"
                                    data-url="{{ route('rutas.update', $r->id) }}" data-id="{{ $r->id }}" data-valores="{{ $valores }}">
                                <i class="bi bi-pencil-square" aria-hidden="true"></i>
                            </button>
                        @endif
                        @if ($puede['crear'])
                            <form action="{{ route('rutas.clonar', $r->id) }}" method="POST" class="m-0"
                                  data-confirmar="¿Clonar esta ruta? Se creará una copia con los mismos horarios y paraderos, que podrás ajustar después.">
                                @csrf
                                <button type="submit" class="btn-icono clonar" title="Clonar ruta" aria-label="Clonar ruta {{ $r->nombre }}"><i class="bi bi-copy" aria-hidden="true"></i></button>
                            </form>
                        @endif
                        @if ($desactivable)
                            <form action="{{ route('rutas.estado', $r->id) }}" method="POST" class="m-0"
                                  data-confirmar="{{ $r->activo ? '¿Suspender esta ruta? Podrás reactivarla con el mismo botón.' : '¿Reactivar esta ruta?' }}">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="activo" value="{{ $r->activo ? 0 : 1 }}">
                                @if ($r->activo)
                                    <button type="submit" class="btn-icono eliminar" title="Suspender" aria-label="Suspender ruta {{ $r->nombre }}"><i class="bi bi-slash-circle" aria-hidden="true"></i></button>
                                @else
                                    <button type="submit" class="btn-icono reactivar" title="Reactivar" aria-label="Reactivar ruta {{ $r->nombre }}"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></button>
                                @endif
                            </form>
                        @endif
                    </div>
                </article>
            @empty
                <div class="tarjeta estado-vacio rutas-vacio">
                    <div class="icono"><i class="bi bi-signpost-2" aria-hidden="true"></i></div>
                    <p class="small m-0">Sin rutas de {{ mb_strtolower($etiquetaSentido) }} registradas todavía.</p>
                    @if ($puede['crear'])
                        <p class="text-muted small m-0">Usa «Nueva {{ $etiquetaSentido }}» para dar de alta la primera.</p>
                    @endif
                </div>
            @endforelse

            <div class="sin-resultados" data-sin-resultados="{{ $clave }}" hidden>
                <i class="bi bi-search d-block mb-2" aria-hidden="true"></i>
                <p class="fw-semibold m-0">No hay rutas que coincidan con tu búsqueda.</p>
            </div>
        </div>
    @else
        {{-- ===== Paraderos de la sede ===== --}}
        @if ($puede['crear'])
            <button type="button" class="rutas-crear-mini" data-abrir-dialogo="dialogoNuevoParadero">
                <i class="bi bi-plus-circle me-1" aria-hidden="true"></i> Nuevo Paradero
            </button>
        @endif
        <p class="text-muted small rutas-nota"><i class="bi bi-info-circle" aria-hidden="true"></i> Propios de esta sede: nunca se comparten ni se mezclan con los de otra sede.</p>

        @if ($paraderos->isNotEmpty())
            <div class="rutas-filtros">
                <div class="buscador">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="search" placeholder="Buscar paradero..." aria-label="Buscar paradero" data-filtro-texto="rutas-paraderos">
                </div>
                <div class="filtros-estado m-0" role="group" aria-label="Filtrar por estado">
                    <button type="button" class="btn btn-dark btn-sm fw-bold" data-filtro-estado="rutas-paraderos" data-valor="todas" aria-pressed="true">Todos</button>
                    <button type="button" class="btn btn-outline-success btn-sm fw-bold" data-filtro-estado="rutas-paraderos" data-valor="1" aria-pressed="false">Activos</button>
                    <button type="button" class="btn btn-outline-danger btn-sm fw-bold" data-filtro-estado="rutas-paraderos" data-valor="0" aria-pressed="false">Desactivados</button>
                </div>
            </div>
        @endif

        <div class="rutas-lista" data-fichas="rutas-paraderos">
            @forelse ($paraderos as $p)
                @php $enRutas = $usos[$p->id] ?? 0; @endphp
                <article class="rutas-fila rutas-fila-paradero {{ $p->activo ? '' : 'inactiva' }}" id="paradero-{{ $p->id }}" data-ficha data-texto="{{ mb_strtolower($p->nombre) }}" data-estado="{{ $p->activo ? '1' : '0' }}">
                    <div class="rutas-fila-info">
                        <h2 class="rutas-fila-nombre"><i class="bi bi-signpost-split-fill rutas-icono-paradero me-1" aria-hidden="true"></i>{{ $p->nombre }}</h2>
                        <div class="rutas-fila-meta">
                            <span>{{ $enRutas === 0 ? 'Ninguna ruta activa lo usa' : ($enRutas === 1 ? 'Lo usa 1 ruta activa' : "Lo usan {$enRutas} rutas activas") }}</span>
                            @unless ($p->activo)
                                <span class="etiqueta-estado inactivo">DESACTIVADO</span>
                            @endunless
                        </div>
                        @if ($p->actualizado_por_nombre && $p->updated_at?->ne($p->created_at))
                            <div class="texto-traza mt-1"><i class="bi bi-clock-history" aria-hidden="true"></i> Editado por {{ $p->actualizado_por_nombre }} · @fecha($p->updated_at)</div>
                        @elseif ($p->creado_por_nombre)
                            <div class="texto-traza mt-1"><i class="bi bi-plus-circle" aria-hidden="true"></i> Creado por {{ $p->creado_por_nombre }} · @fecha($p->created_at)</div>
                        @endif
                    </div>
                    <div class="rutas-fila-acciones">
                        @if (in_array($p->id, $paraderosEditables, true))
                            <button type="button" class="btn-icono editar" title="Editar" aria-label="Editar paradero {{ $p->nombre }}"
                                    data-accion="editar-registro" data-dialogo="dialogoEditarParadero"
                                    data-url="{{ route('rutas.paraderos.update', $p->id) }}" data-id="{{ $p->id }}" data-valores="{{ json_encode(['nombre' => $p->nombre]) }}">
                                <i class="bi bi-pencil-square" aria-hidden="true"></i>
                            </button>
                        @endif
                        @if (in_array($p->id, $paraderosDesactivables, true))
                            <form action="{{ route('rutas.paraderos.estado', $p->id) }}" method="POST" class="m-0"
                                  data-confirmar="{{ $p->activo ? '¿Desactivar este paradero? Las rutas que ya lo tienen no cambian; solo deja de sugerirse al capturar.' : '¿Reactivar este paradero?' }}">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="activo" value="{{ $p->activo ? 0 : 1 }}">
                                @if ($p->activo)
                                    <button type="submit" class="btn-icono eliminar" title="Desactivar" aria-label="Desactivar paradero {{ $p->nombre }}"><i class="bi bi-slash-circle" aria-hidden="true"></i></button>
                                @else
                                    <button type="submit" class="btn-icono reactivar" title="Reactivar" aria-label="Reactivar paradero {{ $p->nombre }}"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></button>
                                @endif
                            </form>
                        @endif
                    </div>
                </article>
            @empty
                <div class="tarjeta estado-vacio rutas-vacio">
                    <div class="icono"><i class="bi bi-geo" aria-hidden="true"></i></div>
                    <p class="small m-0">Sin paraderos registrados en esta sede todavía.</p>
                    <p class="text-muted small m-0">También se crean solos al escribirlos en el horario de una ruta.</p>
                </div>
            @endforelse

            <div class="sin-resultados" data-sin-resultados="rutas-paraderos" hidden>
                <i class="bi bi-search d-block mb-2" aria-hidden="true"></i>
                <p class="fw-semibold m-0">No hay paraderos que coincidan con tu búsqueda.</p>
            </div>
        </div>
    @endif

    {{-- ===== Diálogo "Configurar Ruta y Horarios" (alta) y "Modificar Ruta y Horarios" (edición) ===== --}}
    @if ($puede['crear'] || $puede['editar'])
        <datalist id="paraderosSede">
            @foreach ($paraderos->where('activo', true) as $p)
                <option value="{{ $p->nombre }}"></option>
            @endforeach
        </datalist>

        <template data-plantilla-horario>
            @include('padrones.rutas._horario', ['h' => '__H__', 'numero' => '', 'prefijo' => '__F__', 'datos' => []])
        </template>
        <template data-plantilla-paradero>
            @include('padrones.rutas._paradero', ['h' => '__H__', 'p' => '__P__', 'prefijo' => '__F__'])
        </template>
    @endif

    @foreach (['nuevo' => $puede['crear'], 'editar' => $puede['editar']] as $modo => $permitido)
        @continue (! $permitido)
        @php
            $esNuevo = $modo === 'nuevo';
            $trasError = $esNuevo ? $dialogo === 'crear' : $editandoId !== null;
            $valor = fn (string $campo, string $porDefecto = '') => $trasError ? (string) old($campo, $porDefecto) : $porDefecto;
            $sentidoElegido = $valor('sentido', $sentidoTab ?? 'llegada');
            $horariosIniciales = $trasError && $horariosDeOld !== [] ? $horariosDeOld : [[]];
        @endphp
        <dialog id="{{ $esNuevo ? 'dialogoNuevaRuta' : 'dialogoEditarRuta' }}" class="dialogo ancho rutas-dialogo" aria-labelledby="titulo-ruta-{{ $modo }}"
                @if ($trasError) data-abrir-al-cargar @endif>
            <div class="dialogo-cabecera">
                <h2 id="titulo-ruta-{{ $modo }}"><i class="bi {{ $esNuevo ? 'bi-node-plus' : 'bi-pencil-square' }} me-2 rutas-icono-titulo" aria-hidden="true"></i>{{ $esNuevo ? 'Configurar Ruta y Horarios' : 'Modificar Ruta y Horarios' }}</h2>
                <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </div>
            <div class="dialogo-cuerpo">
                <form action="{{ $esNuevo ? route('rutas.store') : ($editandoId ? route('rutas.update', $editandoId) : '') }}" method="POST" autocomplete="off"
                      data-form-ruta data-prefijo="{{ $modo }}">
                    @csrf
                    @unless ($esNuevo) @method('PUT') @endunless
                    <input type="hidden" name="_dialogo" value="{{ $esNuevo ? 'crear' : ($editandoId ? 'editar-'.$editandoId : '') }}" data-campo-dialogo>
                    <input type="hidden" name="sede_id" value="{{ $sede->id }}">
                    <p class="linea-empresa mb-3"><i class="bi bi-building me-1" aria-hidden="true"></i>Sede: <strong>{{ $sede->nombre }}</strong> · {{ $empresaNombre }}</p>

                    <span class="campo-etiqueta">Sentido de la Ruta</span>
                    <div class="rutas-sentido-opciones" role="radiogroup" aria-label="Sentido de la ruta">
                        <label class="rutas-sentido-opcion">
                            <input type="radio" name="sentido" value="llegada" @checked($sentidoElegido === 'llegada') @if (($sentidoTab ?? 'llegada') === 'llegada') data-por-defecto @endif>
                            <i class="bi bi-sign-turn-right-fill d-block fs-5 mb-1" aria-hidden="true"></i>Llegada a la Sede
                        </label>
                        <label class="rutas-sentido-opcion">
                            <input type="radio" name="sentido" value="salida" @checked($sentidoElegido === 'salida') @if ($sentidoTab === 'salida') data-por-defecto @endif>
                            <i class="bi bi-sign-turn-left-fill d-block fs-5 mb-1" aria-hidden="true"></i>Salida de la Sede
                        </label>
                    </div>

                    <label class="campo-etiqueta" for="{{ $modo }}_ru_nombre">Nombre de la Ruta / Trayecto</label>
                    <input type="text" id="{{ $modo }}_ru_nombre" name="nombre" class="campo text-uppercase" maxlength="150" required
                           placeholder="Ej: RUTA 1 - CANCÚN CENTRO" value="{{ $valor('nombre') }}">

                    <div class="campo-grupo">
                        <div class="flex-fill">
                            <label class="campo-etiqueta" for="{{ $modo }}_ru_turno">Turno Operativo</label>
                            <select id="{{ $modo }}_ru_turno" name="turno_id" class="campo" required>
                                <option value="">-- Seleccionar Turno --</option>
                                @foreach ($turnos as $t)
                                    @continue ($esNuevo && ! $t->elegible)
                                    <option value="{{ $t->id }}" @selected($valor('turno_id') === (string) $t->id) @unless ($t->elegible) hidden @endunless>{{ $t->nombre }} ({{ $t->inicio() }} - {{ $t->fin() }}){{ $t->elegible ? '' : ' (ya no disponible)' }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="flex-fill">
                            <label class="campo-etiqueta" for="{{ $modo }}_ru_proveedor">Empresa Transportista</label>
                            <select id="{{ $modo }}_ru_proveedor" name="proveedor_id" class="campo rutas-transportista" required>
                                <option value="">-- Seleccionar Transportista --</option>
                                @foreach ($proveedores as $pr)
                                    @continue ($esNuevo && ! $pr->elegible)
                                    <option value="{{ $pr->id }}" @selected($valor('proveedor_id') === (string) $pr->id) @unless ($pr->elegible) hidden @endunless>{{ $pr->nombre }}{{ $pr->elegible ? '' : ' (ya no disponible)' }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    @if ($turnos->where('elegible', true)->isEmpty() || $proveedores->where('elegible', true)->isEmpty())
                        <p class="campo-ayuda rutas-ayuda"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i>
                            @if ($turnos->where('elegible', true)->isEmpty()) No hay turnos activos que se usen en esta sede (Estructura → Turnos). @endif
                            @if ($proveedores->where('elegible', true)->isEmpty()) No hay proveedores activos que operen en esta sede (Padrones → Proveedores). @endif
                        </p>
                    @endif

                    <label class="campo-etiqueta" for="{{ $modo }}_ru_costo">Costo Máximo por Taxi <span class="text-lowercase fw-normal">(opcional)</span></label>
                    <input type="number" id="{{ $modo }}_ru_costo" name="costo_maximo_taxi" class="campo" step="0.01" min="0" inputmode="decimal" placeholder="Ej: 250.00" value="{{ $valor('costo_maximo_taxi') }}">
                    <p class="campo-ayuda rutas-ayuda"><i class="bi bi-info-circle" aria-hidden="true"></i> Si un vale de taxi de esta ruta supera este monto, la caseta deberá justificarlo por escrito. Déjalo vacío si no quieres tope.</p>

                    <span class="campo-etiqueta rutas-separador">Horarios de esta Ruta</span>
                    <p class="campo-ayuda rutas-ayuda"><i class="bi bi-info-circle" aria-hidden="true"></i> Si la ruta pasa a horas distintas según el día (ej. lunes a viernes y fin de semana), agrega un horario por cada grupo de días. Al agregar uno se copian los paraderos del anterior: solo ajusta lo que cambie.</p>
                    <div data-horarios data-siguiente="{{ count($horariosIniciales) }}">
                        @foreach ($horariosIniciales as $i => $datosHorario)
                            @include('padrones.rutas._horario', ['h' => $i, 'numero' => $i + 1, 'prefijo' => $modo, 'datos' => $datosHorario])
                        @endforeach
                    </div>
                    <button type="button" class="btn-agregar-horario" data-agregar-horario><i class="bi bi-plus-circle me-1" aria-hidden="true"></i> Agregar otro Horario (ej. fin de semana distinto)</button>

                    <div class="dialogo-acciones">
                        <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                        <button type="submit" class="btn-rutas">{{ $esNuevo ? 'Guardar Ruta' : 'Guardar Cambios' }}</button>
                    </div>
                </form>
                @unless ($esNuevo)
                    @include('componentes.borrar', ['registro' => 'rutas', 'id' => $editandoId])
                @endunless
            </div>
        </dialog>
    @endforeach

    {{-- ===== Paraderos: nuevo y editar ===== --}}
    @if ($puede['crear'])
        <dialog id="dialogoNuevoParadero" class="dialogo" aria-labelledby="titulo-paradero-nuevo" @if ($dialogo === 'paradero-nuevo') data-abrir-al-cargar @endif>
            <div class="dialogo-cabecera">
                <h2 id="titulo-paradero-nuevo"><i class="bi bi-node-plus me-2 rutas-icono-titulo" aria-hidden="true"></i>Nuevo Paradero</h2>
                <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </div>
            <div class="dialogo-cuerpo">
                <form action="{{ route('rutas.paraderos.store', $sede->id) }}" method="POST" autocomplete="off">
                    @csrf
                    <input type="hidden" name="_dialogo" value="paradero-nuevo">
                    <p class="linea-empresa mb-3"><i class="bi bi-building me-1" aria-hidden="true"></i>Sede: <strong>{{ $sede->nombre }}</strong></p>
                    <label class="campo-etiqueta" for="nuevo_paradero_nombre">Nombre del Paradero</label>
                    <input type="text" id="nuevo_paradero_nombre" name="nombre" class="campo text-uppercase" maxlength="150" required placeholder="Ej: Terminal ADO Centro"
                           value="{{ $dialogo === 'paradero-nuevo' ? old('nombre') : '' }}">
                    <div class="dialogo-acciones">
                        <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                        <button type="submit" class="btn-rutas">Guardar</button>
                    </div>
                </form>
            </div>
        </dialog>
    @endif
    @if ($puede['editar'])
        <dialog id="dialogoEditarParadero" class="dialogo" aria-labelledby="titulo-paradero-editar" @if ($editandoParadero) data-abrir-al-cargar @endif>
            <div class="dialogo-cabecera">
                <h2 id="titulo-paradero-editar"><i class="bi bi-pencil-square me-2 rutas-icono-titulo" aria-hidden="true"></i>Editar Paradero</h2>
                <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </div>
            <div class="dialogo-cuerpo">
                <form action="{{ $editandoParadero ? route('rutas.paraderos.update', $editandoParadero) : '' }}" method="POST" autocomplete="off">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="_dialogo" value="{{ $editandoParadero ? 'paradero-editar-'.$editandoParadero : '' }}" data-campo-dialogo data-prefijo-dialogo="paradero-editar-">
                    <p class="campo-ayuda rutas-ayuda mt-0"><i class="bi bi-info-circle" aria-hidden="true"></i> Las rutas que usan este paradero mostrarán el nombre nuevo.</p>
                    <label class="campo-etiqueta" for="editar_paradero_nombre">Nombre</label>
                    <input type="text" id="editar_paradero_nombre" name="nombre" class="campo text-uppercase" maxlength="150" required
                           value="{{ $editandoParadero ? old('nombre') : '' }}">
                    <div class="dialogo-acciones">
                        <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                        <button type="submit" class="btn-rutas">Guardar</button>
                    </div>
                </form>
                @include('componentes.borrar', ['registro' => 'paraderos', 'id' => $editandoParadero])
            </div>
        </dialog>
    @endif
</div>
@endsection
