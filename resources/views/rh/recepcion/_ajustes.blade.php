{{--
    Ajustes de Recepción (aviso de privacidad y kiosco). Se muestra en Recepción → Ajustes
    y en Configuración. $empresa
--}}
@php
    $ajustesRecepcion = app(\App\Services\Recepcion\AjustesRecepcion::class);
    $puedeGuardarRecepcion = auth()->user()->can('candidatos.configurar')
        && app(\App\Services\Permisos\Autorizador::class)->alcanceDeEmpresa(auth()->user(), 'candidatos.configurar');
    $textoAviso = $ajustesRecepcion->textoPrivacidad($empresa);
@endphp
<section class="tarjeta p-4" id="recepcion" aria-labelledby="t-recepcion">
    <h2 id="t-recepcion" class="h5 fw-bold"><i class="bi bi-shield-lock me-2 text-success" aria-hidden="true"></i>Recepción de candidatos: aviso de privacidad y kiosco</h2>
    <p class="small text-muted">El candidato debe marcar «Acepto el aviso de privacidad» antes de guardar su CV (en el kiosco o con Recursos Humanos). Se guarda cuándo, desde qué equipo y la versión exacta del texto que aceptó.</p>
    @if ($ajustesRecepcion->esBorrador($empresa))
        <div class="alert alert-warning small py-2"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i><strong>El texto es un borrador.</strong> Pide a tu abogado que lo revise y escribe aquí el texto definitivo (quita la marca «{{ \App\Services\Recepcion\AjustesRecepcion::MARCA_BORRADOR }}»).</div>
    @endif
    @unless ($puedeGuardarRecepcion)
        <div class="alert alert-secondary small py-2"><i class="bi bi-lock-fill me-1" aria-hidden="true"></i>Tu rol solo puede consultar estos ajustes.</div>
    @endunless
    <form action="{{ route('recepcion.ajustes.guardar') }}" method="POST">
        @csrf
        @method('PUT')
        <label class="campo-etiqueta" for="aviso_privacidad">Texto del aviso de privacidad</label>
        <textarea id="aviso_privacidad" name="aviso_privacidad" class="campo" rows="9" maxlength="6000" @disabled(! $puedeGuardarRecepcion)>{{ old('aviso_privacidad', $textoAviso) }}</textarea>
        <p class="campo-ayuda">Versión vigente: {{ substr($ajustesRecepcion->versionPrivacidad($empresa), 0, 10) }} (cambia sola si cambias el texto).</p>
        <div class="rejilla-cv">
            <div>
                <label class="campo-etiqueta" for="kiosco_horas">El enlace del kiosco dura (horas)</label>
                <input type="number" id="kiosco_horas" name="kiosco_horas" class="campo" min="1" max="24" value="{{ old('kiosco_horas', $ajustesRecepcion->horasKiosco($empresa)) }}" @disabled(! $puedeGuardarRecepcion)>
            </div>
            <div>
                <label class="campo-etiqueta" for="kiosco_usos">Veces que se puede enviar</label>
                <input type="number" id="kiosco_usos" name="kiosco_usos" class="campo" min="1" max="10" value="{{ old('kiosco_usos', $ajustesRecepcion->usosKiosco($empresa)) }}" @disabled(! $puedeGuardarRecepcion)>
            </div>
        </div>
        @if ($puedeGuardarRecepcion)
            <button type="submit" class="btn-verde btn-accion-rh">Guardar ajustes de Recepción</button>
        @endif
    </form>
</section>
