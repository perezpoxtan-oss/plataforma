{{-- Subir / bajar, Editar y Dar de baja / Reactivar de un producto, plan o paquete. $tipo, $r, $primero, $ultimo --}}
<div class="d-flex gap-1 justify-content-end flex-wrap">
    @foreach (['arriba' => ['bi-arrow-up', 'Subir', $primero], 'abajo' => ['bi-arrow-down', 'Bajar', $ultimo]] as $direccion => [$icono, $texto, $deshabilitado])
        <form action="{{ route('catalogo-comercial.mover', [$tipo, $r->id]) }}" method="POST" class="m-0">
            @csrf
            @method('PATCH')
            <input type="hidden" name="direccion" value="{{ $direccion }}">
            <button type="submit" class="btn-icono" title="{{ $texto }} un lugar" aria-label="{{ $texto }} «{{ $r->nombre }}» un lugar" @disabled($deshabilitado)><i class="bi {{ $icono }}" aria-hidden="true"></i></button>
        </form>
    @endforeach
    <a href="{{ route('catalogo-comercial.editar', [$tipo, $r->id]) }}" class="btn-icono editar" title="Editar" aria-label="Editar «{{ $r->nombre }}»"><i class="bi bi-pencil-square" aria-hidden="true"></i></a>
    <form action="{{ route('catalogo-comercial.estado', [$tipo, $r->id]) }}" method="POST" class="m-0"
          data-confirmar="{{ $r->activo ? '¿Dar de baja «'.$r->nombre.'»? Deja de verse en la página pública; las solicitudes que ya llegaron lo conservan. Podrás reactivarlo con el mismo botón.' : '¿Reactivar «'.$r->nombre.'»?' }}">
        @csrf
        @method('PATCH')
        <input type="hidden" name="activo" value="{{ $r->activo ? 0 : 1 }}">
        @if ($r->activo)
            <button type="submit" class="btn-icono eliminar" title="Dar de baja" aria-label="Dar de baja «{{ $r->nombre }}»"><i class="bi bi-slash-circle" aria-hidden="true"></i></button>
        @else
            <button type="submit" class="btn-icono reactivar" title="Reactivar" aria-label="Reactivar «{{ $r->nombre }}»"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></button>
        @endif
    </form>
</div>
