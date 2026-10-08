@extends('empleos.plantilla')

@section('titulo', 'Postularme: '.$v->titulo)
@section('sin-indexar', '1')

@section('contenido')
<a href="{{ route('empleos.show', [$empresa->bolsa_slug, $v->codigo]) }}" class="enlace-volver"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver a la vacante</a>
<section class="tarjeta kiosco-tarjeta">
    <p class="kiosco-saludo">Solicitud para <strong>{{ $v->titulo }}</strong></p>
    <p class="small text-muted">Te toma unos minutos. Lo obligatorio lleva <strong>*</strong>; lo que no sepas, déjalo en blanco. Al final firma con el dedo y toca <strong>Enviar mi solicitud</strong>.</p>

    <form action="{{ route('empleos.guardar', [$empresa->bolsa_slug, $v->codigo]) }}" method="POST" enctype="multipart/form-data" autocomplete="on" data-form-cv>
        @csrf
        @if ($errors->any())
            <div class="alert alert-danger small py-2 px-3" role="alert"><i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>Revisa:
                <ul class="mb-0 ps-3">@foreach ($errors->all() as $m)<li>{{ $m }}</li>@endforeach</ul>
                <span class="d-block mt-1">Vuelve a firmar y, si habías elegido tu CV, vuelve a elegirlo.</span>
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

        @include('rh.candidatos._cv', ['c' => null, 'id' => 'web_cv', 'kiosco' => true, 'sinPuesto' => true, 'conOld' => $errors->any() || old('nombre') !== null,
            'pidePrivacidad' => true, 'pideFirma' => true, 'medioPorDefecto' => 'bolsa_web', 'puestos' => collect()])

        <fieldset class="bloque-cv">
            <legend><i class="bi bi-paperclip me-2" aria-hidden="true"></i>Tu CV (opcional)</legend>
            <p class="small text-muted">PDF o foto (JPG o PNG) de hasta 5 MB.</p>
            <label class="campo-etiqueta" for="web_cv_archivo">Archivo</label>
            <input type="file" id="web_cv_archivo" name="cv" class="campo" accept="application/pdf,image/jpeg,image/png">
        </fieldset>

        <button type="submit" class="btn-verde kiosco-boton w-100"><i class="bi bi-send-fill me-2" aria-hidden="true"></i>Enviar mi solicitud</button>
    </form>
</section>
@endsection
