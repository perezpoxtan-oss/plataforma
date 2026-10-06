@extends('layouts.app')

@section('titulo', 'Pase de Salida '.$pase->folio)

@use('App\Models\PaseSalida')
@use('App\Models\PaseSalidaAprobacion')
@use('App\Models\PaseSalidaBitacora')

@section('contenido')
@php
    [$textoEstado, $claseEstado] = $pase->insignia($vencido);
    $tentativa = PaseSalida::dia($pase->fecha_tentativa_regreso);
    $salidaProgramada = PaseSalida::dia($pase->fecha_salida_programada);
    $solicitante = $pase->solicitante;
    $tipoDestino = ['sede' => 'otra sede', 'proveedor' => 'proveedor', 'colaborador' => 'colaborador se lo lleva'][$pase->destino_tipo] ?? '';
    $pasoInfo = $paso ? PaseSalida::PASOS_FISICOS[$paso] : null;
    $totalRonda = $ronda->count();
    $estadosAprobacion = [
        PaseSalidaAprobacion::APROBADO => ['Aprobado', 'completo', 'bi-check-circle-fill'],
        PaseSalidaAprobacion::OMITIDO => ['Omitido', 'no-aplica', 'bi-skip-forward-circle'],
        PaseSalidaAprobacion::RECHAZADO => ['Rechazado', 'rechazado', 'bi-x-circle-fill'],
        PaseSalidaAprobacion::PENDIENTE => ['Pendiente', 'pendiente', 'bi-hourglass-split'],
    ];
    $pestanaInicial = in_array(old('_pestana'), ['resumen', 'articulos', 'bitacora'], true) ? old('_pestana') : 'resumen';
