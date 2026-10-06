{{--
    Ronda 5: botón que abre el diálogo "Código e identificación"
    (componentes/codigo-identificacion.blade.php, una vez por página).
    Ver docs/tecnico/lector.md.

    Parámetros (todos con prefijo "ident": un @include hereda las variables
    de la vista que lo llama, y así no choca con ellas):
      identTipo        clave del lector (config/lector.php): llave, vehiculo, gafete, equipo, equipo_pc, colaborador, lost_found
      identRegistro    el modelo (con codigo_qr y etiqueta_nfc)
      identTitulo      texto principal (nomenclatura, placas, nombre…)
      identDetalle     texto secundario ('' = sin detalle)
      identImprimir    dirección de la página de impresión (null = sin botón Imprimir)
      identImprimirTexto  "Imprimir etiqueta", "Imprimir calcomanía"…
      identEditable    bool: puede asignar la etiqueta NFC/RFID (el servidor lo vuelve a revisar)
      identAbrir       bool: abrir el diálogo al cargar la página (p. ej. después de reactivar)
      identClase       clases del botón ("btn-icono imprimir-qr" en las fichas)
      identTexto       texto visible junto al ícono ('' = botón de solo ícono)
--}}
@if (! empty($identRegistro->codigo_qr))
@php
    $identDetalle ??= '';
    $identImprimirTexto ??= 'Imprimir etiqueta';
    $identEditable ??= false;
    $identAbrir ??= false;
    $identClase ??= 'btn-icono imprimir-qr';
    $identTexto ??= '';
    $datosIdentificacion = json_encode([
        'titulo' => $identTitulo,
        'detalle' => $identDetalle,
        'qr' => route('identificacion.qr', [$identTipo, $identRegistro->getKey()]),
        'enlace' => route('lector.ir', $identRegistro->codigo_qr),
        'imprimir' => $identImprimir ?? '',
        'imprimirTexto' => $identImprimirTexto,
        'etiquetaUrl' => $identEditable ? route('identificacion.etiqueta', [$identTipo, $identRegistro->getKey()]) : '',
        'etiqueta' => $identRegistro->etiqueta_nfc ?? '',
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
@endphp
<button type="button" class="{{ $identClase }}" title="Código QR e identificación" aria-label="Código QR e identificación de {{ $identTitulo }}"
        data-ver-identificacion="{{ $datosIdentificacion }}" @if ($identAbrir) data-abrir-identificacion @endif>
    <i class="bi bi-qr-code" aria-hidden="true"></i>@if ($identTexto !== '') {{ $identTexto }}@endif
</button>
@endif
