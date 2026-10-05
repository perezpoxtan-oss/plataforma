{{--
    "Expediente de Novedad" (SEGCAT: dialogSeguimiento). Preguntas Base,
    formato de la categoría (todos se pintan del lado del servidor y solo se
    muestra el de la categoría elegida), Minuto a Minuto, estatus y
    resolución. Un caso Resuelto se ve en solo lectura hasta "Reabrir Caso".
--}}
@php
    $n = $expediente['novedad'];
    $v = $expediente['v'];
    $editable = $expediente['editable'];
    $espacios = $catalogos['espacios']->where('sede_id', $n->sede_id);
    $edificios = $espacios->where('nivel', \App\Models\Espacio::EDIFICIO);
    $pisos = $espacios->where('nivel', \App\Models\Espacio::AREA);
    $habitaciones = $espacios->where('nivel', \App\Models\Espacio::AREA_ESPECIFICA);
    $usuarios = $catalogos['usuarios']->filter(fn ($u) => $u['sedes'] === 'todas' || in_array((string) $n->sede_id, explode(' ', $u['sedes']), true));
    $categorias = $expediente['categorias'];
    if (! in_array($n->categoria, $categorias, true)) {
        $categorias[] = $n->categoria;
    }
    $opcionesCategoria = \App\Models\Novedad::OPCIONES_CATEGORIA + ['recorrido_pc' => 'Recorrido Protección Civil (histórico — ya no se crean aquí)'];
    $categoriaActual = $v['categoria'];
