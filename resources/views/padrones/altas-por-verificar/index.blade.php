@extends('layouts.app')

@section('titulo', 'Altas por verificar')

@section('contenido')
{{--
    C-27 · Una sola bandeja «Altas por verificar» (Padrones): Personas,
    Vehículos, Empresas y Colaboradores que la caseta registró sin estar en
    su padrón. Los mismos tres caminos en cada pestaña: Es correcto
    (Aprobar), Es el mismo (Aprobar · es el mismo) y Rechazar con motivo.
--}}
<div class="tema-azul pantalla-altas-verificar">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    <div class="encabezado-pantalla mb-3">
        <div class="icono"><i class="bi bi-patch-question text-warning" aria-hidden="true"></i></div>
        <div>
            <h1>Altas por verificar</h1>
            <p>Lo que la caseta registró y aún no estaba en el padrón. Se usa de inmediato; aquí lo revisas: es correcto, es el mismo que otro o se rechaza.</p>
        </div>
    </div>

    @if ($sinEmpresa)
        <div class="tarjeta estado-vacio"><p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong>.</p></div>
    @else
        <nav class="pildoras-pases mb-3" aria-label="Padrones">
            @foreach ($pestanas as $clave => $nombre)
                <a href="{{ route('altas_por_verificar.index', ['pestana' => $clave]) }}" class="btn-pill-tipo {{ $pestana === $clave ? 'active' : '' }}" @if ($pestana === $clave) aria-current="page" @endif>
                    {{ $nombre }} <span class="conteo-pill">{{ $conteos[$clave] }}</span>
                </a>
            @endforeach
        </nav>

        @if ($pestana === 'colaboradores')
            <p class="small text-muted">Altas de colaboradores que hizo la caseta. Las verifica {{ $quienVerifica }}: asígnale su número, únela con su registro si ya existía o recházala.</p>
            <div class="fichas-grid">
                @forelse ($colaboradores as $c)
                    @php
                        $valores = json_encode(['num_empleado' => $c->num_empleado, 'nombre' => $c->nombre, 'apellido_paterno' => $c->apellido_paterno,
                            'apellido_materno' => $c->apellido_materno, 'telefono' => $c->telefono, 'sede_id' => $c->sede_id,
                            'departamento_id' => $c->departamento_id, 'puesto_id' => $c->puesto_id, 'turno_id' => $c->turno_id]);
                    @endphp
                    <div class="ficha-card ficha-pendiente" id="colaborador-{{ $c->id }}">
                        <div>
                            <h2 class="ficha-title">{{ $c->nombreCompleto() }}</h2>
                            <span class="insignia-verificacion pendiente"><i class="bi bi-hourglass-split" aria-hidden="true"></i> Por verificar</span>
                            <div class="ficha-meta mt-2">
                                <div><i class="bi bi-geo-alt" aria-hidden="true"></i> {{ $c->sede?->nombre ?? 'Todas las sedes' }}@if ($c->departamento) · {{ $c->departamento->nombre }}@endif</div>
                                <div class="texto-traza">Registró: {{ $creadores[$c->creado_por] ?? '—' }} · @fecha($c->created_at) · {{ app(\App\Services\Notificaciones\CentroNotificaciones::class)->hace($c->created_at) }}</div>
                            </div>
                        </div>
                        <div class="d-flex flex-wrap gap-2 mt-2">
                            <button type="button" class="btn-validar-colab" data-accion="editar-registro" data-dialogo="dialogoValidarColaborador"
                                    data-url="{{ route('colaboradores.validar', $c->id) }}" data-id="{{ $c->id }}" data-valores="{{ $valores }}">
                                <i class="bi bi-patch-check" aria-hidden="true"></i> Es correcto
                            </button>
                            <button type="button" class="btn-duplicado-colab" data-accion="editar-registro" data-dialogo="dialogoFusionarColaborador"
                                    data-url="{{ route('colaboradores.fusionar', $c->id) }}" data-id="{{ $c->id }}" data-valores="{{ json_encode(['destino_id' => '']) }}">
                                <i class="bi bi-people" aria-hidden="true"></i> Es el mismo
                            </button>
                            <button type="button" class="btn-rechazar-colab" data-accion="editar-registro" data-dialogo="dialogoRechazarColaborador"
                                    data-url="{{ route('colaboradores.rechazar', $c->id) }}" data-id="{{ $c->id }}" data-valores="{{ json_encode(['motivo_rechazo' => '']) }}">
                                <i class="bi bi-x-octagon" aria-hidden="true"></i> Rechazar
                            </button>
                        </div>
                    </div>
                @empty
                    <div class="tarjeta estado-vacio"><div class="icono"><i class="bi bi-check2-circle" aria-hidden="true"></i></div><p class="text-muted small m-0">No hay colaboradores por verificar.</p></div>
                @endforelse
            </div>
            @include('organizacion.colaboradores._verificar', ['validacion' => $validacion, 'validados' => $validados, 'volver' => 'altas'])
        @else
            @php $def = \App\Services\Padrones\AltasPorVerificar::PADRONES[$pestana]; @endphp
            <p class="small text-muted">La verifica quien administra el {{ $def['padron'] }}. <a href="{{ route($def['ruta']) }}">Ir al padrón</a></p>
            <div class="fichas-grid">
                @forelse ($registros as $r)
                    @php $dialogoAlta = $altas['dialogos'][(int) $r->id] ?? null; @endphp
                    <div class="ficha-card ficha-pendiente" id="{{ $def['ancla'] }}{{ $r->id }}">
                        <div>
                            <h2 class="ficha-title"><i class="bi {{ $def['icono'] }} me-1" aria-hidden="true"></i>{{ $dialogoAlta['titulo'] ?? app(\App\Services\Padrones\AltasPorVerificar::class)->titulo($pestana, $r) }}</h2>
                            <span class="insignia-verificacion pendiente"><i class="bi bi-hourglass-split" aria-hidden="true"></i> Por verificar</span>
                            <div class="texto-traza mt-2">{{ $dialogoAlta['registro'] ?? '' }} · {{ app(\App\Services\Notificaciones\CentroNotificaciones::class)->hace($r->created_at) }}</div>
                        </div>
                        <div class="ficha-footer">
                            <span></span>
                            @include('padrones.altas-por-verificar._boton', ['registro' => $r])
                        </div>
                    </div>
                @empty
                    <div class="tarjeta estado-vacio"><div class="icono"><i class="bi bi-check2-circle" aria-hidden="true"></i></div><p class="text-muted small m-0">No hay {{ mb_strtolower($def['nombre']) }} por verificar.</p></div>
                @endforelse
            </div>
            @include('padrones.altas-por-verificar._dialogo', ['altas' => $altas])
        @endif
    @endif
</div>
@endsection
