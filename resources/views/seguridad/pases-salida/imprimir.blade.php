{{--
    Hoja impresa del pase de salida, con todas las firmas del circuito. Las
    firmas que faltan quedan como línea en blanco para firmarse a mano.
--}}
@use('App\Models\PaseSalida')
@php
    $version = fn (string $archivo) => asset($archivo).'?v='.(@filemtime(public_path($archivo)) ?: '1');
    [$textoEstado, $claseEstado] = $pase->insignia($vencido);
    $firmadas = $pase->firmas->keyBy('rol');
    $tentativa = PaseSalida::dia($pase->fecha_tentativa_regreso);
    $solicitante = $pase->solicitante;
@endphp
<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Pase de Salida {{ $pase->folio }}</title>
    <link href="{{ $version('vendor/bootstrap/bootstrap.min.css') }}" rel="stylesheet">
    <link href="{{ $version('vendor/bootstrap-icons/bootstrap-icons.min.css') }}" rel="stylesheet">
    <link href="{{ $version('css/plataforma.css') }}" rel="stylesheet">
    <link href="{{ $version('css/modos-pantalla.css') }}" rel="stylesheet">
    <script src="{{ $version('js/modo-pantalla.js') }}"></script>
</head>
<body class="pagina-impresion-pase">
<main>
    <div class="controles-impresion">
        <h1>Pase de Salida {{ $pase->folio }}</h1>
        <p>Una hoja carta con los datos del pase y todas sus firmas. Las que faltan se firman a mano.</p>
        <div class="acciones">
            <button type="button" class="btn-imprimir-calcomania" data-accion="imprimir"><i class="bi bi-printer me-2" aria-hidden="true"></i>Imprimir Pase</button>
            <a href="{{ route('pases-salida.index', ['pase' => $pase->id]) }}" class="btn-cerrar-calcomania"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i> Volver al pase</a>
        </div>
    </div>

    <article class="hoja-pase">
        <header class="hoja-pase-encabezado">
            <div class="hoja-pase-empresa">
                @if ($logo)<img src="{{ $logo }}" alt="Logo de {{ $empresa->nombre_comercial }}" class="hoja-pase-logo">@endif
                <div>
                    <div class="hoja-pase-nombre-empresa">{{ $empresa->nombre_comercial }}</div>
                    <div class="hoja-pase-sede">{{ $pase->sede?->nombre }}{{ $pase->sede?->direccion ? ' · '.$pase->sede->direccion : '' }}</div>
                </div>
            </div>
            <div class="hoja-pase-folio">
                <div class="hoja-pase-titulo">Pase de Salida</div>
                <div class="hoja-pase-numero">{{ $pase->folio }}</div>
                <span class="badge-pase pase-{{ $claseEstado }}">{{ $textoEstado }}</span>
            </div>
        </header>

        <section class="hoja-pase-datos">
            <div><div class="dato-etiqueta">Motivo de salida</div><div class="dato-valor">{{ $pase->etiquetaMotivo() }}</div></div>
            <div><div class="dato-etiqueta">¿Regresa?</div><div class="dato-valor">{{ $pase->requiere_regreso ? 'Sí, espera regreso' : 'No (sale definitivo)' }}</div></div>
            <div class="dato-completo"><div class="dato-etiqueta">Solicitante</div>
                <div class="dato-valor">{{ $solicitante?->nombreCompleto() ?? '—' }}
                    <span class="normal">{{ $solicitante ? ($solicitante->num_empleado ? '· Núm. '.$solicitante->num_empleado : '· provisional') : '' }}{{ $solicitante?->departamento ? ' · '.$solicitante->departamento->nombre : '' }}{{ $solicitante?->puesto ? ' · '.$solicitante->puesto->nombre : '' }}</span></div></div>
            <div><div class="dato-etiqueta">Sale de</div><div class="dato-valor">{{ $pase->sede?->nombre }}</div></div>
            <div><div class="dato-etiqueta">Enviar a</div><div class="dato-valor">{{ $pase->nombreDestino() }} <span class="normal">({{ ['sede' => 'otra sede', 'proveedor' => 'proveedor', 'colaborador' => 'colaborador'][$pase->destino_tipo] ?? '' }})</span></div></div>
            @if ($pase->destino_direccion)<div class="dato-completo"><div class="dato-etiqueta">Dirección de destino</div><div class="dato-valor normal">{{ $pase->destino_direccion }}</div></div>@endif
            @if ($pase->destino_telefono)<div><div class="dato-etiqueta">Teléfono</div><div class="dato-valor">{{ $pase->destino_telefono }}</div></div>@endif
            <div><div class="dato-etiqueta">Fecha de salida programada</div><div class="dato-valor">{{ $pase->fecha_salida_programada ? app(\App\Support\HoraLocal::class)->formatear(PaseSalida::dia($pase->fecha_salida_programada), 'd/m/Y') : '—' }}</div></div>
            @if ($pase->requiere_regreso)
                <div><div class="dato-etiqueta">Regreso tentativo</div><div class="dato-valor">{{ $tentativa ? app(\App\Support\HoraLocal::class)->formatear($tentativa, 'd/m/Y') : '—' }}{{ $vencido ? ' (VENCIDO)' : '' }}</div></div>
            @endif
            <div><div class="dato-etiqueta">Elaboró</div><div class="dato-valor">{{ $pase->creador?->name ?? '—' }} <span class="normal">· @fecha($pase->created_at)</span></div></div>
        </section>

        <table class="hoja-pase-articulos">
            <thead><tr><th>Cant.</th><th>Equipo</th><th>Marca</th><th>Modelo</th><th>Serie</th><th>Descripción</th></tr></thead>
            <tbody>
                @foreach ($pase->articulos as $a)
                    <tr><td>{{ $a->cantidad }}</td><td>{{ $a->equipo }}</td><td>{{ $a->marca ?? '—' }}</td><td>{{ $a->modelo ?? '—' }}</td><td>{{ $a->serie ?? '—' }}</td><td>{{ $a->descripcion }}</td></tr>
                @endforeach
            </tbody>
        </table>

        @if ($pase->estado === PaseSalida::RECHAZADO)
            <p class="hoja-pase-rechazo"><strong>RECHAZADO:</strong> {{ $pase->motivo_rechazo }}{{ $pase->rechazador ? ' — '.$pase->rechazador->name : '' }} · @fecha($pase->rechazado_en)</p>
        @endif

        @foreach ($pase->gruposDelCircuito() as $grupo)
            @php [$tituloGrupo, $roles] = PaseSalida::GRUPOS[$grupo]; @endphp
            <section class="hoja-pase-grupo">
                <h2>{{ $tituloGrupo }}</h2>
                <div class="hoja-pase-firmas">
                    @foreach ($roles as $rol => $textoRol)
                        @php $f = $firmadas[$rol] ?? null; @endphp
                        <div class="hoja-pase-firma">
                            <div class="hoja-pase-imagen">
                                @if ($f)<img src="{{ route('pases-salida.firma', [$pase->id, $f->id]) }}" alt="Firma de {{ $f->nombre_firma }}">@endif
                            </div>
                            <div class="firma-linea">{{ $textoRol }}</div>
                            <div class="hoja-pase-firmante">{{ $f ? $f->nombre_firma : 'Nombre:' }}</div>
                            @if ($f)<div class="hoja-pase-fecha">@fecha($f->created_at)</div>@endif
                        </div>
                    @endforeach
                </div>
            </section>
        @endforeach

        <footer class="hoja-pase-pie">Impreso el @fecha(now()) · {{ auth()->user()->name }}</footer>
    </article>
</main>
<script src="{{ $version('js/plataforma.js') }}"></script>
</body>
</html>
