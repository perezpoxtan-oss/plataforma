{{-- Página pública de planes (Catálogo comercial): sin menú de la plataforma ni datos de empresas.
     $ajustes (comerciales), $vistaPrevia (true si aún no está publicada: solo la ve el Super Administrador) --}}
@php
    $version = fn (string $archivo) => asset($archivo).'?v='.(@filemtime(public_path($archivo)) ?: '1');
    $indexar = ! ($vistaPrevia ?? false) && app()->isProduction() && ! $__env->hasSection('sin-indexar');
    $conSesion = auth()->check();
    // En las páginas que tienen las secciones, los enlaces del menú son anclas locales
    $base = in_array(request()->route()?->getName(), ['planes.index', 'panel', 'catalogo-comercial.vista-previa'], true) ? '' : route('planes.index');
@endphp
<!DOCTYPE html>
<html lang="es" class="sin-js">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @unless ($indexar)
        <meta name="robots" content="noindex, nofollow, noarchive">
    @endunless
    <meta name="description" content="{{ \Illuminate\Support\Str::limit($ajustes['portada_subtitulo'] ?: $identidad->get('eslogan'), 155) }}">
    <meta name="theme-color" content="{{ $identidad->get('color_primario') }}">
    <title>@yield('titulo', 'Planes') | {{ $identidad->get('nombre') }}</title>
    @if ($identidad->get('favicon'))
        <link rel="icon" href="{{ asset($identidad->get('favicon')) }}">
    @endif
    <link rel="preload" href="{{ asset('vendor/plus-jakarta-sans/PlusJakartaSans-variable.woff2') }}" as="font" type="font/woff2" crossorigin>
    <link href="{{ $version('vendor/bootstrap-icons/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ $version('css/landing.css') }}" rel="stylesheet">
    <style>:root { --color-primario: {{ $identidad->get('color_primario') }}; --color-acento: {{ $identidad->get('color_acento') }}; }</style>
</head>
<body class="landing">
    <a href="#contenido" class="lp-saltar">Saltar al contenido</a>

    @if ($vistaPrevia ?? false)
        <div class="lp-franja" role="note"><i class="bi bi-eye" aria-hidden="true"></i> Vista previa: esta página todavía no está publicada; solo tú la ves.
            @auth <a href="{{ route('catalogo-comercial.index', ['pestana' => 'ajustes']) }}">Publicarla en Ajustes</a>@endauth
        </div>
    @elseif (! app()->isProduction())
        <div class="lp-franja" role="note">{{ app()->environment('qa') ? 'Ambiente de pruebas (QA)' : 'Desarrollo' }}: los datos son ficticios.</div>
    @endif

    <header class="lp-cabecera">
        <div class="lp-envoltura lp-cabecera-fila">
            <a href="{{ $conSesion ? route('planes.index') : url('/') }}" class="lp-marca" aria-label="{{ $identidad->get('nombre') }}: inicio">
                <span class="lp-marca-simbolo">@include('layouts.partes.simbolo', ['icono' => 'bi-shield-lock-fill'])</span>
                <span class="lp-marca-texto">
                    <span class="lp-marca-nombre">{{ $identidad->get('nombre') }}</span>
                    @if ($identidad->get('eslogan'))<span class="lp-marca-eslogan">{{ $identidad->get('eslogan') }}</span>@endif
                </span>
            </a>
            <nav id="lp-menu" class="lp-navegacion" aria-label="Secciones">
                <a href="{{ $base }}#modulos">Módulos</a>
                <a href="{{ $base }}#planes">Planes</a>
                <a href="{{ $base }}#calculadora">Calculadora</a>
                <a href="{{ $base }}#preguntas">Preguntas</a>
            </nav>
            <div class="lp-cabecera-acciones">
                <a href="{{ $conSesion ? route('panel') : route('login') }}" class="lp-boton lp-boton-borde" aria-label="{{ $conSesion ? 'Ir a la plataforma' : 'Entrar a la plataforma' }}">
                    <i class="bi bi-box-arrow-in-right" aria-hidden="true"></i><span class="lp-entrar-texto">{{ $conSesion ? 'Ir a la plataforma' : 'Entrar' }}</span>
                </a>
                <a href="{{ route('planes.contratar') }}" class="lp-boton lp-boton-principal lp-contratar-cabecera">Contratar</a>
                <button type="button" class="lp-menu-boton" data-menu-boton aria-controls="lp-menu" aria-expanded="false" aria-label="Abrir el menú de secciones"><i class="bi bi-list" aria-hidden="true"></i></button>
            </div>
        </div>
    </header>

    <main id="contenido">
        @yield('contenido')
    </main>

    <footer class="lp-pie">
        <div class="lp-envoltura lp-pie-fila">
            <div class="lp-pie-legal">
                @if ($ajustes['texto_legal'])<p>{{ $ajustes['texto_legal'] }}</p>@endif
                <p>&copy; {{ date('Y') }} {{ $identidad->get('titular') ?: $identidad->get('nombre') }}</p>
            </div>
            <div>
                @if (trim((string) $ajustes['aviso_privacidad']) !== '')
                    <a href="{{ route('planes.privacidad') }}">Aviso de privacidad</a>
                @endif
                @if ($identidad->get('correo_soporte'))
                    <p><a href="mailto:{{ $identidad->get('correo_soporte') }}">{{ $identidad->get('correo_soporte') }}</a></p>
                @endif
                @if ($identidad->get('telefono_soporte'))
                    <p>{{ $identidad->get('telefono_soporte') }}</p>
                @endif
            </div>
        </div>
    </footer>

    <script src="{{ $version('js/landing-calculo.js') }}"></script>
    <script src="{{ $version('js/landing.js') }}"></script>
</body>
</html>
