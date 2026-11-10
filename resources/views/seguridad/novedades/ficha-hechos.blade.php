@extends('layouts.app')

@section('titulo', 'Ficha de Hechos')

@section('contenido')
<div class="pantalla-ficha-hechos">
    @include('administracion.partes.avisos')

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
        <div class="encabezado-pantalla m-0">
            <div class="icono"><i class="bi bi-clipboard-data-fill text-dark" aria-hidden="true"></i></div>
            <div>
                <h1>Ficha de Hechos</h1>
                @if ($habitacion)
                    <p>{{ collect([$habitacion->padre?->padre?->nombre, $habitacion->padre?->nombre, $habitacion->nombre])->filter()->join(' · ') }} — ventana de {{ \App\Services\Novedades\FichaHechos\FichaDeHechos::DIAS_ANTES }} días antes a {{ \App\Services\Novedades\FichaHechos\FichaDeHechos::DIAS_DESPUES }} días después del {{ \Carbon\Carbon::createFromFormat('Y-m-d', $fecha)->format('d/m/Y') }}</p>
                @else
                    <p>Se necesita una habitación válida para poder cruzar la información.</p>
                @endif
            </div>
        </div>
        <a href="{{ route('novedades.index') }}" class="btn-accion-novedades"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver</a>
    </div>

    @if (! $habitacion)
        <div class="alert alert-warning aviso">No se encontró la habitación indicada, o no tienes acceso a su sede.</div>
    @else
        <p class="campo-ayuda mt-0 mb-3"><i class="bi bi-info-circle" aria-hidden="true"></i> Es apoyo para la investigación: tú decides qué es relevante.</p>

        @foreach (['habitacion' => ['En esta habitación exacta', 'bi-door-closed-fill text-danger', 'Nada más registrado aquí en esta ventana de fechas.'],
            'cercanos' => ['En esta misma zona, como contexto', 'bi-building text-primary', 'Nada más registrado en esta zona en esta ventana de fechas.']] as $grupo => [$titulo, $icono, $vacio])
            <h2 class="titulo-ficha-hechos"><i class="bi {{ $icono }} me-2" aria-hidden="true"></i>{{ $titulo }} ({{ count($ficha[$grupo]) }})</h2>
            @if ($grupo === 'cercanos')
                <p class="text-muted small">Esto es más amplio que la habitación exacta — puede o no ser relevante, revisa con criterio.</p>
            @endif
            @forelse ($ficha[$grupo] as $h)
                <div class="ficha-hecho" data-color="{{ $h['color'] }}">
                    <div class="icono-hecho"><i class="bi {{ $h['icono'] }}" aria-hidden="true"></i></div>
                    <div class="flex-grow-1">
                        <div class="tipo-hecho">{{ $h['tipo'] }} · @fecha($h['fecha'])@if (! empty($h['estatus'])) · {{ $h['estatus'] }}@endif</div>
                        <div class="small mt-1">{!! nl2br(e(\Illuminate\Support\Str::limit((string) $h['texto'], 400))) !!}</div>
                        <div class="d-flex flex-wrap gap-2 mt-2 align-items-center">
                            @if (! empty($h['enlace']))<a href="{{ $h['enlace'] }}" class="small">Ver detalle →</a>@endif
                            @if ($vincularUrl && ! empty($h['vinculable']) && ! empty($h['id_articulo']))
                                <button type="button" class="btn-vincular" data-accion="novedad-vincular" data-url="{{ $vincularUrl }}" data-articulo="{{ $h['id_articulo'] }}">
                                    <i class="bi bi-link-45deg me-1" aria-hidden="true"></i>Vincular a este {{ $origen === 'robo' ? 'caso de robo' : 'reporte de pérdida' }}
                                </button>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <p class="text-muted small p-2">{{ $vacio }}</p>
            @endforelse
        @endforeach
    @endif
</div>
@endsection
