{{--
    Lector universal: un solo campo que acepta
      - lectores USB o Bluetooth que "escriben como teclado" (RFID, NFC, código de barras): PC, Mac, Android, iPhone y iPad;
      - QR con la cámara (cualquier celular o PC con cámara, incluido iPhone);
      - NFC del propio celular (solo Android con Chrome: el botón aparece únicamente donde funciona);
      - o lo que se teclee (nomenclatura, placas, número de empleado…).
    Ver docs/tecnico/lector.md.

    Parámetros (@include('componentes.lector', [...])):
      id          id del campo de texto (obligatorio, único en la página)
      etiqueta    texto de la etiqueta
      tipos       'llave,colaborador' (modo buscar): qué registros puede encontrar
      modo        'buscar' (default): encuentra el registro y guarda su id en el oculto `nombre`
                  'capturar': solo guarda lo leído en el campo `nombre` (para asignar una etiqueta NFC/RFID a un registro)
      nombre      name del campo que se envía (id elegido en modo buscar; la etiqueta en modo capturar)
      valor       valor inicial (id en buscar; etiqueta en capturar)
      elegido     en modo buscar, texto del registro ya elegido (al editar)
      requerido   bool
      ayuda       texto bajo el campo (opcional)
      duplicado   solo en modo capturar (Ronda 6, GV-03): dirección del aviso en vivo «ya la tiene X»
                  (route('identificacion.etiqueta-duplicado', tipo)); el aviso sale debajo del lector
--}}
@php
    $modo = $modo ?? 'buscar';
    $capturar = $modo === 'capturar';
@endphp
<div class="lector" data-lector data-modo="{{ $modo }}" data-tipos="{{ $tipos ?? '' }}"
     data-url="{{ route('lector.resolver') }}" data-jsqr="{{ asset('vendor/jsqr/jsQR.js') }}">
    @isset($etiqueta)
        <label class="campo-etiqueta" for="{{ $id }}">{{ $etiqueta }}</label>
    @endisset
    <div class="lector-fila">
        <span class="lector-icono" aria-hidden="true"><i class="bi {{ $capturar ? 'bi-broadcast-pin' : 'bi-upc-scan' }}"></i></span>
        <input type="text" id="{{ $id }}" class="campo lector-entrada" data-lector-entrada autocomplete="off" autocapitalize="characters" spellcheck="false"
               maxlength="200" enterkeyhint="search"
               @if ($capturar) name="{{ $nombre }}" value="{{ $valor ?? '' }}" @if ($requerido ?? false) required @endif @endif
               @if ($capturar && ! empty($duplicado)) data-duplicado="{{ $duplicado }}" data-duplicado-min="4" data-duplicado-aviso="{{ $id }}_aviso" @endif
               placeholder="{{ $capturar ? 'Acerca la tarjeta al lector o escribe su número' : 'Escanea, acerca la etiqueta o escribe' }}">
        <button type="button" class="btn-lector" data-lector-camara hidden title="Leer código QR con la cámara" aria-label="Leer código QR con la cámara"><i class="bi bi-qr-code-scan" aria-hidden="true"></i></button>
        <button type="button" class="btn-lector" data-lector-nfc hidden title="Leer con el NFC del celular" aria-label="Leer con el NFC del celular"><i class="bi bi-broadcast" aria-hidden="true"></i></button>
    </div>
    @unless ($capturar)
        <input type="hidden" name="{{ $nombre ?? '' }}" value="{{ $valor ?? '' }}" data-lector-id @if ($requerido ?? false) data-requerido @endif>
        <div class="lector-elegido" data-lector-elegido @if (empty($elegido)) hidden @endif>
            <i class="bi bi-check-circle-fill" aria-hidden="true"></i>
            <span data-lector-titulo>{{ $elegido ?? '' }}</span>
            <button type="button" class="lector-cambiar" data-lector-limpiar>Cambiar</button>
        </div>
        <div class="lector-opciones" data-lector-opciones hidden></div>
    @endunless
    <p class="lector-estado" data-lector-estado role="status" hidden></p>
    @if ($capturar && ! empty($duplicado))
        <div class="aviso-duplicado" id="{{ $id }}_aviso" data-aviso-duplicado role="status" aria-live="polite" hidden></div>
    @endif
    @isset($ayuda)
        <p class="campo-ayuda">{{ $ayuda }}</p>
    @endisset
</div>
