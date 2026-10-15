{{--
    «Restablecer valor original» de una tarjeta de Configuración (petición del
    dueño: un botón por tarjeta). $ajuste: clave de ConfiguracionController::RESTABLECIBLES;
    $que: qué regresa, para la confirmación. Va FUERA del formulario de la tarjeta.
--}}
<form action="{{ route('configuracion.restablecer') }}" method="POST" class="mt-2 mb-0 form-restablecer"
      data-confirmar="¿Regresar {{ $que }} a su valor original? Lo que tu empresa cambió aquí se pierde.">
    @csrf
    @method('PUT')
    <input type="hidden" name="ajuste" value="{{ $ajuste }}">
    <button type="submit" class="btn btn-outline-secondary btn-sm btn-restablecer"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Restablecer valor original</button>
</form>
