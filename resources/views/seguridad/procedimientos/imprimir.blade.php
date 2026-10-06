{{--
    Hoja impresa de un procedimiento: logo, clave, versión, fechas, aprobado
    por (con su firma), a quién aplica, pasos numerados (críticos resaltados),
    notas y el QR que abre el modo lectura en el celular (lector universal).
--}}
@php
    $archivo = fn (string $ruta) => asset($ruta).'?v='.(@filemtime(public_path($ruta)) ?: '1');
    $vigente = $version->estado === \App\Models\ProcedimientoVersion::PUBLICADA && $p->estaPublicado();
    [$textoVersion] = $version->insignia();
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $p->clave }} · {{ $version->titulo }}</title>
    <link href="{{ $archivo('vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ $archivo('vendor/bootstrap-icons/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ $archivo('css/plataforma.css') }}" rel="stylesheet">
    <link href="{{ $archivo('css/modos-pantalla.css') }}" rel="stylesheet">
    <script src="{{ $archivo('js/modo-pantalla.js') }}"></script>
</head>
<body class="pagina-impresion-pase pagina-impresion-procedimiento">
<main>
    <div class="controles-impresion">
        <h1>{{ $p->clave }} · {{ $version->titulo }}</h1>
        <p>Hoja carta para pegar en la caseta o entregar en capacitación. El QR abre el procedimiento en el celular (con sesión iniciada).</p>
        <div class="acciones">
            <button type="button" class="btn-imprimir-calcomania" data-accion="imprimir"><i class="bi bi-printer me-2" aria-hidden="true"></i>Imprimir</button>
            <a href="{{ route('procedimientos.show', $p->id) }}" class="btn-cerrar-calcomania"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i> Volver al procedimiento</a>
        </div>
    </div>

    <article class="hoja-pase hoja-procedimiento">
        @unless ($vigente)
            <div class="marca-no-vigente">{{ mb_strtoupper($textoVersion) }} — NO VIGENTE</div>
        @endunless
        <header class="hoja-pase-encabezado">
            <div class="hoja-pase-empresa">
                @if ($logo)<img src="{{ $logo }}" alt="Logo de {{ $empresa->nombre_comercial }}" class="hoja-pase-logo">@endif
                <div>
                    <div class="hoja-pase-nombre-empresa">{{ $empresa->nombre_comercial }}</div>
                    @if ($empresa->razon_social && $empresa->razon_social !== $empresa->nombre_comercial)<div class="hoja-pase-sede">{{ $empresa->razon_social }}</div>@endif
                    <div class="hoja-pase-sede">Manual de procedimientos operativos</div>
                </div>
            </div>
            <div class="hoja-pase-folio-qr">
                <div class="hoja-pase-folio">
                    <div class="hoja-pase-titulo">{{ $version->categoria?->nombre ?? 'Procedimiento' }}</div>
                    <div class="hoja-pase-numero">{{ $p->clave }}</div>
                    <div class="hoja-procedimiento-version">Versión {{ $version->numero }} · {{ $textoVersion }}</div>
                </div>
                <div class="hoja-pase-qr" role="img" aria-label="Código QR para abrir {{ $p->clave }} en el celular">{!! $qr !!}</div>
            </div>
        </header>

        <h2 class="hoja-procedimiento-titulo">{{ $version->titulo }}</h2>

        <section class="hoja-pase-datos">
            <div class="dato-completo"><div class="dato-etiqueta">Objetivo</div><div class="dato-valor normal-peso">{{ $version->objetivo }}</div></div>
            @if ($version->alcance)<div class="dato-completo"><div class="dato-etiqueta">Cuándo aplica</div><div class="dato-valor normal-peso">{{ $version->alcance }}</div></div>@endif
            @if ($version->responsables)<div class="dato-completo"><div class="dato-etiqueta">Responsables</div><div class="dato-valor normal-peso">{{ $version->responsables }}</div></div>@endif
            <div><div class="dato-etiqueta">Sedes</div><div class="dato-valor">{{ $aplicaA['sedes'] }}</div></div>
            <div class="dato-doble"><div class="dato-etiqueta">Personal</div><div class="dato-valor">{{ $aplicaA['personal'] }}</div></div>
        </section>

        <section class="hoja-pase-grupo">
            <h2>Pasos</h2>
            <ol class="hoja-procedimiento-pasos">
                @foreach ($version->pasos as $paso)
                    <li class="{{ $paso->critico ? 'critico' : '' }}">
                        <span class="hoja-paso-numero">{{ $paso->orden }}</span>
                        <div>
                            @if ($paso->critico)<strong class="hoja-paso-critico">PUNTO CRÍTICO · </strong>@endif{{ $paso->texto }}
                            @if ($paso->responsable)<span class="hoja-paso-responsable">Responsable: {{ $paso->responsable }}</span>@endif
                        </div>
                    </li>
                @endforeach
            </ol>
        </section>

        @if ($version->notas)
            <section class="hoja-pase-grupo"><h2>Notas</h2><p class="hoja-procedimiento-notas">{{ $version->notas }}</p></section>
        @endif
        @if ($version->adjuntos->isNotEmpty())
            <section class="hoja-pase-grupo"><h2>Adjuntos (consúltalos en la plataforma)</h2>
                <p class="hoja-procedimiento-notas">{{ $version->adjuntos->pluck('nombre')->implode(' · ') }}</p></section>
        @endif

        <section class="hoja-pase-grupo">
            <h2>Control del documento</h2>
            <div class="hoja-pase-firmas">
                <div class="hoja-pase-firma">
                    <div class="hoja-pase-imagen"></div>
                    <div class="firma-linea">Elaboró</div>
                    <div class="hoja-pase-firmante">{{ $version->autor?->name ?? '—' }}</div>
                    <div class="hoja-pase-fecha">@fecha($version->created_at, 'd/m/Y')</div>
                </div>
                <div class="hoja-pase-firma">
                    <div class="hoja-pase-imagen">@if ($version->firma_ruta)<img src="{{ route('procedimientos.versiones.firma', [$p->id, $version->id]) }}" alt="Firma de {{ $version->aprobador_nombre }}">@endif</div>
                    <div class="firma-linea">Aprobó</div>
                    <div class="hoja-pase-firmante">{{ $version->aprobador_nombre ?? 'Pendiente de aprobación' }}{{ $version->aprobador_cargo ? ' · '.$version->aprobador_cargo : '' }}</div>
                    <div class="hoja-pase-fecha">{{ $version->aprobado_en ? app(\App\Support\HoraLocal::class)->formatear($version->aprobado_en) : '' }}</div>
                </div>
                <div class="hoja-pase-firma">
                    <div class="hoja-pase-imagen"></div>
                    <div class="firma-linea">Vigencia</div>
                    <div class="hoja-pase-firmante">{{ $version->aprobado_en ? 'Desde '.app(\App\Support\HoraLocal::class)->formatear($version->aprobado_en, 'd/m/Y') : 'Sin publicar' }}</div>
                    <div class="hoja-pase-fecha">{{ $version->reemplazada_en ? 'Reemplazada el '.app(\App\Support\HoraLocal::class)->formatear($version->reemplazada_en, 'd/m/Y') : '' }}</div>
                </div>
            </div>
            @if ($version->resumen_cambios)<p class="hoja-procedimiento-notas mt-1"><strong>Cambios de esta versión:</strong> {{ $version->resumen_cambios }}</p>@endif
        </section>

        <footer class="hoja-pase-pie">Impreso el @fecha(now()) · {{ auth()->user()->name }} · Consulta siempre la versión vigente en la plataforma.</footer>
    </article>
</main>
<script src="{{ $archivo('js/plataforma.js') }}"></script>
</body>
</html>
