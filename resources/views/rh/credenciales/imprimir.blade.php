{{--
    C-44 (Etapa 8): hoja de credenciales CR80 (85.6 × 54 mm), frente y reverso
    de cada una, como páginas del tamaño exacto. «Imprimir» del navegador la
    manda a la impresora de credenciales (o «Guardar como PDF»). El QR lleva la
    dirección pública de verificación /credencial/{código}: ningún dato.
    Campos del frente y texto del reverso: propuesta del 12 oct (el dueño puede
    cambiarlos en Configuración → Recepción y RR. HH. → Credenciales).
--}}
@php
    $version = fn (string $archivo) => asset($archivo).'?v='.(@filemtime(public_path($archivo)) ?: '1');
    $propiedad = 'Esta credencial es propiedad de '.$empresaNombre.'. Es personal e intransferible.'
        .($ajustes['telefono'] !== '' ? ' Si la encuentras, llama al '.$ajustes['telefono'].'.' : ' Si la encuentras, entrégala en la caseta.');
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Credenciales · {{ $empresaNombre }}</title>
    <link href="{{ $version('vendor/bootstrap-icons/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ $version('css/plataforma.css') }}" rel="stylesheet">
    <style>
        @page { size: 85.6mm 54mm; margin: 0; }
        body.hoja-credenciales { margin: 0; background: #e5e7eb; font-family: system-ui, -apple-system, "Segoe UI", sans-serif; }
        .cara-cr80 { width: 85.6mm; height: 54mm; box-sizing: border-box; background: #fff; overflow: hidden; position: relative; margin: 6mm auto; padding: 2.5mm; page-break-after: always; break-after: page; border-radius: 3mm; }
        .cara-cr80 .franja { position: absolute; left: 0; right: 0; top: 0; height: 9mm; background: #1e3a8a; }
        .cara-cr80 .encabezado { position: relative; display: flex; align-items: center; gap: 1.5mm; height: 6mm; color: #fff; font-weight: 700; font-size: 2.6mm; }
        .cara-cr80 .encabezado img { max-height: 6mm; max-width: 20mm; background: #fff; border-radius: 1mm; padding: 0.3mm; }
        .frente-cuerpo { display: grid; grid-template-columns: 22mm 1fr 17mm; gap: 2mm; margin-top: 3mm; align-items: start; }
        .foto-cr80 { width: 22mm; height: 29.3mm; object-fit: cover; border-radius: 1mm; border: 0.2mm solid #cbd5e1; background: #f1f5f9; display: flex; align-items: center; justify-content: center; color: #94a3b8; font-size: 7mm; }
        .datos-cr80 .nombre { font-weight: 800; font-size: 3.1mm; line-height: 1.15; margin-bottom: 1mm; }
        .datos-cr80 .linea { font-size: 2.3mm; line-height: 1.25; color: #334155; }
        .qr-cr80 svg { width: 17mm; height: 17mm; display: block; }
        .folio-cr80 { font-size: 1.9mm; color: #64748b; text-align: center; margin-top: 0.5mm; }
        .reverso-cr80 .texto { font-size: 2.3mm; line-height: 1.35; color: #1f2937; margin-top: 3mm; }
        .reverso-cr80 .pie { position: absolute; left: 2.5mm; right: 2.5mm; bottom: 2.5mm; display: flex; justify-content: space-between; align-items: flex-end; font-size: 2mm; color: #475569; }
        .reverso-cr80 .firma { border-top: 0.2mm solid #94a3b8; width: 40mm; text-align: center; padding-top: 0.5mm; }
        .panel-cr80 { max-width: 60rem; margin: 1rem auto; padding: 1rem; background: #fff; border-radius: 12px; }
        @media print { body.hoja-credenciales { background: #fff; } .panel-cr80 { display: none; } .cara-cr80 { margin: 0; border-radius: 0; } }
    </style>
</head>
<body class="hoja-credenciales">
    <div class="panel-cr80">
        <h1 class="h5"><i class="bi bi-person-vcard me-1" aria-hidden="true"></i>Credenciales listas ({{ $credenciales->count() }})</h1>
        <p class="small mb-2">Cada credencial sale en dos páginas: frente y reverso. En la impresora de credenciales elige tamaño <strong>CR80</strong> y <strong>«Tamaño real / 100 %»</strong>; si imprime por las dos caras, elige «Dúplex». También puedes <strong>Guardar como PDF</strong>.</p>
        <div class="d-flex gap-2 flex-wrap">
            <button type="button" class="btn-imprimir-calcomania" data-accion="imprimir"><i class="bi bi-printer me-1" aria-hidden="true"></i> Imprimir credenciales</button>
            <a href="{{ route('credenciales.index') }}" class="btn-cerrar-calcomania"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Volver a Credenciales</a>
        </div>
    </div>

    @foreach ($credenciales as $cr)
        @php $c = $cr->colaborador; $foto = \App\Services\Credenciales\AdministradorCredenciales::fotoDataUri($c); @endphp
        <section class="cara-cr80 frente-cr80" aria-label="Frente de la credencial {{ $cr->folio }}">
            <div class="franja"></div>
            <div class="encabezado">@if ($logo)<img src="{{ $logo }}" alt="">@endif<span>{{ $empresaNombre }}</span></div>
            <div class="frente-cuerpo">
                @if ($foto)<img src="{{ $foto }}" alt="Foto de {{ $c->nombreCompleto() }}" class="foto-cr80">@else<div class="foto-cr80"><i class="bi bi-person" aria-hidden="true"></i></div>@endif
                <div class="datos-cr80">
                    <div class="nombre">{{ $c->nombreCompleto() }}</div>
                    @if ($c->puesto)<div class="linea">{{ $c->puesto->nombre }}</div>@endif
                    @if ($c->departamento)<div class="linea">{{ $c->departamento->nombre }}</div>@endif
                    @if ($c->num_empleado)<div class="linea">Núm. {{ $c->num_empleado }}</div>@endif
                    <div class="linea">{{ $cr->vence_en ? 'Vence: '.$cr->vence_en->format('d/m/Y') : 'Sin vencimiento' }}</div>
                </div>
                <div>
                    <div class="qr-cr80" role="img" aria-label="Código QR de verificación">{!! \App\Services\Credenciales\AdministradorCredenciales::qrSvg($cr) !!}</div>
                    <div class="folio-cr80">{{ $cr->folio }}</div>
                </div>
            </div>
        </section>
        <section class="cara-cr80 reverso-cr80" aria-label="Reverso de la credencial {{ $cr->folio }}">
            <div class="texto">{{ $propiedad }}</div>
            @if ($ajustes['reverso_texto'] !== '')<div class="texto">{{ $ajustes['reverso_texto'] }}</div>@endif
            <div class="texto">Escanea el código del frente para comprobar si está vigente.</div>
            <div class="pie"><span>{{ $cr->folio }}</span><span class="firma">Firma del titular</span></div>
        </section>
    @endforeach
    <script src="{{ $version('js/plataforma.js') }}"></script>
</body>
</html>
