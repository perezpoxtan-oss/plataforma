@extends('layouts.app')

@section('titulo', 'App para tu celular')

@section('contenido')
{{-- Mi cuenta → App para tu celular (docs/tecnico/app-movil.md). Sin permiso propio: la app es de la plataforma. --}}
<div class="tema-azul">
    @include('administracion.partes.avisos')

    <div class="encabezado-pantalla mb-4">
        <div class="icono"><i class="bi bi-phone text-primary" aria-hidden="true"></i></div>
        <div><h1>App para tu celular</h1><p>Instala la app en tu celular Android y entra con tu mismo usuario y contraseña.</p></div>
    </div>

    @if (! $apk)
        <section class="tarjeta p-4" aria-labelledby="t-sin-app">
            <h2 id="t-sin-app" class="h5 fw-bold mb-1"><i class="bi bi-hourglass-split me-2 text-primary" aria-hidden="true"></i>La app todavía no está disponible en esta versión</h2>
            <p class="small text-muted mb-0">Cuando esté lista aparecerá aquí, con su botón para descargarla. Mientras tanto puedes abrir la plataforma desde el navegador de tu celular.</p>
        </section>
    @else
        <div class="row g-4">
            <div class="col-lg-7">
                <section class="tarjeta p-4 h-100" aria-labelledby="t-app">
                    <h2 id="t-app" class="h5 fw-bold mb-3"><i class="bi bi-android2 me-2 text-primary" aria-hidden="true"></i>Android</h2>
                    <dl class="mi-cuenta-datos">
                        <div><dt>Versión</dt><dd>{{ $apk['version'] }}</dd></div>
                        @if ($apk['fecha'])
                            <div><dt>Fecha</dt><dd>@fecha($apk['fecha'])</dd></div>
                        @endif
                        <div><dt>Tamaño</dt><dd>{{ number_format($apk['tamano'] / 1048576, 1) }} MB</dd></div>
                    </dl>
                    @if ($enApp)
                        <p class="small text-muted mt-3 mb-2">Ya estás en la app. Si tu versión es anterior, descárgala otra vez y toca <strong>Actualizar</strong>: se instala encima y no pierdes tu sesión.</p>
                    @endif
                    <a href="{{ route('mi-cuenta.app.descargar') }}" class="btn-azul d-inline-flex align-items-center mt-3" style="min-height:44px;border-radius:10px;padding:0 1.25rem;text-decoration:none;"><i class="bi bi-download me-2" aria-hidden="true"></i>Descargar app</a>

                    <h3 class="h6 fw-bold mt-4">Cómo instalarla</h3>
                    <ol class="small mb-0 ps-3">
                        <li>Toca <strong>Descargar app</strong> en tu celular, o escanea el código con su cámara.</li>
                        <li>Abre el archivo descargado (en el aviso de la descarga o en <strong>Descargas</strong>).</li>
                        <li>Si el celular lo pide, toca <strong>Configuración</strong>, activa <strong>Permitir de esta fuente</strong> y regresa.</li>
                        <li>Toca <strong>Instalar</strong> y abre <strong>Smart Centinel</strong>.</li>
                        <li>Para actualizar, descarga la versión nueva y toca <strong>Actualizar</strong>: se instala encima.</li>
                    </ol>
                </section>
            </div>
            <div class="col-lg-5">
                <section class="tarjeta p-4 h-100 text-center" aria-labelledby="t-qr">
                    <h2 id="t-qr" class="h5 fw-bold mb-1"><i class="bi bi-qr-code me-2 text-primary" aria-hidden="true"></i>Escanéalo con tu celular</h2>
                    <p class="small text-muted mb-3">Abre la cámara y apunta al código: la descarga empieza sin entrar. El código sirve {{ $minutos }} minutos; después, vuelve a abrir esta pantalla.</p>
                    <div class="dos-pasos-qr" role="img" aria-label="Código QR para descargar la app en tu celular">{!! $qr !!}</div>
                </section>
            </div>
        </div>
        <p class="small text-muted mt-3 mb-0"><i class="bi bi-shield-check me-1" aria-hidden="true"></i>Huella del archivo (SHA-256): <code class="text-break">{{ $apk['sha256'] }}</code></p>
    @endif

    <section class="tarjeta p-4 mt-4" aria-labelledby="t-ios">
        <h2 id="t-ios" class="h6 fw-bold mb-1"><i class="bi bi-apple me-2 text-primary" aria-hidden="true"></i>iPhone: próximamente</h2>
        <p class="small text-muted mb-0">La app para iPhone llegará después. Mientras tanto, abre la plataforma en Safari, toca <strong>Compartir</strong> y luego <strong>Agregar a inicio</strong>: queda como un ícono más.</p>
    </section>

    @if ($historial !== null)
        <section class="tarjeta p-4 mt-4" aria-labelledby="t-historial">
            <h2 id="t-historial" class="h6 fw-bold mb-1"><i class="bi bi-clock-history me-2 text-primary" aria-hidden="true"></i>Historial de la app (Super Administrador)</h2>
            <p class="small text-muted mb-3">Versiones de la app que se instalaron en este servidor, de la más reciente a la más antigua. La primera es la que se descarga hoy.</p>
            @if ($historial === [])
                <p class="small text-muted mb-0">Todavía no se ha instalado ninguna.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm align-middle small mb-0">
                        <thead><tr><th>Versión</th><th>Código</th><th>Compilada</th><th>Instalada</th><th>Tamaño</th><th>SHA-256</th></tr></thead>
                        <tbody>
                            @foreach ($historial as $h)
                                <tr @class(['fw-bold' => $apk && $h['sha256'] === $apk['sha256']])>
                                    <td>{{ $h['version'] }}</td>
                                    <td>{{ $h['version_code'] ?? '—' }}</td>
                                    <td>@if ($h['fecha']) @fecha($h['fecha']) @else — @endif</td>
                                    <td>@if ($h['instalado_en']) @fecha($h['instalado_en']) @else — @endif</td>
                                    <td>{{ number_format($h['tamano'] / 1048576, 1) }} MB</td>
                                    <td><code class="text-break">{{ $h['sha256'] }}</code></td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif

    <p class="mt-3"><a href="{{ route('mi-cuenta.index') }}"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver a Mi cuenta</a></p>
</div>
@endsection
