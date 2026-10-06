@extends('layouts.app')

@section('titulo', 'Control de Accesos')

@section('contenido')
<div class="pantalla-accesos">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    @if ($sinEmpresa)
        <div class="encabezado-pantalla mb-4">
            <div class="icono"><i class="bi bi-person-badge-fill text-danger" aria-hidden="true"></i></div>
            <div><h1>Control de Accesos</h1><p>Registro dinámico según el tipo de persona que ingresa.</p></div>
        </div>
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-buildings" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong> para ver su bitácora de accesos.</p>
        </div>
    @else
        @php
            $hoy = app(\App\Support\HoraLocal::class)->formatear(now(), 'Y-m-d');
            $variasSedes = $sedesFiltro->count() > 1;
            $esHistorial = $pestana === 'historial';
            $filtrosActivos = array_filter(\Illuminate\Support\Arr::only($filtros, ['q', 'tipo', 'sede', 'desde', 'hasta']));
            $base = array_filter(['q' => $filtros['q'] ?? null, 'tipo' => $filtros['tipo'] ?? null, 'sede' => $filtros['sede'] ?? null]);
        @endphp

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
            <div class="encabezado-pantalla m-0">
                <div class="icono"><i class="bi bi-person-badge-fill text-danger" aria-hidden="true"></i></div>
                <div>
                    <h1>Control de Accesos</h1>
                    <p>Registro dinámico según el tipo de persona que ingresa.</p>
                    <p class="texto-padron-de"><i class="bi bi-building-check" aria-hidden="true"></i> Bitácora de: <strong>{{ $empresaNombre }}</strong></p>
                </div>
            </div>
            @if ($puede['crear'])
                <button type="button" class="btn-nuevo-ingreso" data-abrir-dialogo="dialogoIngreso">
                    <i class="bi bi-person-plus-fill me-1" aria-hidden="true"></i>Nuevo Ingreso
                </button>
            @endif
        </div>

        {{-- Búsqueda y filtro por tipo: en Gente en Sitio y Pendientes filtran al escribir; en el historial, con Filtrar --}}
        <form method="GET" data-autoenviar action="{{ route('accesos.index') }}" class="filtros-accesos {{ $esHistorial ? 'con-fechas' : '' }}" role="search" data-filtros-accesos="{{ $esHistorial ? 'servidor' : 'vivo' }}">
            <input type="hidden" name="pestana" value="{{ $pestana }}">
            <div class="buscador filtro-texto">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="search" name="q" value="{{ $filtros['q'] ?? '' }}" maxlength="100" autocomplete="off"
                       placeholder="Buscar por nombre, gafete, empresa, placas o host..." aria-label="Buscar acceso" data-filtro-accesos-texto>
            </div>
            <select name="tipo" class="filtro-select" aria-label="Filtrar por tipo de persona" data-filtro-accesos-tipo>
                <option value="">Todos los tipos</option>
                @foreach (\App\Models\Acceso::TIPOS as $clave => $etiqueta)
                    <option value="{{ $clave }}" @selected(($filtros['tipo'] ?? '') === $clave)>{{ $etiqueta }}</option>
                @endforeach
            </select>
            @if ($variasSedes)
                <select name="sede" class="filtro-select" aria-label="Filtrar por sede" data-filtro-accesos-sede>
                    <option value="">Todas las sedes</option>
                    @foreach ($sedesFiltro as $s)
                        <option value="{{ $s->id }}" @selected((int) ($filtros['sede'] ?? 0) === $s->id)>{{ $s->nombre }}</option>
                    @endforeach
                </select>
            @endif
            @if ($esHistorial)
                <label class="filtro-fecha"><span>Desde</span><input type="date" name="desde" class="filtro-select" value="{{ $filtros['desde'] ?? '' }}"></label>
                <label class="filtro-fecha"><span>Hasta</span><input type="date" name="hasta" class="filtro-select" value="{{ $filtros['hasta'] ?? '' }}"></label>
                <button type="submit" class="btn-filtrar-accesos"><i class="bi bi-funnel me-1" aria-hidden="true"></i>Filtrar</button>
                @if ($filtrosActivos)
                    <a href="{{ route('accesos.index', ['pestana' => 'historial']) }}" class="btn-limpiar-accesos">Limpiar</a>
                @endif
            @endif
        </form>

        <nav class="accesos-pestanas" aria-label="Secciones de la bitácora">
            @if ($conteos['pendientes'] > 0 || $pestana === 'pendientes')
                <a href="{{ route('accesos.index', ['pestana' => 'pendientes'] + $base) }}" class="accesos-pestana pendientes {{ $pestana === 'pendientes' ? 'activa' : '' }}" @if ($pestana === 'pendientes') aria-current="page" @endif>
                    Pendientes de Autorización ({{ $conteos['pendientes'] }})
                </a>
            @endif
            <a href="{{ route('accesos.index', $base) }}" class="accesos-pestana {{ $pestana === 'en_sitio' ? 'activa' : '' }}" @if ($pestana === 'en_sitio') aria-current="page" @endif>
                Gente en Sitio ({{ $conteos['en_sitio'] }})
            </a>
            <a href="{{ route('accesos.index', ['pestana' => 'historial'] + $base) }}" class="accesos-pestana {{ $esHistorial ? 'activa' : '' }}" @if ($esHistorial) aria-current="page" @endif>
                Historial Finalizados
            </a>
            @if ($esHistorial && $puede['exportar'] && $lista->total() > 0)
                <a href="{{ route('accesos.exportar', request()->except(['pestana', 'page'])) }}" class="btn-exportar-accesos"><i class="bi bi-file-earmark-spreadsheet me-1" aria-hidden="true"></i>Exportar a Excel</a>
            @endif
        </nav>

        @if ($esHistorial && $lista->total() > 0)
            <p class="small text-muted mb-2">{{ number_format($lista->total()) }} registro(s) finalizado(s){{ $filtrosActivos ? ' con esos filtros' : '' }}.</p>
        @endif

        <div class="fichas-accesos" data-accesos>
            @forelse ($lista as $a)
                @include('seguridad.accesos._ficha', ['a' => $a, 'modo' => $pestana])
            @empty
                <div class="tarjeta estado-vacio estado-vacio-accesos">
                    <div class="icono"><i class="bi {{ $pestana === 'pendientes' ? 'bi-hourglass' : ($esHistorial ? 'bi-clock-history' : 'bi-door-open') }}" aria-hidden="true"></i></div>
                    <p class="text-muted small m-0">
                        @if ($filtrosActivos)
                            Sin resultados para esa búsqueda.
                        @elseif ($pestana === 'pendientes')
                            No hay accesos pendientes de autorización.
                        @elseif ($esHistorial)
                            Todavía no hay visitas finalizadas.
                        @else
                            No hay personal en sitio.@if ($puede['crear']) Toca <strong>Nuevo Ingreso</strong> para registrar a alguien.@endif
                        @endif
                    </p>
                </div>
            @endforelse
            <div class="sin-resultados" data-sin-resultados-accesos hidden>
                <i class="bi bi-search d-block mb-2" aria-hidden="true"></i>
                <p class="fw-semibold m-0">Sin resultados para esa búsqueda.</p>
            </div>
        </div>

        @if ($esHistorial)
            <div class="mt-4">{{ $lista->onEachSide(1)->links('pagination::bootstrap-5') }}</div>
        @endif

        @if ($puede['crear'])
            @include('seguridad.accesos._ingreso')
            @if ($puede['provisional'])
                @include('organizacion.colaboradores._registro-rapido', ['sedeSugerida' => $sedesAlta->count() === 1 ? $sedesAlta->first()->id : null])
            @endif
            @if ($puede['persona'])
                @include('seguridad.personas._registro-rapido')
            @endif
        @endif

        @if ($puede['editar'])
            @include('seguridad.accesos._dialogos')
        @endif
    @endif
</div>
@endsection
