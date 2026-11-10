@extends('layouts.app')

@section('titulo', 'Ficha del colaborador')

@section('contenido')
{{--
    Ficha del colaborador en pestañas (role=tablist; flechas, Inicio y Fin
    con el teclado; #expediente, #codigo o #historial abren su pestaña).
    Comportamiento: public/js/pestanas.js.

    Para agregar una pestaña (p. ej. «Credencial»): una entrada en $pestanas
    con [título, ícono, ¿se muestra?] y su vista ficha/_<clave>.blade.php.
    Cada pestaña se muestra solo si el usuario puede ver lo que contiene.
--}}
@php
    $pestanas = array_filter([
        'datos' => ['Datos', 'bi-person-badge', true],
        // Datos personales: sin ese permiso la pestaña no aparece
        'expediente' => ['Expediente', 'bi-folder2-open', $personales],
        'codigo' => ['Código y etiqueta', 'bi-qr-code', ! empty($c->codigo_qr)],
        'historial' => ['Historial', 'bi-clock-history', true],
        // C-33 (D-14): solo lectura, con su permiso propio «Colaboradores → Ver accidentes»
        'accidentes' => ['Accidentes', 'bi-bandaid', $accidentes !== null],
    ], fn (array $p) => $p[2]);
    $primera = array_key_first($pestanas);
@endphp
<div class="pantalla-candidato">
    @include('administracion.partes.avisos')

    <a href="{{ route('colaboradores.index') }}" class="enlace-volver"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Colaboradores</a>

    <div class="encabezado-pantalla mb-3">
        <div class="icono"><i class="bi bi-person-vcard text-success" aria-hidden="true"></i></div>
        <div>
            <h1>{{ $c->nombreCompleto() }}</h1>
            <p>Núm. {{ $c->num_empleado }}{{ $c->sede ? ' · '.$c->sede->nombre : '' }}{{ $c->departamento ? ' · '.$c->departamento->nombre : '' }}{{ $c->puesto ? ' · '.$c->puesto->nombre : '' }}</p>
        </div>
    </div>

    <div class="pestanas-pase" role="tablist" aria-label="Secciones de la ficha" data-pestanas data-inicial="{{ $primera }}">
        @foreach ($pestanas as $clave => [$titulo, $icono])
            <button type="button" role="tab" id="pestana-{{ $clave }}" class="pestana-pase {{ $clave === $primera ? 'activa' : '' }}"
                    aria-selected="{{ $clave === $primera ? 'true' : 'false' }}" aria-controls="panel-{{ $clave }}" tabindex="{{ $clave === $primera ? '0' : '-1' }}"
                    data-pestana="{{ $clave }}"><i class="bi {{ $icono }}" aria-hidden="true"></i> {{ $titulo }}</button>
        @endforeach
    </div>

    @foreach ($pestanas as $clave => [$titulo])
        <section id="panel-{{ $clave }}" class="panel-pase" role="tabpanel" aria-labelledby="pestana-{{ $clave }}" tabindex="0" data-panel="{{ $clave }}" @if ($clave !== $primera) hidden @endif>
            @include('organizacion.colaboradores.ficha._'.$clave)
        </section>
    @endforeach
</div>

@if (isset($pestanas['codigo']))
    @include('componentes.codigo-identificacion')
@endif
@push('scripts')
    <script src="{{ asset('js/pestanas.js') }}?v={{ @filemtime(public_path('js/pestanas.js')) ?: '1' }}"></script>
@endpush
@endsection
