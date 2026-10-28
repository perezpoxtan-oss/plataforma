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
    // C-19 (DD-18): lo que trajo; con lista (o un pase de su empresa) la salida usa el diálogo para compararla
    $traeArticulos = $a->relationLoaded('articulos') ? $a->articulos : collect();
    $conComparacion = $traeArticulos->isNotEmpty() || ($modo === 'en_sitio' && app(\App\Services\Accesos\ArticulosDelProveedor::class)->paseAbierto($a) !== null);
    // Vocabulario corto de estados (aprobado): cuatro pastillas y el detalle en un renglón chico debajo
    $espera = ($esperas ?? [])[$a->id] ?? null;
    $conRh = $a->motivo_visita === 'rh';
    $quienNego = $conRh ? 'Recursos Humanos' : ($a->departamento?->nombre ?? 'el departamento');
    [$pastilla, $clasePastilla, $iconoPastilla, $detalle] = match (true) {
        $modo === 'pendientes' => ['PENDIENTE', 'pendiente', 'bi-hourglass-split', match (true) {
            $a->autorizacion === 'espera' && $conRh => 'RR. HH. pide que espere',
            $a->autorizacion === 'espera' => 'Le pidieron que espere',
            $espera !== null => $espera['texto'],
            in_array($a->tipo, \App\Models\Acceso::CON_AUTORIZACION, true) => 'Confirma con quien lo citó: '.($host ?: ($a->departamento?->nombre ?? '—')),
            default => 'Espera autorización',
        }],
        $modo === 'en_sitio' && $fuera => ['FUERA', 'fuera', 'bi-signpost-split', $a->tipo === 'huesped' ? 'Fuera en tour' : 'Fuera temporal'],
        $modo === 'en_sitio' => ['EN SITIO', 'en-sitio', 'bi-record-circle', match (true) {
            $a->autorizacion === \App\Models\Acceso::POR_EXCEPCION => 'Autorizado por excepción ('.($a->motivo_excepcion ?: 'sin motivo').', '.($a->autorizadoPor?->name ?? '—').')',
            $a->autorizacion === \App\Models\Acceso::POR_LLAMADA => 'Autorizó por teléfono (llamó '.($a->autorizadoPor?->name ?? '—').')', // C-09
            $a->autorizacion === 'autorizada' && $conRh => 'RR. HH. dijo que pase',
            $a->autorizacion === 'autorizada' && $a->departamento !== null => 'Autorizado por '.$a->departamento->nombre,
            default => null,
        }],
        default => ['FINALIZADO', 'finalizado', 'bi-check2-circle', match (true) {
            $a->movimiento === 'regreso' => $a->tipo === 'huesped' ? 'Regreso de tour' : 'Regreso',
            $a->movimiento === 'salida_temporal' => $a->tipo === 'huesped' ? 'Salida a tour' : 'Salida temporal',
            $a->autorizacion === 'rechazada' => 'No autorizado por '.$quienNego.': no ingresó',
            $a->autorizacion === \App\Models\Acceso::LLAMADA_NO => 'Se llamó y no autorizó: no ingresó ('.($a->salidaPor?->name ?? '—').')', // C-09
            $a->autorizacion === \App\Models\Acceso::POR_LLAMADA => 'Autorizó por teléfono (llamó '.($a->autorizadoPor?->name ?? '—').')',
            $a->autorizacion === \App\Models\Acceso::POR_EXCEPCION => 'Autorizado por excepción ('.($a->motivo_excepcion ?: 'sin motivo').', '.($a->autorizadoPor?->name ?? '—').')',
            default => null,
        }],
    };
