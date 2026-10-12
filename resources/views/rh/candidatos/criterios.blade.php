@extends('layouts.app')

@section('titulo', 'Criterios de entrevista')

@section('contenido')
@php
    $ajustesRecepcion = app(\App\Services\Recepcion\AjustesRecepcion::class);
    $maximo = \App\Services\Recepcion\AjustesRecepcion::MAX_CRITERIOS;
    $criteriosForm = old('criterios', $ajustesRecepcion->criterios($empresa));
    $criteriosForm = array_pad(array_slice(is_array($criteriosForm) ? array_values($criteriosForm) : [], 0, $maximo), $maximo, '');
@endphp
<div class="pantalla-ajustes-rh">
    @include('administracion.partes.avisos')
    <a href="{{ route('candidatos.index') }}" class="enlace-volver"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Candidatos</a>
    <div class="encabezado-pantalla mb-3">
        <div class="icono"><i class="bi bi-star-half text-warning" aria-hidden="true"></i></div>
        <div>
            <h1>Criterios de entrevista</h1>
            <p>Con qué califican Recursos Humanos y el jefe del departamento a cada candidato (de 1 a 5 estrellas).</p>
        </div>
    </div>
    <section class="tarjeta p-4" aria-labelledby="t-criterios">
        <h2 id="t-criterios" class="h5 fw-bold"><i class="bi bi-star-half me-2 text-warning" aria-hidden="true"></i>Criterios para calificar las entrevistas</h2>
        <p class="small text-muted">Hasta {{ $maximo }} criterios; deja vacío el renglón que no uses. Si cambias la lista, las evaluaciones anteriores conservan sus criterios.</p>
        @unless ($puedeGuardar)
            <div class="alert alert-secondary small py-2"><i class="bi bi-lock-fill me-1" aria-hidden="true"></i>Los criterios son de toda la empresa: con tu alcance solo puedes consultarlos.</div>
        @endunless
        <form action="{{ route('recepcion.ajustes.guardar') }}" method="POST">
            @csrf
            @method('PUT')
            @error('criterios')<p class="texto-error-rh">{{ $message }}</p>@enderror
            <ol class="lista-criterios">
                @foreach ($criteriosForm as $i => $criterio)
                    <li><input type="text" name="criterios[]" class="campo" maxlength="80" value="{{ $criterio }}" aria-label="Criterio {{ $i + 1 }}" @disabled(! $puedeGuardar)></li>
                @endforeach
            </ol>
            @if ($puedeGuardar)
                <button type="submit" class="btn-verde btn-accion-rh mt-3">Guardar criterios</button>
            @endif
        </form>
    </section>
</div>
@endsection
