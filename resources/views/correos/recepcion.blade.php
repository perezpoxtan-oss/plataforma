@extends('correos.plantilla')
@section('cuerpo')
    <p style="margin-top:0;font-size:18px;font-weight:bold;">{{ $titulo }}</p>
    @foreach ($lineas as $linea)
        <p>{{ $linea }}</p>
    @endforeach
    <p style="margin-bottom:0;">
        @foreach ($botones as [$texto, $enlace])
            <a href="{{ $enlace }}" style="display:inline-block;margin:0 8px 8px 0;background:{{ $loop->last && count($botones) > 1 ? '#475569' : '#2563eb' }};color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:10px;font-weight:bold;">{{ $texto }}</a>
        @endforeach
    </p>
    @if (count($botones) > 1)
        <p style="color:#64748b;font-size:12px;">Al tocar un botón se abre la plataforma: inicia sesión y confirma tu respuesta.</p>
    @endif
@endsection
