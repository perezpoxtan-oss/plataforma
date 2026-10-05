@extends('correos.plantilla')
@section('cuerpo')
    <p style="margin-top:0;font-size:18px;font-weight:bold;">Vale de taxi {{ $folio }} — requiere autorización</p>
    <p>Se registró un vale de taxi en la Bitácora de transporte porque la unidad de la ruta no llegó.</p>
    <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;font-size:14px;margin-bottom:12px;">
        <tr><td style="padding:4px 0;color:#64748b;width:140px;">Monto</td><td style="padding:4px 0;font-weight:bold;font-size:18px;">${{ number_format($monto, 2) }} MXN</td></tr>
        @if ($sede)<tr><td style="padding:4px 0;color:#64748b;">Sede</td><td style="padding:4px 0;">{{ $sede }}</td></tr>@endif
        @if ($ruta)<tr><td style="padding:4px 0;color:#64748b;">Ruta</td><td style="padding:4px 0;">{{ $ruta }}</td></tr>@endif
        @if ($conductor)<tr><td style="padding:4px 0;color:#64748b;">Conductor</td><td style="padding:4px 0;">{{ $conductor }}</td></tr>@endif
        @if ($destino)<tr><td style="padding:4px 0;color:#64748b;">Destino</td><td style="padding:4px 0;">{{ $destino }}</td></tr>@endif
        <tr><td style="padding:4px 0;color:#64748b;">Pasajeros</td><td style="padding:4px 0;">{{ $pasajeros }}</td></tr>
        @if ($justificacion)<tr><td style="padding:4px 0;color:#b45309;">Supera el tope</td><td style="padding:4px 0;">{{ $justificacion }}</td></tr>@endif
    </table>
    <p>Registró: {{ $registradoPor }} · {{ $fecha }}</p>
    <p style="margin-bottom:0;"><a href="{{ $enlace }}" style="display:inline-block;background:#dc2626;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:10px;font-weight:bold;">Ver / imprimir el vale</a></p>
@endsection
