@extends('layouts.app')

@section('titulo', 'Catálogo de Llaves')

@section('contenido')
<div class="tema-indigo pantalla-llaves">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    @if ($sinEmpresa)
        <div class="encabezado-pantalla mb-4">
            <div class="icono"><i class="bi bi-key-fill text-indigo" aria-hidden="true"></i></div>
            <div><h1>Catálogo de Llaves</h1><p>Inventario maestro de accesos físicos y magnéticos.</p></div>
        </div>
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-buildings" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong> para ver su catálogo de llaves.</p>
        </div>
    @else
        @php
            $dialogo = old('_dialogo');
            $editandoId = is_string($dialogo) && str_starts_with($dialogo, 'editar-') ? (int) substr($dialogo, 7) : null;
            $bajaId = is_string($dialogo) && str_starts_with($dialogo, 'baja-') ? (int) substr($dialogo, 5) : null;
            // Ronda 5 (LL-03): el nombre se repite solo dentro de la misma sede
            $nombresExistentes = json_encode($llaves->groupBy('sede_id')->map(fn ($g) => $g->map(fn ($l) => mb_strtolower($l->nomenclatura))->values()), JSON_UNESCAPED_UNICODE);
            $conteoTipos = $llaves->countBy('tipo_dispositivo');
            $variasSedes = $sedesFiltro->count() > 1;
            $llaveBaja = $bajaId ? $llaves->firstWhere('id', $bajaId) : null;
        @endphp

        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-3">
            <div class="encabezado-pantalla m-0">
                <div class="icono"><i class="bi bi-key-fill text-indigo" aria-hidden="true"></i></div>
                <div>
                    <h1>Catálogo de Llaves</h1>
                    <p>Inventario maestro de accesos físicos y magnéticos.</p>
                    <p class="texto-padron-de"><i class="bi bi-building-check" aria-hidden="true"></i> Catálogo de: <strong>{{ $empresaNombre }}</strong></p>
                </div>
            </div>
            <div class="buscador buscador-llaves">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="search" placeholder="Buscar llave o área..." aria-label="Buscar llave o área" data-filtro-llaves="texto">
            </div>
        </div>

        <div class="barra-llaves" role="group" aria-label="Filtros del catálogo">
            @if ($variasSedes)
                <select class="filtro-select" aria-label="Filtrar por sede" data-filtro-llaves="sede">
                    <option value="">Todas las sedes</option>
                    @foreach ($sedesFiltro as $s)
                        <option value="{{ $s->id }}">{{ $s->nombre }}</option>
                    @endforeach
                </select>
            @endif
            <select class="filtro-select" aria-label="Filtrar por tipo de dispositivo" data-filtro-llaves="tipo">
                <option value="">Todos los tipos</option>
                @foreach (\App\Models\Llave::TIPOS_DISPOSITIVO as $clave => $texto)
                    <option value="{{ $clave }}">{{ $texto }} ({{ $conteoTipos[$clave] ?? 0 }})</option>
                @endforeach
            </select>
            <select class="filtro-select" aria-label="Filtrar por caducidad" data-filtro-llaves="caducidad">
                <option value="">Cualquier caducidad</option>
                <option value="vencida">Vencidas</option>
                <option value="pronto">Vencen pronto (30 días)</option>
                <option value="ok">Vigentes</option>
                <option value="sin">Sin fecha de caducidad</option>
            </select>
            <div class="filtros-estado-llaves" role="group" aria-label="Filtrar por estado">
                <button type="button" class="btn-pill-tipo active" data-filtro-llaves-estado="" aria-pressed="true">Todas</button>
                <button type="button" class="btn-pill-tipo" data-filtro-llaves-estado="1" aria-pressed="false">Activas</button>
                <button type="button" class="btn-pill-tipo" data-filtro-llaves-estado="0" aria-pressed="false">Bajas</button>
            </div>
        </div>

        @if ($puede['imprimir'] || $puede['exportar'])
            <div class="acciones-llaves">
                @if ($puede['imprimir'])
                    <button type="button" class="btn-accion-llaves" data-accion="llaves-marcar-todas" aria-pressed="false"><i class="bi bi-check-all" aria-hidden="true"></i> Todo</button>
                    <button type="submit" form="formEtiquetasLlaves" class="btn-accion-llaves oscuro" data-imprimir-llaves>
                        <i class="bi bi-tags-fill" aria-hidden="true"></i> Imprimir Etiquetas <span class="conteo-pill" data-conteo-llaves></span>
                    </button>
                @endif
                @if ($puede['exportar'])
                    <a href="{{ route('llaves.exportar') }}" class="btn-accion-llaves" data-exportar-llaves data-base="{{ route('llaves.exportar') }}"><i class="bi bi-file-earmark-spreadsheet" aria-hidden="true"></i> Exportar</a>
                @endif
            </div>
            @if ($puede['imprimir'])
                <form id="formEtiquetasLlaves" action="{{ route('llaves.imprimir') }}" method="GET" target="_blank" class="d-none"></form>
            @endif
        @endif

        <div class="fichas-grid" data-llaves>
            @if ($puede['crear'])
                <button type="button" class="ficha-card ficha-create" data-abrir-dialogo="dialogoNuevaLlave">
                    <i class="bi bi-plus-circle-fill" aria-hidden="true"></i>
                    <span class="h6 fw-bold m-0 mt-2 titulo-crear">Nueva Llave</span>
                </button>
            @endif

            @forelse ($llaves as $l)
                @php
                    $editable = $puede['editar'] && ($editables === null || in_array($l->id, $editables, true));
                    $desactivable = $puede['estado'] && ($desactivables === null || in_array($l->id, $desactivables, true));
                    $imprimible = $puede['imprimir'] && ($imprimibles === null || in_array($l->id, $imprimibles, true));
                    $caducidad = $l->caducidad();
                    $lugares = $l->lugares();
                    $responsable = $l->colaborador ? $l->colaborador->nombreCompleto().' · Núm. '.$l->colaborador->num_empleado : null;
                    $valores = json_encode([
                        'sede_id' => $l->sede_id, 'departamento_id' => $l->departamento_id, 'puesto_id' => $l->puesto_id,
                        'nomenclatura' => $l->nomenclatura, 'descripcion' => $l->descripcion, 'tipo_dispositivo' => $l->tipo_dispositivo,
                        'alcance' => $l->alcance, 'alcance_otro' => $l->alcance_otro, 'id_externo' => $l->id_externo,
                        'plataforma_externa' => $l->plataforma_externa, 'fecha_caducidad' => $l->fecha_caducidad?->format('Y-m-d'),
                        'etiqueta_nfc' => $l->etiqueta_nfc,
                        'costo_reposicion' => $l->costo_reposicion, 'costo_variable' => (bool) $l->costo_variable,
                        'espacios' => $l->espacios->pluck('id')->values(), 'grupos' => $l->grupos->pluck('id')->values(),
                        'horarios' => $l->horarios->map(fn ($h) => ['nombre' => $h->nombre, 'inicio' => $h->inicio(), 'fin' => $h->fin()])->values(),
                        'colaborador_id' => $l->colaborador_id, 'colaborador_texto' => $responsable,
                    ]);
                @endphp
                <div class="ficha-card ficha-llave {{ $l->activo ? '' : 'inactiva' }}" id="llave-{{ $l->id }}" data-llave
                     data-sede="{{ $l->sede_id }}" data-tipo="{{ $l->tipo_dispositivo }}" data-estado="{{ $l->activo ? 1 : 0 }}"
                     data-caducidad="{{ $caducidad['clase'] ?? 'sin' }}" data-texto="{{ \App\Http\Controllers\Seguridad\LlaveController::textoBusqueda($l) }}">
                    <div>
                        <div class="d-flex justify-content-between align-items-start gap-2">
                            <span class="badge-tipo-llave tipo-{{ $l->tipo_dispositivo }}">{{ $l->etiquetaTipo() }}</span>
                            <div class="d-flex align-items-center gap-2">
                                @if ($l->enUso())
                                    <span class="etiqueta-estado en-uso">EN USO</span>
                                @endif
                                <span class="etiqueta-estado {{ $l->activo ? 'activo' : 'inactivo' }}">{{ $l->activo ? 'ACTIVA' : 'BAJA' }}</span>
                                @if ($imprimible)
                                    <label class="chk-llave" title="Marcar para imprimir su etiqueta">
                                        <input type="checkbox" name="llaves[]" value="{{ $l->id }}" form="formEtiquetasLlaves" data-chk-llave>
                                        <span class="visually-hidden">Marcar {{ $l->nomenclatura }} para imprimir su etiqueta</span>
                                    </label>
                                @endif
                            </div>
                        </div>

                        <h2 class="llave-titulo">{{ $l->nomenclatura }}</h2>
                        <p class="llave-descripcion">{{ $l->descripcion }}</p>
                        @if ($responsable)
                            <p class="llave-responsable"><i class="bi bi-person-check-fill" aria-hidden="true"></i> Responsable: {{ $responsable }}</p>
                        @endif
                        {{-- Préstamo de llaves: quién la tiene ahora y su historial --}}
                        @if ($l->enUso() && $l->prestamoAbierto?->colaborador)
                            <p class="llave-usada-por"><i class="bi bi-key-fill" aria-hidden="true"></i> Usada por: {{ $l->prestamoAbierto->colaborador->nombreCompleto() }} · desde @fecha($l->prestamoAbierto->prestado_en, 'd/m H:i')</p>
                        @endif
                        @can('prestamo_llaves.ver')
                            <a class="llave-historial-enlace" href="{{ route('prestamo_llaves.index') }}#historial-llave-{{ $l->id }}"><i class="bi bi-clock-history" aria-hidden="true"></i> Historial de préstamos</a>
                        @endcan

                        <div class="mini-etiqueta" aria-hidden="true">
                            <div class="mini-etiqueta-barra tipo-{{ $l->tipo_dispositivo }}"></div>
                            <div class="mini-etiqueta-cuerpo">
                                <div class="mini-etiqueta-nombre">{{ $l->nomenclatura }}</div>
                                <div class="mini-etiqueta-sub">{{ $l->etiquetaAlcance() }}</div>
                            </div>
                            <div class="mini-etiqueta-qr"><i class="bi bi-qr-code"></i></div>
                        </div>

                        <div class="meta-llave">
                            <div><i class="bi bi-house-door" aria-hidden="true"></i> <strong>Sede:</strong> {{ $l->sede?->nombre ?? '—' }}</div>
                            <div><i class="bi bi-door-open" aria-hidden="true"></i> <strong>Abre:</strong>
                                {{ implode(', ', array_slice($lugares, 0, 4)) ?: 'Sin lugares asignados' }}@if (count($lugares) > 4) y {{ count($lugares) - 4 }} más @endif
                            </div>
                            @if ($l->departamento)
                                <div><i class="bi bi-diagram-3" aria-hidden="true"></i> <strong>Depto:</strong> {{ $l->departamento->nombre }}@if ($l->puesto) · {{ $l->puesto->nombre }}@endif</div>
                            @elseif ($l->puesto)
                                <div><i class="bi bi-person-badge" aria-hidden="true"></i> <strong>Puesto:</strong> {{ $l->puesto->nombre }}</div>
                            @endif
                            @if ($l->id_externo)
                                <div><i class="bi bi-upc-scan" aria-hidden="true"></i> <strong>ID Externo:</strong> {{ $l->id_externo }}@if ($l->plataforma_externa) <span class="text-muted">({{ $l->plataforma_externa }})</span>@endif</div>
                            @endif
                            @if ($l->etiqueta_nfc)
                                <div><i class="bi bi-broadcast-pin" aria-hidden="true"></i> <strong>Tarjeta NFC/RFID:</strong> asignada</div>
                            @endif
                            <div>
                                <i class="bi bi-clock-history" aria-hidden="true"></i> <strong>Horarios:</strong>
                                @forelse ($l->horarios as $h)
                                    <span class="mini-horario">{{ $h->nombre }} {{ $h->inicio() }}-{{ $h->fin() }}@if ($h->cruzaMedianoche()) <i class="bi bi-moon-stars" title="Termina al día siguiente" aria-label="termina al día siguiente"></i>@endif</span>
                                @empty
                                    24 horas (todos)
                                @endforelse
                            </div>
                            @if ($caducidad)
                                <div><span class="badge-caducidad {{ $caducidad['clase'] }}"><i class="bi bi-calendar-x" aria-hidden="true"></i> Caduca: @fecha($l->caducidadParaMostrar(), 'd/m/Y') ({{ $caducidad['texto'] }})</span></div>
                            @endif
                            @if ($l->actualizado_por_nombre && $l->updated_at?->ne($l->created_at))
                                <div class="texto-traza"><i class="bi bi-clock-history" aria-hidden="true"></i> Editada por {{ $l->actualizado_por_nombre }} · @fecha($l->updated_at)</div>
                            @elseif ($l->creado_por_nombre)
                                <div class="texto-traza"><i class="bi bi-plus-circle" aria-hidden="true"></i> Creada por {{ $l->creado_por_nombre }} · @fecha($l->created_at)</div>
                            @endif
                        </div>
                    </div>

                    <div class="ficha-footer">
                        <span class="pastilla-alcance">Alcance: {{ $l->etiquetaAlcance() }}</span>
                        <div class="d-flex gap-2">
                            {{-- Ronda 5 (LL-05): QR y etiqueta NFC/RFID en un diálogo, sin salir de la pantalla --}}
                            @include('componentes.boton-identificacion', ['identTipo' => 'llave', 'identRegistro' => $l, 'identTitulo' => $l->nomenclatura,
                                'identDetalle' => ($l->sede?->nombre ?? '').' · '.$l->etiquetaTipo(),
                                'identImprimir' => $imprimible ? route('llaves.imprimir', ['llaves' => [$l->id]]) : null, 'identImprimirTexto' => 'Imprimir etiqueta',
                                'identEditable' => $editable, 'identAbrir' => (int) session('identificacion') === $l->id])
                            @if ($desactivable)
                                @if ($l->activo)
                                    <button type="button" class="btn-icono eliminar" title="Dar de baja" aria-label="Dar de baja {{ $l->nomenclatura }}"
                                            data-accion="baja-llave" data-url="{{ route('llaves.baja', $l->id) }}" data-id="{{ $l->id }}"
                                            data-nombre="{{ $l->nomenclatura }}" data-tipo="{{ $l->tipo_dispositivo }}" data-tipo-texto="{{ $l->etiquetaTipo() }}"
                                            data-costo-fijo="{{ $l->costoFijo() }}" data-costo-propio="{{ $l->costo_reposicion }}">
                                        <i class="bi bi-slash-circle" aria-hidden="true"></i>
                                    </button>
                                @else
                                    <form action="{{ route('llaves.reactivar', $l->id) }}" method="POST" class="m-0" data-confirmar="¿Reactivar la llave {{ $l->nomenclatura }}?">
                                        @csrf
                                        @method('PATCH')
                                        <button type="submit" class="btn-icono reactivar" title="Reactivar" aria-label="Reactivar {{ $l->nomenclatura }}"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></button>
                                    </form>
                                @endif
                            @endif
                            @if ($editable)
                                <button type="button" class="btn-icono editar" title="Editar" aria-label="Editar llave {{ $l->nomenclatura }}"
                                        data-accion="editar-registro" data-dialogo="dialogoEditarLlave"
                                        data-url="{{ route('llaves.update', $l->id) }}" data-id="{{ $l->id }}" data-valores="{{ $valores }}">
                                    <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                </button>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <div class="tarjeta estado-vacio estado-vacio-llaves">
                    <div class="icono"><i class="bi bi-key" aria-hidden="true"></i></div>
                    <p class="text-muted small m-0">
                        Todavía no hay llaves registradas.
                        @if ($puede['crear']) Usa <strong>Nueva Llave</strong> para dar de alta la primera. @endif
                    </p>
                </div>
            @endforelse

            <div class="sin-resultados" data-sin-resultados-llaves hidden>
                <i class="bi bi-search d-block mb-2" aria-hidden="true"></i>
                <p class="fw-semibold m-0">No hay llaves que coincidan con tu búsqueda.</p>
            </div>
        </div>

        {{-- ===== Alta y edición (comparten campos) ===== --}}
        @foreach (['nueva' => $puede['crear'], 'editar' => $puede['editar']] as $modo => $permitido)
            @continue (! $permitido)
            @include('seguridad.llaves._formulario', [
                'modo' => $modo,
                'trasError' => $modo === 'nueva' ? $dialogo === 'crear' : $editandoId !== null,
                'editandoId' => $editandoId,
                'sedesFormulario' => $modo === 'nueva' ? $sedesAlta : $sedesEdicion,
                'nombresExistentes' => $nombresExistentes,
            ])
        @endforeach

        {{-- ===== Baja con voucher de reposición ===== --}}
        @if ($puede['estado'])
            <dialog id="dialogoBajaLlave" class="dialogo" aria-labelledby="titulo-baja-llave" data-costos="{{ json_encode($costos) }}"
                    @if ($llaveBaja) data-abrir-al-cargar @endif>
                <div class="dialogo-cabecera">
                    <h2 id="titulo-baja-llave"><i class="bi bi-slash-circle me-2 text-danger" aria-hidden="true"></i>Dar de Baja: <span data-baja-nombre>{{ $llaveBaja?->nomenclatura }}</span></h2>
                    <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
                <div class="dialogo-cuerpo">
                    <form action="{{ $llaveBaja ? route('llaves.baja', $llaveBaja->id) : '' }}" method="POST" autocomplete="off" data-form-baja-llave>
                        @csrf
                        <input type="hidden" name="_dialogo" value="{{ $llaveBaja ? 'baja-'.$llaveBaja->id : '' }}" data-campo-dialogo>
                        <p class="campo-ayuda mb-3"><i class="bi bi-receipt" aria-hidden="true"></i> Al dar de baja se genera un <strong>voucher de reposición</strong> con folio. La llave no se borra: podrás reactivarla después.</p>

                        <label class="campo-etiqueta" for="baja_motivo">Motivo</label>
                        <select id="baja_motivo" name="motivo" class="campo" required>
                            @foreach (\App\Models\VoucherReposicion::MOTIVOS as $clave => $texto)
                                <option value="{{ $clave }}" @selected(old('motivo', 'extraviado') === $clave) @if ($clave === 'extraviado') data-por-defecto @endif>{{ $texto }}</option>
                            @endforeach
                        </select>

                        <label class="campo-etiqueta" for="baja_descripcion">¿Cómo pasó? <span class="text-lowercase fw-normal">(ayuda a decidir si aplica cobro)</span></label>
                        <textarea id="baja_descripcion" name="descripcion" class="campo" rows="3" maxlength="1000" placeholder="Describe brevemente lo ocurrido...">{{ old('descripcion') }}</textarea>

                        <label class="opcion-cobro">
                            <input type="checkbox" name="aplica_cobro" value="1" data-cobro-llave @checked(old('aplica_cobro'))>
                            Aplica CXC (se le cobra al responsable)
                        </label>

                        <div class="caja-cobro" data-caja-cobro-llave @unless (old('aplica_cobro')) hidden @endunless>
                            <label class="campo-etiqueta" for="baja_monto">Monto <span class="text-lowercase fw-normal" data-nota-monto>(sugerido, puedes ajustarlo)</span></label>
                            <div class="grupo-monto">
                                <span class="grupo-monto-simbolo" aria-hidden="true">$</span>
                                <input type="number" step="0.01" min="0" max="999999.99" id="baja_monto" name="monto" class="campo grupo-monto-campo" inputmode="decimal" value="{{ old('monto') }}" data-monto-llave>
                            </div>
                            @include('componentes.lector', ['id' => 'baja_responsable', 'etiqueta' => 'Colaborador responsable',
                                'tipos' => 'colaborador', 'nombre' => 'colaborador_id', 'valor' => old('colaborador_id'),
                                'elegido' => $responsableAnterior ? $responsableAnterior->nombreCompleto().' · Núm. '.$responsableAnterior->num_empleado : null,
                                'ayuda' => 'Escanea su gafete o escribe su número de empleado.'])
                        </div>

                        @include('seguridad.vouchers._firmas-baja', ['id' => 'baja_llave'])

                        <div class="dialogo-acciones">
                            <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                            <button type="submit" class="btn-baja-llave">Generar Voucher y Dar de Baja</button>
                        </div>
                    </form>
                </div>
            </dialog>
        @endif

        @include('componentes.codigo-identificacion')
    @endif
</div>
@endsection
