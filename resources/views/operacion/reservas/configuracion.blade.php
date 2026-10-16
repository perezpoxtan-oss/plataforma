@extends('layouts.app')

@section('titulo', 'Configuración de reservas')

@php
    use App\Models\ReservaServicio;
    use App\Services\Reservas\Cotizador;
    $moneda = $empresa?->moneda ?: 'MXN';
@endphp

@section('contenido')
<div class="tema-verde">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')
    @include('operacion.reservas._nav', ['titulo' => 'Configuración de reservas', 'descripcion' => 'Qué áreas se reservan y cómo, los catálogos de tu empresa y quién aprueba.', 'icono' => 'bi-gear', 'actual' => 'configuracion'])

    {{-- ===================== Áreas reservables ===================== --}}
    <section class="mb-4" aria-labelledby="t-areas">
        <h2 id="t-areas" class="h5 fw-bold">Áreas reservables</h2>
        <p class="res-meta">Las áreas salen de <strong>Zonas y edificios</strong>: elige un salón, sala o terraza que ya exista y márcalo como reservable.</p>

        <form method="POST" action="{{ route('reservas.configuracion.marcar') }}" class="res-filtros" autocomplete="off">
            @csrf
            <label class="visually-hidden" for="espacio_id">Espacio de Zonas y edificios</label>
            <select id="espacio_id" name="espacio_id" class="campo" style="max-width: 520px" required>
                <option value="">Elige un espacio de Zonas y edificios…</option>
                @foreach ($espacios->groupBy('sede') as $sedeNombre => $grupo)
                    <optgroup label="{{ $sedeNombre }}">
                        @foreach ($grupo as $e)<option value="{{ $e['id'] }}">{{ $e['texto'] }}</option>@endforeach
                    </optgroup>
                @endforeach
            </select>
            <button type="submit" class="btn-verde"><i class="bi bi-calendar-plus me-1" aria-hidden="true"></i>Marcar como reservable</button>
        </form>
        @if ($espacios->isEmpty() && $areas->isEmpty())
            <p class="res-meta">Todavía no hay espacios en Zonas y edificios. Créalos primero en <strong>Estructura → Zonas y edificios</strong>.</p>
        @endif

        <div class="res-lista mt-2">
            @forelse ($areas as $a)
                <article class="res-tarjeta">
                    <div class="res-tarjeta-fila">
                        <div>
                            <h3 class="mb-1">{{ $a->nombre() }} @unless ($a->reservable)<span class="etiqueta-inactiva">NO RESERVABLE</span>@endunless
                                @if ($a->confirmacion_inmediata)<span class="res-estado estado-confirmada ms-1">Confirmación inmediata</span>@endif</h3>
                            <div class="res-meta">
                                {{ $a->sede?->nombre }}
                                · {{ $a->aforo_maximo ? 'hasta '.$a->aforo_maximo.' personas' : 'sin aforo' }}
                                · {{ $a->montajes->count() }} {{ $a->montajes->count() === 1 ? 'montaje' : 'montajes' }}
                                · {{ count($a->tarifas()) ? collect($a->tarifas())->map(fn ($v, $k) => \App\Models\ReservaArea::TARIFAS[$k][1].' '.Cotizador::dinero($v, $moneda))->join(', ') : 'sin tarifas' }}
                                · aprueba {{ $a->responsable?->name ?? 'el responsable general' }}
                            </div>
                        </div>
                        <div class="res-acciones">
                            <a href="{{ route('reservas.configuracion.area', $a->id) }}" class="res-boton"><i class="bi bi-sliders" aria-hidden="true"></i>Configurar</a>
                            <form method="POST" action="{{ route('reservas.configuracion.area.estado', $a->id) }}" class="m-0"
                                  data-confirmar="{{ $a->reservable ? '¿Dejar de ofrecer «'.$a->nombre().'» para reservar? Sus reservas ya hechas se conservan.' : '¿Volver a ofrecer «'.$a->nombre().'» para reservar?' }}">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="reservable" value="{{ $a->reservable ? 0 : 1 }}">
                                <button type="submit" class="res-boton {{ $a->reservable ? 'rojo' : '' }}">{{ $a->reservable ? 'Dar de baja' : 'Reactivar' }}</button>
                            </form>
                        </div>
                    </div>
                </article>
            @empty
                <div class="tarjeta estado-vacio">
                    <div class="icono"><i class="bi bi-door-closed" aria-hidden="true"></i></div>
                    <p class="fw-semibold mb-1">Ninguna área se puede reservar todavía.</p>
                    <p class="text-muted small m-0">Elige arriba un espacio y toca <strong>Marcar como reservable</strong>.</p>
                </div>
            @endforelse
        </div>
    </section>

    @if ($empresaCompleta)
        {{-- ===================== Catálogos ===================== --}}
        @foreach (['montajes' => ['Montajes', 'Formas de acomodar el área (cada área dice cuántas personas caben en cada una).', $montajes],
                   'tipos' => ['Tipos de uso', 'Para qué se reserva (sirve para el informe de reservas por tipo).', $tipos]] as $clave => [$tituloCat, $ayuda, $lista])
            <section class="mb-4" id="cat-{{ $clave }}" aria-labelledby="t-{{ $clave }}">
                <h2 id="t-{{ $clave }}" class="h5 fw-bold">{{ $tituloCat }}</h2>
                <p class="res-meta">{{ $ayuda }}</p>
                <div class="res-tarjeta">
                    @foreach ($lista as $item)
                        <div class="res-aprobacion">
                            <form method="POST" action="{{ route('reservas.configuracion.catalogo.actualizar', [$clave, $item->id]) }}" class="d-flex gap-2 flex-grow-1 m-0">
                                @csrf
                                @method('PUT')
                                <label class="visually-hidden" for="{{ $clave }}-{{ $item->id }}">Nombre</label>
                                <input type="text" id="{{ $clave }}-{{ $item->id }}" name="nombre" class="campo" maxlength="60" value="{{ $item->nombre }}" required>
                                <button type="submit" class="res-boton" aria-label="Guardar {{ $item->nombre }}">Guardar</button>
                            </form>
                            <form method="POST" action="{{ route('reservas.configuracion.catalogo.estado', [$clave, $item->id]) }}" class="m-0">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="activo" value="{{ $item->activo ? 0 : 1 }}">
                                <button type="submit" class="res-boton {{ $item->activo ? 'rojo' : '' }}">{{ $item->activo ? 'Dar de baja' : 'Reactivar' }}</button>
                            </form>
                        </div>
                    @endforeach
                    <form method="POST" action="{{ route('reservas.configuracion.catalogo.crear') }}" class="d-flex gap-2 mt-3" autocomplete="off">
                        @csrf
                        <input type="hidden" name="catalogo" value="{{ $clave }}">
                        <label class="visually-hidden" for="nuevo-{{ $clave }}">Nuevo</label>
                        <input type="text" id="nuevo-{{ $clave }}" name="nombre" class="campo" maxlength="60" placeholder="{{ $clave === 'montajes' ? 'Ej. Mesa rusa' : 'Ej. Capacitación' }}" required>
                        <button type="submit" class="btn-verde text-nowrap"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Agregar</button>
                    </form>
                </div>
            </section>
        @endforeach

        <section class="mb-4" id="cat-servicios" aria-labelledby="t-servicios">
            <h2 id="t-servicios" class="h5 fw-bold">Servicios y requerimientos</h2>
            <p class="res-meta">Lo que se puede pedir con una reserva. El <strong>departamento</strong> lo aprueba (si lo marcas) y recibe su orden de servicio al confirmarse. Sus responsables son los de <strong>Departamentos → Responsables</strong>.</p>
            <div class="res-lista">
                @foreach ($servicios as $s)
                    <details class="res-tarjeta">
                        <summary class="d-flex flex-wrap justify-content-between gap-2">
                            <span><strong>{{ $s->nombre }}</strong> @unless ($s->activo)<span class="etiqueta-inactiva">INACTIVO</span>@endunless
                                <span class="res-meta">· {{ ReservaServicio::CATEGORIAS[$s->categoria] ?? 'Otro' }} · {{ ReservaServicio::UNIDADES[$s->unidad][0] }}{{ $s->costo !== null ? ' · '.Cotizador::dinero($s->costo, $moneda) : '' }}
                                · {{ $s->departamento?->nombre ?? 'sin departamento' }}{{ $s->departamento && $s->requiere_aprobacion ? ' (aprueba)' : '' }}</span></span>
                            <span class="res-meta">Editar</span>
                        </summary>
                        @include('operacion.reservas._servicio', ['s' => $s])
                        <form method="POST" action="{{ route('reservas.configuracion.catalogo.estado', ['servicios', $s->id]) }}" class="mt-2">
                            @csrf
                            @method('PATCH')
                            <input type="hidden" name="activo" value="{{ $s->activo ? 0 : 1 }}">
                            <button type="submit" class="res-boton {{ $s->activo ? 'rojo' : '' }}">{{ $s->activo ? 'Dar de baja' : 'Reactivar' }}</button>
                        </form>
                    </details>
                @endforeach
                <details class="res-tarjeta" @if ($servicios->isEmpty()) open @endif>
                    <summary class="fw-bold"><i class="bi bi-plus-circle me-1 text-success" aria-hidden="true"></i>Agregar servicio</summary>
                    @include('operacion.reservas._servicio', ['s' => null])
                </details>
            </div>
        </section>

        <section class="mb-4" aria-labelledby="t-ajustes">
            <h2 id="t-ajustes" class="h5 fw-bold">Quién aprueba, tiempos e IVA</h2>
            @include('operacion.reservas._ajustes', ['empresa' => $empresa, 'desde' => 'reservas', 'editable' => true])
        </section>
    @else
        <p class="res-meta">Los catálogos (montajes, tipos de uso y servicios) y los ajustes generales son de toda la empresa: los cambia quien configura reservas en toda la empresa.</p>
    @endif
</div>
@endsection