@endphp
<div class="tema-azul pantalla-pases ficha-detalle-pase">
    @include('administracion.partes.avisos')

    <a href="{{ route('pases-salida.index') }}" class="volver-pases"><i class="bi bi-arrow-left" aria-hidden="true"></i> Pases de salida</a>

    <header class="cabecera-pase pase-{{ $claseEstado }}">
        <div class="cabecera-pase-fila">
            <div class="min-w-0">
                <span class="ficha-pase-folio grande">{{ $pase->folio }}</span>
                <h1 class="cabecera-pase-titulo">{{ $solicitante?->nombreCompleto() ?? '—' }} <span>· {{ $pase->etiquetaMotivo() }}</span></h1>
                <p class="ficha-pase-ruta m-0">
                    <i class="bi bi-geo-alt" aria-hidden="true"></i> {{ $pase->sede?->nombre }}
                    <i class="bi bi-arrow-right mx-1" aria-hidden="true"></i>{{ $pase->nombreDestino() }} <span class="text-muted">({{ $tipoDestino }})</span>
                </p>
            </div>
            <span class="badge-pase pase-{{ $claseEstado }} grande">{{ $textoEstado }}</span>
        </div>

        @include('seguridad.pases-salida._pasos', ['etapas' => $etapas])

        @if ($siguiente)
            <p class="siguiente-pase grande"><i class="bi bi-arrow-right-circle-fill" aria-hidden="true"></i> {{ $siguiente }}</p>
        @endif

        <div class="acciones-pase">
            @if ($puede['aprobar'])
                <button type="button" class="btn-accion-pase aprobar" data-abrir-dialogo="dialogoAprobarPase"><i class="bi bi-pen-fill" aria-hidden="true"></i> Aprobar</button>
                <button type="button" class="btn-accion-pase rechazar" data-abrir-dialogo="dialogoRechazarPase"><i class="bi bi-x-octagon" aria-hidden="true"></i> Rechazar</button>
                @if ($puede['omitir'])
                    <button type="button" class="btn-accion-pase neutro" data-abrir-dialogo="dialogoOmitirPase"><i class="bi bi-skip-forward" aria-hidden="true"></i> Omitir paso</button>
                @endif
            @endif
            @if ($puede['firmarPaso'])
                <button type="button" class="btn-accion-pase caseta" data-abrir-dialogo="dialogoPasoPase"><i class="bi bi-upc-scan" aria-hidden="true"></i> {{ $pasoInfo[1] }}</button>
            @endif
            @if ($puede['corregir'])
                <button type="button" class="btn-accion-pase aprobar" data-abrir-dialogo="dialogoCorregirPase"><i class="bi bi-pencil-square" aria-hidden="true"></i> Corregir y reenviar</button>
            @endif
            @if ($puede['imprimir'])
                <a href="{{ route('pases-salida.imprimir', $pase->id) }}" target="_blank" rel="noopener" class="btn-accion-pase neutro"><i class="bi bi-printer" aria-hidden="true"></i> Imprimir Pase</a>
            @endif
            @if ($puede['cancelar'])
                <button type="button" class="btn-accion-pase tenue" data-abrir-dialogo="dialogoCancelarPase"><i class="bi bi-slash-circle" aria-hidden="true"></i> Cancelar pase</button>
            @endif
        </div>

        @if ($puede['porQueNo'] && $pase->estado === PaseSalida::PENDIENTE)
            <p class="aviso-sin-firma"><i class="bi bi-lock me-1" aria-hidden="true"></i>{{ $puede['porQueNo'] }}</p>
        @elseif ($paso && ! $puede['firmarPaso'])
            <p class="aviso-sin-firma"><i class="bi bi-lock me-1" aria-hidden="true"></i>Tu usuario no firma esta parte: se necesita el permiso «Firmar» de Pases de salida en la sede
                {{ $pasoInfo[2] === 'destino' ? $pase->sedeDestino?->nombre.' (destino)' : $pase->sede?->nombre.' (origen)' }}.</p>
        @endif
    </header>

    @if ($pase->estado === PaseSalida::RECHAZADO)
        <div class="alert alert-danger small"><i class="bi bi-x-circle-fill me-1" aria-hidden="true"></i><strong>Rechazado y devuelto al solicitante.</strong> {{ $pase->motivo_rechazo }}
            @if ($pase->rechazador)<br><span class="texto-traza">Rechazó {{ $pase->rechazador->name }} · @fecha($pase->rechazado_en)</span>@endif
        </div>
    @elseif ($pase->estado === PaseSalida::CANCELADO)
        <div class="alert alert-secondary small"><i class="bi bi-slash-circle me-1" aria-hidden="true"></i><strong>Pase cancelado</strong> el @fecha($pase->cancelado_en).{{ $pase->motivo_cancelacion ? ' '.$pase->motivo_cancelacion : '' }}</div>
    @elseif ($vencido)
        <div class="alert alert-danger small"><i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i><strong>Vencido:</strong> debió regresar el @fecha($tentativa, 'd/m/Y') y no ha vuelto. ¡Hay que dar seguimiento!</div>
    @elseif ($pase->cerrado_con_faltantes)
        <div class="alert alert-warning small"><i class="bi bi-exclamation-diamond me-1" aria-hidden="true"></i><strong>Cerrado con faltantes:</strong> no regresaron todos los artículos (ver bitácora).</div>
    @endif

    <div class="pestanas-pase" role="tablist" aria-label="Secciones del pase" data-pestanas-pase data-inicial="{{ $pestanaInicial }}">
        <button type="button" role="tab" class="pestana-pase" data-pestana-pase="resumen" aria-controls="panel-resumen"><i class="bi bi-card-text" aria-hidden="true"></i> Resumen</button>
        <button type="button" role="tab" class="pestana-pase" data-pestana-pase="articulos" aria-controls="panel-articulos"><i class="bi bi-box-seam" aria-hidden="true"></i> Artículos <span class="conteo-pill">{{ $pase->articulos->count() }}</span></button>
        <button type="button" role="tab" class="pestana-pase" data-pestana-pase="bitacora" aria-controls="panel-bitacora"><i class="bi bi-pen" aria-hidden="true"></i> Firmas y bitácora <span class="conteo-pill">{{ $pase->bitacora->count() }}</span></button>
    </div>

    {{-- ===================== Resumen ===================== --}}
    <section id="panel-resumen" class="panel-pase" role="tabpanel" data-panel-pase="resumen" aria-label="Resumen">
        <div class="tarjeta p-3 mb-3">
            <dl class="datos-pase">
                <div><dt>Motivo de salida</dt><dd>{{ $pase->etiquetaMotivo() }}</dd></div>
                <div><dt>¿Regresa?</dt><dd>{{ $pase->requiere_regreso ? 'Sí, espera regreso' : 'No (sale definitivo)' }}</dd></div>
                <div class="completo"><dt>Solicitante</dt><dd>{{ $solicitante?->nombreCompleto() ?? '—' }}
                    @if ($solicitante)<span class="text-muted">({{ $solicitante->num_empleado ? 'Núm. '.$solicitante->num_empleado : 'provisional' }}{{ $solicitante->departamento ? ' · '.$solicitante->departamento->nombre : '' }}{{ $solicitante->puesto ? ' · '.$solicitante->puesto->nombre : '' }})</span>@endif</dd></div>
                <div><dt>Sale de</dt><dd>{{ $pase->sede?->nombre }}</dd></div>
                <div><dt>Enviar a</dt><dd>{{ $pase->nombreDestino() }} <span class="text-muted">({{ $tipoDestino }})</span></dd></div>
                @if ($pase->destino_direccion)<div class="completo"><dt>Dirección de destino</dt><dd>{{ $pase->destino_direccion }}</dd></div>@endif
                @if ($pase->destino_telefono)<div><dt>Teléfono</dt><dd>{{ $pase->destino_telefono }}</dd></div>@endif
                <div><dt>Fecha de salida</dt><dd>{{ $salidaProgramada ? app(\App\Support\HoraLocal::class)->formatear($salidaProgramada, 'd/m/Y') : '—' }}</dd></div>
                @if ($pase->requiere_regreso)
                    <div class="{{ $vencido ? 'texto-vencido' : '' }}"><dt>Regreso tentativo</dt><dd>{{ $tentativa ? app(\App\Support\HoraLocal::class)->formatear($tentativa, 'd/m/Y') : '—' }}{{ $vencido ? ' · Vencido' : '' }}</dd></div>
                @endif
                @if ($pase->salio_en)<div><dt>Salió</dt><dd>@fecha($pase->salio_en)</dd></div>@endif
                @if ($pase->regreso_en)<div><dt>Regresó</dt><dd>@fecha($pase->regreso_en)</dd></div>@endif
            </dl>
        </div>

        <h2 class="titulo-seccion-pase"><i class="bi bi-diagram-3 me-2 text-primary" aria-hidden="true"></i>Aprobaciones @if ($pase->ronda > 1)<span class="normal">(ronda {{ $pase->ronda }})</span>@endif</h2>
        @if ($ronda->isEmpty())
            <p class="text-muted small">Este motivo no requiere aprobaciones.</p>
        @endif
        <ol class="lista-aprobaciones">
            @foreach ($ronda as $a)
                @php
                    [$textoA, $claseA, $iconoA] = $estadosAprobacion[$a->estado] ?? $estadosAprobacion[PaseSalidaAprobacion::PENDIENTE];
                    $esActual = $actual && $actual->id === $a->id;
                @endphp
                <li class="aprobacion-pase {{ $claseA }} {{ $esActual ? 'actual' : '' }}">
                    <span class="aprobacion-numero" aria-hidden="true">{{ $a->orden }}</span>
                    <div class="min-w-0 flex-fill">
                        <strong>{{ $a->nombre }}</strong> @unless ($a->obligatorio)<span class="chip-opcional">opcional</span>@endunless
                        <span class="d-block small text-muted">{{ $a->quienFirma() }}</span>
                        @if ($a->resolvio)
                            <span class="texto-traza d-block">{{ $textoA }} por {{ $a->resolvio->name }} · @fecha($a->resuelto_en)</span>
                        @elseif ($esActual)
                            <span class="texto-traza d-block text-primary fw-bold">Le toca firmar ahora{{ $sinFirmantes ? ' · nadie cumple la regla: firma cualquiera con permiso «Aprobar» de la sede' : '' }}</span>
                        @endif
                        @if ($a->comentario)<span class="comentario-pase">«{{ $a->comentario }}»</span>@endif
                    </div>
                    @if ($a->firma)
                        <img src="{{ route('pases-salida.firma', [$pase->id, $a->firma->id]) }}" alt="Firma de {{ $a->firma->nombre_firma }}" class="miniatura-firma" loading="lazy">
                    @endif
                    <span class="estado-seccion {{ $claseA === 'rechazado' ? 'no-aplica rechazado' : $claseA }}">{{ $textoA }}</span>
                </li>
            @endforeach
        </ol>
        @foreach ($rondasAnteriores as $numero => $anteriores)
            <details class="ronda-anterior">
                <summary>Ronda {{ $numero }} (antes de corregir)</summary>
                <ul class="small">
                    @foreach ($anteriores as $a)
                        <li>{{ $a->orden }}. {{ $a->nombre }} — {{ $estadosAprobacion[$a->estado][0] ?? $a->estado }}{{ $a->resolvio ? ' · '.$a->resolvio->name : '' }}{{ $a->comentario ? ' · «'.$a->comentario.'»' : '' }}</li>
                    @endforeach
                </ul>
            </details>
        @endforeach
    </section>

    {{-- ===================== Artículos ===================== --}}
    <section id="panel-articulos" class="panel-pase" role="tabpanel" data-panel-pase="articulos" aria-label="Artículos">
        <div class="lista-articulos-pase">
            @foreach ($pase->articulos as $a)
                <div class="articulo-pase">
                    <div class="min-w-0">
                        <strong>{{ $a->cantidad }}x {{ $a->equipo }}</strong> {{ trim(($a->marca ?? '').' '.($a->modelo ?? '')) }}
                        @if ($a->serie)<span class="d-block small">Serie: <strong>{{ $a->serie }}</strong></span>@endif
                        @if ($a->descripcion)<span class="d-block small text-muted">{{ $a->descripcion }}</span>@endif
                    </div>
                    <div class="estado-articulo-pase">
                        @if ($a->equipo_id)<span class="chip-padron" title="Equipo del padrón de Equipos de seguridad"><i class="bi bi-upc-scan" aria-hidden="true"></i> padrón</span>@endif
                        @if ($a->verificado_salida_en)
                            <span class="chip-verificado"><i class="bi bi-check2" aria-hidden="true"></i> Salió{{ $a->verificado_con_lector ? ' (escaneado)' : '' }}</span>
                        @endif
                        @if ($pase->requiere_regreso && $a->verificado_salida_en)
                            <span class="chip-regreso {{ $a->pendientes() === 0 ? 'completo' : '' }}">Regresó {{ $a->cantidad_regresada }} de {{ $a->cantidad }}</span>
                        @endif
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    {{-- ===================== Firmas y bitácora ===================== --}}
    <section id="panel-bitacora" class="panel-pase" role="tabpanel" data-panel-pase="bitacora" aria-label="Firmas y bitácora">
        <p class="small text-muted"><i class="bi bi-lock" aria-hidden="true"></i> La bitácora no se puede editar ni borrar: cada paso queda con quién, cuándo, comentario, firmas e IP.</p>
        <ol class="bitacora-pase">
            @foreach ($pase->bitacora->sortByDesc('id') as $b)
                @php [$iconoB, $claseB] = PaseSalidaBitacora::EVENTOS[$b->evento] ?? ['bi-dot', 'neutro']; @endphp
                <li class="bitacora-item {{ $claseB }}">
                    <span class="bitacora-icono" aria-hidden="true"><i class="bi {{ $iconoB }}"></i></span>
                    <div class="min-w-0 flex-fill">
                        <strong>{{ $b->titulo }}</strong>
                        <span class="texto-traza d-block">{{ $b->usuario_nombre ?? 'Sistema' }} · @fecha($b->created_at){{ $b->ip ? ' · IP '.$b->ip : '' }}</span>
                        @if ($b->comentario)<span class="comentario-pase">«{{ $b->comentario }}»</span>@endif
                        @if (! empty($b->detalle['persona']))<span class="d-block small">Persona: {{ $b->detalle['persona'] }}</span>@endif
                        @if (! empty($b->detalle['verificados']))<span class="d-block small">Artículos verificados: {{ $b->detalle['verificados'] }}{{ ! empty($b->detalle['con_lector']) ? ' ('.$b->detalle['con_lector'].' con lector)' : '' }}</span>@endif
                        @if (! empty($b->detalle['regresan']))<span class="d-block small">Regresan: {{ implode(', ', $b->detalle['regresan']) }}{{ ! empty($b->detalle['faltan']) ? ' · faltan '.$b->detalle['faltan'] : '' }}</span>@endif
                        @if ($b->firmas->isNotEmpty())
                            <div class="firmas-bitacora">
                                @foreach ($b->firmas as $f)
                                    <figure>
                                        <img src="{{ route('pases-salida.firma', [$pase->id, $f->id]) }}" alt="Firma de {{ $f->nombre_firma }}" class="miniatura-firma" loading="lazy">
                                        <figcaption>{{ $f->etiquetaRol() }}<br><strong>{{ $f->nombre_firma }}</strong></figcaption>
                                    </figure>
                                @endforeach
                            </div>
                        @endif
                    </div>
                </li>
            @endforeach
        </ol>
    </section>

    <p class="texto-traza mt-3">
        @if ($pase->creador)<i class="bi bi-plus-circle" aria-hidden="true"></i> Creado por {{ $pase->creador->name }} · @fecha($pase->created_at)@endif
        @if ($pase->editor && $pase->updated_at?->ne($pase->created_at)) · <i class="bi bi-clock-history" aria-hidden="true"></i> Editado por {{ $pase->editor->name }} · @fecha($pase->updated_at)@endif
    </p>

    @if ($firmaGuardada)
        <form action="{{ route('pases-salida.mi-firma.destroy') }}" method="POST" class="mi-firma-guardada" data-confirmar="¿Borrar tu firma guardada? La próxima vez firmarás en el recuadro.">
            @csrf
            @method('DELETE')
            <img src="{{ route('pases-salida.mi-firma') }}" alt="Tu firma guardada" class="miniatura-firma" loading="lazy">
            <span class="small text-muted">Tienes una firma guardada (privada).</span>
            <button type="submit" class="btn-enlace-pase">Borrar mi firma guardada</button>
        </form>
    @endif

    @include('seguridad.pases-salida._dialogos')

    @if ($formulario)
        @include('seguridad.pases-salida._formulario', ['modoFormulario' => 'corregir', 'paseEditar' => $pase])
        @if ($puede['colaborador'])
            @include('organizacion.colaboradores._registro-rapido')
        @endif
        @if ($puede['proveedor'])
            @include('padrones.proveedores._alta-rapida')
        @endif
    @endif
</div>
@endsection
