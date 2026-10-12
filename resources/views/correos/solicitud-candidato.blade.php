@extends('correos.plantilla')
@section('cuerpo')
    <p style="margin-top:0;font-size:18px;font-weight:bold;">Completa tu solicitud de empleo{{ $empresa !== '' ? ' en '.$empresa : '' }}</p>
    <p>Hola, {{ $nombre }}:</p>
    <p>Recibimos tus datos. Para seguir con el proceso necesitamos tu solicitud de empleo completa: te toma unos minutos y la puedes llenar desde tu celular.</p>
    <p style="margin:20px 0;"><a href="{{ $url }}" style="display:inline-block;background:#16a34a;color:#ffffff;text-decoration:none;font-weight:bold;padding:12px 20px;border-radius:8px;">Llenar mi solicitud</a></p>
    <p style="font-size:13px;color:#64748b;">El enlace vale {{ $horas }} horas y solo sirve para tu solicitud. Si venció, pídele uno nuevo a Recursos Humanos o complétala cuando vayas a tu entrevista.</p>
    <p style="margin-bottom:0;">Si no te postulaste, ignora este correo.</p>
@endsection
