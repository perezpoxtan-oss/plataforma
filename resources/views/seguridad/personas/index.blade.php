@extends('layouts.app')

@section('titulo', 'Padrón de Personas')

@section('contenido')
<div class="tema-azul">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    @if ($sinEmpresa)
        <div class="encabezado-pantalla mb-4">
            <div class="icono"><i class="bi bi-person-badge text-primary" aria-hidden="true"></i></div>
            <div><h1>Padrón de Personas</h1><p>Registro previo de conductores, visitas y contratistas.</p></div>
        </div>
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-buildings" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong> para ver su padrón de personas.</p>
        </div>
    @else
        @php
            $dialogo = old('_dialogo');
            $editandoId = is_string($dialogo) && str_starts_with($dialogo, 'editar-') ? (int) substr($dialogo, 7) : null;
            // Textos de SEGCAT (visitante_lista.php)
            $categoriasUi = ['general' => 'Visitante general', 'prospecto_rrhh' => 'Candidato/prospecto', 'familiar' => 'Familiar/personal'];
            $insignia = fn ($p) => match (true) {
                $p->tipo === 'proveedor' => ['proveedor', 'Proveedor'],
                $p->tipo === 'contratista' => ['contratista', 'Contratista'],
                $p->categoria === 'prospecto_rrhh' => ['prospecto', 'Candidato/Prospecto'],
                $p->categoria === 'familiar' => ['familiar', 'Familiar/Personal'],
                default => ['visitante', 'Visitante general'],
            };
        @endphp

        <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
            <div class="encabezado-pantalla m-0">
                <div class="icono"><i class="bi bi-person-badge text-primary" aria-hidden="true"></i></div>
                <div>
                    <h1>Padrón de Personas</h1>
                    <p>Registro previo de conductores, visitas y contratistas.</p>
                </div>
            </div>
            <div class="barra-filtros justify-content-md-end">
                {{-- Las personas no tienen sede: el filtro genérico "data-filtro-sede" se usa aquí para la categoría --}}
                <select class="filtro-select filtro-categoria-persona" aria-label="Filtrar por categoría de visitante" data-filtro-sede="personas">
                    <option value="">Todas las categorías</option>
                    @foreach ($categoriasUi as $clave => $etiqueta)
                        <option value="{{ $clave }}">{{ $etiqueta }}</option>
                    @endforeach
                </select>
                <div class="buscador">
                    <i class="bi bi-search" aria-hidden="true"></i>
                    <input type="search" placeholder="Buscar por nombre, folio o empresa..." aria-label="Buscar persona" data-filtro-texto="personas">
                </div>
            </div>
        </div>

        <div class="pildoras-tipo" role="group" aria-label="Filtrar por tipo de persona">
            <button type="button" class="btn-pill-tipo active" data-filtro-tipo="personas" data-valor="" aria-pressed="true">Todos</button>
            <button type="button" class="btn-pill-tipo" data-filtro-tipo="personas" data-valor="visitante" aria-pressed="false"><i class="bi bi-person me-1" aria-hidden="true"></i>Visitantes</button>
            <button type="button" class="btn-pill-tipo" data-filtro-tipo="personas" data-valor="proveedor" aria-pressed="false"><i class="bi bi-truck me-1" aria-hidden="true"></i>Proveedores</button>
            <button type="button" class="btn-pill-tipo" data-filtro-tipo="personas" data-valor="contratista" aria-pressed="false"><i class="bi bi-tools me-1" aria-hidden="true"></i>Contratistas</button>
        </div>

        <div class="fichas-grid" data-fichas="personas">
            @if ($puede['crear'])
                <button type="button" class="ficha-card ficha-create ficha-crear-persona" data-abrir-dialogo="dialogoNuevaPersona">
                    <i class="bi bi-person-plus-fill" aria-hidden="true"></i>
                    <span class="h6 fw-bold m-0 mt-2 titulo-crear">Registrar Persona</span>
                </button>
            @endif

            @forelse ($personas as $p)
                @php
                    [$claseInsignia, $textoInsignia] = $insignia($p);
                    $editable = $puede['editar'] && ($editables === null || in_array($p->id, $editables, true));
                    $desactivable = $puede['estado'] && ($desactivables === null || in_array($p->id, $desactivables, true));
                    $empresaDe = $p->empresaQueRepresenta();
                    $tipoId = \App\Models\Persona::IDENTIFICACIONES[$p->tipo_identificacion] ?? 'Identificación';
                    // El folio completo solo para quien puede editar esta ficha (y solo entonces entra al buscador)
                    $folioVisible = $p->folio_identificacion === null ? null
                        : ($editable ? $p->folio_identificacion : '••••'.mb_substr($p->folio_identificacion, -4));
                    $valores = $editable ? json_encode([
                        'tipo' => $p->tipo, 'categoria' => $p->categoria, 'nombre_completo' => $p->nombre_completo,
                        'proveedor_id' => $p->proveedor_id, 'empresa_procedencia' => $p->empresa_procedencia,
                        'tipo_identificacion' => $p->tipo_identificacion ?? 'ine', 'folio_identificacion' => $p->folio_identificacion,
                        'telefono' => $p->telefono, 'motivo_visita' => $p->motivo_visita,
                    ]) : null;
                @endphp
                <div class="ficha-card ficha-persona {{ $p->activo ? '' : 'inactiva' }}" id="persona-{{ $p->id }}" data-ficha
                     data-estado="{{ $p->activo ? 1 : 0 }}" data-tipo="{{ $p->tipo }}" data-sede="{{ $p->tipo === 'visitante' ? $p->categoria : '' }}"
                     data-texto="{{ mb_strtolower($p->nombre_completo.' '.($empresaDe ?? '').' '.$textoInsignia.($editable && $p->folio_identificacion ? ' '.$p->folio_identificacion : '')) }}">
                    <div>
                        <div class="d-flex justify-content-between align-items-start gap-2 mb-2">
                            <span class="insignia-persona {{ $claseInsignia }}"><i class="bi bi-tag-fill me-1" aria-hidden="true"></i>{{ $textoInsignia }}</span>
                            <span class="estado-persona {{ $p->activo ? 'activo' : 'baja' }}">{{ $p->activo ? 'ACTIVO' : 'BAJA' }}</span>
                        </div>
                        <h2 class="persona-nombre">{{ $p->nombre_completo }}</h2>

                        <div class="ficha-meta persona-meta">
                            @if ($empresaDe)
                                <div><i class="bi bi-truck text-success me-1" aria-hidden="true"></i> <strong>Viene de:</strong> {{ $empresaDe }}</div>
                            @endif
                            <div><i class="bi bi-person-vcard me-1" aria-hidden="true"></i> <strong>{{ $tipoId }}:</strong>
                                @if ($folioVisible === null)
                                    <span class="text-muted fw-normal">No registrada</span>
                                @else
                                    <span class="folio-persona" @unless ($editable) title="Folio protegido: solo lo ve completo quien puede editar" @endunless>{{ $folioVisible }}</span>
                                @endif
                            </div>
                            @if ($p->motivo_visita)
                                <div class="persona-motivo"><i class="bi bi-chat-left-text me-1" aria-hidden="true"></i> {{ $p->motivo_visita }}</div>
                            @endif
                            @if ($p->creado_por_nombre)
                                <div class="texto-traza mt-1"><i class="bi bi-plus-circle" aria-hidden="true"></i> Creado por {{ $p->creado_por_nombre }} · @fecha($p->created_at)</div>
                            @endif
                            @if ($p->actualizado_por_nombre && $p->updated_at?->ne($p->created_at))
                                <div class="texto-traza"><i class="bi bi-clock-history" aria-hidden="true"></i> Editado por {{ $p->actualizado_por_nombre }} · @fecha($p->updated_at)</div>
                            @endif
                        </div>
                    </div>
                    @if ($desactivable || $editable)
                    <div class="persona-pie">
                        @if ($desactivable)
                            <form action="{{ route('personas.estado', $p->id) }}" method="POST" class="m-0"
                                  data-confirmar="{{ $p->activo ? '¿Dar de baja este registro? Podrás reactivarlo con un clic.' : '¿Reactivar este registro?' }}">
                                @csrf
                                @method('PATCH')
                                <input type="hidden" name="activo" value="{{ $p->activo ? 0 : 1 }}">
                                @if ($p->activo)
                                    <button type="submit" class="btn-icono eliminar" title="Dar de baja" aria-label="Dar de baja a {{ $p->nombre_completo }}"><i class="bi bi-slash-circle" aria-hidden="true"></i></button>
                                @else
                                    <button type="submit" class="btn-icono reactivar" title="Reactivar" aria-label="Reactivar a {{ $p->nombre_completo }}"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></button>
                                @endif
                            </form>
                        @else
                            <span></span>
                        @endif
                        @if ($editable)
                            <button type="button" class="btn-editar-perfil" aria-label="Editar el perfil de {{ $p->nombre_completo }}"
                                    data-accion="editar-registro" data-dialogo="dialogoEditarPersona"
                                    data-url="{{ route('personas.update', $p->id) }}" data-id="{{ $p->id }}" data-valores="{{ $valores }}">
                                <i class="bi bi-pencil-square me-1" aria-hidden="true"></i> Editar Perfil
                            </button>
                        @endif
                    </div>
                    @endif
                </div>
            @empty
                @unless ($puede['crear'])
                    <div class="tarjeta estado-vacio"><p class="text-muted small m-0">Todavía no hay personas registradas en el padrón.</p></div>
                @endunless
            @endforelse

            <div class="sin-resultados" data-sin-resultados="personas" hidden>
                <i class="bi bi-search d-block mb-2" aria-hidden="true"></i>
                <p class="fw-semibold m-0">No hay personas que coincidan con tu búsqueda.</p>
            </div>
        </div>

        {{-- ===== Alta y edición (comparten campos) ===== --}}
        @foreach (['nuevo' => $puede['crear'], 'editar' => $puede['editar']] as $modo => $habilitado)
            @continue (! $habilitado)
            @php
                $esNuevo = $modo === 'nuevo';
                $reabrir = $esNuevo ? $dialogo === 'crear' : $editandoId !== null;
                // Contrato con la ficha del Proveedor: /personas?nuevo=1&proveedor={id}
                $desde = $esNuevo && ! $reabrir ? $desdeProveedor : null;
                $preset = $desde !== null && $desde['proveedor_id'] > 0
                    ? ['tipo' => $desde['tipo'], 'proveedor_id' => (string) $desde['proveedor_id']]
                    : [];
                $valor = fn (string $campo, string $porDefecto = '') => $reabrir ? (string) old($campo, $porDefecto) : ($preset[$campo] ?? $porDefecto);
                $volver = $esNuevo && ($reabrir ? old('volver') === 'proveedor' : $preset !== []);
                $nombreProveedor = $volver ? ($proveedores->firstWhere('id', (int) $valor('proveedor_id'))?->nombre) : null;
                $opcionesProveedor = $esNuevo ? $proveedores->where('activo', true) : $proveedores;
            @endphp
            <dialog id="{{ $esNuevo ? 'dialogoNuevaPersona' : 'dialogoEditarPersona' }}" class="dialogo ancho dialogo-persona" aria-labelledby="titulo-pe-{{ $modo }}"
                    @if ($reabrir || $desde !== null) data-abrir-al-cargar @endif>
                <div class="dialogo-cabecera">
                    <h2 id="titulo-pe-{{ $modo }}"><i class="bi {{ $esNuevo ? 'bi-person-add' : 'bi-pencil-square' }} me-2 text-primary" aria-hidden="true"></i>{{ $esNuevo ? 'Registrar Persona' : 'Actualizar Perfil' }}</h2>
                    <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
                <div class="dialogo-cuerpo">
                    <form action="{{ $esNuevo ? route('personas.store') : ($editandoId ? route('personas.update', $editandoId) : '') }}" method="POST" autocomplete="off" data-form-persona>
                        @csrf
                        @unless ($esNuevo) @method('PUT') @endunless
                        <input type="hidden" name="_dialogo" value="{{ $esNuevo ? 'crear' : ($editandoId ? 'editar-'.$editandoId : '') }}" data-campo-dialogo>
                        @if ($reabrir && $errors->any())
                            {{-- El aviso de la página queda detrás del diálogo: se repite aquí --}}
                            <div class="alert alert-danger small py-2" role="alert">
                                @foreach ($errors->all() as $error)<div><i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>{{ $error }}</div>@endforeach
                            </div>
                        @endif
                        @if ($esNuevo)
                            <input type="hidden" name="volver" value="{{ $volver ? 'proveedor' : '' }}" data-volver-proveedor>
                            @if ($volver && $nombreProveedor)
                                <p class="aviso-desde-proveedor" data-aviso-volver>
                                    <i class="bi bi-building me-1" aria-hidden="true"></i>
                                    Registrando personal de <strong>{{ $nombreProveedor }}</strong>.@if (Route::has('proveedores.show')) Al guardar regresarás a su ficha.@endif
                                </p>
                            @endif
                        @endif

                        <div class="row">
                            <div class="col-md-7">
                                <label class="campo-etiqueta" for="{{ $modo }}_pe_nombre">Nombre Completo</label>
                                <input type="text" id="{{ $modo }}_pe_nombre" name="nombre_completo" class="campo{{ $reabrir && $errors->has('nombre_completo') ? ' is-invalid' : '' }}" maxlength="150" value="{{ $valor('nombre_completo') }}" required>
                            </div>
                            <div class="col-md-5">
                                <label class="campo-etiqueta" for="{{ $modo }}_pe_tipo">Tipo</label>
                                <select id="{{ $modo }}_pe_tipo" name="tipo" class="campo" data-persona-tipo required>
                                    @foreach (\App\Models\Persona::TIPOS as $clave => $etiqueta)
                                        <option value="{{ $clave }}" @if ($clave === 'visitante') data-por-defecto @endif @selected($valor('tipo', 'visitante') === $clave)>{{ $etiqueta }}</option>
                                    @endforeach
                                </select>
                            </div>
                        </div>

                        <div data-solo-visitante>
                            <label class="campo-etiqueta" for="{{ $modo }}_pe_categoria">Categoría del Visitante</label>
                            <select id="{{ $modo }}_pe_categoria" name="categoria" class="campo">
                                @foreach ($categoriasUi as $clave => $etiqueta)
                                    <option value="{{ $clave }}" @if ($clave === 'general') data-por-defecto @endif @selected($valor('categoria', 'general') === $clave)>{{ $etiqueta }}</option>
                                @endforeach
                            </select>
                        </div>

                        <div class="caja-empresa-persona" data-solo-empresa>
                            <label class="campo-etiqueta" for="{{ $modo }}_pe_proveedor"><i class="bi bi-building me-1" aria-hidden="true"></i> Empresa que representa</label>
                            <select id="{{ $modo }}_pe_proveedor" name="proveedor_id" class="campo{{ $reabrir && $errors->has('proveedor_id') ? ' is-invalid' : '' }} mb-1" data-persona-proveedor>
                                <option value="" data-por-defecto>-- No está en el directorio de proveedores --</option>
                                @foreach ($opcionesProveedor as $prov)
                                    <option value="{{ $prov->id }}" data-categoria="{{ $prov->categoria }}" @selected($valor('proveedor_id') === (string) $prov->id)>{{ $prov->nombre }}{{ $prov->activo ? '' : ' (inactivo)' }}</option>
                                @endforeach
                            </select>
                            <p class="campo-ayuda mt-0">Si la empresa no aparece, déjalo así y escribe su nombre abajo.</p>
                        </div>

                        <div data-solo-sin-proveedor>
                            <label class="campo-etiqueta" for="{{ $modo }}_pe_procedencia">Empresa de procedencia <span class="text-lowercase fw-normal">(opcional)</span></label>
                            <input type="text" id="{{ $modo }}_pe_procedencia" name="empresa_procedencia" class="campo" maxlength="100" placeholder="Ej. Particular, Paquetería del Caribe..." value="{{ $valor('empresa_procedencia') }}">
                        </div>

                        <div class="row">
                            <div class="col-md-4">
                                <label class="campo-etiqueta" for="{{ $modo }}_pe_tipo_id">Identificación</label>
                                <select id="{{ $modo }}_pe_tipo_id" name="tipo_identificacion" class="campo{{ $reabrir && $errors->has('tipo_identificacion') ? ' is-invalid' : '' }}">
                                    @foreach (\App\Models\Persona::IDENTIFICACIONES as $clave => $etiqueta)
                                        <option value="{{ $clave }}" @if ($clave === 'ine') data-por-defecto @endif @selected($valor('tipo_identificacion', 'ine') === $clave)>{{ $etiqueta }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="col-md-4">
                                <label class="campo-etiqueta" for="{{ $modo }}_pe_folio">Folio / Número</label>
                                <input type="text" id="{{ $modo }}_pe_folio" name="folio_identificacion" class="campo{{ $reabrir && $errors->has('folio_identificacion') ? ' is-invalid' : '' }} text-uppercase" maxlength="40" autocapitalize="characters" value="{{ $valor('folio_identificacion') }}">
                            </div>
                            <div class="col-md-4">
                                <label class="campo-etiqueta" for="{{ $modo }}_pe_tel">Teléfono</label>
                                <input type="tel" inputmode="tel" id="{{ $modo }}_pe_tel" name="telefono" class="campo{{ $reabrir && $errors->has('telefono') ? ' is-invalid' : '' }}" maxlength="20" placeholder="10 dígitos" value="{{ $valor('telefono') }}">
                            </div>
                        </div>
                        <p class="campo-ayuda mt-0"><i class="bi bi-shield-lock" aria-hidden="true"></i> El folio no puede repetirse en la empresa; espacios y guiones no cuentan. Quien solo consulta el padrón lo ve oculto (••••1234).</p>

                        <label class="campo-etiqueta" for="{{ $modo }}_pe_motivo">Motivo de la Visita</label>
                        <textarea id="{{ $modo }}_pe_motivo" name="motivo_visita" class="campo" rows="2" maxlength="500" placeholder="Ej. Entrega de mercancía, entrevista de trabajo, visita familiar...">{{ $valor('motivo_visita') }}</textarea>

                        <div class="dialogo-acciones">
                            <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                            <button type="submit" class="btn-azul">{{ $esNuevo ? 'Guardar en Padrón' : 'Actualizar Cambios' }}</button>
                        </div>
                    </form>
                    @unless ($esNuevo)
                        @include('componentes.borrar', ['registro' => 'personas', 'id' => $editandoId])
                    @endunless
                </div>
            </dialog>
        @endforeach
    @endif
</div>
@endsection
