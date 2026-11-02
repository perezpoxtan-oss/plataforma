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
        <details class="mt-2" @if ($val('tecnologia') !== 'qr' || $val('reverso_texto') !== '' || $val('telefono') !== '') open @endif>
            <summary class="small fw-semibold">Avanzado: tecnología y reverso</summary>
            <label class="campo-etiqueta" for="cfg_cred_tecnologia">Tecnología</label>
            <select id="cfg_cred_tecnologia" name="tecnologia" class="campo" @disabled(! $puedeEditar)>
                @foreach (\App\Support\AjustesCredenciales::TECNOLOGIAS as $clave => $texto)
                    <option value="{{ $clave }}" @selected($val('tecnologia') === $clave)>{{ $texto }}</option>
                @endforeach
            </select>
            <label class="campo-etiqueta" for="cfg_cred_telefono">Teléfono del reverso (si la encuentran)</label>
            <input type="text" id="cfg_cred_telefono" name="telefono" class="campo" maxlength="30" value="{{ $val('telefono') }}" @disabled(! $puedeEditar)>
            <label class="campo-etiqueta" for="cfg_cred_reverso">Texto adicional del reverso</label>
            <textarea id="cfg_cred_reverso" name="reverso_texto" class="campo" rows="2" maxlength="300" @disabled(! $puedeEditar)>{{ $val('reverso_texto') }}</textarea>
            <p class="campo-ayuda mt-0"><span class="valor-original">Valor original: solo QR; el reverso dice que es propiedad de la empresa y que se entregue en la caseta.</span></p>
        </details>
        @if ($puedeEditar)
            <button type="submit" class="btn-azul mt-2">Guardar</button>
        @endif
    </form>
</div>
