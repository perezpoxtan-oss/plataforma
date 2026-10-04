{{-- Ruta de navegación: Zonas / Edificio / Piso / Habitación --}}
<nav class="migas" aria-label="Ubicación">
    <a href="{{ route('espacios.index') }}"><i class="bi bi-geo-alt-fill" aria-hidden="true" style="font-size:.85rem;color:#ef4444"></i> {{ $etiquetas['edificio']['plural'] }}</a>
    @foreach ($migas as $miga)
        <i class="bi bi-chevron-right" aria-hidden="true"></i>
        <a href="{{ route('espacios.show', $miga->id) }}">{{ $miga->nombre }}</a>
    @endforeach
    <i class="bi bi-chevron-right" aria-hidden="true"></i>
    <span class="actual" aria-current="page">{{ $nodo->nombre }}</span>
</nav>
