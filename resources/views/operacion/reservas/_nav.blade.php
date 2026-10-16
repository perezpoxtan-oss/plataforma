{{--
    Encabezado y pestañas de Reservas de áreas. Parámetros: $titulo, $descripcion,
    $icono, $puede (crear, ver, aprobar, configurar, exportar), $actual (clave de la pestaña).
--}}
@push('estilos')
    <link href="{{ asset('css/reservas.css') }}?v={{ @filemtime(public_path('css/reservas.css')) ?: '1' }}" rel="stylesheet">
@endpush
@php
    $pend = app(\App\Support\Menu\MisPendientes::class)->para(auth()->user());
    $porClave = collect($pend['items'] ?? [])->keyBy('clave');
    $pestanas = array_filter([
        'calendario' => ($puede['ver'] || $puede['crear']) ? ['Calendario', 'bi-calendar3', route('reservas.index'), 0] : null,
        'mias' => $puede['crear'] ? ['Mis reservas', 'bi-person-check', route('reservas.mias'), (int) ($porClave['reservas_cambio']['total'] ?? 0)] : null,
        'aprobaciones' => ['Por aprobar', 'bi-patch-check', route('reservas.aprobaciones'), (int) ($porClave['reservas_aprobar']['total'] ?? 0)],
        'ordenes' => ['Órdenes de servicio', 'bi-clipboard-check', route('reservas.ordenes'), (int) ($porClave['reservas_ordenes']['total'] ?? 0)],
        'informes' => $puede['ver'] ? ['Informes', 'bi-bar-chart', route('reservas.informes'), 0] : null,
        'configuracion' => $puede['configurar'] ? ['Configuración', 'bi-gear', route('reservas.configuracion'), 0] : null,
    ]);
@endphp
<div class="res-encabezado">
    <div class="encabezado-pantalla m-0">
        <div class="icono"><i class="bi {{ $icono ?? 'bi-calendar-check' }} text-success" aria-hidden="true"></i></div>
        <div>
            <h1>{{ $titulo }}</h1>
            <p>{{ $descripcion }}</p>
        </div>
    </div>
    @if ($puede['crear'])
        <a href="{{ route('reservas.create') }}" class="res-btn-nueva"><i class="bi bi-calendar-plus" aria-hidden="true"></i>Nueva reserva</a>
    @endif
</div>
<nav class="res-nav" aria-label="Secciones de Reservas de áreas">
    @foreach ($pestanas as $clave => [$nombre, $ico, $url, $conteo])
        <a href="{{ $url }}" class="{{ $actual === $clave ? 'activa' : '' }}" @if ($actual === $clave) aria-current="page" @endif>
            <i class="bi {{ $ico }}" aria-hidden="true"></i>{{ $nombre }}@if ($conteo > 0)<span class="res-conteo" aria-label="{{ $conteo }} pendientes">{{ $conteo }}</span>@endif
        </a>
    @endforeach
</nav>
