{{--
    Alta y edición de llave (SEGCAT: dialogLlave / llave_modal_editar.php).
    La edición se llena con data-valores (editar-registro) y el bloque
    "Catálogo de llaves" de plataforma.js: horarios, responsable, lugares.
--}}
@php
    $esNueva = $modo === 'nueva';
    $p = $modo.'_ll_';
    $valor = fn (string $campo, string $porDefecto = '') => $trasError ? (string) old($campo, $porDefecto) : $porDefecto;
    $sedePorDefecto = $esNueva && $sedesFormulario->count() === 1 ? (string) $sedesFormulario->first()->id : '';
    $marcados = $trasError ? array_map('intval', (array) old('espacios', [])) : [];
    $gruposMarcados = $trasError ? array_map('intval', (array) old('grupos', [])) : [];
    $horarios = [];
    if ($trasError) {
        foreach ((array) old('horario_nombre', []) as $i => $nombre) {
            $horarios[] = ['nombre' => $nombre, 'inicio' => old("horario_inicio.$i"), 'fin' => old("horario_fin.$i")];
        }
    }
    if ($horarios === [] && $esNueva) {
        $horarios[] = ['nombre' => '', 'inicio' => '', 'fin' => ''];
    }
    $responsableTexto = $trasError && $responsableAnterior ? $responsableAnterior->nombreCompleto().' · Núm. '.$responsableAnterior->num_empleado : null;
    $etiquetaLugar = function ($e) {
        return match ($e->nivel) {
            \App\Models\Espacio::AREA => $e->nombre.($e->padre ? ' ('.$e->padre->nombre.')' : ''),
            \App\Models\Espacio::AREA_ESPECIFICA => $e->nombre.($e->padre ? ' ('.trim(($e->padre->padre?->nombre ? $e->padre->padre->nombre.' / ' : '').$e->padre->nombre).')' : ''),
            default => $e->nombre,
        };
    };
    $cajas = [
        'zona' => ['Edificio(s) / Zona(s)', '(cada uno completo)', \App\Models\Espacio::EDIFICIO],
        'piso' => ['Piso(s)', '(pueden ser de distintos edificios)', \App\Models\Espacio::AREA],
        'area' => ['Área Específica / Cuarto(s)', '(cualquiera, sin importar edificio o piso)', \App\Models\Espacio::AREA_ESPECIFICA],
    ];
    // Ronda 5 (LL-02): selección en cascada Zona/Edificio → Piso → Área específica (como las píldoras de SEGCAT)
    $edificios = $catalogos['espacios']->where('nivel', \App\Models\Espacio::EDIFICIO)->where('activo', true);
    $pisos = $catalogos['espacios']->where('nivel', \App\Models\Espacio::AREA)->where('activo', true);
    $cascada = function ($e) {
        $padre = $e->padre;
        return match (true) {
            $padre === null => ['', ''],
            $padre->nivel === \App\Models\Espacio::AREA => [(string) ($padre->padre_id ?? ''), (string) $padre->id],
            default => [(string) $padre->id, ''],
        };
    };
