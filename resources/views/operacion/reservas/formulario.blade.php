@extends('layouts.app')

@section('titulo', $reserva ? 'Modificar reserva '.$reserva->folioTexto() : 'Nueva reserva')

@php
    $v = fn (string $campo) => old($campo, $valores[$campo] ?? '');
    $paraCliente = filter_var(old('para_cliente', $valores['para_cliente'] ? '1' : '0'), FILTER_VALIDATE_BOOLEAN);
    $conServicios = collect(old('servicios', $valores['servicios']))->contains(fn ($f) => (float) ($f['cantidad'] ?? 0) > 0);
    $puede = ['crear' => true, 'ver' => auth()->user()->can('reservas.ver'), 'aprobar' => auth()->user()->can('reservas.aprobar'),
        'configurar' => auth()->user()->can('reservas.configurar'), 'exportar' => false];
@endphp

@section('contenido')
<div class="tema-verde">
    @include('administracion.partes.avisos')
    @include('operacion.reservas._nav', [
        'titulo' => $reserva ? 'Modificar reserva '.$reserva->folioTexto() : 'Nueva reserva',
        'descripcion' => $reserva ? 'Si cambias el área, el horario o los servicios, se vuelve a pedir la aprobación de lo que cambió.' : 'Lo indispensable: dónde, día, horario y para qué. Lo demás es opcional.',
        'icono' => 'bi-calendar-plus', 'actual' => $reserva ? 'mias' : 'calendario',
    ])

    @if ($areas->isEmpty())
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-door-closed" aria-hidden="true"></i></div>
            <p class="fw-semibold mb-1">No hay áreas que puedas reservar en tus sedes.</p>
            <p class="text-muted small m-0">Pide a quien administra las reservas que agregue un salón, sala o habitación como área reservable.</p>
        </div>
    @else
    <form method="POST" action="{{ $reserva ? route('reservas.update', $reserva->id) : route('reservas.store') }}" class="res-form" autocomplete="off"
          data-form-reserva data-areas="{{ json_encode($areas) }}" data-disponibilidad="{{ route('reservas.disponibilidad') }}"
          data-excepto="{{ $reserva?->id }}" data-iva="{{ $ajustes['iva_aplica'] ? $ajustes['iva_porcentaje'] : 0 }}" data-moneda="{{ $ajustes['moneda'] }}">
        @csrf
        @if ($reserva) @method('PUT') @endif

        {{-- ===== Lo indispensable ===== --}}
        <section class="res-bloque" aria-labelledby="t-cuando">
            <h2 id="t-cuando"><i class="bi bi-calendar-event me-1 text-success" aria-hidden="true"></i>¿Dónde y cuándo?</h2>
            <div class="res-rejilla">
                @php
                    $elegida = (int) $v('reserva_area_id');
                    $zonaInicial = $areas->firstWhere('id', $elegida)['zona'] ?? (int) request('zona');
                @endphp
                {{-- Cascada: primero la zona (o el área) y después el espacio final --}}
                <div>
                    <label class="campo-etiqueta" for="res_zona">Zona o área <span class="text-danger" aria-hidden="true">*</span></label>
                    <select id="res_zona" class="campo" data-res-zona>
                        <option value="">Elige dónde…</option>
                        @foreach ($areas->groupBy('sede') as $sedeNombre => $grupo)
                            <optgroup label="{{ $sedeNombre }}">
                                @foreach ($grupo->unique('zona') as $a)
                                    @php $cuantos = $areas->where('zona', $a['zona'])->count(); @endphp
                                    <option value="{{ $a['zona'] }}" @selected($zonaInicial === $a['zona'])>{{ $a['zona_nombre'] }}{{ $cuantos > 1 ? ' ('.$cuantos.' espacios)' : '' }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </div>
                <div data-res-espacio-campo>
                    <label class="campo-etiqueta" for="reserva_area_id">Espacio <span class="text-danger" aria-hidden="true">*</span></label>
                    <select id="reserva_area_id" name="reserva_area_id" class="campo" required data-res-area>
                        <option value="">Elige un espacio…</option>
                        @foreach ($areas->groupBy('sede') as $sedeNombre => $grupo)
                            <optgroup label="{{ $sedeNombre }}">
                                @foreach ($grupo as $a)
                                    <option value="{{ $a['id'] }}" data-zona="{{ $a['zona'] }}" @selected($elegida === $a['id'])>{{ $a['texto'] }}{{ $a['aforo'] ? ' (hasta '.$a['aforo'].')' : '' }}</option>
                                @endforeach
                            </optgroup>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="campo-etiqueta" for="fecha">Día <span class="text-danger" aria-hidden="true">*</span></label>
                    <input type="date" id="fecha" name="fecha" class="campo" required value="{{ $v('fecha') }}" data-res-fecha>
                </div>
                <div>
                    <label class="campo-etiqueta" for="hora_inicio">De <span class="text-danger" aria-hidden="true">*</span></label>
                    <input type="time" id="hora_inicio" name="hora_inicio" class="campo" required value="{{ $v('hora_inicio') }}" step="900" data-res-inicio>
                </div>
                <div>
                    <label class="campo-etiqueta" for="hora_fin">A <span class="text-danger" aria-hidden="true">*</span></label>
                    <input type="time" id="hora_fin" name="hora_fin" class="campo" required value="{{ $v('hora_fin') }}" step="900" data-res-fin>
                </div>
            </div>
            <div class="res-ocupado mt-3" data-res-ocupado aria-live="polite">
                <span class="res-meta">Elige el área y el día para ver los horarios ocupados.</span>
            </div>
            <div class="res-meta mt-2" data-res-info hidden></div>
        </section>

        <section class="res-bloque" aria-labelledby="t-que">
            <h2 id="t-que"><i class="bi bi-people me-1 text-success" aria-hidden="true"></i>¿Para qué?</h2>
            <div class="res-rejilla">
                <div style="grid-column: span 2">
                    <label class="campo-etiqueta" for="titulo">Nombre del evento o reunión <span class="text-danger" aria-hidden="true">*</span></label>
                    <input type="text" id="titulo" name="titulo" class="campo" required maxlength="150" value="{{ $v('titulo') }}" placeholder="Ej. Junta de jefes de departamento">
                </div>
                <div>
                    <label class="campo-etiqueta" for="tipo_uso_id">Tipo de uso</label>
                    <select id="tipo_uso_id" name="tipo_uso_id" class="campo">
                        <option value="">Sin especificar</option>
                        @foreach ($tipos as $t)<option value="{{ $t->id }}" @selected((int) $v('tipo_uso_id') === $t->id)>{{ $t->nombre }}</option>@endforeach
                    </select>
                </div>
                <div>
                    <label class="campo-etiqueta" for="asistentes">Personas <span class="text-danger" aria-hidden="true">*</span></label>
                    <input type="number" id="asistentes" name="asistentes" class="campo" required min="1" max="100000" value="{{ $v('asistentes') }}" data-res-asistentes>
                </div>
                <div>
                    <label class="campo-etiqueta" for="montaje_id">Montaje</label>
                    <select id="montaje_id" name="montaje_id" class="campo" data-res-montaje data-valor="{{ $v('montaje_id') }}">
                        <option value="">Sin montaje especial</option>
                    </select>
                </div>
            </div>

            <fieldset class="mt-3">
                <legend class="campo-etiqueta">¿Para quién es?</legend>
                <div class="res-opciones">
                    <label class="res-opcion"><input type="radio" name="para_cliente" value="0" @checked(! $paraCliente) data-res-para> <span><strong>Uso interno</strong><br><span class="res-meta">Sin costo</span></span></label>
                    <label class="res-opcion"><input type="radio" name="para_cliente" value="1" @checked($paraCliente) data-res-para> <span><strong>Para un cliente de la sede</strong><br><span class="res-meta">Con costo: tarifa del área y de los servicios</span></span></label>
                </div>
            </fieldset>
            <div class="res-rejilla mt-3" data-res-cliente @if (! $paraCliente) hidden @endif>
                <div>
                    <label class="campo-etiqueta" for="cliente_nombre">Nombre del cliente (contacto) <span class="text-danger" aria-hidden="true">*</span></label>
                    <input type="text" id="cliente_nombre" name="cliente_nombre" class="campo" maxlength="150" value="{{ $v('cliente_nombre') }}">
                </div>
                <div>
                    <label class="campo-etiqueta" for="cliente_empresa">Empresa del cliente</label>
                    <input type="text" id="cliente_empresa" name="cliente_empresa" class="campo" maxlength="150" value="{{ $v('cliente_empresa') }}">
                </div>
                <div>
                    <label class="campo-etiqueta" for="cliente_correo">Correo</label>
                    <input type="email" id="cliente_correo" name="cliente_correo" class="campo" maxlength="150" value="{{ $v('cliente_correo') }}">
                </div>
                <div>
                    <label class="campo-etiqueta" for="cliente_telefono">Teléfono</label>
                    <input type="tel" id="cliente_telefono" name="cliente_telefono" class="campo" maxlength="30" value="{{ $v('cliente_telefono') }}">
                </div>
                <div data-res-tarifa-campo>
                    <label class="campo-etiqueta" for="tarifa">Tarifa del área</label>
                    <select id="tarifa" name="tarifa" class="campo" data-res-tarifa data-valor="{{ $v('tarifa') }}"></select>
                </div>
            </div>
        </section>

        {{-- ===== Servicios (opcional, plegado) ===== --}}
        <details class="res-bloque" @if ($conServicios) open @endif>
            <summary class="fw-bold"><i class="bi bi-cup-hot me-1 text-success" aria-hidden="true"></i>Servicios y requerimientos <span class="res-meta fw-normal">(opcional: alimentos, bebidas, proyector, internet, mobiliario…)</span></summary>
            @if ($servicios->isEmpty())
                <p class="res-meta mt-3 mb-0">Tu empresa todavía no tiene servicios en su catálogo.</p>
            @else
                <p class="res-meta mt-3">Escribe la cantidad de lo que necesitas. Cada departamento aprueba lo suyo y, al confirmarse, recibe su orden de servicio.</p>
                @foreach ($servicios as $categoria => $grupo)
                    <h3 class="h6 fw-bold mt-3 mb-1">{{ \App\Models\ReservaServicio::CATEGORIAS[$categoria] ?? 'Otro' }}</h3>
                    @foreach ($grupo as $s)
                        <div class="res-servicio">
                            <div>
                                <label for="servicio_{{ $s->id }}" class="fw-semibold">{{ $s->nombre }}</label>
                                <div class="res-meta">
                                    {{ \App\Models\ReservaServicio::UNIDADES[$s->unidad][0] }}
                                    @if ($s->costo !== null)<span data-res-solo-cliente @if (! $paraCliente) hidden @endif> · {{ \App\Services\Reservas\Cotizador::dinero($s->costo, $ajustes['moneda']) }}</span>@endif
                                    @if ($s->departamento) · lo atiende {{ $s->departamento->nombre }}@endif
                                </div>
                            </div>
                            <input type="number" id="servicio_{{ $s->id }}" name="servicios[{{ $s->id }}][cantidad]" class="campo" min="0" step="0.5" max="100000"
                                   value="{{ old('servicios.'.$s->id.'.cantidad', $valores['servicios'][$s->id]['cantidad'] ?? '') }}" placeholder="Cantidad"
                                   aria-label="Cantidad de {{ $s->nombre }}" data-res-servicio data-unidad="{{ $s->unidad }}" data-costo="{{ (float) ($s->costo ?? 0) }}">
                            <input type="text" name="servicios[{{ $s->id }}][notas]" class="campo res-servicio-notas" maxlength="255"
                                   value="{{ old('servicios.'.$s->id.'.notas', $valores['servicios'][$s->id]['notas'] ?? '') }}" placeholder="Indicaciones (opcional)" aria-label="Indicaciones para {{ $s->nombre }}">
                        </div>
                    @endforeach
                @endforeach
            @endif
        </details>

        <details class="res-bloque" @if ($v('notas')) open @endif>
            <summary class="fw-bold"><i class="bi bi-sliders me-1 text-success" aria-hidden="true"></i>Avanzado <span class="res-meta fw-normal">(notas para quien aprueba)</span></summary>
            <label class="campo-etiqueta mt-3" for="notas">Notas</label>
            <textarea id="notas" name="notas" class="campo" rows="3" maxlength="2000">{{ $v('notas') }}</textarea>
        </details>

        <section class="res-bloque" data-res-resumen-costo @if (! $paraCliente) hidden @endif aria-live="polite">
            <h2><i class="bi bi-cash-coin me-1 text-success" aria-hidden="true"></i>Costo estimado</h2>
            <div class="res-meta" data-res-costo>Elige el área, el horario y la tarifa.</div>
            <p class="res-meta mt-2 mb-0">Es un cálculo previo: el total definitivo queda en la reserva{{ $puedeAjustar ? ' (ahí puedes aplicar un descuento o cortesía con su motivo)' : '' }}.</p>
        </section>

        <div class="d-flex flex-wrap gap-2">
            <button type="submit" class="btn-verde btn-accion-rh"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Guardar reserva</button>
            <a href="{{ $reserva ? route('reservas.show', $reserva->id) : route('reservas.index') }}" class="btn-secundario-rh">Cancelar</a>
        </div>
    </form>
    @endif
</div>
@push('scripts')
    <script src="{{ asset('js/reservas.js') }}?v={{ @filemtime(public_path('js/reservas.js')) ?: '1' }}"></script>
@endpush
@endsection
