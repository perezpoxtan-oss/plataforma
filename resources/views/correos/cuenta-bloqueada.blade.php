@extends('correos.plantilla')
@section('cuerpo')
    <p style="margin-top:0;font-size:18px;font-weight:bold;">{{ $paraAfectado ? 'Tu cuenta se bloqueó temporalmente' : 'Cuenta bloqueada por intentos fallidos' }}</p>
    <p>{{ $texto }}</p>
    @if ($paraAfectado)
        <p>Si fuiste tú, espera a que pase el bloqueo y vuelve a intentarlo. Si no reconoces estos intentos, avisa a tu administrador y, cuando puedas entrar, cambia tu contraseña en <strong>Mi cuenta</strong>.</p>
    @else
        <p>Si la persona necesita entrar antes, puedes desbloquearla en <strong>Usuarios</strong>. Si los intentos no son suyos, conviene revisar quién está probando contraseñas.</p>
        @if ($enlace)
            <p style="margin-bottom:0;"><a href="{{ $enlace }}" style="display:inline-block;background:#dc2626;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:10px;font-weight:bold;">Ver Usuarios</a></p>
        @endif
    @endif
@endsection
