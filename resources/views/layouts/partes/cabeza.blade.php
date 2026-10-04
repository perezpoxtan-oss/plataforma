{{-- Encabezado HTML común: metadatos, recursos propios y colores de marca --}}
@php
    $version = fn (string $archivo) => asset($archivo).'?v='.(@filemtime(public_path($archivo)) ?: '1');
@endphp
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="csrf-token" content="{{ csrf_token() }}">
@unless (app()->isProduction())<meta name="robots" content="noindex, nofollow">@endunless
<meta name="theme-color" content="{{ $identidad->get('color_primario') }}">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-title" content="{{ $identidad->get('nombre_corto') }}">
@if ($identidad->get('favicon'))
    <link rel="icon" href="{{ asset($identidad->get('favicon')) }}">
@endif
<link href="{{ $version('vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
<link href="{{ $version('vendor/bootstrap-icons/bootstrap-icons.min.css') }}" rel="stylesheet">
<link href="{{ $version('css/plataforma.css') }}" rel="stylesheet">
<style>:root { --color-primario: {{ $identidad->get('color_primario') }}; --color-acento: {{ $identidad->get('color_acento') }}; }</style>
{{-- Modos de pantalla (Normal, Sol, Noche): la clase se pone en <html> antes de pintar --}}
<link href="{{ $version('css/modos-pantalla.css') }}" rel="stylesheet">
<script src="{{ $version('js/modo-pantalla.js') }}"></script>
@stack('estilos')
