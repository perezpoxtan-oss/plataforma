@extends('correos.plantilla')
@section('cuerpo')
    <p style="margin-top:0;font-size:18px;font-weight:bold;">¡El correo de la plataforma funciona!</p>
    <p>Este es un correo de prueba enviado desde Configuración por <strong>{{ $enviadoPor }}</strong>.</p>
    <p style="margin-bottom:0;">A partir de ahora la plataforma puede enviar avisos por correo.</p>
@endsection
