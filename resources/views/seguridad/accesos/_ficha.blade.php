{{--
    Tarjeta de un acceso (SEGCAT: .ficha-card de accesos_lista.php).
    $a: Acceso con sus relaciones cargadas · $modo: en_sitio | pendientes | historial
    $puede: permisos de la pantalla · $hoy: fecha local de hoy (Y-m-d) · $variasSedes: bool
--}}
@php
    $hora = app(\App\Support\HoraLocal::class);
    $fuera = $modo === 'en_sitio' ? $a->salidaTemporalAbierta : null;
    $formato = fn ($fecha) => $fecha && $hora->formatear($fecha, 'Y-m-d') === $hoy ? 'H:i' : 'd/m/Y H:i';
    $host = $a->host?->nombreCompleto();
    $gafetesPorDevolver = collect([$a->gafete_texto ? 'gafete '.$a->gafete_texto : null])
        ->merge($a->acompanantes->whereNull('salida_at')->pluck('gafete_texto')->filter()->map(fn ($g) => 'gafete '.$g))
        ->filter()->values();
    $idCustodiada = \App\Models\Acceso::IDENTIFICACIONES[$a->identificacion] ?? null;
    $pedir = $gafetesPorDevolver->merge($idCustodiada ? ['la identificación ('.$idCustodiada.')'] : [])->join(', ', ' y ');
    $confirmarSalida = '¿Confirmar salida de '.$a->nombre.'?'.($pedir !== '' ? ' Pide de vuelta: '.$pedir.'.' : '');
