{{--
    Hoja impresa del pase de salida: logo de la empresa, folio, QR que abre la
    verificación (/pases-salida/verificar/{codigo}), datos, artículos, las
    aprobaciones con nombre, cargo, fecha y hora, y las firmas de caseta. Las
    firmas que faltan quedan con línea para firmarse a mano.
--}}
@use('App\Models\PaseSalida')
@php
    $version = fn (string $archivo) => asset($archivo).'?v='.(@filemtime(public_path($archivo)) ?: '1');
    [$textoEstado, $claseEstado] = $pase->insignia($vencido);
    $tentativa = PaseSalida::dia($pase->fecha_tentativa_regreso);
    $solicitante = $pase->solicitante;
    $hora = app(\App\Support\HoraLocal::class);
    // Firmas de caseta (circuito actual) por rol: la más reciente de cada uno
    $porRol = $pase->firmas->groupBy('rol')->map->last();
    // Firmas de caseta de pases anteriores al circuito v2 (las aprobaciones ya salen arriba)
    $anteriores = $pase->firmas->filter(fn ($f) => isset(PaseSalida::ROLES_ANTERIORES[$f->rol]) && $f->grupo !== 'aprobacion');
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
        <p>Una hoja carta con los datos del pase, el QR para verificarlo en la caseta y todas sus firmas. Las que faltan se firman a mano.</p>
        <div class="acciones">
            <button type="button" class="btn-imprimir-calcomania" data-accion="imprimir"><i class="bi bi-printer me-2" aria-hidden="true"></i>Imprimir Pase</button>
            <a href="{{ route('pases-salida.show', $pase->id) }}" class="btn-cerrar-calcomania"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i> Volver al pase</a>
        </div>
    </div>

    <article class="hoja-pase">
        <header class="hoja-pase-encabezado">
            <div class="hoja-pase-empresa">
                @if ($logo)<img src="{{ $logo }}" alt="Logo de {{ $empresa->nombre_comercial }}" class="hoja-pase-logo">@endif
                <div>
                    <div class="hoja-pase-nombre-empresa">{{ $empresa->nombre_comercial }}</div>
                    @if ($empresa->razon_social && $empresa->razon_social !== $empresa->nombre_comercial)<div class="hoja-pase-sede">{{ $empresa->razon_social }}</div>@endif
                    <div class="hoja-pase-sede">{{ $pase->sede?->nombre }}{{ $pase->sede?->direccion ? ' · '.$pase->sede->direccion : '' }}</div>
                </div>
            </div>
            <div class="hoja-pase-folio-qr">
                <div class="hoja-pase-folio">
                    <div class="hoja-pase-titulo">Pase de Salida</div>
                    <div class="hoja-pase-numero">{{ $pase->folio }}</div>
                    <span class="badge-pase pase-{{ $claseEstado }}">{{ $textoEstado }}</span>
                </div>
                <div class="hoja-pase-qr" role="img" aria-label="Código QR para verificar el pase {{ $pase->folio }}">{!! $qr !!}</div>
            </div>
        </header>
        <p class="hoja-pase-verificar">Escanea el QR en la caseta para confirmar que el pase es auténtico y ver su estado al momento.</p>

        <section class="hoja-pase-datos">
            <div><div class="dato-etiqueta">Motivo de salida</div><div class="dato-valor">{{ $pase->etiquetaMotivo() }}</div></div>
            <div><div class="dato-etiqueta">¿Regresa?</div><div class="dato-valor">{{ $pase->requiere_regreso ? 'Sí, espera regreso' : 'No (sale definitivo)' }}</div></div>
            <div><div class="dato-etiqueta">Elaboró</div><div class="dato-valor">{{ $pase->creador?->name ?? '—' }} <span class="normal">· @fecha($pase->created_at)</span></div></div>
            <div class="dato-completo"><div class="dato-etiqueta">Solicitante</div>
                <div class="dato-valor">{{ $solicitante?->nombreCompleto() ?? '—' }}
                    <span class="normal">{{ $solicitante ? ($solicitante->num_empleado ? '· Núm. '.$solicitante->num_empleado : '· provisional') : '' }}{{ $solicitante?->departamento ? ' · '.$solicitante->departamento->nombre : '' }}{{ $solicitante?->puesto ? ' · '.$solicitante->puesto->nombre : '' }}</span></div></div>
            <div><div class="dato-etiqueta">Sale de</div><div class="dato-valor">{{ $pase->sede?->nombre }}</div></div>
            <div class="dato-doble"><div class="dato-etiqueta">Enviar a</div><div class="dato-valor">{{ $pase->nombreDestino() }} <span class="normal">({{ ['sede' => 'otra sede', 'proveedor' => 'proveedor', 'colaborador' => 'colaborador'][$pase->destino_tipo] ?? '' }})</span></div></div>
            @if ($pase->destino_direccion)<div class="dato-doble"><div class="dato-etiqueta">Dirección de destino</div><div class="dato-valor normal">{{ $pase->destino_direccion }}</div></div>@endif
            @if ($pase->destino_telefono)<div><div class="dato-etiqueta">Teléfono</div><div class="dato-valor">{{ $pase->destino_telefono }}</div></div>@endif
            <div><div class="dato-etiqueta">Fecha de salida programada</div><div class="dato-valor">{{ $pase->fecha_salida_programada ? $hora->formatear(PaseSalida::dia($pase->fecha_salida_programada), 'd/m/Y') : '—' }}</div></div>
            @if ($pase->requiere_regreso)
                <div><div class="dato-etiqueta">Regreso tentativo</div><div class="dato-valor">{{ $tentativa ? $hora->formatear($tentativa, 'd/m/Y') : '—' }}{{ $vencido ? ' (VENCIDO)' : '' }}</div></div>
            @endif
        </section>

        <table class="hoja-pase-articulos">
            <thead><tr><th>Cant.</th><th>Equipo</th><th>Marca</th><th>Modelo</th><th>Serie</th><th>Descripción</th>@if ($pase->salio_en)<th>Salió</th>@endif @if ($pase->requiere_regreso && $pase->salio_en)<th>Regresó</th>@endif</tr></thead>
            <tbody>
                @foreach ($pase->articulos as $a)
                    <tr><td>{{ $a->cantidad }}</td><td>{{ $a->equipo }}</td><td>{{ $a->marca ?? '—' }}</td><td>{{ $a->modelo ?? '—' }}</td><td>{{ $a->serie ?? '—' }}</td><td>{{ $a->descripcion }}</td>
                        @if ($pase->salio_en)<td>{{ $a->verificado_salida_en ? ($a->verificado_con_lector ? '✔ escaneado' : '✔') : '—' }}</td>@endif
                        @if ($pase->requiere_regreso && $pase->salio_en)<td>{{ $a->cantidad_regresada }} de {{ $a->cantidad }}</td>@endif</tr>
                @endforeach
            </tbody>
        </table>

        @if ($pase->estado === PaseSalida::RECHAZADO)
            <p class="hoja-pase-rechazo"><strong>RECHAZADO:</strong> {{ $pase->motivo_rechazo }}{{ $pase->rechazador ? ' — '.$pase->rechazador->name : '' }} · @fecha($pase->rechazado_en)</p>
        @elseif ($pase->estado === PaseSalida::CANCELADO)
            <p class="hoja-pase-rechazo"><strong>CANCELADO</strong> · @fecha($pase->cancelado_en){{ $pase->motivo_cancelacion ? ' — '.$pase->motivo_cancelacion : '' }}</p>
        @endif

        <section class="hoja-pase-grupo">
            <h2>Aprobaciones</h2>
            <div class="hoja-pase-firmas">
                @forelse ($aprobaciones as $a)
                    <div class="hoja-pase-firma">
                        <div class="hoja-pase-imagen">
                            @if ($a->firma)<img src="{{ route('pases-salida.firma', [$pase->id, $a->firma->id]) }}" alt="Firma de {{ $a->firma->nombre_firma }}">@endif
                        </div>
                        <div class="firma-linea">{{ $a->orden }}. {{ $a->nombre }}{{ $a->obligatorio ? '' : ' (opcional)' }}</div>
                        <div class="hoja-pase-firmante">{{ $a->firma?->nombre_firma ?? ($a->estado === 'omitido' ? 'OMITIDO' : ($a->estado === 'rechazado' ? 'RECHAZADO' : 'Nombre:')) }}</div>
                        @if ($a->resuelto_en)<div class="hoja-pase-fecha">@fecha($a->resuelto_en)</div>@endif
                    </div>
                @empty
                    <p class="small m-0">Este motivo no requiere aprobaciones.</p>
                @endforelse
            </div>
        </section>

        @foreach ($pase->pasosFisicos() as $p)
            @php [$tituloPaso, , , $roles] = PaseSalida::PASOS_FISICOS[$p]; @endphp
            <section class="hoja-pase-grupo">
                <h2>{{ $tituloPaso }}</h2>
                <div class="hoja-pase-firmas">
                    @foreach ($roles as $rol => $textoRol)
                        @php $f = $porRol[$rol] ?? null; @endphp
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

        @if ($anteriores->isNotEmpty())
            <section class="hoja-pase-grupo">
                <h2>Firmas registradas con el circuito anterior</h2>
                <div class="hoja-pase-firmas">
                    @foreach ($anteriores as $f)
                        <div class="hoja-pase-firma">
                            <div class="hoja-pase-imagen"><img src="{{ route('pases-salida.firma', [$pase->id, $f->id]) }}" alt="Firma de {{ $f->nombre_firma }}"></div>
                            <div class="firma-linea">{{ $f->etiquetaRol() }}</div>
                            <div class="hoja-pase-firmante">{{ $f->nombre_firma }}</div>
                            <div class="hoja-pase-fecha">@fecha($f->created_at)</div>
                        </div>
                    @endforeach
                </div>
            </section>
        @endif

        <footer class="hoja-pase-pie">Impreso el @fecha(now()) · {{ auth()->user()->name }} · Verificación: {{ $urlVerificar }}</footer>
    </article>
</main>
<script src="{{ $version('js/plataforma.js') }}"></script>
</body>
</html>
