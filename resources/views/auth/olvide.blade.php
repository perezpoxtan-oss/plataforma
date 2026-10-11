<!DOCTYPE html>
<html lang="es">
<head>
    @include('layouts.partes.cabeza')
    <title>Olvidé mi contraseña | {{ $identidad->get('nombre') }}</title>
</head>
<body class="acceso">

    @include('layouts.partes.ambiente')

<div class="acceso-caja">
    <div class="acceso-tarjeta">

        <div class="acceso-simbolo">@include('layouts.partes.simbolo', ['icono' => 'bi-key'])</div>

        <h1 class="acceso-titulo h3">Olvidé mi contraseña</h1>
        <p class="acceso-subtitulo">{{ $identidad->get('nombre') }}</p>

        @if (session('enviado'))
            <div class="alert alert-success acceso-aviso" role="status">
                <i class="bi bi-envelope-check-fill me-2" aria-hidden="true"></i> {{ session('enviado') }}
            </div>
            <p class="acceso-texto">El enlace sirve durante 60 minutos. Si no llega, revisa que escribiste bien tu usuario o tu correo y vuelve a pedirlo; si sigue sin llegar, pide a tu administrador que restablezca tu contraseña.</p>
            <p class="acceso-enlace-ayuda"><a href="{{ route('login') }}"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Volver a entrar</a></p>
        @else
            @if (session('error'))
                <div class="alert alert-danger acceso-aviso" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2" aria-hidden="true"></i> {{ session('error') }}
                </div>
            @endif
            @if ($errors->any())
                <div class="alert alert-danger acceso-aviso" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-2" aria-hidden="true"></i> Escribe tu usuario o tu correo.
                </div>
            @endif

            @if (! $correoListo)
                <div class="alert alert-warning acceso-aviso" role="alert">
                    <i class="bi bi-envelope-slash me-2" aria-hidden="true"></i> Tu plataforma no tiene correo configurado: pide a tu administrador que restablezca tu contraseña.
                </div>
                <p class="acceso-enlace-ayuda"><a href="{{ route('login') }}"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Volver a entrar</a></p>
            @else
                <p class="acceso-texto">Escribe tu usuario o tu correo y te enviamos un enlace para crear una contraseña nueva. Nadie más la conocerá.</p>

                <form action="{{ route('contrasena.solicitar') }}" method="POST" novalidate>
                    @csrf
                    <div class="acceso-campo mb-4">
                        <label for="cuenta">Usuario o Correo</label>
                        <div class="acceso-entrada">
                            <input type="text" id="cuenta" name="cuenta" value="{{ old('cuenta') }}" placeholder="Tu usuario o tu correo" required autocomplete="username" autofocus maxlength="150">
                            <i class="bi bi-person" aria-hidden="true"></i>
                        </div>
                    </div>
                    <button type="submit" class="acceso-boton">
                        Enviarme el enlace <i class="bi bi-envelope-fill" aria-hidden="true"></i>
                    </button>
                    <p class="acceso-enlace-ayuda"><a href="{{ route('login') }}"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Volver a entrar</a></p>
                </form>
            @endif
        @endif
    </div>

    <div class="acceso-pie">
        <i class="bi bi-lock-fill me-1" aria-hidden="true"></i> Entorno de conexión verificado &bull; &copy; {{ date('Y') }} {{ $identidad->get('titular') ?: $identidad->get('nombre_corto') }}
    </div>
</div>

<script src="{{ asset('js/plataforma.js') }}?v={{ @filemtime(public_path('js/plataforma.js')) ?: '1' }}"></script>
</body>
</html>
