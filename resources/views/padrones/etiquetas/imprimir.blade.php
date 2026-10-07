{{--
    Hoja de etiquetas QR (Padrones → Etiquetas QR). Página propia, sin menús.
    Ronda 6 (LL-06): cada etiqueta lleva el QR (dibujado en el servidor, solo
    con la dirección /e/{código}: ningún dato del registro), el nombre y el
    código legible para teclearlo.
    Ronda 7: las medidas salen de la plantilla. @page usa milímetros: en un
    rollo térmico cada etiqueta es una página (la impresora corta o avanza);
    en una hoja carta/A4 las etiquetas van en la planilla de columnas × filas.
    Los valores van en un <style> (la política de seguridad permite estilos
    en línea, no código).
--}}
@php
    $version = fn (string $archivo) => asset($archivo).'?v='.(@filemtime(public_path($archivo)) ?: '1');
    $p = $plantilla;
    $mm = fn ($v) => $p->mm((float) $v).'mm';
    [$anchoPagina, $altoPagina] = $p->pagina();
    $relleno = $texto['relleno'];
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Etiquetas QR · {{ $empresaNombre }}</title>
    <link href="{{ $version('vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ $version('vendor/bootstrap-icons/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ $version('css/plataforma.css') }}" rel="stylesheet">
    <link href="{{ $version('css/modos-pantalla.css') }}" rel="stylesheet">
    <script src="{{ $version('js/modo-pantalla.js') }}"></script>
    <style>
        @page { size: {{ $mm($anchoPagina) }} {{ $mm($altoPagina) }}; margin: 0; }
        .pagina-qr { width: {{ $mm($anchoPagina) }}; height: {{ $mm($altoPagina) }}; }
        .pagina-qr.hoja { padding: {{ $mm($p->margen_superior_mm) }} 0 0 {{ $mm($p->margen_izquierdo_mm) }}; }
        .rejilla-qr { grid-template-columns: repeat({{ $p->columnas }}, {{ $mm($p->ancho_mm) }}); grid-auto-rows: {{ $mm($p->alto_mm) }};
            column-gap: {{ $mm($p->separacion_horizontal_mm) }}; row-gap: {{ $mm($p->separacion_vertical_mm) }}; }
        .etiqueta-qr-r7 { width: {{ $mm($p->ancho_mm) }}; height: {{ $mm($p->alto_mm) }}; padding: {{ $mm($relleno) }}; gap: {{ $mm($relleno) }}; }
        .etiqueta-qr-r7 .qr-r7 { width: {{ $mm($p->qr_mm) }}; height: {{ $mm($p->qr_mm) }}; }
        .etiqueta-qr-r7 .titulo-r7 { font-size: {{ $texto['titulo'] }}mm; }
        .etiqueta-qr-r7 .chico-r7 { font-size: {{ $texto['chico'] }}mm; }
        .etiqueta-qr-r7 .logo-r7 img { max-height: {{ $texto['logo'] }}mm; }
        .etiqueta-qr-r7 .logo-r7 span { font-size: {{ $texto['chico'] }}mm; }
    </style>
