@extends('empleos.plantilla')

@section('titulo', 'Postularme: '.$v->titulo)
@section('sin-indexar', '1')

@section('contenido')
{{-- Postulación corta (ADR-0010, criterio UX 8): nombre, teléfono, correo, sede y CV opcional. La solicitud completa
     se pide después con el enlace que recibe por correo o con el kiosco en recepción. --}}
<a href="{{ route('empleos.show', [$empresa->bolsa_slug, $v->codigo]) }}" class="enlace-volver"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver a la vacante</a>
<section class="tarjeta kiosco-tarjeta">
    <p class="kiosco-saludo">Postularme a <strong>{{ $v->titulo }}</strong></p>
    <p class="small text-muted">Solo te pedimos lo indispensable; toma un minuto. Después te mandaremos a tu correo un enlace para llenar tu solicitud completa (también puedes llenarla cuando vayas a tu entrevista).</p>

    <form action="{{ route('empleos.guardar', [$empresa->bolsa_slug, $v->codigo]) }}" method="POST" enctype="multipart/form-data" autocomplete="on">
        @csrf
        @if ($errors->any())
            <div class="alert alert-danger small py-2 px-3" role="alert"><i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>Revisa:
                <ul class="mb-0 ps-3">@foreach ($errors->all() as $m)<li>{{ $m }}</li>@endforeach</ul>
                <span class="d-block mt-1">Si habías elegido tu CV, vuelve a elegirlo.</span>
            </div>
        @endif

        {{-- Campo trampa para robots: las personas no lo ven ni lo llenan --}}
        <div class="campo-trampa" aria-hidden="true">
            <label for="sitio_web">No llenes este campo</label>
            <input type="text" id="sitio_web" name="{{ \App\Http\Controllers\Publico\EmpleosController::TRAMPA }}" tabindex="-1" autocomplete="off" value="">
        </div>

        @if ($sedes->count() > 1)
            <fieldset class="bloque-cv">
                <legend><i class="bi bi-geo-alt me-2" aria-hidden="true"></i>¿En qué sede te interesa trabajar? *</legend>
                <div class="opciones-cv mt-2">
                    @foreach ($sedes as $s)
                        <label class="opcion-cv"><input type="radio" name="sede_id" value="{{ $s->id }}" required @checked((string) old('sede_id') === (string) $s->id)><span>{{ $s->nombre }}</span></label>
                    @endforeach
                </div>
            </fieldset>
        @endif

        <fieldset class="bloque-cv">
            <legend><i class="bi bi-person me-2" aria-hidden="true"></i>Tus datos</legend>
            <label class="campo-etiqueta" for="web_nombre">Nombre completo *</label>
            <input type="text" id="web_nombre" name="nombre_completo" class="campo" maxlength="150" required minlength="5" autocomplete="name" value="{{ old('nombre_completo') }}" placeholder="Nombre y apellidos">
            <div class="rejilla-cv">
                <div>
                    <label class="campo-etiqueta" for="web_telefono">Teléfono celular *</label>
                    <input type="tel" id="web_telefono" name="telefono" class="campo" maxlength="20" required inputmode="numeric" autocomplete="tel" value="{{ old('telefono') }}" placeholder="10 dígitos">
                </div>
                <div>
                    <label class="campo-etiqueta" for="web_correo">Correo *</label>
                    <input type="email" id="web_correo" name="correo" class="campo" maxlength="150" required autocomplete="email" value="{{ old('correo') }}" placeholder="tu@correo.com">
                    <p class="campo-ayuda mt-0">Ahí te llega el enlace para completar tu solicitud.</p>
                </div>
            </div>
        </fieldset>

        <fieldset class="bloque-cv">
            <legend><i class="bi bi-paperclip me-2" aria-hidden="true"></i>Tu CV (opcional)</legend>
            <p class="small text-muted">PDF o foto (JPG o PNG) de hasta 5 MB.</p>
            <label class="campo-etiqueta" for="web_cv_archivo">Archivo</label>
            <input type="file" id="web_cv_archivo" name="cv" class="campo" accept="application/pdf,image/jpeg,image/png">
        </fieldset>

        <div class="aviso-privacidad-cv">
            <details class="detalle-privacidad">
                <summary><i class="bi bi-shield-lock me-1" aria-hidden="true"></i>Leer el aviso de privacidad</summary>
                <div class="texto-privacidad">{{ $privacidad }}</div>
                @isset($arcoUrl)
                    <p class="small mt-2 mb-0"><a href="{{ $arcoUrl }}" target="_blank" rel="noopener">Consultar el aviso completo y ejercer tus derechos ARCO</a> (acceso, rectificación, cancelación u oposición).</p>
                @endisset
            </details>
            <label class="casilla-privacidad">
                <input type="checkbox" name="acepta_privacidad" value="1" required @checked(old('acepta_privacidad'))>
                <span><strong>Acepto el aviso de privacidad</strong> y autorizo el uso de mis datos para este proceso de selección.</span>
            </label>
        </div>

        <button type="submit" class="btn-verde kiosco-boton w-100"><i class="bi bi-send-fill me-2" aria-hidden="true"></i>Enviar mi postulación</button>
    </form>
</section>
@endsection
