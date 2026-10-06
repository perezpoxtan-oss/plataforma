@extends('layouts.app')

@section('titulo', 'Inventario Gafetes')

@section('contenido')
<div class="tema-gafetes">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    @if ($sinEmpresa)
        <div class="encabezado-pantalla mb-4">
            <div class="icono"><i class="bi bi-vignette text-dark" aria-hidden="true"></i></div>
            <div><h1>Inventario Gafetes</h1><p>Plásticos físicos para accesos.</p></div>
        </div>
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-buildings" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong> para ver su inventario de gafetes.</p>
        </div>
    @else
        @php
            $nuevo = \App\Services\Gafetes\AdministradorGafetes::TIPO_NUEVO;
            $dialogo = old('_dialogo');
            $editandoId = is_string($dialogo) && str_starts_with($dialogo, 'editar-') ? (int) substr($dialogo, 7) : null;
            $bajaId = is_string($dialogo) && str_starts_with($dialogo, 'baja-') ? (int) substr($dialogo, 5) : null;
            $enBaja = $bajaId ? $gafetes->firstWhere('id', $bajaId) : null;
            $conteoTipo = $gafetes->countBy('tipo_gafete_id');
            $tiposActivos = $tipos->where('activo', true);
            $hayCasillas = $puede['imprimir'] && $gafetes->isNotEmpty();
        @endphp

        @if ($voucherGenerado)
            <div class="aviso-voucher-generado" role="status">
                <i class="bi bi-receipt" aria-hidden="true"></i>
                <span>Voucher <strong>{{ $voucherGenerado['folio'] }}</strong> listo para imprimir (3 copias en una hoja: Seguridad, Colaborador y Recepción).</span>
                <a href="{{ route('vouchers.imprimir', $voucherGenerado['id']) }}" target="_blank" rel="noopener" class="btn-gafetes"><i class="bi bi-printer me-1" aria-hidden="true"></i>Imprimir voucher</a>
            </div>
        @endif

        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-3">
            <div class="encabezado-pantalla m-0">
                <div class="icono"><i class="bi bi-vignette text-dark" aria-hidden="true"></i></div>
                <div>
                    <h1>Inventario Gafetes</h1>
                    <p>Plásticos físicos para accesos.</p>
                    <p class="texto-padron-de"><i class="bi bi-building-check" aria-hidden="true"></i> Inventario de: <strong>{{ $empresaNombre }}</strong></p>
                </div>
            </div>
            <div class="barra-gafetes">
                @if ($sedesFiltro->count() > 1)
                    <select class="filtro-select" aria-label="Filtrar por sede" data-filtro-sede="gafetes">
                        <option value="">Todas las sedes</option>
                        @foreach ($sedesFiltro as $sede)
                            <option value="{{ $sede->id }}">{{ $sede->nombre }}</option>
                        @endforeach
                    </select>
                @endif
                @if ($hayCasillas)
                    <button type="button" class="btn-marcar-todos" data-marcar-gafetes aria-pressed="false"><i class="bi bi-check-all me-1" aria-hidden="true"></i><span data-texto-marcar>Marcar todos</span></button>
                    <button type="submit" form="formImprimirGafetes" class="btn-gafetes btn-imprimir-gafetes" data-imprimir-gafetes>
                        <i class="bi bi-printer me-1" aria-hidden="true"></i>Imprimir <span class="conteo-seleccion" data-conteo-gafetes hidden></span>
                    </button>
                @endif
                <div class="buscador">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="search" placeholder="Buscar nomenclatura..." aria-label="Buscar gafete" data-filtro-texto="gafetes">
                </div>
            </div>
        </div>

        <div class="pildoras-tipo" role="group" aria-label="Filtrar por tipo de gafete">
            <button type="button" class="btn-pill-tipo active" data-filtro-tipo="gafetes" data-valor="" aria-pressed="true">Todos <span class="conteo-pill">{{ $gafetes->count() }}</span></button>
            @foreach ($tipos as $t)
                @continue (! $t->activo && ! $conteoTipo->has($t->id))
                <button type="button" class="btn-pill-tipo" data-filtro-tipo="gafetes" data-valor="{{ $t->id }}" aria-pressed="false">{{ $t->nombre }} <span class="conteo-pill">{{ $conteoTipo[$t->id] ?? 0 }}</span></button>
            @endforeach
        </div>
        @include('componentes.borrar-tipos', ['registro' => 'tipos_gafete', 'tipos' => $tipos, 'conteo' => $conteoTipo, 'titulo' => 'tipos de gafete'])

        <div class="filtros-estado" role="group" aria-label="Filtrar por estado">
            <button type="button" class="btn btn-dark btn-sm fw-bold" data-filtro-estado="gafetes" data-valor="todas" aria-pressed="true">Todos</button>
            <button type="button" class="btn btn-outline-success btn-sm fw-bold" data-filtro-estado="gafetes" data-valor="1" aria-pressed="false">Disponibles</button>
            <button type="button" class="btn btn-outline-danger btn-sm fw-bold" data-filtro-estado="gafetes" data-valor="0" aria-pressed="false">No disponibles</button>
        </div>

        @if ($hayCasillas)
            {{-- Las casillas de cada ficha pertenecen a este formulario (atributo form=) --}}
            <form id="formImprimirGafetes" action="{{ route('gafetes.imprimir') }}" method="POST" target="_blank" data-form-imprimir-gafetes>@csrf</form>
            <p class="aviso-sin-marcar" data-aviso-sin-marcar role="alert" hidden><i class="bi bi-info-circle-fill me-1" aria-hidden="true"></i>Marca al menos un gafete (o usa «Marcar todos») para imprimir.</p>
        @endif

        <div class="fichas-grid" data-fichas="gafetes">
            @if ($puede['crear'])
                <button type="button" class="ficha-card ficha-create" data-abrir-dialogo="dialogoLoteGafetes">
                    <i class="bi bi-plus-circle-fill" aria-hidden="true"></i>
                    <span class="h6 fw-bold m-0 mt-2 titulo-crear">Generar Lote</span>
                    <span class="small text-muted mt-1">De 1 a 50 gafetes por sede y tipo</span>
                </button>
            @endif

            @forelse ($gafetes as $g)
                @php
                    $editable = $puede['editar'] && ($editables === null || in_array($g->id, $editables, true));
                    $desactivable = $puede['estado'] && ($desactivables === null || in_array($g->id, $desactivables, true));
                    $imprimible = $puede['imprimir'] && ($imprimibles === null || in_array($g->id, $imprimibles, true));
                    $codigo = trim(chunk_split($g->codigo_qr, 4, ' '));
                    $texto = mb_strtolower(implode(' ', array_filter([$g->nomenclatura, $g->tipo?->nombre, $g->sede?->nombre, $g->etiqueta_nfc, $g->codigo_qr])));
                @endphp
                <div class="ficha-card ficha-gafete {{ $g->activo ? '' : 'inactiva' }}" id="gafete-{{ $g->id }}" data-ficha
                     data-texto="{{ $texto }}" data-estado="{{ $g->activo ? '1' : '0' }}" data-sede="{{ $g->sede_id }}" data-tipo="{{ $g->tipo_gafete_id }}">
                    <div>
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                            <h2 class="gafete-nomenclatura" title="{{ $g->nomenclatura }}"><i class="bi bi-qr-code me-2 text-muted" aria-hidden="true"></i>{{ $g->nomenclatura }}</h2>
                            @if ($imprimible)
                                <label class="casilla-imprimir" title="Marcar para imprimir">
                                    <input type="checkbox" name="gafetes[]" value="{{ $g->id }}" form="formImprimirGafetes" data-casilla-gafete aria-label="Marcar {{ $g->nomenclatura }} para imprimir">
                                </label>
                            @endif
                        </div>
                        <div class="gafete-meta">
                            <div><span>Tipo:</span> <strong>{{ $g->tipo?->nombre ?? '—' }}</strong></div>
                            <div><span>Sede:</span> <strong>{{ $g->sede?->nombre ?? '—' }}</strong></div>
                        </div>
                        <p class="gafete-codigo" title="Código del QR (también se puede grabar en una etiqueta NFC)"><i class="bi bi-upc-scan" aria-hidden="true"></i> {{ $codigo }}</p>
                        @if ($g->etiqueta_nfc)
                            <p class="gafete-codigo"><i class="bi bi-broadcast-pin" aria-hidden="true"></i> NFC / RFID: {{ $g->etiqueta_nfc }}</p>
                        @endif
                        @if ($g->actualizado_por_nombre && $g->updated_at?->ne($g->created_at))
                            <div class="texto-traza mt-1"><i class="bi bi-clock-history" aria-hidden="true"></i> Editado por {{ $g->actualizado_por_nombre }} · @fecha($g->updated_at)</div>
                        @elseif ($g->creado_por_nombre)
                            <div class="texto-traza mt-1"><i class="bi bi-plus-circle" aria-hidden="true"></i> Creado por {{ $g->creado_por_nombre }} · @fecha($g->created_at)</div>
                        @endif
                    </div>

                    <div class="ficha-footer">
                        @if ($g->activo)
                            <span class="estado-gafete disponible">DISPONIBLE</span>
                        @else
                            <span class="estado-gafete no-disponible" title="Extraviado / Dañado / Robado">NO DISPONIBLE</span>
                        @endif
                        <div class="d-flex gap-2">
                            {{-- Ronda 5: QR y etiqueta NFC/RFID en un diálogo --}}
                            @include('componentes.boton-identificacion', ['identTipo' => 'gafete', 'identRegistro' => $g, 'identTitulo' => $g->nomenclatura,
                                'identDetalle' => trim(($g->tipo?->nombre ?? '').' · '.($g->sede?->nombre ?? ''), ' ·'),
                                'identImprimir' => $imprimible ? route('gafetes.imprimir', ['gafetes' => [$g->id]]) : null, 'identImprimirTexto' => 'Imprimir gafete',
                                'identEditable' => $editable])
                            @if ($imprimible)
                                <a href="{{ route('gafetes.imprimir', ['gafetes' => [$g->id]]) }}" target="_blank" rel="noopener" class="btn-icono imprimir-qr" title="Imprimir este gafete" aria-label="Imprimir el gafete {{ $g->nomenclatura }}"><i class="bi bi-printer" aria-hidden="true"></i></a>
                            @endif
                            @if ($editable)
                                <button type="button" class="btn-icono editar" title="Editar nomenclatura, tipo y etiqueta" aria-label="Editar gafete {{ $g->nomenclatura }}"
                                        data-accion="editar-registro" data-dialogo="dialogoEditarGafete"
                                        data-url="{{ route('gafetes.update', $g->id) }}" data-id="{{ $g->id }}"
                                        data-valores="{{ json_encode(['nomenclatura' => $g->nomenclatura, 'tipo_gafete_id' => (string) $g->tipo_gafete_id, 'nombre_tipo_nuevo' => '', 'etiqueta_nfc' => $g->etiqueta_nfc, 'codigo_legible' => $codigo]) }}">
                                    <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                </button>
                            @endif
                            @if ($desactivable)
                                @if ($g->activo)
                                    <button type="button" class="btn-icono eliminar" title="Dar de baja (extraviado, dañado o robado)" aria-label="Dar de baja el gafete {{ $g->nomenclatura }}"
                                            data-accion="editar-registro" data-dialogo="dialogoBajaGafete" data-titulo-registro="{{ $g->nomenclatura }}"
                                            data-url="{{ route('gafetes.baja', $g->id) }}" data-id="{{ $g->id }}"
                                            data-valores="{{ json_encode(['motivo' => 'extraviado', 'descripcion' => '', 'aplica_cobro' => false, 'monto' => $costos[$g->tipo_gafete_id] ?? '']) }}">
                                        <i class="bi bi-x-circle" aria-hidden="true"></i>
                                    </button>
                                @else
                                    <form action="{{ route('gafetes.reactivar', $g->id) }}" method="POST" class="m-0" data-confirmar="¿Reactivar el gafete {{ $g->nomenclatura }}? Su voucher se conserva.">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="btn-icono reactivar" title="Reactivar" aria-label="Reactivar el gafete {{ $g->nomenclatura }}"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></button>
                                    </form>
                                @endif
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <div class="tarjeta estado-vacio estado-vacio-gafetes">
                    <div class="icono"><i class="bi bi-vignette" aria-hidden="true"></i></div>
                    <p class="fw-semibold mb-1">Todavía no hay gafetes{{ $sedesFiltro->count() === 1 ? ' en tu sede' : '' }}.</p>
                    <p class="text-muted small m-0">
                        @if ($puede['crear'])
                            Presiona <strong>«Generar Lote»</strong> para crear los primeros: eliges sede, tipo y cuántos.
                        @else
                            Cuando se generen aparecerán aquí.
                        @endif
                    </p>
                </div>
            @endforelse

            <div class="sin-resultados" data-sin-resultados="gafetes" hidden>
                <i class="bi bi-search d-block mb-2" aria-hidden="true"></i>
                <p class="fw-semibold m-0">No hay gafetes que coincidan con tu búsqueda.</p>
            </div>
        </div>

        {{-- ===== Generar lote ===== --}}
        @if ($puede['crear'])
            @php $trasError = $dialogo === 'lote'; @endphp
            <dialog id="dialogoLoteGafetes" class="dialogo" aria-labelledby="titulo-ga-lote" @if ($trasError) data-abrir-al-cargar @endif>
                <div class="dialogo-cabecera">
                    <h2 id="titulo-ga-lote"><i class="bi bi-vignette me-2" aria-hidden="true"></i>Generar Gafetes</h2>
                    <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
                <div class="dialogo-cuerpo">
                    @if ($sedesLote->isEmpty())
                        <p class="aviso-solo-lectura mb-0"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>No hay sedes activas donde puedas generar gafetes. Pide que te asignen una sede o que la activen en «Sedes».</p>
                    @else
                        <form action="{{ route('gafetes.lote') }}" method="POST" autocomplete="off" data-form-gafete>
                            @csrf
                            <input type="hidden" name="_dialogo" value="lote">
                            <p class="linea-empresa mb-3"><i class="bi bi-building-check me-1" aria-hidden="true"></i>Inventario de: <strong>{{ $empresaNombre }}</strong></p>

                            <label class="campo-etiqueta" for="ga_lote_sede">Sede</label>
                            <select id="ga_lote_sede" name="sede_id" class="campo" required>
                                @if ($sedesLote->count() > 1)
                                    <option value="" data-por-defecto>-- Seleccione Sede --</option>
                                @endif
                                @foreach ($sedesLote as $sede)
                                    <option value="{{ $sede->id }}" @selected($trasError && (string) old('sede_id') === (string) $sede->id) @if ($sedesLote->count() === 1) data-por-defecto @endif>{{ $sede->nombre }} ({{ $sede->codigo }})</option>
                                @endforeach
                            </select>

                            <div class="campo-grupo">
                                <div class="flex-fill">
                                    <label class="campo-etiqueta" for="ga_lote_tipo">Tipo de Gafete</label>
                                    <select id="ga_lote_tipo" name="tipo_gafete_id" class="campo" required>
                                        @foreach ($tiposActivos as $t)
                                            <option value="{{ $t->id }}" @selected($trasError && (string) old('tipo_gafete_id') === (string) $t->id) @if ($loop->first) data-por-defecto @endif>{{ $t->nombre }}</option>
                                        @endforeach
                                        <option value="{{ $nuevo }}" @selected($trasError && old('tipo_gafete_id') === $nuevo)>+ Nuevo tipo...</option>
                                    </select>
                                </div>
                                <div class="campo-cantidad">
                                    <label class="campo-etiqueta" for="ga_lote_cantidad">Cantidad a Crear</label>
                                    <input type="number" id="ga_lote_cantidad" name="cantidad" class="campo" value="{{ $trasError ? old('cantidad', 1) : 1 }}" min="1" max="{{ \App\Services\Gafetes\AdministradorGafetes::LOTE_MAXIMO }}" inputmode="numeric" required>
                                </div>
                            </div>

                            <div data-mostrar-si='{"tipo_gafete_id":["{{ $nuevo }}"]}' @unless ($trasError && old('tipo_gafete_id') === $nuevo) hidden @endunless>
                                <label class="campo-etiqueta" for="ga_lote_tipo_nuevo">Nombre del tipo nuevo</label>
                                <input type="text" id="ga_lote_tipo_nuevo" name="nombre_tipo_nuevo" class="campo" maxlength="50" placeholder="Ej: Capital Humano, RRHH, De Personas..."
                                       value="{{ $trasError ? old('nombre_tipo_nuevo') : '' }}" data-requerido-si='{"tipo_gafete_id":["{{ $nuevo }}"]}' @unless ($trasError && old('tipo_gafete_id') === $nuevo) disabled @endunless>
                            </div>

                            <p class="campo-ayuda mt-0"><i class="bi bi-info-circle" aria-hidden="true"></i> La nomenclatura se arma sola: <strong>EMPRESA-SEDE-TIPO-número</strong> (por ejemplo, <span class="text-nowrap">{{ \App\Services\Gafetes\AdministradorGafetes::prefijo($empresaNombre, $sedesLote->first()->codigo, ($tiposActivos->firstWhere('nombre', 'Visitante') ?? $tiposActivos->first())?->nombre ?? 'Visitante') }}001</span>) y sigue después del último que exista.</p>

                            <div class="dialogo-acciones">
                                <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                                <button type="submit" class="btn-gafetes">Generar Lote</button>
                            </div>
                        </form>
                    @endif
                </div>
            </dialog>
        @endif

        {{-- ===== Editar ===== --}}
        @if ($puede['editar'])
            @php $trasError = $editandoId !== null; @endphp
            <dialog id="dialogoEditarGafete" class="dialogo" aria-labelledby="titulo-ga-editar" @if ($trasError) data-abrir-al-cargar @endif>
                <div class="dialogo-cabecera">
                    <h2 id="titulo-ga-editar"><i class="bi bi-pencil-square me-2" aria-hidden="true"></i>Editar Gafete</h2>
                    <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
                <div class="dialogo-cuerpo">
                    <form action="{{ $editandoId ? route('gafetes.update', $editandoId) : '' }}" method="POST" autocomplete="off" data-form-gafete>
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="_dialogo" value="{{ $editandoId ? 'editar-'.$editandoId : '' }}" data-campo-dialogo>

                        <label class="campo-etiqueta" for="ga_ed_nomenclatura">Nomenclatura</label>
                        <input type="text" id="ga_ed_nomenclatura" name="nomenclatura" class="campo text-uppercase campo-nomenclatura" maxlength="50" value="{{ $trasError ? old('nomenclatura') : '' }}" required>

                        <label class="campo-etiqueta" for="ga_ed_tipo">Tipo de Gafete</label>
                        <select id="ga_ed_tipo" name="tipo_gafete_id" class="campo" required>
                            @foreach ($tipos as $t)
                                <option value="{{ $t->id }}" @selected($trasError && (string) old('tipo_gafete_id') === (string) $t->id) @if ($loop->first) data-por-defecto @endif>{{ $t->nombre }}{{ $t->activo ? '' : ' (inactivo)' }}</option>
                            @endforeach
                            <option value="{{ $nuevo }}" @selected($trasError && old('tipo_gafete_id') === $nuevo)>+ Nuevo tipo...</option>
                        </select>
                        <div data-mostrar-si='{"tipo_gafete_id":["{{ $nuevo }}"]}' @unless ($trasError && old('tipo_gafete_id') === $nuevo) hidden @endunless>
                            <label class="campo-etiqueta" for="ga_ed_tipo_nuevo">Nombre del tipo nuevo</label>
                            <input type="text" id="ga_ed_tipo_nuevo" name="nombre_tipo_nuevo" class="campo" maxlength="50" placeholder="Ej: Capital Humano, RRHH, De Personas..."
                                   value="{{ $trasError ? old('nombre_tipo_nuevo') : '' }}" data-requerido-si='{"tipo_gafete_id":["{{ $nuevo }}"]}' @unless ($trasError && old('tipo_gafete_id') === $nuevo) disabled @endunless>
                        </div>

                        @include('componentes.lector', ['id' => 'ga_ed_nfc', 'etiqueta' => 'Etiqueta NFC / RFID (opcional)', 'modo' => 'capturar',
                            'nombre' => 'etiqueta_nfc', 'valor' => $trasError ? old('etiqueta_nfc') : '',
                            'ayuda' => 'Si el gafete trae chip o tarjeta, acércala al lector (o escribe su número) para que también se encuentre así. Déjalo vacío si no tiene.'])

                        <label class="campo-etiqueta" for="ga_ed_codigo">Código Interno <span class="text-lowercase fw-normal">(fijo, para QR/NFC)</span></label>
                        <input type="text" id="ga_ed_codigo" name="codigo_legible" class="campo campo-codigo" disabled>

                        <div class="dialogo-acciones">
                            <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                            <button type="submit" class="btn-gafetes">Guardar Cambios</button>
                        </div>
                    </form>
                    @include('componentes.borrar', ['registro' => 'gafetes', 'id' => $editandoId])
                </div>
            </dialog>
        @endif

        {{-- ===== Dar de baja con voucher ===== --}}
        @if ($puede['estado'])
            @php
                $trasError = $enBaja !== null;
                $cobro = $trasError && old('aplica_cobro');
            @endphp
            <dialog id="dialogoBajaGafete" class="dialogo" aria-labelledby="titulo-ga-baja" @if ($trasError) data-abrir-al-cargar @endif>
                <div class="dialogo-cabecera">
                    <h2 id="titulo-ga-baja"><i class="bi bi-x-circle me-2 text-danger" aria-hidden="true"></i>Dar de Baja: <span data-titulo-registro>{{ $enBaja?->nomenclatura }}</span></h2>
                    <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
                <div class="dialogo-cuerpo">
                    <form action="{{ $enBaja ? route('gafetes.baja', $enBaja->id) : '' }}" method="POST" autocomplete="off" data-form-gafete>
                        @csrf
                        <input type="hidden" name="_dialogo" value="{{ $enBaja ? 'baja-'.$enBaja->id : '' }}" data-campo-dialogo data-prefijo-dialogo="baja-">
                        <p class="campo-ayuda mt-0 mb-3">El gafete queda <strong>No disponible</strong> y se genera un <strong>voucher de reposición</strong> para imprimir y firmar.</p>

                        <label class="campo-etiqueta" for="ga_baja_motivo">Motivo</label>
                        <select id="ga_baja_motivo" name="motivo" class="campo" required>
                            @foreach (\App\Models\VoucherReposicion::MOTIVOS as $clave => $texto)
                                <option value="{{ $clave }}" @selected($trasError && old('motivo') === $clave) @if ($loop->first) data-por-defecto @endif>{{ $texto }}</option>
                            @endforeach
                        </select>

                        <label class="campo-etiqueta" for="ga_baja_descripcion">¿Cómo pasó? <span class="text-lowercase fw-normal">(ayuda a decidir si aplica cobro)</span></label>
                        <textarea id="ga_baja_descripcion" name="descripcion" class="campo" rows="3" maxlength="1000" placeholder="Describe brevemente lo ocurrido...">{{ $trasError ? old('descripcion') : '' }}</textarea>

                        <label class="casilla-cobro" for="ga_baja_cobro">
                            <input type="checkbox" id="ga_baja_cobro" name="aplica_cobro" value="1" @checked($cobro) data-muestra-si-marcado="#cajaCobroGafete">
                            Aplica CXC (se le cobra al responsable)
                        </label>

                        <div id="cajaCobroGafete" class="caja-cobro" @unless ($cobro) hidden @endunless>
                            <label class="campo-etiqueta" for="ga_baja_monto">Monto <span class="text-lowercase fw-normal">(se sugiere el último cobrado por este tipo; puedes cambiarlo)</span></label>
                            <div class="grupo-monto">
                                <span class="grupo-monto-simbolo" aria-hidden="true">$</span>
                                <input type="number" id="ga_baja_monto" name="monto" class="grupo-monto-input" step="0.01" min="0" max="999999.99" inputmode="decimal" value="{{ $trasError ? old('monto') : '' }}" data-requerido-si-marcado>
                            </div>
                            @include('componentes.lector', ['id' => 'ga_baja_responsable', 'etiqueta' => 'Colaborador Responsable', 'tipos' => 'colaborador',
                                'nombre' => 'colaborador_id', 'valor' => $trasError ? old('colaborador_id') : '', 'elegido' => $trasError ? $responsableAnterior : null,
                                'ayuda' => 'Escribe su número de empleado y presiona Enter, o escanea / acerca su credencial.'])
                        </div>

                        <div class="dialogo-acciones">
                            <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                            <button type="submit" class="btn-gafetes">Generar Voucher y Dar de Baja</button>
                        </div>
                    </form>
                </div>
            </dialog>
        @endif

        @include('componentes.codigo-identificacion')
    @endif
</div>
@endsection
