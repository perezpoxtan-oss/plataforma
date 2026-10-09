@extends('layouts.app')

@section('titulo', 'Plantillas de etiquetas QR')

@section('contenido')
{{-- Ronda 7: Etiquetas QR → Plantillas (permiso etiquetas_qr.configurar). Ver docs/tecnico/etiquetas-qr.md --}}
<div class="tema-gafetes pantalla-etiquetas-qr">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
        <div class="encabezado-pantalla m-0">
            <div class="icono"><i class="bi bi-qr-code text-dark" aria-hidden="true"></i></div>
            <div>
                <h1>Etiquetas QR</h1>
                <p>Plantillas: el tamaño de cada etiqueta, cómo se acomoda en el papel y qué datos lleva.</p>
                @unless ($sinEmpresa)
                    <p class="texto-padron-de"><i class="bi bi-building-check" aria-hidden="true"></i> Plantillas de: <strong>{{ $empresaNombre }}</strong></p>
                @endunless
            </div>
        </div>
        @unless ($sinEmpresa)
            <a href="{{ route('etiquetas.plantillas.create') }}" class="btn-gafetes btn-nueva-plantilla-qr"><i class="bi bi-plus-lg me-1" aria-hidden="true"></i>Nueva plantilla</a>
        @endunless
    </div>

    @if ($sinEmpresa)
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-buildings" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong> para ver sus plantillas.</p>
        </div>
    @else
        @include('padrones.etiquetas._pestanas', ['activa' => 'plantillas', 'puedeConfigurar' => true])

        <div class="tarjeta ayuda-plantillas-qr mb-3">
            <p class="m-0"><i class="bi bi-lightbulb" aria-hidden="true"></i>
                <strong>Rollo térmico</strong> (Zebra, Brother, Dymo…): una etiqueta por página; escribe el ancho y alto de tu rollo.
                <strong>Hoja carta o A4</strong>: para planillas de etiquetas adhesivas; escribe columnas, filas, márgenes y separación.
                Antes de imprimir muchas, usa <strong>«Hoja de prueba»</strong> para revisar que todo coincida con tu papel.</p>
        </div>

        @if ($plantillas->isEmpty())
            <div class="sin-resultados">
                <i class="bi bi-rulers d-block mb-2" aria-hidden="true"></i>
                <p class="fw-semibold m-0">Todavía no hay plantillas.</p>
            </div>
        @else
            <div class="rejilla-plantillas-qr">
                @foreach ($plantillas as $pl)
                    @php $editable = in_array($pl->id, $editables, true); @endphp
                    <article class="tarjeta plantilla-qr {{ $pl->activo ? '' : 'inactiva' }}" id="plantilla-{{ $pl->id }}">
                        <div class="plantilla-qr-cabeza">
                            <div class="plantilla-qr-muestra {{ $pl->orientacion }}" aria-hidden="true">
                                <span class="muestra-qr"></span><span class="muestra-texto"></span>
                            </div>
                            <div class="min-w-0">
                                <h2>{{ $pl->nombre }}</h2>
                                <p class="m-0">{{ $pl->resumen() }}</p>
                            </div>
                        </div>
                        <ul class="plantilla-qr-datos">
                            <li><i class="bi bi-geo-alt" aria-hidden="true"></i> {{ $pl->sede?->nombre ?? 'Toda la empresa' }}</li>
                            <li><i class="bi bi-qr-code" aria-hidden="true"></i> QR de {{ $pl->mm($pl->qr_mm) }} mm · {{ $pl->orientacion === 'vertical' ? 'QR arriba' : 'QR a la izquierda' }}</li>
                            <li><i class="bi bi-card-text" aria-hidden="true"></i> {{ collect(\App\Models\EtiquetaPlantilla::DATOS)->keys()->filter(fn ($d) => $pl->{$d})->map(fn ($d) => ['mostrar_titulo' => 'Nombre', 'mostrar_codigo' => 'Código', 'mostrar_tipo' => 'Tipo', 'mostrar_ubicacion' => 'Depto./sede', 'mostrar_fecha' => 'Fecha', 'mostrar_logo' => 'Logo'][$d])->join(' · ') }}</li>
                        </ul>
                        <div class="d-flex flex-wrap gap-2 align-items-center">
                            @if ($pl->clave)<span class="pildora-siempre">Plantilla de siempre</span>@endif
                            @unless ($pl->activo)<span class="etiqueta-inactiva">Desactivada</span>@endunless
                        </div>
                        <p class="traza-plantilla-qr">
                            @if ($pl->creado_por_nombre && ! $pl->clave)Creado por: {{ $pl->creado_por_nombre }} · @fecha($pl->created_at)@endif
                            @if ($pl->actualizado_por_nombre && $pl->updated_at?->ne($pl->created_at))<span class="d-block">Editado por: {{ $pl->actualizado_por_nombre }} · @fecha($pl->updated_at)</span>@endif
                        </p>
                        @if ($editable)
                            <div class="plantilla-qr-acciones">
                                <a href="{{ route('etiquetas.plantillas.prueba', $pl->id) }}" target="_blank" class="btn-limpiar-filtros"><i class="bi bi-printer me-1" aria-hidden="true"></i>Hoja de prueba</a>
                                <a href="{{ route('etiquetas.plantillas.edit', $pl->id) }}" class="btn-gafetes"><i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Editar</a>
                                <form method="POST" action="{{ route('etiquetas.plantillas.estado', $pl->id) }}" class="d-inline">
                                    @csrf @method('PATCH')
                                    <input type="hidden" name="activo" value="{{ $pl->activo ? 0 : 1 }}">
                                    <button type="submit" class="{{ $pl->activo ? 'btn-desactivar-plantilla' : 'btn-activar-plantilla' }}">
                                        <i class="bi {{ $pl->activo ? 'bi-slash-circle' : 'bi-check-circle' }} me-1" aria-hidden="true"></i>{{ $pl->activo ? 'Desactivar' : 'Activar' }}
                                    </button>
                                </form>
                            </div>
                        @else
                            <p class="campo-ayuda m-0"><i class="bi bi-lock" aria-hidden="true"></i> De toda la empresa: solo la cambia quien administra toda la empresa. Puedes usarla al imprimir.</p>
                            <div class="plantilla-qr-acciones">
                                <a href="{{ route('etiquetas.plantillas.prueba', $pl->id) }}" target="_blank" class="btn-limpiar-filtros"><i class="bi bi-printer me-1" aria-hidden="true"></i>Hoja de prueba</a>
                            </div>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif
    @endif
</div>
@endsection
