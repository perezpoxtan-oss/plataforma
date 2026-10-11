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
    <h2 id="t-recepcion" class="h5 fw-bold"><i class="bi bi-shield-lock me-2 text-success" aria-hidden="true"></i>Recepción de candidatos: aviso de privacidad, paso a RR. HH., kiosco y criterios de entrevista</h2>
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
        {{-- Candidatos fase 1: la caseta espera el «Que pase» de RR. HH. --}}
        <input type="hidden" name="rh_autoriza_paso" value="0" @disabled(! $puedeGuardarRecepcion)>
        <label class="casilla-candidato mb-3">
            <input type="checkbox" name="rh_autoriza_paso" value="1" @checked(old('rh_autoriza_paso', $ajustesRecepcion->rhAutorizaPaso($empresa))) @disabled(! $puedeGuardarRecepcion)>
            <span><strong>La caseta espera a que RR. HH. diga «Que pase»</strong><br><span class="small text-muted">Quien viene a Recursos Humanos queda «Esperando a RR. HH.» en caseta hasta que alguien de RR. HH. responda Que pase, Que espere o No puede pasar. Apagado: pasa directo y RR. HH. solo recibe el aviso.</span></span>
        </label>
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
        {{-- Candidatos fase 2: criterios con los que se califican las entrevistas (RR. HH. y departamento) --}}
        @php
            $criteriosActuales = $ajustesRecepcion->criterios($empresa);
            $criteriosForm = old('criterios', $criteriosActuales);
            $criteriosForm = array_pad(array_slice(is_array($criteriosForm) ? array_values($criteriosForm) : [], 0, \App\Services\Recepcion\AjustesRecepcion::MAX_CRITERIOS), \App\Services\Recepcion\AjustesRecepcion::MAX_CRITERIOS, '');
        @endphp
        <fieldset class="bloque-cv mt-3" id="criterios-entrevista">
            <legend><i class="bi bi-star-half me-2" aria-hidden="true"></i>Criterios para calificar las entrevistas</legend>
            <p class="campo-ayuda mt-0">Recursos Humanos y el jefe califican cada criterio de 1 a 5 estrellas. Hasta {{ \App\Services\Recepcion\AjustesRecepcion::MAX_CRITERIOS }} criterios; deja vacío el renglón que no uses. Si cambias la lista, las evaluaciones anteriores conservan sus criterios.</p>
            @error('criterios')<p class="texto-error-rh">{{ $message }}</p>@enderror
            <ol class="lista-criterios">
                @foreach ($criteriosForm as $i => $criterio)
                    <li><input type="text" name="criterios[]" class="campo" maxlength="80" value="{{ $criterio }}" aria-label="Criterio {{ $i + 1 }}" @disabled(! $puedeGuardarRecepcion)></li>
                @endforeach
            </ol>
        </fieldset>
        @if ($puedeGuardarRecepcion)
            <button type="submit" class="btn-verde btn-accion-rh">Guardar ajustes de Recepción</button>
        @endif
    </form>
</section>
{{-- Escalamiento: qué pasa cuando una autorización tarda (Bitácora de accesos). Lo guarda quien configura Recepción o Autorizaciones con alcance de empresa. --}}
@php
    $puedeGuardarEscalamiento = $puedeGuardarRecepcion || (auth()->user()->can('autorizaciones.configurar')
        && app(\App\Services\Permisos\Autorizador::class)->alcanceDeEmpresa(auth()->user(), 'autorizaciones.configurar'));
@endphp
<section class="tarjeta p-4 mt-3" id="escalamiento" aria-labelledby="t-escalamiento">
    <h2 id="t-escalamiento" class="h5 fw-bold"><i class="bi bi-alarm me-2 text-warning" aria-hidden="true"></i>Cuando la autorización tarda: recordatorio y escalamiento</h2>
    <p class="small text-muted">Aplica a quien espera en caseta la autorización de un departamento o el «Que pase» de Recursos Humanos. A los primeros minutos se vuelve a avisar a quien debe responder (campana y correo); después se avisa a los suplentes del departamento (o a quien los cubre) y al Jefe de seguridad de la sede. Para Recursos Humanos se avisa al resto del equipo de RR. HH. y al Jefe de seguridad. La caseta ve en la tarjeta a quién espera, cuántos minutos lleva y a quién se escaló.</p>
    @unless ($puedeGuardarEscalamiento)
        <div class="alert alert-secondary small py-2"><i class="bi bi-lock-fill me-1" aria-hidden="true"></i>Tu rol solo puede consultar estos ajustes.</div>
    @endunless
    <form action="{{ route('recepcion.ajustes.guardar') }}" method="POST">
        @csrf
        @method('PUT')
        <div class="rejilla-cv">
            <div>
                <label class="campo-etiqueta" for="recordar_minutos">Recordar a los (minutos)</label>
                <input type="number" id="recordar_minutos" name="recordar_minutos" class="campo" min="1" max="{{ \App\Services\Recepcion\AjustesRecepcion::MAX_MINUTOS_ESCALAMIENTO }}"
                       value="{{ old('recordar_minutos', $ajustesRecepcion->recordarMinutos($empresa)) }}" @disabled(! $puedeGuardarEscalamiento)>
                <p class="campo-ayuda">Se reenvía el aviso al responsable (o a Recursos Humanos). La espera se pinta en ámbar.</p>
                @error('recordar_minutos')<p class="texto-error-rh">{{ $message }}</p>@enderror
            </div>
            <div>
                <label class="campo-etiqueta" for="escalar_minutos">Avisar al suplente y al jefe a los (minutos)</label>
                <input type="number" id="escalar_minutos" name="escalar_minutos" class="campo" min="2" max="{{ \App\Services\Recepcion\AjustesRecepcion::MAX_MINUTOS_ESCALAMIENTO }}"
                       value="{{ old('escalar_minutos', $ajustesRecepcion->escalarMinutos($empresa)) }}" @disabled(! $puedeGuardarEscalamiento)>
                <p class="campo-ayuda">Suplentes del departamento y Jefe de seguridad de la sede. Para RR. HH., el resto del equipo y el Jefe de seguridad. La espera se pinta en rojo.</p>
                @error('escalar_minutos')<p class="texto-error-rh">{{ $message }}</p>@enderror
            </div>
        </div>
        @if ($puedeGuardarEscalamiento)
            <button type="submit" class="btn-verde btn-accion-rh">Guardar escalamiento</button>
        @endif
    </form>
</section>
