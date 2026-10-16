{{-- Botones de ícono de una ficha de catálogo de reservas: editar (diálogo) y dar de baja / reactivar. $clave, $item, $valores --}}
<div class="d-flex gap-2">
    @include('componentes.ver-historial', ['registro' => $item::class, 'id' => $item->id, 'nombre' => $item->nombre])
    <button type="button" class="btn-icono editar" title="Editar" aria-label="Editar {{ $item->nombre }}"
            data-accion="editar-registro" data-dialogo="dialogoEditar-{{ $clave }}"
            data-url="{{ route('reservas.configuracion.catalogo.actualizar', [$clave, $item->id]) }}" data-id="{{ $item->id }}" data-valores="{{ json_encode($valores) }}">
        <i class="bi bi-pencil-square" aria-hidden="true"></i>
    </button>
    <form method="POST" action="{{ route('reservas.configuracion.catalogo.estado', [$clave, $item->id]) }}" class="m-0"
          data-confirmar="{{ $item->activo ? '¿Dar de baja «'.$item->nombre.'»? Ya no se ofrecerá en las reservas nuevas.' : '¿Reactivar «'.$item->nombre.'»?' }}">
        @csrf
        @method('PATCH')
        <input type="hidden" name="activo" value="{{ $item->activo ? 0 : 1 }}">
        @if ($item->activo)
            <button type="submit" class="btn-icono eliminar" title="Dar de baja" aria-label="Dar de baja {{ $item->nombre }}"><i class="bi bi-slash-circle" aria-hidden="true"></i></button>
        @else
            <button type="submit" class="btn-icono reactivar" title="Reactivar" aria-label="Reactivar {{ $item->nombre }}"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></button>
        @endif
    </form>
</div>
