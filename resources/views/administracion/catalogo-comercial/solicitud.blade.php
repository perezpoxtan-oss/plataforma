@extends('layouts.app')

@php
    use App\Services\Comercial\Calculadora;
    use App\Models\SolicitudCompra;
    $f = fn ($c) => Calculadora::formato((int) $c);
    $dinero = fn ($v) => Calculadora::formato(Calculadora::centavos($v), $s->moneda);
    $reloj = $s->estado === 'nueva' ? \App\Support\RelojVoBo::de($s->created_at) : null;
@endphp

@section('titulo', 'Solicitud '.$s->folio)

@section('contenido')
    @include('administracion.partes.avisos')
    <a href="{{ route('catalogo-comercial.index', ['pestana' => 'solicitudes']) }}" class="enlace-volver"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Solicitudes</a>

    <div class="encabezado-pantalla mb-4 mt-2">
        <div class="icono"><i class="bi bi-cart-check text-success" aria-hidden="true"></i></div>
        <div>
            <h1>Solicitud {{ $s->folio }}</h1>
            <p>Recibida @fecha($s->created_at) · <span class="etiqueta-estado {{ $s->estado === 'pagada' ? 'activo' : ($s->estado === 'cancelada' ? 'inactivo' : 'pendiente') }}">{{ $s->estadoTexto() }}</span>
                @if ($reloj)<span class="reloj-vobo espera-{{ $reloj['nivel'] }} ms-1"><i class="bi bi-clock" aria-hidden="true"></i>Sin atender {{ $reloj['hace'] }} ({{ $reloj['etiqueta'] }})</span>@endif</p>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="tarjeta p-4 mb-4">
                <h2 class="h6 fw-bold mb-3"><i class="bi bi-person me-1 text-primary" aria-hidden="true"></i> Interesado</h2>
                <dl class="row small mb-0">
                    <dt class="col-sm-4">Nombre</dt><dd class="col-sm-8">{{ $s->nombre }}</dd>
                    <dt class="col-sm-4">Empresa</dt><dd class="col-sm-8">{{ $s->empresa ?: 'Sin dato' }}</dd>
                    <dt class="col-sm-4">Correo</dt><dd class="col-sm-8"><a href="mailto:{{ $s->correo }}">{{ $s->correo }}</a></dd>
                    <dt class="col-sm-4">Teléfono</dt><dd class="col-sm-8">@if ($s->telefono)<a href="tel:{{ preg_replace('/[^0-9+]/', '', $s->telefono) }}">{{ $s->telefono }}</a>@else Sin dato @endif</dd>
                    <dt class="col-sm-4">Sedes</dt><dd class="col-sm-8">{{ $s->sedes }}</dd>
                    <dt class="col-sm-4">Aviso de privacidad</dt><dd class="col-sm-8">{{ $s->acepta_privacidad ? 'Aceptado' : 'No' }}@if ($s->privacidad_aceptada_en) (@fecha($s->privacidad_aceptada_en))@endif</dd>
                </dl>
                @if ($s->mensaje)
                    <h3 class="h6 fw-bold mt-3">Mensaje</h3>
                    <p class="small mb-0" style="white-space: pre-line;">{{ $s->mensaje }}</p>
                @endif
            </div>

            <div class="tarjeta p-4">
                <h2 class="h6 fw-bold mb-1"><i class="bi bi-receipt me-1 text-primary" aria-hidden="true"></i> Desglose</h2>
                <p class="small text-muted">Calculado al recibirla, con los precios de ese momento (IVA {{ rtrim(rtrim(number_format((float) $s->iva_porcentaje, 2), '0'), '.') }} %).</p>
                <table class="table table-sm small mb-2">
                    <tbody>
                        @foreach ($s->detalle['renglones'] ?? [] as $r)
                            <tr><td>{{ $r['nombre'] }}@if (($r['cantidad'] ?? 1) > 1) × {{ $r['cantidad'] }}@endif <span class="text-muted">{{ ($r['modalidad'] ?? '') === 'unico' ? 'pago único' : 'al mes' }}</span></td>
                                <td class="text-end">{{ ! empty($r['cotizar']) ? 'A cotizar' : $f($r['importe']) }}</td></tr>
                        @endforeach
                    </tbody>
                </table>
                <dl class="row small mb-0">
                    <dt class="col-7">Al mes, antes de IVA</dt><dd class="col-5 text-end">{{ $dinero($s->mensual_subtotal) }}</dd>
                    <dt class="col-7">IVA</dt><dd class="col-5 text-end">{{ $dinero($s->mensual_iva) }}</dd>
                    <dt class="col-7">Total al mes</dt><dd class="col-5 text-end fw-bold">{{ $dinero($s->mensual_total) }}</dd>
                    @if ($s->unico_subtotal > 0)
                        <dt class="col-7">Pago único con IVA</dt><dd class="col-5 text-end fw-bold">{{ $dinero($s->unico_total) }}</dd>
                    @endif
                </dl>
                @if ($s->requiere_cotizacion)<p class="small text-warning-emphasis mt-2 mb-0"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Pidió un plan «Cotizar»: envíale la cotización.</p>@endif
            </div>
        </div>

        <div class="col-lg-6">
            <form action="{{ route('catalogo-comercial.solicitudes.atender', $s->id) }}" method="POST" class="tarjeta p-4">
                @csrf
                @method('PUT')
                <fieldset @disabled(! $puedeEditar)>
                    <h2 class="h6 fw-bold mb-3"><i class="bi bi-check2-square me-1 text-primary" aria-hidden="true"></i> Atender</h2>
                    <label class="campo-etiqueta" for="estado">Estado</label>
                    <select id="estado" name="estado" class="campo">
                        @foreach (SolicitudCompra::ESTADOS as $clave => $texto)
                            <option value="{{ $clave }}" @selected(old('estado', $s->estado) === $clave)>{{ $texto }}</option>
                        @endforeach
                    </select>
                    <p class="campo-ayuda mt-0">Nueva → Contactada (ya le hablaste) → Cotizada (le enviaste la propuesta) → Pagada o Cancelada.</p>
                    <label class="campo-etiqueta" for="referencia_pago">Referencia del pago</label>
                    <input type="text" id="referencia_pago" name="referencia_pago" class="campo" maxlength="100" value="{{ old('referencia_pago', $s->referencia_pago) }}" placeholder="Folio de la transferencia o del recibo">
                    <label class="campo-etiqueta" for="notas_internas">Notas internas (el interesado no las ve)</label>
                    <textarea id="notas_internas" name="notas_internas" class="campo" rows="6" maxlength="5000">{{ old('notas_internas', $s->notas_internas) }}</textarea>
                    <p class="small text-muted mb-0">Cobro: {{ $s->pasarela === 'manual' || $s->pasarela === null ? 'manual (sin cobro en línea)' : $s->pasarela }}</p>
                </fieldset>
                @if ($puedeEditar)
                    <button type="submit" class="btn-guardar-identidad"><i class="bi bi-save2-fill me-2" aria-hidden="true"></i>Guardar</button>
                @endif
            </form>
        </div>
    </div>
@endsection
