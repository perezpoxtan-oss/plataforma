@extends('layouts.app')

@section('titulo', $bandeja ? 'Bandeja de firmas — Pases de Salida' : 'Pases de Salida')

@section('contenido')
<div class="tema-azul pantalla-pases">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    @if ($sinEmpresa)
        <div class="encabezado-pantalla mb-4">
            <div class="icono"><i class="bi bi-box-arrow-up-right text-primary" aria-hidden="true"></i></div>
            <div><h1>Pases de Salida</h1><p>Control de equipo que sale de la propiedad — préstamo, venta, reparación y más.</p></div>
        </div>
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-buildings" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong> para ver sus pases de salida.</p>
        </div>
    @else
        @php
            $consulta = fn (array $extra) => array_filter(array_merge(['filtro' => $filtro, 'q' => $texto, 'sede' => $sedeFiltro], $extra), fn ($v) => $v !== null && $v !== '' && $v !== 'todos');
            $iconosFiltro = ['todos' => 'bi-grid', 'mi_firma' => 'bi-pen', 'pendientes' => 'bi-hourglass-split', 'aprobados' => 'bi-check2-circle',
                'fuera' => 'bi-box-arrow-right', 'espera_regreso' => 'bi-arrow-return-left', 'vencidos' => 'bi-exclamation-triangle-fill', 'cerrados' => 'bi-archive', 'rechazados' => 'bi-x-octagon'];
            $misFirmas = $conteos['mi_firma'];
        @endphp

        <div class="encabezado-pases">
            <div class="encabezado-pantalla m-0">
                <div class="icono"><i class="bi {{ $bandeja ? 'bi-pen' : 'bi-box-arrow-up-right' }} text-primary" aria-hidden="true"></i></div>
                <div>
                    @if ($bandeja)
                        <h1>Bandeja de firmas</h1>
                        <p>Los pases de salida que esperan <strong>tu</strong> firma: aprobaciones y pasos de caseta.</p>
                    @else
                        <h1>Pases de Salida</h1>
                        <p>Control de equipo que sale de la propiedad — préstamo, venta, reparación y más.</p>
                    @endif
                </div>
            </div>
            <div class="acciones-encabezado-pases">
                @if ($bandeja)
                    <a href="{{ route('pases-salida.index') }}" class="btn-secundario-pase"><i class="bi bi-grid me-1" aria-hidden="true"></i>Todos los pases</a>
                @else
                    <a href="{{ route('pases-salida.pendientes') }}" class="btn-secundario-pase {{ $misFirmas > 0 ? 'con-pendientes' : '' }}">
                        <i class="bi bi-pen me-1" aria-hidden="true"></i>Mi bandeja <span class="conteo-pill">{{ $misFirmas }}</span>
                    </a>
                @endif
                @if ($puede['configurar'])
                    <a href="{{ route('pases-salida.circuito') }}" class="btn-secundario-pase" title="Circuito de aprobación"><i class="bi bi-diagram-3 me-1" aria-hidden="true"></i><span>Circuito</span></a>
                @endif
                @if ($puede['crear'])
                    <button type="button" class="btn-nuevo-pase" data-abrir-dialogo="dialogoNuevoPase">
                        <i class="bi bi-plus-circle me-2" aria-hidden="true"></i>Nuevo Pase
                    </button>
                @endif
            </div>
        </div>

        <nav class="pildoras-pases" aria-label="Filtrar pases">
            @foreach (\App\Services\PasesSalida\AdministradorPasesSalida::FILTROS as $clave => $nombre)
                @php $activo = $filtro === $clave; @endphp
                <a href="{{ $clave === 'mi_firma' ? route('pases-salida.pendientes', array_filter(['q' => $texto, 'sede' => $sedeFiltro])) : route('pases-salida.index', $consulta(['filtro' => $clave])) }}"
                   class="pildora-pase pildora-{{ $clave }} {{ $activo ? 'activa' : '' }} {{ $conteos[$clave] === 0 && ! $activo ? 'vacia' : '' }}" @if ($activo) aria-current="page" @endif>
                    <i class="bi {{ $iconosFiltro[$clave] }}" aria-hidden="true"></i>{{ $nombre }}
                    <span class="conteo-pill">{{ $conteos[$clave] }}</span>
                </a>
            @endforeach
        </nav>

        <form method="GET" data-autoenviar action="{{ $bandeja ? route('pases-salida.pendientes') : route('pases-salida.index') }}" class="busqueda-pases" role="search">
            @if (! $bandeja && $filtro !== 'todos')<input type="hidden" name="filtro" value="{{ $filtro }}">@endif
            @if ($variasSedes)
                <select name="sede" class="filtro-select" aria-label="Filtrar por sede" data-enviar-al-cambiar>
                    <option value="">Todas las sedes</option>
                    @foreach ($sedesVisibles as $s)
                        <option value="{{ $s->id }}" @selected($sedeFiltro === $s->id)>{{ $s->nombre }}</option>
                    @endforeach
                </select>
            @endif
            <div class="buscador">
                <i class="bi bi-upc-scan" aria-hidden="true"></i>
                <input type="search" name="q" value="{{ $texto }}" maxlength="200" placeholder="Folio, solicitante, artículo o escanea el QR de la hoja..." aria-label="Buscar pase">
            </div>
            <button type="submit" class="btn-buscar-pases" aria-label="Buscar"><i class="bi bi-search" aria-hidden="true"></i><span class="d-none d-sm-inline ms-1">Buscar</span></button>
            @if ($texto !== '' || $sedeFiltro !== null)
                <a href="{{ $bandeja ? route('pases-salida.pendientes') : route('pases-salida.index', $filtro !== 'todos' ? ['filtro' => $filtro] : []) }}" class="btn-limpiar-pases">Limpiar</a>
            @endif
        </form>

        <div class="fichas-pases" data-lista-pases>
            @forelse ($pases as $p)
                @include('seguridad.pases-salida._ficha', ['p' => $p, 'meToca' => in_array($p->id, $porFirmar, true)])
            @empty
                <div class="tarjeta estado-vacio fichas-pases-vacio">
                    <div class="icono"><i class="bi {{ $filtro === 'mi_firma' ? 'bi-check2-all' : 'bi-box-arrow-up-right' }}" aria-hidden="true"></i></div>
                    @if ($filtro === 'mi_firma' && $texto === '')
                        <p class="fw-semibold mb-1">Nada pendiente: no hay pases que esperen tu firma.</p>
                        <p class="text-muted small m-0">Aquí aparecen los pases que debes aprobar y, en caseta, los que debes dejar salir o recibir.</p>
                    @else
                        <p class="fw-semibold mb-1">No se encontraron pases{{ $texto !== '' ? ' para «'.$texto.'»' : '' }}.</p>
                        @if ($filtro === 'todos' && $texto === '' && $puede['crear'])
                            <p class="text-muted small m-0">Registra el primero con <strong>Nuevo Pase</strong>.</p>
                        @endif
                    @endif
                </div>
            @endforelse
        </div>

        @if ($pases->hasPages())
            <div class="mt-3">{{ $pases->onEachSide(1)->links('pagination::bootstrap-5') }}</div>
        @endif

        @if ($puede['crear'])
            @include('seguridad.pases-salida._formulario', ['modoFormulario' => 'crear'])
            @if ($puede['colaborador'])
                @include('organizacion.colaboradores._registro-rapido')
            @endif
            @if ($puede['proveedor'])
                @include('padrones.proveedores._alta-rapida')
            @endif
        @endif
    @endif
</div>
@endsection
