@extends('layouts.app')

@section('titulo', 'Recepción de RR. HH.')

@section('contenido')
<div class="pantalla-recepcion">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
        <div class="encabezado-pantalla m-0">
            <div class="icono"><i class="bi bi-person-check-fill text-primary" aria-hidden="true"></i></div>
            <div>
                <h1>Recepción</h1>
                <p>Quién llegó a caseta y viene con Recursos Humanos. Se actualiza sola cada 15 segundos.</p>
            </div>
        </div>
        @unless ($sinEmpresa)
            <div class="d-flex flex-wrap gap-2">
                @if ($puede['kiosco'])
                    <a href="{{ route('recepcion.kiosco') }}" class="btn-secundario-rh"><i class="bi bi-tablet me-1" aria-hidden="true"></i>Modo kiosco</a>
                @endif
                <a href="{{ route('recepcion.metricas') }}" class="btn-secundario-rh"><i class="bi bi-bar-chart-line me-1" aria-hidden="true"></i>Tiempos de espera</a>
                @if ($puede['ver'])
                    <a href="{{ route('candidatos.index') }}" class="btn-secundario-rh"><i class="bi bi-person-workspace me-1" aria-hidden="true"></i>Candidatos</a>
                @endif
                @if ($puede['configurar'])
                    <a href="{{ route('recepcion.ajustes') }}" class="btn-secundario-rh"><i class="bi bi-sliders me-1" aria-hidden="true"></i>Ajustes</a>
                @endif
            </div>
        @endunless
    </div>

    @if ($sinEmpresa)
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-buildings" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong> para ver su recepción.</p>
        </div>
    @else
        <div class="contadores-recepcion" data-recepcion-contadores>
            <div class="contador-rh esperando"><strong data-contador="esperando">{{ $contadores['esperando'] }}</strong><span>Esperando</span></div>
            <div class="contador-rh"><strong data-contador="revision">{{ $contadores['revision'] }}</strong><span>Con RR. HH. (revisión o entrevista)</span></div>
            <div class="contador-rh"><strong data-contador="departamento">{{ $contadores['departamento'] }}</strong><span>Entrevista con el departamento</span></div>
            <div class="contador-rh"><strong data-contador="evaluado">{{ $contadores['evaluado'] }}</strong><span>Evaluados por el departamento</span></div>
        </div>

        <div class="panel-recepcion" data-recepcion data-url="{{ route('recepcion.datos') }}">
            <p class="estado-recepcion" data-recepcion-estado role="status"><i class="bi bi-broadcast me-1" aria-hidden="true"></i>En vivo</p>
            <div class="lista-recepcion" data-recepcion-lista>
                @include('rh.recepcion._lista')
            </div>
        </div>
    @endif
</div>
@endsection
