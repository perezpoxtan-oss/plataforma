@extends('layouts.app')

@section('titulo', 'Manual')

@section('contenido')
<div class="tema-azul pantalla-manual">
    <div class="encabezado-pantalla mb-3">
        <div class="icono"><i class="bi bi-question-circle text-primary" aria-hidden="true"></i></div>
        <div>
            <h1>Manual</h1>
            <p>Instrucciones paso a paso de las pantallas que puedes usar. Solo ves las páginas de lo que te toca.</p>
        </div>
    </div>

    <form method="GET" action="{{ route('manual.index') }}" class="manual-buscador" role="search">
        <div class="buscador">
            <i class="bi bi-search" aria-hidden="true"></i>
            <input type="search" name="q" value="{{ $buscar }}" placeholder="¿Qué quieres hacer? Ej.: prestar una llave" aria-label="Buscar en el manual"
                   autocomplete="off" maxlength="80" data-manual-buscar>
        </div>
        <button type="submit" class="btn btn-primary manual-buscador-boton">Buscar</button>
    </form>

    @if ($grupos === [])
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-journal-x" aria-hidden="true"></i></div>
            @if ($buscar !== '')
                <p class="text-muted small m-0">No encontramos páginas con «{{ $buscar }}». Prueba con otra palabra o <a href="{{ route('manual.index') }}">ve todo el manual</a>.</p>
            @else
                <p class="text-muted small m-0">Todavía no hay páginas del manual para tu usuario. Pide ayuda a tu administrador.</p>
            @endif
        </div>
    @endif

    @foreach ($grupos as $seccion => $paginas)
        <section class="manual-seccion" data-manual-seccion aria-labelledby="manual-seccion-{{ $loop->index }}">
            <h2 class="manual-seccion-titulo" id="manual-seccion-{{ $loop->index }}">
                <i class="bi {{ $iconos[$seccion] ?? 'bi-journal-text' }}" aria-hidden="true"></i> {{ $seccion }}
            </h2>
            <div class="manual-lista">
                @foreach ($paginas as $pagina)
                    <a href="{{ route('manual.ver', $pagina->slug) }}" class="tarjeta manual-ficha" data-manual-pagina data-texto="{{ $pagina->titulo }} {{ $pagina->resumen }}">
                        <span class="manual-ficha-titulo">{{ $pagina->titulo }}</span>
                        <span class="manual-ficha-resumen">{{ $pagina->resumen }}</span>
                        <i class="bi bi-chevron-right manual-ficha-flecha" aria-hidden="true"></i>
                    </a>
                @endforeach
            </div>
        </section>
    @endforeach

    <div class="tarjeta estado-vacio" data-manual-sin-resultados hidden>
        <div class="icono"><i class="bi bi-search" aria-hidden="true"></i></div>
        <p class="text-muted small m-0">No encontramos páginas con esas palabras. Prueba con otra palabra (por ejemplo «llave», «gafete» o «firma»).</p>
    </div>

    @php
        $correoSoporte = $identidad->get('correo_soporte');
        $telefonoSoporte = $identidad->get('telefono_soporte');
    @endphp
    <div class="manual-ayuda">
        <i class="bi bi-life-preserver" aria-hidden="true"></i>
        <div>
            <strong>¿No encuentras lo que buscas?</strong>
            @if ($correoSoporte || $telefonoSoporte)
                <span>Pregunta a tu supervisor o a tu administrador de la plataforma, o escribe a soporte:
                    @if ($correoSoporte)
                        <span class="manual-ayuda-dato">{{ $correoSoporte }}</span>
                    @endif
                    @if ($telefonoSoporte)
                        <span class="manual-ayuda-dato">{{ $telefonoSoporte }}</span>
                    @endif
                </span>
            @else
                <span>Pregunta a tu supervisor o a tu administrador de la plataforma.</span>
            @endif
        </div>
    </div>
</div>
@endsection
