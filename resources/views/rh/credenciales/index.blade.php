{{--
    C-44 (Etapa 8): lista «Credenciales» (Colaboradores → Credenciales). Un
    renglón por colaborador (personal activo del alcance) con su última
    credencial. Filtros: Vigentes, Por vencer, Vencidas, No regresó, De baja y
    Sin credencial. «Imprimir seleccionadas» emite la de quien no tiene y abre
    la hoja CR80 de todas.
--}}
@extends('layouts.app')

@section('titulo', 'Credenciales')

@section('contenido')
<div class="tema-esmeralda lista-credenciales">
    @include('administracion.partes.avisos')
    <a href="{{ route('colaboradores.index') }}" class="enlace-volver"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Colaboradores</a>
    <div class="encabezado-pantalla mb-3">
        <div class="icono"><i class="bi bi-person-vcard text-success" aria-hidden="true"></i></div>
        <div>
            <h1>Credenciales</h1>
            <p>La credencial de cada colaborador: vigencia, impresión y quién no tiene.{{ $ajustes['vigencia'] === 'sin' ? ' Tu empresa usa credenciales sin vencimiento.' : '' }}</p>
        </div>
    </div>

    <nav class="pastillas-credenciales" aria-label="Filtrar credenciales">
        @foreach (\App\Services\Credenciales\AdministradorCredenciales::FILTROS as $clave => $nombreFiltro)
            @continue($clave !== 'todas' && $clave !== 'sin' && $clave !== 'vigentes' && ($conteos[$clave] ?? 0) === 0 && $estado !== $clave)
            <a href="{{ route('credenciales.index', array_filter(['estado' => $clave === 'todas' ? null : $clave, 'q' => $texto ?: null])) }}" class="{{ $estado === $clave ? 'activa' : '' }}"
               @if ($estado === $clave) aria-current="page" @endif>{{ $nombreFiltro }}@if ($clave !== 'todas') ({{ $conteos[$clave] ?? 0 }})@endif</a>
        @endforeach
    </nav>

    <form method="GET" action="{{ route('credenciales.index') }}" class="barra-filtros mb-3">
        @if ($estado !== 'todas')<input type="hidden" name="estado" value="{{ $estado }}">@endif
        <input type="search" name="q" value="{{ $texto }}" class="campo" maxlength="80" placeholder="Nombre o número de empleado" aria-label="Buscar colaborador">
        <button type="submit" class="btn btn-outline-secondary" style="min-height:44px;border-radius:10px;">Buscar</button>
    </form>

    <form method="POST" action="{{ route('credenciales.imprimir-seleccion') }}" class="tarjeta p-3">
        @csrf
        @forelse ($filas as $f)
            @php $c = $f['colaborador']; $cr = $f['credencial']; @endphp
            <div class="fila-credencial">
                <input type="checkbox" name="colaboradores[]" value="{{ $c->id }}" aria-label="Elegir a {{ $c->nombreCompleto() }}" @disabled($c->provisional)>
                <div>
                    <a href="{{ route('colaboradores.expediente', $c->id) }}#codigo" class="fw-semibold">{{ $c->nombreCompleto() }}</a>
                    <div class="small text-muted">Núm. {{ $c->num_empleado ?: '—' }}{{ $c->puesto ? ' · '.$c->puesto->nombre : '' }}{{ $c->sede ? ' · '.$c->sede->nombre : '' }}{{ $c->foto_credencial_ruta ? '' : ' · sin foto' }}{{ $c->provisional ? ' · por verificar' : '' }}</div>
                </div>
                <div class="text-end small">
                    @if ($cr)
                        <span class="insignia-estado-activo {{ $f['grupo'] === 'vencidas' ? 'estado-activo-vencida' : \App\Support\EstadoActivo::clase($cr->estado) }}">{{ $f['grupo'] === 'por_vencer' ? 'POR VENCER' : $cr->textoEstado() }}</span>
                        <div class="text-muted">{{ $cr->folio }}{{ $cr->vence_en ? ' · vence '.$cr->vence_en->format('d/m/Y') : '' }}</div>
                    @else
                        <span class="text-muted">Sin credencial</span>
                    @endif
                </div>
            </div>
        @empty
            <p class="text-muted small m-0">No hay colaboradores en este filtro.</p>
        @endforelse
        @if ($filas->isNotEmpty())
            <div class="d-flex gap-2 flex-wrap align-items-center mt-3">
                <button type="submit" class="btn-esmeralda"><i class="bi bi-printer me-1" aria-hidden="true"></i>Imprimir seleccionadas</button>
                <span class="small text-muted">A quien no tenga credencial vigente se le emite una nueva antes de imprimir.</span>
            </div>
        @endif
    </form>
</div>
@endsection
