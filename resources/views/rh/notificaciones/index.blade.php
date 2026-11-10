@extends('layouts.app')

@section('titulo', 'Notificaciones')

@section('contenido')
<div class="pantalla-notificaciones">
    @include('administracion.partes.avisos')

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
        <div class="encabezado-pantalla m-0">
            <div class="icono"><i class="bi bi-bell-fill text-primary" aria-hidden="true"></i></div>
            <div>
                <h1>Notificaciones</h1>
                <p>Avisos para ti: candidatos que llegan, visitas que esperan tu autorización y respuestas.</p>
            </div>
        </div>
        @if ($noLeidas > 0)
            <form action="{{ route('notificaciones.leer-todas') }}" method="POST" class="m-0">
                @csrf
                <button type="submit" class="btn-azul btn-accion-rh"><i class="bi bi-check2-all me-1" aria-hidden="true"></i>Marcar todas como leídas</button>
            </form>
        @endif
    </div>

    <nav class="pildoras-tipo" aria-label="Filtrar notificaciones">
        <a href="{{ route('notificaciones.index') }}" class="btn-pill-tipo {{ $filtro === 'todas' ? 'active' : '' }}" @if ($filtro === 'todas') aria-current="page" @endif>Todas</a>
        <a href="{{ route('notificaciones.index', ['filtro' => 'no_leidas']) }}" class="btn-pill-tipo {{ $filtro === 'no_leidas' ? 'active' : '' }}" @if ($filtro === 'no_leidas') aria-current="page" @endif>Sin leer ({{ $noLeidas }})</a>
    </nav>

    @if ($lista === null || $lista->isEmpty())
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-bell-slash" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">{{ $filtro === 'no_leidas' ? 'No tienes notificaciones sin leer.' : 'Todavía no tienes notificaciones.' }}</p>
        </div>
    @else
        <ul class="lista-notificaciones">
            @foreach ($lista as $n)
                <li class="notificacion-fila nivel-{{ $n->nivel }} {{ $n->leida_en ? 'leida' : 'no-leida' }}">
                    <span class="notificacion-icono" aria-hidden="true"><i class="bi {{ $n->icono }}"></i></span>
                    <div class="notificacion-cuerpo">
                        <form action="{{ route('notificaciones.abrir', $n->id) }}" method="POST" class="m-0">
                            @csrf
                            <button type="submit" class="notificacion-abrir">
                                <strong>{{ $n->titulo }}</strong>
                                @if ($n->texto)<span>{{ $n->texto }}</span>@endif
                                <small>@fecha($n->created_at){{ $n->leida_en ? '' : ' · Sin leer' }}</small>
                            </button>
                        </form>
                        @if (! empty($n->acciones))
                            <div class="notificacion-acciones">
                                @foreach ($n->acciones as $a)
                                    @continue (! is_array($a) || empty($a['url']))
                                    <form action="{{ $a['url'] }}" method="POST" class="m-0" @if (($a['estilo'] ?? '') === 'rechazar') data-confirmar="¿Rechazar? La caseta y Recursos Humanos verán tu respuesta." @endif>
                                        @csrf
                                        @foreach ((array) ($a['campos'] ?? []) as $campo => $valor)
                                            <input type="hidden" name="{{ $campo }}" value="{{ $valor }}">
                                        @endforeach
                                        <button type="submit" class="btn-respuesta {{ ($a['estilo'] ?? '') === 'rechazar' ? 'rechazar' : 'aceptar' }}">{{ $a['etiqueta'] }}</button>
                                    </form>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
        <div class="mt-4">{{ $lista->onEachSide(1)->links('pagination::bootstrap-5') }}</div>
    @endif
</div>
@endsection
