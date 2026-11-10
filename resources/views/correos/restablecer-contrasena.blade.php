@extends('correos.plantilla')
@section('cuerpo')
    <p style="margin-top:0;font-size:18px;font-weight:bold;">Crea tu contraseña nueva</p>
    <p>Hola, {{ $nombre }}.</p>
    @if ($pedidoPorAdministrador)
        <p>Tu administrador restableció tu contraseña. Para crear una nueva, que solo tú conocerás, toca el botón:</p>
    @else
        <p>Recibimos una solicitud para cambiar la contraseña de tu cuenta. Si fuiste tú, toca el botón y escribe tu contraseña nueva:</p>
    @endif
    <p style="margin-bottom:0;"><a href="{{ $enlace }}" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:10px;font-weight:bold;">Crear mi contraseña nueva</a></p>
    <p style="margin-top:16px;color:#64748b;font-size:13px;">El enlace sirve durante {{ $minutos }} minutos y una sola vez. Si no pediste este cambio, ignora este correo: tu contraseña sigue igual.</p>
@endsection
