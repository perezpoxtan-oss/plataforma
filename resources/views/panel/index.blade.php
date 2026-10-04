@extends('layouts.app')

@section('titulo', 'Panel General')

@section('contenido')
<div class="container p-0" style="max-width: 1200px;">
    <div class="tarjeta p-4 mb-2">
        <h1 class="h4 fw-bold mb-1"><i class="bi bi-speedometer2 text-primary me-2" aria-hidden="true"></i>Consola de Monitoreo Central</h1>
        <p class="text-muted small m-0">
            Panorama operativo de {{ auth()->user()->es_superadmin ? 'todo el sistema' : 'tu empresa' }} — toca una ficha para ver el detalle.
        </p>
    </div>

    {{-- Las fichas (colaboradores, personas en sitio, llaves en uso, transporte,
         novedades, equipos) se agregan aquí conforme se migra cada módulo. --}}
    <div class="tarjeta estado-vacio mt-4">
        <div class="icono"><i class="bi bi-hourglass-split" aria-hidden="true"></i></div>
        <h2 class="h6 fw-bold mb-1">Las fichas de monitoreo aparecerán aquí</h2>
        <p class="text-muted small m-0">Se irán mostrando conforme se migre cada módulo de la operación.</p>
    </div>
</div>
@endsection
