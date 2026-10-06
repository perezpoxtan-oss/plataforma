@extends('layouts.app')

@section('titulo', 'Recorrido '.$r->folio().' · Protección Civil')

@section('contenido')
@use('App\Models\EquipoPc')
@php
    $hayOld = old('_punto') !== null;
    $erroresPunto = $hayOld || $errors->has('equipo_pc_id') || $errors->has('identificador') || $errors->has('categoria');
    $marcados = (array) old('criterios', []);
    $marcado = fn (string $clave) => $hayOld ? array_key_exists($clave, $marcados) : true;
    $numeroPunto = $progreso['puntos'] + 1;
    $porcentaje = $progreso['total'] > 0 ? (int) round($progreso['revisados'] * 100 / $progreso['total']) : 0;
    $categoriaManual = (string) old('categoria', '');
    $zonasSede = $nodos->where('activo', true);
@endphp
<div class="tema-recorridos-pc">
    @if (session('ok'))
        <div class="alert alert-success aviso mb-3" role="status"><i class="bi bi-check-circle-fill" aria-hidden="true"></i> {{ session('ok') }}</div>
    @endif
    @if (session('aviso'))
        <div class="alert alert-warning aviso mb-3" role="status"><i class="bi bi-info-circle-fill" aria-hidden="true"></i> {{ session('aviso') }}</div>
    @endif
    @if ($errors->any() && ! $punto && ! $errors->has('finalizar'))
        <div class="alert alert-danger aviso mb-3" role="alert">
            <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
            <div>@foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
        </div>
    @endif

    <nav class="migas" aria-label="Ubicación">
        <a href="{{ route('recorridos_pc.index') }}"><i class="bi bi-clipboard-check" aria-hidden="true"></i> Recorridos de Protección Civil</a>
        <i class="bi bi-chevron-right" aria-hidden="true"></i>
        <span class="actual" aria-current="page">Recorrido {{ $r->folio() }}</span>
    </nav>

    {{-- ===== Cabecera ===== --}}
    <section class="tarjeta rpc-cabecera estatus-{{ $r->estatus }}" aria-labelledby="titulo-rpc">
        <div class="rpc-cabecera-fila">
            <div>
                <h1 id="titulo-rpc" class="rpc-titulo"><i class="bi bi-clipboard-check-fill text-success me-2" aria-hidden="true"></i>Recorrido {{ $r->folio() }}</h1>
                <p class="rpc-subtitulo"><i class="bi bi-building me-1" aria-hidden="true"></i>{{ $r->sede?->nombre }}@if ($r->espacio) · <i class="bi bi-geo-alt me-1" aria-hidden="true"></i>{{ $r->espacio->nombre }}@endif</p>
            </div>
            <span class="rpc-badge rpc-badge-{{ $r->estatus }}">{{ $r->etiquetaEstatus() }}</span>
        </div>
        <ul class="rpc-trazas">
            <li><i class="bi bi-person-fill" aria-hidden="true"></i> Iniciado por {{ $r->creador?->name ?? '—' }} · @fecha($r->created_at)</li>
            @if ($r->finalizado_en)
                <li><i class="bi bi-flag-fill" aria-hidden="true"></i> Finalizado por {{ $r->finalizador?->name ?? '—' }} · @fecha($r->finalizado_en)</li>
            @elseif ($r->editor && $r->updated_at?->ne($r->created_at))
                <li><i class="bi bi-clock-history" aria-hidden="true"></i> Último movimiento: {{ $r->editor->name }} · @fecha($r->updated_at)</li>
            @endif
            @if ($r->novedad)
                <li class="rpc-texto-ticket"><i class="bi bi-link-45deg" aria-hidden="true"></i> Generó ticket de seguimiento
                    @if ($puedeVerTicket)<a href="{{ route('novedades.index', ['abrir' => $r->novedad->id]) }}">{{ $r->novedad->folio() }}</a>@else<strong>{{ $r->novedad->folio() }}</strong>@endif
                    en la Bitácora de Novedades ({{ $r->novedad->etiquetaEstatus() }})</li>
            @endif
        </ul>
        @if ($puede['imprimir'])
            @php $dia = app(\App\Support\HoraLocal::class)->formatear($r->created_at, 'Y-m-d'); @endphp
            <a href="{{ route('recorridos_pc.reporte', ['desde' => $dia, 'hasta' => $dia, 'sede' => $r->sede_id]) }}" target="_blank" rel="noopener" class="btn-rpc btn-rpc-claro mt-2"><i class="bi bi-printer me-1" aria-hidden="true"></i>Reporte de Auditoría del día</a>
        @endif
    </section>

    {{-- ===== Avance ===== --}}
    <section class="tarjeta rpc-progreso" aria-label="Avance del recorrido">
        @if ($progreso['total'] > 0)
            <div class="d-flex justify-content-between align-items-baseline gap-2 flex-wrap">
                <strong class="rpc-progreso-texto">{{ $progreso['revisados'] }} de {{ $progreso['total'] }} equipos revisados</strong>
                <span class="small text-muted">{{ $r->espacio ? 'Catálogo de '.$r->espacio->nombre : 'Catálogo de la sede' }}</span>
            </div>
            <div class="rpc-barra" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $progreso['total'] }}" aria-valuenow="{{ $progreso['revisados'] }}" aria-label="Equipos revisados">
                <span class="rpc-barra-relleno rpc-ancho-{{ (int) (round($porcentaje / 5) * 5) }}"></span>
            </div>
        @endif
        <div class="rpc-contadores">
            <span><i class="bi bi-clipboard-check" aria-hidden="true"></i> {{ $progreso['puntos'] }} {{ $progreso['puntos'] === 1 ? 'punto' : 'puntos' }}</span>
            <span class="{{ $progreso['fallas'] > 0 ? 'rpc-texto-hallazgo' : 'rpc-texto-ok' }}"><i class="bi {{ $progreso['fallas'] > 0 ? 'bi-exclamation-triangle-fill' : 'bi-check-circle-fill' }}" aria-hidden="true"></i> {{ $progreso['fallas'] }} con hallazgo</span>
            @if ($editable && $progreso['total'] > 0)
                <span><i class="bi bi-hourglass-split" aria-hidden="true"></i> {{ $progreso['pendientes']->count() }} pendientes</span>
            @endif
        </div>
    </section>

    @if ($editable)
        @if ($punto)
            {{-- ===== Punto de inspección (uno a la vez) ===== --}}
            @php
                $equipo = $punto['equipo'];
                $categoria = $equipo?->categoria ?? $categoriaManual;
                $categoriasBloques = $equipo ? [$equipo->categoria] : array_keys(EquipoPc::CATEGORIAS);
            @endphp
            <section id="punto" class="tarjeta rpc-punto" aria-labelledby="titulo-punto">
                <h2 id="titulo-punto" class="rpc-punto-titulo"><i class="bi bi-shield-check me-2" aria-hidden="true"></i>Punto de Inspección #{{ $numeroPunto }}</h2>

                @if ($equipo)
                    <div class="rpc-equipo">
                        <span class="rpc-equipo-icono" aria-hidden="true"><i class="bi {{ $equipo->icono() }}"></i></span>
                        <div>
                            <div class="rpc-equipo-serie">{{ $equipo->numero_serie }}</div>
                            <div class="rpc-equipo-categoria">{{ $equipo->etiquetaCategoria() }}</div>
                            @if ($punto['ubicacion'] || $equipo->referencia)
                                <div class="small"><i class="bi bi-geo-alt me-1" aria-hidden="true"></i>{{ implode(' · ', array_filter([$punto['ubicacion'], $equipo->referencia])) }}</div>
                            @endif
                        </div>
                    </div>
                @endif

                @if ($erroresPunto && $errors->any())
                    <div class="alert alert-danger aviso mb-3" role="alert">
                        <i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i>
                        <div>@foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach</div>
                    </div>
                @endif
                @if ($punto['previa'])
                    <p class="rpc-aviso-duplicado"><i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>Este equipo ya se revisó en este mismo recorrido (@fecha($punto['previa']->created_at, 'H:i'), {{ $punto['previa']->esFalla() ? 'con hallazgo' : 'OK' }}). Si lo guardas otra vez, queda un segundo registro.</p>
                @endif

                <form action="{{ route('recorridos_pc.revisiones.store', $r->id) }}" method="POST" autocomplete="off" data-form-punto-rpc>
                    @csrf
                    <input type="hidden" name="_punto" value="{{ $punto['modo'] }}">
                    @if ($equipo)
                        <input type="hidden" name="equipo_pc_id" value="{{ $equipo->id }}">
                        <input type="hidden" name="categoria" value="{{ $equipo->categoria }}">
                    @else
                        <p class="rpc-ayuda"><i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Captura a mano un equipo sin etiqueta o que no está en el catálogo. Si su ID sí está en el catálogo de la sede, se liga solo.</p>
                        <label class="campo-etiqueta" for="rpc_identificador">Lector ID / Núm. de Serie</label>
                        <input type="text" id="rpc_identificador" name="identificador" class="campo text-uppercase fw-bold" maxlength="100" required autocapitalize="characters"
                               placeholder="Ej. EXT-01" value="{{ old('identificador') }}">
                        <label class="campo-etiqueta" for="rpc_categoria">Categoría del Equipo</label>
                        <select id="rpc_categoria" name="categoria" class="campo" required data-rpc-categoria>
                            <option value="">-- Seleccione Categoría --</option>
                            @foreach (array_keys(EquipoPc::CATEGORIAS) as $clave)
                                <option value="{{ $clave }}" @selected($categoriaManual === $clave)>{{ EquipoPc::opcionRecorrido($clave) }}</option>
                            @endforeach
                        </select>
                        <label class="campo-etiqueta" for="rpc_zona">Ubicación <span class="text-lowercase fw-normal">(opcional)</span></label>
                        <select id="rpc_zona" name="zona_id" class="campo">
                            <option value="">-- Sin ubicación --</option>
                            @foreach ($zonasSede as $n)
                                <option value="{{ $n['id'] }}" @selected((string) old('zona_id') === (string) $n['id'])>{{ $n['texto'] }}</option>
                            @endforeach
                        </select>
                    @endif

                    <h3 class="rpc-seccion"><i class="bi bi-list-check me-2" aria-hidden="true"></i>Componentes <span>(Marcar lo SANO/PRESENTE)</span></h3>
                    @unless ($equipo)
                        <p class="rpc-sin-categoria" data-rpc-sin-categoria @if ($categoria !== '') hidden @endif>Seleccione una categoría arriba para cargar las piezas a evaluar.</p>
                    @endunless
                    @foreach ($categoriasBloques as $cat)
                        @php $activo = $cat === $categoria; @endphp
                        <div class="rpc-criterios" data-rpc-criterios="{{ $cat }}" @unless ($activo) hidden @endunless>
                            @foreach (EquipoPc::componentes($cat) as $clave => $texto)
                                <label class="rpc-criterio">
                                    <input type="checkbox" name="criterios[{{ $clave }}]" value="1" @checked($marcado($clave)) @unless ($activo) disabled @endunless>
                                    <span class="rpc-interruptor" aria-hidden="true"></span>
                                    <span class="rpc-criterio-texto">{{ $texto }}</span>
                                </label>
                            @endforeach
                        </div>
                    @endforeach
                    <div class="rpc-universales" data-rpc-universales @if ($categoria === '') hidden @endif>
                        <h3 class="rpc-seccion">Criterios Operativos Universales</h3>
                        <div class="rpc-criterios">
                            @foreach (\App\Services\Novedades\Formatos\RecorridoPc::UNIVERSALES as $clave => $texto)
                                <label class="rpc-criterio">
                                    <input type="checkbox" name="criterios[{{ $clave }}]" value="1" @checked($marcado($clave)) @if ($categoria === '') disabled @endif>
                                    <span class="rpc-interruptor" aria-hidden="true"></span>
                                    <span class="rpc-criterio-texto">{{ $texto }}</span>
                                </label>
                            @endforeach
                        </div>
                    </div>

                    <label class="campo-etiqueta rpc-etiqueta-falla mt-3" for="rpc_observaciones">Observaciones / Desperfectos Encontrados</label>
                    <textarea id="rpc_observaciones" name="observaciones" class="campo rpc-campo-falla" rows="2" maxlength="2000" placeholder="Describa daños físicos o anomalías...">{{ old('observaciones') }}</textarea>

                    <p class="rpc-resultado" data-rpc-resultado role="status" aria-live="polite"></p>

                    <div class="rpc-punto-acciones">
                        <a href="{{ route('recorridos_pc.show', $r->id) }}#escanear" class="btn-rpc btn-rpc-claro">Cancelar</a>
                        <button type="submit" class="btn-rpc btn-rpc-verde"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Guardar y escanear siguiente</button>
                    </div>
                </form>
            </section>
        @else
            {{-- ===== Escanear el siguiente equipo ===== --}}
            <section id="escanear" class="tarjeta rpc-escaner" aria-labelledby="titulo-escanear">
                <h2 id="titulo-escanear" class="rpc-punto-titulo"><i class="bi bi-qr-code-scan me-2" aria-hidden="true"></i>Punto de Inspección #{{ $numeroPunto }}</h2>
                @if ($errorPunto)
                    <div class="alert alert-danger aviso mb-3" role="alert"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i> {{ $errorPunto }}</div>
                @endif
                <form action="{{ route('recorridos_pc.show', $r->id) }}" method="GET" data-escaner-rpc>
                    @include('componentes.lector', ['id' => 'rpc_escaner', 'etiqueta' => 'Lector ID / Escáner QR / NFC / RFID', 'tipos' => 'equipo_pc', 'nombre' => 'equipo',
                        'ayuda' => 'Escanea el QR del equipo con la cámara, acerca su etiqueta NFC o usa el lector USB. También puedes escribir su ID (ej. EXT-01) y oprimir Enter.'])
                    <noscript><button type="submit" class="btn-rpc btn-rpc-verde">Abrir punto</button></noscript>
                </form>
                <a href="{{ route('recorridos_pc.show', ['recorrido' => $r->id, 'manual' => 1]) }}#punto" class="rpc-enlace-manual"><i class="bi bi-pencil-square me-1" aria-hidden="true"></i>¿No tiene etiqueta o no está en el catálogo? Captúralo a mano</a>
            </section>

            @if ($progreso['pendientes']->isNotEmpty())
                <details class="tarjeta rpc-pendientes" @if ($progreso['puntos'] === 0) open @endif>
                    <summary><i class="bi bi-hourglass-split me-2" aria-hidden="true"></i>Equipos pendientes de revisar ({{ $progreso['pendientes']->count() }})</summary>
                    <p class="small text-muted mb-2">Si la etiqueta está dañada, toca «Revisar» en el equipo.</p>
                    <ul class="rpc-lista-pendientes">
                        @foreach ($progreso['pendientes'] as $p)
                            <li>
                                <span class="rpc-pendiente-icono" aria-hidden="true"><i class="bi {{ $p->icono() }}"></i></span>
                                <span class="rpc-pendiente-texto">
                                    <strong>{{ $p->numero_serie }}</strong> · {{ $p->etiquetaCategoria() }}
                                    @if ($p->espacio_id && $nodos->has($p->espacio_id) || $p->referencia)
                                        <small>{{ implode(' · ', array_filter([$p->espacio_id ? ($nodos->get($p->espacio_id)['texto'] ?? null) : null, $p->referencia])) }}</small>
                                    @endif
                                </span>
                                <a href="{{ route('recorridos_pc.show', ['recorrido' => $r->id, 'equipo' => $p->id]) }}#punto" class="btn-rpc btn-rpc-claro rpc-btn-revisar" aria-label="Revisar {{ $p->numero_serie }}">Revisar</a>
                            </li>
                        @endforeach
                    </ul>
                </details>
            @endif
        @endif
    @endif

    {{-- ===== Equipos revisados ===== --}}
    <section class="tarjeta rpc-revisados" aria-labelledby="titulo-revisados">
        <h2 id="titulo-revisados" class="rpc-punto-titulo"><i class="bi bi-list-check me-2" aria-hidden="true"></i>Equipos revisados ({{ $r->revisiones->count() }})</h2>
        @forelse ($r->revisiones->sortByDesc('id') as $p)
            @php $fallas = $p->criteriosConFalla(); @endphp
            <article class="rpc-revision {{ $p->esFalla() ? 'falla' : 'ok' }}">
                <div class="d-flex justify-content-between align-items-start gap-2">
                    <div>
                        <div class="rpc-revision-titulo">{{ $p->identificador }} <span>· {{ $p->etiquetaCategoria() }}</span></div>
                        @if ($p->ubicacion)
                            <div class="small"><i class="bi bi-geo-alt me-1" aria-hidden="true"></i>{{ $p->ubicacion }}</div>
                        @endif
                    </div>
                    <span class="rpc-resultado-badge {{ $p->esFalla() ? 'falla' : 'ok' }}">{{ $p->esFalla() ? 'FALLA' : 'OK' }}</span>
                </div>
                @if ($fallas !== [])
                    <div class="small rpc-texto-hallazgo mt-1"><i class="bi bi-x-circle me-1" aria-hidden="true"></i>Falla en: {{ implode(', ', $fallas) }}</div>
                @endif
                @if ($p->observaciones)
                    <div class="small mt-1"><i class="bi bi-chat-left-text me-1" aria-hidden="true"></i>{{ $p->observaciones }}</div>
                @endif
                <div class="texto-traza mt-1">@fecha($p->created_at, 'd/m/Y H:i') · {{ $p->creador?->name ?? '—' }}@unless ($p->equipo_pc_id) · capturado a mano @endunless</div>
            </article>
        @empty
            <p class="text-muted small m-0">Todavía no hay equipos revisados en este recorrido.</p>
        @endforelse
    </section>

    {{-- ===== Observaciones generales, Guardar / Finalizar ===== --}}
    @if ($editable)
        <section class="tarjeta rpc-cierre" aria-labelledby="titulo-cierre">
            <form action="{{ route('recorridos_pc.update', $r->id) }}" method="POST">
                @csrf
                @method('PUT')
                @error('finalizar')
                    <div class="alert alert-danger aviso mb-3" role="alert"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i> {{ $message }}</div>
                @enderror
                <label id="titulo-cierre" class="campo-etiqueta" for="rpc_obs_generales">Observaciones Generales del Recorrido</label>
                <textarea id="rpc_obs_generales" name="observaciones_generales" class="campo" rows="2" maxlength="2000" placeholder="Notas generales, si aplica...">{{ old('observaciones_generales', $r->observaciones_generales) }}</textarea>
                <div class="rpc-cierre-acciones">
                    <button type="submit" class="btn-rpc btn-rpc-contorno"><i class="bi bi-cloud-arrow-up me-1" aria-hidden="true"></i>Guardar y Continuar Después</button>
                    <button type="submit" name="finalizar" value="1" class="btn-rpc btn-rpc-verde"
                            data-confirmar-boton="¿Finalizar el recorrido? Ya no se podrán agregar equipos.{{ $progreso['total'] > $progreso['revisados'] ? ' Quedan '.($progreso['total'] - $progreso['revisados']).' equipos del catálogo sin revisar.' : '' }}">
                        <i class="bi bi-check-circle-fill me-1" aria-hidden="true"></i>Finalizar Recorrido
                    </button>
                </div>
            </form>
        </section>
    @elseif ($r->observaciones_generales)
        <section class="tarjeta rpc-cierre">
            <h2 class="campo-etiqueta">Observaciones Generales del Recorrido</h2>
            <p class="m-0 rpc-texto-largo">{{ $r->observaciones_generales }}</p>
        </section>
    @endif
</div>
@endsection
