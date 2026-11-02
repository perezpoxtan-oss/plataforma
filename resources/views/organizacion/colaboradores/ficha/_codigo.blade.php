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

{{-- C-44 (Etapa 8): su credencial como un activo más (Dar salida, Recibir OK / Dañado / No regresó, Dar de baja con voucher) --}}
@php
    $credencial ??= null;
    $puedeCredencial ??= false;
    $estadoCred = $credencial?->estado;
@endphp
@if ($credencial || $puedeCredencial)
<section class="tarjeta p-4 mt-3 credencial-colaborador" aria-labelledby="t-credencial">
    <h2 id="t-credencial" class="h6 fw-bold"><i class="bi bi-person-vcard me-2 text-secondary" aria-hidden="true"></i>Credencial</h2>
    @if ($credencial)
        <p class="mb-2">
            <strong>{{ $credencial->folio }}</strong>
            <span class="insignia-estado-activo {{ \App\Support\EstadoActivo::clase($estadoCred) }}">{{ $credencial->textoEstado() }}</span>
        </p>
        <dl class="lista-datos-expediente">
            @if ($credencial->entregada_en)<dt>Se le dio salida</dt><dd>@fecha($credencial->entregada_en){{ $credencial->entrego ? ' · '.$credencial->entrego->name : '' }}</dd>@endif
            @if ($credencial->recibida_en)<dt>Se recibió</dt><dd>@fecha($credencial->recibida_en){{ $credencial->recibio ? ' · '.$credencial->recibio->name : '' }}</dd>@endif
            @if ($estadoCred === 'baja')<dt>Baja</dt><dd>{{ \App\Models\Credencial::MOTIVOS_BAJA[$credencial->baja_motivo] ?? '—' }}</dd>@endif
        </dl>
        @if ($estadoCred === 'disponible')
            <p class="small text-muted">La guarda Recursos Humanos: dale salida cuando la recoja.</p>
        @endif
    @else
        <p class="small text-muted">Todavía no se le ha dado salida a su credencial.</p>
    @endif

    @if ($puedeCredencial && $c->activo)
        <div class="acciones-ficha">
            @if (! $credencial || in_array($estadoCred, ['disponible', 'baja'], true))
                <form action="{{ route('credenciales.salida', $c->id) }}" method="POST" class="m-0 form-credencial-salida">
                    @csrf
                    <details class="mb-2">
                        <summary class="small">Avanzado: tarjeta NFC / RFID del plástico (opcional)</summary>
                        @include('componentes.lector', ['id' => 'credencial_nfc', 'etiqueta' => 'Tarjeta NFC / RFID', 'modo' => 'capturar', 'nombre' => 'etiqueta_nfc',
                            'valor' => '', 'duplicado' => route('identificacion.etiqueta-duplicado', ['colaborador', $c->id])])
                    </details>
                    <button type="submit" class="btn-esmeralda"><i class="bi bi-box-arrow-right me-1" aria-hidden="true"></i>Dar salida</button>
                    <span class="small text-muted ms-1">Entrega la credencial impresa.</span>
                </form>
            @endif
            @if (in_array($estadoCred, ['asignado', 'no_regreso'], true))
                <form action="{{ route('credenciales.recibir', $credencial->id) }}" method="POST" class="m-0 recepcion-activo">
                    @csrf
                    @method('PATCH')
                    @include('componentes.recepcion-activo', ['raId' => 'credencial_'.$credencial->id, 'raNoRegreso' => $estadoCred === 'asignado'])
                    <button type="submit" class="btn-esmeralda"><i class="bi bi-box-arrow-in-down me-1" aria-hidden="true"></i>Recibir</button>
                </form>
                <details class="mt-2">
                    <summary class="small">Dar de baja con voucher (extraviada, dañada o robada)</summary>
                    <form action="{{ route('credenciales.baja', $credencial->id) }}" method="POST" class="m-0 mt-2"
                          data-confirmar="¿Dar de baja la credencial {{ $credencial->folio }}? Su código y su tarjeta dejarán de identificarlo.">
                        @csrf
                        <label class="campo-etiqueta" for="cred_motivo_{{ $credencial->id }}">Motivo</label>
                        <select id="cred_motivo_{{ $credencial->id }}" name="motivo" class="campo" required>
                            @foreach (\App\Models\VoucherReposicion::MOTIVOS as $clave => $texto)
                                <option value="{{ $clave }}">{{ $texto }}</option>
                            @endforeach
                        </select>
                        <label class="campo-etiqueta" for="cred_desc_{{ $credencial->id }}">¿Cómo pasó? (opcional)</label>
                        <textarea id="cred_desc_{{ $credencial->id }}" name="descripcion" class="campo" rows="2" maxlength="1000"></textarea>
                        <label class="d-flex align-items-center gap-2 mt-2"><input type="checkbox" name="aplica_cobro" value="1"> Con cobro al colaborador</label>
                        <label class="campo-etiqueta" for="cred_monto_{{ $credencial->id }}">Monto (si hay cobro)</label>
                        <input type="number" id="cred_monto_{{ $credencial->id }}" name="monto" class="campo" min="0" max="999999.99" step="0.01">
                        <input type="hidden" name="firma_modo" value="fisica">
                        <button type="submit" class="btn-rojo mt-2"><i class="bi bi-slash-circle me-1" aria-hidden="true"></i>Dar de baja</button>
                    </form>
                </details>
            @endif
        </div>
    @endif
    @if ($credencial)
        <p class="small mt-2 mb-0"><a href="{{ route('activos.historial', ['credencial', $credencial->id]) }}"><i class="bi bi-arrow-left-right me-1" aria-hidden="true"></i>Entregas y recepciones</a></p>
    @endif
</section>
@endif
