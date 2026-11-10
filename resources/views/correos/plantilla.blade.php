{{-- Plantilla de correo: HTML sencillo con estilos en línea (los clientes de correo no leen hojas de estilo) --}}
@php $identidad = app(\App\Support\Identidad::class); $color = $identidad->get('color_primario'); @endphp
<!DOCTYPE html>
<html lang="es">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0"><title>{{ $identidad->get('nombre') }}</title></head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 12px;">
    <tr><td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:16px;border:1px solid #e2e8f0;">
            <tr><td style="background:{{ $color }};color:#ffffff;padding:16px 24px;border-radius:16px 16px 0 0;font-weight:bold;font-size:16px;">{{ $identidad->get('nombre') }}</td></tr>
            <tr><td style="padding:24px;font-size:15px;line-height:1.5;">@yield('cuerpo')</td></tr>
            <tr><td style="padding:16px 24px;border-top:1px solid #e2e8f0;font-size:12px;color:#64748b;">Mensaje automático de {{ $identidad->get('nombre') }}. No respondas a este correo.</td></tr>
        </table>
    </td></tr>
</table>
</body>
</html>
