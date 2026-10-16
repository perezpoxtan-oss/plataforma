@extends('layouts.app')

@section('titulo', 'Configuración de reservas')

@php
    use App\Models\ReservaArea;
    use App\Models\ReservaServicio;
    use App\Services\Reservas\Cotizador;
    $moneda = $empresa?->moneda ?: 'MXN';
    $dialogo = old('_dialogo');
@endphp

@section('contenido')
<div class="tema-verde">
    @include('administracion.partes.avisos')
    @include('componentes.siguiente-paso')
    @include('administracion.partes.selector-empresa')
    @include('operacion.reservas._nav', ['titulo' => 'Configuración de reservas', 'descripcion' => 'Qué áreas se reservan y cómo, los catálogos de tu empresa y quién aprueba.',
        'icono' => 'bi-gear', 'actual' => 'configuracion', 'buscar' => ['reservas-config-areas', 'Buscar área...']])

    {{-- ===================== Áreas reservables ===================== --}}
    <section class="mb-4" id="areas" aria-labelledby="t-areas">
        <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
            <h2 id="t-areas" class="h5 fw-bold m-0">Áreas reservables</h2>
            @include('operacion.reservas._ver-ocultas', ['seccion' => 'areas', 'ancla' => 'areas', 'n' => $ocultas['areas'], 'texto' => 'quitadas'])
        </div>
        <p class="text-muted small">Las áreas salen de <strong>Zonas y edificios</strong>: agrega salones, salas, terrazas o habitaciones que ya existan. Una zona puede reservarse completa o por sus espacios interiores.</p>

        <div class="fichas-grid mt-2" data-fichas="reservas-config-areas">
            <button type="button" class="ficha-card ficha-create" data-abrir-dialogo="dialogoAgregarArea">
                <i class="bi bi-plus-circle-fill" aria-hidden="true"></i>
                <span class="h6 fw-bold m-0 mt-2 titulo-crear">Agregar área reservable</span>
                <span class="small text-muted mt-1">Uno o varios espacios de Zonas y edificios</span>
            </button>

            @foreach ($areas as $a)
                @php $tarifas = $a->tarifas(); $dentro = $interiores[$a->id] ?? 0; @endphp
                <div class="ficha-card {{ $a->reservable ? '' : 'inactiva' }}" id="config-area-{{ $a->id }}" data-ficha data-estado="{{ $a->reservable ? 1 : 0 }}"
                     data-texto="{{ mb_strtolower($a->nombre().' '.($rutas[$a->id] ?? '').' '.$a->sede?->nombre) }}">
                    <div>
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <h3 class="h5 fw-bold m-0"><i class="bi {{ $a->porInteriores() || $dentro > 0 ? 'bi-building' : 'bi-door-open' }} text-success me-2" aria-hidden="true"></i>{{ $a->nombre() }}
                                @unless ($a->reservable)<span class="etiqueta-inactiva ms-1">QUITADA</span>@endunless
                            </h3>
                            <div class="d-flex gap-1">
                                @include('componentes.ver-historial', ['registro' => ReservaArea::class, 'id' => $a->id, 'nombre' => $a->nombre()])
                                @if ($a->reservable)
                                    @include('operacion.reservas._acciones-area', ['a' => $a, 'resumen' => $eliminar[$a->id] ?? null])
                                @else
                                    <a href="{{ route('reservas.configuracion.area', $a->id) }}" class="btn-icono editar" title="Editar" aria-label="Editar {{ $a->nombre() }}"><i class="bi bi-pencil" aria-hidden="true"></i></a>
                                    <form method="POST" action="{{ route('reservas.configuracion.area.estado', $a->id) }}" class="m-0" data-confirmar="¿Volver a ofrecer «{{ $a->nombre() }}» para reservar? Conserva su configuración.">
                                        @csrf
                                        @method('PATCH')
                                        <input type="hidden" name="reservable" value="1">
                                        <button type="submit" class="btn-icono reactivar" title="Reactivar" aria-label="Reactivar {{ $a->nombre() }}"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></button>
                                    </form>
                                @endif
                            </div>
                        </div>
                        <div class="text-muted small mb-2 border-bottom pb-2 mt-1"><i class="bi bi-geo-alt" aria-hidden="true"></i> {{ $a->sede?->nombre }}{{ ($rutas[$a->id] ?? '') !== '' ? ' · '.$rutas[$a->id] : '' }}</div>
                        <ul class="list-unstyled small text-muted m-0">
                            @if ($a->porInteriores())
                                <li><i class="bi bi-diagram-3 me-1" aria-hidden="true"></i> Por sus espacios interiores: {{ $dentro }} {{ $dentro === 1 ? 'espacio' : 'espacios' }}</li>
                                <li><i class="bi bi-info-circle me-1" aria-hidden="true"></i> Su configuración es la inicial de sus espacios</li>
                            @else
                                @if ($dentro > 0)<li><i class="bi bi-diagram-3 me-1" aria-hidden="true"></i> Toda la zona y {{ $dentro }} {{ $dentro === 1 ? 'espacio interior' : 'espacios interiores' }}</li>@endif
                                <li><i class="bi bi-people me-1" aria-hidden="true"></i> {{ $a->aforo_maximo ? 'Hasta '.$a->aforo_maximo.' personas' : 'Sin aforo definido' }}</li>
                                <li><i class="bi bi-grid-3x3-gap me-1" aria-hidden="true"></i> {{ $a->montajes->count() }} {{ $a->montajes->count() === 1 ? 'montaje' : 'montajes' }}</li>
                                <li><i class="bi bi-cash-coin me-1" aria-hidden="true"></i> {{ $tarifas === [] ? 'Sin costo' : collect($tarifas)->map(fn ($v, $k) => ReservaArea::TARIFAS[$k][1].' '.Cotizador::dinero($v, $moneda))->join(', ') }}</li>
                                <li><i class="bi bi-person-check me-1" aria-hidden="true"></i> Aprueba {{ $a->responsable?->name ?? 'el responsable general' }}</li>
                                @if ($a->confirmacion_inmediata)<li><i class="bi bi-lightning-charge me-1" aria-hidden="true"></i> Confirmación inmediata</li>@endif
                            @endif
                        </ul>
                    </div>
                    <div class="ficha-footer">
                        <span class="etiqueta-estado {{ $a->reservable ? 'activo' : 'inactivo' }}">{{ $a->reservable ? 'RESERVABLE' : 'QUITADA' }}</span>
                        @if ($a->updated_at)<span class="texto-traza">Editada @fecha($a->updated_at)</span>@endif
                    </div>
                </div>
            @endforeach
        </div>
        <p class="text-muted text-center p-4" data-sin-resultados="reservas-config-areas" hidden><i class="bi bi-search me-2" aria-hidden="true"></i>No hay áreas que coincidan con tu búsqueda.</p>
    </section>

    @if ($empresaCompleta)
        {{-- ===================== Catálogos (fichas como Departamentos, Puestos y Turnos) ===================== --}}
        @foreach (['montajes' => ['Montajes', 'Formas de acomodar el área (cada área dice cuántas personas caben en cada una).', $montajes, 'bi-grid-3x3-gap', 'Nuevo montaje'],
                   'tipos' => ['Tipos de uso', 'Para qué se reserva (sirve para el informe de reservas por tipo).', $tipos, 'bi-tags', 'Nuevo tipo de uso']] as $clave => [$tituloCat, $ayuda, $lista, $iconoCat, $nuevo])
            <section class="mb-4" id="cat-{{ $clave }}" aria-labelledby="t-{{ $clave }}">
                <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
                    <h2 id="t-{{ $clave }}" class="h5 fw-bold m-0">{{ $tituloCat }}</h2>
                    @include('operacion.reservas._ver-ocultas', ['seccion' => $clave, 'ancla' => 'cat-'.$clave, 'n' => $ocultas[$clave], 'texto' => 'dados de baja'])
                </div>
                <p class="text-muted small">{{ $ayuda }}</p>
                <div class="fichas-grid mt-2">
                    <button type="button" class="ficha-card ficha-create ficha-compacta" data-abrir-dialogo="dialogoNuevo-{{ $clave }}">
                        <i class="bi bi-plus-circle-fill" aria-hidden="true"></i>
                        <span class="h6 fw-bold m-0 mt-2 titulo-crear">{{ $nuevo }}</span>
                    </button>
                    @foreach ($lista as $item)
                        <div class="ficha-card ficha-compacta {{ $item->activo ? '' : 'inactiva' }}" id="{{ $clave }}-{{ $item->id }}">
                            <h3 class="h6 fw-bold m-0"><i class="bi {{ $iconoCat }} text-success me-1" aria-hidden="true"></i>{{ $item->nombre }}</h3>
                            <div class="ficha-footer">
                                <span class="etiqueta-estado {{ $item->activo ? 'activo' : 'inactivo' }}">{{ $item->activo ? 'ACTIVO' : 'DE BAJA' }}</span>
                                @include('operacion.reservas._acciones-catalogo', ['clave' => $clave, 'item' => $item, 'usado' => isset($usados[$clave][$item->id]), 'valores' => ['nombre' => $item->nombre]])
                            </div>
                        </div>
                    @endforeach
                </div>
                @include('operacion.reservas._dialogo-catalogo', ['clave' => $clave, 'titulo' => $nuevo, 'servicio' => false])
            </section>
        @endforeach

        <section class="mb-4" id="cat-servicios" aria-labelledby="t-servicios">
            <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
                <h2 id="t-servicios" class="h5 fw-bold m-0">Servicios y requerimientos</h2>
                @include('operacion.reservas._ver-ocultas', ['seccion' => 'servicios', 'ancla' => 'cat-servicios', 'n' => $ocultas['servicios'], 'texto' => 'dados de baja'])
            </div>
            <p class="text-muted small">Lo que se puede pedir con una reserva. El <strong>departamento</strong> lo aprueba (si lo marcas) y recibe su orden de servicio al confirmarse. Sus responsables son los de <strong>Departamentos → Responsables</strong>. Al reservar, los servicios se muestran agrupados por su departamento, como en una orden de evento.</p>
            <form method="POST" action="{{ route('reservas.configuracion.sugeridos') }}" class="mb-2"
                  data-confirmar="¿Cargar la lista sugerida para hoteles? Agrega, sin costo, los servicios de una orden de evento (Steward, Alimentos y bebidas, Cocina, Mantenimiento, Áreas públicas, Animación, Capacitación y Sistemas) y los montajes Tipo U, Escuela, Auditorio y Banquete que falten. No duplica lo que ya tienes ni crea departamentos.">
                @csrf
                <button type="submit" class="btn-secundario-rh"><i class="bi bi-list-check me-1" aria-hidden="true"></i>Cargar servicios sugeridos de hotel</button>
            </form>
            <div class="fichas-grid mt-2">
                <button type="button" class="ficha-card ficha-create" data-abrir-dialogo="dialogoNuevo-servicios">
                    <i class="bi bi-plus-circle-fill" aria-hidden="true"></i>
                    <span class="h6 fw-bold m-0 mt-2 titulo-crear">Nuevo servicio</span>
                </button>
                @foreach ($servicios as $s)
                    <div class="ficha-card {{ $s->activo ? '' : 'inactiva' }}" id="servicios-{{ $s->id }}">
                        <div>
                            <h3 class="ficha-title"><i class="bi bi-cup-hot text-success me-2" aria-hidden="true"></i>{{ $s->nombre }}</h3>
                            <div class="ficha-subtitulo">{{ ReservaServicio::CATEGORIAS[$s->categoria] ?? 'Otro' }}</div>
                            <ul class="list-unstyled small text-muted mt-2 pt-2 border-top m-0">
                                <li><i class="bi bi-rulers me-1" aria-hidden="true"></i> {{ ReservaServicio::UNIDADES[$s->unidad][0] }}</li>
                                <li><i class="bi bi-cash-coin me-1" aria-hidden="true"></i> {{ $s->costo !== null ? Cotizador::dinero($s->costo, $moneda) : 'Sin costo' }}</li>
                                <li><i class="bi bi-diagram-3 me-1" aria-hidden="true"></i> {{ $s->departamento?->nombre ?? 'Sin departamento' }}{{ $s->departamento && $s->requiere_aprobacion ? ' (aprueba)' : '' }}</li>
                            </ul>
                        </div>
                        <div class="ficha-footer">
                            <span class="etiqueta-estado {{ $s->activo ? 'activo' : 'inactivo' }}">{{ $s->activo ? 'ACTIVO' : 'DE BAJA' }}</span>
                            @include('operacion.reservas._acciones-catalogo', ['clave' => 'servicios', 'item' => $s, 'usado' => isset($usados['servicios'][$s->id]), 'valores' => [
                                'nombre' => $s->nombre, 'categoria' => $s->categoria, 'unidad' => $s->unidad, 'costo' => $s->costo,
                                'departamento_id' => $s->departamento_id, 'requiere_aprobacion' => $s->requiere_aprobacion,
                            ]])
                        </div>
                    </div>
                @endforeach
            </div>
            @include('operacion.reservas._dialogo-catalogo', ['clave' => 'servicios', 'titulo' => 'Nuevo servicio', 'servicio' => true])
        </section>

        <section class="mb-4" aria-labelledby="t-ajustes">
            <h2 id="t-ajustes" class="h5 fw-bold">Quién aprueba, tiempos e IVA</h2>
            @include('operacion.reservas._ajustes', ['empresa' => $empresa, 'desde' => 'reservas', 'editable' => true])
        </section>
    @else
        <p class="text-muted small">Los catálogos (montajes, tipos de uso y servicios) y los ajustes generales son de toda la empresa: los cambia quien configura reservas en toda la empresa.</p>
    @endif

    {{-- ===== Agregar áreas reservables (uno o varios espacios) ===== --}}
    @include('operacion.reservas._dialogo-agregar', ['opciones' => $opciones])
</div>
@endsection
