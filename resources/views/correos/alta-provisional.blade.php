@extends('correos.plantilla')
@section('cuerpo')
    <p style="margin-top:0;font-size:18px;font-weight:bold;">Alta provisional por validar</p>
    <p>La caseta registró a <strong>{{ $nombre }}</strong>{{ $sede ? ' en '.$sede : '' }} porque no aparecía en el directorio de colaboradores.</p>
    <p>Registró: {{ $registradoPor }} · {{ $fecha }}</p>
    <p>Revísalo para <strong>validarlo</strong> (asignarle su número de empleado) o, si ya existía, <strong>unirlo</strong> con su registro correcto.</p>
    <p style="margin-bottom:0;"><a href="{{ $enlace }}" style="display:inline-block;background:#059669;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:10px;font-weight:bold;">Revisar altas provisionales</a></p>
@endsection
