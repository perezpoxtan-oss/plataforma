{{-- Editar y desactivar/reactivar un espacio. Parámetros: $e (espacio), $dialogo (id del diálogo de edición), $valores (array), $puede --}}
<div class="d-flex gap-1">
    @if ($puede['editar'] && isset($dialogo))
        <button type="button" class="btn-icono editar" title="Editar" aria-label="Editar {{ $e->nombre }}"
                data-accion="editar-registro" data-dialogo="{{ $dialogo }}"
                data-url="{{ route('espacios.update', $e->id) }}" data-id="{{ $e->id }}" data-valores="{{ json_encode($valores) }}">
            <i class="bi bi-pencil" aria-hidden="true"></i>
        </button>
    @endif
    @if ($puede['estado'])
        <form action="{{ route('espacios.estado', $e->id) }}" method="POST" class="m-0"
              data-confirmar="{{ $e->activo ? '¿Desactivar «'.$e->nombre.'»? También se desactiva todo lo que tiene dentro. Podrás reactivarlo con el mismo botón.' : '¿Reactivar «'.$e->nombre.'» y todo lo que tiene dentro?' }}">
            @csrf
            @method('PATCH')
            <input type="hidden" name="activo" value="{{ $e->activo ? 0 : 1 }}">
            @if ($e->activo)
                <button type="submit" class="btn-icono eliminar" title="Desactivar" aria-label="Desactivar {{ $e->nombre }}"><i class="bi bi-slash-circle" aria-hidden="true"></i></button>
            @else
                <button type="submit" class="btn-icono reactivar" title="Reactivar" aria-label="Reactivar {{ $e->nombre }}"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></button>
            @endif
        </form>
    @endif
</div>
