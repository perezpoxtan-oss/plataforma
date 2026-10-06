@extends('correos.plantilla')
@section('cuerpo')
    @if ($tipo === 'publicado')
        @php $p = $procedimientos[0]; @endphp
        <p style="margin-top:0;font-size:18px;font-weight:bold;">{{ $p['clave'] }}: {{ $p['titulo'] }}</p>
        <p>Se publicó la <strong>versión {{ $p['version'] }}</strong> de este procedimiento y te aplica. Ábrelo, léelo con calma y firma <strong>«Leí y entendí»</strong>.</p>
        @if ($resumen)
            <p style="color:#475569;"><strong>Qué cambió:</strong> {{ $resumen }}</p>
        @endif
        <p style="margin-bottom:0;"><a href="{{ $p['enlace'] }}" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:10px;font-weight:bold;">Leer y firmar</a></p>
    @else
        <p style="margin-top:0;font-size:18px;font-weight:bold;">Procedimientos por leer y firmar</p>
        <p>Todavía no firmas de enterado {{ count($procedimientos) === 1 ? 'este procedimiento' : 'estos procedimientos' }}:</p>
        <ul style="padding-left:18px;">
            @foreach ($procedimientos as $p)
                <li style="margin-bottom:6px;"><a href="{{ $p['enlace'] }}" style="color:#1d4ed8;font-weight:bold;">{{ $p['clave'] }} · {{ $p['titulo'] }}</a> (versión {{ $p['version'] }})</li>
            @endforeach
        </ul>
        <p style="margin-bottom:0;"><a href="{{ $enlace }}" style="display:inline-block;background:#2563eb;color:#ffffff;text-decoration:none;padding:12px 20px;border-radius:10px;font-weight:bold;">Ver mis procedimientos por leer</a></p>
    @endif
@endsection
