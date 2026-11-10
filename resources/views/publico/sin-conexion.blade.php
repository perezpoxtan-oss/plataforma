{{-- Página «Sin conexión» del service worker (public/js/sw.js). Sin sesión: no muestra datos de nadie. --}}
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <meta name="theme-color" content="{{ $identidad->get('color_primario') }}">
    <title>Sin conexión · {{ $identidad->get('nombre_corto') }}</title>
    <style>
        body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; background: #f8fafc; color: #0f172a; font-family: system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
        .caja { max-width: 24rem; padding: 2rem 1.5rem; text-align: center; }
        .caja img { border-radius: 22%; }
        h1 { font-size: 1.4rem; margin: 1rem 0 .5rem; }
        p { color: #334155; line-height: 1.5; }
        a.boton { display: inline-block; margin-top: 1rem; padding: .8rem 1.6rem; border-radius: .7rem; background: {{ $identidad->get('color_primario') }}; color: #fff; text-decoration: none; }
    </style>
</head>
<body>
    <main class="caja">
        <img src="{{ asset('iconos/app-192.png') }}" alt="" width="96" height="96">
        <h1>Sin conexión</h1>
        <p>No pudimos abrir la plataforma. Revisa tu internet (datos o wifi) y vuelve a intentarlo. Lo que no alcanzaste a guardar no se envió.</p>
        <a class="boton" href="{{ url('/') }}">Reintentar</a>
    </main>
</body>
</html>
