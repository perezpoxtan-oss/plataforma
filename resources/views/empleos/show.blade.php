@extends('empleos.plantilla')
@use('App\Models\Vacante')
@use('App\Models\Candidato')

@section('titulo', $v->titulo)

@section('contenido')
<a href="{{ route('empleos.index', $empresa->bolsa_slug) }}" class="enlace-volver"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Todas las vacantes</a>
<article class="tarjeta kiosco-tarjeta bolsa-detalle">
    <h1 class="kiosco-h1">{{ $v->titulo }}</h1>
    <div class="datos-candidato mb-3">
        <span><i class="bi bi-geo-alt" aria-hidden="true"></i> {{ $v->sedesTexto() }}</span>
        @if ($v->departamento)<span><i class="bi bi-diagram-2" aria-hidden="true"></i> {{ $v->departamento->nombre }}</span>@endif
        <span><i class="bi bi-people" aria-hidden="true"></i> {{ $v->plazas }} plaza{{ $v->plazas !== 1 ? 's' : '' }}</span>
    </div>
    <dl class="cv-datos">
        <dt>Sueldo</dt><dd>{{ $v->sueldoTexto() }}</dd>
        @if ($v->tipo_contrato)<dt>Contrato</dt><dd>{{ Vacante::CONTRATOS[$v->tipo_contrato] ?? '' }}</dd>@endif
        @if ($v->jornada)<dt>Jornada</dt><dd>{{ Vacante::JORNADAS[$v->jornada] ?? '' }}</dd>@endif
        @if ($v->turno)<dt>Turno</dt><dd>{{ $v->turno->nombre }} ({{ $v->turno->inicio() }} a {{ $v->turno->fin() }})</dd>@endif
        @if ($v->horario)<dt>Horario</dt><dd>{{ $v->horario }}</dd>@endif
        @if ($v->escolaridad_minima)<dt>Escolaridad</dt><dd>{{ Candidato::ESCOLARIDAD[$v->escolaridad_minima] ?? '' }} o más</dd>@endif
        @if ($v->experiencia)<dt>Experiencia</dt><dd>{{ $v->experiencia }}</dd>@endif
        @if ($v->fecha_cierre)<dt>Recibimos solicitudes hasta</dt><dd>{{ $v->fecha_cierre->format('d/m/Y') }}</dd>@endif
    </dl>
    @if ($v->descripcion)
        <h2 class="subtitulo-cv">¿Qué harás?</h2>
        <p class="bolsa-descripcion">{{ $v->descripcion }}</p>
    @endif
    @if ($v->listaRequisitos() !== [])
        <h2 class="subtitulo-cv">Requisitos</h2>
        <ul class="bolsa-lista-puntos">@foreach ($v->listaRequisitos() as $r)<li>{{ $r }}</li>@endforeach</ul>
    @endif
    @if ($v->listaPrestaciones() !== [])
        <h2 class="subtitulo-cv">Te ofrecemos</h2>
        <ul class="bolsa-lista-puntos">@foreach ($v->listaPrestaciones() as $p)<li>{{ $p }}</li>@endforeach</ul>
    @endif
    @if ($v->contacto_nombre || $v->contacto_telefono || $v->contacto_correo)
        <p class="small text-muted mt-3 mb-0"><i class="bi bi-telephone me-1" aria-hidden="true"></i>Informes: {{ collect([$v->contacto_nombre, $v->contacto_telefono, $v->contacto_correo])->filter()->join(' · ') }}</p>
    @endif
    <a href="{{ route('empleos.postular', [$empresa->bolsa_slug, $v->codigo]) }}" class="btn-verde kiosco-boton w-100 mt-3"><i class="bi bi-send-fill me-2" aria-hidden="true"></i>Postularme</a>
    <button type="button" class="btn-secundario-rh w-100 mt-2" data-copiar-enlace="{{ route('empleos.show', [$empresa->bolsa_slug, $v->codigo]) }}"><i class="bi bi-share me-1" aria-hidden="true"></i>Copiar enlace para compartir</button>
</article>
@endsection
