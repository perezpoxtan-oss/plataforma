@extends('kiosco.plantilla')

@section('titulo', 'Solicitud de empleo')

@section('contenido')
<section class="tarjeta kiosco-tarjeta text-center">
    <i class="bi bi-phone kiosco-icono" aria-hidden="true"></i>
    <h1 class="kiosco-h1">Escribe tu código</h1>
    <p>Es el código de 6 letras y números que te dieron en recepción (también aparece en la pantalla, debajo del QR).</p>
    <form action="{{ route('kiosco.canjear') }}" method="POST" autocomplete="off">
        @csrf
        @error('codigo')
            <div class="alert alert-danger small py-2" role="alert">{{ $message }}</div>
        @enderror
        <label class="visually-hidden" for="codigo">Código</label>
        <input type="text" id="codigo" name="codigo" class="campo campo-codigo-kiosco" maxlength="6" minlength="6" required autocapitalize="characters"
               spellcheck="false" value="{{ old('codigo', $codigo) }}" placeholder="ABC123">
        <button type="submit" class="btn-verde kiosco-boton">Continuar <i class="bi bi-arrow-right ms-1" aria-hidden="true"></i></button>
    </form>
</section>
@endsection
