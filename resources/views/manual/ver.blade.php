@extends('layouts.app')

@section('titulo', $pagina->titulo.' · Manual')

@section('contenido')
<div class="tema-azul pantalla-manual">
    <nav class="manual-migas no-imprimir" aria-label="Ubicación">
        <a href="{{ route('manual.index') }}" class="manual-migas-regresar"><i class="bi bi-arrow-left" aria-hidden="true"></i> Manual</a>
        <span aria-hidden="true">·</span>
        <span>{{ $pagina->seccion }}</span>
    </nav>

    <div class="manual-cabeza">
        <div class="encabezado-pantalla m-0">
            <div class="icono"><i class="bi {{ $icono }} text-primary" aria-hidden="true"></i></div>
            <div>
                <h1>{{ $pagina->titulo }}</h1>
                <p>{{ $pagina->resumen }}</p>
            </div>
        </div>
        <button type="button" class="btn btn-outline-secondary manual-imprimir no-imprimir" data-accion="imprimir">
            <i class="bi bi-printer" aria-hidden="true"></i> Imprimir
        </button>
    </div>

    <div class="manual-cuerpo {{ count($apartados) > 1 ? 'con-indice' : '' }}">
        @if (count($apartados) > 1)
            <aside class="manual-indice no-imprimir">
                <details class="manual-indice-caja" open data-manual-indice>
                    <summary><i class="bi bi-list-ol" aria-hidden="true"></i> En esta página</summary>
                    <ol>
                        @foreach ($apartados as $apartado)
                            <li><a href="#{{ $apartado['id'] }}">{{ $apartado['texto'] }}</a></li>
                        @endforeach
                    </ol>
                </details>
            </aside>
        @endif

        {{-- HTML generado en el servidor por App\Services\Manual\Manual (sin HTML crudo: todo el texto va escapado) --}}
        <article class="tarjeta manual-contenido">
            {!! $html !!}
        </article>
    </div>

    @if ($anterior || $siguiente)
        <nav class="manual-vecinas no-imprimir" aria-label="Otras páginas de {{ $pagina->seccion }}">
            @if ($anterior)
                <a href="{{ route('manual.ver', $anterior->slug) }}" class="tarjeta manual-vecina">
                    <small><i class="bi bi-arrow-left" aria-hidden="true"></i> Anterior</small><span>{{ $anterior->titulo }}</span>
                </a>
            @endif
            @if ($siguiente)
                <a href="{{ route('manual.ver', $siguiente->slug) }}" class="tarjeta manual-vecina siguiente">
                    <small>Siguiente <i class="bi bi-arrow-right" aria-hidden="true"></i></small><span>{{ $siguiente->titulo }}</span>
                </a>
            @endif
        </nav>
    @endif
</div>
@endsection