@endphp
<dialog id="dialogoExpediente" class="dialogo ancho dialogo-expediente" aria-labelledby="titulo-expediente" data-abrir-al-cargar data-conservar-al-cerrar
        data-expediente="{{ $n->id }}" data-sede="{{ $n->sede_id }}">
    <div class="dialogo-cabecera">
        <h2 id="titulo-expediente">
            <i class="bi bi-folder-check me-2 text-primary" aria-hidden="true"></i>Expediente de Novedad
            <span class="folio-novedad ms-1">{{ $n->folio() }}</span>
        </h2>
        <div class="d-flex align-items-center gap-2">
            @if ($expediente['puedeImprimir'])
                <a href="{{ route('novedades.imprimir', $n->id) }}" target="_blank" rel="noopener" class="btn-icono" title="Imprimir expediente" aria-label="Imprimir expediente"><i class="bi bi-printer" aria-hidden="true"></i></a>
            @endif
            <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
    </div>
    <div class="dialogo-cuerpo">
        @if ($n->resuelto())
            <div class="aviso-solo-lectura"><i class="bi bi-lock-fill me-1" aria-hidden="true"></i>Este caso está <strong>Resuelto</strong>. Se muestra en solo lectura. @if ($expediente['puedeReabrir']) Para corregirlo usa <strong>Reabrir Caso para Editar</strong>. @endif</div>
        @elseif (! $editable)
            <div class="aviso-solo-lectura"><i class="bi bi-eye-fill me-1" aria-hidden="true"></i>Solo puedes consultar este expediente.</div>
        @endif

        {{-- Reporte inicial (lo que se capturó al despachar; no cambia) --}}
        <div class="reporte-inicial">
            <div><span class="dato-etiqueta">Sede</span>{{ $n->sede?->nombre }}</div>
            <div><span class="dato-etiqueta">Reportado</span>@fecha($n->created_at) · {{ $n->creador?->name ?? '—' }}</div>
            <div><span class="dato-etiqueta">¿Quién reporta?</span>{{ $n->reportado_por }}</div>
            <div><span class="dato-etiqueta">Ubicación específica</span>{{ $n->ubicacion }}</div>
            <div class="completo"><span class="dato-etiqueta">¿Qué sucedió?</span>{!! nl2br(e($n->descripcion)) !!}</div>
            @if ($n->origen)
                <div class="completo"><span class="dato-etiqueta">Generado desde</span><a href="{{ route('novedades.index', ['abrir' => $n->origen->id]) }}">Ticket {{ $n->origen->folio() }} · {{ $n->origen->etiquetaCategoria() }}</a></div>
            @endif
        </div>

        <form action="{{ route('novedades.update', $n->id) }}" method="POST" autocomplete="off" data-form-novedad="expediente" data-sede="{{ $n->sede_id }}">
            @csrf
            @method('PUT')
            <input type="hidden" name="_dialogo" value="expediente-{{ $n->id }}">
            <fieldset @disabled(! $editable) class="expediente-campos">
                <div class="preguntas-base">
                    <h3><i class="bi bi-clipboard-data me-2 text-primary" aria-hidden="true"></i>Preguntas Base — se confirman/completan al atender</h3>
                    <div class="row">
                        <div class="col-md-6">
                            <label class="campo-etiqueta" for="exp_categoria">Categoría <span class="text-lowercase fw-normal">(confirmar o corregir)</span></label>
                            <select id="exp_categoria" name="categoria" class="campo campo-categoria" data-nov-categoria>
                                @foreach ($categorias as $clave)
                                    <option value="{{ $clave }}" @selected($categoriaActual === $clave)>{{ $opcionesCategoria[$clave] ?? $clave }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="campo-etiqueta" for="exp_asignado">¿A quién se canaliza?</label>
                            <select id="exp_asignado" name="asignado_a" class="campo">
                                <option value="">-- Sin asignar --</option>
                                @if ($n->asignado && ! $usuarios->contains('id', $n->asignado_a))
                                    <option value="{{ $n->asignado_a }}" selected>{{ $n->asignado->name }}</option>
                                @endif
                                @foreach ($usuarios as $u)
                                    <option value="{{ $u['id'] }}" @selected((string) $v['asignado_a'] === (string) $u['id'])>{{ $u['nombre'] }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <span class="campo-etiqueta">Área General <span class="text-lowercase fw-normal">(edificio y piso)</span></span>
                            <div class="campo-grupo">
                                <select name="area_edificio_id" class="campo" aria-label="Edificio" data-nov-edificio data-nov-area-parte>
                                    <option value="">-- Edificio --</option>
                                    @foreach ($edificios as $e)
                                        <option value="{{ $e->id }}" @selected((string) $v['area_edificio_id'] === (string) $e->id)>{{ $e->nombre }}{{ $e->activo ? '' : ' (desactivado)' }}</option>
                                    @endforeach
                                </select>
                                <select name="area_piso_id" class="campo" aria-label="Piso" data-nov-depende="edificio" data-nov-area-parte>
                                    <option value="">-- Piso --</option>
                                    @foreach ($pisos as $p)
                                        <option value="{{ $p->id }}" data-de="{{ $p->padre_id }}" @selected((string) $v['area_piso_id'] === (string) $p->id)>{{ $p->nombre }}{{ $p->activo ? '' : ' (desactivado)' }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <label class="campo-etiqueta" for="exp_habitacion">Habitación específica <span class="text-lowercase fw-normal">(opcional — solo si el hecho ocurrió en una habitación concreta)</span></label>
                            <select id="exp_habitacion" name="area_especifica_id" class="campo" data-nov-habitaciones>
                                <option value="">-- No aplica / sin especificar --</option>
                                @foreach ($habitaciones as $h)
                                    <option value="{{ $h->id }}" data-ruta="{{ $h->ruta }}" @selected((string) $v['area_especifica_id'] === (string) $h->id)>{{ $h->nombre }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="col-md-6">
                            <label class="campo-etiqueta" for="exp_involucrados">¿Involucra a más personas o áreas?</label>
                            <input type="text" id="exp_involucrados" name="involucrados" class="campo text-uppercase" maxlength="255" list="novColaboradores" placeholder="Nombres o áreas, si aplica" value="{{ $v['involucrados'] }}">
                            <label class="campo-etiqueta" for="exp_cuando">¿Cuándo sucedió? <span class="text-lowercase fw-normal">(fecha y hora del hecho)</span></label>
                            <input type="datetime-local" id="exp_cuando" name="ocurrio_en" class="campo" value="{{ $v['ocurrio_en'] }}">
                        </div>
                    </div>
                    <label class="campo-etiqueta" for="exp_como">¿Cómo sucedió?</label>
                    <textarea id="exp_como" name="como_sucedio" class="campo mb-0" rows="2" maxlength="5000" placeholder="Causa o mecánica del hecho, si ya se conoce...">{{ $v['como_sucedio'] }}</textarea>
                </div>

                {{-- Formato de la categoría --}}
                <div class="accordion expediente-formatos" id="accordionExpediente">
                    <fieldset class="formato-novedad" data-formato="sin_clasificar" @if ($categoriaActual !== 'sin_clasificar') hidden disabled @endif>
                        <p class="text-muted text-center p-4 m-0"><i class="bi bi-info-circle me-2" aria-hidden="true"></i>Elige o confirma una categoría arriba para desplegar su formato correspondiente.</p>
                    </fieldset>
                    @foreach (['incidente_general' => 'incidente-general', 'accidente' => 'accidente', 'habitacion' => 'valores-vista', 'proteccion_civil' => 'proteccion-civil',
                        'recorrido_pc' => 'recorrido-pc', 'lost_found' => 'lost-found', 'robo' => 'robo'] as $clave => $vista)
                        @continue (! in_array($clave, $categorias, true))
                        <fieldset class="formato-novedad" data-formato="{{ $clave }}" @if ($categoriaActual !== $clave) hidden disabled @endif>
                            @include('seguridad.novedades.formatos.'.$vista)
                        </fieldset>
                    @endforeach
                </div>

                {{-- Minuto a Minuto --}}
                <div class="minuto-a-minuto">
                    <span class="campo-etiqueta"><i class="bi bi-clock-history me-1" aria-hidden="true"></i>Minuto a Minuto / Log de Seguimiento</span>
                    <div class="log-notas" tabindex="0" aria-label="Notas de seguimiento">
                        @forelse ($n->notas as $nota)
                            <div class="nota-log {{ $nota->tipo }}">[@fecha($nota->created_at)] {{ $nota->autor_nombre }}: {{ $nota->texto }}</div>
                        @empty
                            <div class="nota-log vacia">No hay registros previos.</div>
                        @endforelse
                    </div>
                    @if ($editable)
                        <label class="visually-hidden" for="exp_nota">Nueva actualización</label>
                        <input type="text" id="exp_nota" name="nueva_nota" class="campo campo-nota" maxlength="2000" placeholder="Escriba aquí la nueva actualización y presione Guardar Expediente..." value="{{ $v['nueva_nota'] }}">
                    @endif
                </div>

                <label class="campo-etiqueta" for="exp_estatus">Estatus Final del Expediente</label>
                <select id="exp_estatus" name="estatus" class="campo campo-estatus" data-nov-estatus>
                    @foreach (\App\Models\Novedad::ESTATUS as $clave => [$texto])
                        <option value="{{ $clave }}" @selected($v['estatus'] === $clave)>{{ $texto }}</option>
                    @endforeach
                </select>
                <label class="campo-etiqueta" for="exp_resolucion">Estatus Final / Resolución <span class="text-lowercase fw-normal">(cómo se concluyó el caso — obligatorio para marcar Resuelto)</span></label>
                <textarea id="exp_resolucion" name="resolucion" class="campo" rows="2" maxlength="5000" data-requerido-si='{"estatus":["resuelto"]}'
                          placeholder="Ej. Se entregó el artículo al huésped, se corrigió la falla eléctrica, se canalizó con gerencia...">{{ $v['resolucion'] }}</textarea>
                @if ($n->resuelto() && $n->cerrado_en)
                    <p class="texto-traza"><i class="bi bi-check2-circle me-1" aria-hidden="true"></i>Cerrado @fecha($n->cerrado_en) por {{ $n->cerrador?->name ?? '—' }}</p>
                @endif
            </fieldset>

            <div class="dialogo-acciones">
                <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cerrar</button>
                @if ($editable)
                    <button type="submit" class="btn-guardar-expediente"><i class="bi bi-save-fill me-1" aria-hidden="true"></i>Guardar Expediente</button>
                @elseif ($expediente['puedeReabrir'])
                    <button type="button" class="btn-reabrir" data-abrir-dialogo="dialogoReabrir"><i class="bi bi-unlock-fill me-1" aria-hidden="true"></i>Reabrir Caso para Editar</button>
                @endif
            </div>
        </form>
    </div>
</dialog>

@if ($expediente['puedeReabrir'])
    {{-- Un caso ya Resuelto solo se reabre si se explica por qué (queda en el historial) --}}
    <dialog id="dialogoReabrir" class="dialogo" aria-labelledby="titulo-reabrir" @if (old('_dialogo') === 'reabrir-'.$n->id) data-abrir-al-cargar @endif>
        <div class="dialogo-cabecera">
            <h2 id="titulo-reabrir"><i class="bi bi-unlock-fill text-warning me-2" aria-hidden="true"></i>Justificar Reapertura</h2>
            <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
        <div class="dialogo-cuerpo">
            <form action="{{ route('novedades.reabrir', $n->id) }}" method="POST">
                @csrf
                <input type="hidden" name="_dialogo" value="reabrir-{{ $n->id }}">
                <p class="text-muted small">Este caso ya estaba marcado como Resuelto. Explica por qué necesita reabrirse — quedará en el historial del caso.</p>
                <label class="campo-etiqueta" for="reabrir_motivo">Motivo de la reapertura</label>
                <textarea id="reabrir_motivo" name="motivo" class="campo" rows="3" minlength="5" maxlength="1000" required placeholder="Ej. El huésped reportó que el problema volvió a presentarse...">{{ old('motivo') }}</textarea>
                <div class="dialogo-acciones">
                    <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                    <button type="submit" class="btn-reabrir"><i class="bi bi-unlock-fill me-1" aria-hidden="true"></i>Confirmar y Reabrir</button>
                </div>
            </form>
        </div>
    </dialog>
@endif
