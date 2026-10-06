{{--
    "Nuevo Procedimiento" (lista) o "Editar borrador" (ficha: $p y $trabajo).
    1. Datos generales · 2. A quién aplica · 3. Pasos · 4. Notas y adjuntos.
    Los errores vuelven DENTRO del diálogo (old('_dialogo')).
--}}
@php
    $editando = isset($trabajo) && $trabajo !== null;
    $modo = $editando ? 'editar' : 'nuevo';
    $conErrores = old('_dialogo') === $modo;
    $reabrir = $conErrores || (! $editando && request()->boolean('nuevo'));
    $previo = fn (string $campo, $porDefecto = null) => $conErrores ? old($campo, $porDefecto) : $porDefecto;
    $sedesUsuario = $formulario['sedes'];
    $base = $editando ? [
        'clave' => $p->clave, 'titulo' => $trabajo->titulo, 'categoria_id' => $trabajo->categoria_id, 'objetivo' => $trabajo->objetivo,
        'alcance' => $trabajo->alcance, 'responsables' => $trabajo->responsables, 'notas' => $trabajo->notas, 'resumen_cambios' => $trabajo->resumen_cambios,
        'aplica' => $trabajo->aplica_todas_sedes ? 'todas' : 'sedes',
        'sedes' => $trabajo->idsDe('sede'), 'departamentos' => $trabajo->idsDe('departamento'), 'puestos' => $trabajo->idsDe('puesto'),
    ] : [
        'clave' => $formulario['clave'] ?? '', 'categoria_id' => $formulario['categorias']->first()?->id,
        // Quien trabaja solo en algunas sedes: sus sedes ya marcadas
        'aplica' => $formulario['todasPermitido'] ? 'todas' : 'sedes', 'sedes' => $formulario['todasPermitido'] ? [] : $sedesUsuario->pluck('id')->all(),
        'departamentos' => [], 'puestos' => [],
    ];
    $valor = fn (string $campo) => $previo($campo, $base[$campo] ?? null);
    $marcados = fn (string $campo) => array_map('intval', (array) ($conErrores ? old($campo, []) : ($base[$campo] ?? [])));
    $pasosPrevios = $conErrores && is_array(old('pasos')) ? array_values(old('pasos'))
        : ($editando ? $trabajo->pasos->map(fn ($x) => ['texto' => $x->texto, 'responsable' => $x->responsable, 'critico' => $x->critico])->all() : [[], [], []]);
    $aplica = $valor('aplica') ?? 'todas';
    $idDialogo = $editando ? 'dialogoEditarProcedimiento' : 'dialogoNuevoProcedimiento';
    $seleccionDeptos = $marcados('departamentos');
    $seleccionPuestos = $marcados('puestos');
