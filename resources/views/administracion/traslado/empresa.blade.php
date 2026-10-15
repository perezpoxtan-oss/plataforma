@extends('layouts.app')

@section('titulo', 'Exportar datos de la empresa')

@php use App\Services\Traslado\Paquetes; @endphp

@section('contenido')
<div class="tema-azul">
    @include('administracion.partes.avisos')

    <div class="encabezado-pantalla mb-3">
        <div class="icono"><i class="bi bi-box-arrow-down text-primary" aria-hidden="true"></i></div>
        <div>
            <h1>Exportar datos de {{ $empresa->nombre_comercial }}</h1>
            <p>Un archivo con todo lo de la empresa: sus datos, hojas de Excel de lo principal y sus fotos, documentos y firmas.</p>
        </div>
    </div>

    <p class="small mb-3"><a href="{{ route('empresas.index') }}"><i class="bi bi-arrow-left me-1" aria-hidden="true"></i>Volver a Empresas</a></p>

    <div class="alert alert-warning small d-flex gap-2" role="note">
        <i class="bi bi-shield-exclamation fs-5" aria-hidden="true"></i>
        <div>La exportación contiene <strong>datos personales</strong> de colaboradores, visitantes y candidatos. Descárgala solo en un equipo de confianza, entrégala por un medio seguro y bórrala del servidor cuando ya no la necesites. No incluye contraseñas. Cada exportación, descarga y borrado queda en la Bitácora de auditoría.</div>
    </div>

    <section class="tarjeta p-4" aria-labelledby="t-exportar">
        <div class="d-flex justify-content-between align-items-start gap-2 flex-wrap">
            <h2 id="t-exportar" class="h5 fw-bold m-0"><i class="bi bi-building me-2 text-primary" aria-hidden="true"></i>Exportaciones</h2>
            @unless ($activo)
                <form action="{{ route('empresas.exportar', $empresa->id) }}" method="POST" class="m-0"
                      data-confirmar="¿Exportar todos los datos de «{{ $empresa->nombre_comercial }}»? Se prepara por partes; puede tardar unos minutos.">
                    @csrf
                    <button type="submit" class="btn-azul" style="min-height:44px;border-radius:10px;padding:0 1rem;"><i class="bi bi-box-arrow-down me-1" aria-hidden="true"></i>Exportar datos</button>
                </form>
            @endunless
        </div>
        <p class="small text-muted mt-2">Trae un archivo de datos por cada tabla, hojas de Excel (CSV) de sedes, colaboradores, usuarios (sin contraseña), padrones y configuración, y los archivos de la empresa. Se conservan las {{ Paquetes::CONSERVAR }} más recientes. Los enlaces de descarga valen {{ \App\Http\Controllers\Administracion\TrasladoController::MINUTOS_ENLACE }} minutos: si vence, recarga la página.</p>

        @if ($activo)
            @include('administracion.traslado._avance', ['trabajo' => $activo, 'titulo' => 'Preparando la exportación'])
        @elseif ($fallido && $fallido['estado'] === 'error')
            <div class="alert alert-danger small" role="alert"><i class="bi bi-exclamation-triangle me-1" aria-hidden="true"></i>La última exportación no se pudo terminar: {{ $fallido['error'] }} Vuelve a intentarlo.</div>
        @endif

        @if ($paquetes->isEmpty())
            <p class="text-muted small m-0">Todavía no hay exportaciones de esta empresa.</p>
        @else
            <div class="d-flex flex-column gap-2">
                @foreach ($paquetes as $p)
                    <div class="border rounded-3 p-2 px-3 d-flex flex-wrap align-items-center gap-2 justify-content-between">
                        <div>
                            <div><i class="bi bi-file-earmark-zip me-1" aria-hidden="true"></i><strong>@fecha($p['fecha'])</strong></div>
                            <div class="small text-muted">{{ Paquetes::legible($p['bytes']) }} · {{ number_format($p['datos']['filas'] ?? 0) }} registros · {{ number_format($p['datos']['archivos'] ?? 0) }} archivos en el paquete · por {{ $p['datos']['creado_por_nombre'] ?? '—' }}</div>
                        </div>
                        <div class="d-flex gap-2">
                            <a href="{{ $p['enlace'] }}" class="btn btn-sm btn-primary" aria-label="Descargar la exportación del @fecha($p['fecha'])"><i class="bi bi-download me-1" aria-hidden="true"></i>Descargar</a>
                            <form action="{{ route('traslado.borrar', $p['archivo']) }}" method="POST" class="m-0"
                                  data-confirmar="¿Borrar del servidor la exportación del @fecha($p['fecha'])? Los datos de la empresa no se tocan.">
                                @csrf
                                @method('DELETE')
                                <button type="submit" class="btn-icono eliminar" title="Borrar" aria-label="Borrar la exportación del @fecha($p['fecha'])"><i class="bi bi-trash" aria-hidden="true"></i></button>
                            </form>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </section>
</div>
@endsection
