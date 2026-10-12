{{--
    Ruta de navegación (ADR-0016): Zonas y edificios › Sede › Zona › Piso › Habitación,
    con el nombre del nivel en cada paso para no perderse. En el celular se
    desliza de lado y siempre muestra el paso actual.
--}}
<nav class="migas migas-espacios" aria-label="Ubicación">
    <a href="{{ route('espacios.index') }}"><i class="bi bi-geo-alt-fill" aria-hidden="true"></i> Zonas y edificios</a>
    @if ($nodo->sede ?? null)
        <i class="bi bi-chevron-right" aria-hidden="true"></i>
        <span class="miga-sede"><small>{{ $empresa->rubro?->terminologia['sede'] ?? 'Sede' }}</small>{{ $nodo->sede->nombre }}</span>
    @endif
    @foreach ($migas as $miga)
        <i class="bi bi-chevron-right" aria-hidden="true"></i>
        <a href="{{ route('espacios.show', $miga->id) }}"><small>{{ $etiquetas[$miga->nivel]['singular'] ?? '' }}</small>{{ $miga->nombre }}</a>
    @endforeach
    <i class="bi bi-chevron-right" aria-hidden="true"></i>
    <span class="actual" aria-current="page"><small>{{ $etiquetas[$nodo->nivel]['singular'] ?? '' }}</small>{{ $nodo->nombre }}</span>
</nav>
