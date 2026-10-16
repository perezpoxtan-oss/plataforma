{{--
    Botones de ícono de una ficha de catálogo de reservas (montajes, tipos de uso, servicios):
    Editar (lápiz, diálogo), Dar de baja / Reactivar y Eliminar (bote de basura).
    Eliminar borra de verdad solo lo que nunca se usó en una reserva ni (los montajes)
    en un área; si ya se usó, el bote explica por qué y ofrece darlo de baja.
    Parámetros: $clave, $item, $valores, $usado (bool)
--}}
<div class="d-flex gap-2">
    @include('componentes.ver-historial', ['registro' => $item::class, 'id' => $item->id, 'nombre' => $item->nombre])
    <button type="button" class="btn-icono editar" title="Editar" aria-label="Editar {{ $item->nombre }}"
            data-accion="editar-registro" data-dialogo="dialogoEditar-{{ $clave }}"
            data-url="{{ route('reservas.configuracion.catalogo.actualizar', [$clave, $item->id]) }}" data-id="{{ $item->id }}" data-valores="{{ json_encode($valores) }}">
        <i class="bi bi-pencil" aria-hidden="true"></i>
    </button>
    <form method="POST" action="{{ route('reservas.configuracion.catalogo.estado', [$clave, $item->id]) }}" class="m-0"
          data-confirmar="{{ $item->activo ? '¿Dar de baja «'.$item->nombre.'»? Ya no se ofrecerá en las reservas nuevas; lo ves con «Mostrar dados de baja» y lo puedes reactivar.' : '¿Reactivar «'.$item->nombre.'»?' }}">
        @csrf
        @method('PATCH')
        <input type="hidden" name="activo" value="{{ $item->activo ? 0 : 1 }}">
        @if ($item->activo)
            <button type="submit" class="btn-icono eliminar" title="Dar de baja" aria-label="Dar de baja {{ $item->nombre }}"><i class="bi bi-slash-circle" aria-hidden="true"></i></button>
        @else
            <button type="submit" class="btn-icono reactivar" title="Reactivar" aria-label="Reactivar {{ $item->nombre }}"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></button>
        @endif
    </form>
    @if (! ($usado ?? false))
        <form method="POST" action="{{ route('reservas.configuracion.catalogo.eliminar', [$clave, $item->id]) }}" class="m-0"
              data-confirmar="¿Eliminar «{{ $item->nombre }}» definitivamente? Nunca se ha usado en una reserva{{ $clave === 'montajes' ? ' ni en un área' : '' }}. No se puede deshacer.">
            @csrf
            @method('DELETE')
            <button type="submit" class="btn-icono eliminar" title="Eliminar" aria-label="Eliminar {{ $item->nombre }}"><i class="bi bi-trash3" aria-hidden="true"></i></button>
        </form>
    @elseif ($item->activo)
        {{-- Ya se usó: el bote explica por qué no se elimina y ofrece darlo de baja --}}
        <form method="POST" action="{{ route('reservas.configuracion.catalogo.estado', [$clave, $item->id]) }}" class="m-0"
              data-confirmar="«{{ $item->nombre }}» ya se usó en reservas{{ $clave === 'montajes' ? ' o en la configuración de un área' : '' }}, así que no se puede eliminar sin perder ese historial. ¿Darlo de baja? Ya no se ofrecerá en las reservas nuevas y lo podrás reactivar.">
            @csrf
            @method('PATCH')
            <input type="hidden" name="activo" value="0">
            <button type="submit" class="btn-icono eliminar" title="Eliminar (ya se usó: se da de baja)" aria-label="Eliminar {{ $item->nombre }} (ya se usó: se da de baja)"><i class="bi bi-trash3" aria-hidden="true"></i></button>
        </form>
    @endif
</div>
