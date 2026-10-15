@extends('correos.plantilla')
@php
    use App\Services\Comercial\Calculadora;
    $s = $solicitud;
    $dinero = fn ($v) => Calculadora::formato(Calculadora::centavos($v), $s->moneda);
@endphp
@section('cuerpo')
    @if ($interno)
        <p style="margin-top:0;font-size:18px;font-weight:bold;">Nueva solicitud {{ $s->folio }}</p>
        <p><strong>{{ $s->nombre }}</strong>{{ $s->empresa ? ' de '.$s->empresa : '' }} pidió información desde la página de planes.</p>
        <p>Correo: {{ $s->correo }}@if ($s->telefono)<br>Teléfono: {{ $s->telefono }}@endif</p>
    @else
        <p style="margin-top:0;font-size:18px;font-weight:bold;">Recibimos tu solicitud</p>
        <p>Hola, {{ $s->nombre }}. Tu folio es <strong>{{ $s->folio }}</strong>. Te contactaremos para confirmar tu plan y la forma de pago.</p>
    @endif
    <p>{{ $resumen }}</p>
    <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border-collapse:collapse;font-size:14px;">
        @foreach ($s->detalle['renglones'] ?? [] as $r)
            <tr>
                <td style="padding:6px 0;border-bottom:1px solid #e2e8f0;">{{ $r['nombre'] }}@if (($r['cantidad'] ?? 1) > 1) × {{ $r['cantidad'] }}@endif · {{ ($r['modalidad'] ?? '') === 'unico' ? 'pago único' : 'al mes' }}</td>
                <td style="padding:6px 0;border-bottom:1px solid #e2e8f0;text-align:right;">{{ ! empty($r['cotizar']) ? 'A cotizar' : Calculadora::formato((int) $r['importe']) }}</td>
            </tr>
        @endforeach
        @if ($s->mensual_subtotal > 0)
            <tr><td style="padding:6px 0;">Al mes, antes de IVA</td><td style="padding:6px 0;text-align:right;">{{ $dinero($s->mensual_subtotal) }}</td></tr>
            <tr><td style="padding:6px 0;">IVA {{ rtrim(rtrim(number_format((float) $s->iva_porcentaje, 2), '0'), '.') }} %</td><td style="padding:6px 0;text-align:right;">{{ $dinero($s->mensual_iva) }}</td></tr>
            <tr><td style="padding:6px 0;font-weight:bold;">Total al mes</td><td style="padding:6px 0;text-align:right;font-weight:bold;">{{ $dinero($s->mensual_total) }}</td></tr>
        @endif
        @if ($s->unico_subtotal > 0)
            <tr><td style="padding:6px 0;font-weight:bold;">Pago único con IVA</td><td style="padding:6px 0;text-align:right;font-weight:bold;">{{ $dinero($s->unico_total) }}</td></tr>
        @endif
    </table>
    @if ($interno && $enlace)
        <p style="margin-bottom:0;margin-top:20px;"><a href="{{ $enlace }}" style="display:inline-block;background:#059669;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:10px;font-weight:bold;">Atender la solicitud</a></p>
    @endif
@endsection
