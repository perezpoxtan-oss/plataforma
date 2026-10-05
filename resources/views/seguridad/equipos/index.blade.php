@extends('layouts.app')

@section('titulo', 'Equipos de Seguridad')

@section('contenido')
<div class="tema-pizarra pantalla-equipos">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    @if ($sinEmpresa)
        <div class="encabezado-pantalla mb-4">
            <div class="icono"><i class="bi bi-box-seam text-dark" aria-hidden="true"></i></div>
            <div><h1>Catálogo de Equipo de Seguridad</h1><p>Radios, lámparas tácticas, fornituras y demás equipo de guardia.</p></div>
        </div>
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-buildings" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong> para ver su equipo de seguridad.</p>
        </div>
    @else
        @php
            $estados = \App\Models\Equipo::ESTADOS;
            $conteo = $equipos->countBy('estado');
            $dialogo = old('_dialogo');
            $editandoId = is_string($dialogo) && str_starts_with($dialogo, 'editar-') ? (int) substr($dialogo, 7) : null;
            $bajaId = is_string($dialogo) && str_starts_with($dialogo, 'baja-') ? (int) substr($dialogo, 5) : null;
            $bajaEquipo = $bajaId ? $equipos->firstWhere('id', $bajaId) : null;
            $seriesExistentes = json_encode($equipos->pluck('numero_serie')->values());
        @endphp

        <div class="d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-3">
            <div class="encabezado-pantalla m-0">
                <div class="icono"><i class="bi bi-box-seam text-dark" aria-hidden="true"></i></div>
                <div>
                    <h1>Catálogo de Equipo de Seguridad</h1>
                    <p>Radios, lámparas tácticas, fornituras y demás equipo de guardia — prestable, se asigna a cada colaborador.</p>
                    <p class="texto-padron-de"><i class="bi bi-building-check" aria-hidden="true"></i> Inventario de: <strong>{{ $empresaNombre }}</strong></p>
                </div>
            </div>
            <div class="filtros-equipos">
                @if ($sedesFiltro->count() > 1)
                    <select class="filtro-select" aria-label="Filtrar por sede" data-filtro-equipos="sede">
                        <option value="">Todas las sedes</option>
                        @foreach ($sedesFiltro as $s)
                            <option value="{{ $s->id }}">{{ $s->nombre }}</option>
                        @endforeach
                    </select>
                @endif
                @if ($tiposFiltro->count() > 1)
                    <select class="filtro-select" aria-label="Filtrar por tipo de equipo" data-filtro-equipos="tipo">
                        <option value="">Todos los tipos</option>
                        @foreach ($tiposFiltro as $t)
                            <option value="{{ $t->id }}">{{ $t->nombre }}</option>
                        @endforeach
                    </select>
                @endif
                <div class="buscador">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="search" placeholder="Buscar por Serie, Tipo, Marca, Sede..." aria-label="Buscar equipo" data-filtro-equipos="texto">
                </div>
            </div>
        </div>

        <div class="pildoras-tipo" role="group" aria-label="Filtrar por estado">
            <button type="button" class="btn-pill-tipo active" data-filtro-estado-equipo="" aria-pressed="true">Cualquier estado <span class="conteo-pill">{{ $equipos->count() }}</span></button>
            @foreach (['disponible' => 'Disponible', 'asignado' => 'Asignado', 'en_mantenimiento' => 'En Mantenimiento', 'baja' => 'Baja/Perdido'] as $clave => $texto)
                <button type="button" class="btn-pill-tipo" data-filtro-estado-equipo="{{ $clave }}" aria-pressed="false">{{ $texto }} <span class="conteo-pill">{{ $conteo[$clave] ?? 0 }}</span></button>
            @endforeach
        </div>

        <div class="fichas-grid" data-equipos>
            @if ($puede['crear'])
                <button type="button" class="ficha-card ficha-create" data-abrir-dialogo="dialogoNuevoEquipo">
                    <i class="bi bi-plus-circle-fill" aria-hidden="true"></i>
                    <span class="h6 fw-bold m-0 mt-2 titulo-crear">Nuevo Equipo</span>
                </button>
            @endif

            @forelse ($equipos as $e)
                @php
                    $editable = $puede['editar'] && ($editables === null || in_array($e->id, $editables, true));
                    $desactivable = $puede['baja'] && ($desactivables === null || in_array($e->id, $desactivables, true));
                    $marcaModelo = trim(($e->marca ?? '').' '.($e->modelo ?? ''));
                    $nombre = trim(($e->tipo->nombre ?? 'Equipo').' '.$e->numero_serie);
                    $valores = json_encode($e->only(['sede_id', 'tipo_equipo_id', 'marca', 'modelo', 'numero_serie', 'costo', 'observaciones', 'etiqueta_nfc', 'estado']));
                    $texto = mb_strtolower(implode(' ', array_filter([$e->numero_serie, $e->tipo->nombre ?? null, $e->marca, $e->modelo, $e->sede->nombre ?? null, $estados[$e->estado] ?? null, $e->observaciones])));
                @endphp
                <div class="ficha-card ficha-equipo {{ $e->estado === 'baja' ? 'inactiva' : '' }}" id="equipo-{{ $e->id }}" data-equipo
                     data-sede="{{ $e->sede_id }}" data-tipo="{{ $e->tipo_equipo_id }}" data-estado="{{ $e->estado }}" data-texto="{{ $texto }}">
                    <div>
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                            <span class="badge-estado-eq estado-eq-{{ $e->estado }}"><i class="bi bi-circle-fill me-1" aria-hidden="true"></i>{{ $estados[$e->estado] ?? $e->estado }}</span>
                            @if ($editable)
                                <button type="button" class="btn-icono editar" title="Editar" aria-label="Editar equipo {{ $e->numero_serie }}"
                                        data-accion="editar-registro" data-dialogo="dialogoEditarEquipo"
                                        data-url="{{ route('equipos.update', $e->id) }}" data-id="{{ $e->id }}" data-valores="{{ $valores }}">
                                    <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                </button>
                            @endif
                        </div>

                        <div class="eq-rotulo mt-2">ID / NÚMERO DE SERIE</div>
                        <div class="eq-serie">{{ $e->numero_serie }}</div>
                        <div class="eq-tipo">{{ $e->tipo->nombre ?? '—' }}</div>
                        @if ($marcaModelo !== '')
                            <div class="eq-marca"><i class="bi bi-tag-fill me-1" aria-hidden="true"></i>{{ $marcaModelo }}</div>
                        @endif
                        @if ($e->observaciones)
                            <div class="eq-observaciones"><i class="bi bi-chat-left-text me-1" aria-hidden="true"></i>{{ $e->observaciones }}</div>
                        @endif
                        @if ($e->resguardoActual?->responsiva?->colaborador)
                            {{-- Responsivas: quién lo tiene ahora --}}
                            <div class="eq-resguardo"><i class="bi bi-person-badge me-1" aria-hidden="true"></i>A cargo de: <strong>{{ $e->resguardoActual->responsiva->colaborador->nombreCompleto() }}</strong> · {{ $e->resguardoActual->etiquetaModalidad() }} · {{ $e->resguardoActual->responsiva->folio }}</div>
                        @endif
                        @if ($e->etiqueta_nfc)
                            <div class="eq-nfc"><i class="bi bi-broadcast-pin me-1" aria-hidden="true"></i>Etiqueta NFC / RFID asignada</div>
                        @endif
                        @if ($e->actualizado_por_nombre && $e->updated_at?->ne($e->created_at))
                            <div class="texto-traza mt-2"><i class="bi bi-clock-history" aria-hidden="true"></i> Editado por {{ $e->actualizado_por_nombre }} · @fecha($e->updated_at)</div>
                        @elseif ($e->creado_por_nombre)
                            <div class="texto-traza mt-2"><i class="bi bi-plus-circle" aria-hidden="true"></i> Creado por {{ $e->creado_por_nombre }} · @fecha($e->created_at, 'd/m/Y')</div>
                        @endif
                    </div>

                    <div class="eq-pie">
                        <div class="small fw-bold"><i class="bi bi-building me-1 text-secondary" aria-hidden="true"></i>{{ $empresaNombre }}</div>
                        <div class="small mb-2"><i class="bi bi-geo-alt-fill me-1 text-danger" aria-hidden="true"></i>{{ $e->sede->nombre ?? '—' }}</div>
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                            <div class="d-flex gap-2">
                                <button type="button" class="btn-icono imprimir-qr" title="Ver código QR" aria-label="Ver código QR de {{ $e->numero_serie }}"
                                        data-ver-qr-equipo data-qr="{{ route('equipos.qr', $e->id) }}" data-nombre="{{ $nombre }}"
                                        data-enlace="{{ route('lector.ir', $e->codigo_qr) }}" data-imprimir="{{ $puede['imprimir'] ? route('equipos.etiqueta', $e->id) : '' }}">
                                    <i class="bi bi-qr-code" aria-hidden="true"></i>
                                </button>
                                @if ($puede['imprimir'])
                                    <a href="{{ route('equipos.etiqueta', $e->id) }}" target="_blank" rel="noopener" class="btn-icono imprimir-qr" title="Imprimir Etiqueta" aria-label="Imprimir etiqueta de {{ $e->numero_serie }}"><i class="bi bi-printer" aria-hidden="true"></i></a>
                                @endif
                            </div>
                            @if ($desactivable && $e->estado === 'baja')
                                <form action="{{ route('equipos.reactivar', $e->id) }}" method="POST" class="m-0" data-confirmar="¿Reactivar este equipo? Volverá a quedar DISPONIBLE.">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn-icono reactivar" title="Reactivar (se encontró / se recuperó)" aria-label="Reactivar {{ $e->numero_serie }}"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></button>
                                </form>
                            @elseif ($desactivable)
                                <button type="button" class="btn-icono eliminar" title="Dar de baja" aria-label="Dar de baja {{ $e->numero_serie }}"
                                        data-accion="editar-registro" data-dialogo="dialogoBajaEquipo" data-baja-nombre="{{ $nombre }}"
                                        data-url="{{ route('equipos.baja', $e->id) }}" data-id="{{ $e->id }}"
                                        data-valores="{{ json_encode(['motivo' => 'extraviado', 'descripcion' => '', 'aplica_cobro' => false, 'monto' => app(\App\Services\Equipos\AdministradorEquipos::class)->costoSugerido($e, $costosVoucher)]) }}">
                                    <i class="bi bi-slash-circle" aria-hidden="true"></i>
                                </button>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                @unless ($puede['crear'])
                    <div class="tarjeta estado-vacio sin-resultados">
                        <div class="icono"><i class="bi bi-box-seam" aria-hidden="true"></i></div>
                        <p class="text-muted small m-0">Todavía no hay equipos registrados{{ $editables !== null || $desactivables !== null ? ' en tus sedes' : '' }}.</p>
                    </div>
                @endunless
            @endforelse

            <div class="sin-resultados" data-sin-resultados-equipos hidden>
                <i class="bi bi-search d-block mb-2" aria-hidden="true"></i>
                <p class="fw-semibold m-0">No hay equipos que coincidan con tu búsqueda.</p>
            </div>
        </div>

        @if ($puede['crear'] || $puede['editar'])
            <datalist id="eq-marcas">@foreach ($sugerencias['marcas'] as $m)<option value="{{ $m }}"></option>@endforeach</datalist>
            <datalist id="eq-modelos">@foreach ($sugerencias['modelos'] as $m)<option value="{{ $m }}"></option>@endforeach</datalist>
        @endif

        {{-- ===== Alta y edición (comparten campos) ===== --}}
        @foreach (['nuevo' => $puede['crear'], 'editar' => $puede['editar']] as $modo => $permitido)
            @continue (! $permitido)
            @php
                $esNuevo = $modo === 'nuevo';
                $trasError = $esNuevo ? $dialogo === 'crear' : $editandoId !== null;
                $valor = fn (string $campo, string $porDefecto = '') => $trasError ? (string) old($campo, $porDefecto) : $porDefecto;
                $sedes = $esNuevo ? $sedesAlta : $sedesEdicion;
                // Al editar, la sede actual de un equipo puede estar desactivada: se conserva
                $sedesExtra = $esNuevo ? collect() : $sedesFiltro->whereNotIn('id', $sedes->pluck('id'));
                $unaSede = $esNuevo && $sedes->count() === 1 ? (string) $sedes->first()->id : '';
            @endphp
            <dialog id="{{ $esNuevo ? 'dialogoNuevoEquipo' : 'dialogoEditarEquipo' }}" class="dialogo ancho" aria-labelledby="titulo-eq-{{ $modo }}"
                    @if ($trasError) data-abrir-al-cargar @endif>
                <div class="dialogo-cabecera">
                    <h2 id="titulo-eq-{{ $modo }}"><i class="bi {{ $esNuevo ? 'bi-node-plus' : 'bi-pencil-square' }} me-2 text-dark" aria-hidden="true"></i>{{ $esNuevo ? 'Alta de Equipo' : 'Editar Equipo' }}</h2>
                    <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
                <div class="dialogo-cuerpo">
                    <form action="{{ $esNuevo ? route('equipos.store') : ($editandoId ? route('equipos.update', $editandoId) : '') }}" method="POST" autocomplete="off" data-form-equipo>
                        @csrf
                        @unless ($esNuevo) @method('PUT') @endunless
                        <input type="hidden" name="_dialogo" value="{{ $esNuevo ? 'crear' : ($editandoId ? 'editar-'.$editandoId : '') }}" data-campo-dialogo>
                        <p class="linea-empresa mb-3"><i class="bi bi-building-check me-1" aria-hidden="true"></i>Inventario de: <strong>{{ $empresaNombre }}</strong></p>

                        <label class="campo-etiqueta" for="{{ $modo }}_eq_sede">Sede de instalación</label>
                        <select id="{{ $modo }}_eq_sede" name="sede_id" class="campo" required>
                            <option value="" @if ($unaSede === '') data-por-defecto @endif>-- Seleccionar sede --</option>
                            @foreach ($sedes as $s)
                                <option value="{{ $s->id }}" @selected($valor('sede_id', $unaSede) === (string) $s->id) @if ($unaSede === (string) $s->id) data-por-defecto @endif>{{ $s->nombre }}</option>
                            @endforeach
                            @foreach ($sedesExtra as $s)
                                <option value="{{ $s->id }}" hidden @selected($valor('sede_id') === (string) $s->id)>{{ $s->nombre }} (no disponible para cambiar)</option>
                            @endforeach
                        </select>
                        @if ($esNuevo && $sedes->isEmpty())
                            <p class="campo-ayuda mb-3"><i class="bi bi-info-circle" aria-hidden="true"></i> No tienes sedes activas donde registrar equipo. Pide al administrador que te asigne una.</p>
                        @endif

                        <label class="campo-etiqueta" for="{{ $modo }}_eq_tipo">Tipo de equipo</label>
                        <select id="{{ $modo }}_eq_tipo" name="tipo_equipo_id" class="campo" required>
                            <option value="" data-por-defecto>-- Selecciona un tipo --</option>
                            @foreach ($tipos as $t)
                                @continue ($esNuevo && ! $t->activo)
                                <option value="{{ $t->id }}" @selected($valor('tipo_equipo_id') === (string) $t->id) @unless ($t->activo) hidden @endunless>{{ $t->nombre }}{{ $t->activo ? '' : ' (inactivo, se conserva)' }}</option>
                            @endforeach
                            <option value="{{ \App\Services\Equipos\AdministradorEquipos::TIPO_NUEVO }}" @selected($valor('tipo_equipo_id') === \App\Services\Equipos\AdministradorEquipos::TIPO_NUEVO)>+ Nuevo tipo...</option>
                        </select>
                        <div data-mostrar-si='{"tipo_equipo_id":["{{ \App\Services\Equipos\AdministradorEquipos::TIPO_NUEVO }}"]}'>
                            <label class="campo-etiqueta" for="{{ $modo }}_eq_tipo_nuevo">Nombre del nuevo tipo</label>
                            <input type="text" id="{{ $modo }}_eq_tipo_nuevo" name="nombre_tipo_nuevo" class="campo" maxlength="100" placeholder="Escribe el nuevo tipo de equipo... (ej: Chaleco antibalas)"
                                   value="{{ $valor('nombre_tipo_nuevo') }}" data-requerido-si='{"tipo_equipo_id":["{{ \App\Services\Equipos\AdministradorEquipos::TIPO_NUEVO }}"]}'>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <label class="campo-etiqueta" for="{{ $modo }}_eq_marca">Marca <span class="text-lowercase fw-normal">(opcional)</span></label>
                                <input type="text" id="{{ $modo }}_eq_marca" name="marca" class="campo text-uppercase" maxlength="80" placeholder="Ej: Motorola" list="eq-marcas"
                                       value="{{ $valor('marca') }}" data-costo-marca>
                            </div>
                            <div class="col-md-4">
                                <label class="campo-etiqueta" for="{{ $modo }}_eq_modelo">Modelo <span class="text-lowercase fw-normal">(opcional)</span></label>
                                <input type="text" id="{{ $modo }}_eq_modelo" name="modelo" class="campo text-uppercase" maxlength="80" placeholder="Ej: DEP 450" list="eq-modelos"
                                       value="{{ $valor('modelo') }}" data-costo-modelo>
                            </div>
                            <div class="col-md-4">
                                <label class="campo-etiqueta text-primary" for="{{ $modo }}_eq_serie">Núm. de Serie / ID</label>
                                <input type="text" id="{{ $modo }}_eq_serie" name="numero_serie" class="campo text-uppercase fw-bold mb-1" maxlength="100" placeholder="Ej: RAD-8829"
                                       value="{{ $valor('numero_serie') }}" autocapitalize="characters" data-series-existentes="{{ $seriesExistentes }}" required>
                                <p class="small mb-2" data-aviso-serie hidden></p>
                            </div>
                        </div>

                        <label class="campo-etiqueta" for="{{ $modo }}_eq_costo">Costo <span class="text-lowercase fw-normal" data-nota-costo>(para el voucher, si algún día se da de baja)</span></label>
                        <div class="grupo-monto">
                            <span class="grupo-monto-simbolo" aria-hidden="true">$</span>
                            <input type="number" step="0.01" min="0" max="99999999.99" inputmode="decimal" id="{{ $modo }}_eq_costo" name="costo" class="campo grupo-monto-campo" placeholder="0.00"
                                   value="{{ $valor('costo') }}" data-costos-equipo="{{ json_encode($sugerencias['costos'], JSON_UNESCAPED_UNICODE) }}">
                        </div>

                        @unless ($esNuevo)
                            <div data-estado-editable>
                                <label class="campo-etiqueta" for="editar_eq_estado">Estado actual <span class="text-lowercase fw-normal">(la baja se hace con el botón «Dar de baja», con voucher)</span></label>
                                <select id="editar_eq_estado" name="estado" class="campo">
                                    @foreach (\App\Models\Equipo::ESTADOS_EDITABLES as $clave)
                                        <option value="{{ $clave }}" @selected($valor('estado', 'disponible') === $clave)>{{ $estados[$clave] }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <p class="estado-fijo-eq" data-estado-fijo hidden></p>
                        @endunless

                        <label class="campo-etiqueta" for="{{ $modo }}_eq_obs">Observaciones <span class="text-lowercase fw-normal">(opcional)</span></label>
                        <input type="text" id="{{ $modo }}_eq_obs" name="observaciones" class="campo" maxlength="1000" placeholder="Condición inicial del activo..." value="{{ $valor('observaciones') }}">

                        @include('componentes.lector', ['id' => $modo.'_eq_nfc', 'etiqueta' => 'Etiqueta NFC / RFID (opcional)', 'modo' => 'capturar',
                            'nombre' => 'etiqueta_nfc', 'valor' => $valor('etiqueta_nfc'),
                            'ayuda' => 'Acerca la etiqueta o tarjeta pegada al equipo para que el lector la reconozca. Puedes dejarlo vacío: el equipo siempre se encuentra con su QR o su número de serie.'])

                        <div class="dialogo-acciones">
                            <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                            <button type="submit" class="btn-oscuro">{{ $esNuevo ? 'Registrar Equipo' : 'Actualizar Equipo' }}</button>
                        </div>
                    </form>
                </div>
            </dialog>
        @endforeach

        {{-- ===== Baja con voucher ===== --}}
        @if ($puede['baja'])
            <dialog id="dialogoBajaEquipo" class="dialogo" aria-labelledby="titulo-eq-baja" @if ($bajaEquipo) data-abrir-al-cargar @endif>
                <div class="dialogo-cabecera">
                    <h2 id="titulo-eq-baja"><i class="bi bi-slash-circle me-2 text-danger" aria-hidden="true"></i>Dar de Baja: <span data-baja-nombre>{{ $bajaEquipo ? trim(($bajaEquipo->tipo->nombre ?? '').' '.$bajaEquipo->numero_serie) : '' }}</span></h2>
                    <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
                <div class="dialogo-cuerpo">
                    <form action="{{ $bajaEquipo ? route('equipos.baja', $bajaEquipo->id) : '' }}" method="POST" autocomplete="off" data-form-baja-equipo>
                        @csrf
                        <input type="hidden" name="_dialogo" value="{{ $bajaEquipo ? 'baja-'.$bajaEquipo->id : '' }}" data-campo-dialogo data-prefijo-dialogo="baja-">
                        <p class="aviso-baja-eq"><i class="bi bi-receipt me-1" aria-hidden="true"></i>Se generará un <strong>voucher de reposición</strong> y el equipo quedará como <strong>BAJA/PERDIDO</strong>. Si aparece, lo reactivas con un clic.</p>

                        <label class="campo-etiqueta" for="baja_eq_motivo">Motivo</label>
                        <select id="baja_eq_motivo" name="motivo" class="campo" required>
                            @foreach (\App\Models\VoucherReposicion::MOTIVOS as $clave => $texto)
                                <option value="{{ $clave }}" @selected(($bajaEquipo ? old('motivo') : 'extraviado') === $clave) @if ($loop->first) data-por-defecto @endif>{{ $texto }}</option>
                            @endforeach
                        </select>

                        <label class="campo-etiqueta" for="baja_eq_descripcion">¿Cómo pasó? <span class="text-lowercase fw-normal">(ayuda a decidir si aplica cobro)</span></label>
                        <textarea id="baja_eq_descripcion" name="descripcion" class="campo" rows="3" maxlength="1000" placeholder="Describe brevemente lo ocurrido...">{{ $bajaEquipo ? old('descripcion') : '' }}</textarea>

                        <label class="casilla-cobro-eq" for="baja_eq_cobro">
                            <input type="checkbox" id="baja_eq_cobro" name="aplica_cobro" value="1" data-muestra-si-marcado="#cajaCobroEquipo" @checked($bajaEquipo && old('aplica_cobro'))>
                            Aplica CXC (se le cobra al responsable)
                        </label>

                        <div id="cajaCobroEquipo" class="caja-cobro-eq" hidden>
                            <label class="campo-etiqueta" for="baja_eq_monto">Monto <span class="text-lowercase fw-normal">(el costo capturado a este equipo, ajustable)</span></label>
                            <div class="grupo-monto">
                                <span class="grupo-monto-simbolo" aria-hidden="true">$</span>
                                <input type="number" step="0.01" min="0" max="999999.99" inputmode="decimal" id="baja_eq_monto" name="monto" class="campo grupo-monto-campo" placeholder="0.00"
                                       value="{{ $bajaEquipo ? old('monto') : '' }}" data-requerido-si-marcado="#baja_eq_cobro">
                            </div>
                            @include('componentes.lector', ['id' => 'baja_eq_responsable', 'etiqueta' => 'Colaborador responsable', 'tipos' => 'colaborador',
                                'nombre' => 'colaborador_id', 'valor' => $bajaEquipo ? old('colaborador_id') : '', 'elegido' => $bajaResponsable,
                                'ayuda' => 'Escanea su gafete o acerca su tarjeta; también puedes escribir su número de empleado o su nombre y oprimir Enter.'])
                        </div>

                        <div class="dialogo-acciones">
                            <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                            <button type="submit" class="btn-rojo">Generar Voucher y Dar de Baja</button>
                        </div>
                    </form>
                </div>
            </dialog>
        @endif

        {{-- ===== Ver QR ===== --}}
        <dialog id="dialogoQrEquipo" class="dialogo dialogo-qr-eq" aria-labelledby="titulo-eq-qr">
            <div class="dialogo-cuerpo text-center">
                <h2 class="h5 fw-bold mb-3" id="titulo-eq-qr" data-qr-nombre></h2>
                <img src="" alt="Código QR del equipo" width="220" height="220" class="qr-eq-imagen" data-qr-imagen>
                <p class="text-muted small mt-3 mb-1">Dirección vinculada (para grabar en la etiqueta NFC):</p>
                <p class="qr-eq-codigo" data-qr-enlace></p>
                <a href="#" target="_blank" rel="noopener" class="btn-qr-eq imprimir" data-qr-imprimir hidden><i class="bi bi-printer me-1" aria-hidden="true"></i>Imprimir Etiqueta</a>
                <button type="button" class="btn-qr-eq" data-cerrar-dialogo>Cerrar</button>
            </div>
        </dialog>
    @endif
</div>
@endsection
