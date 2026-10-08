@extends('layouts.app')

@section('titulo', 'Rutas de Transporte')

@section('contenido')
<div class="tema-rutas">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    @if ($sinEmpresa)
        <div class="encabezado-pantalla mb-4">
            <div class="icono"><i class="bi bi-bus-front rutas-icono-titulo" aria-hidden="true"></i></div>
            <div><h1>Rutas de Transporte</h1><p>Elige una sede para ver sus rutas de llegada y salida.</p></div>
        </div>
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-buildings" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong> para ver sus rutas de transporte.</p>
        </div>
    @else
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
            <div class="encabezado-pantalla m-0">
                <div class="icono"><i class="bi bi-bus-front rutas-icono-titulo" aria-hidden="true"></i></div>
                <div>
                    <h1>Rutas de Transporte</h1>
                    <p>Elige una sede para ver sus rutas de llegada y salida.</p>
                    <p class="texto-padron-de"><i class="bi bi-building-check" aria-hidden="true"></i> Rutas de: <strong>{{ $empresaNombre }}</strong></p>
                </div>
            </div>
            @if ($sedes->count() > 1)
                <div class="buscador">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="search" placeholder="Buscar por sede, ruta o transportista..." aria-label="Buscar sede" data-filtro-texto="rutas-sedes">
                </div>
            @endif
        </div>

        <div class="fichas-grid rutas-sedes-grid" data-fichas="rutas-sedes">
            @forelse ($sedes as $sede)
                @php
                    $r = $resumen[$sede->id];
                    $texto = mb_strtolower(implode(' ', array_filter([$sede->nombre, $sede->codigo, $empresaNombre, implode(' ', $r['transportistas']), $r['rutas']])));
                @endphp
                <div class="ficha-card rutas-ficha-sede" id="sede-{{ $sede->id }}" data-ficha data-texto="{{ $texto }}" data-estado="1">
                    <div>
                        <div class="rutas-sede-cabecera">
                            <div class="rutas-sede-icono"><i class="bi bi-building" aria-hidden="true"></i></div>
                            <div class="rutas-sede-titulo">
                                <h2 title="{{ $sede->nombre }}"><a href="{{ route('rutas.sede', $sede->id) }}">{{ $sede->nombre }}</a></h2>
                                <div class="rutas-sede-empresa">{{ $empresaNombre }}</div>
                            </div>
                        </div>

                        <div class="rutas-insignias">
                            <span class="rutas-sentido llegada"><i class="bi bi-sign-turn-right-fill me-1" aria-hidden="true"></i>{{ $r['llegadas'] }} {{ $r['llegadas'] === 1 ? 'Llegada' : 'Llegadas' }}</span>
                            <span class="rutas-sentido salida"><i class="bi bi-sign-turn-left-fill me-1" aria-hidden="true"></i>{{ $r['salidas'] }} {{ $r['salidas'] === 1 ? 'Salida' : 'Salidas' }}</span>
                            <span class="rutas-sentido paradero"><i class="bi bi-geo-alt-fill me-1" aria-hidden="true"></i>{{ $r['paraderos'] }} {{ $r['paraderos'] === 1 ? 'Paradero' : 'Paraderos' }}</span>
                        </div>

                        <div class="rutas-proximos">
                            @foreach (['proximas_llegadas' => ['Próx. Llegadas', 'bi-sign-turn-right-fill text-primary'], 'proximas_salidas' => ['Próx. Salidas', 'bi-sign-turn-left-fill text-success']] as $clave => [$titulo, $icono])
                                <div class="rutas-proximos-col">
                                    <div class="rutas-proximos-titulo"><i class="bi {{ $icono }}" aria-hidden="true"></i> {{ $titulo }}</div>
                                    @forelse ($r[$clave] as $etiqueta)
                                        <div class="rutas-proximos-item" title="{{ $etiqueta }}">{{ $etiqueta }}</div>
                                    @empty
                                        <div class="rutas-proximos-item text-muted">Sin horarios</div>
                                    @endforelse
                                </div>
                            @endforeach
                        </div>

                        @if ($r['transportistas'])
                            <div class="rutas-transportistas" title="{{ implode(', ', $r['transportistas']) }}"><i class="bi bi-truck me-1" aria-hidden="true"></i>{{ implode(', ', $r['transportistas']) }}</div>
                        @endif
                        @if ($r['suspendidas'] > 0)
                            <div class="rutas-aviso-suspendidas"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i> {{ $r['suspendidas'] === 1 ? 'Con 1 ruta suspendida' : "Con {$r['suspendidas']} rutas suspendidas" }}</div>
                        @endif
                    </div>

                    <div class="rutas-sede-acciones">
                        <a href="{{ route('rutas.sede', $sede->id) }}" class="btn-detalle-sede"><i class="bi bi-sliders me-1" aria-hidden="true"></i>Ver Detalles</a>
                        {{-- Ronda 8 (RT-07/RT-08): horarios de la semana, de consulta (también el Agente) --}}
                        <a href="{{ route('rutas.semana', $sede->id) }}" target="_blank" rel="noopener" class="btn-icono imprimir-hoja" title="Imprimir horarios de la semana" aria-label="Imprimir la hoja de horarios de {{ $sede->nombre }}"><i class="bi bi-printer" aria-hidden="true"></i></a>
                    </div>
                </div>
            @empty
                <div class="tarjeta estado-vacio rutas-vacio">
                    <div class="icono"><i class="bi bi-building" aria-hidden="true"></i></div>
                    <p class="fw-semibold m-0">No hay sedes registradas todavía.</p>
                    <p class="text-muted small m-0">Las rutas se organizan por sede: primero da de alta una sede en Estructura → Sedes.</p>
                </div>
            @endforelse

            <div class="sin-resultados" data-sin-resultados="rutas-sedes" hidden>
                <i class="bi bi-search d-block mb-2" aria-hidden="true"></i>
                <p class="fw-semibold m-0">No hay sedes que coincidan con tu búsqueda.</p>
            </div>
        </div>
    @endif
</div>
@endsection
