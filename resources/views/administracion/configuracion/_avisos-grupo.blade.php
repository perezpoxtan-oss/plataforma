{{--
    Tarjeta de avisos por correo de un módulo (ADR-0016): interruptor general y
    casillas; las casillas pertenecen al formulario único #formAvisos (atributo form).
    $grupo, $def, $empresa, $puedeEditar, $auditoriaSede
--}}
@php $encendido = \App\Support\GruposAvisos::encendido($empresa, $grupo); @endphp
<div class="tarjeta p-4" id="config-{{ $grupo }}" data-modulo-avisos>
    <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
        <h3 id="t-config-{{ $grupo }}" class="h6 fw-bold m-0"><i class="bi {{ $def['icono'] }} me-2 text-primary" aria-hidden="true"></i>{{ $def['nombre'] }}: avisos por correo</h3>
        <div class="form-check form-switch interruptor-modulo m-0">
            <input type="hidden" name="avisos_modulos[{{ $grupo }}]" value="0" form="formAvisos" @disabled(! $puedeEditar)>
            <input class="form-check-input" type="checkbox" role="switch" id="mod_{{ $grupo }}" name="avisos_modulos[{{ $grupo }}]" value="1" form="formAvisos"
                   data-interruptor-modulo @checked($encendido) @disabled(! $puedeEditar)>
            <label class="form-check-label small fw-bold" for="mod_{{ $grupo }}">Avisos de este módulo <span data-interruptor-texto>{{ $encendido ? 'encendidos' : 'apagados' }}</span></label>
        </div>
    </div>
    <p class="small text-muted mt-2 mb-2">Se envían a quienes pueden atender cada asunto, al correo de su cuenta. Si apagas el interruptor, no sale ninguno de este módulo aunque esté marcado.</p>
    <div class="avisos-del-modulo {{ $encendido ? '' : 'apagado' }}" data-avisos-del-modulo>
        @foreach ($def['avisos'] as $clave => [$etiqueta, $porDefecto])
            <label class="opcion-todas">
                <input type="checkbox" name="avisos[{{ $clave }}]" value="1" form="formAvisos" @checked($empresa->preferencias['avisos'][$clave] ?? $porDefecto) @disabled(! $puedeEditar)>
                <span>{{ $etiqueta }}@unless ($porDefecto)<span class="valor-original"> · Valor original: apagado</span>@endunless</span>
            </label>
            @if (in_array($clave, \App\Models\Empresa::AVISOS_CON_DESTINATARIOS, true))
                <div class="ms-4 mb-3">
                    <label class="campo-etiqueta" for="dest_{{ $clave }}">Correos que lo reciben <span class="text-lowercase fw-normal">(uno por línea o separados por coma)</span></label>
                    <textarea id="dest_{{ $clave }}" name="destinatarios[{{ $clave }}]" form="formAvisos" class="campo mb-1" rows="2" maxlength="3500" placeholder="finanzas@tuempresa.com" @disabled(! $puedeEditar)>{{ old("destinatarios.$clave", implode("\n", $empresa->destinatariosAviso($clave))) }}</textarea>
                    <p class="campo-ayuda mt-0">Si lo dejas vacío, se envía a los usuarios que pueden autorizar vales de taxi en esa sede.</p>
                </div>
            @endif
            {{-- Ronda 5 (LL-04): una lista por copia del voucher con cobro --}}
            @if ($clave === 'voucher_cobro')
                <div class="ms-4 mb-3 destinatarios-voucher">
                    @foreach (\App\Models\Empresa::DESTINATARIOS_VOUCHER as $dest => $copia)
                        <label class="campo-etiqueta" for="dest_{{ $dest }}">{{ $copia }} <span class="text-lowercase fw-normal">(correos, uno por línea o separados por coma)</span></label>
                        <textarea id="dest_{{ $dest }}" name="destinatarios[{{ $dest }}]" form="formAvisos" class="campo mb-2" rows="2" maxlength="3500" placeholder="{{ \Illuminate\Support\Str::after($dest, 'voucher_') }}@tuempresa.com" @disabled(! $puedeEditar)>{{ old("destinatarios.$dest", implode("\n", $empresa->destinatariosAviso($dest))) }}</textarea>
                    @endforeach
                    <p class="campo-ayuda mt-0">El colaborador responsable no recibe copia. Si dejas las tres listas vacías, se envía a los usuarios que pueden imprimir vouchers en esa sede.</p>
                </div>
            @endif
        @endforeach
    </div>
    @if ($puedeEditar)
        <button type="submit" form="formAvisos" name="_grupo" value="{{ $grupo }}" class="btn-azul mt-2" style="min-height:44px;border-radius:10px;padding:0 1.25rem;">Guardar avisos</button>
    @else
        <p class="small text-muted mb-0 mt-2"><i class="bi bi-lock-fill me-1" aria-hidden="true"></i>Tu rol solo puede consultar los avisos.</p>
    @endif

    @if ($grupo === 'cuentas')
        @include('administracion.configuracion._auditoria')
    @endif
</div>
