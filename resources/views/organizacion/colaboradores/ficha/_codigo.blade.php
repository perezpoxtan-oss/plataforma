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

{{--
    C-44 (Etapa 8): su credencial (propuesta del 12 oct): foto, Emitir, Imprimir (CR80 frente y reverso),
    Dar salida, Recibir (OK / Dañado / No regresó), Reponer y Dar de baja con voucher. Una sola vigente.
--}}
@php
    $credencial ??= null;
    $puedeCredencial ??= false;
    $ajustesCredencial ??= \App\Support\AjustesCredenciales::de($c->empresa_id);
    $estadoCred = $credencial?->estado;
    $vigenteCred = \App\Services\Credenciales\AdministradorCredenciales::esVigente($credencial, $c);
    $fotoCred = $puedeCredencial || $credencial ? \App\Services\Credenciales\AdministradorCredenciales::fotoDataUri($c) : null;
    $maneja = $puedeCredencial && $c->activo && ! $c->esResidente();
@endphp
@if ($credencial || $maneja)
<section class="tarjeta p-4 mt-3 credencial-colaborador" aria-labelledby="t-credencial">
    <h2 id="t-credencial" class="h6 fw-bold"><i class="bi bi-person-vcard me-2 text-secondary" aria-hidden="true"></i>Credencial</h2>
    <div class="d-flex gap-3 flex-wrap align-items-start">
        <div>
            @if ($fotoCred)<img src="{{ $fotoCred }}" alt="Foto de la credencial de {{ $c->nombreCompleto() }}" class="foto-credencial">@else<div class="foto-credencial" aria-label="Sin foto"><i class="bi bi-person" aria-hidden="true"></i></div>@endif
        </div>
        <div class="flex-fill">
            @if ($credencial)
                <p class="mb-2">
                    <strong>{{ $credencial->folio }}</strong>
                    <span class="insignia-estado-activo {{ $credencial->vencida() && $estadoCred !== 'baja' ? 'estado-activo-vencida' : \App\Support\EstadoActivo::clase($estadoCred) }}">{{ $credencial->textoEstado() }}</span>
                </p>
                <dl class="lista-datos-expediente">
                    <dt>Vigencia</dt><dd>{{ $credencial->vence_en ? 'Vence el '.$credencial->vence_en->format('d/m/Y') : 'Sin vencimiento' }}</dd>
                    @if ($credencial->etiqueta_nfc)
                        <dt>Tarjeta</dt><dd>{{ \App\Support\AjustesCredenciales::nombreTarjeta($credencial->tipo_tarjeta) ?? 'Tarjeta NFC / RFID' }} · {{ $credencial->etiqueta_nfc }}</dd>
                        @if (\App\Support\AjustesCredenciales::lectorTarjeta($credencial->tipo_tarjeta))<dt>Lector que la lee</dt><dd>{{ \App\Support\AjustesCredenciales::lectorTarjeta($credencial->tipo_tarjeta) }}</dd>@endif
                    @endif
                    @if ($credencial->entregada_en)<dt>Se le dio salida</dt><dd>@fecha($credencial->entregada_en){{ $credencial->entrego ? ' · '.$credencial->entrego->name : '' }}</dd>@endif
                    @if ($credencial->recibida_en)<dt>Se recibió</dt><dd>@fecha($credencial->recibida_en){{ $credencial->recibio ? ' · '.$credencial->recibio->name : '' }}</dd>@endif
                    @if ($estadoCred === 'baja')<dt>Baja</dt><dd>{{ \App\Models\Credencial::MOTIVOS_BAJA[$credencial->baja_motivo] ?? '—' }}</dd>@endif
                </dl>
                @if ($estadoCred === 'disponible')
                    <p class="small text-muted">Emitida: la guarda Recursos Humanos. Imprímela y dale salida cuando la recoja.</p>
                @endif
            @else
                <p class="small text-muted">Todavía no tiene credencial. Toma su foto y emítela.</p>
            @endif
            {{-- Decisión del dueño (3 nov): qué identifica la credencial en esta empresa y qué lector necesita cada cosa --}}
            <p class="small text-muted mb-0 credencial-tecnologias">
                <i class="bi bi-upc-scan me-1" aria-hidden="true"></i><strong>Se lee con:</strong> código QR — {{ \App\Support\AjustesCredenciales::LECTOR_QR }}@foreach ($ajustesCredencial['tarjetas'] as $tipoTj); tarjeta {{ \App\Support\AjustesCredenciales::nombreTarjeta($tipoTj) }} — {{ \App\Support\AjustesCredenciales::lectorTarjeta($tipoTj) }}@endforeach.
            </p>
        </div>
    </div>

    @if ($maneja)
        {{-- Foto: cámara (celular o computadora) o archivo; se recorta sola a tamaño credencial --}}
        <details class="mt-2" @if ($errors->has('foto')) open @endif>
            <summary class="small fw-semibold">{{ $c->foto_credencial_ruta ? 'Cambiar la foto' : 'Tomar foto' }}</summary>
            <form action="{{ route('credenciales.foto', $c->id) }}" method="POST" enctype="multipart/form-data" class="mt-2 camara-credencial" data-camara-credencial>
                @csrf
                <div class="d-flex gap-2 flex-wrap mb-2">
                    <button type="button" class="btn-cancelar" data-camara-abrir><i class="bi bi-camera me-1" aria-hidden="true"></i>Usar la cámara</button>
                    <button type="button" class="btn-cancelar" data-camara-tomar hidden><i class="bi bi-circle-fill me-1" aria-hidden="true"></i>Tomar la foto</button>
                </div>
                <video data-camara-video playsinline muted hidden></video>
                <canvas data-camara-lienzo width="600" height="800" hidden></canvas>
                <input type="hidden" name="foto_camara" data-camara-dato>
                <label class="campo-etiqueta" for="cred_foto_{{ $c->id }}">O elige un archivo (JPG o PNG)</label>
                <input type="file" id="cred_foto_{{ $c->id }}" name="foto" class="campo" accept="image/jpeg,image/png" capture="user">
                <p class="campo-ayuda mt-1">De frente, fondo claro y sin lentes oscuros. Se recorta sola a tamaño credencial y se guarda privada.</p>
                <button type="submit" class="btn-esmeralda"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Guardar foto</button>
            </form>
        </details>

        <div class="acciones-ficha mt-3">
            @if (! $credencial || $estadoCred === 'baja')
                <form action="{{ route('credenciales.emitir', $c->id) }}" method="POST" class="m-0">
                    @csrf
                    <button type="submit" class="btn-esmeralda"><i class="bi bi-person-vcard me-1" aria-hidden="true"></i>Emitir credencial</button>
                    <span class="small text-muted ms-1">{{ $ajustesCredencial['vigencia'] === 'sin' ? 'Sin vencimiento.' : 'Vence según la regla de tu empresa.' }}</span>
                </form>
            @endif
            @if ($vigenteCred)
                <a href="{{ route('credenciales.imprimir', ['credenciales' => [$credencial->id]]) }}" class="btn-cancelar" target="_blank" rel="noopener"><i class="bi bi-printer me-1" aria-hidden="true"></i>Imprimir credencial</a>
            @endif
            @if ($estadoCred === 'disponible')
                <form action="{{ route('credenciales.salida', $c->id) }}" method="POST" class="m-0 form-credencial-salida">
                    @csrf
                    {{-- Sin tarjetas habilitadas (de fábrica, solo QR) no se pide número de tarjeta --}}
                    @if ($ajustesCredencial['tarjetas'] !== [])
                        <details class="mb-2" @if ($errors->has('etiqueta_nfc') || $errors->has('tipo_tarjeta')) open @endif>
                            <summary class="small">Avanzado: tarjeta NFC / RFID del plástico (opcional)</summary>
                            @if (count($ajustesCredencial['tarjetas']) === 1)
                                <input type="hidden" name="tipo_tarjeta" value="{{ $ajustesCredencial['tarjetas'][0] }}">
                                <p class="campo-ayuda mt-2 mb-1">Tarjeta {{ \App\Support\AjustesCredenciales::nombreTarjeta($ajustesCredencial['tarjetas'][0]) }}: {{ \App\Support\AjustesCredenciales::lectorTarjeta($ajustesCredencial['tarjetas'][0]) }}.</p>
                            @else
                                <fieldset class="mt-2">
                                    <legend class="campo-etiqueta">¿Qué tarjeta es?</legend>
                                    @foreach ($ajustesCredencial['tarjetas'] as $tipoTj)
                                        <label class="d-flex align-items-start gap-2 small"><input type="radio" name="tipo_tarjeta" value="{{ $tipoTj }}" @checked(old('tipo_tarjeta') === $tipoTj)>
                                            <span><strong>{{ \App\Support\AjustesCredenciales::nombreTarjeta($tipoTj) }}</strong> — {{ \App\Support\AjustesCredenciales::lectorTarjeta($tipoTj) }}</span></label>
                                    @endforeach
                                </fieldset>
                                @error('tipo_tarjeta')<p class="texto-error-campo">{{ $message }}</p>@enderror
                            @endif
                            @include('componentes.lector', ['id' => 'credencial_nfc', 'etiqueta' => 'Número de la tarjeta', 'modo' => 'capturar', 'nombre' => 'etiqueta_nfc',
                                'valor' => '', 'duplicado' => route('identificacion.etiqueta-duplicado', ['colaborador', $c->id])])
                        </details>
                    @endif
                    <button type="submit" class="btn-esmeralda"><i class="bi bi-box-arrow-right me-1" aria-hidden="true"></i>Dar salida</button>
                    <span class="small text-muted ms-1">Se la entregas impresa.</span>
                </form>
            @endif
            @if (in_array($estadoCred, ['asignado', 'no_regreso'], true))
                <form action="{{ route('credenciales.recibir', $credencial->id) }}" method="POST" class="m-0 recepcion-activo">
                    @csrf
                    @method('PATCH')
                    @include('componentes.recepcion-activo', ['raId' => 'credencial_'.$credencial->id, 'raNoRegreso' => $estadoCred === 'asignado'])
                    <button type="submit" class="btn-esmeralda"><i class="bi bi-box-arrow-in-down me-1" aria-hidden="true"></i>Recibir</button>
                </form>
            @endif
            @if ($credencial && $estadoCred !== 'baja')
                <details class="mt-2">
                    <summary class="small">Reponer credencial (se perdió, se dañó, venció o cambió su foto)</summary>
                    <form action="{{ route('credenciales.reponer', $credencial->id) }}" method="POST" class="m-0 mt-2"
                          data-confirmar="¿Reponer la credencial {{ $credencial->folio }}? Deja de valer en este momento y se emite otra.">
                        @csrf
                        <label class="campo-etiqueta" for="cred_rep_motivo_{{ $credencial->id }}">¿Por qué?</label>
                        <select id="cred_rep_motivo_{{ $credencial->id }}" name="motivo" class="campo" required>
                            @foreach (\App\Models\Credencial::MOTIVOS_REPONER as $clave => $texto)
                                <option value="{{ $clave }}" @selected($credencial->vencida() ? $clave === 'vencida' : $clave === 'extraviado')>{{ $texto }}</option>
                            @endforeach
                        </select>
                        <label class="d-flex align-items-center gap-2 mt-2"><input type="checkbox" name="aplica_cobro" value="1"> Con cobro al colaborador (solo extraviada, dañada o robada)</label>
                        <label class="campo-etiqueta" for="cred_rep_monto_{{ $credencial->id }}">Monto (si hay cobro)</label>
                        <input type="number" id="cred_rep_monto_{{ $credencial->id }}" name="monto" class="campo" min="0" max="999999.99" step="0.01">
                        <input type="hidden" name="firma_modo" value="fisica">
                        <button type="submit" class="btn-esmeralda mt-2"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Reponer credencial</button>
                    </form>
                </details>
                <details class="mt-2">
                    <summary class="small">Dar de baja con voucher (sin emitir otra)</summary>
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
