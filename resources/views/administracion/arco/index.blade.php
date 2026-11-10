@extends('layouts.app')

@section('titulo', 'Solicitudes ARCO')

@section('contenido')
{{-- Bandeja de solicitudes ARCO (docs/tecnico/privacidad.md): reloj y color del plazo legal de 20 días hábiles --}}
<div class="tema-azul">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    <div class="encabezado-pantalla mb-3">
        <div class="icono"><i class="bi bi-person-vcard text-primary" aria-hidden="true"></i></div>
        <div>
            <h1>Solicitudes ARCO</h1>
            <p>Lo que las personas piden sobre sus datos personales: acceso, rectificación, cancelación u oposición. La ley da <strong>20 días hábiles</strong> para responder desde que confirman su correo.</p>
        </div>
    </div>

    <nav class="d-flex flex-wrap gap-2 mb-3" aria-label="Filtrar solicitudes">
        @foreach (['abiertas' => 'Abiertas', 'atendidas' => 'Respondidas', 'todas' => 'Todas'] as $clave => $texto)
            <a href="{{ route('arco.index', array_filter(['estado' => $clave, 'plataforma' => $plataforma ? 1 : null])) }}" class="chip-config {{ $filtro === $clave ? 'activo' : '' }}" @if ($filtro === $clave) aria-current="page" @endif>{{ $texto }}</a>
        @endforeach
        @if ($esSuperadmin)
            <a href="{{ route('arco.index', array_filter(['estado' => $filtro, 'plataforma' => $plataforma ? null : 1])) }}" class="chip-config {{ $plataforma ? 'activo' : '' }}">
                <i class="bi bi-globe me-1" aria-hidden="true"></i>De la página de la plataforma @if ($deLaPlataforma > 0)<strong>({{ $deLaPlataforma }})</strong>@endif
            </a>
        @endif
    </nav>

    @if ($solicitudes->isEmpty())
        <div class="tarjeta p-4 text-center text-muted">
            <i class="bi bi-inbox fs-2 d-block mb-2" aria-hidden="true"></i>
            No hay solicitudes {{ $filtro === 'abiertas' ? 'abiertas' : '' }}. Cuando alguien llene el formulario de derechos ARCO y confirme su correo, aparecerá aquí, en la campana y en Mis pendientes.
        </div>
    @else
        <ul class="lista-arco">
            @foreach ($solicitudes as $s)
                <li>
                    <div>
                        <a href="{{ route('arco.show', $s->id) }}" class="fw-bold">{{ $s->folio }} · {{ $s->etiquetaDerecho() }}</a>
                        <div class="small text-muted">{{ $s->nombre }} · recibida @fecha($s->created_at, 'd/m/Y'){{ $s->empresa_id === null ? ' · sin empresa (canalízala)' : '' }}</div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        @include('administracion.arco._reloj', ['s' => $s, 'recordar' => $recordar])
                        <a href="{{ route('arco.show', $s->id) }}" class="btn btn-sm btn-outline-primary" style="min-height:40px;border-radius:10px;">{{ $s->abierta() ? 'Atender' : 'Ver' }}</a>
                    </div>
                </li>
            @endforeach
        </ul>
        {{ $solicitudes->links() }}
    @endif
</div>
@endsection
