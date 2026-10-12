{{--
    ADR-0014: lo que se ofrece al escanear un QR impreso fuera de un formulario
    (/e/{código}) cuando hay una acción probable. Variables: $menu (AccionesLector::para).
--}}
@extends('layouts.app')

@section('titulo', $menu['tipo'].' · '.$menu['titulo'])

@section('contenido')
<div class="pantalla-acciones-lector">
    @include('administracion.partes.avisos')
    <section class="tarjeta acciones-lector" aria-labelledby="titulo-acciones-lector">
        <p class="acciones-lector-tipo"><i class="bi {{ $menu['icono'] }} me-1" aria-hidden="true"></i>{{ $menu['tipo'] }}</p>
        <h1 id="titulo-acciones-lector">{{ $menu['titulo'] }}</h1>
        @if ($menu['detalle'])
            <p class="text-muted small mb-2">{{ $menu['detalle'] }}</p>
        @endif
        @if ($menu['estado'])
            <p class="acciones-lector-estado">{{ $menu['estado'] }}</p>
        @endif

        <div class="acciones-lector-botones">
            @foreach ($menu['acciones'] as $accion)
                @if ($accion['metodo'] === 'GET')
                    <a href="{{ $accion['url'] }}" class="{{ $accion['principal'] ? 'btn-accion-lector principal' : 'btn-accion-lector' }}">
                        <i class="bi {{ $accion['icono'] }} me-2" aria-hidden="true"></i>{{ $accion['etiqueta'] }}
                    </a>
                @else
                    <form action="{{ $accion['url'] }}" method="POST" class="m-0">
                        @csrf
                        @method($accion['metodo'])
                        @foreach ($accion['campos'] ?? [] as $campo => $valor)
                            <input type="hidden" name="{{ $campo }}" value="{{ $valor }}">
                        @endforeach
                        <button type="submit" class="{{ $accion['principal'] ? 'btn-accion-lector principal' : 'btn-accion-lector' }}">
                            <i class="bi {{ $accion['icono'] }} me-2" aria-hidden="true"></i>{{ $accion['etiqueta'] }}
                        </button>
                    </form>
                @endif
                @if ($accion['nota'])
                    <p class="campo-ayuda mt-1 mb-3">{{ $accion['nota'] }}</p>
                @endif
            @endforeach
            <a href="{{ $menu['ficha'] }}" class="acciones-lector-ficha"><i class="bi bi-card-text me-1" aria-hidden="true"></i>Solo ver la ficha</a>
        </div>
    </section>
</div>
@endsection
