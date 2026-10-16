@extends('layouts.app')

@section('titulo', 'Configuración')

@php
    // Configuración por secciones (petición del dueño, 2026-10-15): cada sección junta los ajustes de un tema,
    // con sus avisos por correo (ADR-0016) y, si un ajuste vive en otro módulo, su enlace «Configurar en …».
    // Inventario completo: docs/tecnico/configuracion-inventario.md
    $u = auth()->user();
    $mostrarAvisos = $empresa && $verConfiguracion;
    $nombreBitacora = $empresa ? app(\App\Services\Novedades\AjustesNovedades::class)->nombreMenu($empresa->id) : 'Novedades';
    // Grupo de avisos => sección donde se muestra (los que no estén aquí van en «Avisos por correo»)
    $seccionDeGrupo = ['cuentas' => 'sec-cuentas', 'colaboradores' => 'caseta', 'padrones' => 'caseta', 'procedimientos' => 'caseta',
        'novedades' => 'sec-novedades', 'pases_salida' => 'sec-pases', 'transporte' => 'sec-transporte', 'vouchers' => 'sec-activos', 'candidatos' => 'sec-rh', 'reservas' => 'sec-reservas'];
    $gruposEn = fn (string $seccion) => $mostrarAvisos
        ? array_filter($grupos, fn ($g) => ($seccionDeGrupo[$g] ?? 'sec-avisos') === $seccion, ARRAY_FILTER_USE_KEY) : [];
    $verPases = $empresa && $u->can('pases_salida.configurar');
    $verRecepcion = $empresa && $u->can('candidatos.configurar');
    $verBolsa = $empresa && $u->can('vacantes.configurar');
    $verNovedades = $mostrarAvisos && $u->can('novedades.ver');
    $plataforma = $esSuperadmin;
    // [ancla, nombre, icono, ¿se muestra?]
    $secciones = [
        ['sec-general', 'General', 'bi-sliders', $mostrarAvisos],
        ['caseta', 'Caseta y accesos', 'bi-door-open', $mostrarAvisos || isset($enOtras['esperas']) || isset($enOtras['impresion'])],
        ['sec-rh', 'Recepción y RR. HH.', 'bi-people', $mostrarAvisos || $verRecepcion || $verBolsa],
        ['sec-novedades', $nombreBitacora, 'bi-headset', $mostrarAvisos || $lostFound],
        ['sec-activos', 'Activos', 'bi-key', $mostrarAvisos],
        ['sec-transporte', 'Transporte', 'bi-taxi-front', $mostrarAvisos],
        ['sec-pases', 'Pases de salida', 'bi-box-arrow-up-right', $mostrarAvisos || $verPases],
        ['sec-avisos', 'Avisos por correo', 'bi-envelope', $mostrarAvisos],
        ['sec-cuentas', 'Cuentas y seguridad', 'bi-shield-lock', $mostrarAvisos],
        ['sec-plataforma', 'Plataforma', 'bi-hdd-network', $plataforma],
        // Reservas de áreas (docs/tecnico/reservas.md): al final para no mover las demás secciones
        ['sec-reservas', 'Reservas de áreas', 'bi-calendar-check', $mostrarAvisos || ($empresa && $u->can('reservas.configurar'))],
    ];
    $visibles = array_values(array_filter($secciones, fn ($s) => $s[3]));
@endphp

