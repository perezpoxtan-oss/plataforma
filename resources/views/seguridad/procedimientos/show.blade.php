@extends('layouts.app')

@section('titulo', 'Procedimiento '.$p->clave)

@use('App\Models\Procedimiento')
@use('App\Models\ProcedimientoVersion')

@section('contenido')
@php
    [$textoEstado, $claseEstado] = $p->insignia();
    [$textoVersion, $claseVersion] = $version->insignia();
    $color = $p->categoria?->color ?? 'gris';
    $esActual = $version->is($vigente) || ($vigente === null && $version->is($trabajo));
    $lectorSolo = auth()->user()->cannot('procedimientos.crear') && auth()->user()->cannot('procedimientos.editar') && auth()->user()->cannot('procedimientos.aprobar');
    $pestanaInicial = in_array(request()->query('pestana'), ['contenido', 'versiones', 'acuses'], true) ? request()->query('pestana') : 'contenido';
@endphp
<div class="tema-azul pantalla-pases pantalla-procedimientos ficha-detalle-pase">
    @include('administracion.partes.avisos')

    <a href="{{ route('procedimientos.index') }}" class="volver-pases"><i class="bi bi-arrow-left" aria-hidden="true"></i> Procedimientos</a>

    <header class="cabecera-pase cabecera-procedimiento cat-{{ $color }}">
        <div class="cabecera-pase-fila">
            <div class="min-w-0">
                <span class="ficha-pase-folio grande">{{ $p->clave }}</span>
                <p class="categoria-procedimiento"><span class="punto-categoria" aria-hidden="true"></span>{{ $version->categoria?->nombre ?? $p->categoria?->nombre }}</p>
                <h1 class="cabecera-pase-titulo">{{ $version->titulo }}</h1>
            </div>
            <span class="d-flex flex-column align-items-end gap-1">
                <span class="badge-procedimiento {{ $claseEstado }} grande">{{ $textoEstado }}</span>
            </span>
        </div>

        <ul class="lineas-version">
            @if ($vigente)
                <li><i class="bi bi-patch-check-fill text-success" aria-hidden="true"></i> <strong>Vigente: versión {{ $vigente->numero }}</strong>
                    · aprobada por {{ $vigente->aprobador_nombre }} el @fecha($vigente->aprobado_en, 'd/m/Y')</li>
            @endif
            @if ($trabajo)
                <li><i class="bi {{ $trabajo->estado === ProcedimientoVersion::EN_REVISION ? 'bi-hourglass-split text-warning' : 'bi-pencil-square text-secondary' }}" aria-hidden="true"></i>
                    <strong>Versión {{ $trabajo->numero }} {{ $trabajo->estado === ProcedimientoVersion::EN_REVISION ? 'en revisión' : 'en borrador' }}</strong>
                    · escribió {{ $trabajo->autor?->name ?? '—' }}{{ $trabajo->enviado_en && $trabajo->estado === ProcedimientoVersion::EN_REVISION ? ' · enviada el '.app(\App\Support\HoraLocal::class)->formatear($trabajo->enviado_en, 'd/m/Y') : '' }}</li>
            @endif
            @if ($p->estado === Procedimiento::RETIRADO)
                <li><i class="bi bi-slash-circle text-danger" aria-hidden="true"></i> <strong>Retirado</strong> por {{ $p->retiro?->name ?? '—' }} el @fecha($p->retirado_en, 'd/m/Y'){{ $p->motivo_retiro ? ': '.$p->motivo_retiro : '' }}</li>
            @endif
        </ul>

        @if ($pendienteMio)
            <p class="aviso-por-leer"><i class="bi bi-book-half me-1" aria-hidden="true"></i>Te toca leer este procedimiento y firmar «Leí y entendí».</p>
        @elseif ($miAcuse)
            <p class="aviso-leido"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Firmaste de enterado la versión {{ $vigente->numero }} el @fecha($miAcuse->leido_en).</p>
        @endif

        <div class="acciones-pase">
            @if ($vigente || $trabajo)
                <a href="{{ route('procedimientos.leer', $p->id) }}" class="btn-accion-pase aprobar"><i class="bi bi-book" aria-hidden="true"></i> {{ $pendienteMio ? 'Leer y firmar' : 'Modo lectura' }}</a>
            @endif
            @if ($puede['aprobar'])
                <button type="button" class="btn-accion-pase aprobar" data-abrir-dialogo="dialogoAprobarProcedimiento"><i class="bi bi-patch-check-fill" aria-hidden="true"></i> Aprobar</button>
                <button type="button" class="btn-accion-pase rechazar" data-abrir-dialogo="dialogoRechazarProcedimiento"><i class="bi bi-x-octagon" aria-hidden="true"></i> Pedir cambios</button>
            @endif
            @if ($puede['editar'])
                <button type="button" class="btn-accion-pase caseta" data-abrir-dialogo="dialogoEditarProcedimiento"><i class="bi bi-pencil-square" aria-hidden="true"></i> Editar borrador</button>
            @endif
            @if ($puede['enviar'])
                <button type="button" class="btn-accion-pase aprobar" data-abrir-dialogo="dialogoEnviarProcedimiento"><i class="bi bi-send" aria-hidden="true"></i> Enviar a revisión</button>
            @endif
            @if ($puede['nuevaVersion'])
                <form action="{{ route('procedimientos.nueva-version', $p->id) }}" method="POST" class="form-en-linea"
                      data-confirmar="¿Crear la versión {{ $vigente->numero + 1 }} como borrador? La versión {{ $vigente->numero }} sigue vigente hasta que se apruebe la nueva.">
                    @csrf
                    <button type="submit" class="btn-accion-pase caseta"><i class="bi bi-files" aria-hidden="true"></i> Nueva versión</button>
                </form>
            @endif
            <a href="{{ route('procedimientos.imprimir', array_filter(['procedimiento' => $p->id, 'version' => $esActual ? null : $version->numero])) }}" target="_blank" rel="noopener" class="btn-accion-pase neutro"><i class="bi bi-printer" aria-hidden="true"></i> Imprimir</a>
            @if ($puede['descartar'])
                <form action="{{ route('procedimientos.descartar', $p->id) }}" method="POST" class="form-en-linea"
                      data-confirmar="¿Descartar el borrador de la versión {{ $trabajo->numero }}? Sigue vigente la versión {{ $vigente?->numero }}.">
                    @csrf
                    <button type="submit" class="btn-accion-pase tenue"><i class="bi bi-trash3" aria-hidden="true"></i> Descartar borrador</button>
                </form>
            @endif
            @if ($puede['retirar'])
                <button type="button" class="btn-accion-pase tenue" data-abrir-dialogo="dialogoRetirarProcedimiento"><i class="bi bi-slash-circle" aria-hidden="true"></i> Retirar</button>
            @endif
            @if ($puede['reactivar'])
                <form action="{{ route('procedimientos.reactivar', $p->id) }}" method="POST" class="form-en-linea" data-confirmar="¿Reactivar {{ $p->clave }}? Volverá a regir la versión {{ $vigente->numero }}.">
                    @csrf
                    <button type="submit" class="btn-accion-pase aprobar"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i> Reactivar</button>
                </form>
            @endif
        </div>

        @if ($puede['porQueNo'] && $trabajo?->estado === ProcedimientoVersion::EN_REVISION)
            <p class="aviso-sin-firma"><i class="bi bi-lock me-1" aria-hidden="true"></i>{{ $puede['porQueNo'] }}</p>
        @endif
        @if ($puede['borrar'])
            <div class="mt-2">@include('componentes.borrar', ['registro' => 'procedimientos', 'id' => $p->id])</div>
        @endif
    </header>

    @if ($trabajo && $trabajo->estado === ProcedimientoVersion::BORRADOR && $trabajo->motivo_rechazo)
        <div class="alert alert-danger small"><i class="bi bi-x-circle-fill me-1" aria-hidden="true"></i><strong>La versión {{ $trabajo->numero }} regresó a borrador con este comentario:</strong> {{ $trabajo->motivo_rechazo }}
            @if ($trabajo->rechazador)<br><span class="texto-traza">Pidió cambios {{ $trabajo->rechazador->name }} · @fecha($trabajo->rechazado_en)</span>@endif
        </div>
    @endif

    <div class="pestanas-pase" role="tablist" aria-label="Secciones del procedimiento" data-pestanas-pase data-inicial="{{ $pestanaInicial }}">
        <button type="button" role="tab" class="pestana-pase" data-pestana-pase="contenido" aria-controls="panel-contenido"><i class="bi bi-card-text" aria-hidden="true"></i> Contenido</button>
        @unless ($lectorSolo)
            <button type="button" role="tab" class="pestana-pase" data-pestana-pase="versiones" aria-controls="panel-versiones"><i class="bi bi-clock-history" aria-hidden="true"></i> Versiones <span class="conteo-pill">{{ $versiones->count() }}</span></button>
        @endunless
        @if ($puede['acuses'])
            <button type="button" role="tab" class="pestana-pase" data-pestana-pase="acuses" aria-controls="panel-acuses"><i class="bi bi-pen" aria-hidden="true"></i> Acuses <span class="conteo-pill">{{ $acuses['firmaron'] }}/{{ $acuses['deben'] }}</span></button>
        @endif
    </div>

    {{-- ===================== Contenido ===================== --}}
    <section id="panel-contenido" class="panel-pase" role="tabpanel" data-panel-pase="contenido" aria-label="Contenido">
        @if ($versiones->count() > 1)
            <nav class="selector-versiones" aria-label="Ver otra versión">
                @foreach ($versiones->sortByDesc('numero') as $v)
                    @php [$tv, $cv] = $v->insignia(); @endphp
                    <a href="{{ route('procedimientos.show', ['procedimiento' => $p->id, 'version' => $v->numero]) }}" class="chip-version-selector {{ $cv }} {{ $v->is($version) ? 'activa' : '' }}" @if ($v->is($version)) aria-current="page" @endif>
                        v{{ $v->numero }} · {{ $tv }}
                    </a>
                @endforeach
            </nav>
        @endif
        @unless ($version->is($vigente))
            <p class="aviso-version {{ $claseVersion }}"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>
                Estás viendo la <strong>versión {{ $version->numero }} ({{ mb_strtolower($textoVersion) }})</strong>{{ $vigente ? '. La que rige es la versión '.$vigente->numero.'.' : ': todavía no hay una versión publicada.' }}</p>
        @endunless
        @include('seguridad.procedimientos._contenido', ['p' => $p, 'version' => $version, 'aplicaA' => $aplicaA])
    </section>

    {{-- ===================== Versiones ===================== --}}
    @unless ($lectorSolo)
        <section id="panel-versiones" class="panel-pase" role="tabpanel" data-panel-pase="versiones" aria-label="Versiones">
            <p class="small text-muted"><i class="bi bi-lock" aria-hidden="true"></i> Una versión publicada no se edita: los cambios van en una versión nueva. El historial no se puede borrar.</p>
            <ol class="lista-versiones">
                @foreach ($versiones->sortByDesc('numero') as $v)
                    @php [$tv, $cv] = $v->insignia(); @endphp
                    <li class="version-procedimiento {{ $cv }}">
                        <div class="version-procedimiento-cabecera">
                            <a href="{{ route('procedimientos.show', ['procedimiento' => $p->id, 'version' => $v->numero]) }}" class="fw-bold">Versión {{ $v->numero }}</a>
                            <span class="badge-procedimiento {{ $cv }}">{{ $tv }}</span>
                        </div>
                        <dl class="fechas-pase">
                            <div><dt>Escribió</dt><dd>{{ $v->autor?->name ?? '—' }} · @fecha($v->created_at, 'd/m/Y')</dd></div>
                            @if ($v->enviado_en)<div><dt>Envió a revisión</dt><dd>{{ $v->envio?->name ?? '—' }} · @fecha($v->enviado_en, 'd/m/Y')</dd></div>@endif
                            @if ($v->aprobado_en)<div><dt>Aprobó</dt><dd>{{ $v->aprobador_nombre }}{{ $v->aprobador_cargo ? ' ('.$v->aprobador_cargo.')' : '' }} · @fecha($v->aprobado_en)</dd></div>@endif
                            @if ($v->reemplazada_en)<div><dt>Reemplazada</dt><dd>@fecha($v->reemplazada_en, 'd/m/Y')</dd></div>@endif
                        </dl>
                        @if ($v->resumen_cambios)<p class="comentario-pase m-0">Qué cambió: «{{ $v->resumen_cambios }}»</p>@endif
                        @if ($v->firma_ruta)
                            <figure class="firma-aprobacion">
                                <img src="{{ route('procedimientos.versiones.firma', [$p->id, $v->id]) }}" alt="Firma de {{ $v->aprobador_nombre }}" class="miniatura-firma" loading="lazy">
                                <figcaption>Firma de aprobación</figcaption>
                            </figure>
                        @endif
                        @if (($eventos[$v->id] ?? collect())->isNotEmpty())
                            <details class="ronda-anterior">
                                <summary>Historial de la versión {{ $v->numero }} ({{ count($eventos[$v->id]) }})</summary>
                                <ol class="bitacora-pase">
                                    @foreach ($eventos[$v->id]->sortByDesc('id') as $e)
                                        @php [$te, $ie, $ce] = $e->datos(); @endphp
                                        <li class="bitacora-item {{ $ce }}">
                                            <span class="bitacora-icono" aria-hidden="true"><i class="bi {{ $ie }}"></i></span>
                                            <div class="min-w-0 flex-fill">
                                                <strong>{{ $te }}</strong>
                                                <span class="texto-traza d-block">{{ $e->usuario_nombre ?? 'Sistema' }} · @fecha($e->created_at){{ $e->ip ? ' · IP '.$e->ip : '' }}</span>
                                                @if ($e->comentario)<span class="comentario-pase">«{{ $e->comentario }}»</span>@endif
                                            </div>
                                        </li>
                                    @endforeach
                                </ol>
                            </details>
                        @endif
                    </li>
                @endforeach
            </ol>
            @if (($eventos[''] ?? collect())->isNotEmpty())
                <h2 class="titulo-seccion-pase"><i class="bi bi-journal-text me-2 text-primary" aria-hidden="true"></i>Del procedimiento</h2>
                <ol class="bitacora-pase">
                    @foreach ($eventos['']->sortByDesc('id') as $e)
                        @php [$te, $ie, $ce] = $e->datos(); @endphp
                        <li class="bitacora-item {{ $ce }}">
                            <span class="bitacora-icono" aria-hidden="true"><i class="bi {{ $ie }}"></i></span>
                            <div class="min-w-0 flex-fill">
                                <strong>{{ $te }}</strong>
                                <span class="texto-traza d-block">{{ $e->usuario_nombre ?? 'Sistema' }} · @fecha($e->created_at)</span>
                                @if ($e->comentario)<span class="comentario-pase">«{{ $e->comentario }}»</span>@endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            @endif
        </section>
    @endunless

    {{-- ===================== Acuses ===================== --}}
    @if ($puede['acuses'])
        @php
            $porcentaje = $acuses['deben'] > 0 ? (int) floor($acuses['firmaron'] * 100 / $acuses['deben']) : 0;
            $faltan = $acuses['filas']->whereNull('acuse');
            $firmaron = $acuses['filas']->whereNotNull('acuse');
        @endphp
        <section id="panel-acuses" class="panel-pase" role="tabpanel" data-panel-pase="acuses" aria-label="Acuses">
            <div class="tarjeta p-3 mb-3">
                <div class="acuse-barra grande">
                    <div class="acuse-barra-texto"><span>Cumplimiento de la versión {{ $vigente->numero }}</span><strong>{{ $acuses['firmaron'] }} de {{ $acuses['deben'] }} · {{ $porcentaje }}%</strong></div>
                    <div class="acuse-barra-fondo"><div class="acuse-barra-avance {{ $porcentaje === 100 ? 'completo' : '' }}" style="width: {{ $porcentaje }}%"></div></div>
                </div>
                <form method="GET" data-autoenviar action="{{ route('procedimientos.show', $p->id) }}" class="filtros-acuses">
                    <input type="hidden" name="pestana" value="acuses">
                    @if ($sedesAcuses->count() > 1)
                        <select name="sede" class="filtro-select" aria-label="Filtrar por sede" data-enviar-al-cambiar>
                            <option value="">Todas las sedes</option>
                            @foreach ($sedesAcuses as $s)
                                <option value="{{ $s->id }}" @selected($acusesFiltros['sede'] === $s->id)>{{ $s->nombre }}</option>
                            @endforeach
                        </select>
                    @endif
                    <select name="departamento" class="filtro-select" aria-label="Filtrar por departamento" data-enviar-al-cambiar>
                        <option value="">Todos los departamentos</option>
                        @foreach ($departamentosAcuses as $d)
                            <option value="{{ $d->id }}" @selected($acusesFiltros['departamento'] === $d->id)>{{ $d->nombre }}</option>
                        @endforeach
                    </select>
                    <noscript><button type="submit" class="btn-buscar-pases">Filtrar</button></noscript>
                    <a href="{{ route('procedimientos.acuses.exportar', array_filter(['procedimiento' => $p->id, 'sede' => $acusesFiltros['sede'], 'departamento' => $acusesFiltros['departamento']])) }}" class="btn-secundario-pase"><i class="bi bi-file-earmark-spreadsheet me-1" aria-hidden="true"></i>Exportar</a>
                </form>
            </div>

            <h2 class="titulo-seccion-pase"><i class="bi bi-hourglass-split me-2 text-warning" aria-hidden="true"></i>Faltan <span class="normal">({{ $faltan->count() }})</span></h2>
            @if ($faltan->isEmpty())
                <p class="text-muted small">Nadie pendiente{{ $acusesFiltros['sede'] || $acusesFiltros['departamento'] ? ' con este filtro' : '' }}.</p>
            @else
                <ul class="lista-acuses">
                    @foreach ($faltan as $fila)
                        @php $c = $fila['usuario']->colaborador; @endphp
                        <li class="acuse-fila falta">
                            <span class="acuse-icono" aria-hidden="true"><i class="bi bi-hourglass-split"></i></span>
                            <div class="min-w-0 flex-fill"><strong>{{ $fila['usuario']->name }}</strong>
                                <span class="d-block small text-muted">{{ $c?->num_empleado ? 'Núm. '.$c->num_empleado.' · ' : '' }}{{ $c?->sede?->nombre ?? 'Corporativo' }}{{ $c?->departamento ? ' · '.$c->departamento->nombre : '' }}{{ $c?->puesto ? ' · '.$c->puesto->nombre : '' }}</span></div>
                            <span class="estado-seccion pendiente">Falta</span>
                        </li>
                    @endforeach
                </ul>
            @endif

            <h2 class="titulo-seccion-pase"><i class="bi bi-check2-circle me-2 text-success" aria-hidden="true"></i>Firmaron <span class="normal">({{ $firmaron->count() + $acuses['extra']->count() }})</span></h2>
            @if ($firmaron->isEmpty() && $acuses['extra']->isEmpty())
                <p class="text-muted small">Todavía nadie firma esta versión.</p>
            @else
                <ul class="lista-acuses">
                    @foreach ($firmaron as $fila)
                        @php $c = $fila['usuario']->colaborador; @endphp
                        <li class="acuse-fila firmo">
                            <span class="acuse-icono" aria-hidden="true"><i class="bi bi-check2"></i></span>
                            <div class="min-w-0 flex-fill"><strong>{{ $fila['usuario']->name }}</strong>
                                <span class="d-block small text-muted">{{ $c?->sede?->nombre ?? 'Corporativo' }}{{ $c?->departamento ? ' · '.$c->departamento->nombre : '' }} · firmó el @fecha($fila['acuse']->leido_en)</span></div>
                            <img src="{{ route('procedimientos.acuses.firma', [$p->id, $fila['acuse']->id]) }}" alt="Firma de {{ $fila['usuario']->name }}" class="miniatura-firma" loading="lazy">
                        </li>
                    @endforeach
                    @foreach ($acuses['extra'] as $a)
                        <li class="acuse-fila firmo">
                            <span class="acuse-icono" aria-hidden="true"><i class="bi bi-check2"></i></span>
                            <div class="min-w-0 flex-fill"><strong>{{ $a->nombre }}</strong>
                                <span class="d-block small text-muted">Firmó sin estar en la lista · @fecha($a->leido_en)</span></div>
                            <img src="{{ route('procedimientos.acuses.firma', [$p->id, $a->id]) }}" alt="Firma de {{ $a->nombre }}" class="miniatura-firma" loading="lazy">
                        </li>
                    @endforeach
                </ul>
            @endif
            <p class="small text-muted mt-2"><i class="bi bi-info-circle" aria-hidden="true"></i> Deben firmar los usuarios activos con colaborador ligado que entran en «A quién aplica». Una versión nueva pide firmar otra vez.</p>
        </section>
    @endif

    <p class="texto-traza mt-3">
        @if ($p->creador)<i class="bi bi-plus-circle" aria-hidden="true"></i> Creado por {{ $p->creador->name }} · @fecha($p->created_at)@endif
        @if ($p->editor && $p->updated_at?->ne($p->created_at)) · <i class="bi bi-clock-history" aria-hidden="true"></i> Editado por {{ $p->editor->name }} · @fecha($p->updated_at)@endif
    </p>

    @include('seguridad.procedimientos._dialogos')
    @if ($formulario)
        @include('seguridad.procedimientos._formulario', ['trabajo' => $trabajo])
    @endif
</div>
@endsection
