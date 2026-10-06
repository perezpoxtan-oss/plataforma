@extends('layouts.app')

@section('titulo', 'Robo — Seguimiento')

@section('contenido')
<div class="tema-rojo pantalla-robo">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    @if ($sinEmpresa)
        <div class="encabezado-pantalla mb-4">
            <div class="icono"><i class="bi bi-exclamation-octagon-fill text-danger" aria-hidden="true"></i></div>
            <div><h1>Robo — Seguimiento</h1><p>Todos los casos, con su estatus de investigación, en un solo lugar.</p></div>
        </div>
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-buildings" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong> para ver sus casos de robo.</p>
        </div>
    @else
        @php
            $filtro = $filtros['filtro'];
            $texto = trim((string) ($filtros['q'] ?? ''));
            $sedeFiltro = isset($filtros['sede']) ? (int) $filtros['sede'] : null;
            $consulta = fn (array $extra) => array_filter(array_merge(['filtro' => $filtro, 'q' => $texto, 'sede' => $sedeFiltro], $extra), fn ($v) => $v !== null && $v !== '' && $v !== 'todos');
            // Textos y colores de las píldoras de SEGCAT
            $pildoras = [
                'todos' => ['Todos', 'dark'],
                'abiertos' => ['Abiertos', 'danger'],
                'sin_policia' => ['Sin parte a la policía', 'warning'],
                'con_sospechoso' => ['Con sospechoso', 'dark'],
            ];
        @endphp

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
            <div class="encabezado-pantalla m-0">
                <div class="icono"><i class="bi bi-exclamation-octagon-fill text-danger" aria-hidden="true"></i></div>
                <div>
                    <h1>Robo — Seguimiento</h1>
                    <p>Todos los casos, con su estatus de investigación, en un solo lugar.</p>
                    <p class="texto-padron-de"><i class="bi bi-building-check" aria-hidden="true"></i> Casos de: <strong>{{ $empresaNombre }}</strong></p>
                </div>
            </div>
            @if ($puede['tickets'])
                <div class="acciones-novedades">
                    <a href="{{ route('novedades.index') }}" class="btn-accion-novedades"><i class="bi bi-arrow-left" aria-hidden="true"></i> Novedades</a>
                </div>
            @endif
        </div>

        <nav class="filtros-lf" aria-label="Filtrar casos">
            @foreach ($pildoras as $clave => [$nombre, $color])
                @php $activo = $filtro === $clave; @endphp
                <a href="{{ route('robo.index', $consulta(['filtro' => $clave])) }}"
                   class="btn btn-sm fw-bold {{ $activo ? 'btn-'.$color : 'btn-outline-'.$color }}" @if ($activo) aria-current="page" @endif>
                    {{ $nombre }} <span class="conteo-filtro">{{ $conteos[$clave] }}</span>
                </a>
            @endforeach
        </nav>

        <form method="GET" action="{{ route('robo.index') }}" class="busqueda-lf" role="search">
            @if ($filtro !== 'todos')<input type="hidden" name="filtro" value="{{ $filtro }}">@endif
            <div class="buscador">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="search" name="q" value="{{ $texto }}" maxlength="100" placeholder="Buscar por objeto, lugar o #ticket..." aria-label="Buscar caso">
            </div>
            @if ($variasSedes)
                <select name="sede" class="filtro-select" aria-label="Filtrar por sede" data-enviar-al-cambiar>
                    <option value="">Todas las sedes</option>
                    @foreach ($sedesFiltro as $s)
                        <option value="{{ $s->id }}" @selected($sedeFiltro === $s->id)>{{ $s->nombre }}</option>
                    @endforeach
                </select>
            @endif
            <button type="submit" class="btn-buscar-pases" aria-label="Buscar"><i class="bi bi-search" aria-hidden="true"></i><span class="d-none d-sm-inline ms-1">Buscar</span></button>
            @if ($texto !== '' || $sedeFiltro !== null)
                <a href="{{ route('robo.index', $filtro !== 'todos' ? ['filtro' => $filtro] : []) }}" class="btn-limpiar-pases">Limpiar</a>
            @endif
        </form>

        <div class="lista-robos">
            @forelse ($casos as $c)
                @php
                    $r = $c->robo;
                    $resuelto = $c->resuelto();
                    $editable = in_array($c->id, $editables, true);
                @endphp
                <article class="caso-robo {{ $resuelto ? 'resuelto' : '' }}" id="robo-{{ $c->id }}">
                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                        <div class="caso-robo-datos">
                            <strong>{{ $c->folio() }}</strong> — {{ $c->ubicacion }}@if ($r?->lugar_exacto) ({{ $r->lugar_exacto }})@endif
                            <div class="small text-muted mt-1">
                                @fecha($c->created_at, 'd/m/Y')
                                @if ($r?->hora_aproximada) · aprox. {{ substr((string) $r->hora_aproximada, 0, 5) }}@endif
                                · Reportó: {{ $c->reportado_por }}
                                @if ($variasSedes) · {{ $c->sede?->nombre }}@endif
                            </div>
                            <div class="small mt-1 texto-robo">{!! nl2br(e($r?->objetos_descripcion ?: $c->descripcion)) !!}</div>
                            @if ($r?->valor_estimado)
                                <div class="small fw-bold text-danger mt-1">Valor estimado: ${{ number_format((float) $r->valor_estimado, 2) }}</div>
                            @endif
                            @if ($r?->articuloVinculado)
                                <div class="small fw-bold text-success mt-1"><i class="bi bi-link-45deg" aria-hidden="true"></i> Vinculado con el hallazgo {{ $r->articuloVinculado->folio }} — {{ $r->articuloVinculado->objeto }}</div>
                            @endif
                        </div>
                        <div class="badges-robo">
                            <span class="badge-robo {{ $resuelto ? 'verde' : 'rojo' }}">{{ $resuelto ? 'Resuelto' : ($c->estatus === \App\Models\Novedad::PENDIENTE_TURNO ? 'Pendiente de turno' : 'Abierto') }}</span>
                            <span class="badge-robo {{ $r?->hay_sospechoso ? 'ambar' : 'gris' }}">{{ $r?->hay_sospechoso ? 'Con sospechoso' : 'Sin sospechoso' }}</span>
                            <span class="badge-robo {{ $r?->parte_policia ? 'verde' : 'rojo' }}">{{ $r?->parte_policia ? 'Con parte policial' : 'Sin parte a policía' }}</span>
                            @if ($r?->canalizado_gerencia)<span class="badge-robo azul">Gerencia notificada</span>@endif
                            @if ($r?->canalizado_legal)<span class="badge-robo azul">En Legal</span>@endif
                        </div>
                    </div>
                    <div class="texto-traza mt-1">
                        <i class="bi bi-person-plus-fill" aria-hidden="true"></i> Creó: {{ $c->creador?->name ?? '—' }} · @fecha($c->created_at)
                        @if ($c->editor && $c->actualizado_por !== $c->creado_por) · <i class="bi bi-person-check-fill" aria-hidden="true"></i> Último en dar seguimiento: {{ $c->editor->name }} · @fecha($c->updated_at)@endif
                    </div>
                    <a href="{{ route('robo.index', $consulta(['abrir' => $c->id, 'page' => request('page')])) }}" class="btn-abrir-robo {{ $editable ? 'abrir' : 'ver' }}">
                        <i class="bi {{ $editable ? 'bi-folder2-open' : 'bi-eye-fill' }} me-1" aria-hidden="true"></i>{{ $editable ? 'Abrir Expediente' : 'Ver Expediente' }}
                    </a>
                </article>
            @empty
                <div class="tarjeta estado-vacio">
                    <div class="icono"><i class="bi bi-shield-check" aria-hidden="true"></i></div>
                    <p class="text-muted small m-0">
                        @if ($texto !== '')
                            No se encontraron casos para «{{ $texto }}».
                        @elseif ($filtro !== 'todos')
                            No hay casos con este filtro.
                        @else
                            No hay casos de robo. Se registran desde la Bitácora de Novedades con la categoría <strong>Robo</strong>.
                        @endif
                    </p>
                </div>
            @endforelse
        </div>

        @if ($casos->hasPages())
            <div class="mt-4">{{ $casos->onEachSide(1)->links('pagination::bootstrap-5') }}</div>
        @endif

        @if ($expediente)
            @include('seguridad.robo._expediente')
        @endif
    @endif
</div>
@endsection
