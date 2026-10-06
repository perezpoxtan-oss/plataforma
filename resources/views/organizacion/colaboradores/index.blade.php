@extends('layouts.app')

@section('titulo', 'Colaboradores')

@section('contenido')
<div class="tema-esmeralda">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    @if ($sinEmpresa)
        <div class="encabezado-pantalla mb-4">
            <div class="icono"><i class="bi bi-person-vcard text-success" aria-hidden="true"></i></div>
            <div><h1>Colaboradores</h1><p>Directorio de personal y contratistas internos.</p></div>
        </div>
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-buildings" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong> para ver sus colaboradores.</p>
        </div>
    @else
        @php
            $dialogo = old('_dialogo');
            $editandoId = is_string($dialogo) && str_starts_with($dialogo, 'editar-') ? (int) substr($dialogo, 7) : null;
            $numeros = json_encode($colaboradores->pluck('num_empleado')->filter()->map(fn ($n) => mb_strtolower($n))->values());
            $porValidar = $colaboradores->where('provisional', true)->whereNull('fusionado_en_id')->where('activo', true)->count();
        @endphp

        @if ($porValidar > 0 && $puede['aprobar'])
            <div class="alert alert-warning d-flex align-items-center gap-2 aviso mb-3" role="status">
                <i class="bi bi-hourglass-split" aria-hidden="true"></i>
                <span>Hay <strong>{{ $porValidar }}</strong> {{ $porValidar === 1 ? 'alta provisional' : 'altas provisionales' }} de la caseta por validar. Elige «Por validar» en el filtro.</span>
            </div>
        @endif

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-4">
            <div class="encabezado-pantalla m-0">
                <div class="icono"><i class="bi bi-person-vcard text-success" aria-hidden="true"></i></div>
                <div>
                    <h1>Colaboradores</h1>
                    <p>Directorio de personal y contratistas internos.</p>
                </div>
            </div>
            <div class="barra-filtros justify-content-md-end">
                @if ($sedesFiltro->count() > 1)
                    <select class="filtro-select" aria-label="Filtrar por sede" data-filtro-colab="sede">
                        <option value="">Todas las sedes</option>
                        @foreach ($sedesFiltro as $sede)
                            <option value="{{ $sede->id }}">{{ $sede->nombre }}</option>
                        @endforeach
                    </select>
                @endif
                @if ($departamentosFiltro->count() > 1)
                    <select class="filtro-select" aria-label="Filtrar por departamento" data-filtro-colab="depto">
                        <option value="">Todos los departamentos</option>
                        @foreach ($departamentosFiltro as $dep)
                            <option value="{{ $dep->id }}">{{ $dep->nombre }}</option>
                        @endforeach
                    </select>
                @endif
                @if ($porValidar > 0 || $puede['aprobar'])
                    <select class="filtro-select" aria-label="Filtrar por registro" data-filtro-colab="registro">
                        <option value="">Todos los registros</option>
                        <option value="provisional">Por validar ({{ $porValidar }})</option>
                    </select>
                @endif
                <div class="buscador">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="search" placeholder="Buscar empleado, número o puesto..." aria-label="Buscar colaborador" data-filtro-colab="texto">
                </div>
            </div>
        </div>

        <div class="fichas-grid" data-colaboradores>
            @if ($puede['crear'])
                <button type="button" class="ficha-card ficha-create" data-abrir-dialogo="dialogoNuevoColaborador">
                    <i class="bi bi-person-plus-fill" aria-hidden="true"></i>
                    <span class="h6 fw-bold m-0 mt-2 titulo-crear">Nuevo Colaborador</span>
                </button>
            @endif

            @if ($puede['provisional'])
                <button type="button" class="ficha-card ficha-create ficha-provisional" data-abrir-dialogo="dialogoRegistroRapidoColaborador">
                    <i class="bi bi-person-plus" aria-hidden="true"></i>
                    <span class="h6 fw-bold m-0 mt-2 titulo-crear">Alta provisional</span>
                    <span class="small text-muted mt-1">Para alguien que aún no aparece. Recursos Humanos lo validará.</span>
                </button>
            @endif

            @forelse ($colaboradores as $c)
                @php
                    $adicionales = $c->sedesAdicionales;
                    $todasSusSedes = collect([$c->sede_id])->merge($adicionales->pluck('id'))->filter()->unique()->join(',');
                    $editable = $puede['editar'] && ($editables === null || in_array($c->id, $editables, true));
                    $desactivable = $puede['estado'] && ($desactivables === null || in_array($c->id, $desactivables, true));
                    $nombreCompleto = $c->nombreCompleto();
                    $valores = json_encode([
                        'num_empleado' => $c->num_empleado, 'nombre' => $c->nombre, 'apellido_paterno' => $c->apellido_paterno,
                        'apellido_materno' => $c->apellido_materno, 'telefono' => $c->telefono, 'sede_id' => $c->sede_id,
                        'departamento_id' => $c->departamento_id, 'puesto_id' => $c->puesto_id,
                    ]);
                @endphp
                @php
                    $pendiente = $c->provisional && $c->fusionado_en_id === null && $c->activo;
                    $validable = $pendiente && $puede['aprobar'] && ($aprobables === null || in_array($c->id, $aprobables, true));
                @endphp
                <div class="ficha-card {{ $c->activo ? '' : 'inactiva' }} {{ $pendiente ? 'ficha-pendiente' : '' }}" id="colaborador-{{ $c->id }}" data-colaborador data-registro="{{ $pendiente ? 'provisional' : 'validado' }}"
                     data-sedes="{{ $todasSusSedes }}" data-depto="{{ $c->departamento_id }}"
                     data-texto="{{ mb_strtolower($nombreCompleto.' #'.$c->num_empleado.' '.$c->num_empleado.' '.($c->puesto?->nombre ?? '').' '.($c->departamento?->nombre ?? '').' '.($c->sede?->nombre ?? '')) }}">
                    <div>
                        <div class="d-flex align-items-center gap-3 mb-3">
                            <div class="avatar-colaborador" aria-hidden="true">{{ $c->iniciales() }}</div>
                            <div class="lh-sm text-truncate">
                                <h2 class="ficha-title m-0" style="font-size: 1.05rem;">{{ $nombreCompleto }}</h2>
                                @if ($c->num_empleado)
                                    <span class="badge-num-empleado">#{{ $c->num_empleado }}</span>
                                @endif
                                @if ($pendiente)
                                    <span class="badge-provisional"><i class="bi bi-hourglass-split" aria-hidden="true"></i> PROVISIONAL</span>
                                @endif
                            </div>
                        </div>

                        <div class="ficha-meta">
                            <div><i class="bi bi-briefcase text-success" aria-hidden="true"></i> <strong>Puesto:</strong> {{ $c->puesto?->nombre ?? 'Sin Puesto' }}</div>
                            <div><i class="bi bi-diagram-3 text-warning" aria-hidden="true"></i> <strong>Depto:</strong> {{ $c->departamento?->nombre ?? 'Sin Departamento' }}</div>
                            <div class="text-muted small border-top pt-2 mt-1"><i class="bi bi-geo-alt-fill" aria-hidden="true"></i>
                                {{ $c->sede ? 'Sede: '.$c->sede->nombre : 'Sede: Corporativo (todas las sedes)' }}
                            </div>
                            @if ($adicionales->isNotEmpty())
                                <div class="text-muted small" title="{{ $adicionales->pluck('nombre')->join(', ') }}"><i class="bi bi-signpost-split" aria-hidden="true"></i>
                                    +{{ $adicionales->count() }} {{ $adicionales->count() === 1 ? 'sede adicional' : 'sedes adicionales' }}: {{ $adicionales->pluck('nombre')->join(', ') }}
                                </div>
                            @endif
                            @if ($pendiente)
                                <div class="small mt-1 texto-provisional"><i class="bi bi-shield-exclamation" aria-hidden="true"></i> Alta provisional de la caseta: Recursos Humanos debe validarla.</div>
                            @elseif ($c->fusionado_en_id && $c->fusionadoEn)
                                <div class="small mt-1 text-muted"><i class="bi bi-arrow-left-right" aria-hidden="true"></i> Era un duplicado: se unió con #{{ $c->fusionadoEn->num_empleado }} {{ $c->fusionadoEn->nombreCompleto() }}.</div>
                            @elseif ($c->validado_en)
                                <div class="texto-traza mt-1"><i class="bi bi-patch-check" aria-hidden="true"></i> Validado por Recursos Humanos · @fecha($c->validado_en)</div>
                            @endif
                            @if ($c->creado_por_nombre)
                                <div class="texto-traza mt-1"><i class="bi bi-plus-circle" aria-hidden="true"></i> Creado por {{ $c->creado_por_nombre }} · @fecha($c->created_at)</div>
                            @endif
                            @if ($c->actualizado_por_nombre && $c->updated_at?->ne($c->created_at))
                                <div class="texto-traza"><i class="bi bi-clock-history" aria-hidden="true"></i> Editado por {{ $c->actualizado_por_nombre }} · @fecha($c->updated_at)</div>
                            @endif
                        </div>
                        @if ($validable)
                            <div class="d-flex flex-wrap gap-2 mt-2">
                                <button type="button" class="btn-validar-colab"
                                        data-accion="editar-registro" data-dialogo="dialogoValidarColaborador"
                                        data-url="{{ route('colaboradores.validar', $c->id) }}" data-id="{{ $c->id }}" data-valores="{{ $valores }}">
                                    <i class="bi bi-patch-check" aria-hidden="true"></i> Validar
                                </button>
                                <button type="button" class="btn-duplicado-colab"
                                        data-accion="editar-registro" data-dialogo="dialogoFusionarColaborador"
                                        data-url="{{ route('colaboradores.fusionar', $c->id) }}" data-id="{{ $c->id }}"
                                        data-valores="{{ json_encode(['destino_id' => '']) }}">
                                    <i class="bi bi-people" aria-hidden="true"></i> Es un duplicado
                                </button>
                            </div>
                        @endif
                        @if ($puede['sedesAdicionales'] && $editable && ! $pendiente)
                            <button type="button" class="btn-sedes-colab"
                                    data-accion="editar-registro" data-dialogo="dialogoSedesColaborador"
                                    data-url="{{ route('colaboradores.sedes', $c->id) }}" data-id="{{ $c->id }}"
                                    data-nombre="{{ $nombreCompleto }}" data-sede-principal="{{ $c->sede?->nombre ?? 'Corporativo (todas las sedes)' }}"
                                    data-valores="{{ json_encode(['sede_id' => $c->sede_id, 'sedes' => $adicionales->pluck('id')]) }}">
                                <i class="bi bi-signpost-split" aria-hidden="true"></i> Gestionar sedes adicionales
                            </button>
                        @endif
                    </div>
                    <div class="ficha-footer">
                        <span class="etiqueta-estado {{ $c->activo ? ($pendiente ? 'pendiente' : 'activo') : 'inactivo' }}">{{ $c->activo ? ($pendiente ? 'POR VALIDAR' : 'ACTIVO') : ($c->fusionado_en_id ? 'UNIDO' : 'BAJA') }}</span>
                        <div class="d-flex gap-2">
                            @if ($editable)
                                <button type="button" class="btn-icono editar" title="Editar" aria-label="Editar a {{ $nombreCompleto }}"
                                        data-accion="editar-registro" data-dialogo="dialogoEditarColaborador"
                                        data-url="{{ route('colaboradores.update', $c->id) }}" data-id="{{ $c->id }}" data-valores="{{ $valores }}"
                                        @if ($puede['datos']) data-url-datos="{{ route('colaboradores.datos-personales', $c->id) }}" @endif>
                                    <i class="bi bi-pencil-square" aria-hidden="true"></i>
                                </button>
                            @endif
                            @if ($desactivable)
                                <form action="{{ route('colaboradores.estado', $c->id) }}" method="POST" class="m-0"
                                      data-confirmar="{{ $c->activo ? '¿Dar de baja a este colaborador? Podrás reactivarlo con el mismo botón.' : '¿Reingresar a este colaborador?' }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="activo" value="{{ $c->activo ? 0 : 1 }}">
                                    @if ($c->activo)
                                        <button type="submit" class="btn-icono eliminar" title="Dar de baja" aria-label="Dar de baja a {{ $nombreCompleto }}"><i class="bi bi-slash-circle" aria-hidden="true"></i></button>
                                    @else
                                        <button type="submit" class="btn-icono reactivar" title="Reingresar" aria-label="Reingresar a {{ $nombreCompleto }}"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></button>
                                    @endif
                                </form>
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                @unless ($puede['crear'])
                    <div class="tarjeta estado-vacio"><p class="text-muted small m-0">Todavía no hay colaboradores registrados.</p></div>
                @endunless
            @endforelse

            <div class="sin-resultados" data-sin-resultados-colab hidden>
                <i class="bi bi-search d-block mb-2" aria-hidden="true"></i>
                <p class="fw-semibold m-0">No hay colaboradores que coincidan con tu búsqueda.</p>
            </div>
        </div>

        {{-- ===== Alta y edición (comparten campos) ===== --}}
        @foreach (['nuevo' => $alta, 'editar' => $edicion] as $modo => $cat)
            @continue ($cat === null)
            @php
                $esNuevo = $modo === 'nuevo';
                $reabrir = $esNuevo ? $dialogo === 'crear' : $editandoId !== null;
                $valor = fn (string $campo, string $porDefecto = '') => $reabrir ? old($campo, $porDefecto) : $porDefecto;
                $previos = $reabrir ? session()->getOldInput() : [];
                $permitidas = $cat['sedesPermitidas'];
                $sedesOpciones = $esNuevo
                    ? $cat['sedes']->filter(fn ($s) => $s->activo && ($permitidas === null || in_array($s->id, $permitidas, true)))
                    : $cat['sedes'];
                $tituloLegales = $esNuevo ? 'Datos Legales (Opcionales)' : 'Datos Legales y de Contacto (Opcionales)';
                // Al reabrir una edición con errores, un dato personal que no llegó (no se alcanzó a cargar) se deja bloqueado: así no se borra
                $bloqueado = fn (string $campo) => ! $esNuevo && $reabrir && ! array_key_exists($campo, $previos);
            @endphp
            <dialog id="{{ $esNuevo ? 'dialogoNuevoColaborador' : 'dialogoEditarColaborador' }}" class="dialogo extra-ancho" aria-labelledby="titulo-co-{{ $modo }}" @if ($reabrir) data-abrir-al-cargar @endif>
                <div class="dialogo-cabecera">
                    <h2 id="titulo-co-{{ $modo }}"><i class="bi {{ $esNuevo ? 'bi-person-vcard' : 'bi-pencil-square' }} me-2 text-success" aria-hidden="true"></i>{{ $esNuevo ? 'Alta de Colaborador' : 'Editar Colaborador' }}</h2>
                    <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
                <div class="dialogo-cuerpo">
                    <form action="{{ $esNuevo ? route('colaboradores.store') : ($editandoId ? route('colaboradores.update', $editandoId) : '') }}" method="POST" autocomplete="off" data-form-colaborador>
                        @csrf
                        @unless ($esNuevo) @method('PUT') @endunless
                        <input type="hidden" name="_dialogo" value="{{ $esNuevo ? 'crear' : ($editandoId ? 'editar-'.$editandoId : '') }}" data-campo-dialogo>

                        <div class="row">
                            <div class="col-md-6">
                                <label class="campo-etiqueta" for="{{ $modo }}_co_sede">1. Sede Física @if ($permitidas === null)<span class="text-lowercase fw-normal">(opcional)</span>@endif</label>
                                <select id="{{ $modo }}_co_sede" name="sede_id" class="campo" data-colab-sede @if ($permitidas !== null) required @endif>
                                    <option value="">{{ $permitidas === null ? '-- Corporativo (todas las sedes) --' : '-- Selecciona tu sede --' }}</option>
                                    @foreach ($sedesOpciones as $sede)
                                        @php $ajena = ! $sede->activo || ($permitidas !== null && ! in_array($sede->id, $permitidas, true)); @endphp
                                        <option value="{{ $sede->id }}" @if ($ajena) data-ajena @endif @selected((string) $valor('sede_id') === (string) $sede->id)>{{ $sede->nombre }}{{ $sede->activo ? '' : ' (inactiva)' }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="campo-etiqueta" for="{{ $modo }}_co_num">Núm. Empleado</label>
                                <input type="text" id="{{ $modo }}_co_num" name="num_empleado" class="campo mb-1" maxlength="20" value="{{ $valor('num_empleado') }}"
                                       data-numeros-existentes="{{ $numeros }}" autocapitalize="characters" required>
                                <p class="small mb-2" data-aviso-numero hidden></p>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <label class="campo-etiqueta" for="{{ $modo }}_co_depto">2. Departamento <span class="text-lowercase fw-normal">(área)</span></label>
                                <select id="{{ $modo }}_co_depto" name="departamento_id" class="campo" data-colab-depto>
                                    <option value="">-- Sin Departamento --</option>
                                    @foreach ($cat['departamentos'] as $dep)
                                        @continue ($esNuevo && ! $dep->activo)
                                        <option value="{{ $dep->id }}" data-todas="{{ $dep->todas_las_sedes ? 1 : 0 }}" data-sedes="{{ $dep->sedes->pluck('id')->join(',') }}"
                                                @selected((string) $valor('departamento_id') === (string) $dep->id)>{{ $dep->nombre }}{{ $dep->activo ? '' : ' (inactivo)' }}</option>
                                    @endforeach
                                </select>
                                <p class="campo-ayuda">Solo aparecen los que aplican en la sede elegida.</p>
                            </div>
                            <div class="col-md-6">
                                <label class="campo-etiqueta" for="{{ $modo }}_co_puesto">3. Puesto <span class="text-lowercase fw-normal">(rango)</span></label>
                                <select id="{{ $modo }}_co_puesto" name="puesto_id" class="campo" data-colab-puesto>
                                    <option value="">-- Sin Puesto --</option>
                                    @foreach ($cat['puestos'] as $pu)
                                        @continue ($esNuevo && ! $pu->activo)
                                        <option value="{{ $pu->id }}" data-deps="{{ $pu->departamentos->pluck('id')->join(',') }}"
                                                @selected((string) $valor('puesto_id') === (string) $pu->id)>{{ $pu->nombre }}{{ $pu->activo ? '' : ' (inactivo)' }}</option>
                                    @endforeach
                                </select>
                                <p class="campo-ayuda">Se acota según el Departamento (los universales siempre aparecen).</p>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <label class="campo-etiqueta" for="{{ $modo }}_co_nombre">Nombre(s)</label>
                                <input type="text" id="{{ $modo }}_co_nombre" name="nombre" class="campo" maxlength="60" value="{{ $valor('nombre') }}" required>
                            </div>
                            <div class="col-md-4">
                                <label class="campo-etiqueta" for="{{ $modo }}_co_paterno">Apellido Paterno</label>
                                <input type="text" id="{{ $modo }}_co_paterno" name="apellido_paterno" class="campo" maxlength="60" value="{{ $valor('apellido_paterno') }}" required>
                            </div>
                            <div class="col-md-4">
                                <label class="campo-etiqueta" for="{{ $modo }}_co_materno">Apellido Materno</label>
                                <input type="text" id="{{ $modo }}_co_materno" name="apellido_materno" class="campo" maxlength="60" value="{{ $valor('apellido_materno') }}">
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-6">
                                <label class="campo-etiqueta" for="{{ $modo }}_co_tel">Teléfono <span class="text-lowercase fw-normal">(opcional)</span></label>
                                <input type="tel" inputmode="tel" id="{{ $modo }}_co_tel" name="telefono" class="campo" maxlength="20" placeholder="10 dígitos" value="{{ $valor('telefono') }}">
                            </div>
                        </div>

                        <hr class="text-muted opacity-25 my-3">
                        @if ($puede['datos'])
                            <h3 class="subtitulo-dialogo"><i class="bi bi-2-circle text-muted me-1" aria-hidden="true"></i> {{ $tituloLegales }}</h3>
                            <p class="small text-muted mb-2" data-cargando-datos hidden><span class="spinner-border spinner-border-sm text-success me-1" aria-hidden="true"></span> Cargando datos personales...</p>
                            <div class="row">
                                <div class="col-md-4">
                                    <label class="campo-etiqueta" for="{{ $modo }}_co_curp">CURP</label>
                                    <input type="text" id="{{ $modo }}_co_curp" name="curp" class="campo text-uppercase" maxlength="18" data-dato-personal autocapitalize="characters"
                                           pattern="[A-Za-z]{4}[0-9]{6}[HMhm][A-Za-z]{5}[A-Za-z0-9][0-9]" title="18 caracteres, formato oficial de CURP" value="{{ $valor('curp') }}" @disabled($bloqueado('curp'))>
                                </div>
                                <div class="col-md-4">
                                    <label class="campo-etiqueta" for="{{ $modo }}_co_rfc">RFC</label>
                                    <input type="text" id="{{ $modo }}_co_rfc" name="rfc" class="campo text-uppercase" maxlength="13" data-dato-personal autocapitalize="characters"
                                           pattern="[A-Za-zÑñ&]{3,4}[0-9]{6}[A-Za-z0-9]{3}" title="12 o 13 caracteres, formato oficial de RFC" value="{{ $valor('rfc') }}" @disabled($bloqueado('rfc'))>
                                </div>
                                <div class="col-md-4">
                                    <label class="campo-etiqueta" for="{{ $modo }}_co_nss">NSS</label>
                                    <input type="text" id="{{ $modo }}_co_nss" name="nss" class="campo" maxlength="13" inputmode="numeric" data-dato-personal
                                           pattern="[0-9 \-]{11,13}" title="11 dígitos" value="{{ $valor('nss') }}" @disabled($bloqueado('nss'))>
                                </div>
                            </div>
                            <div class="row">
                                <div class="col-md-4">
                                    <label class="campo-etiqueta" for="{{ $modo }}_co_fecha">Fecha de Nacimiento</label>
                                    <input type="date" id="{{ $modo }}_co_fecha" name="fecha_nacimiento" class="campo" max="{{ now()->subDay()->format('Y-m-d') }}" data-dato-personal value="{{ $valor('fecha_nacimiento') }}" @disabled($bloqueado('fecha_nacimiento'))>
                                </div>
                                <div class="col-md-4">
                                    <label class="campo-etiqueta" for="{{ $modo }}_co_lugar">Estado de Nacimiento</label>
                                    <select id="{{ $modo }}_co_lugar" name="lugar_nacimiento" class="campo" data-dato-personal @disabled($bloqueado('lugar_nacimiento'))>
                                        <option value="">-- Selecciona --</option>
                                        @foreach (\App\Models\Colaborador::ESTADOS_NACIMIENTO as $estado)
                                            <option value="{{ $estado }}" @selected($valor('lugar_nacimiento') === $estado)>{{ $estado }}</option>
                                        @endforeach
                                    </select>
                                </div>
                                <div class="col-md-4">
                                    <label class="campo-etiqueta" for="{{ $modo }}_co_nacionalidad">Nacionalidad</label>
                                    <input type="text" id="{{ $modo }}_co_nacionalidad" name="nacionalidad" class="campo" maxlength="40" data-dato-personal value="{{ $valor('nacionalidad', $esNuevo ? 'Mexicana' : '') }}" @disabled($bloqueado('nacionalidad'))>
                                </div>
                            </div>
                            @unless ($esNuevo)
                                <div class="row">
                                    <div class="col-md-12">
                                        <label class="campo-etiqueta" for="editar_co_correo">Correo Personal</label>
                                        <input type="email" id="editar_co_correo" name="correo_personal" class="campo" maxlength="150" data-dato-personal value="{{ $valor('correo_personal') }}" @disabled($bloqueado('correo_personal'))>
                                    </div>
                                    <div class="col-md-12">
                                        <label class="campo-etiqueta" for="editar_co_direccion">Dirección Completa</label>
                                        <textarea id="editar_co_direccion" name="direccion_completa" class="campo" rows="2" maxlength="500" data-dato-personal @disabled($bloqueado('direccion_completa'))>{{ $valor('direccion_completa') }}</textarea>
                                    </div>
                                </div>
                            @endunless
                        @else
                            <p class="campo-ayuda mt-0"><i class="bi bi-lock-fill" aria-hidden="true"></i> Los datos legales (CURP, RFC, NSS, nacimiento y dirección) solo los ve y captura quien tiene el permiso «Datos personales».</p>
                        @endif

                        <div class="dialogo-acciones">
                            <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                            <button type="submit" class="btn-esmeralda">{{ $esNuevo ? 'Registrar Colaborador' : 'Guardar Cambios' }}</button>
                        </div>
                    </form>
                    @unless ($esNuevo)
                        @include('componentes.borrar', ['registro' => 'colaboradores', 'id' => $editandoId])
                    @endunless
                </div>
            </dialog>
        @endforeach

        {{-- ===== Altas provisionales: la caseta registra, Recursos Humanos valida ===== --}}
        @if ($puede['provisional'])
            @include('organizacion.colaboradores._registro-rapido')
        @endif

        @if ($validacion)
            @php
                $reabrirValidar = is_string($dialogo) && str_starts_with($dialogo, 'validar-');
                $validarId = $reabrirValidar ? (int) substr($dialogo, 8) : null;
                $v = fn (string $campo) => $reabrirValidar ? old($campo) : '';
            @endphp
            <dialog id="dialogoValidarColaborador" class="dialogo ancho" aria-labelledby="titulo-co-validar" @if ($reabrirValidar) data-abrir-al-cargar @endif>
                <div class="dialogo-cabecera">
                    <h2 id="titulo-co-validar"><i class="bi bi-patch-check me-2 text-success" aria-hidden="true"></i>Validar alta provisional</h2>
                    <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
                <div class="dialogo-cuerpo">
                    <p class="small text-muted">Revisa los datos que capturó la caseta, corrige lo necesario y asígnale su número de empleado. Desde ese momento es un colaborador normal.</p>
                    <form action="{{ $validarId ? route('colaboradores.validar', $validarId) : '' }}" method="POST" autocomplete="off" data-form-colaborador>
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="_dialogo" value="{{ $validarId ? 'validar-'.$validarId : '' }}" data-campo-dialogo data-prefijo-dialogo="validar-">
                        <div class="row">
                            <div class="col-md-6">
                                <label class="campo-etiqueta" for="validar_co_num">Núm. Empleado</label>
                                <input type="text" id="validar_co_num" name="num_empleado" class="campo mb-1" maxlength="20" value="{{ $v('num_empleado') }}"
                                       data-numeros-existentes="{{ $numeros }}" autocapitalize="characters" required>
                                <p class="small mb-2" data-aviso-numero hidden></p>
                            </div>
                            <div class="col-md-6">
                                <label class="campo-etiqueta" for="validar_co_sede">Sede Física</label>
                                <select id="validar_co_sede" name="sede_id" class="campo" data-colab-sede>
                                    <option value="">-- Corporativo (todas las sedes) --</option>
                                    @foreach ($validacion['sedes']->where('activo', true) as $sede)
                                        <option value="{{ $sede->id }}" @selected((string) $v('sede_id') === (string) $sede->id)>{{ $sede->nombre }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <label class="campo-etiqueta" for="validar_co_depto">Departamento</label>
                                <select id="validar_co_depto" name="departamento_id" class="campo" data-colab-depto>
                                    <option value="">-- Sin Departamento --</option>
                                    @foreach ($validacion['departamentos']->where('activo', true) as $dep)
                                        <option value="{{ $dep->id }}" data-todas="{{ $dep->todas_las_sedes ? 1 : 0 }}" data-sedes="{{ $dep->sedes->pluck('id')->join(',') }}" @selected((string) $v('departamento_id') === (string) $dep->id)>{{ $dep->nombre }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="campo-etiqueta" for="validar_co_puesto">Puesto</label>
                                <select id="validar_co_puesto" name="puesto_id" class="campo" data-colab-puesto>
                                    <option value="">-- Sin Puesto --</option>
                                    @foreach ($validacion['puestos']->where('activo', true) as $pu)
                                        <option value="{{ $pu->id }}" data-deps="{{ $pu->departamentos->pluck('id')->join(',') }}" @selected((string) $v('puesto_id') === (string) $pu->id)>{{ $pu->nombre }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>
                        <div class="row">
                            <div class="col-md-4"><label class="campo-etiqueta" for="validar_co_nombre">Nombre(s)</label><input type="text" id="validar_co_nombre" name="nombre" class="campo" maxlength="60" value="{{ $v('nombre') }}" required></div>
                            <div class="col-md-4"><label class="campo-etiqueta" for="validar_co_paterno">Apellido Paterno</label><input type="text" id="validar_co_paterno" name="apellido_paterno" class="campo" maxlength="60" value="{{ $v('apellido_paterno') }}" required></div>
                            <div class="col-md-4"><label class="campo-etiqueta" for="validar_co_materno">Apellido Materno</label><input type="text" id="validar_co_materno" name="apellido_materno" class="campo" maxlength="60" value="{{ $v('apellido_materno') }}"></div>
                        </div>
                        <label class="campo-etiqueta" for="validar_co_tel">Teléfono <span class="text-lowercase fw-normal">(opcional)</span></label>
                        <input type="tel" inputmode="tel" id="validar_co_tel" name="telefono" class="campo" maxlength="20" value="{{ $v('telefono') }}">
                        <div class="dialogo-acciones">
                            <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                            <button type="submit" class="btn-esmeralda">Validar Colaborador</button>
                        </div>
                    </form>
                </div>
            </dialog>

            <dialog id="dialogoFusionarColaborador" class="dialogo" aria-labelledby="titulo-co-fusionar">
                <div class="dialogo-cabecera">
                    <h2 id="titulo-co-fusionar"><i class="bi bi-people me-2 text-warning" aria-hidden="true"></i>Es un duplicado</h2>
                    <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
                <div class="dialogo-cuerpo">
                    <p class="small text-muted">Si esta persona ya estaba registrada, elige su registro correcto. Todo lo que la caseta registró con el alta provisional pasa a ese colaborador y el provisional queda dado de baja.</p>
                    <form action="" method="POST">
                        @csrf
                        @method('PUT')
                        <label class="campo-etiqueta" for="fusionar_destino">Colaborador correcto</label>
                        <select id="fusionar_destino" name="destino_id" class="campo" required data-select-buscable="Escribe nombre o número de empleado…">
                            <option value="">-- Selecciona --</option>
                            @foreach ($validados as $op)
                                <option value="{{ $op->id }}">{{ $op->nombreCompleto() }} · #{{ $op->num_empleado }}{{ $op->sede ? ' · '.$op->sede->nombre : '' }}</option>
                            @endforeach
                        </select>
                        <div class="dialogo-acciones">
                            <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                            <button type="submit" class="btn-ambar">Unir registros</button>
                        </div>
                    </form>
                </div>
            </dialog>
        @endif

        {{-- ===== Sedes adicionales ===== --}}
        @if ($puede['sedesAdicionales'])
            <dialog id="dialogoSedesColaborador" class="dialogo" aria-labelledby="titulo-co-sedes">
                <div class="dialogo-cabecera">
                    <h2 id="titulo-co-sedes"><i class="bi bi-signpost-split me-2 text-success" aria-hidden="true"></i>Sedes adicionales</h2>
                    <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
                <div class="dialogo-cuerpo">
                    <p class="text-muted small mb-1 fw-semibold" data-colab-nombre></p>
                    <p class="text-muted small mb-3">
                        Sede principal: <strong data-colab-sede-principal></strong>.
                        Marca aquí las sedes DONDE TAMBIÉN tiene presencia, además de la principal.
                    </p>
                    <form action="" method="POST">
                        @csrf
                        @method('PUT')
                        @if ($sedesEditables->isEmpty())
                            <p class="text-muted fst-italic">No hay otras sedes activas que puedas asignar.</p>
                        @endif
                        <div class="caja-checks caja-checks-alta">
                            @foreach ($sedesEditables as $sede)
                                <label class="fila-check" data-sede-opcion="{{ $sede->id }}"><input type="checkbox" name="sedes[]" value="{{ $sede->id }}"> {{ $sede->nombre }}</label>
                            @endforeach
                        </div>
                        @if ($edicion && $edicion['sedesPermitidas'] !== null)
                            <p class="campo-ayuda"><i class="bi bi-info-circle" aria-hidden="true"></i> Solo aparecen tus sedes; las demás sedes adicionales del colaborador se conservan.</p>
                        @endif

                        <div class="dialogo-acciones">
                            <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                            <button type="submit" class="btn-esmeralda">Guardar Sedes</button>
                        </div>
                    </form>
                </div>
            </dialog>
        @endif
    @endif
</div>
@endsection