@endphp
<dialog id="{{ $idDialogo }}" class="dialogo extra-ancho dialogo-pase dialogo-procedimiento" aria-labelledby="titulo-{{ $idDialogo }}" @if ($reabrir) data-abrir-al-cargar @endif>
    <div class="dialogo-cabecera">
        <h2 id="titulo-{{ $idDialogo }}"><i class="bi {{ $editando ? 'bi-pencil-square' : 'bi-journal-plus' }} me-2 text-primary" aria-hidden="true"></i>{{ $editando ? 'Editar borrador — '.$p->clave.' · versión '.$trabajo->numero : 'Nuevo Procedimiento' }}</h2>
        <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </div>
    <div class="dialogo-cuerpo">
        <form action="{{ $editando ? route('procedimientos.update', $p->id) : route('procedimientos.store') }}" method="POST" enctype="multipart/form-data" autocomplete="off" data-form-procedimiento>
            @csrf
            @if ($editando) @method('PUT') @endif
            <input type="hidden" name="_dialogo" value="{{ $modo }}">
            @if ($editando && $trabajo->motivo_rechazo)
                <div class="alert alert-warning small py-2"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i><strong>Lo regresaron con este comentario:</strong> {{ $trabajo->motivo_rechazo }}</div>
            @endif
            @if ($conErrores && $errors->any())
                <div class="alert alert-danger small py-2" role="alert">
                    @foreach ($errors->all() as $error)<div><i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>{{ $error }}</div>@endforeach
                    @if (! empty(old('_dialogo')))<div class="mt-1"><i class="bi bi-paperclip me-1" aria-hidden="true"></i>Si habías elegido adjuntos, vuelve a elegirlos.</div>@endif
                </div>
            @endif

            {{-- ===== 1. Datos generales ===== --}}
            <h3 class="paso-pase"><span class="numero-paso">1</span><i class="bi bi-card-heading text-primary me-2" aria-hidden="true"></i>Datos generales</h3>
            <div class="row">
                <div class="col-md-4">
                    <label class="campo-etiqueta" for="{{ $modo }}_clave">Clave</label>
                    @if ($editando && $trabajo->numero > 1)
                        <input type="text" id="{{ $modo }}_clave" class="campo campo-solo-lectura" value="{{ $p->clave }}" readonly tabindex="-1">
                        <input type="hidden" name="clave" value="{{ $p->clave }}">
                    @else
                        <input type="text" id="{{ $modo }}_clave" name="clave" class="campo texto-clave" maxlength="30" required autocapitalize="characters"
                               placeholder="PRO-SEG-001" value="{{ $valor('clave') }}">
                    @endif
                </div>
                <div class="col-md-8">
                    <label class="campo-etiqueta" for="{{ $modo }}_categoria">Categoría</label>
                    <select id="{{ $modo }}_categoria" name="categoria_id" class="campo" required>
                        @foreach ($formulario['categorias'] as $c)
                            <option value="{{ $c->id }}" @selected((string) $valor('categoria_id') === (string) $c->id)>{{ $c->nombre }}</option>
                        @endforeach
                        @if ($editando && ! $formulario['categorias']->contains('id', $trabajo->categoria_id) && $trabajo->categoria)
                            <option value="{{ $trabajo->categoria_id }}" selected>{{ $trabajo->categoria->nombre }} (inactiva)</option>
                        @endif
                    </select>
                </div>
            </div>
            <label class="campo-etiqueta" for="{{ $modo }}_titulo">Título</label>
            <input type="text" id="{{ $modo }}_titulo" name="titulo" class="campo" maxlength="150" required placeholder="Ej: Robo en habitación" value="{{ $valor('titulo') }}">
            <label class="campo-etiqueta" for="{{ $modo }}_objetivo">Objetivo <span class="text-lowercase fw-normal">(para qué sirve)</span></label>
            <textarea id="{{ $modo }}_objetivo" name="objetivo" class="campo" rows="2" maxlength="2000" required placeholder="Ej: Atender un reporte de robo cuidando al huésped y conservando las evidencias.">{{ $valor('objetivo') }}</textarea>
            <div class="row">
                <div class="col-md-6">
                    <label class="campo-etiqueta" for="{{ $modo }}_alcance">Alcance <span class="text-lowercase fw-normal">(cuándo aplica; opcional)</span></label>
                    <textarea id="{{ $modo }}_alcance" name="alcance" class="campo" rows="2" maxlength="2000" placeholder="Ej: Cualquier reporte de faltante en habitaciones y áreas públicas.">{{ $valor('alcance') }}</textarea>
                </div>
                <div class="col-md-6">
                    <label class="campo-etiqueta" for="{{ $modo }}_responsables">Responsables <span class="text-lowercase fw-normal">(opcional)</span></label>
                    <textarea id="{{ $modo }}_responsables" name="responsables" class="campo" rows="2" maxlength="1000" placeholder="Ej: Supervisor de turno, Agente de caseta, Gerente de guardia.">{{ $valor('responsables') }}</textarea>
                </div>
            </div>

            {{-- ===== 2. A quién aplica ===== --}}
            <h3 class="paso-pase"><span class="numero-paso">2</span><i class="bi bi-people text-success me-2" aria-hidden="true"></i>A quién aplica</h3>
            <p class="small text-muted mb-2">Quien entre aquí debe leerlo y firmar «Leí y entendí». Sin departamentos ni puestos marcados, aplica a todo el personal de esas sedes.</p>
            <div class="opciones-aplica" role="radiogroup" aria-label="Sedes">
                @if ($formulario['todasPermitido'])
                    <label class="opcion-aplica"><input type="radio" name="aplica" value="todas" @checked($aplica === 'todas') data-aplica-sedes> <span><strong>Todas las sedes</strong></span></label>
                @endif
                <label class="opcion-aplica"><input type="radio" name="aplica" value="sedes" @checked($aplica === 'sedes' || ! $formulario['todasPermitido']) data-aplica-sedes> <span><strong>Sedes elegidas</strong></span></label>
            </div>
            <fieldset class="lista-casillas-circuito casillas-aplica" data-sedes-elegidas @if ($aplica !== 'sedes' && $formulario['todasPermitido']) hidden @endif>
                <legend class="visually-hidden">Sedes</legend>
                @forelse ($sedesUsuario as $s)
                    <label><input type="checkbox" name="sedes[]" value="{{ $s->id }}" @checked(in_array((int) $s->id, $marcados('sedes'), true))> {{ $s->nombre }}</label>
                @empty
                    <p class="small text-muted m-0">No hay sedes activas en tu alcance.</p>
                @endforelse
            </fieldset>
            <details class="detalle-aplica" @if ($seleccionDeptos || $seleccionPuestos) open @endif>
                <summary><i class="bi bi-funnel me-1" aria-hidden="true"></i>Solo algunos departamentos o puestos <span class="text-muted fw-normal">(opcional)</span></summary>
                <div class="row">
                    <div class="col-md-6">
                        <span class="campo-etiqueta d-block">Departamentos</span>
                        <div class="lista-casillas-circuito">
                            @forelse ($formulario['departamentos'] as $d)
                                <label><input type="checkbox" name="departamentos[]" value="{{ $d->id }}" @checked(in_array((int) $d->id, $seleccionDeptos, true))> {{ $d->nombre }}</label>
                            @empty
                                <p class="small text-muted m-0">Sin departamentos.</p>
                            @endforelse
                        </div>
                    </div>
                    <div class="col-md-6">
                        <span class="campo-etiqueta d-block">Puestos</span>
                        <div class="lista-casillas-circuito">
                            @forelse ($formulario['puestos'] as $pu)
                                <label><input type="checkbox" name="puestos[]" value="{{ $pu->id }}" @checked(in_array((int) $pu->id, $seleccionPuestos, true))> {{ $pu->nombre }}</label>
                            @empty
                                <p class="small text-muted m-0">Sin puestos.</p>
                            @endforelse
                        </div>
                    </div>
                </div>
                <p class="small text-muted">Le aplica a quien esté en <strong>alguno</strong> de los departamentos marcados <strong>o</strong> tenga alguno de los puestos marcados.</p>
            </details>

            {{-- ===== 3. Pasos ===== --}}
            <h3 class="paso-pase"><span class="numero-paso">3</span><i class="bi bi-list-ol text-warning me-2" aria-hidden="true"></i>Pasos <span class="normal">(en orden)</span></h3>
            <p class="small text-muted mb-2">Un paso por renglón, claro y corto. Marca <strong>Punto crítico</strong> lo que nunca debe olvidarse: se resalta en rojo al consultarlo.</p>
            <ol class="pasos-editor" data-pasos-editor data-siguiente="{{ count($pasosPrevios) }}">
                @foreach ($pasosPrevios as $i => $paso)
                    @include('seguridad.procedimientos._paso', ['i' => $i, 'paso' => is_array($paso) ? $paso : []])
                @endforeach
            </ol>
            <template data-plantilla-paso>
                @include('seguridad.procedimientos._paso', ['i' => '__i__', 'paso' => []])
            </template>
            <button type="button" class="btn-agregar-articulo" data-agregar-paso><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Agregar paso</button>

            {{-- ===== 4. Notas y adjuntos ===== --}}
            <h3 class="paso-pase"><span class="numero-paso">4</span><i class="bi bi-paperclip text-secondary me-2" aria-hidden="true"></i>Notas y adjuntos <span class="normal">(opcional)</span></h3>
            <label class="campo-etiqueta" for="{{ $modo }}_notas">Notas</label>
            <textarea id="{{ $modo }}_notas" name="notas" class="campo" rows="2" maxlength="4000" placeholder="Teléfonos de emergencia, aclaraciones…">{{ $valor('notas') }}</textarea>
            @if ($editando && $trabajo->adjuntos->isNotEmpty())
                <span class="campo-etiqueta d-block">Adjuntos de esta versión <span class="text-lowercase fw-normal">(marca los que quieras quitar)</span></span>
                <ul class="lista-adjuntos-editar">
                    @foreach ($trabajo->adjuntos as $a)
                        <li><label><input type="checkbox" name="quitar_adjuntos[]" value="{{ $a->id }}"> <i class="bi {{ $a->esImagen() ? 'bi-file-image' : 'bi-file-earmark-pdf' }}" aria-hidden="true"></i> {{ $a->nombre }} <span class="text-muted">({{ $a->tamanoLegible() }})</span> — quitar</label></li>
                    @endforeach
                </ul>
            @endif
            <label class="campo-etiqueta" for="{{ $modo }}_adjuntos">Agregar adjuntos <span class="text-lowercase fw-normal">(PDF, JPG, PNG o WEBP; máximo 5 MB cada uno)</span></label>
            <input type="file" id="{{ $modo }}_adjuntos" name="adjuntos[]" class="campo" multiple accept=".pdf,.jpg,.jpeg,.png,.webp,application/pdf,image/jpeg,image/png,image/webp">

            @if ($editando && $trabajo->numero > 1)
                <label class="campo-etiqueta mt-2" for="{{ $modo }}_resumen">Qué cambió respecto a la versión {{ $trabajo->numero - 1 }} <span class="text-danger" aria-hidden="true">*</span> <span class="text-lowercase fw-normal">(obligatorio al enviar)</span></label>
                <textarea id="{{ $modo }}_resumen" name="resumen_cambios" class="campo" rows="2" maxlength="1000" placeholder="Ej: Se agregó llamar al 911 antes de avisar a la gerencia.">{{ $valor('resumen_cambios') }}</textarea>
            @endif

            <div class="dialogo-acciones">
                <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                <button type="submit" class="btn-cancelar btn-siguiente-pase"><i class="bi bi-save me-1" aria-hidden="true"></i>Guardar borrador</button>
                <button type="submit" class="btn-azul" name="enviar" value="1"><i class="bi bi-send me-1" aria-hidden="true"></i>Guardar y enviar a revisión</button>
            </div>
        </form>
    </div>
</dialog>
