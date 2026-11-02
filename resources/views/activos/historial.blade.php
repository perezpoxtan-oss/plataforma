{{--
    C-45 (DD-19): «Entregas y recepciones» de una llave, un gafete, un equipo
    o una credencial. Variables: $tipoNombre, $titulo, $detalle, $ficha, $movimientos.
--}}
@extends('layouts.app')

@section('titulo', 'Entregas y recepciones · '.$titulo)

@section('contenido')
<div class="pantalla-historial-activo">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-3">
        <div>
            <p class="acciones-lector-tipo mb-1"><i class="bi bi-arrow-left-right me-1" aria-hidden="true"></i>{{ $tipoNombre }} · Entregas y recepciones</p>
            <h1 class="h4 mb-0">{{ $titulo }}</h1>
            @if ($detalle)<p class="text-muted small mb-0">{{ $detalle }}</p>@endif
        </div>
        <a href="{{ $ficha }}" class="btn-cancelar"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver a la ficha</a>
    </div>

    <section class="tarjeta" aria-label="Movimientos">
        @if ($movimientos->isEmpty())
            <p class="text-muted small m-0">Todavía no hay entregas ni recepciones registradas. Aparecen aquí desde que se usa el historial único; lo anterior sigue en su módulo (Préstamo de llaves, Bitácora de accesos o Responsivas).</p>
        @else
            <div class="tabla-scroll">
                <table class="table table-sm align-middle mb-0 bg-white">
                    <thead>
                        <tr><th scope="col">Cuándo</th><th scope="col">Movimiento</th><th scope="col">Quién</th><th scope="col">Detalle</th><th scope="col">Registró</th></tr>
                    </thead>
                    <tbody>
                        @foreach ($movimientos as $m)
                            <tr class="movimiento-{{ $m->accion }} {{ $m->resultado ? 'resultado-'.$m->resultado : '' }}">
                                <td>@fecha($m->created_at)</td>
                                <td><strong>{{ $m->textoAccion() }}</strong></td>
                                <td>{{ $m->quien ?? '—' }}</td>
                                <td>{{ $m->detalle ?? '' }}</td>
                                <td>{{ $m->autor?->name ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
@endsection
