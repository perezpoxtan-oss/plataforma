{{-- Páginas públicas de privacidad (sin sesión): aviso con su versión, solicitud ARCO y confirmación del correo --}}
<!DOCTYPE html>
<html lang="es">
<head>
    @include('layouts.partes.cabeza')
    <meta name="robots" content="noindex, nofollow, noarchive">
    <title>@yield('titulo') | {{ $identidad->get('nombre') }}</title>
</head>
<body class="acceso">

    @include('layouts.partes.ambiente')

<div class="acceso-caja">
    <div class="acceso-tarjeta" style="max-width:40rem;">
        <div class="acceso-simbolo">@include('layouts.partes.simbolo', ['icono' => 'bi-shield-lock'])</div>
        @yield('contenido')
    </div>
</div>

<script src="{{ asset('js/plataforma.js') }}?v={{ @filemtime(public_path('js/plataforma.js')) ?: '1' }}"></script>
</body>
</html>
