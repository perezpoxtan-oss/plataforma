@extends('layouts.app')

@section('titulo', 'Lost & Found · '.$a->folio)

@section('contenido')
<div class="tema-ambar pantalla-lost-found">
    @include('administracion.partes.avisos')

    @php
        $entrega = $a->entrega;
        $vinculo = $a->reportesVinculados->first();
    @endphp

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
        <div class="encabezado-pantalla m-0">
            <div class="icono"><i class="bi bi-bag-fill text-warning" aria-hidden="true"></i></div>
            <div>
                <h1>{{ $a->folio }} — {{ $a->objeto }}</h1>
                <p>Ficha del artículo de Lost &amp; Found.</p>
            </div>
        </div>
        <div class="acciones-novedades">
            <a href="{{ route('lost_found.archivo') }}#articulo-{{ $a->id }}" class="btn-accion-novedades"><i class="bi bi-arrow-left" aria-hidden="true"></i> Lost &amp; Found</a>
            @if ($puedeImprimir)
                <a href="{{ route('lost_found.articulos.etiqueta', $a->id) }}" target="_blank" rel="noopener" class="btn-accion-novedades oscuro"><i class="bi bi-tag-fill" aria-hidden="true"></i> Etiqueta</a>
            @endif
        </div>
    </div>

    <div class="ficha-articulo-lf tarjeta {{ $semaforo['clase'] ?? 'cerrado' }}">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-2">
            <div class="d-flex flex-wrap align-items-center gap-2">
                <span class="estatus-lf grande {{ $a->enResguardo() ? 'resguardo' : 'cerrado' }}">{{ $a->etiquetaEstatus() }}</span>
                <span class="badge-tipo-lf">{{ $a->etiquetaTipo() }}</span>
                @if ($semaforo)
                    <span class="badge-semaforo {{ $semaforo['clase'] }}"><i class="bi bi-circle-fill" aria-hidden="true"></i> {{ $semaforo['texto'] }} · {{ $semaforo['dias'] }} de {{ $umbrales[$a->tipo_valor] ?? 90 }} días</span>
                @endif
            </div>
            @if ($puedeCerrar)
                <button type="button" class="btn-lf cerrar grande" data-abrir-dialogo="dialogoCerrarArticulo"><i class="bi bi-box-arrow-right me-1" aria-hidden="true"></i>Cerrar / Entregar</button>
            @endif
        </div>

        <div class="datos-articulo-lf">
            <div><span class="dato-etiqueta">Objeto</span>{{ $a->objeto }}</div>
            <div><span class="dato-etiqueta">Marca / Color</span>{{ trim($a->marca.' '.$a->color) ?: '—' }}</div>
            <div><span class="dato-etiqueta">Sede</span>{{ $a->sede?->nombre ?? '—' }}</div>
            <div><span class="dato-etiqueta">Ubicación en bodega</span><strong>{{ $a->ubicacion_bodega ?: '—' }}</strong></div>
            <div><span class="dato-etiqueta">Área donde se encontró</span>{{ collect([$a->areaEspecifica?->nombre, $a->lugar_detalle])->filter()->join(' · ') ?: '—' }}</div>
            <div><span class="dato-etiqueta">Encontrado</span>@fecha($a->created_at)</div>
            @if ($a->novedad)
                <div class="completo">
                    <span class="dato-etiqueta">Ticket de origen</span>
                    @if ($puedeVerTicket)
                        <a href="{{ route('novedades.index', ['abrir' => $a->novedad->id]) }}">{{ $a->novedad->folio() }}</a>
                    @else
                        {{ $a->novedad->folio() }}
                    @endif
                    — {{ $a->novedad->ubicacion }} · Reportó: {{ $a->novedad->reportado_por }}
                    @if ($puedeAcuse)
                        · <a href="{{ route('novedades.acuse', $a->novedad->id) }}" target="_blank" rel="noopener"><i class="bi bi-receipt" aria-hidden="true"></i> Acuse de Recibo</a>
                    @endif
                </div>
            @endif
        </div>

        @if ($vinculo || $a->robosVinculados->isNotEmpty())
            <div class="vinculos-lf">
                @foreach ($a->reportesVinculados as $r)
                    <div><i class="bi bi-link-45deg text-success" aria-hidden="true"></i> Reporte de pérdida <strong>{{ $r->folio }}</strong>@if ($r->nombre_huesped) — {{ $r->nombre_huesped }}@endif @if ($r->telefono) · Tel. {{ $r->telefono }}@endif @if ($r->correo) · {{ $r->correo }}@endif</div>
                @endforeach
                @foreach ($a->robosVinculados as $robo)
                    @if ($robo->novedad)
                        <div><i class="bi bi-exclamation-octagon text-danger" aria-hidden="true"></i> Vinculado con el caso de Robo <strong>{{ $robo->novedad->folio() }}</strong> — {{ $robo->novedad->ubicacion }}</div>
                    @endif
                @endforeach
            </div>
        @endif

        <p class="texto-traza mt-2 mb-0">
            <i class="bi bi-plus-circle" aria-hidden="true"></i> Registró {{ $a->creador?->name ?? '—' }} · @fecha($a->created_at)
            @if ($a->editor && $a->updated_at?->ne($a->created_at)) · <i class="bi bi-pencil" aria-hidden="true"></i> Editó {{ $a->editor->name }} · @fecha($a->updated_at)@endif
        </p>
    </div>

    {{-- Cierre / entrega --}}
    <section class="tarjeta entrega-lf" aria-labelledby="titulo-entrega">
        <h2 class="subtitulo-lf" id="titulo-entrega"><i class="bi bi-box-arrow-right text-warning me-1" aria-hidden="true"></i>Cierre / entrega</h2>
        @if ($entrega)
            <div class="datos-articulo-lf">
                <div><span class="dato-etiqueta">¿Cómo se cerró?</span>{{ $entrega->etiquetaCierre() }}</div>
                <div><span class="dato-etiqueta">Fecha</span>@fecha($a->cerrado_en ?? $entrega->created_at)</div>
                @if ($entrega->nombre_recibe)
                    <div><span class="dato-etiqueta">{{ $entrega->tipo_cierre === 'BENEFICENCIA' ? 'Institución que recibe' : 'Recibió' }}</span>{{ $entrega->nombre_recibe }}@if ($entrega->colaborador) <span class="text-muted">(colaborador · Núm. {{ $entrega->colaborador->num_empleado }})</span>@elseif ($entrega->persona) <span class="text-muted">(Padrón de personas)</span>@endif</div>
                @endif
                @if ($entrega->tipo_identificacion)
                    <div><span class="dato-etiqueta">Identificación</span>{{ $entrega->tipo_identificacion }}</div>
                @endif
                @if ($entrega->correo_recibe)
                    <div><span class="dato-etiqueta">Correo</span>{{ $entrega->correo_recibe }}</div>
                @endif
                @if ($entrega->paqueteria)
                    <div><span class="dato-etiqueta">Paquetería / guía</span>{{ $entrega->paqueteria }} · {{ $entrega->numero_guia }}</div>
                @endif
                @if ($entrega->observaciones)
                    <div class="completo"><span class="dato-etiqueta">Comentarios</span>{{ $entrega->observaciones }}</div>
                @endif
                <div><span class="dato-etiqueta">Registró el cierre</span>{{ $a->cerrador?->name ?? $entrega->creador?->name ?? '—' }}</div>
            </div>
            @if ($entrega->firma_ruta)
                <figure class="firma-entrega-lf">
                    <img src="{{ route('lost_found.entregas.firma', $entrega->id) }}" alt="{{ \App\Models\LostFoundEntrega::ETIQUETAS_FIRMA[$entrega->tipo_cierre] ?? 'Firma' }}" loading="lazy">
                    <figcaption>{{ \App\Models\LostFoundEntrega::ETIQUETAS_FIRMA[$entrega->tipo_cierre] ?? 'Firma' }}</figcaption>
                </figure>
            @endif
        @else
            <p class="text-muted small m-0">
                Sigue en resguardo.
                @if ($puedeCerrar) Cuando se entregue, dona o destruya, usa <strong>Cerrar / Entregar</strong>: se pide la firma y queda en el historial del ticket. @endif
            </p>
        @endif
    </section>

    @if ($puedeCerrar)
        @include('seguridad.lost-found._cerrar', ['articulo' => $a, 'volver' => 'ficha', 'abrir' => $abrirCierre, 'puedePersona' => $puede['persona']])
        @if ($puede['persona'])
            @include('seguridad.personas._registro-rapido')
        @endif
    @endif
</div>
@endsection
