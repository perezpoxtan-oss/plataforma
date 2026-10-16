{{--
    Encabezado y pestañas de Reservas de áreas, con el componente de pestañas
    de la plataforma (.pestanas, como Zonas y edificios). Parámetros: $titulo,
    $descripcion, $icono, $puede (crear, ver, aprobar, configurar, exportar),
    $actual (clave de la pestaña) y, opcional, $buscar = [clave de fichas, texto de ayuda].
--}}
@push('estilos')
    <link href="{{ asset('css/reservas.css') }}?v={{ @filemtime(public_path('css/reservas.css')) ?: '1' }}" rel="stylesheet">
@endpush
@php
    $pend = app(\App\Support\Menu\MisPendientes::class)->para(auth()->user());
    $porClave = collect($pend['items'] ?? [])->keyBy('clave');
    $pestanas = array_filter([
        'areas' => ($puede['ver'] || $puede['crear']) ? ['Áreas', 'bi-door-open', route('reservas.index'), 0] : null,
        'calendario' => ($puede['ver'] || $puede['crear']) ? ['Calendario', 'bi-calendar3', route('reservas.calendario'), 0] : null,
        'mias' => $puede['crear'] ? ['Mis reservas', 'bi-person-check', route('reservas.mias'), (int) ($porClave['reservas_cambio']['total'] ?? 0)] : null,
        'aprobaciones' => ['Por aprobar', 'bi-patch-check', route('reservas.aprobaciones'), (int) ($porClave['reservas_aprobar']['total'] ?? 0)],
        'ordenes' => ['Órdenes de servicio', 'bi-clipboard-check', route('reservas.ordenes'), (int) ($porClave['reservas_ordenes']['total'] ?? 0)],
        'informes' => $puede['ver'] ? ['Informes', 'bi-bar-chart', route('reservas.informes'), 0] : null,
        'configuracion' => $puede['configurar'] ? ['Configuración', 'bi-gear', route('reservas.configuracion'), 0] : null,
    ]);
@endphp
<div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
    <div class="encabezado-pantalla m-0">
        <div class="icono"><i class="bi {{ $icono ?? 'bi-calendar-check' }} text-success" aria-hidden="true"></i></div>
        <div>
            <h1>{{ $titulo }}</h1>
            <p>{{ $descripcion }}</p>
        </div>
    </div>
    @isset($buscar)
        <div class="barra-filtros justify-content-md-end">
            <div class="buscador">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="search" placeholder="{{ $buscar[1] }}" aria-label="{{ $buscar[1] }}" data-filtro-texto="{{ $buscar[0] }}">
            </div>
        </div>
    @endisset
</div>
<nav class="pestanas" aria-label="Secciones de Reservas de áreas">
    @foreach ($pestanas as $clave => [$nombre, $ico, $url, $conteo])
        <a href="{{ $url }}" class="btn {{ $actual === $clave ? 'btn-dark' : 'btn-outline-dark' }}" @if ($actual === $clave) aria-current="page" @endif>
            <i class="bi {{ $ico }} me-1" aria-hidden="true"></i>{{ $nombre }}@if ($conteo > 0) <span class="badge bg-danger ms-1">{{ $conteo }}<span class="visually-hidden"> pendientes</span></span>@endif
        </a>
    @endforeach
</nav>
