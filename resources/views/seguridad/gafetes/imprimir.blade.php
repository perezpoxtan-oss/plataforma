{{--
    Impresión de gafetes "doble vista" (SEGCAT: gafete_imprimir.php).
    Cada gafete mide 172 x 54 mm: frente y reverso lado a lado para doblar
    por la línea punteada y meter en la mica. El QR viene dibujado del
    servidor en SVG y solo contiene la dirección /e/{código}: ningún dato
    personal, y no se consulta a terceros.
--}}
@php
    $version = fn (string $archivo) => asset($archivo).'?v='.(@filemtime(public_path($archivo)) ?: '1');
    $bajas = $gafetes->where('activo', false)->count();
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Impresión de Gafetes · {{ $empresa->nombre_comercial }}</title>
    <link href="{{ $version('vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ $version('vendor/bootstrap-icons/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ $version('css/plataforma.css') }}" rel="stylesheet">
    <link href="{{ $version('css/modos-pantalla.css') }}" rel="stylesheet">
    <script src="{{ $version('js/modo-pantalla.js') }}"></script>
</head>
<body class="pagina-impresion-gafetes">
<main>
    <div class="controles-impresion">
        <h1>Impresión Doble Vista (Libro)</h1>
        <p>Recorta por el borde exterior y dobla por la línea punteada del medio para colocar en la mica.</p>
        <p class="detalle">{{ $gafetes->count() === 1 ? '1 gafete' : $gafetes->count().' gafetes' }} · Hoja tamaño carta
            @if ($bajas > 0)
                · <span class="aviso-bajas"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i> {{ $bajas === 1 ? '1 está dado de baja' : $bajas.' están dados de baja' }}</span>
            @endif
        </p>
        <div class="acciones">
            <button type="button" class="btn-imprimir-calcomania" data-accion="imprimir"><i class="bi bi-printer me-2" aria-hidden="true"></i>Imprimir Selección</button>
            <a href="{{ route('gafetes.index') }}" class="btn-cerrar-calcomania"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i> Volver al inventario</a>
        </div>
    </div>

    <div class="hoja-gafetes">
        @foreach ($gafetes as $g)
            @php $color = $g->tipo?->color() ?? 'contratista'; @endphp
            <div class="gafete-doble">
                <div class="gafete-frente">
                    <div class="gafete-franja franja-{{ $color }}">
                        <div class="gafete-empresa">{{ $empresa->nombre_comercial }}</div>
                        <div class="gafete-sede">Sede: {{ $g->sede?->nombre ?? '—' }}</div>
                    </div>
                    <div class="gafete-cuerpo">
                        <div class="gafete-tipo tipo-{{ $color }}">{{ $g->tipo?->nombre ?? 'Gafete' }}</div>
                        @if ($logo)
                            <img src="{{ $logo }}" alt="Logo de {{ $empresa->nombre_comercial }}" class="gafete-logo">
                        @else
                            <div class="gafete-sin-logo"><i class="bi bi-card-image" aria-hidden="true"></i> Espacio Logo</div>
                        @endif
                    </div>
                </div>
                <div class="gafete-reverso">
                    <div class="gafete-qr" role="img" aria-label="Código QR del gafete {{ $g->nomenclatura }}">{!! $qrs[$g->id] !!}</div>
                    <div class="gafete-nomenclatura-reverso">{{ $g->nomenclatura }}</div>
                    @unless ($g->activo)
                        <div class="gafete-baja">GAFETE DADO DE BAJA</div>
                    @endunless
                    <div class="gafete-reglas">
                        * Portar en lugar visible en todo momento.<br>
                        * Devolver al finalizar su visita.<br>
                        * Intransferible.
                    </div>
                </div>
            </div>
        @endforeach
    </div>
</main>
<script src="{{ $version('js/plataforma.js') }}"></script>
</body>
</html>
