<!DOCTYPE html>
<html lang="es">
<head>
    @include('layouts.partes.cabeza')
    <title>{{ $bienvenida ? 'Crea tu contraseña' : 'Contraseña nueva' }} | {{ $identidad->get('nombre') }}</title>
</head>
<body class="acceso">

    @include('layouts.partes.ambiente')

<div class="acceso-caja">
    <div class="acceso-tarjeta">

        <div class="acceso-simbolo">@include('layouts.partes.simbolo', ['icono' => 'bi-key'])</div>

        <h1 class="acceso-titulo h3">{{ $bienvenida ? 'Bienvenido, crea tu contraseña' : 'Crea tu contraseña nueva' }}</h1>
        <p class="acceso-subtitulo">{{ $identidad->get('nombre') }}</p>

        @if ($errors->any())
            <div class="alert alert-danger acceso-aviso" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2" aria-hidden="true"></i>
                <div>@foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
            </div>
        @endif

        <p class="acceso-texto">Hola, <strong>{{ $nombre }}</strong>. Tu usuario es <strong>{{ $usuario }}</strong>. Escribe una contraseña de al menos 8 caracteres, con letras y números, que no contenga tu usuario ni tu correo.</p>

        <form action="{{ route('contrasena.guardar') }}" method="POST" novalidate>
            @csrf
            <input type="hidden" name="token" value="{{ $token }}">
            <input type="hidden" name="email" value="{{ $correo }}">

            <div class="acceso-campo mb-3">
                <label for="password">Contraseña nueva</label>
                <div class="acceso-entrada">
                    <input type="password" id="password" name="password" class="con-boton" placeholder="Mínimo 8, con letras y números" required autocomplete="new-password" autofocus>
                    <i class="bi bi-lock" aria-hidden="true"></i>
                    <button type="button" class="acceso-ver-contrasena" data-accion="ver-contrasena" data-objetivo="password" aria-label="Mostrar u ocultar contraseña" tabindex="-1">
                        <i class="bi bi-eye" aria-hidden="true"></i>
                    </button>
                </div>
            </div>

            <div class="acceso-campo mb-4">
                <label for="password_confirmation">Repite la contraseña nueva</label>
                <div class="acceso-entrada">
                    <input type="password" id="password_confirmation" name="password_confirmation" class="con-boton" placeholder="Igual que arriba" required autocomplete="new-password">
                    <i class="bi bi-lock-fill" aria-hidden="true"></i>
                    <button type="button" class="acceso-ver-contrasena" data-accion="ver-contrasena" data-objetivo="password_confirmation" aria-label="Mostrar u ocultar contraseña" tabindex="-1">
                        <i class="bi bi-eye" aria-hidden="true"></i>
                    </button>
                </div>
            </div>

            <button type="submit" class="acceso-boton">
                Guardar <i class="bi bi-check-lg" aria-hidden="true"></i>
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
