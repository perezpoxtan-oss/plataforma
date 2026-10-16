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
    @include('administracion.partes.selector-empresa')
    @include('operacion.reservas._nav', ['titulo' => 'Configuración de reservas', 'descripcion' => 'Qué áreas se reservan y cómo, los catálogos de tu empresa y quién aprueba.',
        'icono' => 'bi-gear', 'actual' => 'configuracion', 'buscar' => ['reservas-config-areas', 'Buscar área...']])

    {{-- ===================== Áreas reservables ===================== --}}
    <section class="mb-4" aria-labelledby="t-areas">
        <h2 id="t-areas" class="h5 fw-bold m-0">Áreas reservables</h2>
        <p class="text-muted small">Las áreas salen de <strong>Zonas y edificios</strong>: elige un salón, sala o terraza que ya exista y márcalo como reservable.</p>

        <div class="fichas-grid mt-2" data-fichas="reservas-config-areas">
            <button type="button" class="ficha-card ficha-create" data-abrir-dialogo="dialogoMarcarReservable">
                <i class="bi bi-plus-circle-fill" aria-hidden="true"></i>
                <span class="h6 fw-bold m-0 mt-2 titulo-crear">Marcar como reservable</span>
                <span class="small text-muted mt-1">Un espacio de Zonas y edificios</span>
            </button>

            @foreach ($areas as $a)
                @php $tarifas = $a->tarifas(); @endphp
                <div class="ficha-card {{ $a->reservable ? '' : 'inactiva' }}" id="config-area-{{ $a->id }}" data-ficha data-estado="{{ $a->reservable ? 1 : 0 }}"
                     data-texto="{{ mb_strtolower($a->nombre().' '.$a->sede?->nombre) }}" @unless ($a->reservable) style="opacity:.6" @endunless>
                    <div>
                        <h3 class="ficha-title"><i class="bi bi-door-open text-success me-2" aria-hidden="true"></i>{{ $a->nombre() }}</h3>
                        <div class="ficha-subtitulo">{{ $a->sede?->nombre }}</div>
                        <ul class="list-unstyled small text-muted mt-2 pt-2 border-top m-0">
                            <li><i class="bi bi-people me-1" aria-hidden="true"></i> {{ $a->aforo_maximo ? 'Hasta '.$a->aforo_maximo.' personas' : 'Sin aforo definido' }}</li>
                            <li><i class="bi bi-grid-3x3-gap me-1" aria-hidden="true"></i> {{ $a->montajes->count() }} {{ $a->montajes->count() === 1 ? 'montaje' : 'montajes' }}</li>
                            <li><i class="bi bi-cash-coin me-1" aria-hidden="true"></i> {{ $tarifas === [] ? 'Sin costo' : collect($tarifas)->map(fn ($v, $k) => ReservaArea::TARIFAS[$k][1].' '.Cotizador::dinero($v, $moneda))->join(', ') }}</li>
                            <li><i class="bi bi-person-check me-1" aria-hidden="true"></i> Aprueba {{ $a->responsable?->name ?? 'el responsable general' }}</li>
                            @if ($a->confirmacion_inmediata)<li><i class="bi bi-lightning-charge me-1" aria-hidden="true"></i> Confirmación inmediata</li>@endif
                        </ul>
                    </div>
                    <div class="ficha-footer">
                        <span class="etiqueta-estado {{ $a->reservable ? 'activo' : 'inactivo' }}">{{ $a->reservable ? 'RESERVABLE' : 'DE BAJA' }}</span>
                        <div class="d-flex gap-2">
                            @include('componentes.ver-historial', ['registro' => ReservaArea::class, 'id' => $a->id, 'nombre' => $a->nombre()])
                            <a href="{{ route('reservas.configuracion.area', $a->id) }}" class="btn-icono editar" title="Configurar" aria-label="Configurar {{ $a->nombre() }}"><i class="bi bi-sliders" aria-hidden="true"></i></a>
                            <form method="POST" action="{{ route('reservas.configuracion.area.estado', $a->id) }}" class="m-0"
                                  data-confirmar="{{ $a->reservable ? '¿Dejar de ofrecer «'.$a->nombre().'» para reservar? Sus reservas ya hechas se conservan.' : '¿Volver a ofrecer «'.$a->nombre().'» para reservar?' }}">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="reservable" value="{{ $a->reservable ? 0 : 1 }}">
                                @if ($a->reservable)
                                    <button type="submit" class="btn-icono eliminar" title="Dar de baja" aria-label="Dar de baja {{ $a->nombre() }}"><i class="bi bi-slash-circle" aria-hidden="true"></i></button>
                                @else
                                    <button type="submit" class="btn-icono reactivar" title="Reactivar" aria-label="Reactivar {{ $a->nombre() }}"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></button>
                                @endif
                            </form>
                        </div>
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
                <h2 id="t-{{ $clave }}" class="h5 fw-bold m-0">{{ $tituloCat }}</h2>
                <p class="text-muted small">{{ $ayuda }}</p>
                <div class="fichas-grid mt-2">
                    <button type="button" class="ficha-card ficha-create ficha-compacta" data-abrir-dialogo="dialogoNuevo-{{ $clave }}">
                        <i class="bi bi-plus-circle-fill" aria-hidden="true"></i>
                        <span class="h6 fw-bold m-0 mt-2 titulo-crear">{{ $nuevo }}</span>
                    </button>
                    @foreach ($lista as $item)
                        <div class="ficha-card ficha-compacta" id="{{ $clave }}-{{ $item->id }}" @unless ($item->activo) style="opacity:.6" @endunless>
                            <h3 class="h6 fw-bold m-0"><i class="bi {{ $iconoCat }} text-success me-1" aria-hidden="true"></i>{{ $item->nombre }}</h3>
                            <div class="ficha-footer">
                                <span class="etiqueta-estado {{ $item->activo ? 'activo' : 'inactivo' }}">{{ $item->activo ? 'ACTIVO' : 'DE BAJA' }}</span>
                                @include('operacion.reservas._acciones-catalogo', ['clave' => $clave, 'item' => $item, 'valores' => ['nombre' => $item->nombre]])
                            </div>
                        </div>
                    @endforeach
                </div>
                @include('operacion.reservas._dialogo-catalogo', ['clave' => $clave, 'titulo' => $nuevo, 'servicio' => false])
            </section>
        @endforeach

        <section class="mb-4" id="cat-servicios" aria-labelledby="t-servicios">
            <h2 id="t-servicios" class="h5 fw-bold m-0">Servicios y requerimientos</h2>
            <p class="text-muted small">Lo que se puede pedir con una reserva. El <strong>departamento</strong> lo aprueba (si lo marcas) y recibe su orden de servicio al confirmarse. Sus responsables son los de <strong>Departamentos → Responsables</strong>.</p>
            <div class="fichas-grid mt-2">
                <button type="button" class="ficha-card ficha-create" data-abrir-dialogo="dialogoNuevo-servicios">
                    <i class="bi bi-plus-circle-fill" aria-hidden="true"></i>
                    <span class="h6 fw-bold m-0 mt-2 titulo-crear">Nuevo servicio</span>
                </button>
                @foreach ($servicios as $s)
                    <div class="ficha-card" id="servicios-{{ $s->id }}" @unless ($s->activo) style="opacity:.6" @endunless>
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
                            @include('operacion.reservas._acciones-catalogo', ['clave' => 'servicios', 'item' => $s, 'valores' => [
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

    {{-- ===== Marcar un espacio como reservable ===== --}}
    <dialog id="dialogoMarcarReservable" class="dialogo" aria-labelledby="t-marcar" @if ($errors->has('espacio_id')) data-abrir-al-cargar @endif>
        <div class="dialogo-cabecera">
            <h2 id="t-marcar"><i class="bi bi-door-open me-2 text-success" aria-hidden="true"></i>Marcar como reservable</h2>
            <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div class="dialogo-cuerpo">
            <form method="POST" action="{{ route('reservas.configuracion.marcar') }}" autocomplete="off">
                @csrf
                @if ($espacios->isEmpty())
                    <p class="small m-0">No quedan espacios por marcar. Crea el salón o la sala en <strong>Estructura → Zonas y edificios</strong>.</p>
                @else
                    <label class="campo-etiqueta" for="espacio_id">Espacio de Zonas y edificios <span class="text-danger" aria-hidden="true">*</span></label>
                    <select id="espacio_id" name="espacio_id" class="campo" required>
                        <option value="">Elige un espacio…</option>
                        @foreach ($espacios->groupBy('sede') as $sedeNombre => $grupo)
                            <optgroup label="{{ $sedeNombre }}">
                                @foreach ($grupo as $e)<option value="{{ $e['id'] }}">{{ $e['texto'] }}</option>@endforeach
                            </optgroup>
                        @endforeach
                    </select>
                    <p class="small text-muted mt-2 mb-0">Después completa su aforo, montajes, horario y tarifas (todo es opcional).</p>
                @endif
                <div class="dialogo-acciones">
                    <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                    @unless ($espacios->isEmpty())<button type="submit" class="btn-verde">Guardar</button>@endunless
                </div>
            </form>
        </div>
    </dialog>
</div>
@endsection
