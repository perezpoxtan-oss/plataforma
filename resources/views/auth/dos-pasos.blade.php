<!DOCTYPE html>
<html lang="es">
<head>
    @include('layouts.partes.cabeza')
    <title>Verificación en dos pasos | {{ $identidad->get('nombre') }}</title>
</head>
<body class="acceso">

    @include('layouts.partes.ambiente')

<div class="acceso-caja">
    <div class="acceso-tarjeta">

        <div class="acceso-simbolo">@include('layouts.partes.simbolo', ['icono' => 'bi-phone-vibrate'])</div>

        <h1 class="acceso-titulo h3">Verificación en dos pasos</h1>
        <p class="acceso-subtitulo">{{ $identidad->get('nombre') }}</p>

        @if ($errors->any())
            <div class="alert alert-danger acceso-aviso" role="alert">
                <i class="bi bi-exclamation-triangle-fill me-2" aria-hidden="true"></i> {{ $errors->first() }}
            </div>
        @endif

        <p class="acceso-texto">Abre tu app de autenticación (por ejemplo, Google Authenticator o Microsoft Authenticator) y escribe el código de 6 números que ves para esta plataforma.</p>

        <form action="{{ route('login.dos-pasos.verificar') }}" method="POST" novalidate>
            @csrf
            <div class="acceso-campo mb-3">
                <label for="codigo">Código</label>
                <div class="acceso-entrada">
                    <input type="text" id="codigo" name="codigo" inputmode="numeric" autocomplete="one-time-code" maxlength="20" placeholder="123456" required autofocus>
                    <i class="bi bi-shield-lock" aria-hidden="true"></i>
                </div>
            </div>

            <label class="acceso-recordar mb-4">
                <input type="checkbox" name="recordar" value="1">
                <span>Recordar este dispositivo 30 días <small class="d-block text-muted">Solo en tu equipo o teléfono personal; nunca en uno compartido.</small></span>
            </label>

            <button type="submit" class="acceso-boton">
                Entrar <i class="bi bi-arrow-right-short fs-5" aria-hidden="true"></i>
            </button>
        </form>

        <details class="acceso-texto mt-3">
            <summary>¿No tienes tu teléfono?</summary>
            <p class="mt-2 mb-1">Escribe en «Código» uno de tus <strong>códigos de respaldo</strong> (8 letras y números, por ejemplo ABCD-EF23). Cada uno sirve una sola vez.</p>
            <p class="mb-2">Si tampoco los tienes, pide que <strong>restablezcan tu verificación en dos pasos</strong>; después la activas con tu teléfono nuevo.</p>
            {{-- C-36 (E-14): la petición queda a su nombre en los pendientes de quien la atiende (sin terminal) --}}
            <form action="{{ route('login.dos-pasos.ayuda') }}" method="POST">
                @csrf
                <button type="submit" class="btn btn-outline-secondary w-100" style="min-height:44px;border-radius:10px;"><i class="bi bi-person-raised-hand me-1" aria-hidden="true"></i>Perdí mi teléfono: pedir ayuda</button>
            </form>
        </details>

        <form action="{{ route('login.dos-pasos.cancelar') }}" method="POST" class="acceso-enlace-ayuda">
            @csrf
            <button type="submit" class="btn btn-link p-0"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Volver a entrar con otra cuenta</button>
        </form>
    </div>
</div>

<script src="{{ asset('js/plataforma.js') }}?v={{ @filemtime(public_path('js/plataforma.js')) ?: '1' }}"></script>
</body>
</html>
