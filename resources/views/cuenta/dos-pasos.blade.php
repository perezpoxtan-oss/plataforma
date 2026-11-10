@extends('layouts.app')

@section('titulo', 'Verificación en dos pasos')

@section('contenido')
{{-- Mi cuenta → Verificación en dos pasos (docs/tecnico/dos-pasos.md). Sin permiso propio: la cuenta es de su dueño. --}}
<div class="tema-azul">
    @include('administracion.partes.avisos')

    <div class="encabezado-pantalla mb-4">
        <div class="icono"><i class="bi bi-phone-vibrate text-primary" aria-hidden="true"></i></div>
        <div><h1>Verificación en dos pasos</h1><p>Además de tu contraseña, al entrar te pediremos un código que cambia cada 30 segundos en tu teléfono. Así, aunque alguien conozca tu contraseña, no puede entrar.</p></div>
    </div>

    @if ($activa && $codigos)
        <section class="tarjeta p-4" id="codigos-respaldo" aria-labelledby="t-codigos">
            <h2 id="t-codigos" class="h5 fw-bold mb-1"><i class="bi bi-key me-2 text-primary" aria-hidden="true"></i>Tus códigos de respaldo</h2>
            <p class="small text-muted mb-3">Te sirven para entrar si pierdes o cambias tu teléfono. <strong>Cada código sirve una sola vez.</strong> Guárdalos en un lugar seguro (impresos o en tu gestor de contraseñas): <strong>es la única vez que los verás.</strong></p>
            <ul class="codigos-respaldo mb-3">
                @foreach ($codigos as $codigo)
                    <li>{{ $codigo }}</li>
                @endforeach
            </ul>
            <div class="d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-outline-secondary" data-accion="imprimir" style="min-height:44px;border-radius:10px;"><i class="bi bi-printer me-1" aria-hidden="true"></i>Imprimir</button>
                <a href="{{ route('panel') }}" class="btn-azul d-inline-flex align-items-center" style="min-height:44px;border-radius:10px;padding:0 1.25rem;text-decoration:none;"><i class="bi bi-house me-1" aria-hidden="true"></i>Ya los guardé: volver al inicio</a>
            </div>
        </section>
    @else
        @if ($obligatoria)
            <div class="tarjeta p-4 mb-4 cuenta-primer-acceso" role="alert">
                <h2 class="h6 fw-bold mb-1"><i class="bi bi-exclamation-triangle-fill text-warning me-2" aria-hidden="true"></i>Tu cuenta necesita la verificación en dos pasos</h2>
                <p class="small text-muted m-0">Actívala para seguir usando la plataforma. Toma un minuto: solo necesitas tu teléfono.</p>
            </div>
        @endif

        <section class="tarjeta p-4" aria-labelledby="t-activar">
            <h2 id="t-activar" class="h5 fw-bold mb-3"><i class="bi bi-shield-lock me-2 text-primary" aria-hidden="true"></i>Actívala en tres pasos</h2>
            <ol class="dos-pasos-pasos">
                <li>
                    <strong>Instala una app de autenticación</strong> en tu teléfono, si aún no tienes una: <em>Google Authenticator</em> o <em>Microsoft Authenticator</em> (gratis en la tienda de tu teléfono).
                </li>
                <li>
                    <strong>Agrega tu cuenta:</strong> en la app toca <strong>+</strong> y <strong>Escanear código QR</strong>, y apunta la cámara a este código.
                    <div class="row g-3 align-items-center mt-1">
                        <div class="col-auto"><div class="dos-pasos-qr" role="img" aria-label="Código QR para agregar tu cuenta a la app">{!! $qr !!}</div></div>
                        <div class="col">
                            <p class="small mb-1">¿Estás en el mismo teléfono o no puedes escanear? Elige <strong>Ingresar una clave de configuración</strong> en la app y escribe esta clave (tipo «Basada en el tiempo»):</p>
                            <p class="mb-2"><span class="dos-pasos-clave" id="clave-dos-pasos">{{ $clave }}</span></p>
                            @if (\App\Support\AppMovil::es() || request()->header('Sec-CH-UA-Mobile') === '?1')
                                <a href="{{ $uri }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Abrir en mi app de autenticación</a>
                            @endif
                        </div>
                    </div>
                </li>
                <li>
                    <strong>Escribe el código de 6 números</strong> que te muestra la app para confirmar:
                    <form action="{{ route('mi-cuenta.dos-pasos.activar') }}" method="POST" autocomplete="off" class="mt-2" style="max-width:20rem;">
                        @csrf
                        <label class="campo-etiqueta" for="codigo">Código de la app</label>
                        <input type="text" id="codigo" name="codigo" class="campo @error('codigo') is-invalid @enderror" inputmode="numeric" autocomplete="one-time-code" maxlength="12" placeholder="123456" required>
                        @error('codigo')<div class="invalid-feedback d-block">{{ $message }}</div>@enderror
                        <button type="submit" class="btn-azul mt-2" style="min-height:44px;border-radius:10px;padding:0 1.25rem;"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Guardar</button>
                    </form>
                </li>
            </ol>
            <p class="small text-muted mb-0"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Al guardar verás 10 códigos de respaldo para entrar si pierdes tu teléfono. Si cambias de teléfono sin ellos, pide a tu administrador que restablezca tu verificación.</p>
        </section>

        @unless ($obligatoria)
            <p class="mt-3"><a href="{{ route('mi-cuenta.index') }}"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver a Mi cuenta</a></p>
        @endunless
    @endif
</div>
@endsection
