@extends('correos.plantilla')
@section('cuerpo')
    @php $identidad = app(\App\Support\Identidad::class); @endphp
    <p style="margin-top:0;font-size:18px;font-weight:bold;">Bienvenido a {{ $identidad->get('nombre') }}</p>
    <p>Hola, {{ $nombre }}. Ya tienes una cuenta para entrar a la plataforma.</p>
    <p>Tu usuario es <strong>{{ $usuario }}</strong>. Para empezar, crea tu contraseña (solo tú la conocerás):</p>
    <p style="margin-bottom:0;"><a href="{{ $enlace }}" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:10px;font-weight:bold;">Crear mi contraseña</a></p>
    <p style="margin-top:16px;color:#64748b;font-size:13px;">El enlace sirve durante {{ $horas }} horas. Si se vence, en la pantalla para entrar toca «Olvidé mi contraseña» y te enviaremos otro.</p>
@endsection
