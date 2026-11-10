@extends('empleos.plantilla')
@use('App\Models\Vacante')

@section('titulo', 'Vacantes')

@section('contenido')
<section class="tarjeta kiosco-tarjeta bolsa-portada">
    <h1 class="kiosco-h1">Trabaja con nosotros</h1>
    @if ($ajustes['presentacion'])
        <p class="bolsa-presentacion">{{ $ajustes['presentacion'] }}</p>
    @else
        <p class="bolsa-presentacion">Estas son las vacantes abiertas de {{ $empresa->nombre_comercial }}. Elige una y llena tu solicitud desde tu celular.</p>
    @endif
</section>

<form method="GET" action="{{ route('empleos.index', $empresa->bolsa_slug) }}" class="filtros-rh bolsa-filtros" role="search">
    <div class="buscador">
        <i class="bi bi-search" aria-hidden="true"></i>
        <input type="search" name="q" value="{{ $filtros['q'] }}" maxlength="80" placeholder="¿Qué puesto buscas?" aria-label="Buscar vacante">
    </div>
    @if ($sedes->count() > 1)
        <select name="sede" class="filtro-select" aria-label="Sede" data-enviar-al-cambiar>
            <option value="">Todas las sedes</option>
            @foreach ($sedes as $s)
                <option value="{{ $s->id }}" @selected($filtros['sede'] === $s->id)>{{ $s->nombre }}</option>
            @endforeach
        </select>
    @endif
    @if ($departamentos->count() > 1)
        <select name="departamento" class="filtro-select" aria-label="Área" data-enviar-al-cambiar>
            <option value="">Todas las áreas</option>
            @foreach ($departamentos as $d)
                <option value="{{ $d->id }}" @selected($filtros['departamento'] === $d->id)>{{ $d->nombre }}</option>
            @endforeach
        </select>
    @endif
    <button type="submit" class="btn-secundario-rh"><i class="bi bi-search me-1" aria-hidden="true"></i>Buscar</button>
</form>

<div class="bolsa-lista">
    @forelse ($vacantes as $v)
        <a href="{{ route('empleos.show', [$empresa->bolsa_slug, $v->codigo]) }}" class="tarjeta bolsa-vacante">
            <h2>{{ $v->titulo }}</h2>
            <div class="datos-candidato">
                <span><i class="bi bi-geo-alt" aria-hidden="true"></i> {{ $v->sedesTexto() }}</span>
                @if ($v->departamento)<span><i class="bi bi-diagram-2" aria-hidden="true"></i> {{ $v->departamento->nombre }}</span>@endif
                <span><i class="bi bi-cash" aria-hidden="true"></i> {{ $v->sueldoTexto() }}</span>
                @if ($v->jornada)<span><i class="bi bi-clock" aria-hidden="true"></i> {{ Vacante::JORNADAS[$v->jornada] ?? '' }}</span>@endif
            </div>
            <span class="bolsa-ver">Ver vacante y postularme <i class="bi bi-arrow-right" aria-hidden="true"></i></span>
        </a>
    @empty
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-briefcase" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">{{ $filtros['q'] !== '' || $filtros['sede'] || $filtros['departamento'] ? 'No hay vacantes con esa búsqueda.' : 'Por ahora no hay vacantes abiertas. Vuelve pronto.' }}</p>
        </div>
    @endforelse
</div>
@endsection
