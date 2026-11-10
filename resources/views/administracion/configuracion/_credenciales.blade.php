{{--
    C-44 (Etapa 8): Configuración → Estructura → Credenciales. Sin cambiar
    nada: no vencen, solo QR y el reverso dice de quién es (lo que el dueño aún
    no decide queda con un valor prudente; ver docs/tecnico/credenciales.md).
--}}
@php
    $aj = \App\Support\AjustesCredenciales::de($empresa);
    $val = fn (string $k) => old($k, $aj[$k]);
@endphp
<div class="tarjeta p-4 config-caseta" id="cfg-credenciales">
    <h3 class="h6 fw-bold"><i class="bi bi-person-vcard me-2 text-primary" aria-hidden="true"></i>Credenciales del personal</h3>
    @unless ($puedeEditar)
        <div class="alert alert-secondary small py-2"><i class="bi bi-lock-fill me-1" aria-hidden="true"></i>Tu rol solo puede consultar esta configuración, no modificarla.</div>
    @endunless
    <form action="{{ route('configuracion.credenciales') }}" method="POST">
        @csrf
        @method('PUT')
        <label class="campo-etiqueta" for="cfg_cred_vigencia">Vigencia</label>
        <select id="cfg_cred_vigencia" name="vigencia" class="campo" @disabled(! $puedeEditar)>
            @foreach (\App\Support\AjustesCredenciales::VIGENCIAS as $clave => $texto)
                <option value="{{ $clave }}" @selected($val('vigencia') === $clave)>{{ $texto }}</option>
            @endforeach
        </select>
        <div class="campo-grupo">
            <div class="flex-fill">
                <label class="campo-etiqueta" for="cfg_cred_meses">Meses (si vence por meses)</label>
                <input type="number" id="cfg_cred_meses" name="vigencia_meses" class="campo campo-horas-caseta" min="1" max="120" value="{{ $val('vigencia_meses') }}" @disabled(! $puedeEditar)>
            </div>
            <div class="flex-fill">
                <label class="campo-etiqueta" for="cfg_cred_fecha">Fecha (si vence cada año, MM-DD)</label>
                <input type="text" id="cfg_cred_fecha" name="vigencia_fecha" class="campo campo-horas-caseta" maxlength="5" placeholder="12-31" value="{{ $val('vigencia_fecha') }}" @disabled(! $puedeEditar)>
            </div>
            <div class="flex-fill">
                <label class="campo-etiqueta" for="cfg_cred_aviso">Avisar en Mis pendientes</label>
                <div class="d-flex align-items-center gap-2"><input type="number" id="cfg_cred_aviso" name="aviso_dias" class="campo campo-horas-caseta" min="0" max="180" value="{{ $val('aviso_dias') }}" @disabled(! $puedeEditar)><span class="small text-muted">días antes de vencer</span></div>
            </div>
        </div>
        <p class="campo-ayuda mt-0">La vigencia se calcula al emitir; las ya emitidas no cambian. <span class="valor-original">Valor original: sin vencimiento, aviso 30 días antes.</span></p>
        @php $tarjetasCred = array_values(array_filter((array) $val('tarjetas'), fn ($t) => is_string($t) && $t !== '')); @endphp
        <details class="mt-2" @if ($tarjetasCred !== [] || $val('reverso_texto') !== '' || $val('telefono') !== '') open @endif>
            <summary class="small fw-semibold">Avanzado: tecnología y reverso</summary>
            {{-- Decisión del dueño (3 nov): el QR siempre; cada empresa habilita las tarjetas que usa (pueden convivir) --}}
            <fieldset>
                <legend class="campo-etiqueta">Tecnología de la credencial</legend>
                <label class="d-flex align-items-start gap-2 small"><input type="checkbox" checked disabled>
                    <span><strong>Código QR</strong> (siempre) — {{ \App\Support\AjustesCredenciales::LECTOR_QR }}.</span></label>
                <input type="hidden" name="tarjetas[]" value="">
                @foreach (\App\Support\AjustesCredenciales::TARJETAS as $clave => $tj)
                    <label class="d-flex align-items-start gap-2 small" for="cfg_cred_tarjeta_{{ $clave }}">
                        <input type="checkbox" id="cfg_cred_tarjeta_{{ $clave }}" name="tarjetas[]" value="{{ $clave }}" @checked(in_array($clave, $tarjetasCred, true)) @disabled(! $puedeEditar)>
                        <span><strong>Tarjeta {{ $tj['nombre'] }}</strong> — {{ $tj['lector'] }}.</span></label>
                @endforeach
                @error('tarjetas')<p class="texto-error-campo">{{ $message }}</p>@enderror
            </fieldset>
            <p class="campo-ayuda mt-1">Puedes habilitar las dos. El número de la tarjeta queda en la etiqueta NFC / RFID del colaborador y la caseta la lee igual que el QR. Sin ninguna, al dar salida no se pide número de tarjeta.</p>
            <label class="campo-etiqueta" for="cfg_cred_telefono">Teléfono del reverso (si la encuentran)</label>
            <input type="text" id="cfg_cred_telefono" name="telefono" class="campo" maxlength="30" value="{{ $val('telefono') }}" @disabled(! $puedeEditar)>
            <label class="campo-etiqueta" for="cfg_cred_reverso">Texto adicional del reverso</label>
            <textarea id="cfg_cred_reverso" name="reverso_texto" class="campo" rows="2" maxlength="300" @disabled(! $puedeEditar)>{{ $val('reverso_texto') }}</textarea>
            <p class="campo-ayuda mt-0"><span class="valor-original">Valor original: solo código QR, sin tarjeta; el reverso dice que es propiedad de la empresa y que se entregue en la caseta.</span></p>
        </details>
        @if ($puedeEditar)
            <button type="submit" class="btn-azul mt-2">Guardar</button>
        @endif
    </form>
</div>
