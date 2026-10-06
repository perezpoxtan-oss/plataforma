@extends('layouts.app')

@section('titulo', 'Vouchers de Reposición')

@section('contenido')
<div class="tema-gafetes pantalla-vouchers">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    @if ($sinEmpresa)
        <div class="encabezado-pantalla mb-4">
            <div class="icono"><i class="bi bi-receipt text-dark" aria-hidden="true"></i></div>
            <div><h1>Vouchers de Reposición</h1><p>Historial de bajas por extravío, daño o robo — Llaves, Gafetes y Equipos.</p></div>
        </div>
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-buildings" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong> para ver sus vouchers.</p>
        </div>
    @else
        @php
            $hayFiltros = $filtros['q'] !== '' || collect($filtros)->except('q')->filter(fn ($v) => $v !== null)->isNotEmpty();
            $iconos = ['llave' => 'bi-key-fill', 'gafete' => 'bi-vignette', 'equipo' => 'bi-box-seam'];
            $plurales = ['llave' => 'Llaves', 'gafete' => 'Gafetes', 'equipo' => 'Equipos'];
            $nombres = array_map(fn ($c) => $plurales[$c] ?? ucfirst($c), array_keys($origenes));
            $visibles = count($nombres) > 1 ? implode(', ', array_slice($nombres, 0, -1)).' y '.end($nombres) : implode('', $nombres);
        @endphp

        <div class="encabezado-pantalla mb-3">
            <div class="icono"><i class="bi bi-receipt text-dark" aria-hidden="true"></i></div>
            <div>
                <h1>Vouchers de Reposición</h1>
                <p>Historial de bajas por extravío, daño o robo — {{ $visibles ?: 'Llaves, Gafetes y Equipos' }}.</p>
                <p class="texto-padron-de"><i class="bi bi-building-check" aria-hidden="true"></i> Vouchers de: <strong>{{ $empresaNombre }}</strong></p>
            </div>
        </div>

        <form method="GET" data-autoenviar action="{{ route('vouchers.index') }}" class="filtros-vouchers" role="search" aria-label="Filtrar vouchers">
            <div class="buscador">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="search" name="q" value="{{ $filtros['q'] }}" placeholder="Folio, artículo o responsable..." aria-label="Buscar voucher">
            </div>
            @if ($sedes->count() > 1)
                <select name="sede" class="filtro-select" aria-label="Filtrar por sede" data-enviar-al-cambiar>
                    <option value="">Todas las sedes</option>
                    @foreach ($sedes as $sede)
                        <option value="{{ $sede->id }}" @selected($filtros['sede'] === $sede->id)>{{ $sede->nombre }}</option>
                    @endforeach
                </select>
            @endif
            @if (count($origenes) > 1)
                <select name="origen" class="filtro-select" aria-label="Filtrar por origen" data-enviar-al-cambiar>
                    <option value="">{{ $visibles }}</option>
                    @foreach ($origenes as $clave => $etiqueta)
                        <option value="{{ $clave }}" @selected($filtros['origen'] === $clave)>Solo {{ $plurales[$clave] ?? $etiqueta }}</option>
                    @endforeach
                </select>
            @endif
            <select name="cobro" class="filtro-select" aria-label="Filtrar por cobro" data-enviar-al-cambiar>
                <option value="">Aplica cobro o no</option>
                <option value="1" @selected($filtros['cobro'] === '1')>Solo con cobro</option>
                <option value="0" @selected($filtros['cobro'] === '0')>Solo sin cobro</option>
            </select>
            <div class="rango-fechas">
                <label class="filtro-fecha"><span>Desde</span><input type="date" name="desde" value="{{ $filtros['desde'] }}" data-enviar-al-cambiar></label>
                <label class="filtro-fecha"><span>Hasta</span><input type="date" name="hasta" value="{{ $filtros['hasta'] }}" data-enviar-al-cambiar></label>
            </div>
            <div class="acciones-filtro">
                <button type="submit" class="btn-gafetes"><i class="bi bi-search me-1" aria-hidden="true"></i>Buscar</button>
                @if ($hayFiltros)
                    <a href="{{ route('vouchers.index') }}" class="btn-limpiar-filtros"><i class="bi bi-x-lg me-1" aria-hidden="true"></i>Quitar filtros</a>
                @endif
            </div>
        </form>

        @if ($origenes === [])
            <p class="aviso-solo-lectura"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Los vouchers se muestran según el módulo de donde salieron (Llaves, Gafetes o Equipos) y no tienes permiso de consultar ninguno de ellos. Pide que te lo asignen en la matriz de permisos.</p>
        @elseif ($vouchers->total() > 0)
            <p class="conteo-vouchers">{{ $vouchers->total() === 1 ? '1 voucher' : number_format($vouchers->total()).' vouchers' }}{{ $hayFiltros ? ' con estos filtros' : '' }}</p>
        @endif

        <div class="fichas-grid fichas-vouchers">
            @forelse ($vouchers as $v)
                <article class="ficha-card ficha-voucher" id="voucher-{{ $v->id }}">
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <span class="voucher-folio">{{ $v->folio }}</span>
                        <span class="voucher-cobro {{ $v->aplica_cobro ? 'con-cobro' : 'sin-cobro' }}">{{ $v->aplica_cobro ? 'CON COBRO' : 'SIN COBRO' }}</span>
                    </div>
                    <h2 class="voucher-articulo"><i class="bi {{ $iconos[$v->origen_tipo] ?? 'bi-box' }} me-1 text-muted" aria-hidden="true"></i>{{ $v->origen_descripcion }}</h2>
                    <div class="voucher-meta">
                        <div><span>Origen:</span> <strong>{{ $v->etiquetaOrigen() }}</strong></div>
                        <div><span>Motivo:</span> <strong>{{ \App\Models\VoucherReposicion::MOTIVOS[$v->motivo] ?? $v->motivo }}</strong></div>
                        <div><span>Sede:</span> <strong>{{ $v->sede?->nombre ?? '—' }}</strong></div>
                        <div><span>Responsable:</span> <strong>{{ $v->colaborador ? $v->colaborador->nombreCompleto() : 'No especificado' }}</strong></div>
                        @if ($v->aplica_cobro)
                            <div><span>Monto:</span> <strong class="voucher-monto">${{ number_format((float) $v->monto, 2) }}</strong></div>
                        @endif
                    </div>
                    @if ($v->descripcion)
                        <p class="voucher-como"><span>¿Cómo pasó?</span> {{ \Illuminate\Support\Str::limit($v->descripcion, 160) }}</p>
                    @endif
                    <div class="texto-traza"><i class="bi bi-clock-history" aria-hidden="true"></i> Generado por {{ $v->creado_por_nombre ?? 'alguien' }} · @fecha($v->created_at)</div>
                    @if ($puedeImprimir)
                        <a href="{{ route('vouchers.imprimir', $v->id) }}" target="_blank" rel="noopener" class="btn-ver-voucher"><i class="bi bi-printer" aria-hidden="true"></i> Ver / Reimprimir</a>
                    @endif
                </article>
            @empty
                @if ($origenes !== [])
                    <div class="sin-resultados">
                        <i class="bi {{ $hayVouchers ? 'bi-search' : 'bi-receipt' }} d-block mb-2" aria-hidden="true"></i>
                        <p class="fw-semibold m-0">{{ $hayVouchers ? 'No hay vouchers que coincidan con tu búsqueda.' : 'Todavía no se ha generado ningún voucher.' }}</p>
                        @unless ($hayVouchers)
                            <p class="small mt-1 mb-0">Se generan solos al dar de baja una llave, un gafete o un equipo extraviado, dañado o robado.</p>
                        @endunless
                    </div>
                @endif
            @endforelse
        </div>

        @if ($vouchers->hasPages())
            <div class="mt-4">{{ $vouchers->onEachSide(1)->links('pagination::bootstrap-5') }}</div>
        @endif
    @endif
</div>
@endsection
