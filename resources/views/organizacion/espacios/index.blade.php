@extends('layouts.app')

@section('titulo', 'Zonas y edificios')

@section('contenido')
<div class="tema-rojo">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    @if ($sinEmpresa)
        <div class="encabezado-pantalla mb-4">
            <div class="icono"><i class="bi bi-geo-alt-fill text-danger" aria-hidden="true"></i></div>
            <div><h1>Zonas y edificios</h1><p>Estructura física de cada sede.</p></div>
        </div>
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-buildings" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong> para ver sus zonas y edificios.</p>
        </div>
    @else
        {{-- Paso previo: se elige la sede y se entra a su pantalla (con una sola sede se entra directo) --}}
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
            <div class="encabezado-pantalla m-0">
                <div class="icono"><i class="bi bi-geo-alt-fill text-danger" aria-hidden="true"></i></div>
                <div>
                    <h1>Zonas y edificios</h1>
                    <p>Elige una sede para ver sus zonas, edificios y secciones.</p>
                </div>
            </div>
            <div class="barra-filtros justify-content-md-end">
                @if ($sedes->count() > 1)
                    <div class="buscador">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <input type="search" placeholder="Buscar sede..." aria-label="Buscar sede" data-filtro-texto="espacios-sedes">
                    </div>
                @endif
                @include('importaciones._boton', ['tipo' => 'zonas', 'modulo' => 'espacios'])
            </div>
        </div>

        @if ($sedes->isEmpty())
            <div class="tarjeta estado-vacio">
                <div class="icono"><i class="bi bi-house-door" aria-hidden="true"></i></div>
                <p class="text-muted small m-0">Primero registra una sede en <a href="{{ route('sedes.index') }}">Estructura → Sedes</a>.</p>
            </div>
        @else
            <div class="fichas-grid" data-fichas="espacios-sedes">
                @foreach ($sedes as $sede)
                    @php $r = $resumen[$sede->id]; @endphp
                    <div class="ficha-card" data-ficha data-estado="1" data-texto="{{ mb_strtolower($sede->nombre.' '.$sede->codigo) }}">
                        <div>
                            <h2 class="h5 fw-bold m-0"><i class="bi bi-geo-alt text-danger me-2" aria-hidden="true"></i>{{ $sede->nombre }}
                                @if ($sede->codigo)<span class="text-muted small fw-semibold">({{ $sede->codigo }})</span>@endif
                            </h2>
                            <ul class="list-unstyled small text-muted mt-2 pt-2 border-top m-0">
                                <li><i class="bi bi-building me-1" aria-hidden="true"></i> {{ $r['zonas'] }} {{ $r['zonas'] === 1 ? $etiquetas['edificio']['singular'] : $etiquetas['edificio']['plural'] }}</li>
                                <li><i class="bi bi-layers me-1" aria-hidden="true"></i> {{ $r['pisos'] }} {{ $r['pisos'] === 1 ? $etiquetas['area']['singular'] : $etiquetas['area']['plural'] }}</li>
                                <li><i class="bi bi-door-closed me-1" aria-hidden="true"></i> {{ $r['habitaciones'] }} {{ $r['habitaciones'] === 1 ? $etiquetas['area_especifica']['singular'] : $etiquetas['area_especifica']['plural'] }} en uso</li>
                                <li><i class="bi bi-bookmark me-1" aria-hidden="true"></i> {{ $r['secciones'] }} {{ $r['secciones'] === 1 ? 'Sección' : 'Secciones' }}</li>
                            </ul>
                        </div>
                        <a href="{{ route('espacios.sede', $sede->id) }}" class="btn-entrar" aria-label="Entrar a {{ $sede->nombre }}"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> Entrar a la sede</a>
                    </div>
                @endforeach
            </div>
            <p class="text-muted text-center p-4" data-sin-resultados="espacios-sedes" hidden><i class="bi bi-search me-2" aria-hidden="true"></i>No hay sedes que coincidan con tu búsqueda.</p>
        @endif
    @endif
</div>
@endsection
