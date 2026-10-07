@php
    $esInicio = request()->routeIs('panel');
    $menusMovil = collect($menus)->sortBy('orden_movil')->values();
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    @include('layouts.partes.cabeza')
    <title>@yield('titulo', 'Panel') | {{ $identidad->get('nombre_corto') }}</title>
</head>
<body class="app"
      data-inactividad="{{ (int) config('plataforma.sesion.inactividad_minutos') * 60 }}"
      data-aviso="{{ (int) config('plataforma.sesion.aviso_segundos') }}"
      data-latido="{{ route('sesion.latido') }}"
      data-expirada="{{ route('sesion.expirada') }}">

    @include('layouts.partes.ambiente')

    {{-- ===================== Barra superior (PC) ===================== --}}
    <div class="barra-pc-contenedor">
        <nav class="barra-pc" aria-label="Menú principal">
            <div class="barra-pc-menu">
                <a href="{{ route('panel') }}" class="marca">
                    <span class="marca-simbolo">@include('layouts.partes.simbolo')</span>
                    <span class="marca-texto">{{ $identidad->get('nombre_corto') }}</span>
                </a>

                <div class="barra-pc-botones">
                    <a href="{{ route('panel') }}" class="boton-nav {{ $esInicio ? 'activo' : '' }}">
                        <i class="bi {{ $esInicio ? 'bi-grid-1x2-fill' : 'bi-grid-1x2' }}" aria-hidden="true"></i><span>Inicio</span>
                    </a>

                    @foreach ($menus as $menu)
                        <div class="dropdown">
                            <button type="button" class="boton-nav {{ $menu['activo'] ? 'activo' : '' }}" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi {{ $menu['icono'] }}" aria-hidden="true"></i><span>{{ $menu['nombre'] }}</span>
                            </button>
                            <ul class="dropdown-menu menu-desplegable {{ $loop->last ? 'dropdown-menu-end' : '' }}">
                                @foreach ($menu['secciones'] as $seccion => $items)
                                    @unless ($loop->first)<li><hr class="menu-separador"></li>@endunless
                                    @if ($seccion !== '')<li><span class="menu-seccion">{{ $seccion }}</span></li>@endif
                                    @foreach ($items as $item)
                                        <li>
                                            <a class="dropdown-item menu-desplegable-item {{ $item['activo'] ? 'activo' : '' }}" href="{{ $item['url'] }}" @if ($item['activo']) aria-current="page" @endif>
                                                <i class="bi {{ $item['icono'] }} text-{{ $item['color'] }}" aria-hidden="true"></i> {{ $item['nombre'] }}
                                            </a>
                                        </li>
                                    @endforeach
                                @endforeach
                            </ul>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="barra-pc-acciones">
                <button type="button" class="btn-alto-contraste" data-accion="modo-pantalla" title="Modo de pantalla: Normal" aria-label="Modo de pantalla: Normal. Cambiar a Sol">
                    <i class="bi bi-sun" aria-hidden="true"></i>
                </button>

                <div class="bloque-usuario">
                    <div class="avatar" aria-hidden="true">{{ $usuario->iniciales() }}</div>
                    <div class="text-start" style="line-height: 1.2;">
                        <div class="bloque-usuario-nombre">{{ $usuario->name }}</div>
                        <div class="bloque-usuario-rol">{{ $rolNombre }}</div>
                    </div>
                    <form action="{{ route('logout') }}" method="POST" class="m-0">
                        @csrf
                        <button type="submit" class="boton-salir" title="Cerrar sesión" aria-label="Cerrar sesión"><i class="bi bi-power" aria-hidden="true"></i></button>
                    </form>
                </div>
            </div>
        </nav>
    </div>

    {{-- ===================== Cabecera (celular) ===================== --}}
    <header class="cabecera-movil">
        <div class="d-flex align-items-center gap-2">
            @unless ($esInicio)
                <a href="{{ route('panel') }}" class="boton-regresar" aria-label="Regresar al inicio"><i class="bi bi-arrow-left fs-4" aria-hidden="true"></i></a>
            @endunless
            <a href="{{ route('panel') }}" class="marca">
                <span class="marca-simbolo">@include('layouts.partes.simbolo')</span>
                <span class="marca-texto">{{ $identidad->get('nombre_corto') }}</span>
            </a>
        </div>
        <div class="d-flex align-items-center gap-2">
            <button type="button" class="btn-alto-contraste" data-accion="modo-pantalla" title="Modo de pantalla: Normal" aria-label="Modo de pantalla: Normal. Cambiar a Sol">
                <i class="bi bi-sun" aria-hidden="true"></i>
            </button>
            <div class="tarjeta-usuario-movil" title="{{ $usuario->name }} · {{ $rolNombre }}">
                <div class="avatar" aria-hidden="true">{{ $usuario->iniciales() }}</div>
                <span class="visually-hidden">{{ $usuario->name }}, {{ $rolNombre }}</span>
            </div>
        </div>
    </header>

    {{-- ===================== Contenido ===================== --}}
    <main class="contenido">
        @yield('contenido')
    </main>

    {{-- ===================== Pie (PC) ===================== --}}
    <footer class="pie-pc">
        <span>&copy; {{ date('Y') }} {{ $identidad->get('titular') }} {{ $identidad->get('nombre_corto') }}. Panel Operativo Autorizado.</span>
        <span><i class="bi bi-shield-shaded opacity-50" aria-hidden="true"></i> Capa de Seguridad Activa SSL</span>
    </footer>

    {{-- ===================== Barra inferior (celular) ===================== --}}
    <nav class="barra-inferior" aria-label="Accesos rápidos">
        <a href="{{ route('panel') }}" class="barra-inferior-item {{ $esInicio ? 'activo' : '' }}">
            <i class="bi {{ $esInicio ? 'bi-house-door-fill' : 'bi-house-door' }}" aria-hidden="true"></i><span>Inicio</span>
        </a>
        @foreach ($atajos as $atajo)
            <a href="{{ $atajo['url'] }}" class="barra-inferior-item {{ $atajo['activo'] ? 'activo' : '' }}">
                <i class="bi {{ $atajo['icono'] }}" aria-hidden="true"></i><span>{{ $atajo['nombre'] }}</span>
            </a>
        @endforeach
        <button type="button" class="barra-inferior-item" data-bs-toggle="offcanvas" data-bs-target="#menuLateral" aria-controls="menuLateral">
            <i class="bi bi-list" aria-hidden="true"></i><span>Menú</span>
        </button>
    </nav>

    {{-- ===================== Menú lateral (celular) ===================== --}}
    <div class="offcanvas offcanvas-end menu-lateral" tabindex="-1" id="menuLateral" aria-labelledby="menuLateralTitulo">
        <div class="offcanvas-header bg-light border-bottom">
            <h2 class="offcanvas-title h6 fw-bold text-dark" id="menuLateralTitulo"><i class="bi bi-grid-fill me-2 text-primary" aria-hidden="true"></i>Menú Principal</h2>
            <button type="button" class="btn-close" data-bs-dismiss="offcanvas" aria-label="Cerrar"></button>
        </div>
        <div class="offcanvas-body p-3">
            @foreach ($menusMovil as $menu)
                {{-- Todos los grupos inician cerrados; el de la pantalla actual se resalta --}}
                <div class="menu-lateral-grupo contraible {{ $loop->first ? '' : 'mt-3' }} {{ $menu['activo'] ? 'actual' : '' }}" data-bs-toggle="collapse" data-bs-target="#grupo-{{ $menu['clave'] }}" aria-expanded="false" aria-controls="grupo-{{ $menu['clave'] }}" role="button">
                    <span>{{ $menu['nombre'] }}@if ($menu['activo'])<span class="visually-hidden"> (pantalla actual)</span>@endif</span><i class="bi bi-chevron-down" aria-hidden="true"></i>
                </div>
                <div class="collapse" id="grupo-{{ $menu['clave'] }}">
                    @foreach ($menu['secciones'] as $items)
                        @foreach ($items as $item)
                            <a href="{{ $item['url'] }}" class="menu-lateral-item {{ $item['activo'] ? 'activo' : '' }}"><i class="bi {{ $item['icono'] }} text-{{ $item['color'] }}" aria-hidden="true"></i> {{ $item['nombre'] }}</a>
                        @endforeach
                    @endforeach
                </div>
            @endforeach

            <div class="menu-lateral-grupo contraible mt-4" data-bs-toggle="collapse" data-bs-target="#grupo-pantalla" aria-expanded="false" role="button">
                <span>Pantalla</span><i class="bi bi-chevron-down" aria-hidden="true"></i>
            </div>
            <div class="collapse" id="grupo-pantalla">
                <button type="button" class="menu-lateral-item menu-lateral-modo" data-accion="modo-pantalla" title="Modo de pantalla: Normal" aria-label="Modo de pantalla: Normal. Cambiar a Sol">
                    <i class="bi bi-sun" aria-hidden="true"></i> <span data-modo-etiqueta>Modo de pantalla: Normal</span>
                </button>
            </div>

            <div class="menu-lateral-grupo mt-4">Sesión</div>
            <form action="{{ route('logout') }}" method="POST" class="m-0">
                @csrf
                <button type="submit" class="menu-lateral-item menu-lateral-salir"><i class="bi bi-power" aria-hidden="true"></i> Cerrar Sesión</button>
            </form>
        </div>
    </div>

    {{-- ===================== Aviso de sesión por expirar ===================== --}}
    <div class="modal fade modal-sesion" id="modalSesionPorExpirar" data-bs-backdrop="static" data-bs-keyboard="false" tabindex="-1" aria-labelledby="tituloSesion">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-body text-center p-4">
                    <i class="bi bi-clock-history text-warning" style="font-size: 2.5rem;" aria-hidden="true"></i>
                    <h2 class="h5 fw-bold mt-3 mb-2" id="tituloSesion">Tu sesión está por cerrarse</h2>
                    <p class="text-muted small mb-2">Por seguridad, se cierra sola tras {{ (int) config('plataforma.sesion.inactividad_minutos') }} minutos sin actividad. ¿Sigues aquí?</p>
                    {{-- Ronda 5: cuenta regresiva (la llena plataforma.js) --}}
                    <p class="sesion-cuenta mb-4" aria-live="polite">Se cerrará en <strong data-sesion-cuenta>2:00</strong></p>
                    <button type="button" class="btn btn-dark w-100" data-accion="seguir-en-sesion">Seguir conectado</button>
                </div>
            </div>
        </div>
    </div>

    <script src="{{ asset('vendor/bootstrap/bootstrap.bundle.min.js') }}"></script>
    <script src="{{ asset('js/plataforma.js') }}?v={{ @filemtime(public_path('js/plataforma.js')) ?: '1' }}"></script>
    @stack('scripts')
</body>
</html>
