{{--
    C-13 (DD-07): en la ficha de una persona, un vehículo o una empresa externa,
    «Restringir acceso» (motivo y vigencia) separado de «Dar de baja».
    Parámetros: registro, padron (personas | vehiculos | proveedores), nombre, puede (bool).
    La insignia «Acceso restringido…» la pinta cada ficha en su cuerpo.
--}}
@if ($puede)
    @if ($registro->restringido_en !== null)
        <form action="{{ route('restriccion.guardar', [$padron, $registro->id]) }}" method="POST" class="m-0" data-confirmar="¿Quitar la restricción de «{{ $nombre }}»? La caseta lo podrá registrar otra vez.">
            @csrf
            @method('PATCH')
            <input type="hidden" name="accion" value="quitar">
            <button type="submit" class="btn-icono reactivar" title="Quitar restricción" aria-label="Quitar la restricción de {{ $nombre }}"><i class="bi bi-shield-check" aria-hidden="true"></i></button>
        </form>
    @else
        <button type="button" class="btn-icono eliminar" title="Restringir acceso" aria-label="Restringir el acceso de {{ $nombre }}"
                data-accion="editar-registro" data-dialogo="dialogoRestringirAcceso" data-titulo-registro="{{ $nombre }}"
                data-url="{{ route('restriccion.guardar', [$padron, $registro->id]) }}" data-id="{{ $registro->id }}" data-valores='{"motivo":"","hasta":""}'>
            <i class="bi bi-shield-x" aria-hidden="true"></i>
        </button>
    @endif
@endif
