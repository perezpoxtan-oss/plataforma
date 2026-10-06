@extends('correos.plantilla')
@section('cuerpo')
    @php
        [$titulo, $color, $boton] = match ($tipo) {
            'por_aprobar' => ["Pase {$folio}: espera tu aprobación", '#2563eb', 'Revisar y aprobar'],
            'aprobado' => ["Pase {$folio}: aprobado", '#16a34a', 'Ver el pase'],
            'rechazado' => ["Pase {$folio}: rechazado", '#dc2626', 'Ver motivos y corregir'],
            default => ["Pase {$folio}: vencido", '#dc2626', 'Ver el pase'],
        };
    @endphp
    <p style="margin-top:0;font-size:18px;font-weight:bold;">{{ $titulo }}</p>
    @switch ($tipo)
        @case ('por_aprobar')
            <p>Te toca firmar el paso <strong>{{ $paso }}</strong> de este pase de salida.</p>
            @break
        @case ('aprobado')
            <p>Ya firmaron todas las aprobaciones. La caseta puede registrar la salida.</p>
            @break
        @case ('rechazado')
            <p>El pase se rechazó{{ $paso ? ' en el paso «'.$paso.'»' : '' }} y regresó al solicitante. Corrígelo y reenvíalo, o cancélalo.</p>
            @break
        @default
            <p>El equipo debió regresar{{ $fecha ? ' el '.$fecha : '' }} y aún no vuelve. Da seguimiento.</p>
    @endswitch
    <table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;font-size:14px;margin-bottom:12px;">
        <tr><td style="padding:4px 0;color:#64748b;width:140px;">Motivo</td><td style="padding:4px 0;font-weight:bold;">{{ $motivo }}</td></tr>
        @if ($solicitante)<tr><td style="padding:4px 0;color:#64748b;">Solicitante</td><td style="padding:4px 0;">{{ $solicitante }}</td></tr>@endif
        @if ($sede)<tr><td style="padding:4px 0;color:#64748b;">Sale de</td><td style="padding:4px 0;">{{ $sede }}</td></tr>@endif
        <tr><td style="padding:4px 0;color:#64748b;">Destino</td><td style="padding:4px 0;">{{ $destino }}</td></tr>
        <tr><td style="padding:4px 0;color:#64748b;">Artículos</td><td style="padding:4px 0;">{{ $articulos }}</td></tr>
        @if ($comentario)<tr><td style="padding:4px 0;color:#b91c1c;">Comentario</td><td style="padding:4px 0;">{{ $comentario }}</td></tr>@endif
    </table>
    <p style="margin-bottom:0;"><a href="{{ $enlace }}" style="display:inline-block;background:{{ $color }};color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:10px;font-weight:bold;">{{ $boton }}</a></p>
@endsection
