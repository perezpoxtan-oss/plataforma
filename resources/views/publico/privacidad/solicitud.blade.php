@extends('publico.privacidad.plantilla')

@section('titulo', 'Derechos ARCO')

@section('contenido')
<h1 class="acceso-titulo h3">Tus derechos sobre tus datos</h1>
<p class="acceso-subtitulo">{{ $empresa?->nombre_comercial ?? $identidad->get('nombre') }}</p>

@if (session('enviada'))
    <div class="alert alert-success acceso-aviso text-start" role="status">
        <i class="bi bi-envelope-check-fill me-2" aria-hidden="true"></i>
        @if (session('sin_correo'))
            Recibimos tu solicitud. Por ahora no podemos confirmarla por correo: <strong>acude con una identificación oficial</strong> para confirmar que eres tú; desde ese día tenemos 20 días hábiles para responderte.
        @else
            Recibimos tu solicitud. <strong>Revisa tu correo y toca «Confirmar mi solicitud»</strong> (el enlace sirve 72 horas). Desde que la confirmes tenemos 20 días hábiles para responderte.
        @endif
    </div>
    @if ($codigo)
        <p class="acceso-enlace-ayuda"><a href="{{ route('privacidad.aviso', $codigo) }}"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Volver al aviso de privacidad</a></p>
    @endif
@else
    <p class="acceso-texto text-start">Puedes pedir <strong>Acceso</strong> (saber qué datos tenemos de ti), <strong>Rectificación</strong> (corregirlos), <strong>Cancelación</strong> (que los borremos) u <strong>Oposición</strong> (que dejemos de usarlos para algo). Te pediremos confirmar tu correo.</p>

    @if ($errors->any())
        <div class="alert alert-danger acceso-aviso text-start" role="alert">
            <i class="bi bi-exclamation-triangle-fill me-2" aria-hidden="true"></i> Revisa:
            <ul class="mb-0 ps-3">@foreach ($errors->all() as $m)<li>{{ $m }}</li>@endforeach</ul>
        </div>
    @endif

    <form action="{{ $codigo ? route('privacidad.empresa.solicitud.guardar', $codigo) : route('privacidad.solicitud.guardar') }}" method="POST" class="text-start" novalidate>
        @csrf
        {{-- Campo trampa para robots: las personas no lo ven ni lo llenan --}}
        <div class="campo-trampa" aria-hidden="true">
            <label for="sitio_web">No llenes este campo</label>
            <input type="text" id="sitio_web" name="{{ \App\Http\Controllers\Publico\PrivacidadController::TRAMPA }}" tabindex="-1" autocomplete="off" value="">
        </div>

        <fieldset class="mb-3">
            <legend class="small fw-bold">¿Qué quieres hacer? *</legend>
            @foreach (\App\Models\SolicitudArco::DERECHOS as $clave => $texto)
                <label class="acceso-recordar mb-1"><input type="radio" name="derecho" value="{{ $clave }}" required @checked(old('derecho') === $clave)><span>{{ $texto }}</span></label>
            @endforeach
        </fieldset>

        <div class="acceso-campo mb-3">
            <label for="nombre">Nombre completo *</label>
            <div class="acceso-entrada"><input type="text" id="nombre" name="nombre" value="{{ old('nombre') }}" maxlength="150" required autocomplete="name"><i class="bi bi-person" aria-hidden="true"></i></div>
        </div>
        <div class="acceso-campo mb-3">
            <label for="correo">Correo *</label>
            <div class="acceso-entrada"><input type="email" id="correo" name="correo" value="{{ old('correo') }}" maxlength="150" required autocomplete="email"><i class="bi bi-envelope" aria-hidden="true"></i></div>
        </div>
        <div class="acceso-campo mb-3">
            <label for="telefono">Teléfono (opcional)</label>
            <div class="acceso-entrada"><input type="tel" id="telefono" name="telefono" value="{{ old('telefono') }}" maxlength="20" autocomplete="tel"><i class="bi bi-telephone" aria-hidden="true"></i></div>
        </div>
        <div class="acceso-campo mb-3">
            <label for="relacion">¿Cuál es tu relación con nosotros?</label>
            <select id="relacion" name="relacion" class="form-select">
                <option value="">Elige una opción</option>
                @foreach (\App\Models\SolicitudArco::RELACIONES as $clave => $texto)
                    @continue ($empresa !== null && $clave === 'cliente')
                    <option value="{{ $clave }}" @selected(old('relacion') === $clave)>{{ $texto }}</option>
                @endforeach
            </select>
        </div>
        <div class="acceso-campo mb-3">
            <label for="detalle">Cuéntanos qué necesitas *</label>
            <textarea id="detalle" name="detalle" class="form-control" rows="4" maxlength="3000" required placeholder="Por ejemplo: visité la sede el 3 de marzo y quiero que borren mi foto y mi identificación.">{{ old('detalle') }}</textarea>
        </div>
        <label class="acceso-recordar mb-4"><input type="checkbox" name="acepta" value="1" required @checked(old('acepta'))><span>Los datos son míos (o tengo la representación legal de la persona) y acepto que se usen solo para atender esta solicitud.</span></label>

        <button type="submit" class="acceso-boton">Enviar solicitud <i class="bi bi-send-fill" aria-hidden="true"></i></button>
    </form>
@endif
@endsection
