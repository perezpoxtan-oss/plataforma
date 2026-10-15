@extends('publico.planes.plantilla')

@section('titulo', $prueba ? 'Probar gratis '.$ajustes['dias_prueba'].' días' : 'Contratar o solicitar información')
@section('sin-indexar', '1')

@section('contenido')
<div class="lp-envoltura lp-pagina-titulo">
    @if ($prueba)
        <h1>Probar gratis {{ $ajustes['dias_prueba'] }} días</h1>
        <p>Elige el plan que quieres probar y déjanos tus datos. Revisamos tu solicitud y te enviamos por correo el acceso para el Administrador de tu empresa. No pagas nada durante la prueba.
            <a href="{{ route('planes.contratar', array_filter(['plan' => $eleccion['plan_id'], 'sedes' => $eleccion['sedes'], 'periodicidad' => $eleccion['periodicidad']])) }}">Prefiero contratar</a></p>
    @else
        <h1>Contratar o solicitar información</h1>
        <p>Déjanos tus datos y lo que te interesa. Te contactamos para confirmar tu plan y la forma de pago; todavía no se cobra nada en línea.
            @if ($ajustes['prueba_habilitada'])<a href="{{ route('planes.contratar', ['prueba' => 1]) }}">Prefiero probar gratis {{ $ajustes['dias_prueba'] }} días</a>@endif</p>
    @endif
</div>
<section class="lp-seccion">
    <div class="lp-envoltura">
        <form action="{{ route('planes.guardar') }}" method="POST" class="lp-calculo" data-calculadora data-catalogo="{{ json_encode($calculadora) }}"
              data-promocion="{{ $promocion ? json_encode($promocion->paraNavegador()) : '' }}" novalidate>
            @csrf
            <input type="hidden" name="tipo" value="{{ old('tipo', $prueba ? 'prueba' : 'compra') }}">
            <div>
                @if ($errors->any())
                    <div class="lp-errores" role="alert" tabindex="-1" id="errores">
                        <strong><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i> Revisa lo siguiente:</strong>
                        <ul>@foreach ($errors->all() as $m)<li>{{ $m }}</li>@endforeach</ul>
                    </div>
                @endif

                {{-- Campo trampa para robots: las personas no lo ven ni lo llenan --}}
                <div class="campo-trampa" aria-hidden="true">
                    <label for="sitio_web">No llenes este campo</label>
                    <input type="text" id="sitio_web" name="{{ \App\Http\Controllers\Publico\PlanesController::TRAMPA }}" tabindex="-1" autocomplete="off" value="">
                </div>

                <fieldset class="lp-bloque">
                    <legend>Tus datos</legend>
                    <div class="lp-campos">
                        <div>
                            <label class="lp-etiqueta" for="c-nombre">Nombre completo *</label>
                            <input class="lp-entrada" type="text" id="c-nombre" name="nombre" maxlength="150" required autocomplete="name" value="{{ old('nombre') }}" @error('nombre') aria-invalid="true" @enderror>
                        </div>
                        <div>
                            <label class="lp-etiqueta" for="c-empresa">Empresa</label>
                            <input class="lp-entrada" type="text" id="c-empresa" name="empresa" maxlength="150" autocomplete="organization" value="{{ old('empresa') }}">
                        </div>
                        <div>
                            <label class="lp-etiqueta" for="c-correo">Correo *</label>
                            <input class="lp-entrada" type="email" id="c-correo" name="correo" maxlength="150" required autocomplete="email" value="{{ old('correo') }}" @error('correo') aria-invalid="true" @enderror>
                        </div>
                        <div>
                            <label class="lp-etiqueta" for="c-telefono">Teléfono</label>
                            <input class="lp-entrada" type="tel" id="c-telefono" name="telefono" maxlength="20" inputmode="tel" autocomplete="tel" value="{{ old('telefono') }}" @error('telefono') aria-invalid="true" @enderror>
                        </div>
                    </div>
                </fieldset>

                @include('publico.planes._seleccion', ['prefijo' => 'c', 'campoCodigo' => 'codigo_promocion', 'eleccion' => [
                    'plan_id' => old('plan_id', $eleccion['plan_id']),
                    'sedes' => old('sedes', $eleccion['sedes']),
                    'paquetes' => array_map('intval', (array) old('paquetes', $eleccion['paquetes'])),
                    'periodicidad' => old('periodicidad', $eleccion['periodicidad']),
                    'codigo_promocion' => old('codigo_promocion', $eleccion['codigo_promocion']),
                ]])

                <div class="lp-bloque">
                    <label class="lp-etiqueta" for="c-mensaje">¿Algo más que debamos saber?</label>
                    <textarea class="lp-entrada" id="c-mensaje" name="mensaje" maxlength="1000" placeholder="Por ejemplo: tenemos 2 hoteles y una oficina corporativa">{{ old('mensaje') }}</textarea>
                </div>

                <label class="lp-casilla">
                    <input type="checkbox" name="acepta_privacidad" value="1" required @checked(old('acepta_privacidad'))>
                    <span>Acepto el
                        @if (trim((string) $ajustes['aviso_privacidad']) !== '')
                            <a href="{{ route('planes.privacidad') }}" target="_blank" rel="noopener">aviso de privacidad</a>
                        @else
                            aviso de privacidad
                        @endif
                        y que me contacten sobre esta solicitud. *</span>
                </label>
            </div>
            @include('publico.planes._resumen', ['prefijo' => 'c', 'calculo' => $calculo, 'boton' => $prueba ? 'Solicitar mi prueba gratis' : 'Enviar solicitud', 'prueba' => $prueba])
        </form>
    </div>
</section>
@endsection
