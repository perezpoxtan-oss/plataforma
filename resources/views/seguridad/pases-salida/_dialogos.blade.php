{{--
    Diálogos de la ficha del pase: Aprobar, Rechazar, Omitir paso, paso de
    caseta (Registrar Salida / Confirmar Llegada / Autorizar Salida de
    Regreso / Registrar Regreso) y Cancelar. Los errores vuelven DENTRO de su
    diálogo (old('_dialogo')).
--}}
@php
    $dialogo = old('_dialogo');
    $erroresDentro = function () use ($errors) {
        if (! $errors->any()) {
            return '';
        }
        $html = '<div class="alert alert-danger small py-2" role="alert">';
        foreach ($errors->all() as $error) {
            $html .= '<div><i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>'.e($error).'</div>';
        }

        return $html.'</div>';
    };
@endphp

@if ($puede['aprobar'] && $actual)
    {{-- ===== Aprobar ===== --}}
    <dialog id="dialogoAprobarPase" class="dialogo dialogo-pase" aria-labelledby="titulo-aprobar" @if ($dialogo === 'aprobar') data-abrir-al-cargar @endif>
        <div class="dialogo-cabecera">
            <h2 id="titulo-aprobar"><i class="bi bi-pen-fill me-2 text-primary" aria-hidden="true"></i>Aprobar — {{ $actual->nombre }}</h2>
            <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div class="dialogo-cuerpo">
            <form action="{{ route('pases-salida.firmar', $pase->id) }}" method="POST" autocomplete="off" data-form-firma-propia>
                @csrf
                <input type="hidden" name="_dialogo" value="aprobar">
                <input type="hidden" name="paso" value="aprobacion">
                <input type="hidden" name="aprobacion_id" value="{{ $actual->id }}">
                @if ($dialogo === 'aprobar'){!! $erroresDentro() !!}@endif
                <p class="small">Firmas el paso <strong>{{ $actual->orden }} de {{ $totalRonda }}</strong> del pase {{ $pase->folio }}: {{ $pase->articulos->count() }} {{ $pase->articulos->count() === 1 ? 'artículo' : 'artículos' }} hacia {{ $pase->nombreDestino() }}.</p>
                @include('seguridad.pases-salida._firma-propia', ['id' => 'aprobar', 'etiqueta' => 'Tu firma ('.auth()->user()->name.')'])
                <label class="campo-etiqueta" for="aprobar_comentario">Comentario <span class="text-lowercase fw-normal">(opcional)</span></label>
                <textarea id="aprobar_comentario" name="comentario" class="campo" rows="2" maxlength="1000">{{ $dialogo === 'aprobar' ? old('comentario') : '' }}</textarea>
                <div class="dialogo-acciones">
                    <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                    <button type="submit" class="btn-azul"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Aprobar y firmar</button>
                </div>
            </form>
        </div>
    </dialog>

    {{-- ===== Rechazar ===== --}}
    <dialog id="dialogoRechazarPase" class="dialogo dialogo-pase" aria-labelledby="titulo-rechazar" @if ($dialogo === 'rechazar') data-abrir-al-cargar @endif>
        <div class="dialogo-cabecera">
            <h2 id="titulo-rechazar"><i class="bi bi-x-octagon me-2 text-danger" aria-hidden="true"></i>Rechazar {{ $pase->folio }}</h2>
            <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div class="dialogo-cuerpo">
            <form action="{{ route('pases-salida.rechazar', $pase->id) }}" method="POST" data-confirmar="¿Rechazar el pase {{ $pase->folio }}? Regresará al solicitante para que lo corrija.">
                @csrf
                <input type="hidden" name="aprobacion_id" value="{{ $actual->id }}">
                @if ($dialogo === 'rechazar'){!! $erroresDentro() !!}@endif
                <p class="small">El pase regresa al solicitante con tus motivos: podrá corregirlo y reenviarlo, o cancelarlo.</p>
                <label class="campo-etiqueta" for="motivo_rechazo">Motivo del rechazo <span class="text-danger" aria-hidden="true">*</span></label>
                <textarea id="motivo_rechazo" name="motivo_rechazo" class="campo" rows="3" maxlength="1000" placeholder="Ej: Falta la factura de venta autorizada." required>{{ $dialogo === 'rechazar' ? old('motivo_rechazo') : '' }}</textarea>
                <div class="dialogo-acciones">
                    <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                    <button type="submit" class="btn-confirmar-rechazo">Confirmar Rechazo</button>
                </div>
            </form>
        </div>
    </dialog>

    @if ($puede['omitir'])
        {{-- ===== Omitir paso opcional ===== --}}
        <dialog id="dialogoOmitirPase" class="dialogo dialogo-pase" aria-labelledby="titulo-omitir" @if ($dialogo === 'omitir') data-abrir-al-cargar @endif>
            <div class="dialogo-cabecera">
                <h2 id="titulo-omitir"><i class="bi bi-skip-forward me-2 text-secondary" aria-hidden="true"></i>Omitir «{{ $actual->nombre }}»</h2>
                <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </div>
            <div class="dialogo-cuerpo">
                <form action="{{ route('pases-salida.omitir', $pase->id) }}" method="POST">
                    @csrf
                    <input type="hidden" name="aprobacion_id" value="{{ $actual->id }}">
                    @if ($dialogo === 'omitir'){!! $erroresDentro() !!}@endif
                    <p class="small">Este paso es opcional. Si no aplica para este pase, se omite y sigue el siguiente.</p>
                    <label class="campo-etiqueta" for="omitir_comentario">¿Por qué se omite? <span class="text-danger" aria-hidden="true">*</span></label>
                    <textarea id="omitir_comentario" name="comentario" class="campo" rows="2" maxlength="1000" required>{{ $dialogo === 'omitir' ? old('comentario') : '' }}</textarea>
                    <div class="dialogo-acciones">
                        <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                        <button type="submit" class="btn-azul">Omitir paso</button>
                    </div>
                </form>
            </div>
        </dialog>
    @endif
