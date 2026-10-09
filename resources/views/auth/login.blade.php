<!DOCTYPE html>
<html lang="es">
<head>
    @include('layouts.partes.cabeza')
    <title>Iniciar Sesión | {{ $identidad->get('nombre') }}</title>
</head>
<body class="acceso">

    @include('layouts.partes.ambiente')

<div class="acceso-caja">
    <div class="acceso-tarjeta">

        <div class="acceso-simbolo">@include('layouts.partes.simbolo', ['icono' => 'bi-shield-check'])</div>

        <h1 class="acceso-titulo h3">{{ $identidad->get('nombre') }}</h1>
        <p class="acceso-subtitulo">{{ $identidad->get('eslogan') }}</p>

        @switch(session('acceso'))
            @case('expirado')
                <div class="alert alert-warning acceso-aviso" role="alert">
                    <i class="bi bi-clock-history me-2" aria-hidden="true"></i> Sesión finalizada por seguridad.
                    <span class="d-block small mt-1">Se cerró tras {{ (int) config('plataforma.sesion.inactividad_minutos') }} minutos sin actividad. Vuelve a entrar para continuar.</span>
                </div>
                @break
            @case('credenciales')
                <div class="alert alert-danger acceso-aviso" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2" aria-hidden="true"></i> Datos de acceso incorrectos.
                </div>
                @break
            @case('bloqueado')
                <div class="alert alert-danger acceso-aviso" role="alert">
                    <i class="bi bi-lock-fill me-2" aria-hidden="true"></i> Demasiados intentos fallidos. Esta cuenta está bloqueada temporalmente por {{ (int) session('minutos') }} minuto(s) más. Vuelve a intentarlo más tarde.
                </div>
                @break
            @case('equipo')
                <div class="alert alert-danger acceso-aviso" role="alert">
                    <i class="bi bi-lock-fill me-2" aria-hidden="true"></i> Demasiados intentos desde este equipo. Espera {{ (int) session('minutos') }} minuto(s) y vuelve a intentarlo.
                </div>
                @break
            @case('inactiva')
                <div class="alert alert-danger acceso-aviso" role="alert">
                    <i class="bi bi-person-x-fill me-2" aria-hidden="true"></i> Tu cuenta no está activa. Consulta a tu administrador.
                </div>
                @break
            @case('pagina_vencida')
                <div class="alert alert-warning acceso-aviso" role="alert">
                    <i class="bi bi-clock-history me-2" aria-hidden="true"></i> La página estuvo abierta mucho tiempo. Vuelve a intentarlo.
                </div>
                @break
        @endswitch

        @if ($errors->any())
            <div class="alert alert-danger acceso-aviso" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2" aria-hidden="true"></i> Captura tu usuario y tu contraseña.
            </div>
        @endif

        <form action="{{ route('login.iniciar') }}" method="POST" novalidate>
            @csrf

            <div class="acceso-campo mb-3">
                <label for="username">Usuario o Correo</label>
                <div class="acceso-entrada">
                    <input type="text" id="username" name="username" value="{{ old('username') }}" placeholder="Tu usuario o tu correo" required autocomplete="username" autofocus>
                    <i class="bi bi-person" aria-hidden="true"></i>
                </div>
            </div>

            <div class="acceso-campo mb-4">
                <label for="password">Contraseña</label>
                <div class="acceso-entrada">
                    <input type="password" id="password" name="password" class="con-boton" placeholder="••••••••" required autocomplete="current-password">
                    <i class="bi bi-lock" aria-hidden="true"></i>
                    <button type="button" class="acceso-ver-contrasena" data-accion="ver-contrasena" data-objetivo="password" aria-label="Mostrar u ocultar contraseña" tabindex="-1">
                        <i class="bi bi-eye" aria-hidden="true"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="acceso-boton">
                Autenticar Ingreso <i class="bi bi-arrow-right-short fs-5" aria-hidden="true"></i>
            </button>
        </form>
    </div>

    <div class="acceso-pie">
        <i class="bi bi-lock-fill me-1" aria-hidden="true"></i> Entorno de conexión verificado &bull; &copy; {{ date('Y') }} {{ $identidad->get('titular') ?: $identidad->get('nombre_corto') }}
    </div>
</div>

<script src="{{ asset('js/plataforma.js') }}?v={{ @filemtime(public_path('js/plataforma.js')) ?: '1' }}"></script>
</body>
</html>
