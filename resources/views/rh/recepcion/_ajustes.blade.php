{{--
    Ajustes de Recepción (aviso de privacidad y kiosco). Se muestra en Recepción → Ajustes
    y en Configuración. $empresa. Lo demás (esperas, escalamiento, criterios) tiene su pantalla (ADR-0010).
--}}
@php
    $ajustesRecepcion = app(\App\Services\Recepcion\AjustesRecepcion::class);
    $puedeGuardarRecepcion = auth()->user()->can('candidatos.configurar')
        && app(\App\Services\Permisos\Autorizador::class)->alcanceDeEmpresa(auth()->user(), 'candidatos.configurar');
@endphp
<section class="tarjeta p-4" id="recepcion" aria-labelledby="t-recepcion">
    <h2 id="t-recepcion" class="h5 fw-bold"><i class="bi bi-shield-lock me-2 text-success" aria-hidden="true"></i>Recepción de candidatos: aviso de privacidad y kiosco</h2>
    <p class="small text-muted">El candidato debe marcar «Acepto el aviso de privacidad» antes de guardar su CV (en el kiosco o con Recursos Humanos). Se guarda cuándo, desde qué equipo y la versión exacta del texto que aceptó.</p>
    @if ($ajustesRecepcion->esBorrador($empresa))
        <div class="alert alert-warning small py-2"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i><strong>El aviso tiene datos por completar</strong> (por ejemplo, el nombre y el domicilio de tu empresa, entre corchetes). Complétalo con tu abogado y publícalo en Configuración → Privacidad.</div>
    @endif
    @unless ($puedeGuardarRecepcion)
        <div class="alert alert-secondary small py-2"><i class="bi bi-lock-fill me-1" aria-hidden="true"></i>Tu rol solo puede consultar estos ajustes.</div>
    @endunless
    {{-- C-37 (E-09): el aviso de privacidad se edita en un solo lugar (Configuración → Privacidad), con el mismo número de versión --}}
    @php $avisoVigente = app(\App\Services\Privacidad\AvisosPrivacidad::class)->vigente($empresa); @endphp
    <div class="tarjeta-enlace-config small mb-3" id="recepcion-aviso">
        <p class="mb-2"><i class="bi bi-file-earmark-lock me-1" aria-hidden="true"></i>Aviso de privacidad: <strong>versión {{ $avisoVigente?->numero ?? 1 }}</strong>@if ($avisoVigente), publicada el @fecha($avisoVigente->publicado_en, 'd/m/Y')@endif. Es el que acepta el candidato. Se edita en un solo lugar: Configuración → Privacidad.</p>
        @can('configuracion.datos_personales')
            <a href="{{ route('configuracion.index') }}#cfg-aviso" class="btn-enlace-config"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Editar en Configuración → Privacidad</a>
        @endcan
    </div>
    <form action="{{ route('recepcion.ajustes.guardar') }}" method="POST">
        @csrf
        @method('PUT')
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
        <p class="campo-ayuda mt-0"><span class="valor-original">Valor original: {{ \App\Services\Recepcion\AjustesRecepcion::HORAS_KIOSCO }} horas y {{ \App\Services\Recepcion\AjustesRecepcion::USOS_KIOSCO }} veces.</span></p>
        @if ($puedeGuardarRecepcion)
            <button type="submit" class="btn-verde btn-accion-rh">Guardar ajustes de Recepción</button>
        @endif
    </form>
    {{-- ADR-0010: las esperas (autorización de visitas, «Que pase» de RR. HH., recordatorio y escalamiento) viven en
         Responsables por departamento; los criterios de entrevista, en Candidatos → Criterios de entrevista --}}
    <p class="small text-muted mt-3 mb-0"><i class="bi bi-signpost me-1" aria-hidden="true"></i>Otros ajustes:
        @can('autorizaciones.configurar')
            <a href="{{ route('autorizaciones.responsables', ['desde' => 'recepcion']) }}">Responsables por departamento y esperas</a> (quién autoriza, si la caseta espera el «Que pase», recordatorio y escalamiento)
        @endcan
        @can('candidatos.configurar')
            · <a href="{{ route('candidatos.criterios') }}">Criterios de entrevista</a>
        @endcan
    </p>
</section>
