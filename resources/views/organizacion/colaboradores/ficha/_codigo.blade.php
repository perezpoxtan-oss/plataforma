{{-- Pestaña «Código y etiqueta»: el QR de su gafete o tarjeta y la etiqueta NFC / RFID. Asignar la etiqueta e imprimir la credencial siguen en el diálogo «Código e identificación» (componentes/codigo-identificacion). --}}
<section class="tarjeta p-4" aria-labelledby="t-codigo">
    <h2 id="t-codigo" class="h6 fw-bold"><i class="bi bi-qr-code me-2 text-secondary" aria-hidden="true"></i>Código QR y etiqueta NFC / RFID</h2>
    <div class="row g-3 align-items-center">
        <div class="col-sm-5 col-md-4">
            <div class="ident-qr m-0">
                <img src="{{ route('identificacion.qr', ['colaborador', $c->id]) }}" alt="Código QR de {{ $c->nombreCompleto() }}" width="220" height="220">
            </div>
        </div>
        <div class="col-sm-7 col-md-8">
            <dl class="lista-datos-expediente">
                <dt>Dirección del código</dt><dd><code>{{ route('lector.ir', $c->codigo_qr) }}</code></dd>
                <dt>Etiqueta NFC / RFID</dt><dd>{{ $c->etiqueta_nfc ? $c->etiqueta_nfc : 'Sin etiqueta asignada' }}</dd>
            </dl>
            <p class="small text-muted">Al leer el código con la cámara del celular, o la etiqueta con el lector, se abre este colaborador.</p>
            <div class="acciones-ficha">
                @include('componentes.boton-identificacion', ['identTipo' => 'colaborador', 'identRegistro' => $c, 'identTitulo' => $c->nombreCompleto(),
                    'identDetalle' => 'Núm. '.$c->num_empleado, 'identImprimir' => $imprimirCredencial, 'identImprimirTexto' => 'Imprimir credencial',
                    'identEditable' => $identEditable, 'identClase' => 'btn-esmeralda',
                    'identTexto' => 'Código e identificación'])
                @if ($imprimirCredencial)
                    <a href="{{ $imprimirCredencial }}" class="btn-cancelar"><i class="bi bi-printer me-1" aria-hidden="true"></i>Imprimir credencial</a>
                @endif
            </div>
        </div>
    </div>
</section>