@endphp
<article class="ficha-acceso es-{{ $modo }} {{ $fuera ? 'tour-fuera' : '' }} {{ $a->movimiento !== 'entrada' ? 'es-movimiento' : '' }}" id="acceso-{{ $a->id }}"
         data-acceso-ficha data-tipo="{{ $a->tipo }}" data-sede="{{ $a->sede_id }}" data-texto="{{ \App\Services\Accesos\ConsultaAccesos::textoBusqueda($a) }}"
         @if ($modo === 'pendientes' && $espera !== null) data-espera-desde="{{ $espera['desde'] }}" data-espera-ambar="{{ ($umbrales ?? [10, 20])[0] }}" data-espera-roja="{{ ($umbrales ?? [10, 20])[1] }}" @endif>
    <div class="ficha-acceso-cabeza">
        {{-- Giros: un tipo que el giro ya no ofrece (registro de antes) conserva su nombre --}}
        <span class="badge-acceso tipo-{{ $a->tipo }}">@if (! $a->terminos()->ofreceTipo($a->tipo))<!--terminos:no-->{{ mb_strtoupper($a->etiquetaTipo()) }}<!--terminos:si-->@else{{ mb_strtoupper($a->etiquetaTipo()) }}@endif</span>
        <span class="estado-acceso {{ $clasePastilla }}" data-estado-pastilla>{{ $pastilla }} <i class="bi {{ $iconoPastilla }} ms-1" aria-hidden="true"></i></span>
    </div>
    @if ($detalle !== null)
        <p class="ficha-acceso-detalle {{ $modo === 'pendientes' && $espera !== null && $a->autorizacion !== 'espera' ? 'espera-'.$espera['nivel'] : '' }} {{ $a->autorizacion === 'rechazada' ? 'negado' : '' }}" data-estado-detalle>
            {{ $detalle }}@if ($modo === 'pendientes' && $espera !== null) · <span data-espera-minutos>{{ $espera['minutos'] }}</span> min @endif
            @if ($modo === 'historial' && $a->movimiento === 'entrada') <span class="gafete-mini">· Gafete: {{ $a->gafeteVisible() }}</span> @endif
        </p>
    @elseif ($modo === 'historial')
        <p class="ficha-acceso-detalle"><span class="gafete-mini">Gafete: {{ $a->gafeteVisible() }}</span></p>
    @endif

    {{-- C-30: la habitación o vivienda que no está en el catálogo se marca «sin catálogo» --}}
    <h3 class="ficha-acceso-nombre">{{ $a->nombre }}@if ($a->habitacion) <span class="badge-hab" @if (! $a->espacio_id) title="Sin catálogo: no está en Zonas y edificios" @endif>{{ $a->terminos()->abreviaturaEspacio($a->tipo) }} {{ $a->habitacion }}@if (! $a->espacio_id) <i class="bi bi-question-circle" aria-hidden="true"></i>@endif</span>@endif</h3>
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
    @if ($modo === 'en_sitio' && ! $fuera && ($rebaso = \App\Services\Relojes\Relojes\Permanencia::tarjeta($a, $ajustesCaseta ?? \App\Support\AjustesCaseta::de((int) $a->empresa_id))))
        {{-- C-25: reloj de permanencia (visita 4 h, proveedor 8 h, daypass hasta el cierre) --}}
        <div class="aviso-padron-acceso espera-{{ $rebaso['nivel'] }}" data-rebaso><i class="bi bi-clock-history me-1" aria-hidden="true"></i>{{ $rebaso['texto'] }}</div>
    @endif
    @if ($a->aviso_padron && $modo !== 'historial')
        {{-- C-13: empresa nueva, parecida a una restringida o que no opera en esta sede --}}
        <div class="aviso-padron-acceso"><i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>{{ $a->aviso_padron }}</div>
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
                {{-- C-15: lo que confirmó la caseta al dar salida --}}
                @if ($a->identificacion_devuelta === false)
                    <div class="texto-devuelta no-devuelta"><i class="bi bi-person-vcard me-1" aria-hidden="true"></i>ID quedó en caseta (no se le devolvió) · {{ $a->salidaPor?->name ?? '—' }}</div>
                @else
                    <div class="texto-devuelta"><i class="bi bi-check2-all me-1" aria-hidden="true"></i>ID Devuelta por: {{ $a->salidaPor?->name ?? '—' }}</div>
                @endif
                @if ($a->gafete_no_regreso || $a->acompanantes->contains('gafete_no_regreso', true))
                    <div class="texto-devuelta no-devuelta"><i class="bi bi-exclamation-octagon me-1" aria-hidden="true"></i>No devolvió: {{ collect([$a->gafete_no_regreso ? $a->gafete_texto : null])->merge($a->acompanantes->where('gafete_no_regreso', true)->pluck('gafete_texto'))->filter()->join(', ') }}</div>
                @endif
            @endif
        </div>
    @else
        <div class="ficha-acceso-datos">
            @if ($a->persona_visita)
                <div><strong>{{ $a->motivo_visita === 'general' ? 'Viene a:' : (in_array($a->tipo, \App\Models\Acceso::CON_AUTORIZACION, true) ? 'Viene con:' : 'Visitando a:') }}</strong> <span class="dato-destacado">{{ $a->persona_visita }}</span>@if ($a->motivo_visita === 'huesped' && $a->habitacion) · Habitación {{ $a->habitacion }}@endif</div>
            @endif
            @if ($a->motivo_visita)
                <div><strong>Motivo:</strong> {{ $a->etiquetaMotivo() }}</div>
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
            @if (in_array($a->tipo, \App\Models\Acceso::CON_AUTORIZACION, true) && ! $a->persona_visita)
                {{-- C-09: «a quién viene» (antes «Host (citó)») --}}
                <div><strong>Viene con:</strong> <span class="dato-destacado">{{ $host ?: ($a->departamento?->nombre ?? '—') }}</span></div>
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

    @if ($modo === 'pendientes' && $puede['aprobar'] && $a->esperaAlDepartamento())
        {{-- Proveedor/contratista que espera al departamento: sin «Confirmar Autorización»; si no puede esperar, por excepción y con motivo --}}
        @php $motivosExcepcion = app(\App\Services\Autorizaciones\Excepciones::class)->motivosPara($a); @endphp
        <div class="ficha-acceso-acciones">
        <details class="excepcion-acceso w-100">
            <summary class="btn-accion-acceso excepcion"><i class="bi bi-shield-exclamation me-1" aria-hidden="true"></i>Autorizar por excepción</summary>
            <form action="{{ route('accesos.excepcion', $a->id) }}" method="POST" class="m-0 mt-2"
                  data-confirmar="¿Dejar pasar a {{ $a->nombre }} sin la autorización del departamento? Quedará anotado con tu nombre y se avisará al responsable.">
                @csrf
                @method('PATCH')
                <label class="campo-etiqueta" for="motivo_excepcion_{{ $a->id }}">Motivo *</label>
                <select id="motivo_excepcion_{{ $a->id }}" name="motivo_excepcion" class="campo" required>
                    <option value="">-- Seleccionar --</option>
                    @foreach ($motivosExcepcion as $claveMotivo => $textoMotivo)
                        <option value="{{ $claveMotivo }}">{{ $textoMotivo }}</option>
                    @endforeach
                </select>
                <label class="campo-etiqueta" for="detalle_excepcion_{{ $a->id }}">Detalle <span class="text-lowercase fw-normal">(obligatorio con «Otro»)</span></label>
                <input type="text" id="detalle_excepcion_{{ $a->id }}" name="detalle_excepcion" class="campo" maxlength="250" placeholder="Ej: lo autorizó Juan Pérez a las 10:15">
                <button type="submit" class="btn-accion-acceso excepcion mt-2"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>Autorizar por excepción</button>
            </form>
        </details>
        </div>
    @elseif ($modo === 'pendientes' && $a->esperaAlDepartamento())
        <p class="aviso-sin-permiso"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Espera la autorización del departamento; si no puede esperar, tu supervisor lo autoriza por excepción.</p>
    @elseif ($modo === 'pendientes' && $puede['aprobar'])
        <div class="ficha-acceso-acciones">
            <form action="{{ route('accesos.autorizar', $a->id) }}" method="POST" class="m-0 w-100"
                  data-confirmar="{{ $a->autorizacion === \App\Models\Acceso::SIN_RESPONSABLE ? 'Su departamento no tiene quien autorice en esta sede. ¿Ya lo confirmaste con quien lo recibe? Al confirmar, la persona podrá ingresar.' : (in_array($a->autorizacion, ['esperando', 'espera'], true) ? ($a->motivo_visita === 'rh' ? '¿Recursos Humanos ya autorizó (por teléfono o en persona)? Al confirmar, la persona podrá ingresar.' : '¿El responsable del departamento ya autorizó (por teléfono o en persona)? Al confirmar, la persona podrá ingresar.') : '¿El Host confirmó la autorización de este acceso? Al confirmar, la persona podrá ingresar.') }}">
                @csrf
                @method('PATCH')
                <button type="submit" class="btn-accion-acceso autorizar"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>Confirmar Autorización</button>
            </form>
        </div>
    @elseif ($modo === 'pendientes' && $a->autorizacion === \App\Models\Acceso::SIN_RESPONSABLE){{-- C-08 / DD-11 --}}
        <p class="aviso-sin-permiso"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Su departamento no tiene quien autorice en esta sede: espera a tu supervisor o al jefe de seguridad (ya se les avisó).</p>
    @elseif ($modo === 'pendientes' && ! in_array($a->autorizacion, ['esperando', 'espera'], true)){{-- Recepción: la visita espera al departamento (o a RR. HH.), no al host --}}
        <p class="aviso-sin-permiso"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Espera a que el Host autorice; tu supervisor confirma la autorización en el sistema.</p>
    @endif
    @if ($modo === 'pendientes' && ! $puede['aprobar'] && ($puede['crear'] ?? false))
        @include('seguridad.accesos._noche')
    @endif
    {{-- C-19 (DD-18): lo que trajo y, al salir, cómo salió --}}
    @if ($traeArticulos->isNotEmpty())
        <div class="trae-equipo-ficha mt-1"><i class="bi bi-tools me-1" aria-hidden="true"></i>Trae: {{ $traeArticulos->map->texto()->join(', ') }}
            @if ($a->articulos_salida === 'mismo') · <span class="text-success">salió con lo mismo</span>@elseif ($a->articulos_salida === 'distinto') · <strong class="text-danger">salió con algo distinto{{ $a->articulos_nota ? ': '.$a->articulos_nota : '' }}</strong>@endif
        </div>
    @endif
    @if ($modo !== 'pendientes' && $a->articulos_por_revisar && ! $a->articulos_revisado_en)
        <div class="aviso-padron-acceso"><i class="bi bi-tools me-1" aria-hidden="true"></i>No salió con lo mismo que trajo: por revisar.</div>
        @if ($puede['aprobar'])
            <form action="{{ route('accesos.revisar-articulos', $a->id) }}" method="POST" class="m-0 mt-1">
                @csrf
                @method('PATCH')
                <button type="submit" class="btn-accion-acceso autorizar"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Atender</button>
            </form>
        @endif
    @endif
    {{-- C-25: la excepción que aprobó el agente espera la revisión del supervisor --}}
    @if ($modo !== 'pendientes' && $a->excepcion_por_revisar && ! $a->excepcion_revisada_en)
        <div class="aviso-padron-acceso"><i class="bi bi-shield-exclamation me-1" aria-hidden="true"></i>Aprobado por el agente sin supervisor: por revisar.</div>
        @if ($puede['aprobar'])
            <form action="{{ route('accesos.revisar-excepcion', $a->id) }}" method="POST" class="m-0 mt-1">
                @csrf
                @method('PATCH')
                <button type="submit" class="btn-accion-acceso autorizar"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Atender</button>
            </form>
        @endif
    @endif
    @if ($modo === 'en_sitio' && $puede['editar'])
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
                @if ($pedir !== '' || $conComparacion)
                    {{-- C-15: con gafete o identificación en custodia, el mismo diálogo de «Dar salida» con sus casillas ya marcadas --}}
                    <button type="button" class="btn-accion-acceso salida {{ $a->admiteSalidaTemporal() ? 'mitad' : 'w-100' }}" title="{{ $confirmarSalida }}"
                            data-dar-salida="{{ json_encode(app(\App\Services\Accesos\ConsultaAccesos::class)->resumen($a)) }}"><i class="bi bi-box-arrow-right me-1" aria-hidden="true"></i>{{ $a->tipo === 'huesped' ? 'Salida Final' : 'Registrar Salida' }}</button>
                @else
                    <form action="{{ route('accesos.salida', $a->id) }}" method="POST" class="m-0 {{ $a->admiteSalidaTemporal() ? 'mitad' : 'w-100' }}" data-confirmar="{{ $confirmarSalida }}">
                        @csrf
                        @method('PATCH')
                        <button type="submit" class="btn-accion-acceso salida"><i class="bi bi-box-arrow-right me-1" aria-hidden="true"></i>{{ $a->tipo === 'huesped' ? 'Salida Final' : 'Registrar Salida' }}</button>
                    </form>
                @endif
            @endif
        </div>
    @endif
</article>