@endif

@if ($puede['firmarPaso'])
    {{-- ===== Paso de caseta ===== --}}
    @php
        [$tituloPaso, $botonPaso, , $rolesPaso] = \App\Models\PaseSalida::PASOS_FISICOS[$paso];
        $esRegreso = $paso === 'regreso';
        $rolPersona = array_key_first($rolesPaso);
        $previosVerificados = $dialogo === 'paso' ? array_map('intval', (array) old('verificados', [])) : [];
        $ayudas = [
            'salida' => 'Revisa que salga exactamente lo autorizado: escanea cada equipo del padrón o márcalo a mano.',
            'recepcion' => 'Revisa que haya llegado todo. Si falta algo o llegó diferente, anótalo en el comentario.',
            'salida_regreso' => 'Revisa lo que sale de regreso hacia la sede de origen.',
            'regreso' => 'Anota cuántos regresan de cada artículo. Si faltan, se registra un regreso parcial y el pase sigue esperando lo demás.',
        ];
    @endphp
    <dialog id="dialogoPasoPase" class="dialogo ancho dialogo-pase" aria-labelledby="titulo-paso" @if ($dialogo === 'paso') data-abrir-al-cargar @endif>
        <div class="dialogo-cabecera">
            <h2 id="titulo-paso"><i class="bi bi-upc-scan me-2 text-primary" aria-hidden="true"></i>{{ $botonPaso }} — <span class="text-nowrap">{{ $pase->folio }}</span></h2>
            <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div class="dialogo-cuerpo">
            <form action="{{ route('pases-salida.firmar', $pase->id) }}" method="POST" autocomplete="off" data-form-paso-pase data-form-firma-propia data-paso="{{ $paso }}">
                @csrf
                <input type="hidden" name="_dialogo" value="paso">
                <input type="hidden" name="paso" value="{{ $paso }}">
                @if ($dialogo === 'paso'){!! $erroresDentro() !!}@endif

                <h3 class="paso-pase"><span class="numero-paso">1</span>{{ $esRegreso ? 'Lo que regresa' : 'Verifica los artículos' }}</h3>
                <p class="small text-muted">{{ $ayudas[$paso] }}</p>
                @if (! $esRegreso && $pase->articulos->contains(fn ($a) => $a->equipo_id))
                    <div class="lector-equipo-pase" data-lector-verificar>
                        @include('componentes.lector', ['id' => 'verificar_equipo', 'etiqueta' => 'Escanear equipo', 'tipos' => 'equipo', 'nombre' => '',
                            'ayuda' => 'Escanea el QR o la etiqueta del equipo: se marca solo en la lista.'])
                    </div>
                @endif
                <div class="verificacion-articulos" data-verificacion-articulos>
                    @foreach ($pase->articulos as $a)
                        @if ($esRegreso)
                            @continue($a->pendientes() === 0)
                            <div class="fila-verificacion">
                                <div class="min-w-0 flex-fill">
                                    <strong>{{ $a->equipo }}</strong> {{ trim(($a->marca ?? '').' '.($a->modelo ?? '')) }}
                                    @if ($a->serie)<span class="d-block small">Serie: {{ $a->serie }}</span>@endif
                                    <span class="d-block small text-muted">Faltan por regresar: {{ $a->pendientes() }} de {{ $a->cantidad }}</span>
                                </div>
                                <label class="cantidad-regreso">
                                    <span class="visually-hidden">Cuántos regresan de {{ $a->equipo }}</span>
                                    <input type="number" name="regresa[{{ $a->id }}]" class="campo m-0" min="0" max="{{ $a->pendientes() }}" inputmode="numeric"
                                           value="{{ $dialogo === 'paso' ? old('regresa.'.$a->id, $a->pendientes()) : $a->pendientes() }}" data-cantidad-regreso data-pendientes="{{ $a->pendientes() }}">
                                    <span class="small">de {{ $a->pendientes() }}</span>
                                </label>
                            </div>
                        @else
                            <label class="fila-verificacion casilla" data-articulo-verificar data-equipo-id="{{ $a->equipo_id }}">
                                <input type="checkbox" name="verificados[]" value="{{ $a->id }}" @checked(in_array($a->id, $previosVerificados, true)) data-verificado>
                                <span class="min-w-0 flex-fill">
                                    <strong>{{ $a->cantidad }}x {{ $a->equipo }}</strong> {{ trim(($a->marca ?? '').' '.($a->modelo ?? '')) }}
                                    @if ($a->serie)<span class="d-block small">Serie: {{ $a->serie }}</span>@endif
                                </span>
                                <span class="marca-escaneado" data-marca-escaneado hidden><i class="bi bi-upc-scan" aria-hidden="true"></i> escaneado</span>
                                @if ($a->equipo_id)<input type="checkbox" name="escaneados[]" value="{{ $a->id }}" hidden data-escaneado>@endif
                            </label>
                        @endif
                    @endforeach
                </div>
                @unless ($esRegreso)
                    <p class="conteo-verificados" data-conteo-verificados aria-live="polite"></p>
                @endunless
                @if ($esRegreso)
                    <label class="opcion-guardar-firma" data-cerrar-faltantes hidden>
                        <input type="checkbox" name="cerrar_con_faltantes" value="1" @checked(old('cerrar_con_faltantes'))>
                        <span>Cerrar el pase aunque falten artículos <small class="d-block text-muted">Úsalo solo si lo que falta ya no va a regresar (explícalo en el comentario).</small></span>
                    </label>
                @endif

                <h3 class="paso-pase"><span class="numero-paso">2</span>{{ $rolesPaso[$rolPersona] }}</h3>
                <label class="campo-etiqueta" for="persona_nombre">Nombre completo <span class="text-danger" aria-hidden="true">*</span></label>
                <input type="text" id="persona_nombre" name="persona_nombre" class="campo text-uppercase" maxlength="150" required autocapitalize="characters"
                       value="{{ $dialogo === 'paso' ? old('persona_nombre') : $personaSugerida }}">
                @include('componentes.firma', ['id' => 'firma_persona_pase', 'nombre' => 'firma_persona', 'etiqueta' => 'Firma de '.mb_strtolower($rolesPaso[$rolPersona]), 'requerido' => true])

                <h3 class="paso-pase"><span class="numero-paso">3</span>Seguridad</h3>
                @include('seguridad.pases-salida._firma-propia', ['id' => 'paso', 'etiqueta' => 'Tu firma ('.auth()->user()->name.')'])

                <label class="campo-etiqueta mt-2" for="paso_comentario">Comentario <span class="text-lowercase fw-normal">{{ $esRegreso ? '(obligatorio si faltan artículos)' : '(obligatorio si algo no coincide)' }}</span></label>
                <textarea id="paso_comentario" name="comentario" class="campo" rows="2" maxlength="1000">{{ $dialogo === 'paso' ? old('comentario') : '' }}</textarea>

                <div class="dialogo-acciones">
                    <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                    <button type="submit" class="btn-azul"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>{{ $botonPaso }}</button>
                </div>
            </form>
        </div>
    </dialog>
