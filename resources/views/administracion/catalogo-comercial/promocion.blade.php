@extends('layouts.app')

@php
    use App\Models\Promocion;
    $nueva = ! $p->exists;
    $v = fn (string $campo, $omision = null) => old($campo, $p->exists ? $p->{$campo} : $omision);
    $fecha = fn (string $campo) => old($campo, $p->{$campo}?->format('Y-m-d'));
    $marcado = fn ($id) => in_array((string) $id, array_map('strval', (array) old('planes', $marcados)), true);
@endphp

@section('titulo', $nueva ? 'Nueva promoción' : 'Editar promoción')

@section('contenido')
    @include('administracion.partes.avisos')
    <a href="{{ route('catalogo-comercial.index', ['pestana' => 'promociones']) }}" class="enlace-volver"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Promociones</a>

    <div class="encabezado-pantalla mb-4 mt-2">
        <div class="icono"><i class="bi bi-ticket-perforated text-success" aria-hidden="true"></i></div>
        <div>
            <h1>{{ $nueva ? 'Nueva promoción' : 'Editar promoción: '.$p->nombre }}</h1>
            <p>Un descuento al mes sobre el plan y sus paquetes de suscripción (nunca sobre el pago único).</p>
        </div>
    </div>

    <form action="{{ $nueva ? route('catalogo-comercial.promociones.guardar') : route('catalogo-comercial.promociones.actualizar', $p->id) }}" method="POST" class="tarjeta p-4" autocomplete="off" style="max-width: 860px;">
        @csrf
        @unless ($nueva) @method('PUT') @endunless
        <div class="row">
            <div class="col-md-7">
                <label class="campo-etiqueta" for="nombre">Nombre *</label>
                <input type="text" id="nombre" name="nombre" class="campo" maxlength="100" required value="{{ $v('nombre') }}" placeholder="Bienvenida 15 %">
            </div>
            <div class="col-md-5">
                <label class="campo-etiqueta" for="codigo">Código (opcional)</label>
                <input type="text" id="codigo" name="codigo" class="campo text-uppercase" maxlength="40" value="{{ $v('codigo') }}" placeholder="BIENVENIDA15">
                <p class="campo-ayuda mt-0">Lo escribe el visitante en la página de planes. Vacío: solo se aplica a mano a un cliente.</p>
            </div>
        </div>
        <div class="row">
            <div class="col-md-4">
                <label class="campo-etiqueta" for="tipo">Tipo de descuento</label>
                <select id="tipo" name="tipo" class="campo">
                    @foreach (Promocion::TIPOS as $clave => $texto)
                        <option value="{{ $clave }}" @selected($v('tipo', Promocion::PORCENTAJE) === $clave)>{{ $texto }}</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-4">
                <label class="campo-etiqueta" for="valor">Descuento *</label>
                <input type="number" id="valor" name="valor" class="campo" min="0.01" step="0.01" required value="{{ $v('valor') }}" placeholder="15">
                <p class="campo-ayuda mt-0">Porcentaje (15 = 15 %) o pesos al mes.</p>
            </div>
            <div class="col-md-4">
                <label class="campo-etiqueta" for="meses">Solo los primeros … meses</label>
                <input type="number" id="meses" name="meses" class="campo" min="1" max="120" value="{{ $v('meses') }}" placeholder="Siempre">
                <p class="campo-ayuda mt-0">Vacío = mientras dure la suscripción. En el pago anual cubre hasta 12 meses.</p>
            </div>
        </div>
        <details @if (! $nueva || $errors->hasAny(['periodicidad', 'vigente_desde', 'vigente_hasta', 'usos_maximos', 'planes'])) open @endif>
            <summary class="fw-bold mb-2">Avanzado: vigencia, límite de usos, planes y forma de pago</summary>
            <div class="row">
                <div class="col-md-4">
                    <label class="campo-etiqueta" for="vigente_desde">Vale desde</label>
                    <input type="date" id="vigente_desde" name="vigente_desde" class="campo" value="{{ $fecha('vigente_desde') }}">
                </div>
                <div class="col-md-4">
                    <label class="campo-etiqueta" for="vigente_hasta">Vale hasta</label>
                    <input type="date" id="vigente_hasta" name="vigente_hasta" class="campo" value="{{ $fecha('vigente_hasta') }}">
                </div>
                <div class="col-md-4">
                    <label class="campo-etiqueta" for="usos_maximos">Límite de usos</label>
                    <input type="number" id="usos_maximos" name="usos_maximos" class="campo" min="1" value="{{ $v('usos_maximos') }}" placeholder="Sin límite">
                    <p class="campo-ayuda mt-0">Un uso se cuenta cuando el cliente se activa.@unless ($nueva) Usada {{ $p->usos }} {{ $p->usos === 1 ? 'vez' : 'veces' }}.@endunless</p>
                </div>
            </div>
            <label class="campo-etiqueta" for="periodicidad">Forma de pago</label>
            <select id="periodicidad" name="periodicidad" class="campo" style="max-width: 320px;">
                @foreach (Promocion::PERIODICIDADES as $clave => $texto)
                    <option value="{{ $clave }}" @selected($v('periodicidad', 'ambas') === $clave)>{{ $texto }}</option>
                @endforeach
            </select>
            <fieldset class="mb-3">
                <legend class="campo-etiqueta">Planes a los que aplica (ninguno marcado = todos)</legend>
                @foreach ($planes as $plan)
                    <div class="form-check form-check-inline">
                        <input class="form-check-input" type="checkbox" id="plan-{{ $plan->id }}" name="planes[]" value="{{ $plan->id }}" @checked($marcado($plan->id))>
                        <label class="form-check-label small" for="plan-{{ $plan->id }}">{{ $plan->nombre }}@unless ($plan->activo) (de baja)@endunless</label>
                    </div>
                @endforeach
            </fieldset>
        </details>
        <button type="submit" class="btn-guardar-identidad"><i class="bi bi-save2-fill me-2" aria-hidden="true"></i>Guardar</button>
    </form>
@endsection
