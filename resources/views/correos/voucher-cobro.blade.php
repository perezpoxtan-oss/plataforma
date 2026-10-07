@extends('correos.plantilla')
@section('cuerpo')
    <p style="margin-top:0;font-size:12px;font-weight:bold;letter-spacing:.08em;text-transform:uppercase;color:#64748b;">{{ $copia }}</p>
    <p style="margin-top:0;font-size:18px;font-weight:bold;">Voucher de reposición {{ $folio }} — con cobro</p>
    <p>Se dio de baja un artículo ({{ mb_strtolower($origen) }}) y se generó un voucher con cargo (CXC).</p>
    <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;font-size:14px;margin-bottom:12px;">
        <tr><td style="padding:4px 0;color:#64748b;width:140px;">Monto</td><td style="padding:4px 0;font-weight:bold;font-size:18px;">${{ number_format($monto, 2) }} MXN</td></tr>
        <tr><td style="padding:4px 0;color:#64748b;">{{ $origen }}</td><td style="padding:4px 0;">{{ $articulo }}</td></tr>
        <tr><td style="padding:4px 0;color:#64748b;">Motivo</td><td style="padding:4px 0;">{{ $motivo }}</td></tr>
        @if ($sede)<tr><td style="padding:4px 0;color:#64748b;">Sede</td><td style="padding:4px 0;">{{ $sede }}</td></tr>@endif
        @if ($responsable)<tr><td style="padding:4px 0;color:#64748b;">Responsable</td><td style="padding:4px 0;">{{ $responsable }}</td></tr>@endif
        @if ($firma)<tr><td style="padding:4px 0;color:#64748b;">Firmas</td><td style="padding:4px 0;">{{ $firma }}</td></tr>@endif
    </table>
    <p>Generó: {{ $registradoPor }} · {{ $fecha }}</p>
    <p style="margin-bottom:0;"><a href="{{ $enlace }}" style="display:inline-block;background:#0f172a;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:10px;font-weight:bold;">Ver / imprimir el voucher</a></p>
    <p style="font-size:12px;color:#64748b;margin-bottom:0;">El enlace pide entrar a la plataforma: solo lo abre quien tiene permiso de imprimir vouchers.</p>
@endsection
