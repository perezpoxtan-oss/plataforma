@extends('layouts.app')
@use('App\Models\Vacante')

@section('titulo', 'Vacantes')

@section('contenido')
<div class="pantalla-vacantes">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    @if ($sinEmpresa)
        <div class="encabezado-pantalla mb-4">
            <div class="icono"><i class="bi bi-megaphone text-warning" aria-hidden="true"></i></div>
            <div><h1>Vacantes</h1><p>Bolsa de trabajo de la empresa.</p></div>
        </div>
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-buildings" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong> para ver sus vacantes.</p>
        </div>
    @else
        @php
            $dialogo = (string) old('_dialogo');
            $hayFiltros = $filtros['q'] !== '' || $filtros['estado'] !== '' || $filtros['sede'] > 0 || $filtros['departamento'] > 0;
            $base = array_filter(['q' => $filtros['q'], 'sede' => $filtros['sede'] ?: null, 'departamento' => $filtros['departamento'] ?: null]);
            $editandoId = str_starts_with($dialogo, 'editar-') ? (int) substr($dialogo, 7) : null;
            $urlBolsa = $bolsa['lista'] ? route('empleos.index', $empresa->bolsa_slug) : null;
            $controlador = app(\App\Http\Controllers\RecursosHumanos\VacanteController::class);
        @endphp

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
            <div class="encabezado-pantalla m-0">
                <div class="icono"><i class="bi bi-megaphone text-warning" aria-hidden="true"></i></div>
                <div>
                    <h1>Vacantes</h1>
                    <p>Publica tus vacantes, imprime su cartel y recibe solicitudes por internet.</p>
                    <p class="texto-padron-de"><i class="bi bi-building-check" aria-hidden="true"></i> Vacantes de: <strong>{{ $empresa->nombre_comercial }}</strong></p>
                </div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                @if ($puede['candidatos'])
                    <a href="{{ route('candidatos.index') }}" class="btn-secundario-rh"><i class="bi bi-person-workspace me-1" aria-hidden="true"></i>Candidatos</a>
                @endif
                @if ($puede['crear'])
                    <button type="button" class="btn-verde btn-accion-rh" data-abrir-dialogo="dialogoVacante"><i class="bi bi-plus-circle-fill me-1" aria-hidden="true"></i>Nueva vacante</button>
                @endif
            </div>
        </div>

        {{-- Bolsa de trabajo en internet --}}
        @if ($puede['configurar'] || $urlBolsa)
            <section class="tarjeta caja-bolsa {{ $urlBolsa ? 'encendida' : 'apagada' }}" aria-labelledby="t-bolsa">
                <div class="d-flex flex-wrap align-items-center gap-2">
                    <i class="bi bi-globe2 caja-bolsa-icono" aria-hidden="true"></i>
                    <div class="flex-grow-1 min-w-0">
                        <h2 id="t-bolsa" class="h6 fw-bold m-0">Bolsa de trabajo en internet <span class="etiqueta-estado {{ $urlBolsa ? 'activo' : 'inactivo' }}">{{ $urlBolsa ? 'ENCENDIDA' : 'APAGADA' }}</span></h2>
                        @if ($urlBolsa)
                            <a href="{{ $urlBolsa }}" class="enlace-bolsa" target="_blank" rel="noopener">{{ $urlBolsa }}</a>
                        @else
                            <p class="small text-muted m-0">Enciéndela para que cualquiera vea las vacantes publicadas y se postule desde su celular.</p>
                        @endif
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        @if ($urlBolsa)
                            <button type="button" class="btn-secundario-rh" data-copiar-enlace="{{ $urlBolsa }}"><i class="bi bi-link-45deg me-1" aria-hidden="true"></i>Copiar enlace</button>
                        @endif
                        @if ($puede['configurar'])
                            <button type="button" class="btn-secundario-rh" data-abrir-dialogo="dialogoBolsa"><i class="bi bi-gear me-1" aria-hidden="true"></i>Ajustes</button>
                        @endif
                    </div>
                </div>
            </section>
        @endif

        <form method="GET" action="{{ route('vacantes.index') }}" class="filtros-rh" role="search" data-autoenviar>
            <input type="hidden" name="estado" value="{{ $filtros['estado'] }}">
            <div class="buscador">
                <i class="bi bi-search" aria-hidden="true"></i>
                <input type="search" name="q" value="{{ $filtros['q'] }}" maxlength="100" placeholder="Buscar vacante..." aria-label="Buscar vacante">
            </div>
            @if ($sedes->count() > 1)
                <select name="sede" class="filtro-select" aria-label="Filtrar por sede" data-enviar-al-cambiar>
                    <option value="">Todas las sedes</option>
                    @foreach ($sedes as $s)
                        <option value="{{ $s->id }}" @selected($filtros['sede'] === $s->id)>{{ $s->nombre }}</option>
                    @endforeach
                </select>
            @endif
            <select name="departamento" class="filtro-select" aria-label="Filtrar por departamento" data-enviar-al-cambiar>
                <option value="">Todos los departamentos</option>
                @foreach ($departamentos as $d)
                    <option value="{{ $d->id }}" @selected($filtros['departamento'] === $d->id)>{{ $d->nombre }}</option>
                @endforeach
            </select>
            @if ($hayFiltros)
                <a href="{{ route('vacantes.index') }}" class="btn-limpiar-filtros">Limpiar</a>
            @endif
        </form>

        @if ($puede['editar'])
            <nav class="pildoras-pases" aria-label="Estados">
                <a href="{{ route('vacantes.index', $base) }}" class="btn-pill-tipo {{ $filtros['estado'] === '' ? 'active' : '' }}">Todas ({{ $conteos->sum() }})</a>
                @foreach (['publicada' => 'Publicadas', 'borrador' => 'Borradores', 'pausada' => 'En pausa', 'cerrada' => 'Cerradas'] as $clave => $texto)
                    <a href="{{ route('vacantes.index', $base + ['estado' => $clave]) }}" class="btn-pill-tipo {{ $filtros['estado'] === $clave ? 'active' : '' }}">{{ $texto }} ({{ (int) ($conteos[$clave] ?? 0) }})</a>
                @endforeach
            </nav>
        @endif

        <div class="fichas-grid fichas-vacantes">
            @forelse ($lista as $v)
                @php
                    $publica = $controlador->urlPublica($empresa, $v);
                    $mod = $modificables[$v->id] ?? ['editar' => false, 'eliminar' => false];
                    $vencida = $v->estado === 'publicada' && ! $v->vigente($hoy);
                    $valores = [
                        'titulo' => $v->titulo, 'puesto_id' => $v->puesto_id, 'departamento_id' => $v->departamento_id, 'plazas' => $v->plazas, 'jefe_ve_cv' => $v->jefe_ve_cv,
                        'todas_las_sedes' => $v->todas_las_sedes, 'sedes' => $v->sedes->pluck('id')->all(), 'tipo_contrato' => $v->tipo_contrato, 'jornada' => $v->jornada,
                        'turno_id' => $v->turno_id, 'horario' => $v->horario, 'sueldo_min' => $v->sueldo_min !== null ? (string) (float) $v->sueldo_min : null,
                        'sueldo_max' => $v->sueldo_max !== null ? (string) (float) $v->sueldo_max : null, 'sueldo_periodo' => $v->sueldo_periodo, 'sueldo_a_tratar' => $v->sueldo_a_tratar,
                        'descripcion' => $v->descripcion, 'requisitos' => implode("\n", $v->listaRequisitos()), 'prestaciones' => implode("\n", $v->listaPrestaciones()),
                        'escolaridad_minima' => $v->escolaridad_minima, 'experiencia' => $v->experiencia, 'fecha_publicacion' => $v->fecha_publicacion?->format('Y-m-d'),
                        'fecha_cierre' => $v->fecha_cierre?->format('Y-m-d'), 'contacto_nombre' => $v->contacto_nombre, 'contacto_telefono' => $v->contacto_telefono, 'contacto_correo' => $v->contacto_correo,
                    ];
                @endphp
                <article class="ficha-card ficha-vacante estado-{{ $v->estado }}">
                    <div>
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                            <span class="pastilla-etapa etapa-{{ Vacante::COLORES[$v->estado] ?? 'gris' }}">{{ $v->etiquetaEstado() }}</span>
                            <span class="plazas-vacante"><i class="bi bi-people" aria-hidden="true"></i> {{ $v->plazas }} plaza{{ $v->plazas !== 1 ? 's' : '' }}</span>
                        </div>
                        <h2 class="ficha-title">{{ $v->titulo }}</h2>
                        <div class="datos-candidato">
                            @if ($v->puesto || $v->departamento)<span><i class="bi bi-diagram-2" aria-hidden="true"></i> {{ collect([$v->puesto?->nombre, $v->departamento?->nombre])->filter()->join(' · ') }}</span>@endif
                            <span><i class="bi bi-geo-alt" aria-hidden="true"></i> {{ $v->sedesTexto() }}</span>
                            <span><i class="bi bi-cash" aria-hidden="true"></i> {{ $v->sueldoTexto() }}</span>
                            @if ($v->turno || $v->horario)<span><i class="bi bi-clock" aria-hidden="true"></i> {{ collect([$v->turno?->nombre, $v->horario])->filter()->join(' · ') }}</span>@endif
                            @if ($v->fecha_cierre)<span class="{{ $vencida ? 'text-danger fw-bold' : '' }}"><i class="bi bi-calendar-x" aria-hidden="true"></i> {{ $vencida ? 'Venció' : 'Cierra' }} el {{ $v->fecha_cierre->format('d/m/Y') }}</span>@endif
                        </div>
                        @if ($vencida)
                            <p class="aviso-vacante-vencida"><i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>Ya pasó su fecha de cierre: no se ve en internet ni en la caseta. Cámbiala en «Editar» o ciérrala.</p>
                        @endif
                        @if ($puede['candidatos'] || $puede['evaluar'])
                            {{-- Candidatos fase 2: tabla «Candidatos de esta vacante» para comparar --}}
                            <a href="{{ route('vacantes.candidatos', $v->id) }}" class="contador-postulados">
                                @if ($puede['candidatos'])
                                    <strong>{{ $v->candidatos_count }}</strong> candidato{{ $v->candidatos_count !== 1 ? 's' : '' }}
                                    <span>· {{ $v->en_proceso_count }} en proceso · {{ $v->plazas }} plaza{{ $v->plazas !== 1 ? 's' : '' }}</span>
                                @else
                                    <strong>Candidatos de esta vacante</strong>
                                @endif
                                <i class="bi bi-arrow-right" aria-hidden="true"></i>
                            </a>
                        @endif
                        <div class="texto-traza mt-2"><i class="bi bi-plus-circle" aria-hidden="true"></i> Creado por {{ $v->registradoPor?->name ?? '—' }} · @fecha($v->created_at)</div>
                        @if ($v->editadoPor && $v->updated_at?->ne($v->created_at))
                            <div class="texto-traza"><i class="bi bi-clock-history" aria-hidden="true"></i> Editado por {{ $v->editadoPor->name }} · @fecha($v->updated_at)</div>
                        @endif
                    </div>

                    <div class="acciones-vacante">
                        @if ($mod['editar'])
                            <button type="button" class="btn-secundario-rh" data-accion="editar-registro" data-dialogo="dialogoEditarVacante" data-id="{{ $v->id }}"
                                    data-url="{{ route('vacantes.update', $v->id) }}" data-valores="{{ json_encode($valores) }}"><i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Editar</button>
                            @foreach (Vacante::TRANSICIONES[$v->estado] as $destino)
                                @continue($destino === 'cerrada')
                                <form action="{{ route('vacantes.estado', $v->id) }}" method="POST" class="m-0"
                                      data-confirmar="{{ ['publicada' => $v->estado === 'pausada' ? '¿Reanudar «'.$v->titulo.'»? Vuelve a verse en internet y en la caseta.' : '¿Publicar «'.$v->titulo.'»? Se verá en la bolsa de trabajo y en la caseta.', 'pausada' => '¿Pausar «'.$v->titulo.'»? Deja de verse en internet hasta que la reanudes.', 'borrador' => '¿Reabrir «'.$v->titulo.'» como borrador?'][$destino] }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="estado" value="{{ $destino }}">
                                    <button type="submit" class="btn-etapa {{ $destino === 'publicada' ? 'principal' : '' }}">{{ $destino === 'publicada' && $v->estado === 'pausada' ? 'Reanudar' : Vacante::BOTONES[$destino] }}</button>
                                </form>
                            @endforeach
                            @if ($v->puedePasarA('cerrada'))
                                <button type="button" class="btn-etapa peligro" data-abrir-dialogo="dialogoCerrar{{ $v->id }}">Cerrar</button>
                            @endif
                        @endif
                        <a href="{{ route('vacantes.cartel', $v->id) }}" class="btn-secundario-rh" target="_blank" rel="noopener"><i class="bi bi-printer me-1" aria-hidden="true"></i>Cartel</a>
                        @if ($publica)
                            <button type="button" class="btn-secundario-rh" data-copiar-enlace="{{ $publica }}"><i class="bi bi-link-45deg me-1" aria-hidden="true"></i>Copiar enlace</button>
                            <a href="https://wa.me/?text={{ rawurlencode('Vacante: '.$v->titulo.' en '.$empresa->nombre_comercial.'. Postúlate aquí: '.$publica) }}" class="btn-secundario-rh btn-whatsapp" target="_blank" rel="noopener"><i class="bi bi-whatsapp me-1" aria-hidden="true"></i>WhatsApp</a>
                        @endif
                        @if ($mod['eliminar'] && $v->candidatos_count === 0)
                            <form action="{{ route('vacantes.destroy', $v->id) }}" method="POST" class="m-0" data-confirmar="¿Eliminar la vacante «{{ $v->titulo }}»? No se puede deshacer.">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn-icono eliminar" aria-label="Eliminar {{ $v->titulo }}"><i class="bi bi-trash3" aria-hidden="true"></i></button>
                            </form>
                        @endif
                    </div>
                </article>

                @if ($mod['editar'] && $v->puedePasarA('cerrada'))
                    @php $reabrirCerrar = $dialogo === 'cerrar-'.$v->id; @endphp
                    <dialog id="dialogoCerrar{{ $v->id }}" class="dialogo" aria-labelledby="titulo-cerrar-{{ $v->id }}" @if ($reabrirCerrar) data-abrir-al-cargar @endif>
                        <div class="dialogo-cabecera">
                            <h2 id="titulo-cerrar-{{ $v->id }}"><i class="bi bi-x-octagon me-2 text-danger" aria-hidden="true"></i>Cerrar «{{ $v->titulo }}»</h2>
                            <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                        </div>
                        <div class="dialogo-cuerpo">
                            <form action="{{ route('vacantes.estado', $v->id) }}" method="POST">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="_dialogo" value="cerrar-{{ $v->id }}">
                                <input type="hidden" name="estado" value="cerrada">
                                @if ($reabrirCerrar && $errors->any())
                                    <div class="alert alert-danger small py-2 px-3" role="alert">{{ $errors->first() }}</div>
                                @endif
                                <p class="small text-muted">Deja de verse en internet y en la caseta. Los candidatos que ya se postularon se conservan.</p>
                                <span class="campo-etiqueta">¿Por qué se cierra?</span>
                                <div class="opciones-cv">
                                    @foreach (Vacante::MOTIVOS_CIERRE as $clave => $texto)
                                        @continue($v->estado === 'borrador' && $clave === 'cubierta')
                                        <label class="opcion-cv"><input type="radio" name="cierre_motivo" value="{{ $clave }}" required @checked(($reabrirCerrar ? old('cierre_motivo') : ($v->estado === 'borrador' ? 'cancelada' : null)) === $clave)><span>{{ $texto }}</span></label>
                                    @endforeach
                                </div>
                                <div class="dialogo-acciones">
                                    <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                                    <button type="submit" class="btn-rojo">Cerrar vacante</button>
                                </div>
                            </form>
                        </div>
                    </dialog>
                @endif
            @empty
                <div class="tarjeta estado-vacio">
                    <div class="icono"><i class="bi bi-megaphone" aria-hidden="true"></i></div>
                    <p class="text-muted small m-0">
                        @if ($hayFiltros)
                            Sin vacantes con esos filtros.
                        @elseif ($puede['editar'])
                            Todavía no hay vacantes. @if ($puede['crear'])Crea la primera con <strong>Nueva vacante</strong>; queda como borrador hasta que la publiques.@endif
                        @else
                            No hay vacantes publicadas en este momento.
                        @endif
                    </p>
                </div>
            @endforelse
        </div>
        <div class="mt-4">{{ $lista->onEachSide(1)->links('pagination::bootstrap-5') }}</div>

        {{-- ===== Nueva vacante ===== --}}
        @if ($puede['crear'])
            <dialog id="dialogoVacante" class="dialogo ancho dialogo-vacante" aria-labelledby="titulo-vacante" @if ($dialogo === 'vacante') data-abrir-al-cargar @endif>
                <div class="dialogo-cabecera">
                    <h2 id="titulo-vacante"><i class="bi bi-plus-circle-fill me-2 text-success" aria-hidden="true"></i>Nueva vacante</h2>
                    <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
                <div class="dialogo-cuerpo">
                    <form action="{{ route('vacantes.store') }}" method="POST" autocomplete="off">
                        @csrf
                        <input type="hidden" name="_dialogo" value="vacante">
                        @include('rh.vacantes._formulario', ['modo' => 'nueva', 'reabrir' => $dialogo === 'vacante', 'sedesForm' => $sedesAlta])
                        <div class="dialogo-acciones">
                            <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                            <button type="submit" class="btn-borrador-vacante">Guardar borrador</button>
                            @if ($puede['editar'])
                                <button type="submit" name="publicar" value="1" class="btn-verde">Guardar y publicar</button>
                            @endif
                        </div>
                    </form>
                </div>
            </dialog>
        @endif

        {{-- ===== Editar vacante (un solo diálogo; se llena con los datos de la ficha) ===== --}}
        @if ($puede['editar'])
            <dialog id="dialogoEditarVacante" class="dialogo ancho" aria-labelledby="titulo-editar-vacante" @if ($editandoId) data-abrir-al-cargar @endif>
                <div class="dialogo-cabecera">
                    <h2 id="titulo-editar-vacante"><i class="bi bi-pencil-square me-2 text-success" aria-hidden="true"></i>Editar vacante</h2>
                    <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
                <div class="dialogo-cuerpo">
                    <form action="{{ $editandoId ? route('vacantes.update', $editandoId) : '#' }}" method="POST" autocomplete="off">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="_dialogo" value="{{ $editandoId ? 'editar-'.$editandoId : '' }}" data-campo-dialogo>
                        @include('rh.vacantes._formulario', ['modo' => 'editar', 'reabrir' => $editandoId !== null, 'sedesForm' => $sedesEditar, 'sedeUsuario' => null])
                        <div class="dialogo-acciones">
                            <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                            <button type="submit" class="btn-verde">Guardar cambios</button>
                        </div>
                    </form>
                </div>
            </dialog>
        @endif

        {{-- ===== Ajustes de la bolsa de trabajo ===== --}}
        @if ($puede['configurar'])
            <dialog id="dialogoBolsa" class="dialogo" aria-labelledby="t-ajustes-bolsa">
                <div class="dialogo-cabecera">
                    <h2 id="t-ajustes-bolsa"><i class="bi bi-globe2 me-2 text-primary" aria-hidden="true"></i>Bolsa de trabajo en internet</h2>
                    <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
                <div class="dialogo-cuerpo">
                    @include('rh.vacantes._ajustes-bolsa', ['empresa' => $empresa, 'enDialogo' => true])
                </div>
            </dialog>
        @endif
    @endif
</div>
@endsection
