{{--
    Detalle "Firmas del pase" (SEGCAT: pases_salida_modal_aprobar.php). Se pinta
    dentro de <dialog id="dialogoDetallePase">: al abrir una tarjeta (lo pide
    plataforma.js a GET /pases-salida/{id}) o al volver de firmar o rechazar
    (?pase={id}).

    Variables: $pase, $grupoAbierto, $vencido, $puedeFirmar, $puedeRechazar,
    $puedeImprimir, $sugeridos (rol => nombre propuesto).
--}}
@use('App\Models\PaseSalida')
@php
    [$textoEstado, $claseEstado] = $pase->insignia($vencido);
    $firmadas = $pase->firmas->keyBy('rol');
    $rolAnterior = old('rol');
    $grupoAnterior = is_string($rolAnterior) ? PaseSalida::grupoDeRol($rolAnterior) : null;
    $reabrirFirma = $errors->any() && $grupoAnterior !== null && $grupoAnterior === $grupoAbierto && $puedeFirmar && ! $firmadas->has($rolAnterior);
    $reabrirRechazo = $errors->any() && old('_dialogo') === 'rechazar' && $puedeRechazar;
    $tentativa = PaseSalida::dia($pase->fecha_tentativa_regreso);
    $iconos = ['aprobacion' => 'bi-pen-fill text-primary', 'salida_fisica' => 'bi-box-arrow-right texto-morado', 'recepcion_destino' => 'bi-box-arrow-in-down text-info',
        'salida_regreso' => 'bi-signpost-split texto-morado', 'regreso' => 'bi-arrow-return-left text-primary'];
    $ordenGrupos = $pase->gruposDelCircuito();
    $posicionAbierto = $grupoAbierto === null ? null : array_search($grupoAbierto, $ordenGrupos, true);
@endphp
<div class="dialogo-cabecera">
    <h2 id="titulo-detalle-pase"><i class="bi bi-clipboard-check-fill me-2 text-primary" aria-hidden="true"></i>{{ $pase->folio }}</h2>
    <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
