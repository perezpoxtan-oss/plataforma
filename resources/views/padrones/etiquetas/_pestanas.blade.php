{{-- Ronda 7: pestañas del gestor de impresión QR (Imprimir · Historial · Plantillas) --}}
<nav class="pestanas-etiquetas-qr" aria-label="Secciones de Etiquetas QR">
    <a href="{{ route('etiquetas.index') }}" class="{{ $activa === 'imprimir' ? 'activa' : '' }}" @if ($activa === 'imprimir') aria-current="page" @endif>
        <i class="bi bi-printer" aria-hidden="true"></i> Imprimir
    </a>
    <a href="{{ route('etiquetas.historial') }}" class="{{ $activa === 'historial' ? 'activa' : '' }}" @if ($activa === 'historial') aria-current="page" @endif>
        <i class="bi bi-clock-history" aria-hidden="true"></i> Historial
    </a>
    @if ($puedeConfigurar ?? false)
        <a href="{{ route('etiquetas.plantillas') }}" class="{{ $activa === 'plantillas' ? 'activa' : '' }}" @if ($activa === 'plantillas') aria-current="page" @endif>
            <i class="bi bi-rulers" aria-hidden="true"></i> Plantillas
        </a>
    @endif
</nav>
