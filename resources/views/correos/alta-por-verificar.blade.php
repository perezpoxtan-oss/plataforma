@extends('correos.plantilla')
@section('cuerpo')
    <p style="margin-top:0;font-size:18px;font-weight:bold;">Alta pendiente de verificar</p>
    <p>La caseta registró {{ $tipo === 'vehículo' ? 'el' : 'la' }} {{ $tipo }} <strong>{{ $titulo }}</strong>{{ $sede ? ' en la sede '.$sede : '' }}{{ $origen ? ' desde '.$origen : '' }}, porque no aparecía en el {{ $padron }}. Ya se pudo usar en la operación.</p>
    <p>Registró: {{ $registradoPor }} · {{ $fecha }}</p>
    <p>Revísalo para <strong>aceptarlo</strong> (y completar sus datos), <strong>rechazarlo</strong> con un motivo o, si ya existía, <strong>unirlo</strong> con el registro correcto.</p>
    <p style="margin-bottom:0;"><a href="{{ $enlace }}" style="display:inline-block;background:#059669;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:10px;font-weight:bold;">Revisar altas por verificar</a></p>
@endsection
