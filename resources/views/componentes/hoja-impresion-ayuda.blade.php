{{--
    Mejoras padrones (ADR-0015), en las hojas de impresión (no sale en papel):
      - Recordatorio visible de imprimir a «Tamaño real / 100 %» (la causa más
        común de etiquetas mal medidas).
      - Con $selEtiquetas (["llave-12", …]) y si la persona puede usar
        Etiquetas QR: «Imprimir con una plantilla», el mismo camino de
        Etiquetas QR (otra medida, historial y reimpresión).
--}}
<div class="recordatorio-escala no-imprimir" role="note">
    <i class="bi bi-rulers" aria-hidden="true"></i>
    <span><strong>Antes de imprimir:</strong> en la ventana de la impresora elige <strong>«Tamaño real»</strong> o <strong>«Escala 100 %»</strong> (no «Ajustar a la página») y sin márgenes. Si no, las etiquetas salen más chicas y el lector no las lee bien.</span>
</div>
@if (! empty($selEtiquetas) && auth()->user()?->can('etiquetas_qr.ver'))
    <form action="{{ route('etiquetas.imprimir') }}" method="POST" class="imprimir-con-plantilla no-imprimir">
        @csrf
        @foreach ($selEtiquetas as $clave)
            <input type="hidden" name="sel[]" value="{{ $clave }}">
        @endforeach
        <button type="submit" class="btn-cerrar-calcomania"><i class="bi bi-qr-code me-1" aria-hidden="true"></i>Imprimir con una plantilla de Etiquetas QR</button>
    </form>
@endif