@section('contenido')
<div class="tema-azul">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    <div class="encabezado-pantalla mb-3">
        <div class="icono"><i class="bi bi-sliders text-primary" aria-hidden="true"></i></div>
        <div>
            <h1>Configuración</h1>
            <p>Ordenada por secciones: cada una junta sus ajustes y sus avisos. Cada ajuste dice su valor original; lo que se configura en otra pantalla trae su botón «Configurar en …».</p>
        </div>
    </div>

    {{-- Versión instalada (ADR-0016) --}}
    <div class="version-instalada mb-3">
        <i class="bi bi-box-seam" aria-hidden="true"></i>
        <span>Versión instalada: <strong>{{ $version['numero'] ?? 'sin número (equipo de desarrollo)' }}</strong>@if ($version['instalada']) · desde el @fecha($version['instalada'], 'd/m/Y H:i')@endif</span>
    </div>

    @if (count($visibles) > 1)
        <nav class="indice-config mb-3" aria-label="Secciones de la configuración">
            @foreach ($visibles as [$ancla, $nombre, $icono])
                <a href="#{{ $ancla }}" class="chip-config"><i class="bi {{ $icono }} me-1" aria-hidden="true"></i>{{ $nombre }}</a>
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
        @if ($secciones[0][3])
            <section class="seccion-config" id="sec-general" aria-labelledby="t-sec-general">
                <h2 id="t-sec-general" class="titulo-seccion-config"><i class="bi bi-sliders" aria-hidden="true"></i>General</h2>
                {{-- «Configura tu empresa»: volver a mostrarla (u ocultarla) en Inicio --}}
                @if ($u->can('configuracion.editar'))
                    @include('administracion.configuracion._configura-tu-empresa', ['empresa' => $empresa])
                @endif
                @isset($enOtras['empresa'])
                    <div class="tarjeta p-4 tarjeta-enlace-config">
                        <h3 class="h6 fw-bold"><i class="bi bi-building me-2 text-primary" aria-hidden="true"></i>Datos de la empresa</h3>
                        <p class="small mb-1">Zona horaria: <strong>{{ $enOtras['empresa']['zona'] }}</strong> <span class="valor-original">· Valor original: {{ \App\Support\ZonasHorarias::etiqueta('America/Mexico_City') }}</span>. Cada sede puede tener la suya (en Sedes).</p>
                        <p class="small text-muted mb-2">Nombre, razón social, RFC, logo y zona horaria se cambian en Empresas.</p>
                        <a href="{{ $enOtras['empresa']['url'] }}" class="btn-enlace-config"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Configurar en Empresas</a>
                    </div>
                @endisset
                @include('administracion.configuracion._caseta', ['empresa' => $empresa, 'puedeEditar' => $puedeEditar, 'tarjeta' => 'vobo'])
                @include('administracion.configuracion._menu', ['empresa' => $empresa, 'puedeEditar' => $puedeEditar])
            </section>
        @endif

        {{-- ========================== Caseta y accesos ========================== --}}
        @if ($secciones[1][3])
            <section class="seccion-config" id="caseta" aria-labelledby="t-sec-caseta">
                <h2 id="t-sec-caseta" class="titulo-seccion-config"><i class="bi bi-door-open" aria-hidden="true"></i>Caseta y accesos</h2>
                @isset($enOtras['esperas'])
                    @php $e = $enOtras['esperas']; @endphp
                    <div class="tarjeta p-4 tarjeta-enlace-config" id="cfg-esperas">
                        <h3 class="h6 fw-bold"><i class="bi bi-hourglass-split me-2 text-primary" aria-hidden="true"></i>Quién espera autorización del departamento</h3>
                        <ul class="small mb-2">
                            @foreach ($e['tipos'] as [$tipo, $espera])
                                <li>{{ $tipo }}: <strong>{{ $espera ? 'espera la autorización' : 'pasa sin esperar' }}</strong></li>
                            @endforeach
                            <li>Quien viene con Recursos Humanos: <strong>{{ $e['rh'] ? 'la caseta espera el «Que pase»' : 'pasa sin esperar' }}</strong></li>
                            <li>Recordar a los <strong>{{ $e['recordar'] }} min</strong> y avisar al suplente y al jefe a los <strong>{{ $e['escalar'] }} min</strong></li>
                        </ul>
                        <p class="small text-muted mb-2"><span class="valor-original">Valor original: todos esperan la autorización; recordar a los {{ \App\Services\Recepcion\PanelRecepcion::ESPERA_AMBAR }} min y avisar a los {{ \App\Services\Recepcion\PanelRecepcion::ESPERA_ROJA }} min.</span> Quién autoriza en cada departamento y sus suplentes también se eligen ahí.</p>
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
                @if ($mostrarAvisos)
                    @include('administracion.configuracion._caseta', ['empresa' => $empresa, 'puedeEditar' => $puedeEditar, 'tarjeta' => 'procedimientos'])
                @endif
                @foreach ($gruposEn('caseta') as $grupo => $def)
                    @include('administracion.configuracion._avisos-grupo')
                @endforeach
            </section>
        @endif

        {{-- ========================= Recepción y RR. HH. ========================= --}}
        @if ($secciones[2][3])
            <section class="seccion-config" id="sec-rh" aria-labelledby="t-sec-rh">
                <h2 id="t-sec-rh" class="titulo-seccion-config"><i class="bi bi-people" aria-hidden="true"></i>Recepción y RR. HH.</h2>
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
                {{-- Vacantes: bolsa de trabajo en internet (lección 36) --}}
                @if ($verBolsa)
                    @include('rh.vacantes._ajustes-bolsa', ['empresa' => $empresa])
                @endif
            </section>
        @endif

        {{-- ============================ Novedades / Incidentes ============================ --}}
        @if ($secciones[3][3])
            <section class="seccion-config" id="sec-novedades" aria-labelledby="t-sec-novedades">
                <h2 id="t-sec-novedades" class="titulo-seccion-config"><i class="bi bi-headset" aria-hidden="true"></i>{{ $nombreBitacora }}</h2>
                @foreach ($gruposEn('sec-novedades') as $grupo => $def)
                    @include('administracion.configuracion._avisos-grupo')
                @endforeach
                {{-- Mejoras Incidentes: Bitácora de novedades (nombre del menú y hallazgos de recorrido) --}}
                @if ($verNovedades)
                    @include('seguridad.novedades._config', ['empresa' => $empresa, 'puedeEditar' => $puedeEditar])
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
        @if ($secciones[4][3])
            <section class="seccion-config" id="sec-activos" aria-labelledby="t-sec-activos">
                <h2 id="t-sec-activos" class="titulo-seccion-config"><i class="bi bi-key" aria-hidden="true"></i>Activos</h2>
                @include('administracion.configuracion._caseta', ['empresa' => $empresa, 'puedeEditar' => $puedeEditar, 'tarjeta' => 'llaves'])
                @include('administracion.configuracion._caseta', ['empresa' => $empresa, 'puedeEditar' => $puedeEditar, 'tarjeta' => 'responsivas'])
                @foreach ($gruposEn('sec-activos') as $grupo => $def)
                    @include('administracion.configuracion._avisos-grupo')
                @endforeach
            </section>
        @endif

        {{-- ============================== Transporte ============================== --}}
        @if ($secciones[5][3])
            <section class="seccion-config" id="sec-transporte" aria-labelledby="t-sec-transporte">
                <h2 id="t-sec-transporte" class="titulo-seccion-config"><i class="bi bi-taxi-front" aria-hidden="true"></i>Transporte</h2>
                @include('administracion.configuracion._caseta', ['empresa' => $empresa, 'puedeEditar' => $puedeEditar, 'tarjeta' => 'transporte'])
                @foreach ($gruposEn('sec-transporte') as $grupo => $def)
                    @include('administracion.configuracion._avisos-grupo')
                @endforeach
            </section>
        @endif

        {{-- =========================== Pases de salida =========================== --}}
        @if ($secciones[6][3])
            <section class="seccion-config" id="sec-pases" aria-labelledby="t-sec-pases">
                <h2 id="t-sec-pases" class="titulo-seccion-config"><i class="bi bi-box-arrow-up-right" aria-hidden="true"></i>Pases de salida</h2>
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
        @if ($secciones[10][3])
            <section class="seccion-config" id="sec-reservas" aria-labelledby="t-sec-reservas">
                <h2 id="t-sec-reservas" class="titulo-seccion-config"><i class="bi bi-calendar-check" aria-hidden="true"></i>Reservas de áreas</h2>
                @if ($u->can('reservas.configurar'))
                    @push('estilos')<link href="{{ asset('css/reservas.css') }}" rel="stylesheet">@endpush
                    @include('operacion.reservas._ajustes', ['empresa' => $empresa, 'desde' => 'configuracion',
                        'editable' => app(\App\Services\Permisos\Autorizador::class)->alcanceDeEmpresa($u, 'reservas.configurar')])
                    <div class="tarjeta p-4 tarjeta-enlace-config">
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
        @if ($secciones[7][3])
            <section class="seccion-config" id="sec-avisos" aria-labelledby="t-sec-avisos">
                <h2 id="t-sec-avisos" class="titulo-seccion-config"><i class="bi bi-envelope" aria-hidden="true"></i>Avisos por correo</h2>
                <div class="tarjeta p-4">
                    @unless ($correoListo)
                        <div class="alert alert-warning small py-2"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>El correo de la plataforma aún no está configurado: los avisos por correo se activarán cuando el Super Administrador lo configure. Los de la campana sí llegan.</div>
                    @endunless
                    <p class="small text-muted">Cada sección de arriba tiene sus avisos con un interruptor general. Aquí ves todos de un vistazo; toca uno para ir a sus casillas. Por omisión todos están encendidos, salvo los que dicen «Valor original: apagado».</p>
                    <ul class="lista-avisos-config small mb-2">
                        @foreach ($grupos as $grupo => $def)
                            @php $encendido = \App\Support\GruposAvisos::encendido($empresa, $grupo); @endphp
                            <li><a href="#config-{{ $grupo }}">{{ $def['nombre'] }}</a>: <strong>{{ $encendido ? 'encendidos' : 'apagados' }}</strong></li>
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
        @if ($secciones[8][3])
            <section class="seccion-config" id="sec-cuentas" aria-labelledby="t-sec-cuentas">
                <h2 id="t-sec-cuentas" class="titulo-seccion-config"><i class="bi bi-shield-lock" aria-hidden="true"></i>Cuentas y seguridad</h2>
                @foreach ($gruposEn('sec-cuentas') as $grupo => $def)
                    @include('administracion.configuracion._avisos-grupo')
                @endforeach
                <div class="tarjeta p-4" id="cfg-sesion">
                    <h3 class="h6 fw-bold"><i class="bi bi-hourglass-bottom me-2 text-primary" aria-hidden="true"></i>Cierre de sesión por inactividad</h3>
                    <p class="small mb-0">La sesión se cierra sola tras <strong>{{ (int) config('plataforma.sesion.inactividad_minutos') }} minutos</strong> sin actividad, en todas las empresas. <span class="valor-original">Valor original: 20 minutos.</span> Es un ajuste de la plataforma: si necesitas otro tiempo, pídelo a quien administra la plataforma.</p>
                </div>
            </section>
        @endif

        {{-- ============================== Plataforma ============================== --}}
        @if ($esSuperadmin)
            <div class="seccion-config" id="sec-plataforma">
                <h2 id="t-sec-plataforma" class="titulo-seccion-config"><i class="bi bi-hdd-network" aria-hidden="true"></i>Plataforma</h2>
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
                        <li>Correo de la plataforma y respaldos: aquí abajo.</li>
                    </ul>
                </div>
            </div>
            {{-- ================= Correo saliente (plataforma) ================= --}}
            <section class="tarjeta p-4" aria-labelledby="t-correo">
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
            <section class="tarjeta p-4" aria-labelledby="t-respaldos">
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
                                <span class="chip-catalogo">{{ ['diario' => 'Diario', 'antes-de-actualizar' => 'Antes de actualizar', 'manual' => 'Manual'][$r['motivo']] ?? $r['motivo'] }}</span>
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
        @elseif (! $empresa)
            <div class="tarjeta estado-vacio"><p class="text-muted small m-0">Elige arriba la empresa de trabajo.</p></div>
        @endif
    </div>
</div>
@endsection
