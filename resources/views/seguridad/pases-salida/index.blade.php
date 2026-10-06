@extends('layouts.app')

@section('titulo', 'Pases de Salida')

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
            // Colores de las píldoras, los de SEGCAT
            $estilos = ['todos' => 'dark', 'pendientes' => 'warning', 'aprobados' => 'primary', 'fuera' => 'dark', 'espera_regreso' => 'warning', 'vencidos' => 'danger'];
            $consulta = fn (array $extra) => array_filter(array_merge(['filtro' => $filtro, 'q' => $texto, 'sede' => $sedeFiltro], $extra), fn ($v) => $v !== null && $v !== '' && $v !== 'todos');
        @endphp

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
            <div class="encabezado-pantalla m-0">
                <div class="icono"><i class="bi bi-box-arrow-up-right text-primary" aria-hidden="true"></i></div>
                <div>
                    <h1>Pases de Salida</h1>
                    <p>Control de equipo que sale de la propiedad — préstamo, venta, reparación y más.</p>
                </div>
            </div>
            @if ($puede['crear'])
                <button type="button" class="btn-nuevo-pase" data-abrir-dialogo="dialogoNuevoPase">
                    <i class="bi bi-plus-circle me-2" aria-hidden="true"></i>Nuevo Pase
                </button>
            @endif
        </div>

        <nav class="filtros-pases" aria-label="Filtrar pases">
            @foreach (\App\Services\PasesSalida\AdministradorPasesSalida::FILTROS as $clave => $nombre)
                @php $activo = $filtro === $clave; @endphp
                <a href="{{ route('pases-salida.index', $consulta(['filtro' => $clave])) }}"
                   class="btn btn-sm fw-bold {{ $activo ? 'btn-'.$estilos[$clave] : 'btn-outline-'.$estilos[$clave] }}" @if ($activo) aria-current="page" @endif>
                    @if ($clave === 'vencidos')<i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>@endif{{ $nombre }}
                    <span class="conteo-filtro">{{ $conteos[$clave] }}</span>
                </a>
            @endforeach
        </nav>

        <form method="GET" data-autoenviar action="{{ route('pases-salida.index') }}" class="busqueda-pases" role="search">
            @if ($filtro !== 'todos')<input type="hidden" name="filtro" value="{{ $filtro }}">@endif
            @if ($variasSedes)
                <select name="sede" class="filtro-select" aria-label="Filtrar por sede" data-enviar-al-cambiar>
                    <option value="">Todas las sedes</option>
                    @foreach ($sedesVisibles as $s)
                        <option value="{{ $s->id }}" @selected($sedeFiltro === $s->id)>{{ $s->nombre }}</option>
                    @endforeach
                </select>
            @endif
            <div class="buscador">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="search" name="q" value="{{ $texto }}" maxlength="100" placeholder="Buscar por folio, solicitante o artículo..." aria-label="Buscar pase">
            </div>
            <button type="submit" class="btn-buscar-pases" aria-label="Buscar"><i class="bi bi-search" aria-hidden="true"></i><span class="d-none d-sm-inline ms-1">Buscar</span></button>
            @if ($texto !== '' || $sedeFiltro !== null)
                <a href="{{ route('pases-salida.index', $filtro !== 'todos' ? ['filtro' => $filtro] : []) }}" class="btn-limpiar-pases">Limpiar</a>
            @endif
        </form>

        <div class="lista-pases" data-lista-pases>
            @forelse ($pases as $p)
                @php
                    $vencido = $pasesSrv->vencido($p);
                    [$textoEstado, $claseEstado] = $p->insignia($vencido);
                    $tentativa = \App\Models\PaseSalida::dia($p->fecha_tentativa_regreso);
                @endphp
                <article class="pase-card pase-{{ $claseEstado }}" id="pase-{{ $p->id }}">
                    <div class="pase-card-cabecera">
                        <div class="min-w-0">
                            <h2 class="pase-titulo"><strong>{{ $p->folio }}</strong> — {{ $p->solicitante?->nombreCompleto() ?? '—' }}</h2>
                            <div class="pase-meta">
                                {{ $p->etiquetaMotivo() }}
                                · {{ $p->articulos_count }} {{ $p->articulos_count === 1 ? 'artículo' : 'artículos' }}
                                · Creado @fecha($p->created_at, 'd/m/Y')
                                @if ($p->fecha_salida_programada) · Sale: @fecha(\App\Models\PaseSalida::dia($p->fecha_salida_programada), 'd/m/Y')@endif
                                @if ($tentativa && $p->estaFuera())
                                    · @if ($vencido)<span class="texto-vencido"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i> Debió regresar el @fecha($tentativa, 'd/m/Y')</span>
                                    @else Regresa (tentativo): @fecha($tentativa, 'd/m/Y')@endif
                                @endif
                            </div>
                            <div class="pase-ruta">
                                <i class="bi bi-geo-alt" aria-hidden="true"></i> {{ $p->sede?->nombre }}
                                <i class="bi bi-arrow-right mx-1" aria-hidden="true"></i>
                                <i class="bi {{ ['sede' => 'bi-building', 'proveedor' => 'bi-truck', 'colaborador' => 'bi-person'][$p->destino_tipo] ?? 'bi-signpost' }}" aria-hidden="true"></i> {{ $p->nombreDestino() }}
                            </div>
                        </div>
                        <span class="badge-pase pase-{{ $claseEstado }}">{{ $textoEstado }}</span>
                    </div>
                    <div class="pase-card-pie">
                        <a href="{{ route('pases-salida.index', $consulta(['pase' => $p->id, 'page' => request('page')])) }}" class="btn-ver-pase"
                           data-detalle-pase="{{ route('pases-salida.show', $p->id) }}">
                            <i class="bi bi-folder2-open me-1" aria-hidden="true"></i>{{ $p->textoBoton() }}
                        </a>
                        @if ($p->editor && $p->updated_at?->ne($p->created_at))
                            <span class="texto-traza"><i class="bi bi-clock-history" aria-hidden="true"></i> Editado por {{ $p->editor->name }} · @fecha($p->updated_at)</span>
                        @elseif ($p->creador)
                            <span class="texto-traza"><i class="bi bi-plus-circle" aria-hidden="true"></i> Creado por {{ $p->creador->name }} · @fecha($p->created_at)</span>
                        @endif
                    </div>
                </article>
            @empty
                <div class="tarjeta estado-vacio">
                    <div class="icono"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i></div>
                    <p class="fw-semibold mb-1">No se encontraron pases{{ $texto !== '' ? ' para «'.$texto.'»' : '' }}.</p>
                    @if ($filtro === 'todos' && $texto === '' && $puede['crear'])
                        <p class="text-muted small m-0">Registra el primero con <strong>Nuevo Pase</strong>.</p>
                    @endif
                </div>
            @endforelse
        </div>

        @if ($pases->hasPages())
            <div class="mt-3">{{ $pases->onEachSide(1)->links('pagination::bootstrap-5') }}</div>
        @endif

        {{-- ===== Detalle / firmas del pase (se llena al abrirlo) ===== --}}
        <dialog id="dialogoDetallePase" class="dialogo ancho dialogo-pase" aria-labelledby="titulo-detalle-pase" data-dialogo-detalle-pase
                @if ($detalle) data-abrir-al-cargar @endif>
            @if ($detalle)
                @include('seguridad.pases-salida._detalle', $detalle)
            @endif
        </dialog>

        @if ($puede['crear'])
            @include('seguridad.pases-salida._formulario')
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
