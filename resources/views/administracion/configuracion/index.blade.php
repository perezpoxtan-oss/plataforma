@extends('layouts.app')

@section('titulo', $tema === null ? 'Configuración' : $temaNombre.' · Configuración')

@php
    // Configuración en mosaico (pedido del dueño, 12 nov 2026): al entrar, buscador y una tarjeta por tema
    // (App\Support\TemasConfiguracion); cada tema abre solo sus secciones en /configuracion/{tema}.
    // Cada sección junta los ajustes de un tema, con sus avisos por correo (ADR-0016) y, si un ajuste vive
    // en otro módulo, su enlace «Configurar en …». Inventario completo: docs/tecnico/configuracion-inventario.md
    $u = auth()->user();
    // C-03: solo las secciones y avisos de los módulos que la empresa tiene contratados
    $autorizador = app(\App\Services\Permisos\Autorizador::class);
    $tiene = fn (string ...$modulos) => $empresa === null || collect($modulos)->contains(fn ($m) => $autorizador->moduloActivo((int) $empresa->id, $m));
    $mostrarAvisos = $empresa && $verConfiguracion;
    // Grupo de avisos => sección donde se muestra (los que no estén ahí van en «Avisos por correo»)
    // C-38: un solo lugar por ajuste — Colaboradores en Estructura, Procedimientos en la bitácora y las autorizaciones de visitas en la caseta
    $seccionDeGrupo = \App\Support\TemasConfiguracion::SECCION_DE_GRUPO;
    $gruposEn = fn (string $seccion) => $mostrarAvisos
        ? array_filter($grupos, fn ($def, $g) => ($seccionDeGrupo[$g] ?? 'sec-avisos') === $seccion && ($def['modulo'] === null || $tiene($def['modulo'])), ARRAY_FILTER_USE_BOTH) : [];
    $verPases = $empresa && $u->can('pases_salida.configurar');
    $verRecepcion = $empresa && $u->can('candidatos.configurar');
    $verBolsa = $empresa && $u->can('vacantes.configurar');
    $verNovedades = $mostrarAvisos && $u->can('novedades.ver');
    // Decisión 10: Equipos de acceso (ZKTeco, Hikvision) en «Caseta y accesos»
    $verEquipos = $empresa && $verConfiguracion && $tiene('accesos');
    // Qué secciones ve el rol lo decide el controlador ($secciones); aquí solo se pintan las del tema abierto
    $seccionesDelTema = $tema === null ? [] : $seccionesDelTema;
    $verSeccion = fn (string $ancla) => ($secciones[$ancla] ?? false) && in_array($ancla, $seccionesDelTema, true);
    // Con una sola sección, su título repite el del tema: queda para lectores de pantalla
    $tituloSeccion = count($seccionesDelTema) > 1 ? 'titulo-seccion-config' : 'titulo-seccion-config visually-hidden';
@endphp

@push('scripts')
    <script src="{{ asset('js/configuracion.js') }}?v={{ @filemtime(public_path('js/configuracion.js')) ?: '1' }}"></script>
@endpush