@endphp
<dialog id="{{ $esNueva ? 'dialogoNuevaLlave' : 'dialogoEditarLlave' }}" class="dialogo ancho dialogo-llave" aria-labelledby="titulo-ll-{{ $modo }}"
        @if ($trasError) data-abrir-al-cargar @endif>
    <div class="dialogo-cabecera">
        <h2 id="titulo-ll-{{ $modo }}"><i class="bi {{ $esNueva ? 'bi-key' : 'bi-pencil-square' }} me-2 text-indigo" aria-hidden="true"></i>{{ $esNueva ? 'Alta Inventario de Llave' : 'Actualizar Llave' }}</h2>
        <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </div>
    <div class="dialogo-cuerpo">
        <form action="{{ $esNueva ? route('llaves.store') : ($editandoId ? route('llaves.update', $editandoId) : '') }}" method="POST" autocomplete="off" data-form-llave>
            @csrf
            @unless ($esNueva) @method('PUT') @endunless
            <input type="hidden" name="_dialogo" value="{{ $esNueva ? 'crear' : ($editandoId ? 'editar-'.$editandoId : '') }}" data-campo-dialogo>
            <p class="linea-empresa mb-3"><i class="bi bi-building-check me-1" aria-hidden="true"></i>Catálogo de: <strong>{{ $empresaNombre }}</strong></p>

            <div class="row">
                <div class="col-md-6">
                    <label class="campo-etiqueta" for="{{ $p }}sede">Sede</label>
                    <select id="{{ $p }}sede" name="sede_id" class="campo" required data-llave-sede>
                        <option value="">-- Seleccione Sede --</option>
                        @foreach ($sedesFormulario as $s)
                            <option value="{{ $s->id }}" @selected($valor('sede_id', $sedePorDefecto) === (string) $s->id) @if ($sedePorDefecto === (string) $s->id) data-por-defecto @endif
                                    @unless ($s->activo) hidden @endunless>{{ $s->nombre }}{{ $s->activo ? '' : ' (inactiva)' }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="campo-etiqueta" for="{{ $p }}depto">Departamento <span class="text-lowercase fw-normal">(opcional)</span></label>
                    <select id="{{ $p }}depto" name="departamento_id" class="campo" data-llave-depto>
                        <option value="">-- Corporativo (Todos) --</option>
                        @foreach ($catalogos['departamentos'] as $d)
                            <option value="{{ $d->id }}" @selected($valor('departamento_id') === (string) $d->id)
                                    data-sedes="{{ $d->todas_las_sedes ? 'todas' : $d->sedes->pluck('id')->join(' ') }}"
                                    @unless ($d->activo) data-inactivo hidden @endunless>{{ $d->nombre }}{{ $d->activo ? '' : ' (inactivo)' }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <label class="campo-etiqueta" for="{{ $p }}puesto">Puesto Objetivo <span class="text-lowercase fw-normal">(opcional)</span></label>
            <select id="{{ $p }}puesto" name="puesto_id" class="campo mb-1" data-llave-puesto>
                <option value="">-- Cualquier Puesto --</option>
                @foreach ($catalogos['puestos'] as $pu)
                    <option value="{{ $pu->id }}" @selected($valor('puesto_id') === (string) $pu->id)
                            data-deps="{{ $pu->departamentos->pluck('id')->join(' ') }}"
                            @unless ($pu->activo) data-inactivo hidden @endunless>{{ $pu->nombre }}{{ $pu->activo ? '' : ' (inactivo)' }}</option>
                @endforeach
            </select>
            <p class="campo-ayuda mb-3">Se acota según el Departamento elegido (los puestos sin departamento siempre aparecen).</p>

            @include('componentes.lector', ['id' => $p.'responsable', 'etiqueta' => 'Responsable Permanente (opcional)',
                'tipos' => 'colaborador', 'nombre' => 'colaborador_id', 'valor' => $trasError ? old('colaborador_id') : null, 'elegido' => $responsableTexto,
                'ayuda' => 'Excepcional: la mayoría de las llaves NO llevan a nadie aquí; el préstamo normal se registra en Préstamo de llaves. Escanea su gafete o escribe su número de empleado.'])

            <label class="campo-etiqueta" for="{{ $p }}nomenclatura">Nombre de la Llave (Único en su sede)</label>
            {{-- Ronda 5 (LL-03): la lista de nombres cambia con la sede elegida (data-nombres-por-sede) --}}
            <input type="text" id="{{ $p }}nomenclatura" name="nomenclatura" class="campo text-uppercase mb-1" maxlength="50" placeholder="Ej: LL-CAT-SIT-01"
                   value="{{ $valor('nomenclatura') }}" autocapitalize="characters" data-nombres-existentes="[]" data-nombres-por-sede="{{ $nombresExistentes }}"
                   data-ambito-nombre="esta sede" required>
            <p class="small mb-2" data-aviso-nombre hidden></p>

            <label class="campo-etiqueta" for="{{ $p }}descripcion">Descripción de Accesos</label>
            <input type="text" id="{{ $p }}descripcion" name="descripcion" class="campo" maxlength="255" placeholder="Ej: Llaves del site central de TI" value="{{ $valor('descripcion') }}" required>

            <div class="row">
                <div class="col-md-6">
                    <label class="campo-etiqueta" for="{{ $p }}tipo">Tipo Dispositivo</label>
                    <select id="{{ $p }}tipo" name="tipo_dispositivo" class="campo" required data-llave-tipo>
                        @foreach (\App\Models\Llave::TIPOS_DISPOSITIVO as $clave => $texto)
                            <option value="{{ $clave }}" @selected($valor('tipo_dispositivo', 'electronica_rfid') === $clave) @if ($clave === 'electronica_rfid') data-por-defecto @endif>{{ $texto }}</option>
                        @endforeach
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="campo-etiqueta" for="{{ $p }}alcance">Alcance de Apertura</label>
                    <select id="{{ $p }}alcance" name="alcance" class="campo" required data-llave-alcance>
                        @foreach (\App\Models\Llave::ALCANCES as $clave => $texto)
                            <option value="{{ $clave }}" @selected($valor('alcance', 'global') === $clave) @if ($clave === 'global') data-por-defecto @endif>{{ $texto }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            {{-- Lo que abre, según el alcance: lugares de Zonas y áreas de ESA sede --}}
            <div class="caja-lugares" data-llave-lugares="global" hidden>
                <p class="campo-ayuda m-0"><i class="bi bi-globe2" aria-hidden="true"></i> Llave maestra: abre todo en la sede elegida.</p>
            </div>
            @foreach ($cajas as $alcance => [$titulo, $nota, $nivel])
                <div class="caja-lugares" data-llave-lugares="{{ $alcance }}" hidden>
                    <label class="campo-etiqueta" for="{{ $p }}buscar_{{ $alcance }}">{{ $titulo }} <span class="text-lowercase fw-normal">{{ $nota }}</span></label>
                    <p class="campo-ayuda mb-2" data-lugares-sin-sede><i class="bi bi-info-circle" aria-hidden="true"></i> Elige la Sede arriba primero.</p>
                    @if ($alcance !== 'zona')
                        <div class="cascada-lugares" data-cascada>
                            <div class="cascada-paso" data-cascada-paso="edificio">
                                <span class="cascada-titulo">1. Zona / Edificio <span class="fw-normal">(toca uno o varios para ver solo sus {{ $alcance === 'piso' ? 'pisos' : 'pisos y cuartos' }})</span></span>
                                <div class="cascada-pildoras">
                                    @foreach ($edificios as $ed)
                                        <label class="pildora-cascada" data-pildora-edificio data-sede="{{ $ed->sede_id }}">
                                            <input type="checkbox" value="{{ $ed->id }}"> {{ $ed->nombre }}
                                        </label>
                                    @endforeach
                                </div>
                            </div>
                            @if ($alcance === 'area')
                                <div class="cascada-paso" data-cascada-paso="piso">
                                    <span class="cascada-titulo">2. Piso <span class="fw-normal">(opcional: acota todavía más)</span></span>
                                    <div class="cascada-pildoras">
                                        @foreach ($pisos as $pi)
                                            <label class="pildora-cascada" data-pildora-piso data-sede="{{ $pi->sede_id }}" data-edificio="{{ $pi->padre_id }}">
                                                <input type="checkbox" value="{{ $pi->id }}"> {{ $pi->nombre }}@if ($pi->padre) <span class="text-muted fw-normal">({{ $pi->padre->nombre }})</span>@endif
                                            </label>
                                        @endforeach
                                    </div>
                                </div>
                            @endif
                            <span class="cascada-titulo">{{ $alcance === 'area' ? '3. Cuartos / áreas' : '2. Pisos' }} <span class="fw-normal">(marca los que abre)</span></span>
                        </div>
                    @endif
                    <input type="search" id="{{ $p }}buscar_{{ $alcance }}" class="campo campo-buscar-lugar mb-2" placeholder="Buscar por nombre..." data-buscar-lugar>
                    <div class="caja-checks lista-lugares">
                        @foreach ($catalogos['espacios']->where('nivel', $nivel) as $e)
                            @php [$deEdificio, $dePiso] = $cascada($e); @endphp
                            <label class="fila-check" data-lugar data-sede="{{ $e->sede_id }}" data-nombre="{{ mb_strtolower($etiquetaLugar($e)) }}" @unless ($e->activo) data-inactivo @endunless
                                   data-edificio="{{ $deEdificio }}" data-piso="{{ $dePiso }}">
                                <input type="checkbox" name="espacios[]" value="{{ $e->id }}" @checked(in_array($e->id, $marcados, true))>
                                {{ $etiquetaLugar($e) }}{{ $e->activo ? '' : ' (desactivado)' }}
                            </label>
                        @endforeach
                        <p class="campo-ayuda m-0 p-1" data-lugares-vacio hidden><i class="bi bi-info-circle" aria-hidden="true"></i> Esta sede no tiene lugares de este tipo en <strong>Zonas y áreas</strong>. Agrégalos ahí o elige el alcance «Otra».</p>
                    </div>
                </div>
            @endforeach
            <div class="caja-lugares" data-llave-lugares="seccion" hidden>
                <span class="campo-etiqueta">Sección(es) <span class="text-lowercase fw-normal">(grupos ya armados en Zonas y áreas)</span></span>
                <p class="campo-ayuda mb-2" data-lugares-sin-sede><i class="bi bi-info-circle" aria-hidden="true"></i> Elige la Sede arriba primero.</p>
                <div class="caja-checks lista-lugares">
                    @foreach ($catalogos['grupos'] as $g)
                        <label class="fila-check" data-lugar data-sede="{{ $g->sede_id }}" data-nombre="{{ mb_strtolower($g->nombre) }}" @unless ($g->activo) data-inactivo @endunless>
                            <input type="checkbox" name="grupos[]" value="{{ $g->id }}" @checked(in_array($g->id, $gruposMarcados, true))>
                            {{ $g->nombre }} <span class="text-muted small">({{ $g->espacios_count }} hab.)</span>{{ $g->activo ? '' : ' (desactivada)' }}
                        </label>
                    @endforeach
                    <p class="campo-ayuda m-0 p-1" data-lugares-vacio hidden><i class="bi bi-info-circle" aria-hidden="true"></i> Esta sede no tiene secciones. Créalas en <strong>Zonas y áreas</strong>, pestaña Secciones.</p>
                </div>
            </div>
            <div class="caja-lugares" data-llave-lugares="otra" hidden>
                <label class="campo-etiqueta" for="{{ $p }}otra">Especificar Espacio <span class="text-lowercase fw-normal">(no catalogado en Zonas y áreas)</span></label>
                <input type="text" id="{{ $p }}otra" name="alcance_otro" class="campo mb-0" maxlength="150" placeholder="Ej: Cuarto de máquinas, Reja de mantenimiento..." value="{{ $valor('alcance_otro') }}" data-llave-otra>
            </div>

            <div class="caja-id-externo" data-mostrar-si='{"tipo_dispositivo":{{ json_encode(\App\Models\Llave::CON_ID_EXTERNO) }}}'>
                <div class="row">
                    <div class="col-md-6">
                        <label class="campo-etiqueta" for="{{ $p }}id_externo">ID Externo <span class="text-lowercase fw-normal">(opcional)</span></label>
                        <input type="text" id="{{ $p }}id_externo" name="id_externo" class="campo text-uppercase" maxlength="60" placeholder="Ej: VC-000187" value="{{ $valor('id_externo') }}">
                    </div>
                    <div class="col-md-6">
                        <label class="campo-etiqueta" for="{{ $p }}plataforma">Plataforma que lo generó</label>
                        <input type="text" id="{{ $p }}plataforma" name="plataforma_externa" class="campo" maxlength="80" placeholder="Ej: VingCard, Onity, Salto, ZKTeco..." value="{{ $valor('plataforma_externa') }}">
                    </div>
                </div>
                <p class="campo-ayuda mb-0"><i class="bi bi-info-circle" aria-hidden="true"></i> La plataforma no programa la llave: solo guarda estos datos para consultarlos. Dos plataformas distintas pueden coincidir en el mismo ID; lo que no se repite es «este ID en esta plataforma, en esta sede».</p>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <label class="campo-etiqueta" for="{{ $p }}caducidad">Fecha de Caducidad <span class="text-lowercase fw-normal">(opcional)</span></label>
                    <input type="date" id="{{ $p }}caducidad" name="fecha_caducidad" class="campo mb-1" value="{{ $valor('fecha_caducidad') }}">
                    <p class="campo-ayuda mb-3"><i class="bi bi-info-circle" aria-hidden="true"></i> Para saber con tiempo qué llaves necesitan reprogramarse.</p>
                    {{-- Ronda 5 (LL-05): costo de reposición fijo o variable --}}
                    <label class="campo-etiqueta" for="{{ $p }}costo">Costo de Reposición <span class="text-lowercase fw-normal">(opcional)</span></label>
                    <div class="grupo-monto mb-1">
                        <span class="grupo-monto-simbolo" aria-hidden="true">$</span>
                        <input type="number" step="0.01" min="0" max="999999.99" id="{{ $p }}costo" name="costo_reposicion" class="campo grupo-monto-campo" inputmode="decimal" placeholder="0.00" value="{{ $valor('costo_reposicion') }}">
                    </div>
                    <label class="opcion-cobro mb-1">
                        <input type="checkbox" name="costo_variable" value="1" @checked($valor('costo_variable') === '1')>
                        Costo variable (se captura en cada baja)
                    </label>
                    <p class="campo-ayuda mb-3"><i class="bi bi-info-circle" aria-hidden="true"></i> Con costo fijo, el voucher de baja cobra este monto. Con costo variable, se pregunta el monto en cada baja (este valor solo se sugiere).</p>
                </div>
                <div class="col-md-6">
                    @include('componentes.lector', ['id' => $p.'nfc', 'etiqueta' => 'Etiqueta NFC / RFID (opcional)', 'modo' => 'capturar',
                        'nombre' => 'etiqueta_nfc', 'valor' => $valor('etiqueta_nfc'), 'ayuda' => 'Acerca la tarjeta o el llavero NFC al lector para asignarlo a esta llave.'])
                </div>
            </div>

            <span class="campo-etiqueta">Horarios de Apertura Válidos</span>
            <div class="horarios-llave" data-horarios-llave>
                @foreach ($horarios as $h)
                    <div class="fila-horario" data-fila-horario>
                        <input type="text" name="horario_nombre[]" class="campo mb-0" maxlength="60" placeholder="Nombre (ej. Turno Limpieza)" aria-label="Nombre del horario" value="{{ $h['nombre'] }}">
                        <input type="time" lang="es-MX" name="horario_inicio[]" class="campo mb-0" aria-label="Hora de inicio" value="{{ $h['inicio'] }}">
                        <input type="time" lang="es-MX" name="horario_fin[]" class="campo mb-0" aria-label="Hora de fin" value="{{ $h['fin'] }}">
                        <button type="button" class="btn-quitar-horario" data-quitar-horario title="Quitar horario" aria-label="Quitar horario"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                    </div>
                @endforeach
            </div>
            <template data-plantilla-horario>
                <div class="fila-horario" data-fila-horario>
                    <input type="text" name="horario_nombre[]" class="campo mb-0" maxlength="60" placeholder="Nombre (ej. Turno Limpieza)" aria-label="Nombre del horario">
                    <input type="time" lang="es-MX" name="horario_inicio[]" class="campo mb-0" aria-label="Hora de inicio">
                    <input type="time" lang="es-MX" name="horario_fin[]" class="campo mb-0" aria-label="Hora de fin">
                    <button type="button" class="btn-quitar-horario" data-quitar-horario title="Quitar horario" aria-label="Quitar horario"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
            </template>
            <button type="button" class="btn-agregar-horario" data-agregar-horario><i class="bi bi-plus-circle" aria-hidden="true"></i> Agregar otro horario</button>
            <p class="campo-ayuda mt-2"><i class="bi bi-info-circle" aria-hidden="true"></i> Sin ningún horario = válida las 24 horas. El nombre es libre, propio de esta llave (no depende del catálogo de Turnos). Si la hora de fin es menor que la de inicio, termina al día siguiente.</p>

            <div class="dialogo-acciones">
                <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                <button type="submit" class="btn-indigo">{{ $esNueva ? 'Guardar e Indexar Llave' : 'Actualizar Registro' }}</button>
            </div>
        </form>
        @unless ($esNueva)
            @include('componentes.borrar', ['registro' => 'llaves', 'id' => $editandoId ?? null])
        @endunless
    </div>
</dialog>
