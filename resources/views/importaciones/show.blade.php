@extends('layouts.app')

@section('titulo', ($importacion->aplicada() ? 'Resultado: ' : 'Vista previa: ').$importador->titulo())

@php
    $aplicada = $importacion->aplicada();
    $etiquetas = \App\Services\Importacion\AdministradorImportaciones::ETIQUETAS;
    $chips = $aplicada
        ? ['todas' => ['Todas', $importacion->total_filas, ''], 'nuevas' => ['Creadas', $conteo['creada'] ?? 0, 'nueva'], 'existen' => ['Actualizadas u omitidas', ($conteo['actualizada'] ?? 0) + ($conteo['omitida'] ?? 0), 'existe'], 'errores' => ['Con error', $conteo['error'] ?? 0, 'error']]
        : ['todas' => ['Todas', $importacion->total_filas, ''], 'nuevas' => ['Nuevas', $conteo['nueva'] ?? 0, 'nueva'], 'existen' => ['Ya existen', $conteo['existe'] ?? 0, 'existe'], 'errores' => ['Con error', $conteo['error'] ?? 0, 'error']];
    $clasesEstado = ['nueva' => 'nueva', 'creada' => 'nueva', 'existe' => 'existe', 'actualizada' => 'existe', 'omitida' => 'existe', 'error' => 'error'];
    $porGuardar = ($conteo['nueva'] ?? 0);
    $porActualizar = ($conteo['por_actualizar'] ?? 0);
    $ignoradas = $conteo['ignoradas'] ?? [];
@endphp

