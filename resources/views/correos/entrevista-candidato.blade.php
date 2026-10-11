@extends('correos.plantilla')
@section('cuerpo')
    <p style="margin-top:0;font-size:18px;font-weight:bold;">Tu entrevista{{ $empresa !== '' ? ' en '.$empresa : '' }}</p>
    <p>Hola, {{ $nombre }}:</p>
    <p>Te esperamos para tu entrevista de trabajo.</p>
    <table role="presentation" cellpadding="0" cellspacing="0" style="margin:0 0 16px;font-size:15px;">
        <tr><td style="padding:4px 12px 4px 0;color:#64748b;">Fecha</td><td style="padding:4px 0;font-weight:bold;">{{ $fecha }}</td></tr>
        <tr><td style="padding:4px 12px 4px 0;color:#64748b;">Hora</td><td style="padding:4px 0;font-weight:bold;">{{ $hora }}</td></tr>
        @if ($lugar)
            <tr><td style="padding:4px 12px 4px 0;color:#64748b;">Lugar</td><td style="padding:4px 0;font-weight:bold;">{{ $lugar }}</td></tr>
        @endif
        @if ($buscar)
            <tr><td style="padding:4px 12px 4px 0;color:#64748b;">Pregunta por</td><td style="padding:4px 0;font-weight:bold;">{{ $buscar }}</td></tr>
        @endif
    </table>
    <p>Al llegar, preséntate en la caseta con una identificación oficial y di que vienes a una entrevista.</p>
    <p style="margin-bottom:0;">Si no puedes asistir, comunícate con Recursos Humanos para cambiar tu cita.</p>
@endsection
