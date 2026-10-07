{{--
    Ronda 5 (LL-05 / VE-04): diálogo "Código e identificación", común a todas
    las fichas con QR. Se incluye UNA vez por página; lo abren los botones de
    componentes/boton-identificacion.blade.php. Muestra el QR (generado en el
    servidor), la dirección /e/{código} con "Copiar", el botón de imprimir
    (abre la página de impresión) y, con permiso de editar, "Asignar etiqueta
    NFC / RFID" con el lector universal en modo capturar.
    Comportamiento: bloque "Ajustes Ronda 5" al final de public/js/plataforma.js.
--}}
<dialog id="dialogoIdentificacion" class="dialogo dialogo-identificacion" aria-labelledby="titulo-identificacion">
    <div class="dialogo-cabecera">
        <h2 id="titulo-identificacion"><i class="bi bi-qr-code me-2" aria-hidden="true"></i>Código e identificación</h2>
        <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </div>
    <div class="dialogo-cuerpo">
        <p class="ident-titulo" data-ident-titulo></p>
        <p class="ident-detalle" data-ident-detalle></p>
        <div class="ident-qr">
            <img src="data:," alt="Código QR" width="220" height="220" data-ident-qr>
        </div>
        <p class="ident-ayuda">Al leerlo con la cámara del celular abre esta ficha (pide entrar si no hay sesión). La misma dirección se puede grabar en una etiqueta NFC:</p>
        <div class="ident-enlace">
            <code data-ident-enlace></code>
            <button type="button" class="btn-ident-copiar" data-ident-copiar><i class="bi bi-clipboard" aria-hidden="true"></i> <span data-ident-copiar-texto>Copiar</span></button>
        </div>
        <div class="ident-acciones">
            <a href="#" target="_blank" rel="noopener" class="btn-ident-imprimir" data-ident-imprimir hidden><i class="bi bi-printer me-1" aria-hidden="true"></i><span data-ident-imprimir-texto>Imprimir etiqueta</span></a>
        </div>

        <section class="ident-nfc" data-ident-nfc hidden aria-labelledby="titulo-ident-nfc">
            <h3 id="titulo-ident-nfc"><i class="bi bi-broadcast-pin me-1" aria-hidden="true"></i>Asignar etiqueta NFC / RFID</h3>
            <p class="ident-nfc-actual" data-ident-nfc-actual></p>
            <form method="POST" action="" autocomplete="off" data-ident-form>
                @csrf
                @method('PUT')
                {{-- Todos los parámetros explícitos: un @include hereda las variables de la pantalla que lo llama --}}
                @include('componentes.lector', ['id' => 'ident_etiqueta_nfc', 'etiqueta' => 'Tarjeta, llavero o etiqueta', 'modo' => 'capturar',
                    'nombre' => 'etiqueta_nfc', 'valor' => '', 'tipos' => '', 'requerido' => false, 'elegido' => null, 'ayuda' =>'Acércala al lector USB/Bluetooth o al NFC del celular (Android). También puedes escribir su número.'])
                <p class="ident-mensaje" data-ident-mensaje role="status" hidden></p>
                <div class="ident-nfc-botones">
                    <button type="button" class="btn-ident-quitar" data-ident-quitar hidden><i class="bi bi-x-circle me-1" aria-hidden="true"></i>Quitar etiqueta</button>
                    <button type="submit" class="btn-ident-guardar"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Guardar etiqueta</button>
                </div>
            </form>
        </section>

        <div class="dialogo-acciones">
            <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cerrar</button>
        </div>
    </div>
</dialog>