@section('contenido')
<div class="tema-azul pantalla-importar">
    @include('administracion.partes.avisos')

    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3 mb-3">
        <div class="encabezado-pantalla m-0">
            <div class="icono"><i class="bi {{ $aplicada ? 'bi-check2-circle text-success' : 'bi-eye text-primary' }}" aria-hidden="true"></i></div>
            <div>
                <h1>{{ $aplicada ? 'Resultado' : 'Vista previa' }}: {{ $importador->titulo() }}</h1>
                <p class="text-break">Archivo <strong>{{ $importacion->archivo_nombre }}</strong> · {{ number_format($importacion->total_filas) }} {{ $importacion->total_filas === 1 ? 'fila' : 'filas' }}
                    · subido el @fecha($importacion->created_at)@if ($aplicada) · guardado el @fecha($importacion->aplicada_en)@endif</p>
            </div>
        </div>
        <a href="{{ route('importaciones.create', $importador->tipo()) }}" class="enlace-volver"><i class="bi bi-arrow-left" aria-hidden="true"></i> Importar otro archivo</a>
    </div>

    @include('importaciones._pasos', ['actual' => $aplicada ? 4 : 2])

    @if ($ignoradas !== [])
        <div class="alert alert-secondary small" role="status"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>
            {{ count($ignoradas) === 1 ? 'Esta columna no es de la plantilla y no se toma en cuenta' : 'Estas columnas no son de la plantilla y no se toman en cuenta' }}:
            {{ collect($ignoradas)->map(fn ($c) => '«'.$c.'»')->join(', ') }}.
        </div>
    @endif
    @if ($ocultasPersonales)
        <div class="alert alert-secondary small" role="status"><i class="bi bi-lock me-1" aria-hidden="true"></i>Tu rol no ve datos personales (CURP, RFC, NSS…): esas columnas no se muestran ni se guardan con tu usuario.</div>
    @endif

    {{-- Conteos: cada uno filtra la tabla --}}
    <nav class="importar-conteos mb-3" aria-label="Filtrar filas">
        @foreach ($chips as $clave => [$texto, $numero, $clase])
            <a href="{{ route('importaciones.show', ['importacion' => $importacion, 'ver' => $clave === 'todas' ? null : $clave]) }}"
               class="conteo-importar {{ $clase }} {{ $ver === $clave ? 'activo' : '' }}" @if ($ver === $clave) aria-current="true" @endif>
                <strong>{{ number_format($numero) }}</strong> {{ $texto }}
            </a>
        @endforeach
    </nav>

    @if (! $aplicada)
        <section class="tarjeta p-4 mb-4" aria-labelledby="t-guardar">
            <h2 id="t-guardar" class="h5 fw-bold mb-2"><span class="numero-paso">3</span>Guarda lo que está bien</h2>
            <p class="small text-muted mb-3">Todavía no se ha guardado nada.
                @if (($conteo['error'] ?? 0) > 0)
                    Las <strong>{{ $conteo['error'] }}</strong> {{ $conteo['error'] === 1 ? 'fila con error no se guarda' : 'filas con error no se guardan' }}: corrígelas en tu archivo y súbelo otra vez (las que ya se guardaron saldrán como «Ya existe»), o guarda ahora las demás.
                @endif
            </p>
            <form action="{{ route('importaciones.aplicar', $importacion) }}" method="POST">
                @csrf
                @if (($conteo['existe'] ?? 0) > 0 && $importador->actualizable())
                    <fieldset class="mb-3">
                        <legend class="small fw-bold mb-1">¿Qué hacemos con los {{ $conteo['existe'] }} que ya existen?</legend>
                        <label class="fila-check mb-1"><input type="radio" name="existentes" value="omitir" checked> <span class="small">Dejarlos como están (se omiten)</span></label>
                        <label class="fila-check mb-1 {{ $puedeEditar ? '' : 'text-muted' }}"><input type="radio" name="existentes" value="actualizar" @disabled(! $puedeEditar)>
                            <span class="small">Actualizar sus datos con los del archivo ({{ $porActualizar }} se pueden actualizar; una celda vacía no borra el dato que ya tienen)@unless ($puedeEditar) — tu rol no puede editar @endunless</span></label>
                    </fieldset>
                @elseif (($conteo['existe'] ?? 0) > 0)
                    <p class="small text-muted">Los {{ $conteo['existe'] }} que ya existen se dejan como están.</p>
                @endif
                @if ($porGuardar > 0 || ($puedeEditar && $porActualizar > 0))
                    <button type="submit" class="btn-azul boton-importar"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>Guardar</button>
                    <span class="small text-muted ms-2">{{ $porGuardar }} {{ $porGuardar === 1 ? 'nuevo' : 'nuevos' }} se darán de alta.</span>
                @else
                    <p class="small fw-bold mb-0"><i class="bi bi-exclamation-circle me-1 text-warning" aria-hidden="true"></i>No hay nada nuevo que guardar: corrige las filas con error en tu archivo y súbelo de nuevo.</p>
                @endif
            </form>
        </section>
    @else
        <section class="tarjeta p-4 mb-4" aria-labelledby="t-resultado">
            <h2 id="t-resultado" class="h5 fw-bold mb-2"><i class="bi bi-check2-circle me-2 text-success" aria-hidden="true"></i>Así quedó</h2>
            <p class="small mb-3">{{ $conteo['creada'] ?? 0 }} nuevos · {{ $conteo['actualizada'] ?? 0 }} actualizados · {{ $conteo['omitida'] ?? 0 }} sin cambios (ya existían) · {{ $conteo['error'] ?? 0 }} con error.</p>
            <div class="d-flex flex-wrap gap-2">
                <a href="{{ $importador->rutaLista() }}" class="btn-importar-descarga"><i class="bi bi-arrow-right" aria-hidden="true"></i> Ir a {{ $importador->titulo() }}</a>
                <a href="{{ route('importaciones.resultado', $importacion) }}" class="btn-importar-descarga secundario"><i class="bi bi-download" aria-hidden="true"></i> Descargar resultado</a>
                @if ($puedeOriginal)
                    <a href="{{ route('importaciones.original', $importacion) }}" class="btn-importar-descarga secundario"><i class="bi bi-file-earmark" aria-hidden="true"></i> Descargar archivo original</a>
                @endif
            </div>
        </section>
    @endif

    <section class="tarjeta p-0 mb-4" aria-label="Filas del archivo">
        @if ($filas->isEmpty())
            <p class="text-muted small p-4 m-0">No hay filas con este filtro.</p>
        @else
            <div class="table-responsive tabla-importar-contenedor">
                <table class="table table-sm small align-middle mb-0 tabla-importar">
                    <thead>
                        <tr>
                            <th scope="col">Fila</th>
                            <th scope="col">Estado</th>
                            @foreach ($columnas as $columna)
                                <th scope="col" class="text-nowrap">{{ $columna->titulo }}</th>
                            @endforeach
                            <th scope="col">Detalle</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($filas as $r)
                            @php $celdas = $valores[$r['fila']] ?? []; $errores = $r['errores'] ?? []; @endphp
                            <tr class="fila-{{ $clasesEstado[$r['estado']] ?? 'error' }}">
                                <td>{{ $r['fila'] }}</td>
                                <td><span class="estado-importar {{ $clasesEstado[$r['estado']] ?? 'error' }}">{{ $etiquetas[$r['estado']] ?? $r['estado'] }}</span></td>
                                @foreach ($columnas as $columna)
                                    @php $error = $errores[$columna->clave] ?? null; @endphp
                                    <td class="{{ $error ? 'celda-error' : '' }}">
                                        {{ $celdas[$columna->clave] ?? '' }}
                                        @if ($error)<small class="d-block motivo-error"><i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>{{ $error }}</small>@endif
                                    </td>
                                @endforeach
                                <td class="detalle-importar">
                                    {{ $r['mensaje'] ?? '' }}
                                    @if (! empty($r['no_actualiza']))<span class="d-block text-muted">{{ $r['no_actualiza'] }}</span>@endif
                                    @if (! empty($errores['general']))<span class="d-block motivo-error">{{ $errores['general'] }}</span>@endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @if ($paginas > 1)
        <nav class="d-flex flex-wrap gap-2 align-items-center mb-4" aria-label="Páginas">
            <span class="small text-muted">Página {{ $pagina }} de {{ $paginas }}:</span>
            @for ($p = 1; $p <= $paginas; $p++)
                <a href="{{ route('importaciones.show', ['importacion' => $importacion, 'ver' => $ver === 'todas' ? null : $ver, 'pagina' => $p]) }}" class="conteo-importar {{ $p === $pagina ? 'activo' : '' }}">{{ $p }}</a>
            @endfor
        </nav>
    @endif
</div>
@endsection
