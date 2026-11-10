{{-- Si un plan o paquete se ve en la página pública ($r) --}}
@if (! $r->activo)
    <span class="etiqueta-estado inactivo text-nowrap">DE BAJA</span>
@elseif ($r->publicado)
    <span class="etiqueta-estado activo text-nowrap">Se muestra</span>
@else
    <span class="etiqueta-estado pendiente text-nowrap">Oculto</span>
@endif