</div>
<div class="dialogo-cuerpo" data-detalle-pase-cuerpo>
    @if ($errors->any())
        <div class="alert alert-danger small py-2" role="alert">
            @foreach ($errors->all() as $error)<div><i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>{{ $error }}</div>@endforeach
        </div>
    @endif

    <div class="mb-2"><span class="badge-pase pase-{{ $claseEstado }}">{{ $textoEstado }}</span></div>

    <div class="resumen-pase">
        <strong>{{ $pase->etiquetaMotivo() }}</strong> — Solicita: {{ $pase->solicitante?->nombreCompleto() ?? '—' }}
        @if ($pase->solicitante)
            <span class="text-muted">({{ $pase->solicitante->num_empleado ? 'Núm. '.$pase->solicitante->num_empleado : 'provisional' }}{{ $pase->solicitante->departamento ? ' · '.$pase->solicitante->departamento->nombre : '' }}{{ $pase->solicitante->puesto ? ' · '.$pase->solicitante->puesto->nombre : '' }})</span>
        @endif
        <br>Sale de: <strong>{{ $pase->sede?->nombre }}</strong> → Destino: <strong>{{ $pase->nombreDestino() }}</strong>
        <span class="text-muted">({{ ['sede' => 'otra sede', 'proveedor' => 'proveedor', 'colaborador' => 'colaborador se lo lleva'][$pase->destino_tipo] ?? '' }})</span>
        @if ($pase->destino_direccion)<br>Dirección: {{ $pase->destino_direccion }}@endif
        @if ($pase->destino_telefono)<br>Teléfono: {{ $pase->destino_telefono }}@endif
        @if ($pase->fecha_salida_programada)<br>Fecha de salida: @fecha(PaseSalida::dia($pase->fecha_salida_programada), 'd/m/Y')@endif
        @if ($tentativa)
            <br>Regreso tentativo: @fecha($tentativa, 'd/m/Y')
            @if ($vencido)<span class="texto-vencido"><i class="bi bi-exclamation-triangle-fill" aria-hidden="true"></i> Vencido</span>@endif
        @endif
        @unless ($pase->requiere_regreso)<br><span class="text-muted"><i class="bi bi-check2-circle" aria-hidden="true"></i> Este motivo no espera regreso del equipo.</span>@endunless
    </div>

    <h3 class="titulo-seccion-pase"><i class="bi bi-box-seam text-warning me-2" aria-hidden="true"></i>Artículos <span class="normal">(valida que coincidan con lo que llegó/regresa)</span></h3>
    @foreach ($pase->articulos as $a)
        <div class="articulo-pase">
            <strong>{{ $a->cantidad }}x {{ $a->equipo }}</strong> {{ trim(($a->marca ?? '').' '.($a->modelo ?? '')) }}
            @if ($a->serie) · Serie: {{ $a->serie }}@endif
            @if ($a->equipo_id)<span class="chip-padron" title="Equipo del padrón de Equipos de seguridad"><i class="bi bi-upc-scan" aria-hidden="true"></i> padrón</span>@endif
            @if ($a->descripcion)<br><span class="text-muted">{{ $a->descripcion }}</span>@endif
        </div>
    @endforeach

    @switch ($pase->estado)
        @case (PaseSalida::RECHAZADO)
            <div class="alert alert-danger mt-3 small"><i class="bi bi-x-circle-fill me-1" aria-hidden="true"></i><strong>Rechazado.</strong> {{ $pase->motivo_rechazo }}
                @if ($pase->rechazador)<br><span class="texto-traza">Rechazó {{ $pase->rechazador->name }} · @fecha($pase->rechazado_en)</span>@endif
            </div>
            @break
        @case (PaseSalida::APROBADO)
            <div class="alert alert-primary py-2 px-3 small mt-3"><i class="bi bi-check-circle-fill me-1" aria-hidden="true"></i>Pase aprobado — listo para que el equipo salga físicamente.</div>
            @break
        @case (PaseSalida::SALIO)
            @if (! $pase->requiere_regreso)
                <div class="alert alert-success mt-3 small"><i class="bi bi-check-circle-fill me-1" aria-hidden="true"></i><strong>Salió</strong> el @fecha($pase->salio_en). Este motivo no espera regreso — queda cerrado.</div>
            @elseif ($pase->destino_tipo === 'sede')
                <div class="alert alert-info py-2 px-3 small mt-3"><i class="bi bi-truck me-1" aria-hidden="true"></i>El equipo va en camino a {{ $pase->sedeDestino?->nombre }} — falta que confirmen su llegada.</div>
            @else
                <div class="alert alert-info py-2 px-3 small mt-3"><i class="bi bi-truck me-1" aria-hidden="true"></i>Salió el @fecha($pase->salio_en) — falta confirmar su regreso a {{ $pase->sede?->nombre }}.</div>
            @endif
            @break
        @case (PaseSalida::EN_DESTINO)
            <div class="alert alert-info py-2 px-3 small mt-3"><i class="bi bi-check-circle-fill me-1" aria-hidden="true"></i>El equipo llegó a destino el @fecha($pase->recibido_destino_en) — antes de regresar, la sede destino corre su propio circuito de salida.</div>
            @break
        @case (PaseSalida::EN_TRANSITO_REGRESO)
            <div class="alert alert-info py-2 px-3 small mt-3"><i class="bi bi-truck me-1" aria-hidden="true"></i>El equipo va en camino de vuelta a {{ $pase->sede?->nombre }}.</div>
            @break
        @case (PaseSalida::REGRESADO)
            <div class="alert alert-success mt-3 small"><i class="bi bi-check-circle-fill me-1" aria-hidden="true"></i><strong>Regresó y quedó cerrado</strong> el @fecha($pase->regreso_en).</div>
            @break
    @endswitch

    <h3 class="titulo-seccion-pase mt-3"><i class="bi bi-pen me-2 text-primary" aria-hidden="true"></i>Firmas del pase</h3>
    @foreach ($ordenGrupos as $indice => $grupo)
        @php
            [$tituloGrupo, $roles] = PaseSalida::GRUPOS[$grupo];
            $firmadosGrupo = collect(array_keys($roles))->filter(fn ($r) => $firmadas->has($r))->count();
            $completo = $firmadosGrupo === count($roles);
            $abierto = $grupo === $grupoAbierto;
            $estadoGrupo = match (true) {
                $completo => ['Completo', 'completo'],
                $abierto => ['En curso · '.$firmadosGrupo.' de '.count($roles), 'en-curso'],
                $pase->estado === PaseSalida::RECHAZADO => ['No aplica', 'no-aplica'],
                $posicionAbierto === null && $firmadosGrupo === 0 => ['No aplica', 'no-aplica'],
                default => ['Pendiente', 'pendiente'],
            };
        @endphp
        <section class="seccion-firmas {{ $abierto ? 'abierta' : '' }}" aria-label="{{ $tituloGrupo }}">
            <div class="seccion-firmas-cabecera">
                <h4><i class="bi {{ $iconos[$grupo] }} me-2" aria-hidden="true"></i>{{ $tituloGrupo }}</h4>
                <span class="estado-seccion {{ $estadoGrupo[1] }}">{{ $estadoGrupo[0] }}</span>
            </div>
            @foreach ($roles as $rol => $textoRol)
                @if ($firmadas->has($rol))
                    @php $f = $firmadas[$rol]; @endphp
                    <div class="fila-firma firmada">
                        <span class="min-w-0"><i class="bi bi-check-circle-fill text-success me-1" aria-hidden="true"></i><strong>{{ $textoRol }}</strong> — {{ $f->nombre_firma }}
                            <span class="texto-traza d-block">@fecha($f->created_at){{ $f->capturo ? ' · capturó '.$f->capturo->name : '' }}</span></span>
                        <img src="{{ route('pases-salida.firma', [$pase->id, $f->id]) }}" alt="Firma de {{ $f->nombre_firma }}" class="miniatura-firma" loading="lazy">
                    </div>
                @elseif ($abierto && $puedeFirmar)
                    <div class="fila-firma pendiente">
                        <span><i class="bi bi-hourglass-split text-warning me-1" aria-hidden="true"></i>{{ $textoRol }} — pendiente</span>
                        <button type="button" class="btn-firmar-rol" data-firmar-rol="{{ $rol }}" data-rol-texto="{{ $textoRol }}" data-sugerido="{{ $sugeridos[$rol] ?? '' }}">
                            <i class="bi bi-pen me-1" aria-hidden="true"></i>Firmar
                        </button>
                    </div>
                @else
                    <div class="fila-firma pendiente apagada"><span><i class="bi bi-hourglass-split me-1" aria-hidden="true"></i>{{ $textoRol }} — pendiente</span></div>
                @endif
            @endforeach
            @if ($abierto && ! $puedeFirmar)
                <p class="aviso-sin-firma"><i class="bi bi-lock me-1" aria-hidden="true"></i>
                    Tu usuario no firma esta parte: se necesita el permiso «{{ $grupo === 'aprobacion' ? 'Aprobar' : 'Firmar' }}» de Pases de salida en la sede
                    {{ in_array($grupo, PaseSalida::GRUPOS_DEL_DESTINO, true) ? $pase->sedeDestino?->nombre.' (destino)' : $pase->sede?->nombre.' (origen)' }}.</p>
            @endif
        </section>
    @endforeach

    @if ($grupoAbierto !== null && $puedeFirmar)
        <div class="caja-firma-pase" data-caja-firma-pase @unless ($reabrirFirma) hidden @endunless>
            <form action="{{ route('pases-salida.firmar', $pase->id) }}" method="POST" autocomplete="off" data-form-firma-pase>
                @csrf
                <input type="hidden" name="rol" value="{{ $reabrirFirma ? $rolAnterior : '' }}" data-firma-rol>
                <p class="titulo-firma-pase" data-firma-titulo>Firma — {{ $reabrirFirma ? PaseSalida::GRUPOS[$grupoAnterior][1][$rolAnterior] : '' }}</p>
                @include('componentes.firma', ['id' => 'firma_pase_'.$pase->id, 'nombre' => 'firma', 'etiqueta' => 'Firma', 'requerido' => true])
                <label class="campo-etiqueta" for="firma_nombre_{{ $pase->id }}">Nombre completo de quien firma</label>
                <input type="text" id="firma_nombre_{{ $pase->id }}" name="nombre_firma" class="campo text-uppercase" maxlength="150" required
                       value="{{ $reabrirFirma ? old('nombre_firma') : '' }}" autocapitalize="characters" data-firma-nombre>
                <div class="dialogo-acciones">
                    <button type="button" class="btn-cancelar" data-cancelar-firma>Cancelar</button>
                    <button type="submit" class="btn-azul">Guardar Firma</button>
                </div>
            </form>
        </div>
    @endif

    @if ($puedeRechazar)
        <div class="zona-rechazo">
            <button type="button" class="btn-rechazar-pase" data-mostrar-rechazo @if ($reabrirRechazo) hidden @endif><i class="bi bi-x-octagon me-1" aria-hidden="true"></i>Rechazar Pase</button>
            <form action="{{ route('pases-salida.rechazar', $pase->id) }}" method="POST" class="caja-rechazo" data-caja-rechazo @unless ($reabrirRechazo) hidden @endunless>
                @csrf
                <label class="campo-etiqueta" for="motivo_rechazo_{{ $pase->id }}">Motivo del rechazo</label>
                <textarea id="motivo_rechazo_{{ $pase->id }}" name="motivo_rechazo" class="campo" rows="2" maxlength="1000" placeholder="Motivo del rechazo..." required>{{ $reabrirRechazo ? old('motivo_rechazo') : '' }}</textarea>
                <button type="submit" class="btn-confirmar-rechazo" data-confirmar="¿Rechazar el pase {{ $pase->folio }}? Ya no podrá aprobarse.">Confirmar Rechazo</button>
            </form>
        </div>
    @endif

    <div class="pie-detalle-pase">
        <span class="texto-traza">
            @if ($pase->creador)<i class="bi bi-plus-circle" aria-hidden="true"></i> Creado por {{ $pase->creador->name }} · @fecha($pase->created_at)@endif
        </span>
        @if ($puedeImprimir)
            <a href="{{ route('pases-salida.imprimir', $pase->id) }}" target="_blank" rel="noopener" class="btn-imprimir-pase"><i class="bi bi-printer me-1" aria-hidden="true"></i>Imprimir Pase</a>
        @endif
    </div>
</div>