@section('contenido')
<div class="tema-azul" data-configuracion data-anclas='@json($mapaAnclas)'>
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    @if ($tema === null)
        {{-- ============================== Mosaico ============================== --}}
        <div class="encabezado-pantalla mb-3">
            <div class="icono"><i class="bi bi-sliders text-primary" aria-hidden="true"></i></div>
            <div>
                <h1>Configuración</h1>
                <p>Toca un tema para ver solo sus ajustes, o escribe qué quieres ajustar. Cada ajuste dice su valor original; lo que se configura en otra pantalla trae su botón «Configurar en …».</p>
            </div>
        </div>

        {{-- Versión instalada (ADR-0016) --}}
        <div class="version-instalada mb-3">
            <i class="bi bi-box-seam" aria-hidden="true"></i>
            <span>Versión instalada: <strong>{{ $version['numero'] ?? 'sin número (equipo de desarrollo)' }}</strong>@if ($version['instalada']) · desde el @fecha($version['instalada'], 'd/m/Y H:i')@endif</span>
        </div>

        @if ($temas !== [])
            {{-- Sin JavaScript manda ?q= y el servidor filtra; con JavaScript filtra al escribir (js/configuracion.js) --}}
            <form method="GET" action="{{ route('configuracion.index') }}" class="buscador-config mb-3" role="search" data-buscador-config>
                <label class="campo-etiqueta" for="buscar-config">¿Qué quieres ajustar?</label>
                <div class="buscador-config-fila">
                    <span class="buscador-config-campo">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <input type="search" id="buscar-config" name="q" class="campo mb-0" value="{{ $busqueda }}" maxlength="80" autocomplete="off"
                               placeholder="Por ejemplo: llaves, dos pasos, correo" aria-describedby="buscar-config-ayuda">
                    </span>
                    <button type="submit" class="btn-azul">Buscar</button>
                </div>
                <p id="buscar-config-ayuda" class="campo-ayuda buscador-config-ayuda">Busca por el nombre del ajuste o por una palabra (por ejemplo «horas», «firma» o «avisos»).</p>
            </form>

            {{-- Ajustes que coinciden: llevan directo a su tarjeta, resaltada --}}
            <section class="resultados-config mb-3" aria-labelledby="t-resultados-config" data-resultados-config @if ($busqueda === '') hidden @endif>
                <h2 id="t-resultados-config" class="h6 fw-bold mb-2">Ajustes que coinciden</h2>
                <ul class="lista-resultados-config">
                    @foreach ($ajustes as $a)
                        <li data-buscar="{{ $a['buscar'] }}" data-tema="{{ $a['slug'] }}" @unless ($a['coincide']) hidden @endunless>
                            <a href="{{ $a['url'] }}"><span class="fw-bold">{{ $a['nombre'] }}</span><span class="small text-muted"> · {{ $a['tema'] }}</span></a>
                        </li>
                    @endforeach
                </ul>
                <p class="small text-muted mb-0" data-sin-resultados @if ($busqueda === '' || collect($ajustes)->contains('coincide', true) || collect($temas)->contains('coincide', true)) hidden @endif>
                    No encontramos «<span data-texto-buscado>{{ $busqueda }}</span>». Prueba con otra palabra o toca un tema.
                </p>
            </section>

            <nav aria-label="Temas de la configuración">
                <ul class="mosaico-config">
                    @foreach ($temas as $slug => $t)
                        <li data-buscar="{{ $t['buscar'] }}" data-tema="{{ $slug }}" @unless ($t['coincide']) hidden @endunless>
                            <a href="{{ $t['url'] }}" class="tarjeta tarjeta-tema-config" id="tema-{{ $slug }}">
                                <span class="icono-tema-config"><i class="bi {{ $t['icono'] }}" aria-hidden="true"></i></span>
                                <span class="cuerpo-tema-config">
                                    <span class="nombre-tema-config">{{ $t['nombre'] }}
                                        @if ($t['cambiado'])<span class="insignia-cambiado" title="Tu empresa tiene ajustes distintos de los de fábrica">Cambiado</span>@endif
                                    </span>
                                    <span class="descripcion-tema-config">{{ $t['descripcion'] }}</span>
                                </span>
                                <i class="bi bi-chevron-right flecha-tema-config" aria-hidden="true"></i>
                            </a>
                        </li>
                    @endforeach
                </ul>
            </nav>
        @endif
        @unless ($empresa)
            <div class="tarjeta estado-vacio mt-3"><p class="text-muted small m-0">Elige arriba la empresa de trabajo para ver sus ajustes.</p></div>
        @endunless
    @else
        {{-- ============================== Un tema ============================== --}}
        <p class="mb-2"><a href="{{ route('configuracion.index') }}" class="volver-config"><i class="bi bi-chevron-left" aria-hidden="true"></i>Configuración</a></p>
        <div class="encabezado-pantalla mb-3">
            <div class="icono"><i class="bi {{ $temaIcono }} text-primary" aria-hidden="true"></i></div>
            <div>
                <h1>{{ $temaNombre }}</h1>
                <p>{{ $temaDescripcion }} Cada ajuste dice su valor original; lo que se configura en otra pantalla trae su botón «Configurar en …».</p>
            </div>
        </div>

        @if (count($seccionesDelTema) > 1)
            <nav class="indice-config mb-3" aria-label="Secciones de {{ $temaNombre }}">
                @foreach ($seccionesDelTema as $ancla)
                    <a href="#{{ $ancla }}" class="chip-config"><i class="bi {{ \App\Support\TemasConfiguracion::SECCIONES[$ancla][1] }} me-1" aria-hidden="true"></i>{{ \App\Support\TemasConfiguracion::SECCIONES[$ancla][0] }}</a>
                @endforeach
            </nav>
        @endif

    @if ($mostrarAvisos)
        {{-- Un solo formulario de avisos; sus casillas viven en la tarjeta de cada módulo (atributo form) --}}
        <form action="{{ route('configuracion.avisos') }}" method="POST" id="formAvisos" class="d-none">
            @csrf
            @method('PUT')
        </form>
    @endif

    <div class="secciones-config">
        {{-- ============================== General ============================== --}}
        @if ($verSeccion('sec-general'))
            <section class="seccion-config" id="sec-general" aria-labelledby="t-sec-general">
                <h2 id="t-sec-general" class="{{ $tituloSeccion }}"><i class="bi bi-sliders" aria-hidden="true"></i>General</h2>
                {{-- «Configura tu empresa»: volver a mostrarla (u ocultarla) en Inicio --}}
                @if ($u->can('configuracion.editar'))
                    @include('administracion.configuracion._configura-tu-empresa', ['empresa' => $empresa])
                @endif
                @isset($enOtras['empresa'])
                    <div class="tarjeta p-4 tarjeta-enlace-config" id="cfg-empresa">
                        <h3 class="h6 fw-bold"><i class="bi bi-building me-2 text-primary" aria-hidden="true"></i>Datos de la empresa</h3>
                        <p class="small mb-1">Zona horaria: <strong>{{ $enOtras['empresa']['zona'] }}</strong> <span class="valor-original">· Valor original: {{ \App\Support\ZonasHorarias::etiqueta('America/Mexico_City') }}</span>. Cada sede puede tener la suya (en Sedes).</p>
                        <p class="small text-muted mb-2">Nombre, razón social, RFC, logo y zona horaria se cambian en Empresas.</p>
                        <a href="{{ $enOtras['empresa']['url'] }}" class="btn-enlace-config"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Configurar en Empresas</a>
                    </div>
                @endisset
                {{-- C-22: «Reloj y suplente» (antes «Reloj del visto bueno») --}}
                @include('administracion.configuracion._relojes', ['empresa' => $empresa, 'puedeEditar' => $puedeEditar])
                @include('administracion.configuracion._menu', ['empresa' => $empresa, 'puedeEditar' => $puedeEditar])
            </section>
        @endif

        {{-- ========================== Caseta y accesos ========================== --}}
        @if ($verSeccion('caseta'))
            <section class="seccion-config" id="caseta" aria-labelledby="t-sec-caseta">
                <h2 id="t-sec-caseta" class="{{ $tituloSeccion }}"><i class="bi bi-door-open" aria-hidden="true"></i>Caseta y accesos</h2>
                @isset($enOtras['esperas'])
                    @php $e = $enOtras['esperas']; @endphp
                    <div class="tarjeta p-4 tarjeta-enlace-config" id="cfg-esperas">
                        <h3 class="h6 fw-bold"><i class="bi bi-hourglass-split me-2 text-primary" aria-hidden="true"></i>Quién espera autorización del departamento</h3>
                        <ul class="small mb-2">
                            @foreach ($e['tipos'] as [$tipo, $espera])
                                <li>{{ $tipo }}: <strong>{{ $espera ? 'espera la autorización si el departamento tiene quien autorice en la sede' : 'pasa sin esperar' }}</strong></li>
                            @endforeach
                            {{-- C-08 (A-03) y DD-11 --}}
                            <li>Sin quien autorice en la sede: <strong>{{ mb_strtolower($e['sinCobertura']) }}</strong>@if ($e['faltan'] > 0) · <span class="text-danger">hoy {{ $e['faltan'] === 1 ? '1 departamento no tiene' : $e['faltan'].' departamentos no tienen' }}</span>@endif</li>
                            <li>Quien viene con Recursos Humanos: <strong>{{ $e['rh'] ? 'la caseta espera el «Aprobar · que pase»' : 'pasa sin esperar' }}</strong></li>
                            <li>Recordar a los <strong>{{ $e['recordar'] }} min</strong> y avisar al suplente y al jefe a los <strong>{{ $e['escalar'] }} min</strong></li>
                        </ul>
                        <p class="small text-muted mb-2"><span class="valor-original">Valor original: todos esperan la autorización (sin quien autorice, esperan al supervisor); recordar a los {{ \App\Services\Recepcion\PanelRecepcion::ESPERA_AMBAR }} min y avisar a los {{ \App\Services\Recepcion\PanelRecepcion::ESPERA_ROJA }} min.</span> Quién autoriza en cada departamento y sus suplentes también se eligen ahí.</p>
                        <a href="{{ $e['url'] }}" class="btn-enlace-config"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Configurar en Responsables por departamento</a>
                    </div>
                @endisset
                @isset($enOtras['impresion'])
                    <div class="tarjeta p-4 tarjeta-enlace-config" id="cfg-impresion">
                        <h3 class="h6 fw-bold"><i class="bi bi-printer me-2 text-primary" aria-hidden="true"></i>Imprimir etiquetas desde los padrones</h3>
                        <p class="small mb-1">Hoy: <strong>{{ $enOtras['impresion']['texto'] }}</strong></p>
                        <p class="small text-muted mb-2"><span class="valor-original">Valor original: hoja propia de cada padrón.</span></p>
                        <a href="{{ $enOtras['impresion']['url'] }}" class="btn-enlace-config"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Configurar en Etiquetas QR → Plantillas</a>
                    </div>
                @endisset
                @if ($verEquipos)
                    @php $equiposAcceso = \App\Models\EquipoAcceso::where('activo', true)->get(['id', 'ultimo_contacto_at', 'created_at']); @endphp
                    <div class="tarjeta p-4 tarjeta-enlace-config" id="cfg-equipos-acceso">
                        <h3 class="h6 fw-bold"><i class="bi bi-router me-2 text-primary" aria-hidden="true"></i>Equipos de acceso</h3>
                        <p class="small mb-1">Hoy: <strong>{{ $equiposAcceso->isEmpty() ? 'ninguno' : $equiposAcceso->count().' equipo'.($equiposAcceso->count() === 1 ? '' : 's') }}</strong>. Lectores, torniquetes y checadores (ZKTeco, Hikvision) que registran solos la entrada y salida del personal.</p>
                        <p class="small text-muted mb-2"><span class="valor-original">Valor original: ninguno (la caseta registra a mano).</span></p>
                        <a href="{{ route('equipos-acceso.index') }}" class="btn-enlace-config"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Configurar en Equipos de acceso</a>
                    </div>
                @endif
                @if ($mostrarAvisos && $tiene('accesos'))
                    {{-- C-04: motivo de visita marcado (de fábrica, ninguno) y «Visita a huésped» --}}
                    @include('administracion.configuracion._caseta', ['empresa' => $empresa, 'puedeEditar' => $puedeEditar, 'tarjeta' => 'motivos'])
                    {{-- C-25: tiempo de permanencia y la noche sin supervisor --}}
                    @include('administracion.configuracion._caseta', ['empresa' => $empresa, 'puedeEditar' => $puedeEditar, 'tarjeta' => 'permanencia'])
                    @include('administracion.configuracion._caseta', ['empresa' => $empresa, 'puedeEditar' => $puedeEditar, 'tarjeta' => 'noche'])
                @endif
                @foreach ($gruposEn('caseta') as $grupo => $def)
                    @include('administracion.configuracion._avisos-grupo')
                @endforeach
            </section>
        @endif

        {{-- ============================== Estructura (C-38) ============================== --}}
        @if ($verSeccion('sec-estructura'))
            <section class="seccion-config" id="sec-estructura" aria-labelledby="t-sec-estructura">
                <h2 id="t-sec-estructura" class="{{ $tituloSeccion }}"><i class="bi bi-diagram-3" aria-hidden="true"></i>Estructura</h2>
                @foreach ($gruposEn('sec-estructura') as $grupo => $def)
                    @include('administracion.configuracion._avisos-grupo')
                @endforeach
                {{-- C-44 (Etapa 8): credencialización (vigencia, tecnología y reverso) --}}
                @include('administracion.configuracion._credenciales', ['empresa' => $empresa, 'puedeEditar' => $puedeEditar])
            </section>
        @endif

        {{-- ========================= Recepción y RR. HH. ========================= --}}
        @if ($verSeccion('sec-rh'))
            <section class="seccion-config" id="sec-rh" aria-labelledby="t-sec-rh">
                <h2 id="t-sec-rh" class="{{ $tituloSeccion }}"><i class="bi bi-people" aria-hidden="true"></i>Recepción y RR. HH.</h2>
                @foreach ($gruposEn('sec-rh') as $grupo => $def)
                    @include('administracion.configuracion._avisos-grupo')
                @endforeach
                {{-- Recepción de candidatos: aviso de privacidad y kiosco (ADR-0007) --}}
                @if ($verRecepcion)
                    @include('rh.recepcion._ajustes', ['empresa' => $empresa])
                @endif
                @isset($enOtras['plazos'])
                    <div class="tarjeta p-4 tarjeta-enlace-config" id="cfg-plazos">
                        <h3 class="h6 fw-bold"><i class="bi bi-calendar-check me-2 text-primary" aria-hidden="true"></i>Candidatos: plazos y documentos para contratar</h3>
                        <ul class="small mb-2">
                            @foreach ($enOtras['plazos']['lista'] as [$etiqueta, $vigente, $original])
                                <li>{{ $etiqueta }}: <strong>{{ $vigente }}</strong> <span class="valor-original">· original {{ $original }}</span></li>
                            @endforeach
                            <li>Documentos para contratar: <strong>{{ $enOtras['plazos']['documentos'] }}</strong></li>
                        </ul>
                        <a href="{{ $enOtras['plazos']['url'] }}" class="btn-enlace-config"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Configurar en Candidatos → Documentos y plazos</a>
                    </div>
                @endisset
                @isset($enOtras['criterios'])
                    <div class="tarjeta p-4 tarjeta-enlace-config" id="cfg-criterios">
                        <h3 class="h6 fw-bold"><i class="bi bi-star me-2 text-primary" aria-hidden="true"></i>Criterios para calificar las entrevistas</h3>
                        <p class="small mb-1">{{ implode(' · ', $enOtras['criterios']['lista']) }}</p>
                        <p class="small text-muted mb-2"><span class="valor-original">{{ $enOtras['criterios']['propios'] ? 'Tu empresa cambió la lista original.' : 'Es la lista original.' }}</span></p>
                        <a href="{{ $enOtras['criterios']['url'] }}" class="btn-enlace-config"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Configurar en Candidatos → Criterios de entrevista</a>
                    </div>
                @endisset
                {{-- Asistencia (ADR-0024): se configura en su pantalla («Avanzado») --}}
                @isset($enOtras['asistencia'])
                    <div class="tarjeta p-4 tarjeta-enlace-config" id="cfg-asistencia">
                        <h3 class="h6 fw-bold"><i class="bi bi-calendar2-check me-2 text-primary" aria-hidden="true"></i>Asistencia: tolerancia y aviso de faltas</h3>
                        <ul class="small mb-2">
                            <li>Tolerancia de retardo: <strong>{{ $enOtras['asistencia']['tolerancia'] }} min</strong> <span class="valor-original">· original {{ \App\Services\Asistencia\AjustesAsistencia::TOLERANCIA }} min</span></li>
                            <li>Aviso al jefe cuando alguien no llega: <strong>{{ $enOtras['asistencia']['aviso'] ? 'encendido' : 'apagado' }}</strong> <span class="valor-original">· original apagado</span></li>
                        </ul>
                        <a href="{{ $enOtras['asistencia']['url'] }}" class="btn-enlace-config"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Configurar en Asistencia → Avanzado</a>
                    </div>
                @endisset
                {{-- Vacantes: bolsa de trabajo en internet (lección 36) --}}
                @if ($verBolsa)
                    @include('rh.vacantes._ajustes-bolsa', ['empresa' => $empresa])
                @endif
            </section>
        @endif

        {{-- ============================ Novedades / Incidentes ============================ --}}
        @if ($verSeccion('sec-novedades'))
            <section class="seccion-config" id="sec-novedades" aria-labelledby="t-sec-novedades">
                <h2 id="t-sec-novedades" class="{{ $tituloSeccion }}"><i class="bi bi-headset" aria-hidden="true"></i>{{ $nombreBitacora }}</h2>
                @foreach ($gruposEn('sec-novedades') as $grupo => $def)
                    @include('administracion.configuracion._avisos-grupo')
                @endforeach
                {{-- C-38: Procedimientos (acuse «Leí y entendí» y recordatorio) junto a la bitácora («¿Qué hacer? Ver procedimiento») --}}
                @if ($mostrarAvisos && $tiene('procedimientos'))
                    @include('administracion.configuracion._caseta', ['empresa' => $empresa, 'puedeEditar' => $puedeEditar, 'tarjeta' => 'procedimientos'])
                @endif
                {{-- Mejoras Incidentes: Bitácora de novedades (nombre del menú y hallazgos de recorrido) --}}
                @if ($verNovedades)
                    @include('seguridad.novedades._config', ['empresa' => $empresa, 'puedeEditar' => $puedeEditar])
                @endif
                {{-- DD-17: frecuencia de revisión de los equipos de Protección Civil --}}
                @if ($mostrarAvisos && $tiene('equipos_pc'))
                    @include('administracion.configuracion._equipos-pc', ['empresa' => $empresa, 'puedeEditar' => $puedeEditar])
                @endif
                {{-- Lost & Found: días de resguardo (empresa) --}}
                @if ($lostFound)
                    <div class="tarjeta p-4 config-lost-found" id="lost-found">
                        <h3 id="t-lost-found" class="h6 fw-bold"><i class="bi bi-bag-fill me-2 text-warning" aria-hidden="true"></i>Lost &amp; Found: días de resguardo</h3>
                        <p class="small text-muted">Cuántos días puede estar un artículo en resguardo, según su clasificación, antes de que el semáforo lo marque para atención. Aplica a todas las sedes de {{ $empresa->nombre_comercial }}. También se cambian desde el botón <strong>Días de resguardo</strong> del archivo de Lost &amp; Found: es el mismo dato.</p>
                        @unless ($lostFound['puedeEditar'])
                            <div class="alert alert-secondary small py-2"><i class="bi bi-lock-fill me-1" aria-hidden="true"></i>Tu rol solo puede consultar esta configuración, no modificarla.</div>
                        @endunless
                        <form action="{{ route('configuracion.lost_found') }}" method="POST">
                            @csrf
                            @method('PUT')
                            {{-- Los mismos campos que el diálogo «Días de resguardo» del archivo de Lost & Found --}}
                            @include('seguridad.lost-found._dias-campos', ['dias' => $lostFound['dias'], 'guardados' => $lostFound['guardados'], 'puedeEditar' => $lostFound['puedeEditar'], 'prefijo' => ''])
                            @if ($lostFound['puedeEditar'])
                                <button type="submit" class="btn-azul mt-2" style="min-height:44px;border-radius:10px;padding:0 1.25rem;">Guardar días de resguardo</button>
                            @endif
                        </form>
                        @if ($lostFound['puedeEditar'] && $lostFound['dias'] !== array_replace($lostFound['dias'], \App\Models\LostFoundUmbral::POR_OMISION))
                            @include('administracion.configuracion._restablecer', ['ajuste' => 'lost_found', 'que' => 'los días de resguardo de Lost & Found'])
                        @endif
                    </div>
                @endif
            </section>
        @endif

        {{-- =============================== Activos =============================== --}}
        @if ($verSeccion('sec-activos'))
            <section class="seccion-config" id="sec-activos" aria-labelledby="t-sec-activos">
                <h2 id="t-sec-activos" class="{{ $tituloSeccion }}"><i class="bi bi-key" aria-hidden="true"></i>Activos</h2>
                @if ($tiene('prestamo_llaves'))
                    @include('administracion.configuracion._caseta', ['empresa' => $empresa, 'puedeEditar' => $puedeEditar, 'tarjeta' => 'llaves'])
                @endif
                @if ($tiene('responsivas'))
                    @include('administracion.configuracion._caseta', ['empresa' => $empresa, 'puedeEditar' => $puedeEditar, 'tarjeta' => 'responsivas'])
                @endif
                @foreach ($gruposEn('sec-activos') as $grupo => $def)
                    @include('administracion.configuracion._avisos-grupo')
                @endforeach
            </section>
        @endif

        {{-- ============================== Transporte ============================== --}}
        @if ($verSeccion('sec-transporte'))
            <section class="seccion-config" id="sec-transporte" aria-labelledby="t-sec-transporte">
                <h2 id="t-sec-transporte" class="{{ $tituloSeccion }}"><i class="bi bi-taxi-front" aria-hidden="true"></i>Transporte</h2>
                @include('administracion.configuracion._caseta', ['empresa' => $empresa, 'puedeEditar' => $puedeEditar, 'tarjeta' => 'transporte'])
                @foreach ($gruposEn('sec-transporte') as $grupo => $def)
                    @include('administracion.configuracion._avisos-grupo')
                @endforeach
            </section>
        @endif

        {{-- =========================== Pases de salida =========================== --}}
        @if ($verSeccion('sec-pases'))
            <section class="seccion-config" id="sec-pases" aria-labelledby="t-sec-pases">
                <h2 id="t-sec-pases" class="{{ $tituloSeccion }}"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>Pases de salida</h2>
                {{-- Pases de salida: circuito de aprobación (ver docs/tecnico/pases-salida.md) --}}
                @if ($verPases)
                    @include('seguridad.pases-salida._config-resumen', ['empresa' => $empresa])
                @endif
                @foreach ($gruposEn('sec-pases') as $grupo => $def)
                    @include('administracion.configuracion._avisos-grupo')
                @endforeach
            </section>
        @endif

        {{-- ========================= Reservas de áreas ========================= --}}
        @if ($verSeccion('sec-reservas'))
            <section class="seccion-config" id="sec-reservas" aria-labelledby="t-sec-reservas">
                <h2 id="t-sec-reservas" class="{{ $tituloSeccion }}"><i class="bi bi-calendar-check" aria-hidden="true"></i>Reservas de áreas</h2>
                @if ($u->can('reservas.configurar'))
                    @push('estilos')<link href="{{ asset('css/reservas.css') }}" rel="stylesheet">@endpush
                    @include('operacion.reservas._ajustes', ['empresa' => $empresa, 'desde' => 'configuracion',
                        'editable' => app(\App\Services\Permisos\Autorizador::class)->alcanceDeEmpresa($u, 'reservas.configurar')])
                    <div class="tarjeta p-4 tarjeta-enlace-config" id="cfg-reservas-areas">
                        <h3 class="h6 fw-bold"><i class="bi bi-door-open me-2 text-primary" aria-hidden="true"></i>Áreas reservables y catálogos</h3>
                        <p class="small text-muted mb-2">Qué salones y salas se reservan (aforo, montajes, horario, tarifas, responsable), montajes, tipos de uso y servicios con su departamento.</p>
                        <a href="{{ route('reservas.configuracion') }}" class="btn-enlace-config"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Configurar en Reservas de áreas</a>
                    </div>
                @endif
                @foreach ($gruposEn('sec-reservas') as $grupo => $def)
                    @include('administracion.configuracion._avisos-grupo')
                @endforeach
            </section>
        @endif

        {{-- =========================== Avisos por correo =========================== --}}
        @if ($verSeccion('sec-avisos'))
            <section class="seccion-config" id="sec-avisos" aria-labelledby="t-sec-avisos">
                <h2 id="t-sec-avisos" class="{{ $tituloSeccion }}"><i class="bi bi-envelope" aria-hidden="true"></i>Avisos por correo</h2>
                <div class="tarjeta p-4">
                    @unless ($correoListo)
                        <div class="alert alert-warning small py-2"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>El correo de la plataforma aún no está configurado: los avisos por correo se activarán cuando el Super Administrador lo configure. Los de la campana sí llegan.</div>
                    @endunless
                    <p class="small text-muted">Cada tema tiene sus avisos con un interruptor general. Aquí ves todos de un vistazo; toca uno para ir a sus casillas. Por omisión todos están encendidos, salvo los que dicen «Valor original: apagado».</p>
                    <ul class="lista-avisos-config small mb-2">
                        @foreach ($grupos as $grupo => $def)
                            @php $encendido = \App\Support\GruposAvisos::encendido($empresa, $grupo); @endphp
                            <li><a href="{{ \App\Support\TemasConfiguracion::url('config-'.$grupo) }}">{{ $def['nombre'] }}</a>: <strong>{{ $encendido ? 'encendidos' : 'apagados' }}</strong></li>
                        @endforeach
                    </ul>
                    <p class="small text-muted mb-0">Cada persona puede silenciar los avisos que no quiere recibir en <a href="{{ route('mi-cuenta.index') }}">Mi cuenta → Mis avisos</a>.</p>
                    @if ($puedeEditar)
                        @include('administracion.configuracion._restablecer', ['ajuste' => 'avisos', 'que' => 'todos los avisos por correo (los correos que capturaste se conservan)'])
                    @endif
                </div>
                @foreach ($gruposEn('sec-avisos') as $grupo => $def)
                    @include('administracion.configuracion._avisos-grupo')
                @endforeach
            </section>
        @endif

        {{-- ========================= Cuentas y seguridad ========================= --}}
        @if ($verSeccion('sec-cuentas'))
            <section class="seccion-config" id="sec-cuentas" aria-labelledby="t-sec-cuentas">
                <h2 id="t-sec-cuentas" class="{{ $tituloSeccion }}"><i class="bi bi-shield-lock" aria-hidden="true"></i>Cuentas y seguridad</h2>
                @foreach ($gruposEn('sec-cuentas') as $grupo => $def)
                    @include('administracion.configuracion._avisos-grupo')
                @endforeach
                {{-- Verificación en dos pasos: a quién se exige (docs/tecnico/dos-pasos.md) --}}
                @if ($dosPasos)
                    @include('administracion.configuracion._dos-pasos', ['dosPasos' => $dosPasos, 'puedeEditar' => $puedeEditar])
                @endif
                <div class="tarjeta p-4" id="cfg-sesion">
                    <h3 class="h6 fw-bold"><i class="bi bi-hourglass-bottom me-2 text-primary" aria-hidden="true"></i>Cierre de sesión por inactividad</h3>
                    <p class="small mb-0">La sesión se cierra sola tras <strong>{{ (int) config('plataforma.sesion.inactividad_minutos') }} minutos</strong> sin actividad, en todas las empresas. <span class="valor-original">Valor original: 20 minutos.</span> Es un ajuste de la plataforma: si necesitas otro tiempo, pídelo a quien administra la plataforma.</p>
                </div>
            </section>
        @endif

        {{-- ============================== Privacidad ============================== --}}
        @if ($verSeccion('sec-privacidad'))
            <section class="seccion-config" id="sec-privacidad" aria-labelledby="t-sec-privacidad">
                <h2 id="t-sec-privacidad" class="{{ $tituloSeccion }}"><i class="bi bi-person-lock" aria-hidden="true"></i>Privacidad</h2>
                @include('administracion.configuracion._privacidad', ['p' => $privacidad, 'empresa' => $empresa])
            </section>
        @endif

        {{-- ============================== Plataforma ============================== --}}
        @if ($verSeccion('sec-plataforma'))
            <div class="seccion-config" id="sec-plataforma">
                <h2 id="t-sec-plataforma" class="{{ $tituloSeccion }}"><i class="bi bi-hdd-network" aria-hidden="true"></i>Plataforma</h2>
                <div class="tarjeta p-4 tarjeta-enlace-config">
                    <h3 class="h6 fw-bold"><i class="bi bi-gear-wide-connected me-2 text-primary" aria-hidden="true"></i>Ajustes de toda la plataforma</h3>
                    <p class="small text-muted">Solo tú (Super Administrador) ves esta sección. No son de una empresa: aplican a todas.</p>
                    <ul class="lista-enlaces-config small mb-0">
                        @if (auth()->user()->can('identidad.ver'))
                            <li><a href="{{ route('identidad.edit') }}">Identidad de la plataforma</a>: nombre, logotipo y colores.</li>
                        @endif
                        @if (auth()->user()->can('catalogo_comercial.ver'))
                            <li><a href="{{ route('catalogo-comercial.index', ['pestana' => 'ajustes']) }}">Catálogo comercial → Ajustes</a>: moneda, IVA, página pública y suscripciones (días de prueba, días de gracia, descuento anual y avisos de vencimiento).</li>
                            <li><a href="{{ route('catalogo-comercial.index', ['pestana' => 'clientes']) }}">Catálogo comercial → Clientes</a>: plan, sedes, usuarios y excepciones de cada cliente.</li>
                        @endif
                        <li><a href="{{ route('empresas.index') }}">Empresas</a>: módulos contratados de cada empresa (botón de la cuadrícula).</li>
                        <li>Super Administradores, versión de PHP del servidor, correo de la plataforma y respaldos: aquí abajo.</li>
                    </ul>
                </div>
                {{-- C-36 (DD-21, E-14): dos Super Administradores como mínimo; uno restablece al otro sin terminal --}}
                @include('administracion.configuracion._superadmins', ['sa' => $superadmins])
                @include('administracion.configuracion._version-php', ['php' => $php])
            </div>
            {{-- ================= Correo saliente (plataforma) ================= --}}
            <section class="tarjeta p-4" id="cfg-correo" aria-labelledby="t-correo">
                <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                    <h2 id="t-correo" class="h5 fw-bold m-0"><i class="bi bi-envelope-at me-2 text-primary" aria-hidden="true"></i>Correo de la plataforma</h2>
                    <span class="etiqueta-estado {{ $correoListo ? 'activo' : 'inactivo' }}">{{ $correoListo ? 'CONFIGURADO' : 'SIN CONFIGURAR' }}</span>
                </div>
                <p class="small text-muted mt-2">Cuenta desde la que salen los avisos. Los datos te los da tu proveedor de correo (servidor de salida, puerto, usuario y contraseña de la cuenta). Solo tú (Super Administrador) ves esta sección.</p>
                @if ($correo['ultimo_error'])
                    <div class="alert alert-danger small py-2"><i class="bi bi-x-octagon me-1" aria-hidden="true"></i>Último error: {{ $correo['ultimo_error'] }}</div>
                @elseif ($correo['ultimo_envio'])
                    <p class="small text-success"><i class="bi bi-check-circle me-1" aria-hidden="true"></i>Último envío correcto: @fecha($correo['ultimo_envio'])</p>
                @endif

                <form action="{{ route('configuracion.correo') }}" method="POST" autocomplete="off" data-conservar-al-cerrar>
                    @csrf
                    @method('PUT')
                    <div class="campo-grupo">
                        <div style="flex:3"><label class="campo-etiqueta" for="c_host">Servidor SMTP</label><input type="text" id="c_host" name="host" class="campo" maxlength="150" placeholder="mail.tudominio.com" value="{{ old('host', $correo['host']) }}" required></div>
                        <div style="flex:1"><label class="campo-etiqueta" for="c_puerto">Puerto</label><input type="number" id="c_puerto" name="puerto" class="campo" min="1" max="65535" value="{{ old('puerto', $correo['puerto']) }}" required></div>
                    </div>
                    <label class="campo-etiqueta" for="c_cifrado">Cifrado</label>
                    <select id="c_cifrado" name="cifrado" class="campo">
                        @foreach ($cifrados as $valor => $etiqueta)
                            <option value="{{ $valor }}" @selected(old('cifrado', $correo['cifrado']) === $valor)>{{ $etiqueta }}</option>
                        @endforeach
                    </select>
                    <div class="campo-grupo">
                        <div style="flex:1"><label class="campo-etiqueta" for="c_usuario">Usuario</label><input type="text" id="c_usuario" name="usuario" class="campo" maxlength="150" placeholder="avisos@tudominio.com" value="{{ old('usuario', $correo['usuario']) }}" autocomplete="off"></div>
                        <div style="flex:1">
                            <label class="campo-etiqueta" for="c_contrasena">Contraseña</label>
                            <input type="password" id="c_contrasena" name="contrasena" class="campo" maxlength="200" autocomplete="new-password"
                                   placeholder="{{ $tieneContrasena ? '•••••••• (guardada; escribe solo para cambiarla)' : 'Contraseña de la cuenta' }}">
                        </div>
                    </div>
                    @if ($tieneContrasena)
                        <label class="small d-flex align-items-center gap-2 mb-2"><input type="checkbox" name="quitar_contrasena" value="1"> Quitar la contraseña guardada</label>
                    @endif
                    <div class="campo-grupo">
                        <div style="flex:1"><label class="campo-etiqueta" for="c_rem">Correo del remitente</label><input type="email" id="c_rem" name="remitente_correo" class="campo" maxlength="150" placeholder="avisos@tudominio.com" value="{{ old('remitente_correo', $correo['remitente_correo']) }}" required></div>
                        <div style="flex:1"><label class="campo-etiqueta" for="c_nom">Nombre del remitente <span class="text-lowercase fw-normal">(opcional)</span></label><input type="text" id="c_nom" name="remitente_nombre" class="campo" maxlength="80" placeholder="Avisos de seguridad" value="{{ old('remitente_nombre', $correo['remitente_nombre']) }}"></div>
                    </div>
                    <p class="campo-ayuda"><i class="bi bi-shield-lock" aria-hidden="true"></i> La contraseña se guarda cifrada y nunca se vuelve a mostrar.</p>
                    <button type="submit" class="btn-azul" style="min-height:44px;border-radius:10px;padding:0 1.25rem;">Guardar correo</button>
                </form>

                @if ($correoListo)
                    <hr class="my-4">
                    <form action="{{ route('configuracion.correo.prueba') }}" method="POST" class="campo-grupo align-items-end">
                        @csrf
                        <div style="flex:3"><label class="campo-etiqueta" for="c_para">Enviar correo de prueba a</label><input type="email" id="c_para" name="para" class="campo mb-0" value="{{ auth()->user()->email }}" required></div>
                        <div><button type="submit" class="btn btn-outline-dark fw-bold" style="min-height:44px;border-radius:10px;"><i class="bi bi-send me-1" aria-hidden="true"></i>Enviar prueba</button></div>
                    </form>
                @endif
            </section>

            {{-- ================= Respaldos (plataforma) ================= --}}
            <section class="tarjeta p-4" id="cfg-respaldos" aria-labelledby="t-respaldos">
                <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
                    <h2 id="t-respaldos" class="h5 fw-bold m-0"><i class="bi bi-database-check me-2 text-primary" aria-hidden="true"></i>Respaldos de la base de datos</h2>
                    <form action="{{ route('configuracion.respaldos') }}" method="POST" class="m-0" data-confirmar="¿Crear un respaldo ahora? Tarda unos segundos.">
                        @csrf
                        <button type="submit" class="btn-azul" style="min-height:44px;border-radius:10px;padding:0 1rem;"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Respaldar ahora</button>
                    </form>
                </div>
                <p class="small text-muted mt-2">Se hace uno automático cada día después de las 3:00 y otro antes de instalar cada versión nueva. Se conservan {{ \App\Services\Respaldos\Respaldos::DIAS }} días. Para restaurar: descarga el archivo y pide a tu proveedor de hospedaje o a tu administrador de base de datos que lo importe.</p>

                @if ($respaldos->isEmpty())
                    <p class="text-muted small m-0">Todavía no hay respaldos.</p>
                @else
                    <div class="lista-respaldos">
                        @foreach ($respaldos as $r)
                            <div class="fila-respaldo">
                                <span><i class="bi bi-file-earmark-zip me-1" aria-hidden="true"></i><strong>@fecha($r['fecha'])</strong></span>
                                <span class="chip-catalogo">{{ ['diario' => 'Diario', 'antes-de-actualizar' => 'Antes de actualizar', 'antes-de-limpiar-qa' => 'Antes de limpiar la base de pruebas', 'manual' => 'Manual'][$r['motivo']] ?? $r['motivo'] }}</span>
                                <span class="small text-muted">{{ number_format($r['bytes'] / 1024, 1) }} KB</span>
                                <a href="{{ route('configuracion.respaldos.descargar', $r['archivo']) }}" class="btn-icono editar" title="Descargar" aria-label="Descargar respaldo del @fecha($r['fecha'])"><i class="bi bi-download" aria-hidden="true"></i></a>
                            </div>
                        @endforeach
                    </div>
                @endif
                <p class="campo-ayuda mt-2 mb-0"><i class="bi bi-shield-exclamation" aria-hidden="true"></i> Un respaldo contiene TODA la información (incluidos datos personales). Guárdalo en un lugar seguro; cada descarga queda en la Bitácora de auditoría.</p>
                {{-- Respaldos y traslado: base + fotos, documentos y firmas en un solo paquete --}}
                <a href="{{ route('traslado.index') }}" class="btn btn-sm btn-outline-primary mt-2"><i class="bi bi-truck me-1" aria-hidden="true"></i>Respaldos y traslado a otro servidor</a>
            </section>
        @endif
    </div>
    @endif
</div>
@endsection
