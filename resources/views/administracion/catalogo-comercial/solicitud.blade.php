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
            <h1>Solicitud {{ $s->folio }}@if ($s->tipo !== 'compra') <span class="badge {{ $s->tipo === 'prueba' ? 'text-bg-info' : 'text-bg-secondary' }} align-middle fs-6">{{ $s->tipoTexto() }}</span>@endif</h1>
            <p>Recibida @fecha($s->created_at) · <span class="etiqueta-estado {{ $s->estado === 'pagada' ? 'activo' : ($s->estado === 'cancelada' ? 'inactivo' : 'pendiente') }}">{{ $s->estadoTexto() }}</span>
                @if ($reloj)<span class="reloj-vobo espera-{{ $reloj['nivel'] }} ms-1"><i class="bi bi-clock" aria-hidden="true"></i>Sin atender {{ $reloj['hace'] }} ({{ $reloj['etiqueta'] }})</span>@endif</p>
        </div>
    </div>

    @if ($suscripcion)
        <div class="alert alert-success small d-flex flex-wrap align-items-center gap-2" role="status">
            <i class="bi bi-building-check" aria-hidden="true"></i>
            <span>{{ $s->activada_en ? 'Esta solicitud ya se aplicó' : 'Este cliente ya existe' }}: «{{ $suscripcion->empresa?->nombre_comercial }}» ({{ $suscripcion->estadoTexto($suscripcion->estadoEfectivo()) }}).</span>
            <a href="{{ route('catalogo-comercial.clientes.show', $suscripcion->id) }}" class="btn btn-sm btn-success fw-bold ms-auto">Atender al cliente</a>
        </div>
    @elseif ($s->esPrueba() && $s->activada_en === null && $s->estado !== 'cancelada' && $puedeEditar)
        <form action="{{ route('catalogo-comercial.solicitudes.aprobar-prueba', $s->id) }}" method="POST" class="tarjeta p-4 mb-4 d-flex flex-column flex-md-row gap-3 align-items-md-center"
              data-confirmar="¿Aprobar la prueba gratis de {{ $s->empresa ?: $s->nombre }}? Se crea su empresa con una sede, sus módulos y su Administrador ({{ $s->correo }}), que recibe el correo de bienvenida.">
            @csrf
            <div class="flex-grow-1">
                <h2 class="h6 fw-bold mb-1"><i class="bi bi-hourglass-split me-1 text-primary" aria-hidden="true"></i> Prueba gratis por aprobar</h2>
                <p class="small mb-0">La prueba no se activa sola (para evitar abusos). Al aprobarla, el cliente puede usar la plataforma {{ $diasPrueba }} días; después tiene {{ $diasGracia }} días de gracia para contratar y luego queda en solo consulta.</p>
            </div>
            <button type="submit" class="btn btn-success fw-bold text-nowrap"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Aprobar prueba gratis</button>
        </form>
    @endif

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
                    <dt class="col-sm-4">Forma de pago</dt><dd class="col-sm-8">{{ $s->periodicidad === 'anual' ? 'Anual' : 'Mensual' }}</dd>
                    @if ($s->codigo_promocion)<dt class="col-sm-4">Código</dt><dd class="col-sm-8"><code>{{ $s->codigo_promocion }}</code></dd>@endif
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
                @php $d = $s->detalle ?? []; $conPeriodo = $s->periodo_total > 0 || $s->descuento > 0; @endphp
                <dl class="row small mb-0">
                    <dt class="col-7">Al mes, antes de IVA y descuentos</dt><dd class="col-5 text-end">{{ $dinero($s->mensual_subtotal) }}</dd>
                    @if (($d['descuento_anual'] ?? 0) > 0)<dt class="col-7">Descuento por pago anual</dt><dd class="col-5 text-end">−{{ $f($d['descuento_anual']) }}</dd>@endif
                    @if (($d['descuento_promocion'] ?? 0) > 0)<dt class="col-7">Promoción {{ $d['promocion']['nombre'] ?? '' }}</dt><dd class="col-5 text-end">−{{ $f($d['descuento_promocion']) }}</dd>@endif
                    <dt class="col-7">{{ $s->periodicidad === 'anual' ? 'Al año' : 'Al mes' }}, antes de IVA</dt><dd class="col-5 text-end">{{ $dinero($conPeriodo ? $s->periodo_subtotal : $s->mensual_subtotal) }}</dd>
                    <dt class="col-7">IVA</dt><dd class="col-5 text-end">{{ $dinero($conPeriodo ? $s->periodo_iva : $s->mensual_iva) }}</dd>
                    <dt class="col-7">Total {{ $s->periodoTexto() }}</dt><dd class="col-5 text-end fw-bold">{{ $dinero($conPeriodo ? $s->periodo_total : $s->mensual_total) }}</dd>
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
                    <p class="campo-ayuda mt-0">Nueva → Contactada (ya le hablaste) → Cotizada (le enviaste la propuesta) → Pagada o Cancelada.
                        <strong>Pagada</strong> activa al cliente sola (empresa, sede, Administrador con correo de bienvenida, módulos del plan) o, si ya es cliente, registra su pago.</p>
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
