@extends('layouts.app')

@section('titulo', ($forzado ?? false) ? 'Mis procedimientos por leer' : 'Procedimientos')

@section('contenido')
<div class="tema-azul pantalla-pases pantalla-procedimientos">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    @if ($sinEmpresa)
        <div class="encabezado-pantalla mb-4">
            <div class="icono"><i class="bi bi-book text-primary" aria-hidden="true"></i></div>
            <div><h1>Procedimientos</h1><p>Manual de procedimientos operativos: qué hacer en cada situación.</p></div>
        </div>
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-buildings" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong> para ver sus procedimientos.</p>
        </div>
    @else
        @php
            $consulta = fn (array $extra) => array_filter(array_merge(['filtro' => $filtro, 'q' => $texto, 'categoria' => $categoriaFiltro], $extra),
                fn ($v) => $v !== null && $v !== '' && $v !== 'todos');
            $iconos = ['todos' => 'bi-grid', 'por_leer' => 'bi-book-half', 'por_aprobar' => 'bi-patch-check', 'publicados' => 'bi-check2-circle',
                'revision' => 'bi-hourglass-split', 'borradores' => 'bi-pencil', 'retirados' => 'bi-slash-circle'];
            $porLeerTotal = count($porLeer);
        @endphp

        <div class="encabezado-pases">
            <div class="encabezado-pantalla m-0">
                <div class="icono"><i class="bi {{ $forzado ? 'bi-book-half' : 'bi-book' }} text-primary" aria-hidden="true"></i></div>
                <div>
                    @if ($forzado)
                        <h1>Mis procedimientos por leer</h1>
                        <p>Léelos con calma y al final firma <strong>«Leí y entendí»</strong>.</p>
                    @else
                        <h1>Procedimientos</h1>
                        <p>Manual de procedimientos operativos: qué hacer en cada situación.</p>
                    @endif
                    <p class="texto-padron-de"><i class="bi bi-building-check" aria-hidden="true"></i> Manual de: <strong>{{ $empresaNombre }}</strong></p>
                </div>
            </div>
            <div class="acciones-encabezado-pases">
                @if ($forzado)
                    <a href="{{ route('procedimientos.index') }}" class="btn-secundario-pase"><i class="bi bi-grid me-1" aria-hidden="true"></i>Todos</a>
                @else
                    <a href="{{ route('procedimientos.por-leer') }}" class="btn-secundario-pase {{ $porLeerTotal > 0 ? 'con-pendientes' : '' }}">
                        <i class="bi bi-book-half me-1" aria-hidden="true"></i>Por leer <span class="conteo-pill">{{ $porLeerTotal }}</span>
                    </a>
                @endif
                @if ($puede['categorias'])
                    <button type="button" class="btn-secundario-pase" data-abrir-dialogo="dialogoCategoriasProcedimientos"><i class="bi bi-tags me-1" aria-hidden="true"></i>Categorías</button>
                @endif
                @if ($puede['crear'])
                    <button type="button" class="btn-nuevo-pase" data-abrir-dialogo="dialogoNuevoProcedimiento">
                        <i class="bi bi-plus-circle me-2" aria-hidden="true"></i>Nuevo Procedimiento
                    </button>
                @endif
            </div>
        </div>

        @if ($filtros->count() > 1)
            <nav class="pildoras-pases" aria-label="Filtrar procedimientos">
                @foreach ($filtros as $clave => $nombre)
                    @php $activo = $filtro === $clave; @endphp
                    <a href="{{ $clave === 'por_leer' ? route('procedimientos.por-leer', array_filter(['q' => $texto, 'categoria' => $categoriaFiltro])) : route('procedimientos.index', $consulta(['filtro' => $clave])) }}"
                       class="pildora-pase pildora-proc-{{ $clave }} {{ $activo ? 'activa' : '' }} {{ $conteos[$clave] === 0 && ! $activo ? 'vacia' : '' }}" @if ($activo) aria-current="page" @endif>
                        <i class="bi {{ $iconos[$clave] }}" aria-hidden="true"></i>{{ $nombre }}
                        <span class="conteo-pill">{{ $conteos[$clave] }}</span>
                    </a>
                @endforeach
            </nav>
        @endif

        <nav class="categorias-procedimientos" aria-label="Filtrar por categoría">
            <a href="{{ $forzado ? route('procedimientos.por-leer', array_filter(['q' => $texto])) : route('procedimientos.index', $consulta(['categoria' => null])) }}"
               class="chip-categoria {{ $categoriaFiltro === null ? 'activa' : '' }}" @if ($categoriaFiltro === null) aria-current="page" @endif>Todas</a>
            @foreach ($categorias->where('activo', true) as $c)
                <a href="{{ $forzado ? route('procedimientos.por-leer', array_filter(['q' => $texto, 'categoria' => $c->id])) : route('procedimientos.index', $consulta(['categoria' => $c->id])) }}"
                   class="chip-categoria cat-{{ $c->color }} {{ $categoriaFiltro === $c->id ? 'activa' : '' }}" @if ($categoriaFiltro === $c->id) aria-current="page" @endif>
                    <span class="punto-categoria" aria-hidden="true"></span>{{ $c->nombre }}
                </a>
            @endforeach
        </nav>

        <form method="GET" action="{{ $forzado ? route('procedimientos.por-leer') : route('procedimientos.index') }}" class="busqueda-pases" role="search">
            @if (! $forzado && $filtro !== 'todos')<input type="hidden" name="filtro" value="{{ $filtro }}">@endif
            @if ($categoriaFiltro !== null)<input type="hidden" name="categoria" value="{{ $categoriaFiltro }}">@endif
            <div class="buscador">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="search" name="q" value="{{ $texto }}" maxlength="200" placeholder="Buscar: incendio, robo, llave… o escanea el QR de la hoja" aria-label="Buscar procedimiento">
            </div>
            <button type="submit" class="btn-buscar-pases" aria-label="Buscar"><i class="bi bi-search" aria-hidden="true"></i><span class="d-none d-sm-inline ms-1">Buscar</span></button>
            @if ($texto !== '')
                <a href="{{ $forzado ? route('procedimientos.por-leer') : route('procedimientos.index', array_filter(['filtro' => $filtro !== 'todos' ? $filtro : null, 'categoria' => $categoriaFiltro])) }}" class="btn-limpiar-pases">Limpiar</a>
            @endif
        </form>

        <details class="escanear-procedimiento" data-lector-procedimiento>
            <summary><i class="bi bi-qr-code-scan me-1" aria-hidden="true"></i>Escanear el QR de una hoja impresa</summary>
            @include('componentes.lector', ['id' => 'lector_procedimiento', 'tipos' => 'procedimiento', 'nombre' => '',
                'ayuda' => 'Con la cámara, un lector de QR o el NFC: se abre el procedimiento en modo lectura.'])
        </details>

        <div class="fichas-pases fichas-procedimientos">
            @forelse ($lista as $p)
                @include('seguridad.procedimientos._ficha', ['p' => $p])
            @empty
                <div class="tarjeta estado-vacio fichas-pases-vacio">
                    <div class="icono"><i class="bi {{ $filtro === 'por_leer' ? 'bi-check2-all' : 'bi-book' }}" aria-hidden="true"></i></div>
                    @if ($filtro === 'por_leer' && $texto === '')
                        <p class="fw-semibold mb-1">¡Al día! No tienes procedimientos por leer.</p>
                        <p class="text-muted small m-0">Cuando se publique uno que te aplique, aparecerá aquí y en Inicio.</p>
                    @elseif ($filtro === 'por_aprobar' && $texto === '')
                        <p class="fw-semibold mb-1">No hay procedimientos esperando tu aprobación.</p>
                    @else
                        <p class="fw-semibold mb-1">No se encontraron procedimientos{{ $texto !== '' ? ' para «'.$texto.'»' : '' }}.</p>
                        @if ($filtro === 'todos' && $texto === '' && $puede['crear'])
                            <p class="text-muted small m-0">Escribe el primero con <strong>Nuevo Procedimiento</strong>.</p>
                        @elseif ($lector && $texto === '')
                            <p class="text-muted small m-0">Aquí aparecerán los procedimientos publicados que aplican a tu sede.</p>
                        @endif
                    @endif
                </div>
            @endforelse
        </div>

        @if ($lista->hasPages())
            <div class="mt-3">{{ $lista->onEachSide(1)->links('pagination::bootstrap-5') }}</div>
        @endif

        @if ($puede['crear'])
            @include('seguridad.procedimientos._formulario', ['trabajo' => null])
        @endif
        @if ($puede['categorias'])
            @include('seguridad.procedimientos._categorias')
        @endif
    @endif
</div>
@endsection
