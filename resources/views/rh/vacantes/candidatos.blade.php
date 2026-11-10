@extends('layouts.app')

@section('titulo', 'Candidatos de la vacante')

@section('contenido')
@use('App\Models\Candidato')
<div class="pantalla-vacantes">
    @include('administracion.partes.avisos')

    <a href="{{ route('vacantes.index') }}" class="enlace-volver"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Vacantes</a>

    <div class="encabezado-pantalla mb-3">
        <div class="icono"><i class="bi bi-people text-warning" aria-hidden="true"></i></div>
        <div>
            <h1>Candidatos de esta vacante</h1>
            <p><strong>{{ $v->titulo }}</strong>{{ $v->departamento ? ' · '.$v->departamento->nombre : '' }} · {{ $v->sedesTexto() }}</p>
        </div>
    </div>

    <div class="resumen-plazas">
        <div class="contador-rh"><strong>{{ $v->plazas }}</strong><span>Plaza{{ $v->plazas !== 1 ? 's' : '' }}</span></div>
        <div class="contador-rh"><strong>{{ $cubiertas }}</strong><span>Elegidos o contratados</span></div>
        <div class="contador-rh"><strong>{{ $filas->count() }}</strong><span>{{ $verFichas ? 'Postulaciones' : 'Que entrevistaste o te canalizaron' }}</span></div>
    </div>

    @if ($filas->isEmpty())
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-person-x" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">{{ $verFichas ? 'Nadie se ha postulado a esta vacante en tus sedes.' : 'Todavía no te han canalizado candidatos de esta vacante.' }}</p>
        </div>
    @else
        <section class="tarjeta p-0" aria-label="Comparar candidatos">
            <div class="tabla-scroll">
                <table class="tabla-metricas tabla-candidatos-vacante">
                    <caption class="visually-hidden">Candidatos de {{ $v->titulo }}: etapa, promedios y resultado</caption>
                    <thead>
                        <tr>
                            <th scope="col">Nombre</th>
                            <th scope="col">Etapa</th>
                            <th scope="col">Promedio RR. HH.</th>
                            <th scope="col">Promedio departamento</th>
                            <th scope="col">Resultado</th>
                            <th scope="col">Entrevista</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($filas as $p)
                            @php
                                $rh = $p->evaluaciones->where('tipo', 'rh')->last();
                                $depto = $p->evaluaciones->where('tipo', 'departamento')->last();
                                $ultima = $depto ?? $rh;
                                $enlace = $verFichas ? route('candidatos.show', $p->candidato_id)
                                    : (in_array((int) $p->entrevistador_id, $titulares, true) || $depto ? route('entrevistas.show', $p->id) : null);
                            @endphp
                            <tr>
                                <td>@if ($enlace)<a href="{{ $enlace }}">{{ $p->candidato?->nombre_completo }}</a>@else{{ $p->candidato?->nombre_completo }}@endif
                                    <span class="d-block texto-traza">{{ $p->sede?->nombre }}</span></td>
                                <td><span class="pastilla-etapa etapa-{{ Candidato::COLORES[$p->etapa] ?? 'gris' }}">{{ $p->etiquetaEtapa() }}</span></td>
                                <td>@if ($rh)<i class="bi bi-star-fill text-warning" aria-hidden="true"></i> {{ $rh->promedioTexto() }}@else — @endif</td>
                                <td>@if ($depto)<i class="bi bi-star-fill text-warning" aria-hidden="true"></i> {{ $depto->promedioTexto() }}@else — @endif</td>
                                <td>@if ($ultima)<span class="pastilla-resultado resultado-{{ $ultima->resultado }}">{{ $ultima->etiquetaResultado() }}</span>@else — @endif</td>
                                <td>{{ $p->entrevistador?->name ?? '—' }}@if ($p->numero_entrevista > 1)<span class="d-block texto-traza">{{ $p->numeroTexto() }} entrevista</span>@endif</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
        <p class="texto-traza mt-2"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Los promedios van de 1 a 5 estrellas. Al elegir a tantas personas como plazas, los demás que siguen con el departamento pasan a «Considerar».</p>
    @endif
</div>
@endsection
