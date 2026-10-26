@extends('layouts.app')

@section('titulo', 'Préstamo de Llaves')

@section('contenido')
<div class="pantalla-prestamo-llaves">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    @if ($sinEmpresa)
        <div class="encabezado-pantalla mb-4">
            <div class="icono"><i class="bi bi-key-fill text-warning" aria-hidden="true"></i></div>
            <div><h1>Bitácora de Llaves</h1><p>Registro operativo, préstamos y devoluciones.</p></div>
        </div>
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-buildings" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong> para ver su bitácora de llaves.</p>
        </div>
    @else
        @php
            $variasSedes = $sedesFiltro->count() > 1 || $sedesPrestar->count() > 1;
            $trasError = old('_dialogo') === 'prestar';
        @endphp

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
            <div class="encabezado-pantalla m-0">
                <div class="icono"><i class="bi bi-key-fill text-warning" aria-hidden="true"></i></div>
                <div>
                    <h1>Bitácora de Llaves</h1>
                    <p>Registro operativo, préstamos y devoluciones.</p>
                    <p class="texto-padron-de"><i class="bi bi-building-check" aria-hidden="true"></i> Bitácora de: <strong>{{ $empresaNombre }}</strong></p>
                </div>
            </div>
            <div class="acciones-prestamo">
                @if ($puede['exportar'])
                    <a href="{{ route('prestamo_llaves.exportar') }}" class="btn-excel-llaves" data-exportar-prestamos data-base="{{ route('prestamo_llaves.exportar') }}">
                        <i class="bi bi-file-earmark-excel me-1" aria-hidden="true"></i>Excel (Auditoría)
                    </a>
                @endif
                @if ($puede['prestar'])
                    <button type="button" class="btn-prestar-llave" data-abrir-dialogo="dialogoPrestarLlave">
                        <i class="bi bi-key me-1" aria-hidden="true"></i>Prestar Llave
                    </button>
                @endif
            </div>
        </div>

        {{-- Mejoras activos (ADR-0014): recibir una llave escaneándola, sin buscar su ficha (como «Dar salida» en accesos) --}}
        @if ($puede['recibir'])
            <form action="{{ route('prestamo_llaves.recibir-escaneada') }}" method="POST" autocomplete="off" class="recibir-llave-escaneo" data-recibir-llave-escaneo>
                @csrf
                <div class="flex-fill">
                    @include('componentes.lector', ['id' => 'recibir_llave', 'modo' => 'capturar', 'nombre' => 'lectura', 'camara' => true,
                        'etiqueta' => 'Recibir: escanea la llave que te devuelven', 'placeholder' => 'Escanea el llavero o escribe su nombre (HDC-101)',
                        'ayuda' => 'Con el QR, la etiqueta NFC o el lector se recibe sola. Si la escribes, toca «Recibir».'])
                </div>
                <button type="submit" class="btn-recibir-llave btn-recibir-escaneo"><i class="bi bi-box-arrow-in-down me-1" aria-hidden="true"></i>Recibir</button>
            </form>
        @endif
        {{-- Fin Mejoras activos --}}

        <div class="pestanas-prestamo" role="tablist" aria-label="Préstamos">
            <button type="button" role="tab" class="activa" aria-selected="true" data-pestana-prestamos="uso">
                Llaves en Uso (<span data-conteo-en-uso>{{ $enUso->count() + $noRegresaron->count() }}</span>)
            </button>
            <button type="button" role="tab" aria-selected="false" data-pestana-prestamos="historial">Historial de Entregas</button>
        </div>

        <div class="filtros-prestamo">
            <div class="buscador">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="search" placeholder="Buscar por colaborador, nómina o llave..." aria-label="Buscar por colaborador, nómina o llave" autocomplete="off" data-filtro-prestamos="texto">
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

        {{-- ===== Llaves en Uso ===== --}}
        <div class="fichas-grid fichas-prestamo" data-vista-prestamos="uso">
            <div class="estado-vacio-prestamo" data-vacio-en-uso @if ($enUso->isNotEmpty() || $noRegresaron->isNotEmpty()) hidden @endif>
                <i class="bi bi-check2-circle" aria-hidden="true"></i> Todas las llaves están en caseta.
            </div>
            @foreach ($noRegresaron as $p)
                @include('operacion.prestamo-llaves._ficha', [
                    'p' => $p,
                    'puedeRecibir' => $puede['recibir'] && ($recibibles === null || in_array($p->id, $recibibles, true)),
                    'puedeAnular' => false,
                    'semaforoPrestamo' => null,
                ])
            @endforeach
            @foreach ($enUso as $p)
                @include('operacion.prestamo-llaves._ficha', [
                    'p' => $p,
                    'puedeRecibir' => $puede['recibir'] && ($recibibles === null || in_array($p->id, $recibibles, true)),
                    'puedeAnular' => $puede['anular'] && ($anulables === null || in_array($p->id, $anulables, true)),
                    'semaforoPrestamo' => $semaforo[$p->id] ?? null,
                ])
            @endforeach
        </div>

        {{-- ===== Historial de Entregas (devueltas y anuladas) ===== --}}
        <div class="fichas-grid fichas-prestamo" data-vista-prestamos="historial" hidden>
            @forelse ($historial as $p)
                @php
                    $nombre = $p->nombreReceptor();
                    $texto = mb_strtolower(implode(' ', array_filter([$nombre, $p->colaborador?->num_empleado, $p->llave?->nomenclatura, $p->llave?->descripcion, $p->sede?->nombre])));
                    $reactivable = $p->anulado && $puede['anular'] && ($anulables === null || in_array($p->id, $anulables, true));
                @endphp
                <div class="ficha-card ficha-prestamo {{ $p->anulado ? 'anulada' : 'dentro' }}" id="prestamo-{{ $p->id }}" data-ficha-prestamo data-sede="{{ $p->sede_id }}" data-texto="{{ $texto }}">
                    <div>
                        <div class="d-flex justify-content-between align-items-center gap-2 mb-2">
                            @if ($p->anulado)
                                <span class="badge-prestamo anulada"><i class="bi bi-slash-circle me-1" aria-hidden="true"></i>ANULADO</span>
                            @elseif ($p->estado === \App\Models\PrestamoLlave::NO_REGRESO)
                                <span class="badge-prestamo no-regreso"><i class="bi bi-exclamation-octagon-fill me-1" aria-hidden="true"></i>NO REGRESÓ</span>
                            @else
                                <span class="badge-prestamo dentro"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>EN CASETA</span>
                            @endif
                            <div class="d-flex align-items-center gap-2">
                                <span class="folio-prestamo">{{ $p->folio() }}</span>
                                <button type="button" class="btn-historial-llave suelto" title="Ver historial" aria-label="Ver historial de la llave {{ $p->llave?->nomenclatura }}"
                                        data-historial-llave="{{ route('prestamo_llaves.historial', $p->llave_id) }}">
                                    <i class="bi bi-clock-history" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>
                        <div class="prestamo-llave-titulo">{{ $p->llave?->nomenclatura }}</div>
                        <h2 class="prestamo-usada-por">Usada por: {{ $nombre }}</h2>
                        <div class="prestamo-detalle">
                            <div><strong>Garantía:</strong> {{ $p->textoGarantia() }}</div>
                            <div><strong>Salió:</strong> @fecha($p->prestado_en) <span class="text-muted">({{ $p->entrego?->name ?? '—' }})</span></div>
                            @if ($p->anulado)
                                <div><strong>Anulado:</strong> @fecha($p->anulado_en) <span class="text-muted">({{ $p->anulo?->name ?? '—' }})</span></div>
                            @elseif ($p->devuelto_en)
                                <div><strong>Regresó:</strong> @fecha($p->devuelto_en) <span class="text-muted">({{ $p->recibio?->name ?? '—' }})</span></div>
                            @endif
                            @if ($variasSedes)
                                <div><i class="bi bi-geo-alt-fill me-1 text-danger" aria-hidden="true"></i>{{ $p->sede?->nombre }}</div>
                            @endif
                        </div>
                    </div>
                    @if ($reactivable)
                        <div class="prestamo-acciones">
                            <form action="{{ route('prestamo_llaves.reactivar', $p->id) }}" method="POST" class="flex-grow-1 m-0" data-confirmar="¿Reactivar este préstamo anulado? Volverá a contar como válido.">
                                @csrf
                                @method('PATCH')
                                <button type="submit" class="btn-reactivar-prestamo"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Reactivar</button>
                            </form>
                        </div>
                    @endif
                </div>
            @empty
                <div class="estado-vacio-prestamo"><i class="bi bi-inbox" aria-hidden="true"></i> Todavía no hay entregas en el historial.</div>
            @endforelse
        </div>

        {{-- ===== Prestar Llave (Registrar y Capturar Siguiente) ===== --}}
        @if ($puede['prestar'])
            <dialog id="dialogoPrestarLlave" class="dialogo dialogo-prestar" aria-labelledby="titulo-prestar-llave" @if ($trasError || $llavePrestar) data-abrir-al-cargar @endif>
                <div class="dialogo-cabecera">
                    <h2 id="titulo-prestar-llave"><i class="bi bi-key-fill me-2 text-warning" aria-hidden="true"></i>Prestar Llave <span class="badge-escaner"><i class="bi bi-upc-scan me-1" aria-hidden="true"></i>Escáner Listo</span></h2>
                    <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
                <div class="dialogo-cuerpo">
                    <div class="alert alert-success aviso mb-3" role="status" data-errores-prestamo-ok hidden></div>
                    <div class="alert alert-danger aviso mb-3" role="alert" data-errores-prestamo hidden></div>
                    <p class="contador-sesion" data-contador-prestamos hidden><i class="bi bi-stack me-1" aria-hidden="true"></i><span data-numero-prestamos>0</span> préstamo(s) registrados en esta sesión — el cuadro sigue abierto, captura al siguiente.</p>

                    <form action="{{ route('prestamo_llaves.store') }}" method="POST" autocomplete="off" data-form-prestamo
                          data-llaves-fuera="{{ json_encode($llavesFuera) }}">
                        @csrf
                        <input type="hidden" name="_dialogo" value="prestar">

                        <label class="campo-etiqueta" for="prestamo_sede">Sede</label>
                        <select id="prestamo_sede" name="sede_id" class="campo" required data-sede-prestamo>
                            @if ($sedesPrestar->count() > 1)
                                <option value="">-- Seleccionar --</option>
                            @endif
                            @foreach ($sedesPrestar as $s)
                                <option value="{{ $s->id }}" @selected((string) old('sede_id', $llavePrestar?->sede_id ?? $sedeSugerida) === (string) $s->id) @if ($sedeSugerida === $s->id) data-por-defecto @endif>{{ $s->nombre }}</option>
                            @endforeach
                        </select>

                        <div class="lector-llave-prestamo">
                            @include('componentes.lector', ['id' => 'prestamo_llave', 'etiqueta' => 'Llave a Prestar (Buscar, QR o NFC)',
                                'tipos' => 'llave', 'nombre' => 'llave_id', 'requerido' => true,
                                'valor' => $llavePrestar?->id, 'elegido' => $llavePrestar?->nomenclatura,
                                'ayuda' => 'Escanea el QR del llavero, acerca su etiqueta NFC o escribe su nombre (por ejemplo HDC-101).'])
                        </div>

                        {{-- C-16: también a una persona del padrón (técnico de un proveedor, contratista) o a una empresa externa --}}
                        @include('componentes.receptor', ['id' => 'prestamo_colaborador', 'prefijo' => 'receptor', 'etiqueta' => 'Solicitante (¿a quién se le presta?)',
                            'requerido' => true, 'ayuda' => 'Escanea su gafete o escribe su número de nómina o su nombre.'])

                        <hr class="separador-prestamo">

                        <div class="campo-grupo campo-grupo-garantia">
                            <div class="flex-fill">
                                <label class="campo-etiqueta etiqueta-garantia" for="prestamo_garantia"><i class="bi bi-person-vcard me-1" aria-hidden="true"></i>ID Dejada en Garantía</label>
                                <select id="prestamo_garantia" name="tipo_garantia" class="campo" required>
                                    @foreach (\App\Models\PrestamoLlave::GARANTIAS_OPCIONES as $clave => $texto)
                                        <option value="{{ $clave }}" @selected(old('tipo_garantia', 'gafete_interno') === $clave) @if ($clave === 'gafete_interno') data-por-defecto @endif>{{ $texto }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="flex-fill">
                                <label class="campo-etiqueta" for="prestamo_folio">Folio / Detalles ID (Opcional)</label>
                                <input type="text" id="prestamo_folio" name="folio_garantia" class="campo" maxlength="80" placeholder="Ej. Depto Ama de Llaves" value="{{ old('folio_garantia') }}" data-folio-garantia>
                                <p class="campo-ayuda mt-0" data-ayuda-folio-gafete>Con «Gafete interno» se llena solo con el número del gafete que escaneaste.</p>
                            </div>
                        </div>

                        {{-- C-17 (DD-09): la llave no cumple sus reglas → aviso y motivo; queda en el préstamo y en la auditoría --}}
                        <div class="aviso-excepcion-llave" data-excepcion-llave role="alert" hidden>
                            <p class="mb-2"><i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i><span data-excepcion-texto></span></p>
                            <label class="campo-etiqueta" for="prestamo_excepcion">Prestar de todos modos: motivo</label>
                            <textarea id="prestamo_excepcion" name="excepcion_motivo" class="campo" rows="2" maxlength="250" disabled
                                      placeholder="Ej: lo autorizó el gerente de turno por teléfono"></textarea>
                        </div>

                        <div class="dialogo-acciones">
                            <button type="button" class="btn-cancelar btn-cerrar-prestamo" data-cerrar-dialogo>Cerrar</button>
                            <button type="submit" class="btn-registrar-prestamo" data-texto-original="Registrar y Capturar Siguiente"><i class="bi bi-key me-2" aria-hidden="true"></i><span>Registrar y Capturar Siguiente</span></button>
                        </div>
                    </form>
                </div>
            </dialog>
        @endif

        {{-- ===== Historial de una llave (se llena al tocar el reloj) ===== --}}
        <dialog id="dialogoHistorialLlave" class="dialogo dialogo-historial" aria-label="Historial de movimientos de la llave"
                data-url-historial="{{ route('prestamo_llaves.historial', 0) }}">
            <div data-contenido-historial></div>
        </dialog>
    @endif
</div>
@endsection
