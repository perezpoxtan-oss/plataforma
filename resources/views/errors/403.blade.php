{{-- C-03: «No viene en tu plan» (el módulo no está contratado) se distingue de «Tu rol no tiene permiso» --}}
@php
    // Sin base de datos la página igual se pinta (como las demás de error)
    try { $plan = $plan ?? \App\Support\NoVieneEnTuPlan::datos(request()); } catch (\Throwable) { $plan = null; }
@endphp
@if ($plan)
@include('errors.plantilla', ['codigo' => 403, 'icono' => 'bi-box-seam', 'color' => '#d97706', 'titulo' => $plan['titulo'], 'mensaje' => $plan['mensaje'], 'accion' => 'inicio', 'urlExtra' => $plan['url'], 'botonExtra' => $plan['boton']])
@else
@include('errors.plantilla', ['codigo' => 403, 'icono' => 'bi-shield-lock', 'color' => '#dc2626', 'titulo' => 'No tienes permiso para ver esto', 'mensaje' => 'Tu rol no tiene acceso a esta pantalla o a este registro. Si lo necesitas para tu trabajo, pide a tu administrador que revise tu rol en la Matriz de permisos.', 'accion' => 'inicio'])
@endif