</head>
<body class="pagina-etiquetas-llaves pagina-etiquetas-qr pagina-etiquetas-r7">
<main>
    <div class="panel-etiquetas-llaves">
        <h1>{{ $prueba ? 'Hoja de prueba' : 'Etiquetas QR listas' }}</h1>
        <p>
            @if ($prueba)
                Plantilla «{{ $p->nombre }}» · {{ $p->resumen() }}. Son etiquetas de ejemplo: no se guardan en el historial.
            @else
                {{ $etiquetas->count() }} {{ $etiquetas->count() === 1 ? 'etiqueta' : 'etiquetas' }} · plantilla «{{ $p->nombre }}» ({{ $p->resumen() }}).
            @endif
        </p>
        @if ($impresion)
            <p class="panel-etiquetas-nota">
                <i class="bi bi-clock-history" aria-hidden="true"></i>
                {{ $impresion->reimpresion_de_id ? 'Reimpresión' : 'Impresión' }} núm. {{ $impresion->id }} del @fecha($impresion->created_at): queda en el historial.
            </p>
        @endif
        @if ($recortadas)
            <p class="panel-etiquetas-nota"><i class="bi bi-exclamation-triangle" aria-hidden="true"></i> Marcaste más de {{ \App\Services\Lector\EtiquetasMasivas::MAXIMO }}: aquí van las primeras {{ \App\Services\Lector\EtiquetasMasivas::MAXIMO }}. Imprime el resto en otra hoja.</p>
        @endif
        @if ($faltan > 0)
            <p class="panel-etiquetas-nota"><i class="bi bi-info-circle" aria-hidden="true"></i> {{ $faltan === 1 ? 'Una etiqueta ya no se puede' : $faltan.' etiquetas ya no se pueden' }} imprimir (sin permiso o el registro ya no existe).</p>
        @endif
        <div class="panel-etiquetas-acciones">
            <button type="button" class="btn-imprimir-calcomania" data-accion="imprimir"><i class="bi bi-printer me-1" aria-hidden="true"></i> Imprimir Etiquetas</button>
            <a href="{{ $prueba ? route('etiquetas.plantillas') : route('etiquetas.index') }}" class="btn-cerrar-calcomania"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i> {{ $prueba ? 'Volver a Plantillas' : 'Volver a Etiquetas QR' }}</a>
        </div>
        <p class="panel-etiquetas-nota">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            @if ($p->esHoja())
                Usa hojas {{ $p->papel === 'a4' ? 'A4' : 'carta' }} con planilla de {{ $p->columnas }} × {{ $p->filas }}. En la impresora elige «Tamaño real / 100 %» y sin márgenes.
            @else
                Rollo térmico de {{ $p->mm($p->ancho_mm) }} × {{ $p->mm($p->alto_mm) }} mm: cada etiqueta sale en su propia página. En la impresora elige ese tamaño de papel y «Tamaño real / 100 %».
            @endif
            Al escanear el QR con la cámara del celular (o con el lector de la caseta) se abre la ficha del registro, solo con sesión iniciada en esta empresa.
        </p>
    </div>

    @foreach ($paginas as $pagina)
        <section class="pagina-qr {{ $p->esHoja() ? 'hoja' : 'rollo' }}" aria-label="Página {{ $loop->iteration }}">
            <div class="rejilla-qr">
                @foreach ($pagina as $e)
                    <div class="etiqueta-qr-r7 {{ $p->orientacion }} {{ $e['activo'] ? '' : 'de-baja' }}">
                        <div class="qr-r7" role="img" aria-label="Código QR de {{ $e['titulo'] }}">{!! $e['qr'] !!}</div>
                        <div class="cuerpo-r7">
                            @if ($p->mostrar_logo)
                                <div class="logo-r7">
                                    @if ($logo)<img src="{{ $logo }}" alt="Logo de {{ $empresaNombre }}">@else<span>{{ $empresaNombre }}</span>@endif
                                </div>
                            @endif
                            @if ($p->mostrar_titulo)<div class="titulo-r7">{{ $e['titulo'] }}</div>@endif
                            @if ($p->mostrar_tipo)<div class="chico-r7 tipo-r7">{{ $e['tipo_nombre'] }}</div>@endif
                            @if ($p->mostrar_ubicacion)<div class="chico-r7">{{ $e['ubicacion'] ?? '' }}</div>@endif
                            @if ($p->mostrar_fecha)<div class="chico-r7">@fecha($impresion?->created_at ?? now(), 'd/m/Y')</div>@endif
                            @if ($p->mostrar_codigo)<div class="chico-r7 codigo-r7">{{ $e['codigo'] }}</div>@endif
                            @unless ($e['activo'])<div class="chico-r7 baja-r7">DE BAJA</div>@endunless
                        </div>
                    </div>
                @endforeach
            </div>
        </section>
    @endforeach
</main>
<script src="{{ $version('js/plataforma.js') }}"></script>
</body>
</html>
