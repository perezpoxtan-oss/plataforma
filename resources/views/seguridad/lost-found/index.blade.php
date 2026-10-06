@extends('layouts.app')
@use('App\Models\LostFoundArticulo')

@section('titulo', 'Lost & Found')

@section('contenido')
<div class="tema-ambar pantalla-lost-found">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    @if ($sinEmpresa)
        <div class="encabezado-pantalla mb-4">
            <div class="icono"><i class="bi bi-bag-fill text-warning" aria-hidden="true"></i></div>
            <div><h1>Lost &amp; Found</h1><p>Todos los artículos, con su estado y ubicación — acciones directo desde aquí.</p></div>
        </div>
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-buildings" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong> para ver su archivo de Lost &amp; Found.</p>
        </div>
    @else
        @php
            $filtro = $filtros['filtro'];
            $texto = trim((string) ($filtros['q'] ?? ''));
            $sedeFiltro = isset($filtros['sede']) ? (int) $filtros['sede'] : null;
            $tipoFiltro = $filtros['tipo'] ?? null;
            $consulta = fn (array $extra) => array_filter(array_merge(['filtro' => $filtro, 'q' => $texto, 'sede' => $sedeFiltro, 'tipo' => $tipoFiltro], $extra), fn ($v) => $v !== null && $v !== '' && $v !== 'todos');
            $pildoras = [
                'todos' => ['Todos', 'dark', null],
                'resguardo' => ['En resguardo', 'warning', 'bi-box-seam'],
                'urgentes' => ['Solo urgentes / vencidos', 'danger', 'bi-exclamation-triangle-fill'],
                'devueltos' => ['Devueltos', 'success', 'bi-person-check'],
                'otros' => ['Donados / destruidos / beneficencia', 'secondary', 'bi-gift'],
            ];
            $meses = [1 => 'Enero', 2 => 'Febrero', 3 => 'Marzo', 4 => 'Abril', 5 => 'Mayo', 6 => 'Junio', 7 => 'Julio', 8 => 'Agosto', 9 => 'Septiembre', 10 => 'Octubre', 11 => 'Noviembre', 12 => 'Diciembre'];
            $grupos = $porMes ?? collect(['urgentes' => $articulos]);
            $hayFiltros = $texto !== '' || $sedeFiltro !== null || $tipoFiltro !== null;
        @endphp

        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-3">
            <div class="encabezado-pantalla m-0">
                <div class="icono"><i class="bi bi-bag-fill text-warning" aria-hidden="true"></i></div>
                <div>
                    <h1>Lost &amp; Found</h1>
                    <p>Todos los artículos, con su estado y ubicación — acciones directo desde aquí.</p>
                    <p class="texto-padron-de"><i class="bi bi-building-check" aria-hidden="true"></i> Archivo de: <strong>{{ $empresaNombre }}</strong></p>
                </div>
            </div>
            <div class="acciones-novedades">
                @if ($puede['tickets'])
                    <a href="{{ route('lost_found.index') }}" class="btn-accion-novedades"><i class="bi bi-arrow-left" aria-hidden="true"></i> Novedades</a>
                @endif
                @if ($puede['imprimir'])
                    <a href="{{ route('lost_found.auditoria', array_filter(['sede' => $sedeFiltro])) }}" target="_blank" rel="noopener" class="btn-accion-novedades oscuro"><i class="bi bi-clipboard-check-fill" aria-hidden="true"></i> Auditoría</a>
                @endif
            </div>
        </div>
        @if ($puede['configurar'])
            <p class="small text-muted mb-3 lf-enlace-config"><i class="bi bi-sliders me-1" aria-hidden="true"></i>Días de resguardo: <a href="{{ route('configuracion.index') }}#lost-found">Configurar en Estructura → Configuración</a>.</p>
        @endif

        {{-- Escanear la etiqueta de la bolsa (QR, NFC o el folio tecleado) y abrir su ficha --}}
        <div class="tarjeta escaner-lf">
            @include('componentes.lector', ['id' => 'lf_escanear', 'etiqueta' => 'Escanear etiqueta de la bolsa', 'tipos' => 'lost_found', 'nombre' => '',
                'ayuda' => 'Escanea el QR de la etiqueta o escribe el folio (LF-000123): se abre la ficha del artículo.'])
        </div>

        <nav class="filtros-lf" aria-label="Filtrar artículos">
            @foreach ($pildoras as $clave => [$nombre, $color, $icono])
                @php $activo = $filtro === $clave; @endphp
                <a href="{{ route('lost_found.archivo', $consulta(['filtro' => $clave])) }}"
                   class="btn btn-sm fw-bold {{ $activo ? 'btn-'.$color : 'btn-outline-'.$color }}" @if ($activo) aria-current="page" @endif>
                    @if ($icono)<i class="bi {{ $icono }} me-1" aria-hidden="true"></i>@endif{{ $nombre }}
                    <span class="conteo-filtro">{{ $conteos[$clave] }}</span>
                </a>
            @endforeach
        </nav>

        <form method="GET" action="{{ route('lost_found.archivo') }}" class="busqueda-lf" role="search">
            @if ($filtro !== 'todos')<input type="hidden" name="filtro" value="{{ $filtro }}">@endif
            <div class="buscador">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="search" name="q" value="{{ $texto }}" maxlength="100" placeholder="Buscar por folio, área/habitación u objeto..." aria-label="Buscar artículo">
            </div>
            @if ($variasSedes)
                <select name="sede" class="filtro-select" aria-label="Filtrar por sede" data-enviar-al-cambiar>
                    <option value="">Todas las sedes</option>
                    @foreach ($sedesFiltro as $s)
                        <option value="{{ $s->id }}" @selected($sedeFiltro === $s->id)>{{ $s->nombre }}</option>
                    @endforeach
                </select>
            @endif
            <select name="tipo" class="filtro-select" aria-label="Filtrar por tipo de valor" data-enviar-al-cambiar>
                <option value="">Todos los tipos de valor</option>
                @foreach (LostFoundArticulo::TIPOS_VALOR as $clave => $nombre)
                    <option value="{{ $clave }}" @selected($tipoFiltro === $clave)>{{ $nombre }} ({{ $umbrales[$clave] }} días)</option>
                @endforeach
            </select>
            <button type="submit" class="btn-buscar-pases" aria-label="Buscar"><i class="bi bi-search" aria-hidden="true"></i><span class="d-none d-sm-inline ms-1">Buscar</span></button>
            @if ($hayFiltros)
                <a href="{{ route('lost_found.archivo', $filtro !== 'todos' ? ['filtro' => $filtro] : []) }}" class="btn-limpiar-pases">Limpiar</a>
            @endif
        </form>

        <p class="leyenda-semaforo-lf">
            <span><span class="semaforo-punto verde" aria-hidden="true"></span>En tiempo</span>
            <span><span class="semaforo-punto amarillo" aria-hidden="true"></span>Por vencer (70 % de sus días)</span>
            <span><span class="semaforo-punto rojo" aria-hidden="true"></span>Vencido: decidir entrega, donación o destrucción</span>
        </p>

        {{-- Tickets de Lost & Found que todavía no tienen ningún artículo --}}
        @if ($sinArticulos->isNotEmpty())
            <section class="mb-3" aria-labelledby="titulo-sin-articulos">
                <h2 class="subtitulo-lf" id="titulo-sin-articulos"><i class="bi bi-exclamation-triangle-fill text-warning me-1" aria-hidden="true"></i>Sin artículos capturados aún ({{ $sinArticulos->count() }})</h2>
                @foreach ($sinArticulos as $h)
                    <div class="fila-articulo pendiente">
                        <div class="fila-articulo-datos">
                            <strong>{{ $h->folio() }}</strong> — {{ $h->ubicacion }}
                            <div class="small text-muted mt-1">
                                @fecha($h->created_at, 'd/m/Y') · Reportó: {{ $h->reportado_por }}@if ($variasSedes) · {{ $h->sede?->nombre }}@endif
                            </div>
                            <div class="small text-muted mt-1">Este ticket ya existe en Bitácora pero todavía no tiene ningún objeto capturado aquí.</div>
                        </div>
                        <div class="fila-articulo-acciones">
                            <a href="{{ route('novedades.index', ['abrir' => $h->id]) }}" class="btn-lf completar"><i class="bi bi-pencil-fill me-1" aria-hidden="true"></i>Completar</a>
                        </div>
                    </div>
                @endforeach
            </section>
        @endif

        @if ($articulos->isEmpty())
            <div class="tarjeta estado-vacio">
                <div class="icono"><i class="bi bi-bag" aria-hidden="true"></i></div>
                <p class="text-muted small m-0">
                    @if ($texto !== '')
                        No se encontraron artículos para «{{ $texto }}».
                    @elseif ($filtro === 'urgentes')
                        No hay artículos urgentes ni vencidos: todo lo que está en resguardo va en tiempo.
                    @elseif ($filtro !== 'todos' || $hayFiltros)
                        No hay artículos con este filtro.
                    @else
                        Todavía no hay artículos en Lost &amp; Found. Se registran desde un ticket de <strong>Lost &amp; Found</strong> en la Bitácora de Novedades.
                    @endif
                </p>
            </div>
        @endif

        @foreach ($grupos as $claveMes => $lista)
            @if ($porMes !== null)
                @php [$anio, $mes] = explode('-', $claveMes); @endphp
                <div class="mes-header-lf">
                    <span><i class="bi bi-calendar3 me-2" aria-hidden="true"></i>{{ $meses[(int) $mes] ?? $mes }} {{ $anio }}</span>
                    <span class="badge bg-light text-dark">{{ $lista->count() }} artículo(s)</span>
                </div>
            @endif
            @foreach ($lista as $a)
                @php
                    $semaforo = $a->semaforo($umbrales);
                    $vinculo = $a->reportesVinculados->first();
                    $cerrable = $a->enResguardo() && in_array($a->id, $cerrables, true);
                @endphp
                <article class="fila-articulo {{ $semaforo['clase'] ?? 'cerrado' }}" id="articulo-{{ $a->id }}">
                    <div class="fila-articulo-datos">
                        <div class="titulo-articulo-lf">
                            @if ($semaforo)<span class="semaforo-punto {{ $semaforo['clase'] }}" title="{{ $semaforo['texto'] }}" aria-label="{{ $semaforo['texto'] }}"></span>@endif
                            <a href="{{ route('lost_found.articulos.show', $a->id) }}" class="folio-lf">{{ $a->folio }}</a> — {{ $a->objeto }}
                            <span class="badge-tipo-lf">{{ $a->etiquetaTipo() }}</span>
                        </div>
                        <div class="small text-muted mt-1">
                            @fecha($a->created_at, 'd/m/Y')
                            @if ($semaforo) · <strong class="dias-lf {{ $semaforo['clase'] }}">{{ $semaforo['dias'] }} día(s) en resguardo</strong> de {{ $umbrales[$a->tipo_valor] ?? 90 }}@endif
                            @if (trim($a->marca.' '.$a->color) !== '') · {{ trim($a->marca.' '.$a->color) }}@endif
                            @if ($a->areaEspecifica) · {{ $a->areaEspecifica->nombre }}@endif
                            @if ($a->lugar_detalle) · {{ $a->lugar_detalle }}@endif
                            @if ($a->ubicacion_bodega) · <strong class="text-body">Bodega: {{ $a->ubicacion_bodega }}</strong>@endif
                            @if ($variasSedes) · {{ $a->sede?->nombre }}@endif
                        </div>
                        @if ($vinculo)
                            <div class="small text-success fw-bold mt-1"><i class="bi bi-link-45deg" aria-hidden="true"></i> Reporte de pérdida {{ $vinculo->folio }}@if ($vinculo->nombre_huesped) — {{ $vinculo->nombre_huesped }}@endif</div>
                        @endif
                        @if ($a->entrega)
                            <div class="small mt-1"><i class="bi bi-check2-circle text-success" aria-hidden="true"></i> {{ $a->entrega->etiquetaCierre() }}@if ($a->entrega->nombre_recibe) a <strong>{{ $a->entrega->nombre_recibe }}</strong>@endif · @fecha($a->cerrado_en ?? $a->entrega->created_at)</div>
                        @endif
                        <div class="texto-traza"><i class="bi bi-plus-circle" aria-hidden="true"></i> Registró {{ $a->creador?->name ?? '—' }} · Ticket {{ $a->novedad?->folio() }}@if ($a->cerrador) · <i class="bi bi-person-check" aria-hidden="true"></i> Cerró {{ $a->cerrador->name }}@endif</div>
                    </div>
                    <div class="fila-articulo-acciones">
                        <span class="estatus-lf {{ $a->enResguardo() ? 'resguardo' : 'cerrado' }}">{{ $a->etiquetaEstatus() }}</span>
                        <a href="{{ route('lost_found.articulos.show', $a->id) }}" class="btn-icono" title="Ver ficha" aria-label="Ver ficha de {{ $a->folio }}"><i class="bi bi-eye" aria-hidden="true"></i></a>
                        @if (in_array($a->id, $imprimibles, true))
                            <a href="{{ route('lost_found.articulos.etiqueta', $a->id) }}" target="_blank" rel="noopener" class="btn-icono" title="Imprimir etiqueta" aria-label="Imprimir etiqueta de {{ $a->folio }}"><i class="bi bi-tag-fill" aria-hidden="true"></i></a>
                        @endif
                        @if ($cerrable)
                            <button type="button" class="btn-lf cerrar" data-accion="lf-cerrar"
                                    data-url="{{ route('lost_found.articulos.cerrar', $a->id) }}" data-id="{{ $a->id }}" data-folio="{{ $a->folio }}" data-objeto="{{ $a->objeto }}"
                                    data-detalle="{{ trim($a->marca.' '.$a->color) !== '' ? ' — '.trim($a->marca.' '.$a->color) : '' }}"
                                    data-bodega="{{ $a->ubicacion_bodega ? ' · Bodega: '.$a->ubicacion_bodega : '' }}"
                                    data-recibe="{{ $vinculo?->nombre_huesped }}" data-correo="{{ $vinculo?->correo }}"
                                    data-vinculo="{{ $vinculo ? 'Reporte de pérdida '.$vinculo->folio.($vinculo->nombre_huesped ? ' de '.$vinculo->nombre_huesped : '').'. Revisa que sea la misma persona antes de entregar.' : '' }}">
                                <i class="bi bi-box-arrow-right me-1" aria-hidden="true"></i>Cerrar / Entregar
                            </button>
                        @endif
                    </div>
                </article>
            @endforeach
        @endforeach

        @if ($paginador && $paginador->hasPages())
            <div class="mt-4">{{ $paginador->onEachSide(1)->links('pagination::bootstrap-5') }}</div>
        @endif
        @if ($filtro === 'urgentes' && $articulos->count() >= 500)
            <p class="campo-ayuda mt-3"><i class="bi bi-info-circle" aria-hidden="true"></i> Se muestran los 500 más antiguos. Usa la búsqueda o el filtro de sede para acotar.</p>
        @endif

        @if ($puede['firmar'])
            @include('seguridad.lost-found._cerrar', ['articulo' => $cierre, 'volver' => 'archivo', 'abrir' => $cierre !== null, 'puedePersona' => $puede['persona']])
            @if ($puede['persona'])
                @include('seguridad.personas._registro-rapido')
            @endif
        @endif
    @endif
</div>
@endsection
