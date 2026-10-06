@extends('layouts.app')

@section('titulo', 'Días de Resguardo — Lost & Found')

@section('contenido')
<div class="tema-ambar pantalla-lost-found pantalla-umbrales-lf">
    @include('administracion.partes.avisos')

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
        <div class="encabezado-pantalla m-0">
            <div class="icono"><i class="bi bi-sliders text-primary" aria-hidden="true"></i></div>
            <div>
                <h1>Días de Resguardo — Lost &amp; Found</h1>
                <p>Cuántos días puede estar un artículo en resguardo, según su clasificación, antes de que el semáforo lo marque para atención.</p>
                <p class="texto-padron-de"><i class="bi bi-building-check" aria-hidden="true"></i> Empresa: <strong>{{ $empresaNombre }}</strong> (aplica a todas sus sedes)</p>
            </div>
        </div>
        <a href="{{ route('lost_found.archivo') }}" class="btn-accion-novedades"><i class="bi bi-arrow-left" aria-hidden="true"></i> Volver</a>
    </div>

    @unless ($puedeEditar)
        <div class="alert alert-secondary aviso py-2 px-3 small"><i class="bi bi-lock-fill me-1" aria-hidden="true"></i>Tu rol solo puede consultar esta configuración, no modificarla.</div>
    @endunless

    <form method="POST" action="{{ route('lost_found.umbrales.guardar') }}">
        @csrf
        @method('PUT')
        @foreach (\App\Models\LostFoundArticulo::TIPOS_VALOR as $tipo => $nombre)
            @php $guardado = $guardados[$tipo] ?? null; @endphp
            <div class="umbral-card">
                <div>
                    <label class="fw-bold" for="dias_{{ $tipo }}">{{ $nombre }}</label>
                    <div class="small text-muted">
                        Amarillo a partir del día {{ (int) ceil($dias[$tipo] * 0.7) }}; rojo (vencido) desde el día {{ $dias[$tipo] }}.
                    </div>
                    @if ($guardado && $guardado->updated_at)
                        <div class="texto-traza">Última actualización: @fecha($guardado->updated_at)@if ($guardado->editor) — {{ $guardado->editor->name }}@endif</div>
                    @endif
                </div>
                <div class="d-flex align-items-center gap-2">
                    <input type="number" id="dias_{{ $tipo }}" name="dias[{{ $tipo }}]" class="campo campo-dias-lf" min="1" max="{{ \App\Services\Novedades\ArchivoLostFound::MAX_DIAS }}" inputmode="numeric" required
                           value="{{ old('dias.'.$tipo, $dias[$tipo]) }}" @disabled(! $puedeEditar)
                           data-mensaje-min="Debe ser al menos 1 día: un artículo no puede vencer el mismo día que se encuentra.">
                    <span class="text-muted small">día(s)</span>
                </div>
            </div>
        @endforeach

        @if ($puedeEditar)
            <button type="submit" class="btn-guardar-expediente btn-guardar-umbrales"><i class="bi bi-save-fill me-2" aria-hidden="true"></i>Guardar Cambios</button>
        @endif
    </form>
</div>
@endsection