@endphp
<article class="ficha-acceso es-{{ $modo }} {{ $fuera ? 'tour-fuera' : '' }} {{ $a->movimiento !== 'entrada' ? 'es-movimiento' : '' }}" id="acceso-{{ $a->id }}"
         data-acceso-ficha data-tipo="{{ $a->tipo }}" data-sede="{{ $a->sede_id }}" data-texto="{{ \App\Services\Accesos\ConsultaAccesos::textoBusqueda($a) }}">
    <div class="ficha-acceso-cabeza">
        <span class="badge-acceso tipo-{{ $a->tipo }}">{{ mb_strtoupper($a->etiquetaTipo()) }}</span>
        @if ($modo === 'pendientes')
            <span class="estado-acceso pendiente">PENDIENTE <i class="bi bi-hourglass-split ms-1" aria-hidden="true"></i></span>
        @elseif ($modo === 'en_sitio')
            @if ($fuera)
                <span class="estado-acceso fuera">{{ $a->tipo === 'huesped' ? 'FUERA EN TOUR' : 'FUERA TEMPORAL' }} <i class="bi bi-signpost-split ms-1" aria-hidden="true"></i></span>
            @else
                <span class="estado-acceso en-sitio">EN SITIO <i class="bi bi-record-circle ms-1" aria-hidden="true"></i></span>
            @endif
        @else
            @if ($a->movimiento !== 'entrada')
                <span class="estado-acceso movimiento">{{ $a->movimiento === 'regreso' ? ($a->tipo === 'huesped' ? 'REGRESO DE TOUR' : 'REGRESO') : ($a->tipo === 'huesped' ? 'SALIDA A TOUR' : 'SALIDA TEMPORAL') }}</span>
            @else
                <span class="gafete-mini">Gafete: {{ $a->gafeteVisible() }}</span>
            @endif
        @endif
    </div>

    <h3 class="ficha-acceso-nombre">{{ $a->nombre }}@if ($a->habitacion) <span class="badge-hab">Hab. {{ $a->habitacion }}</span>@endif</h3>
    {{-- Ronda 8 (AC-05): si lo buscado es un acompañante, se avisa de quién viene acompañando (el filtro en vivo las muestra u oculta) --}}
    @php $coincide = \App\Services\Accesos\ConsultaAccesos::acompananteQueCoincide($a, is_string(request('q')) ? request('q') : null); @endphp
    @foreach ($a->acompanantes as $ac)
        <p class="pista-acompanante" data-pista-acompanante="{{ \App\Services\Accesos\ConsultaAccesos::normalizar($ac->nombre.' '.$ac->gafete_texto) }}" @if ($coincide?->id !== $ac->id) hidden @endif>
            <i class="bi bi-people-fill" aria-hidden="true"></i> Coincide con <strong>{{ $ac->nombreVisible() }}</strong>, acompañante de {{ $a->nombre }}
        </p>
    @endforeach
    @if ($a->empresa_procedencia)
        <div class="ficha-acceso-empresa"><i class="bi bi-building me-1" aria-hidden="true"></i>{{ $a->empresa_procedencia }}</div>
    @endif
    @if ($variasSedes)
        <div class="ficha-acceso-sede"><i class="bi bi-geo-alt me-1" aria-hidden="true"></i>{{ $a->sede?->nombre }}</div>
    @endif

    @if ($modo === 'en_sitio' && ! $fuera)
        <div class="gafete-box"><i class="bi bi-upc-scan me-2" aria-hidden="true"></i>GAFETE: {{ $a->gafeteVisible() }}</div>
    @endif

    @if ($modo === 'historial')
        <div class="ficha-acceso-tiempos">
            <div><strong>{{ $a->movimiento === 'salida_temporal' ? 'Salió:' : ($a->movimiento === 'regreso' ? 'Regresó:' : 'Ingreso:') }}</strong> @fecha($a->entrada_at)</div>
            @if ($a->movimiento !== 'regreso')
                <div><strong>{{ $a->movimiento === 'salida_temporal' ? 'Regresó:' : 'Salida:' }}</strong> {{ $a->salida_at ? $hora->formatear($a->salida_at) : '—' }}</div>
            @endif
            @if ($a->placas)
                <div><strong>Vehículo:</strong> {{ $a->placas }}@if ($a->conductor) · {{ $a->conductor }}@endif</div>
            @endif
            @if ($a->num_acompanantes > 0)
                <div><strong>Acompañantes:</strong> {{ $a->num_acompanantes }}</div>
            @endif
            @if ($a->movimiento === 'entrada')
                <div class="texto-devuelta"><i class="bi bi-check2-all me-1" aria-hidden="true"></i>ID Devuelta por: {{ $a->salidaPor?->name ?? '—' }}</div>
            @endif
        </div>
    @else
        <div class="ficha-acceso-datos">
            @if ($a->persona_visita)
                <div><strong>Visitando a:</strong> <span class="dato-destacado">{{ $a->persona_visita }}</span></div>
            @endif
            @if ($a->motivo_visita)
                <div><strong>Motivo:</strong> {{ \App\Models\Acceso::MOTIVOS[$a->motivo_visita] ?? $a->motivo_visita }}</div>
            @endif
            @if ($a->tipo === 'huesped' && $a->tiene_reserva !== null)
                <div><strong>Reserva:</strong> {{ $a->tiene_reserva ? 'Sí'.($a->numero_reserva ? ' — #'.$a->numero_reserva : '') : 'No' }}</div>
            @endif
            @if ($a->tipo_pase)
                <div><strong>Pase:</strong> {{ \App\Models\Acceso::PASES[$a->tipo_pase] ?? $a->tipo_pase }}</div>
            @endif
            @if ($a->tipo_visita)
                <div><strong>Tipo de Visita:</strong> {{ \App\Models\Acceso::TIPOS_VISITA[$a->tipo_visita] ?? $a->tipo_visita }}</div>
            @endif
            @if ($a->departamento)
                <div><strong>Departamento:</strong> {{ $a->departamento->nombre }}</div>
            @endif
            @if ($a->area_trabajo)
                <div><strong>Área:</strong> {{ $a->area_trabajo }}</div>
            @endif
            @if ($a->actividad)
                <div><strong>Actividad:</strong> {{ $a->actividad }}</div>
            @endif
            @if ($a->tipo_emergencia)
                <div><strong>Emergencia:</strong> {{ \App\Models\Acceso::TIPOS_EMERGENCIA[$a->tipo_emergencia] ?? $a->tipo_emergencia }}</div>
            @endif
            @if ($a->observaciones)
                <div><strong>Observaciones:</strong> {{ $a->observaciones }}</div>
            @endif
            @if (in_array($a->tipo, \App\Models\Acceso::CON_AUTORIZACION, true))
                <div><strong>Host (citó):</strong> <span class="dato-destacado">{{ $host ?: '—' }}</span></div>
            @endif
            @if ($idCustodiada)
                <div><strong>ID en Caseta:</strong> {{ $idCustodiada }}</div>
            @endif
            @if ($a->modo_arribo && ! $fuera)
                <div><strong>Arribo:</strong> {{ \App\Models\Acceso::MODOS[$a->modo_arribo] ?? $a->modo_arribo }}</div>
            @endif
            @if ($a->placas)
                <div><strong>Vehículo:</strong> <span class="placas-mini">{{ $a->placas }}</span></div>
            @endif
            @if ($a->conductor)
                <div><strong>Conductor:</strong> {{ $a->conductor }}</div>
            @endif
            @if ($a->zona)
                <div><strong>Enviado a:</strong> <span class="dato-destacado">{{ $a->zona->nombre }}</span>{{ $a->zona->esDescarga() ? ' (descarga)' : '' }}</div>
            @endif
            @if ($a->num_acompanantes > 0)
                <div><strong>Acompañantes:</strong> {{ $a->num_acompanantes }}</div>
            @endif
            @if ($a->acompanantes->isNotEmpty())
                <ul class="lista-acompanantes">
                    @foreach ($a->acompanantes as $ac)
                        <li>
                            <span class="acompanante-texto">
                                <i class="bi bi-person me-1" aria-hidden="true"></i>{{ $ac->nombreVisible() }}
                                @if ($ac->identificacion) · {{ \App\Models\Acceso::IDENTIFICACIONES[$ac->identificacion] ?? $ac->identificacion }}@endif
                                @if ($ac->gafete_texto) · <span class="texto-gafete">Gafete {{ $ac->gafete_texto }}</span>@endif
                                @if ($ac->estaFueraTemporal()) <span class="estado-acceso fuera mini">FUERA</span>@endif
                            </span>
                            @if ($modo === 'en_sitio' && $puede['editar'])
                                <span class="acompanante-acciones">
                                    @if ($ac->estaFueraTemporal())
                                        <form action="{{ route('accesos.acompanantes.regreso', $ac->id) }}" method="POST" class="m-0">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="btn-acomp regreso" title="Registrar regreso de {{ $ac->nombreVisible() }}"><i class="bi bi-arrow-return-left" aria-hidden="true"></i> Regresó</button>
                                        </form>
                                    @else
                                        @if (in_array($a->tipo, \App\Models\Acceso::CON_AUTORIZACION, true))
                                            <form action="{{ route('accesos.acompanantes.salida-temporal', $ac->id) }}" method="POST" class="m-0"
                                                  data-confirmar="¿{{ $ac->nombreVisible() }} sale un rato y regresa? Su gafete sigue reservado.">
                                                @csrf
                                                @method('PATCH')
                                                <button type="submit" class="btn-acomp temporal" title="Salida temporal de {{ $ac->nombreVisible() }}"><i class="bi bi-signpost-split" aria-hidden="true"></i> Temporal</button>
                                            </form>
                                        @endif
                                        <form action="{{ route('accesos.acompanantes.salida', $ac->id) }}" method="POST" class="m-0"
                                              data-confirmar="¿Confirmar salida de {{ $ac->nombreVisible() }}{{ $ac->gafete_texto ? ' y devolver su gafete '.$ac->gafete_texto : '' }}?">
                                            @csrf
                                            @method('PATCH')
                                            <button type="submit" class="btn-acomp salida" title="Dar salida solo a {{ $ac->nombreVisible() }}"><i class="bi bi-box-arrow-right" aria-hidden="true"></i> Salida</button>
                                        </form>
                                    @endif
                                </span>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
            @if ($fuera)
                <div class="aviso-fuera">
                    <i class="bi bi-signpost-split me-1" aria-hidden="true"></i><strong>Salió:</strong> @fecha($fuera->entrada_at, $formato($fuera->entrada_at)) (por {{ $fuera->registradoPor?->name ?? '—' }})
                    @if ($fuera->placas) · {{ $fuera->placas }}@endif
                    @if ($fuera->conductor) · {{ $fuera->conductor }}@endif
                </div>
            @endif
            <div class="texto-traza mt-1">
                <strong>{{ $modo === 'pendientes' ? 'Solicitado' : 'Hora Ingreso' }}:</strong> @fecha($a->entrada_at, $formato($a->entrada_at)) (por {{ $a->registradoPor?->name ?? '—' }})
                @if ($a->autorizado_at)
                    <br><strong>Autorizado:</strong> @fecha($a->autorizado_at, $formato($a->autorizado_at)) (por {{ $a->autorizadoPor?->name ?? '—' }})
                @endif
            </div>
        </div>
    @endif

    @include('rh.recepcion._ficha-acceso'){{-- Recepción: autorización del departamento y fotos (ADR-0007) --}}

    @if ($modo === 'pendientes' && $puede['aprobar'])
        <div class="ficha-acceso-acciones">
            <form action="{{ route('accesos.autorizar', $a->id) }}" method="POST" class="m-0 w-100"
                  data-confirmar="{{ in_array($a->autorizacion, ['esperando', 'espera'], true) ? ($a->motivo_visita === 'rh' ? '¿Recursos Humanos ya autorizó (por teléfono o en persona)? Al confirmar, la persona podrá ingresar.' : '¿El responsable del departamento ya autorizó (por teléfono o en persona)? Al confirmar, la persona podrá ingresar.') : '¿El Host confirmó la autorización de este acceso? Al confirmar, la persona podrá ingresar.' }}">
                @csrf
                @method('PATCH')
                <button type="submit" class="btn-accion-acceso autorizar"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>Confirmar Autorización</button>
            </form>
        </div>
    @elseif ($modo === 'pendientes' && ! in_array($a->autorizacion, ['esperando', 'espera'], true)){{-- Recepción: la visita espera al departamento (o a RR. HH.), no al host --}}
        <p class="aviso-sin-permiso"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Espera a que el Host autorice; tu supervisor confirma la autorización en el sistema.</p>
    @elseif ($modo === 'en_sitio' && $puede['editar'])
        <div class="ficha-acceso-acciones">
            @if ($fuera)
                <button type="button" class="btn-accion-acceso regreso" data-accion="acceso-dialogo" data-dialogo="dialogoRegresoAcceso" data-id="{{ $a->id }}"
                        data-url="{{ route('accesos.regreso', $a->id) }}" data-nombre="{{ $a->nombre }}"
                        data-titulo="{{ $a->tipo === 'huesped' ? 'Regreso de Tour' : 'Regreso' }}"><i class="bi bi-arrow-return-left me-1" aria-hidden="true"></i>Registrar Regreso</button>
            @else
                @if ($a->placas)
                    <button type="button" class="btn-accion-acceso zona" data-accion="acceso-dialogo" data-dialogo="dialogoZonaAcceso" data-id="{{ $a->id }}"
                            data-url="{{ route('accesos.zona', $a->id) }}" data-nombre="{{ $a->nombre }}" data-sede="{{ $a->sede_id }}"
                            data-zona="{{ $a->zona_estacionamiento_id }}"><i class="bi bi-p-square me-1" aria-hidden="true"></i>Cambiar Zona</button>
                @endif
                @if ($a->admiteSalidaTemporal())
                    <button type="button" class="btn-accion-acceso temporal" data-accion="acceso-dialogo" data-dialogo="dialogoSalidaTemporalAcceso" data-id="{{ $a->id }}"
                            data-url="{{ route('accesos.salida-temporal', $a->id) }}" data-nombre="{{ $a->nombre }}"
                            data-titulo="{{ $a->textoSalidaTemporal() }}"><i class="bi bi-signpost-split me-1" aria-hidden="true"></i>{{ $a->textoSalidaTemporal() }}</button>
                @endif
                <form action="{{ route('accesos.salida', $a->id) }}" method="POST" class="m-0 {{ $a->admiteSalidaTemporal() ? 'mitad' : 'w-100' }}" data-confirmar="{{ $confirmarSalida }}">
                    @csrf
                    @method('PATCH')
                    <button type="submit" class="btn-accion-acceso salida"><i class="bi bi-box-arrow-right me-1" aria-hidden="true"></i>{{ $a->tipo === 'huesped' ? 'Salida Final' : 'Registrar Salida' }}</button>
                </form>
            @endif
        </div>
    @endif
</article>
