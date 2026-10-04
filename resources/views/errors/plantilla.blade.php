{{--
    Pantallas de error en español. No dependen de la base de datos ni de la
    sesión (deben verse aunque la base esté caída): solo archivos propios.
    Parámetros: $codigo, $icono, $color, $titulo, $mensaje, $accion ('inicio' | 'acceso' | 'recargar')
--}}
@php
    $version = fn (string $archivo) => asset($archivo).'?v='.(@filemtime(public_path($archivo)) ?: '1');
    $conSesion = false;
    try { $conSesion = auth()->check(); } catch (\Throwable) { $conSesion = false; }
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $titulo }}</title>
    <link href="{{ $version('vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ $version('vendor/bootstrap-icons/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ $version('css/plataforma.css') }}" rel="stylesheet">
    <link href="{{ $version('css/modos-pantalla.css') }}" rel="stylesheet">
    <script src="{{ $version('js/modo-pantalla.js') }}"></script>
</head>
<body class="acceso">
<main class="acceso-caja">
    <div class="acceso-tarjeta pagina-error">
        <div class="error-icono" style="--error-color: {{ $color }}"><i class="bi {{ $icono }}" aria-hidden="true"></i></div>
        <p class="error-codigo">Error {{ $codigo }}</p>
        <h1 class="h4 fw-bold mb-2">{{ $titulo }}</h1>
        <p class="text-muted mb-4">{{ $mensaje }}</p>
        @switch($accion)
            @case('acceso')
                <a href="{{ url('/login') }}" class="acceso-boton d-inline-flex align-items-center justify-content-center gap-2 text-decoration-none"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> Volver a entrar</a>
                @break
            @case('recargar')
                <a href="{{ url()->current() }}" class="acceso-boton d-inline-flex align-items-center justify-content-center gap-2 text-decoration-none"><i class="bi bi-arrow-clockwise" aria-hidden="true"></i> Intentar de nuevo</a>
                @break
            @default
                <a href="{{ url($conSesion ? '/' : '/login') }}" class="acceso-boton d-inline-flex align-items-center justify-content-center gap-2 text-decoration-none"><i class="bi bi-house-door" aria-hidden="true"></i> {{ $conSesion ? 'Ir al inicio' : 'Ir a la pantalla de acceso' }}</a>
        @endswitch
        <p class="small text-muted mt-4 mb-0">Si el problema continúa, avisa a tu administrador e indica el código <strong>{{ $codigo }}</strong> y la hora.</p>
    </div>
</main>
</body>
</html>
