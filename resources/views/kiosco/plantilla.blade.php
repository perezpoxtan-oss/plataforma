{{-- Kiosco de auto-registro: página pública, sin menú ni datos de la plataforma. No se indexa. --}}
@php
    $version = fn (string $archivo) => asset($archivo).'?v='.(@filemtime(public_path($archivo)) ?: '1');
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="robots" content="noindex, nofollow, noarchive">
    <meta name="referrer" content="no-referrer">
    <title>@yield('titulo', 'Solicitud de empleo') | {{ $identidad->get('nombre_corto') }}</title>
    <link href="{{ $version('vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ $version('vendor/bootstrap-icons/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ $version('css/plataforma.css') }}" rel="stylesheet">
    <link href="{{ $version('css/modos-pantalla.css') }}" rel="stylesheet">
    <script src="{{ $version('js/modo-pantalla.js') }}"></script>
</head>
<body class="app kiosco-publico">
    <header class="kiosco-cabeza">
        <span class="marca-simbolo">@include('layouts.partes.simbolo')</span>
        <span class="kiosco-titulo">@yield('titulo', 'Solicitud de empleo')</span>
    </header>
    <main class="kiosco-contenido">
        @yield('contenido')
    </main>
    <footer class="kiosco-pie">Tus datos viajan cifrados y solo los ve Recursos Humanos.</footer>
    <script src="{{ $version('js/plataforma.js') }}"></script>
</body>
</html>
