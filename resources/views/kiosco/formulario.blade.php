@extends('kiosco.plantilla')

@section('titulo', 'Solicitud de empleo')

@section('contenido')
<section class="tarjeta kiosco-tarjeta">
    <p class="kiosco-saludo">Hola, <strong>{{ $c->nombre_completo }}</strong></p>
    <p class="small text-muted">Llena tu solicitud para <strong>{{ $empresa }}</strong>. Te toma unos minutos; lo que no sepas, déjalo en blanco. Lo obligatorio lleva <strong>*</strong>. Al final firma con el dedo y toca <strong>Enviar mi solicitud</strong>.</p>

    <form action="{{ route('kiosco.guardar', $token) }}" method="POST" enctype="multipart/form-data" autocomplete="on" data-form-cv>
        @csrf
        @if ($errors->any())
            <div class="alert alert-danger small py-2 px-3" role="alert"><i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>Revisa:
                <ul class="mb-0 ps-3">@foreach ($errors->all() as $m)<li>{{ $m }}</li>@endforeach</ul>
                <span class="d-block mt-1">Si habías elegido archivos, vuelve a elegirlos.</span>
            </div>
        @endif

        @include('rh.candidatos._cv', ['id' => 'kiosco_cv', 'kiosco' => true, 'conOld' => $errors->any() || old('nombre_completo') !== null, 'pidePrivacidad' => true])

        <fieldset class="bloque-cv">
            <legend><i class="bi bi-paperclip me-2" aria-hidden="true"></i>Documentos (opcionales)</legend>
            <p class="small text-muted">Puedes tomarles foto con tu celular. PDF, JPG o PNG de hasta 5 MB.</p>
            @foreach (['cv' => 'Tu CV (si lo traes)', 'ine' => 'Identificación (INE)', 'comprobante' => 'Comprobante de domicilio'] as $campo => $texto)
                <label class="campo-etiqueta" for="kiosco_{{ $campo }}">{{ $texto }}</label>
                <input type="file" id="kiosco_{{ $campo }}" name="{{ $campo }}" class="campo" accept="application/pdf,image/jpeg,image/png" @if ($campo !== 'cv') capture="environment" @endif>
            @endforeach
        </fieldset>

        <button type="submit" class="btn-verde kiosco-boton w-100"><i class="bi bi-send-fill me-2" aria-hidden="true"></i>Enviar mi solicitud</button>
        <p class="texto-traza text-center mt-2 mb-0">Este enlace vence en {{ max(1, (int) now()->diffInMinutes($enlace->expira_en)) }} minutos y solo sirve para tu solicitud.</p>
    </form>
</section>
@endsection
