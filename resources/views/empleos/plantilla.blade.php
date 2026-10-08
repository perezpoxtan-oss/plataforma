{{-- Bolsa de trabajo pública (lección 36): sin menú ni datos de la plataforma. $empresa --}}
@php
    $version = fn (string $archivo) => asset($archivo).'?v='.(@filemtime(public_path($archivo)) ?: '1');
    $indexar = app(\App\Services\Vacantes\BolsaTrabajo::class)->ajustes($empresa)['indexar'] && ! $__env->hasSection('sin-indexar');
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @unless ($indexar)
        <meta name="robots" content="noindex, nofollow, noarchive">
    @endunless
    <meta name="referrer" content="no-referrer">
    <title>@yield('titulo', 'Bolsa de trabajo') | {{ $empresa->nombre_comercial }}</title>
    <link href="{{ $version('vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ $version('vendor/bootstrap-icons/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ $version('css/plataforma.css') }}" rel="stylesheet">
    <link href="{{ $version('css/modos-pantalla.css') }}" rel="stylesheet">
    <script src="{{ $version('js/modo-pantalla.js') }}"></script>
</head>
<body class="app kiosco-publico bolsa-publica">
    <header class="kiosco-cabeza">
        <i class="bi bi-briefcase-fill bolsa-cabeza-icono" aria-hidden="true"></i>
        <a href="{{ route('empleos.index', $empresa->bolsa_slug) }}" class="kiosco-titulo bolsa-cabeza-enlace">Empleos en {{ $empresa->nombre_comercial }}</a>
    </header>
    <main class="kiosco-contenido bolsa-contenido">
        @yield('contenido')
    </main>
    <footer class="kiosco-pie">Tus datos viajan cifrados y solo los ve Recursos Humanos de {{ $empresa->nombre_comercial }}.</footer>
    <script src="{{ $version('js/plataforma.js') }}"></script>
</body>
</html>
