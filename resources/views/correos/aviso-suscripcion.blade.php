@extends('correos.plantilla')
@section('cuerpo')
    <p style="margin-top:0;font-size:18px;font-weight:bold;">{{ $titulo }}</p>
    @foreach (preg_split('/\R/', $texto) as $parrafo)
        @if (trim($parrafo) !== '')<p>{{ $parrafo }}</p>@endif
    @endforeach
    @if ($enlace)
        <p style="margin-bottom:0;margin-top:20px;"><a href="{{ $enlace }}" style="display:inline-block;background:#059669;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:10px;font-weight:bold;">{{ $boton }}</a></p>
    @endif
@endsection
