@extends('layouts.app')

@section('titulo', 'Responsivas de Equipos')

@section('contenido')
<div class="pantalla-responsivas">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    @if ($sinEmpresa)
        <div class="encabezado-pantalla mb-4">
            <div class="icono"><i class="bi bi-pen-fill text-success" aria-hidden="true"></i></div>
            <div><h1>Control de Resguardos</h1><p>Lotes de asignación con firma digital y rastreo por Serie.</p></div>
        </div>
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-buildings" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong> para ver sus resguardos.</p>
        </div>
    @else
        @php
            $variasSedes = $sedesFiltro->count() > 1 || $sedesCrear->count() > 1;
            $trasError = old('_dialogo') === 'nuevo-resguardo';
            $filasPrevias = $trasError ? collect((array) old('equipos', []))->values() : collect();
            $modalidadesPrevias = $trasError ? array_values((array) old('modalidades', [])) : [];
            $textoEquipo = fn ($e) => '['.$e->numero_serie.'] '.trim(($e->tipo->nombre ?? 'Equipo').' '.trim(($e->marca ?? '').' '.($e->modelo ?? '')));
        @endphp

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
            <div class="encabezado-pantalla m-0">
                <div class="icono"><i class="bi bi-pen-fill text-success" aria-hidden="true"></i></div>
                <div>
                    <h1>Control de Resguardos</h1>
                    <p>Lotes de asignación con firma digital y rastreo por Serie.</p>
                    <p class="texto-padron-de"><i class="bi bi-building-check" aria-hidden="true"></i> Resguardos de: <strong>{{ $empresaNombre }}</strong></p>
                </div>
            </div>
            @if ($puede['crear'])
                <button type="button" class="btn-nuevo-resguardo" data-abrir-dialogo="dialogoNuevoResguardo">
                    <i class="bi bi-clipboard-plus me-1" aria-hidden="true"></i>Nuevo Resguardo (Lote)
                </button>
            @endif
        </div>

        <div class="pestanas-prestamo pestanas-responsivas" role="tablist" aria-label="Resguardos">
            <button type="button" role="tab" class="activa" aria-selected="true" data-pestana-prestamos="campo">Equipos en Campo ({{ $enCampo->count() }} Lotes)</button>
            <button type="button" role="tab" aria-selected="false" data-pestana-prestamos="devueltos">Historial Devueltos ({{ $devueltas->count() }} Lotes)</button>
        </div>

        <div class="filtros-prestamo">
            <div class="buscador">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="search" placeholder="Buscar por folio, colaborador o número de serie..." aria-label="Buscar resguardo" autocomplete="off" data-filtro-prestamos="texto">
            </div>
            @if ($sedesFiltro->count() > 1)
                <select class="filtro-select" aria-label="Filtrar por sede" data-filtro-prestamos="sede">
                    <option value="">Todas las sedes</option>
                    @foreach ($sedesFiltro as $s)
                        <option value="{{ $s->id }}">{{ $s->nombre }}</option>
                    @endforeach
                </select>
            @endif
        </div>
        <div class="sin-resultados" data-sin-resultados-prestamos hidden><i class="bi bi-search me-2" aria-hidden="true"></i>Sin resultados para esa búsqueda.</div>

        @foreach (['campo' => $enCampo, 'devueltos' => $devueltas] as $vista => $lotes)
            <div class="fichas-grid fichas-resguardo" data-vista-prestamos="{{ $vista }}" @if ($vista === 'devueltos') hidden @endif>
                @forelse ($lotes as $r)
                    @php
                        $activa = $r->enCampo();
                        $nombre = $r->colaborador?->nombreCompleto() ?? '—';
                        $texto = mb_strtolower(implode(' ', array_filter([$r->folio, $nombre, $r->colaborador?->num_empleado, $r->sede?->nombre,
                            $r->equipos->map(fn ($f) => ($f->equipo?->numero_serie ?? '').' '.($f->equipo?->tipo?->nombre ?? ''))->join(' ')])));
                        $recibible = $activa && $puede['recibir'] && ($recibibles === null || in_array($r->id, $recibibles, true));
                        $imprimible = $puede['imprimir'] && ($imprimibles === null || in_array($r->id, $imprimibles, true));
                    @endphp
                    <div class="ficha-card ficha-resguardo {{ $activa ? 'activa' : 'devuelta' }}" id="responsiva-{{ $r->id }}" data-ficha-prestamo data-sede="{{ $r->sede_id }}" data-texto="{{ $texto }}">
                        <div>
                            <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                                @if ($activa)
                                    <span class="badge-resguardo en-campo"><i class="bi bi-box-seam me-1" aria-hidden="true"></i>EN CAMPO</span>
                                @else
                                    <span class="badge-resguardo cerrado"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>LOTE CERRADO</span>
                                @endif
                                <span class="folio-resguardo {{ $activa ? '' : 'cerrado' }}">{{ $r->folio }}</span>
                            </div>

                            <div class="caja-resguardante">
                                <div class="rotulo-resguardo">{{ $activa ? 'Resguardante Activo:' : 'Resguardó en su momento:' }}</div>
                                <div class="nombre-resguardante"><i class="bi bi-person-badge me-1" aria-hidden="true"></i>{{ $nombre }}</div>
                                @if ($r->colaborador?->num_empleado)
                                    <div class="nomina-resguardante">No. Nómina: {{ $r->colaborador->num_empleado }}</div>
                                @endif
                            </div>

                            <div class="eq-lista">
                                @if ($activa)
                                    <div class="rotulo-resguardo">ACTIVOS VINCULADOS AL RESGUARDO:</div>
                                @endif
                                @foreach ($r->equipos as $f)
                                    <div class="eq-item">
                                        <div class="eq-item-texto">
                                            <div class="fw-bold"><i class="bi {{ $activa ? 'bi-check-circle-fill text-success' : 'bi-dot' }} me-1" aria-hidden="true"></i>{{ $f->equipo?->tipo?->nombre ?? 'Equipo' }}
                                                <span class="pastilla-modalidad {{ $f->modalidad }}">{{ $f->etiquetaModalidad() }}</span>
                                                @if ($f->estado_devolucion === 'baja')<span class="pastilla-modalidad baja">BAJA</span>@endif
                                            </div>
                                            <small class="eq-serie-resguardo">S/N: {{ $f->equipo?->numero_serie }}</small>
                                        </div>
                                        <button type="button" class="btn-historial-llave" title="Ver Historial del Equipo" aria-label="Ver historial del equipo {{ $f->equipo?->numero_serie }}"
                                                data-historial-llave="{{ route('responsivas.historial', $f->equipo_id) }}" data-dialogo-historial="dialogoHistorialEquipo">
                                            <i class="bi bi-clock-history" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                @endforeach
                            </div>

                            @if ($activa)
                                <div class="traza-resguardo"><strong>Entregó Caseta:</strong> {{ $r->entrego?->name ?? '—' }}<br>(@fecha($r->entregado_en))</div>
                            @else
                                <div class="traza-resguardo"><strong>Devuelto a Caseta:</strong> {{ $r->recibio?->name ?? '—' }}<br>(@fecha($r->devuelto_en))</div>
                            @endif
                            @if ($variasSedes)
                                <div class="traza-resguardo"><i class="bi bi-geo-alt-fill me-1 text-danger" aria-hidden="true"></i>{{ $r->sede?->nombre }}</div>
                            @endif
                        </div>

                        <div class="acciones-resguardo">
                            <div class="d-flex gap-2">
                                <button type="button" class="btn-resguardo-firma" data-ver-firma="{{ route('responsivas.firma', $r->id) }}" data-folio="{{ $r->folio }}" data-nombre="{{ $nombre }}">
                                    <i class="bi bi-vector-pen me-1" aria-hidden="true"></i>Firma
                                </button>
                                @if ($imprimible)
                                    <a href="{{ route('responsivas.hoja', $r->id) }}" target="_blank" rel="noopener" class="btn-resguardo-hoja">
                                        <i class="bi bi-printer me-1" aria-hidden="true"></i>{{ $activa ? 'Hoja' : 'Archivo' }}
                                    </a>
                                @endif
                            </div>
                            @if ($recibible)
                                <form action="{{ route('responsivas.recibir', $r->id) }}" method="POST" class="m-0" data-confirmar="¿Confirmar recepción de todos los equipos del lote {{ $r->folio }} en estado OK?">
                                    @csrf
                                    @method('PATCH')
                                    <button type="submit" class="btn-recibir-lote"><i class="bi bi-arrow-return-left me-1" aria-hidden="true"></i>Recibir Lote Completo (OK)</button>
                                </form>
                            @endif
                        </div>
                    </div>
                @empty
                    <div class="estado-vacio-prestamo">
                        @if ($vista === 'campo')
                            <i class="bi bi-check2-circle" aria-hidden="true"></i> No hay equipos prestados actualmente.
                        @else
                            <i class="bi bi-inbox" aria-hidden="true"></i> El historial está limpio.
                        @endif
                    </div>
                @endforelse
            </div>
        @endforeach

        {{-- ===== Nuevo Resguardo (Lote) ===== --}}
        @if ($puede['crear'])
            <dialog id="dialogoNuevoResguardo" class="dialogo dialogo-resguardo" aria-labelledby="titulo-nuevo-resguardo" @if ($trasError) data-abrir-al-cargar @endif>
                <div class="dialogo-cabecera">
                    <h2 id="titulo-nuevo-resguardo"><i class="bi bi-journal-arrow-up me-2 text-success" aria-hidden="true"></i>Nuevo Resguardo (Lote)</h2>
                    <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
                <div class="dialogo-cuerpo">
                    <div class="alert alert-danger aviso mb-3" role="alert" data-errores-resguardo hidden></div>
                    <form action="{{ route('responsivas.store') }}" method="POST" autocomplete="off" data-form-resguardo>
                        @csrf
                        <input type="hidden" name="_dialogo" value="nuevo-resguardo">

                        <div class="campo-grupo campo-grupo-resguardo">
                            <div class="flex-fill">
                                <label class="campo-etiqueta" for="resguardo_sede">Sede de Origen</label>
                                <select id="resguardo_sede" name="sede_id" class="campo" required data-sede-resguardo>
                                    @if ($sedesCrear->count() > 1)
                                        <option value="">-- Seleccionar Sede --</option>
                                    @endif
                                    @foreach ($sedesCrear as $s)
                                        <option value="{{ $s->id }}" @selected((string) old('sede_id', $sedeSugerida) === (string) $s->id) @if ($sedeSugerida === $s->id) data-por-defecto @endif>{{ $s->nombre }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        @include('componentes.lector', ['id' => 'resguardo_colaborador', 'etiqueta' => 'Colaborador (Responsable)',
                            'tipos' => 'colaborador', 'nombre' => 'colaborador_id', 'requerido' => true, 'valor' => $trasError ? old('colaborador_id') : null,
                            'elegido' => $colaboradorAnterior ? $colaboradorAnterior->nombreCompleto().' · Núm. '.$colaboradorAnterior->num_empleado : null,
                            'ayuda' => 'Escanea su gafete o escribe su número de nómina o su nombre.'])

                        <div class="caja-equipos-lote">
                            <div class="d-flex justify-content-between align-items-center gap-2 mb-2 flex-wrap">
                                <span class="campo-etiqueta m-0 etiqueta-equipos-lote"><i class="bi bi-box-seam me-1" aria-hidden="true"></i>Equipos a Resguardar</span>
                                <button type="button" class="btn-anadir-equipo" data-anadir-equipo><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>+ Añadir Equipo</button>
                            </div>
                            @include('componentes.lector', ['id' => 'resguardo_equipo_lector', 'tipos' => 'equipo', 'nombre' => '_equipo_leido',
                                'ayuda' => 'Escanea el QR o la etiqueta de cada equipo: se agrega solo a la lista. También puedes elegirlo de la lista.'])
                            <div class="filas-equipos" data-filas-equipos>
                                @foreach ($filasPrevias as $i => $id)
                                    @include('operacion.responsivas._fila', ['indice' => $i, 'seleccionado' => (string) $id, 'modalidad' => $modalidadesPrevias[$i] ?? 'prestado'])
                                @endforeach
                            </div>
                            <p class="campo-ayuda mt-2 mb-0 sin-equipos-sede" data-sin-equipos hidden>No hay equipos DISPONIBLES en esta sede.</p>
                        </div>

                        @include('componentes.firma', ['id' => 'firma_resguardo', 'nombre' => 'firma', 'etiqueta' => 'Firma Digital del Colaborador', 'requerido' => true,
                            'ayuda' => 'El colaborador firma con el dedo, un lápiz o el mouse. Con su firma acepta la responsabilidad de los equipos.'])

                        <div class="dialogo-acciones">
                            <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                            <button type="submit" class="btn-guardar-resguardo">Guardar Lote de Resguardo</button>
                        </div>
                    </form>
                    <template data-plantilla-fila-equipo>
                        @include('operacion.responsivas._fila', ['indice' => '__N__', 'seleccionado' => '', 'modalidad' => 'prestado'])
                    </template>
                </div>
            </dialog>
        @endif

        {{-- ===== Ver firma ===== --}}
        <dialog id="dialogoVerFirma" class="dialogo dialogo-firma-registrada" aria-labelledby="titulo-ver-firma">
            <div class="dialogo-cabecera">
                <h2 id="titulo-ver-firma" class="h6 m-0">Firma Registrada</h2>
                <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </div>
            <div class="dialogo-cuerpo text-center">
                <img alt="Firma del colaborador" class="firma-registrada" data-firma-imagen>
                <p class="small text-muted mt-2 mb-0"><span data-firma-nombre></span> <span data-firma-folio></span></p>
            </div>
        </dialog>

        {{-- ===== Historial de un equipo ===== --}}
        <dialog id="dialogoHistorialEquipo" class="dialogo dialogo-historial" aria-label="Historial del equipo">
            <div data-contenido-historial></div>
        </dialog>
    @endif
</div>
@endsection