@endif

@if ($puede['cancelar'])
    {{-- ===== Cancelar ===== --}}
    <dialog id="dialogoCancelarPase" class="dialogo dialogo-pase" aria-labelledby="titulo-cancelar" @if ($dialogo === 'cancelar') data-abrir-al-cargar @endif>
        <div class="dialogo-cabecera">
            <h2 id="titulo-cancelar"><i class="bi bi-slash-circle me-2 text-secondary" aria-hidden="true"></i>Cancelar {{ $pase->folio }}</h2>
            <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div class="dialogo-cuerpo">
            <form action="{{ route('pases-salida.cancelar', $pase->id) }}" method="POST" data-confirmar="¿Cancelar el pase {{ $pase->folio }}? Ya no podrá aprobarse ni salir.">
                @csrf
                @if ($dialogo === 'cancelar'){!! $erroresDentro() !!}@endif
                <p class="small">El pase se cancela y ya no sigue el circuito. Queda en la bitácora.</p>
                <label class="campo-etiqueta" for="motivo_cancelacion">Motivo <span class="text-lowercase fw-normal">(opcional)</span></label>
                <textarea id="motivo_cancelacion" name="motivo_cancelacion" class="campo" rows="2" maxlength="1000">{{ $dialogo === 'cancelar' ? old('motivo_cancelacion') : '' }}</textarea>
                <div class="dialogo-acciones">
                    <button type="button" class="btn-cancelar" data-cerrar-dialogo>No, volver</button>
                    <button type="submit" class="btn-confirmar-rechazo">Cancelar pase</button>
                </div>
            </form>
        </div>
    </dialog>
@endif
