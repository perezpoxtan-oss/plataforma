@extends('layouts.app')

@section('titulo', 'Turnos')

@section('contenido')
<div class="tema-indigo">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    @if ($sinEmpresa)
        <div class="encabezado-pantalla mb-4">
            <div class="icono"><i class="bi bi-clock-fill text-indigo" aria-hidden="true"></i></div>
            <div><h1>Turnos</h1><p>Horarios corporativos predefinidos.</p></div>
        </div>
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-buildings" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong> para ver sus turnos.</p>
        </div>
    @else
        @php
            $dialogo = old('_dialogo');
            $editandoId = is_string($dialogo) && str_starts_with($dialogo, 'editar-') ? (int) substr($dialogo, 7) : null;
            $sedesDeId = is_string($dialogo) && str_starts_with($dialogo, 'sedes-') ? (int) substr($dialogo, 6) : null;
            $existentes = json_encode($turnos->map(fn ($t) => mb_strtolower($t->nombre))->values());
            $variasSedes = $sedes->count() > 1;
            $idsActivas = $sedes->pluck('id');
        @endphp

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
            <div class="encabezado-pantalla m-0">
                <div class="icono"><i class="bi bi-clock-fill text-indigo" aria-hidden="true"></i></div>
                <div>
                    <h1>Turnos</h1>
                    <p>Horarios corporativos predefinidos.</p>
                </div>
            </div>
            @if ($turnos->count() > 1 || $variasSedes)
                <div class="barra-filtros justify-content-md-end">
                    @if ($variasSedes)
                        <select class="filtro-select" aria-label="Filtrar por sede" data-filtro-sede="turnos">
                            <option value="">Todas las sedes</option>
                            @foreach ($sedes as $sede)
                                <option value="{{ $sede->id }}">{{ $sede->nombre }}</option>
                            @endforeach
                        </select>
                    @endif
                    <div class="buscador">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <input type="search" placeholder="Buscar turno..." aria-label="Buscar turno" data-filtro-texto="turnos">
                    </div>
                </div>
            @endif
        </div>

        <div class="fichas-grid" data-fichas="turnos">
            @if ($puede['crear'])
                <button type="button" class="ficha-card ficha-create" data-abrir-dialogo="dialogoNuevoTurno">
                    <i class="bi bi-plus-circle-fill" aria-hidden="true"></i>
                    <span class="h6 fw-bold m-0 mt-2 titulo-crear">Nuevo Turno</span>
                </button>
            @endif

            @forelse ($turnos as $t)
                @php
                    $sedesActivas = $t->sedes->where('activo', true);
                    $usaSedes = $t->todas_las_sedes ? $idsActivas : $sedesActivas->pluck('id');
                    $etiquetaSedes = $t->todas_las_sedes ? 'Todas las sedes' : $sedesActivas->count().' sede'.($sedesActivas->count() !== 1 ? 's' : '');
                    $valores = json_encode(['nombre' => $t->nombre, 'hora_inicio' => $t->inicio(), 'hora_fin' => $t->fin()]);
                @endphp
                <div class="ficha-card" id="turno-{{ $t->id }}" data-ficha data-estado="{{ $t->activo ? 1 : 0 }}"
                     data-sede="{{ $usaSedes->join(' ') }}" data-texto="{{ mb_strtolower($t->nombre.' '.$t->inicio().' '.$t->fin()) }}">
                    <div>
                        <h2 class="ficha-title">{{ $t->nombre }}</h2>
                        <div class="turno-horario">
                            <span><i class="bi bi-clock" aria-hidden="true"></i> {{ $t->inicio() }} a {{ $t->fin() }} · {{ $t->duracion() }}</span>
                            @if ($t->cruzaMedianoche())
                                <span class="turno-medianoche"><i class="bi bi-moon-stars" aria-hidden="true"></i> Termina al día siguiente</span>
                            @endif
                        </div>
                        @if ($puede['sedes'])
                            <button type="button" class="btn-sedes-turno" data-abrir-dialogo="dialogoSedesTurno{{ $t->id }}" aria-label="Sedes que usan {{ $t->nombre }}: {{ $etiquetaSedes }}">
                                <i class="bi bi-signpost-split" aria-hidden="true"></i> {{ $etiquetaSedes }}
                            </button>
                        @elseif ($variasSedes || ! $t->todas_las_sedes)
                            <div class="small text-muted mt-2"><i class="bi bi-signpost-split" aria-hidden="true"></i>
                                {{ $t->todas_las_sedes ? 'Todas las sedes' : 'Solo en: '.($sedesActivas->pluck('nombre')->join(', ') ?: 'ninguna sede') }}
                            </div>
                        @endif
                        @if ($t->creado_por_nombre)
                            <div class="texto-traza mt-2"><i class="bi bi-plus-circle" aria-hidden="true"></i> Creado por {{ $t->creado_por_nombre }} · @fecha($t->created_at)</div>
                        @endif
                        @if ($t->actualizado_por_nombre && $t->updated_at?->ne($t->created_at))
                            <div class="texto-traza"><i class="bi bi-clock-history" aria-hidden="true"></i> Editado por {{ $t->actualizado_por_nombre }} · @fecha($t->updated_at)</div>
                        @endif
                    </div>
                    <div class="ficha-footer">
                        <span class="etiqueta-estado {{ $t->activo ? 'activo' : 'inactivo' }}">{{ $t->activo ? 'ACTIVO' : 'INACTIVO' }}</span>
                        <div class="d-flex gap-2">
                            @if ($puede['editar'])
                                <button type="button" class="btn-icono editar" title="Editar" aria-label="Editar {{ $t->nombre }}"
                                        data-accion="editar-registro" data-dialogo="dialogoEditarTurno"
                                        data-url="{{ route('turnos.update', $t->id) }}" data-id="{{ $t->id }}" data-valores="{{ $valores }}">
                                    <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                </button>
                            @endif
                            @if ($puede['estado'])
                                <form action="{{ route('turnos.estado', $t->id) }}" method="POST" class="m-0"
                                      data-confirmar="{{ $t->activo ? '¿Desactivar el turno «'.$t->nombre.'»? Podrás reactivarlo con el mismo botón.' : '¿Reactivar el turno «'.$t->nombre.'»?' }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="activo" value="{{ $t->activo ? 0 : 1 }}">
                                    @if ($t->activo)
                                        <button type="submit" class="btn-icono eliminar" title="Desactivar" aria-label="Desactivar {{ $t->nombre }}"><i class="bi bi-slash-circle" aria-hidden="true"></i></button>
                                    @else
                                        <button type="submit" class="btn-icono reactivar" title="Reactivar" aria-label="Reactivar {{ $t->nombre }}"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></button>
                                    @endif
                                </form>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                @unless ($puede['crear'])
                    <div class="tarjeta estado-vacio"><p class="text-muted small m-0">Todavía no hay turnos registrados.</p></div>
                @endunless
            @endforelse
        </div>
        <p class="text-muted text-center p-4" data-sin-resultados="turnos" hidden><i class="bi bi-search me-2" aria-hidden="true"></i>No hay turnos que coincidan con tu búsqueda.</p>

        {{-- ===== Alta y edición ===== --}}
        @foreach (['nuevo' => $puede['crear'], 'editar' => $puede['editar']] as $modo => $permitido)
            @continue (! $permitido)
            @php
                $esNuevo = $modo === 'nuevo';
                $reabrir = $esNuevo ? $dialogo === 'crear' : $editandoId !== null;
                $todas = $reabrir ? (bool) old('todas_las_sedes') : true;
                $marcadas = $reabrir ? array_map('intval', (array) old('sedes', [])) : [];
            @endphp
            <dialog id="{{ $esNuevo ? 'dialogoNuevoTurno' : 'dialogoEditarTurno' }}" class="dialogo" aria-labelledby="titulo-turno-{{ $modo }}" @if ($reabrir) data-abrir-al-cargar @endif>
                <div class="dialogo-cabecera">
                    <h2 id="titulo-turno-{{ $modo }}"><i class="bi {{ $esNuevo ? 'bi-clock' : 'bi-pencil-square' }} me-2 text-indigo" aria-hidden="true"></i>{{ $esNuevo ? 'Alta de Turno' : 'Editar Turno' }}</h2>
                    <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
                <div class="dialogo-cuerpo">
                    <form action="{{ $esNuevo ? route('turnos.store') : ($editandoId ? route('turnos.update', $editandoId) : '') }}" method="POST" autocomplete="off">
                        @csrf
                        @unless ($esNuevo) @method('PUT') @endunless
                        <input type="hidden" name="_dialogo" value="{{ $esNuevo ? 'crear' : ($editandoId ? 'editar-'.$editandoId : '') }}" data-campo-dialogo>

                        <label class="campo-etiqueta" for="{{ $modo }}_turno_nombre">Nombre del Turno</label>
                        <input type="text" id="{{ $modo }}_turno_nombre" name="nombre" class="campo" maxlength="50" placeholder="Ej. Matutino"
                               value="{{ $reabrir ? old('nombre') : '' }}" data-nombres-existentes="{{ $existentes }}" required>
                        <p data-aviso-nombre hidden></p>

                        <div class="turno-horas">
                            <div>
                                <label class="campo-etiqueta" for="{{ $modo }}_turno_inicio">Hora Inicio</label>
                                <input type="time" id="{{ $modo }}_turno_inicio" name="hora_inicio" class="campo" value="{{ $reabrir ? old('hora_inicio') : '' }}" required>
                            </div>
                            <div>
                                <label class="campo-etiqueta" for="{{ $modo }}_turno_fin">Hora Fin</label>
                                <input type="time" id="{{ $modo }}_turno_fin" name="hora_fin" class="campo" value="{{ $reabrir ? old('hora_fin') : '' }}" required>
                            </div>
                        </div>
                        <p class="small text-muted turno-ayuda"><i class="bi bi-moon-stars me-1" aria-hidden="true"></i>Si la hora de fin es menor que la de inicio (p. ej. 23:00 a 07:00), el turno termina al día siguiente.</p>

                        @if ($esNuevo)
                            @if ($variasSedes)
                                <span class="campo-etiqueta d-block">Sedes que usan este turno</span>
                                <input type="hidden" name="todas_las_sedes" value="0">
                                <label class="opcion-todas">
                                    <input type="checkbox" name="todas_las_sedes" value="1" @checked($todas) data-oculta-si-marcado="#nuevo_turno_sedes">
                                    <span><strong>Todas las sedes</strong><br><span class="small text-muted">También las que se abran después. Desmárcalo para elegir solo algunas.</span></span>
                                </label>
                                <div class="caja-checks" id="nuevo_turno_sedes" @if ($todas) hidden @endif>
                                    @foreach ($sedes as $sede)
                                        <label class="fila-check"><input type="checkbox" name="sedes[]" value="{{ $sede->id }}" @checked(in_array($sede->id, $marcadas, true))> {{ $sede->nombre }}</label>
                                    @endforeach
                                </div>
                            @else
                                <input type="hidden" name="todas_las_sedes" value="1">
                            @endif
                        @endif

                        <div class="dialogo-acciones">
                            <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                            <button type="submit" class="btn-indigo">{{ $esNuevo ? 'Guardar Turno' : 'Actualizar' }}</button>
                        </div>
                    </form>
                    @unless ($esNuevo)
                        @include('componentes.borrar', ['registro' => 'turnos', 'id' => $editandoId])
                    @endunless
                </div>
            </dialog>
        @endforeach

        {{-- ===== Sedes que usan cada turno ===== --}}
        @if ($puede['sedes'])
            @foreach ($turnos as $t)
                @php
                    $reabrir = $sedesDeId === $t->id;
                    $todas = $reabrir ? (bool) old('todas_las_sedes') : $t->todas_las_sedes;
                    $marcadas = $reabrir ? array_map('intval', (array) old('sedes', [])) : $t->sedes->pluck('id')->all();
                @endphp
                <dialog id="dialogoSedesTurno{{ $t->id }}" class="dialogo" aria-labelledby="titulo-sedes-{{ $t->id }}" @if ($reabrir) data-abrir-al-cargar @endif>
                    <div class="dialogo-cabecera">
                        <h2 id="titulo-sedes-{{ $t->id }}"><i class="bi bi-signpost-split me-2 text-indigo" aria-hidden="true"></i>Sedes que usan "{{ $t->nombre }}"</h2>
                        <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                    </div>
                    <div class="dialogo-cuerpo">
                        <p class="text-muted small mb-3">{{ $empresaNombre }} define este turno — elige en qué sedes está activo.</p>
                        <form action="{{ route('turnos.sedes', $t->id) }}" method="POST">
                            @csrf
                            @method('PUT')
                            <input type="hidden" name="_dialogo" value="sedes-{{ $t->id }}">

                            @if ($sedesAsignables->isEmpty())
                                <p class="text-muted fst-italic">Esta empresa no tiene sedes activas registradas todavía.</p>
                            @elseif ($puede['todasLasSedes'])
                                <input type="hidden" name="todas_las_sedes" value="0">
                                <label class="opcion-todas">
                                    <input type="checkbox" name="todas_las_sedes" value="1" @checked($todas) data-oculta-si-marcado="#sedes_turno_{{ $t->id }}">
                                    <span><strong>Todas las sedes</strong><br><span class="small text-muted">También las que se abran después. Desmárcalo para elegir solo algunas.</span></span>
                                </label>
                                <div class="lista-sedes-turno" id="sedes_turno_{{ $t->id }}" @if ($todas) hidden @endif>
                                    @foreach ($sedesAsignables as $sede)
                                        <label class="opcion-sede-turno"><input type="checkbox" name="sedes[]" value="{{ $sede->id }}" @checked(in_array($sede->id, $marcadas, true))> <span>{{ $sede->nombre }}</span></label>
                                    @endforeach
                                </div>
                            @else
                                <div class="lista-sedes-turno">
                                    @foreach ($sedesAsignables as $sede)
                                        <label class="opcion-sede-turno"><input type="checkbox" name="sedes[]" value="{{ $sede->id }}" @checked($todas || in_array($sede->id, $marcadas, true))> <span>{{ $sede->nombre }}</span></label>
                                    @endforeach
                                </div>
                                <p class="small text-muted"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Solo puedes activar o quitar el turno en tu sede; las demás sedes se quedan como están.</p>
                            @endif

                            <div class="dialogo-acciones">
                                <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                                @if ($sedesAsignables->isNotEmpty())
                                    <button type="submit" class="btn-indigo">Guardar Sedes</button>
                                @endif
                            </div>
                        </form>
                    </div>
                </dialog>
            @endforeach
        @endif
    @endif
</div>
@endsection
