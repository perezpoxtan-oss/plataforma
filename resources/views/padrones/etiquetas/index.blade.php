@extends('layouts.app')

@section('titulo', 'Etiquetas QR')

@section('contenido')
{{--
    Padrones → Etiquetas QR: gestor centralizado de impresión QR.
    Ronda 6 (LL-06): imprimir en bloque las etiquetas de llaves, gafetes, equipos, vehículos…
    Ronda 7: plantillas (rollo térmico u hoja carta/A4), filtros por fecha de alta, tipo y
    estatus, e historial con «Reimprimir». Filtros en la dirección (data-autoenviar); las
    casillas y «Marcar todas» las maneja el bloque «Ajustes Ronda 6» de public/js/plataforma.js
    y la descripción de la plantilla elegida, el bloque «Ajustes Ronda 7».
--}}
<div class="tema-gafetes pantalla-etiquetas-qr">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    <div class="encabezado-pantalla mb-3">
        <div class="icono"><i class="bi bi-qr-code text-dark" aria-hidden="true"></i></div>
        <div>
            <h1>Etiquetas QR</h1>
            <p>Imprime de una vez las etiquetas QR de tus llaves, gafetes, equipos, vehículos y más. Marca las que quieras, elige la plantilla y oprime «Imprimir».</p>
            @unless ($sinEmpresa)
                <p class="texto-padron-de"><i class="bi bi-building-check" aria-hidden="true"></i> Etiquetas de: <strong>{{ $empresaNombre }}</strong></p>
            @endunless
        </div>
    </div>

    @if ($sinEmpresa)
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-buildings" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong> para imprimir sus etiquetas.</p>
        </div>
    @else
        @include('padrones.etiquetas._pestanas', ['activa' => 'imprimir'])

        @if ($tipos === [])
            <div class="tarjeta estado-vacio">
                <div class="icono"><i class="bi bi-printer" aria-hidden="true"></i></div>
                <p class="fw-semibold mb-1">No tienes permiso de imprimir etiquetas de ningún módulo.</p>
                <p class="text-muted small m-0">Para imprimir las etiquetas de un módulo (por ejemplo Llaves) necesitas poder verlo y la acción «Imprimir» de ese módulo. Pídelo a tu administrador.</p>
            </div>
        @else
            @php
                $hayFiltros = $filtros['tipo'] !== null || $filtros['sede'] !== null || $filtros['estado'] !== 'activos' || $filtros['q'] !== '' || $filtros['desde'] !== null || $filtros['hasta'] !== null;
                $conservar = array_filter(['sede' => $filtros['sede'], 'estado' => $filtros['estado'] !== 'activos' ? $filtros['estado'] : null, 'q' => $filtros['q'] ?: null, 'desde' => $filtros['desde'], 'hasta' => $filtros['hasta']]);
            @endphp

            <div class="pildoras-tipo mb-2" role="group" aria-label="Filtrar por tipo">
                <a href="{{ route('etiquetas.index', $conservar) }}"
                   class="btn-pill-tipo {{ $filtros['tipo'] === null ? 'active' : '' }}" @if ($filtros['tipo'] === null) aria-current="true" @endif>Todos</a>
                @foreach ($tipos as $clave => $t)
                    <a href="{{ route('etiquetas.index', ['tipo' => $clave] + $conservar) }}"
                       class="btn-pill-tipo {{ $filtros['tipo'] === $clave ? 'active' : '' }}" @if ($filtros['tipo'] === $clave) aria-current="true" @endif>
                        <i class="bi {{ $t['icono'] }} me-1" aria-hidden="true"></i>{{ $t['nombre'] }}@if ($filtros['tipo'] === null) <span class="conteo-pill">{{ $conteo[$clave] ?? 0 }}</span>@endif
                    </a>
                @endforeach
            </div>

            <form method="GET" data-autoenviar action="{{ route('etiquetas.index') }}" class="filtros-vouchers filtros-etiquetas-qr" role="search" aria-label="Filtrar etiquetas">
                <div class="buscador">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="search" name="q" value="{{ $filtros['q'] }}" placeholder="Nombre, placas, serie, número..." aria-label="Buscar">
                </div>
                <select name="tipo" class="filtro-select" aria-label="Tipo de registro" data-enviar-al-cambiar>
                    <option value="">Todos los tipos</option>
                    @foreach ($tipos as $clave => $t)
                        <option value="{{ $clave }}" @selected($filtros['tipo'] === $clave)>{{ $t['nombre'] }}</option>
                    @endforeach
                </select>
                @if ($sedes->count() > 1)
                    <select name="sede" class="filtro-select" aria-label="Filtrar por sede" data-enviar-al-cambiar>
                        <option value="">Todas las sedes</option>
                        @foreach ($sedes as $s)
                            <option value="{{ $s->id }}" @selected($filtros['sede'] === $s->id)>{{ $s->nombre }}</option>
                        @endforeach
                    </select>
                @endif
                <select name="estado" class="filtro-select" aria-label="Estatus" data-enviar-al-cambiar>
                    <option value="activos" @selected($filtros['estado'] === 'activos')>Solo activos</option>
                    <option value="baja" @selected($filtros['estado'] === 'baja')>Solo de baja</option>
                    <option value="todos" @selected($filtros['estado'] === 'todos')>Activos y de baja</option>
                </select>
                <label class="filtro-fecha"><span>Alta desde</span><input type="date" name="desde" value="{{ $filtros['desde'] }}" data-enviar-al-cambiar></label>
                <label class="filtro-fecha"><span>hasta</span><input type="date" name="hasta" value="{{ $filtros['hasta'] }}" data-enviar-al-cambiar></label>
                <div class="acciones-filtro">
                    <button type="submit" class="btn-gafetes"><i class="bi bi-search me-1" aria-hidden="true"></i>Buscar</button>
                    @if ($hayFiltros)
                        <a href="{{ route('etiquetas.index') }}" class="btn-limpiar-filtros"><i class="bi bi-x-lg me-1" aria-hidden="true"></i>Quitar filtros</a>
                    @endif
                </div>
            </form>
            @if ($filtros['sede'] !== null && collect($tipos)->keys()->intersect(['vehiculo', 'procedimiento'])->isNotEmpty())
                <p class="campo-ayuda"><i class="bi bi-info-circle" aria-hidden="true"></i> Los vehículos y los procedimientos son de toda la empresa: con una sede elegida no aparecen.</p>
            @endif

            <form method="POST" action="{{ route('etiquetas.imprimir') }}" target="_blank" class="form-etiquetas-qr" data-etiquetas-qr>
                @csrf
                <div class="barra-etiquetas-qr">
                    <label class="marcar-todas-qr">
                        <input type="checkbox" data-etiquetas-todas @disabled($filas->isEmpty())>
                        <span>Marcar todas <small>({{ $filas->count() }})</small></span>
                    </label>
                    <label class="tamano-etiquetas-qr">
                        <span>Plantilla</span>
                        <select name="plantilla" class="filtro-select" aria-label="Plantilla de la etiqueta" aria-describedby="etiquetas_plantilla_detalle" data-plantilla-etiquetas>
                            @foreach ($plantillas as $pl)
                                <option value="{{ $pl->id }}" @selected($preelegida?->id === $pl->id) data-resumen="{{ $pl->resumen() }}{{ $pl->sede ? ' · '.$pl->sede->nombre : '' }}">{{ $pl->nombre }}</option>
                            @endforeach
                        </select>
                    </label>
                    <button type="submit" class="btn-gafetes btn-imprimir-etiquetas-qr" data-etiquetas-imprimir disabled>
                        <i class="bi bi-printer me-1" aria-hidden="true"></i>Imprimir <span data-etiquetas-conteo>0</span>
                    </button>
                </div>
                <p class="campo-ayuda mb-1 detalle-plantilla-qr" id="etiquetas_plantilla_detalle" data-plantilla-detalle>
                    <i class="bi bi-rulers" aria-hidden="true"></i> <span>{{ $preelegida ? $preelegida->resumen().($preelegida->sede ? ' · '.$preelegida->sede->nombre : '') : '' }}</span>
                    @if ($puedeConfigurar)
                        · <a href="{{ route('etiquetas.plantillas') }}">Configurar plantillas</a>
                    @endif
                </p>
                <p class="campo-ayuda mb-3" data-etiquetas-ayuda>Marca las etiquetas que quieras imprimir (máximo {{ \App\Services\Lector\EtiquetasMasivas::MAXIMO }} por hoja de impresión). Se abre en otra pestaña.</p>

                @if ($filas->isEmpty())
                    <div class="sin-resultados">
                        <i class="bi {{ $hayFiltros ? 'bi-search' : 'bi-qr-code' }} d-block mb-2" aria-hidden="true"></i>
                        <p class="fw-semibold m-0">{{ $hayFiltros ? 'No hay registros que coincidan con tu búsqueda.' : 'Todavía no hay registros con código QR para imprimir.' }}</p>
                    </div>
                @else
                    <ul class="lista-etiquetas-qr">
                        @foreach ($filas as $f)
                            <li class="fila-etiqueta-qr {{ $f['activo'] ? '' : 'inactiva' }}">
                                <label>
                                    <input type="checkbox" name="sel[]" value="{{ $f['clave'] }}" data-etiqueta-qr>
                                    <span class="icono-etiqueta-qr"><i class="bi {{ $f['icono'] }}" aria-hidden="true"></i></span>
                                    <span class="texto-etiqueta-qr">
                                        <strong>{{ $f['titulo'] }}</strong>
                                        <small>{{ $f['tipo_nombre'] }}{{ $f['detalle'] !== '' ? ' · '.$f['detalle'] : '' }}</small>
                                        <small class="codigo-etiqueta-qr">Código: {{ $f['codigo'] }}@if ($f['creado_en']) · Alta: @fecha($f['creado_en'], 'd/m/Y')@endif</small>
                                    </span>
                                    @unless ($f['activo'])
                                        <span class="etiqueta-inactiva">De baja</span>
                                    @endunless
                                </label>
                            </li>
                        @endforeach
                    </ul>
                    @if ($filas->count() >= \App\Services\Lector\EtiquetasMasivas::POR_TIPO)
                        <p class="campo-ayuda"><i class="bi bi-info-circle" aria-hidden="true"></i> Se muestran los primeros {{ \App\Services\Lector\EtiquetasMasivas::POR_TIPO }} de cada tipo: usa la búsqueda o los filtros para encontrar los demás.</p>
                    @endif
                @endif
            </form>
        @endif
    @endif
</div>
@endsection
