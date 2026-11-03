@extends('layouts.app')

@section('titulo', 'Equipos de acceso')

@section('contenido')
{{-- Decisión 10: equipos de acceso ZKTeco y Hikvision (docs/tecnico/equipos-acceso.md). Lo nuevo vive aquí, en Configuración: la caseta no cambia. --}}
<div class="tema-azul">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    <div class="encabezado-pantalla mb-3">
        <div class="icono"><i class="bi bi-router text-primary" aria-hidden="true"></i></div>
        <div>
            <h1>Equipos de acceso</h1>
            <p>Lectores, torniquetes y checadores que registran solos la entrada y salida del personal en la Bitácora de accesos.</p>
        </div>
    </div>

    @if ($sinEmpresa)
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-buildings" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong> para ver sus equipos.</p>
        </div>
    @else
        @php
            $dialogo = old('_dialogo');
            $editandoId = is_string($dialogo) && str_starts_with($dialogo, 'editar-') ? (int) substr($dialogo, 7) : null;
            $puedeCrear = $sedesAlta->isNotEmpty();
            $estadoDe = function (\App\Models\EquipoAcceso $e) use ($ajustes) {
                if (! $e->activo) {
                    return ['DE BAJA', 'inactivo', 'La plataforma no acepta sus llamadas.'];
                }
                if ($e->ultimo_contacto_at === null) {
                    return ['SIN CONECTAR', 'pendiente', 'Todavía no llama a la plataforma: configúralo con los pasos de abajo.'];
                }
                if ($e->ultimo_contacto_at->lt(now()->subHours($ajustes['sin_conexion_horas']))) {
                    return ['SIN CONEXIÓN', 'inactivo', 'Revisa que tenga corriente e internet.'];
                }

                // ZKTeco pregunta cada 30 s; Hikvision solo llama con un paso o su latido
                return $e->marca !== 'zkteco' || $e->enLinea(5)
                    ? ['EN LÍNEA', 'activo', null] : ['SIN RESPUESTA', 'pendiente', 'Hace unos minutos que no llama; si sigue así, se avisa al jefe de seguridad.'];
            };
        @endphp

        @if (is_array($tokenNuevo))
            @php $equipoToken = $equipos->firstWhere('id', $tokenNuevo['id']); @endphp
            @if ($equipoToken && $equipoToken->marca === 'hikvision')
                <div class="alert alert-warning aviso mb-3" role="status">
                    <i class="bi bi-key-fill" aria-hidden="true"></i>
                    <div>
                        <strong>Token de «{{ $equipoToken->nombre }}»: cópialo ahora, no se vuelve a mostrar.</strong>
                        <div class="mt-1"><code class="token-equipo">{{ route('equipos.hikvision.eventos', $tokenNuevo['token']) }}</code></div>
                        <button type="button" class="btn-enlace-config mt-2" data-copiar-enlace="{{ route('equipos.hikvision.eventos', $tokenNuevo['token']) }}"><i class="bi bi-clipboard me-1" aria-hidden="true"></i>Copiar dirección</button>
                    </div>
                </div>
            @endif
        @endif

        <div class="fichas-grid">
            @if ($puedeCrear)
                <button type="button" class="ficha-card ficha-create" data-abrir-dialogo="dialogoNuevoEquipo">
                    <i class="bi bi-plus-circle-fill" aria-hidden="true"></i>
                    <span class="h6 fw-bold m-0 mt-2 titulo-crear">Nuevo equipo</span>
                </button>
            @endif

            @forelse ($equipos as $e)
                @php
                    [$pastilla, $clase, $explica] = $estadoDe($e);
                    $conector = \App\Services\EquiposAcceso\Conectores::para($e->marca);
                    $editable = $puedeEditar($e);
                    $tokenDeEste = is_array($tokenNuevo) && $tokenNuevo['id'] === $e->id ? $tokenNuevo['token'] : null;
                @endphp
                <div class="ficha-card" id="equipo-{{ $e->id }}">
                    <div>
                        <h2 class="ficha-title">{{ $e->nombre }}</h2>
                        <div class="small text-muted">{{ $marcas[$e->marca] ?? $e->marca }}@if ($e->modelo) · {{ $e->modelo }}@endif · Serie {{ $e->numero_serie }}</div>
                        <ul class="small mb-2 mt-2 list-unstyled">
                            <li><i class="bi bi-geo-alt me-1" aria-hidden="true"></i>{{ $e->sede?->nombre }}@if ($e->puerta) · {{ $e->puerta }}@endif</li>
                            <li><i class="bi bi-arrow-left-right me-1" aria-hidden="true"></i>{{ \App\Models\EquipoAcceso::SENTIDOS[$e->sentido] ?? $e->sentido }}</li>
                            <li><i class="bi bi-broadcast me-1" aria-hidden="true"></i>Última llamada: {{ $e->ultimo_contacto_at ? \Carbon\Carbon::parse($e->ultimo_contacto_at)->diffForHumans() : 'nunca' }}</li>
                            <li><i class="bi bi-person-check me-1" aria-hidden="true"></i>Último marcaje: {{ $e->ultimo_marcaje_at ? \Carbon\Carbon::parse($e->ultimo_marcaje_at)->diffForHumans() : 'ninguno' }}</li>
                            @if ($conector->sincronizaUsuarios())
                                <li><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Altas y bajas por entregar: {{ $e->pendientes }}@if ($e->errores > 0) · <span class="text-danger">{{ $e->errores }} que el equipo no aceptó</span>@endif</li>
                            @else
                                <li class="text-muted"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Las altas y bajas de tarjetas se cargan en el equipo (o con el puente local, cuando exista).</li>
                            @endif
                        </ul>
                        @if ($explica)
                            <p class="small text-muted mb-2">{{ $explica }}</p>
                        @endif

                        <details class="mt-1" @if ($tokenDeEste || $e->ultimo_contacto_at === null) open @endif>
                            <summary class="small fw-semibold">Cómo configurarlo</summary>
                            <ol class="small mt-2">
                                @foreach ($conector->comoConfigurar($e, $tokenDeEste) as $paso)
                                    <li>{{ $paso }}</li>
                                @endforeach
                            </ol>
                        </details>
                        <details class="mt-1">
                            <summary class="small fw-semibold">Últimos marcajes</summary>
                            @php $ultimos = $marcajes[$e->id] ?? collect(); @endphp
                            @if ($ultimos->isEmpty())
                                <p class="small text-muted mt-2 mb-0">Aún no llega ninguno.</p>
                            @else
                                <ul class="small mt-2 mb-0 list-unstyled">
                                    @foreach ($ultimos as $m)
                                        <li>@fecha($m->marcado_at ?? $m->created_at, 'd/m H:i') · {{ $m->colaborador?->nombreCompleto() ?? $m->identificador ?? '—' }} · <strong>{{ \App\Models\MarcajeEquipoAcceso::RESULTADOS[$m->resultado] ?? $m->resultado }}</strong>@if ($m->detalle && ! in_array($m->resultado, ['entrada', 'salida'], true)) <span class="text-muted">({{ $m->detalle }})</span>@endif</li>
                                    @endforeach
                                </ul>
                            @endif
                        </details>
                    </div>
                    <div class="ficha-footer">
                        <span class="etiqueta-estado {{ $clase }}">{{ $pastilla }}</span>
                        @if ($editable)
                            <div class="d-flex gap-2 flex-wrap justify-content-end">
                                @if ($e->activo && $conector->sincronizaUsuarios())
                                    <form action="{{ route('equipos-acceso.sincronizar', $e->id) }}" method="POST" class="m-0">
                                        @csrf
                                        <button type="submit" class="btn-enlace-config" title="Pone en cola las altas y bajas para el equipo"><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Sincronizar</button>
                                    </form>
                                @endif
                                @if ($e->activo && $e->marca === 'hikvision')
                                    <form action="{{ route('equipos-acceso.token', $e->id) }}" method="POST" class="m-0"
                                          data-confirmar="¿Generar otro token para «{{ $e->nombre }}»? El actual deja de servir y tendrás que escribir el nuevo en el equipo.">
                                        @csrf
                                        <button type="submit" class="btn-icono" title="Generar otro token" aria-label="Generar otro token de {{ $e->nombre }}"><i class="bi bi-key" aria-hidden="true"></i></button>
                                    </form>
                                @endif
                                <button type="button" class="btn-icono editar" title="Editar" aria-label="Editar {{ $e->nombre }}" data-abrir-dialogo="dialogoEquipo{{ $e->id }}"><i class="bi bi-pencil-square" aria-hidden="true"></i></button>
                                <form action="{{ route('equipos-acceso.estado', $e->id) }}" method="POST" class="m-0"
                                      data-confirmar="{{ $e->activo ? '¿Dar de baja «'.$e->nombre.'»? Sus marcajes dejan de registrarse y la caseta registra a mano.' : '¿Reactivar «'.$e->nombre.'»?' }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="activo" value="{{ $e->activo ? 0 : 1 }}">
                                    @if ($e->activo)
                                        <button type="submit" class="btn-icono eliminar" title="Dar de baja" aria-label="Dar de baja {{ $e->nombre }}"><i class="bi bi-slash-circle" aria-hidden="true"></i></button>
                                    @else
                                        <button type="submit" class="btn-icono reactivar" title="Reactivar" aria-label="Reactivar {{ $e->nombre }}"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></button>
                                    @endif
                                </form>
                            </div>
                        @endif
                    </div>
                </div>
            @empty
                @unless ($puedeCrear)
                    <div class="tarjeta estado-vacio"><p class="text-muted small m-0">Todavía no hay equipos de acceso registrados.</p></div>
                @endunless
            @endforelse
        </div>

        {{-- Aviso de «sin conexión» (reloj y suplente) --}}
        <div class="tarjeta p-4 mt-3" id="ajustes-equipos">
            <h2 class="h6 fw-bold"><i class="bi bi-alarm me-2 text-primary" aria-hidden="true"></i>Cuando un equipo se queda sin conexión</h2>
            <form action="{{ route('equipos-acceso.ajustes') }}" method="POST">
                @csrf
                @method('PUT')
                <label class="campo-etiqueta" for="eq_sin_conexion">Avisar al jefe de seguridad después de</label>
                <div class="d-flex align-items-center gap-2">
                    <input type="number" id="eq_sin_conexion" name="sin_conexion_horas" class="campo campo-horas-caseta" min="{{ \App\Support\AjustesEquiposAcceso::MINIMO }}" max="{{ \App\Support\AjustesEquiposAcceso::MAXIMO }}"
                           value="{{ old('sin_conexion_horas', $ajustes['sin_conexion_horas']) }}" @disabled(! $editaAjustes)>
                    <span class="small text-muted">horas sin que el equipo llame</span>
                </div>
                <p class="campo-ayuda mt-1">Al doble del tiempo pasa a su suplente. <span class="valor-original">Valor original: 2 horas.</span></p>
                @if ($editaAjustes)
                    <button type="submit" class="btn-azul mt-1">Guardar</button>
                @endif
            </form>
        </div>

        {{-- ===== Alta y edición ===== --}}
        @php
            $formularios = $puedeCrear ? [null] : [];
            foreach ($equipos as $e) {
                if ($puedeEditar($e)) {
                    $formularios[] = $e;
                }
            }
        @endphp
        @foreach ($formularios as $e)
            @php
                $esNuevo = $e === null;
                $idDialogo = $esNuevo ? 'dialogoNuevoEquipo' : 'dialogoEquipo'.$e->id;
                $marcaDialogo = $esNuevo ? 'crear' : 'editar-'.$e->id;
                $reabrir = $dialogo === $marcaDialogo;
                $v = fn (string $campo, $omision = '') => $reabrir ? old($campo, $omision) : ($esNuevo ? $omision : ($e->{$campo} ?? $omision));
                $pref = $esNuevo ? 'nuevo_eq' : 'eq'.$e->id;
            @endphp
            <dialog id="{{ $idDialogo }}" class="dialogo" aria-labelledby="titulo-{{ $pref }}" @if ($reabrir) data-abrir-al-cargar @endif>
                <div class="dialogo-cabecera">
                    <h2 id="titulo-{{ $pref }}"><i class="bi {{ $esNuevo ? 'bi-router' : 'bi-pencil-square' }} me-2 text-primary" aria-hidden="true"></i>{{ $esNuevo ? 'Nuevo equipo de acceso' : 'Editar '.$e->nombre }}</h2>
                    <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
                </div>
                <div class="dialogo-cuerpo">
                    <form action="{{ $esNuevo ? route('equipos-acceso.store') : route('equipos-acceso.update', $e->id) }}" method="POST" autocomplete="off">
                        @csrf
                        @unless ($esNuevo) @method('PUT') @endunless
                        <input type="hidden" name="_dialogo" value="{{ $marcaDialogo }}">

                        @if ($esNuevo)
                            <label class="campo-etiqueta" for="{{ $pref }}_marca">Marca</label>
                            <select id="{{ $pref }}_marca" name="marca" class="campo" required>
                                @foreach ($marcas as $clave => $nombre)
                                    <option value="{{ $clave }}" @selected($v('marca', 'zkteco') === $clave)>{{ $nombre }}</option>
                                @endforeach
                            </select>
                        @endif
                        <label class="campo-etiqueta" for="{{ $pref }}_sede">Sede</label>
                        <select id="{{ $pref }}_sede" name="sede_id" class="campo" required>
                            @foreach ($sedesAlta as $s)
                                <option value="{{ $s->id }}" @selected((int) $v('sede_id', $sedesAlta->first()?->id) === $s->id)>{{ $s->nombre }}</option>
                            @endforeach
                        </select>
                        <label class="campo-etiqueta" for="{{ $pref }}_nombre">Nombre</label>
                        <input type="text" id="{{ $pref }}_nombre" name="nombre" class="campo" maxlength="80" placeholder="Ej. Torniquete de personal" value="{{ $v('nombre') }}" required>
                        <label class="campo-etiqueta" for="{{ $pref }}_serie">Número de serie</label>
                        <input type="text" id="{{ $pref }}_serie" name="numero_serie" class="campo" maxlength="40" value="{{ $v('numero_serie') }}" required>
                        <p class="campo-ayuda mt-0">Viene en la etiqueta del equipo o en su menú «Información del sistema».</p>
                        <label class="campo-etiqueta" for="{{ $pref }}_puerta">Puerta (opcional)</label>
                        <input type="text" id="{{ $pref }}_puerta" name="puerta" class="campo" maxlength="80" placeholder="Ej. Acceso de personal" value="{{ $v('puerta') }}">
                        <label class="campo-etiqueta" for="{{ $pref }}_sentido">Registra</label>
                        <select id="{{ $pref }}_sentido" name="sentido" class="campo" required>
                            @foreach (\App\Models\EquipoAcceso::SENTIDOS as $clave => $texto)
                                <option value="{{ $clave }}" @selected($v('sentido', 'ambos') === $clave)>{{ $texto }}</option>
                            @endforeach
                        </select>
                        <details class="mt-2" @if ($v('modelo') || $v('ips_permitidas')) open @endif>
                            <summary class="small fw-semibold">Avanzado</summary>
                            <label class="campo-etiqueta" for="{{ $pref }}_modelo">Modelo (opcional)</label>
                            <input type="text" id="{{ $pref }}_modelo" name="modelo" class="campo" maxlength="60" value="{{ $v('modelo') }}">
                            <label class="campo-etiqueta" for="{{ $pref }}_ips">IP públicas desde las que llama (opcional)</label>
                            <input type="text" id="{{ $pref }}_ips" name="ips_permitidas" class="campo" maxlength="255" placeholder="Ej. 201.150.10.20" value="{{ $v('ips_permitidas') }}">
                            <p class="campo-ayuda mt-0">Recomendado para ZKTeco: si la red del cliente tiene IP fija, solo se aceptan sus llamadas desde ahí.</p>
                        </details>
                        <div class="dialogo-acciones">
                            <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                            <button type="submit" class="btn-azul">Guardar</button>
                        </div>
                    </form>
                </div>
            </dialog>
        @endforeach
    @endif
</div>
@endsection
