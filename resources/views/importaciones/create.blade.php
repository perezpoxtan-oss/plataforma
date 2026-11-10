@extends('layouts.app')

@section('titulo', 'Importar '.mb_strtolower($importador->titulo()))

@section('contenido')
<div class="tema-azul pantalla-importar">
    @include('administracion.partes.avisos')

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
        <div class="encabezado-pantalla m-0">
            <div class="icono"><i class="bi bi-file-earmark-arrow-up text-primary" aria-hidden="true"></i></div>
            <div>
                <h1>Importar desde Excel: {{ $importador->titulo() }}</h1>
                <p>Carga muchos registros de una vez. Primero ves cómo quedará cada fila; nada se guarda hasta que tocas <strong>Guardar</strong>.</p>
            </div>
        </div>
        <a href="{{ $importador->rutaLista() }}" class="enlace-volver"><i class="bi bi-arrow-left" aria-hidden="true"></i> Volver a {{ $importador->titulo() }}</a>
    </div>

    @include('importaciones._pasos', ['actual' => 1])

    @unless ($puedeCrear)
        <div class="alert alert-warning small" role="status"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>
            Tu rol no puede dar de alta {{ mb_strtolower($importador->titulo()) }}: las filas nuevas saldrán con error.
            @if ($puedeEditar) Sí puedes actualizar los que ya existen. @endif
        </div>
    @endunless

    <div class="row g-4">
        <div class="col-lg-5">
            <section class="tarjeta p-4 h-100" aria-labelledby="t-plantilla">
                <h2 id="t-plantilla" class="h5 fw-bold mb-2"><span class="numero-paso">1</span>Descarga la plantilla</h2>
                <p class="small text-muted">Ábrela en Excel, borra la fila de ejemplo y copia tus datos debajo de los encabezados (una fila por {{ $importador->registro() }}). Las columnas con <strong>*</strong> son obligatorias; las demás las puedes dejar vacías y completarlas después.</p>
                <a href="{{ route('importaciones.plantilla', $importador->tipo()) }}" class="btn-importar-descarga"><i class="bi bi-download" aria-hidden="true"></i> Descargar plantilla</a>
                <p class="small text-muted mt-3 mb-2">Con lo que reconocemos si ya existe: <strong>{{ $importador->nombreClave() }}</strong>.</p>

                <details class="importar-columnas mt-2">
                    <summary class="small fw-bold">Qué va en cada columna</summary>
                    <table class="table table-sm small mt-2 mb-0">
                        <thead><tr><th scope="col">Columna</th><th scope="col">Qué escribir</th></tr></thead>
                        <tbody>
                            @foreach ($columnas as $columna)
                                <tr>
                                    <td class="fw-bold text-nowrap">{{ $columna->encabezado() }}</td>
                                    <td>{{ $columna->ayuda ?: '—' }}@if ($columna->ejemplo !== '') <span class="text-muted">Ejemplo: {{ $columna->ejemplo }}</span>@endif</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @if ($ocultasPersonales)
                        <p class="small text-muted mt-2 mb-0"><i class="bi bi-lock me-1" aria-hidden="true"></i>Tu rol no ve datos personales (CURP, RFC, NSS…): esas columnas no van en tu plantilla y, si tu archivo las trae, no se toman en cuenta.</p>
                    @endif
                </details>
            </section>
        </div>

        <div class="col-lg-7">
            <section class="tarjeta p-4 h-100" aria-labelledby="t-subir">
                <h2 id="t-subir" class="h5 fw-bold mb-2"><span class="numero-paso">2</span>Sube tu archivo</h2>
                <p class="small text-muted">Sirve el libro de Excel (.xlsx, se lee la primera hoja) o un archivo CSV. Hasta {{ number_format($maxFilas) }} filas y {{ $maxMb }} MB por archivo; si tienes más, divídelo.</p>
                <form action="{{ route('importaciones.store', $importador->tipo()) }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    <label class="campo-etiqueta" for="archivo">Archivo</label>
                    <input type="file" id="archivo" name="archivo" class="campo" accept=".xlsx,.csv,.txt" required>
                    <button type="submit" class="btn-azul boton-importar mt-3"><i class="bi bi-eye me-1" aria-hidden="true"></i>Revisar archivo</button>
                </form>
                <p class="small text-muted mt-3 mb-0"><i class="bi bi-shield-check me-1" aria-hidden="true"></i>Al revisar no se guarda nada: verás cada fila marcada como <strong>Nueva</strong>, <strong>Ya existe</strong> o <strong>Con error</strong> y decides antes de guardar.</p>
            </section>
        </div>
    </div>

    @if ($anteriores->isNotEmpty())
        <section class="tarjeta p-4 mt-4" aria-labelledby="t-anteriores">
            <h2 id="t-anteriores" class="h6 fw-bold mb-3"><i class="bi bi-clock-history me-2 text-primary" aria-hidden="true"></i>Archivos anteriores</h2>
            <div class="table-responsive">
                <table class="table table-sm small align-middle mb-0">
                    <thead><tr><th scope="col">Fecha</th><th scope="col">Quién</th><th scope="col">Archivo</th><th scope="col">Resultado</th><th scope="col"><span class="visually-hidden">Abrir</span></th></tr></thead>
                    <tbody>
                        @foreach ($anteriores as $anterior)
                            @php $r = $anterior->resumen ?? []; @endphp
                            <tr>
                                <td class="text-nowrap">@fecha($anterior->created_at)</td>
                                <td>{{ $anterior->autor?->name ?? '—' }}</td>
                                <td class="text-break">{{ $anterior->archivo_nombre }}</td>
                                <td>
                                    @if ($anterior->aplicada())
                                        {{ ($r['creada'] ?? 0) }} nuevos · {{ ($r['actualizada'] ?? 0) }} actualizados · {{ ($r['omitida'] ?? 0) }} sin cambios · {{ ($r['error'] ?? 0) }} con error
                                    @else
                                        <span class="text-muted">Sin guardar (vista previa)</span>
                                    @endif
                                </td>
                                <td class="text-end"><a href="{{ route('importaciones.show', $anterior) }}">Ver</a></td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</div>
@endsection
