{{--
    Recuadro de firma autógrafa (dedo, lápiz o mouse). Envía la firma como
    imagen en el campo oculto `nombre`; el servidor la guarda con
    App\Services\Firmas\Firmas. Ver docs/tecnico/firmas.md.

    Parámetros: id (único), nombre (name del campo), etiqueta, requerido (bool), ayuda (opcional)
--}}
<div class="firma" data-firma>
    <div class="d-flex justify-content-between align-items-end gap-2">
        <span class="campo-etiqueta" id="{{ $id }}_etiqueta">{{ $etiqueta ?? 'Firma' }}@if ($requerido ?? false) <span class="text-danger" aria-hidden="true">*</span>@endif</span>
        <button type="button" class="firma-limpiar" data-firma-limpiar><i class="bi bi-eraser" aria-hidden="true"></i> Limpiar firma</button>
    </div>
    <div class="firma-lienzo">
        <canvas id="{{ $id }}" data-firma-lienzo role="img" aria-labelledby="{{ $id }}_etiqueta" width="600" height="200"></canvas>
        <span class="firma-guia" data-firma-guia aria-hidden="true">Firme aquí</span>
    </div>
    <input type="hidden" name="{{ $nombre }}" value="" data-firma-valor @if ($requerido ?? false) data-firma-requerida @endif>
    @isset($ayuda)
        <p class="campo-ayuda">{{ $ayuda }}</p>
    @endisset
</div>
