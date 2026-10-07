@extends('layouts.app')

@section('titulo', $plantilla->exists ? 'Editar plantilla de etiqueta' : 'Nueva plantilla de etiqueta')

@section('contenido')
{{--
    Ronda 7: alta y edición de una plantilla de etiqueta QR. Página propia (son
    muchos campos). Los campos de la hoja (data-solo-formato="hoja") y la vista
    previa a escala (data-vista-plantilla) los maneja el bloque «Ajustes Ronda 7»
    de plataforma.js. Todo se vuelve a revisar en el servidor (PlantillasEtiquetas).
--}}
@php
    $p = $plantilla;
    $num = fn (string $campo) => old($campo, $p->{$campo} === null ? '' : $p->mm((float) $p->{$campo}));
    $formato = old('formato', $p->formato ?? 'rollo');
    $esHoja = $formato === 'hoja';
    $sedePorDefecto = $p->exists ? (string) $p->sede_id : ($deEmpresa ? '' : (string) $sedes->first()?->id);
    $sedeElegida = (string) old('sede_id', $sedePorDefecto);
    $hayDatosEnviados = old('_token') !== null;
@endphp
<div class="tema-gafetes pantalla-etiquetas-qr">
    <div class="encabezado-pantalla mb-3">
        <div class="icono"><i class="bi bi-rulers text-dark" aria-hidden="true"></i></div>
        <div>
            <h1>{{ $p->exists ? 'Editar plantilla' : 'Nueva plantilla' }}</h1>
            <p>{{ $p->exists ? $p->nombre : 'Escribe las medidas de tu etiqueta en milímetros (mm). La vista previa se acomoda mientras escribes.' }}</p>
        </div>
    </div>

    @include('administracion.partes.avisos')

    <form method="POST" action="{{ $p->exists ? route('etiquetas.plantillas.update', $p->id) : route('etiquetas.plantillas.store') }}" autocomplete="off" class="form-plantilla-qr" data-form-plantilla-qr>
        @csrf
        @if ($p->exists) @method('PUT') @endif

        <div class="plantilla-qr-columnas">
            <div class="tarjeta">
                <h2 class="titulo-seccion-qr">1. Nombre y lugar</h2>
                <label class="campo-etiqueta" for="pl_nombre">Nombre de la plantilla</label>
                <input type="text" id="pl_nombre" name="nombre" class="campo @error('nombre') es-invalido @enderror" maxlength="80" required value="{{ old('nombre', $p->nombre) }}" placeholder="Ej. Zebra 2 × 1 pulgadas">
                @error('nombre')<p class="texto-error-campo">{{ $message }}</p>@enderror

                <label class="campo-etiqueta" for="pl_sede">¿Quién la usa?</label>
                <select id="pl_sede" name="sede_id" class="campo @error('sede_id') es-invalido @enderror" @unless ($deEmpresa) required @endunless>
                    @if ($deEmpresa)<option value="">Toda la empresa</option>@endif
                    @foreach ($sedes as $s)
                        <option value="{{ $s->id }}" @selected($sedeElegida === (string) $s->id)>Solo la sede {{ $s->nombre }}</option>
                    @endforeach
                </select>
                @error('sede_id')<p class="texto-error-campo">{{ $message }}</p>@enderror

                <h2 class="titulo-seccion-qr">2. Papel</h2>
                <fieldset class="opciones-formato-qr">
                    <legend class="campo-etiqueta">Formato</legend>
                    @foreach (\App\Models\EtiquetaPlantilla::FORMATOS as $clave => $texto)
                        <label class="opcion-formato-qr">
                            <input type="radio" name="formato" value="{{ $clave }}" @checked($formato === $clave) data-formato-plantilla>
                            <span><i class="bi {{ $clave === 'hoja' ? 'bi-file-earmark' : 'bi-receipt' }}" aria-hidden="true"></i> {{ $texto }}</span>
                        </label>
                    @endforeach
                </fieldset>

                <div data-solo-formato="hoja" @unless ($esHoja) hidden @endunless>
                    <label class="campo-etiqueta" for="pl_papel">Hoja</label>
                    <select id="pl_papel" name="papel" class="campo" @unless ($esHoja) disabled @endunless>
                        @foreach (\App\Models\EtiquetaPlantilla::PAPELES as $clave => $datos)
                            <option value="{{ $clave }}" data-ancho="{{ $datos[1] }}" data-alto="{{ $datos[2] }}" @selected(old('papel', $p->papel ?? 'carta') === $clave)>{{ $datos[0] }}</option>
                        @endforeach
                    </select>
                </div>

                <div class="fila-medidas-qr">
                    <div>
                        <label class="campo-etiqueta" for="pl_ancho">Ancho de la etiqueta (mm)</label>
                        <input type="text" inputmode="decimal" id="pl_ancho" name="ancho_mm" class="campo @error('ancho_mm') es-invalido @enderror" required value="{{ $num('ancho_mm') }}" data-medida>
                    </div>
                    <div>
                        <label class="campo-etiqueta" for="pl_alto">Alto de la etiqueta (mm)</label>
                        <input type="text" inputmode="decimal" id="pl_alto" name="alto_mm" class="campo @error('alto_mm') es-invalido @enderror" required value="{{ $num('alto_mm') }}" data-medida>
                    </div>
                </div>
                @error('ancho_mm')<p class="texto-error-campo">{{ $message }}</p>@enderror
                @error('alto_mm')<p class="texto-error-campo">{{ $message }}</p>@enderror
                <p class="campo-ayuda mb-3">Comunes: 50 × 25 (2 × 1 pulgadas), 40 × 25, 100 × 50 (4 × 2 pulgadas), 102 × 152 (4 × 6 pulgadas). 1 pulgada = 25.4 mm.</p>

                <div data-solo-formato="hoja" @unless ($esHoja) hidden @endunless>
                    <div class="fila-medidas-qr">
                        <div>
                            <label class="campo-etiqueta" for="pl_columnas">Columnas (a lo ancho)</label>
                            <input type="number" id="pl_columnas" name="columnas" class="campo @error('columnas') es-invalido @enderror" min="1" max="10" inputmode="numeric" value="{{ old('columnas', $p->columnas ?: 2) }}" data-medida @unless ($esHoja) disabled @endunless>
                        </div>
                        <div>
                            <label class="campo-etiqueta" for="pl_filas">Filas (a lo largo)</label>
                            <input type="number" id="pl_filas" name="filas" class="campo @error('filas') es-invalido @enderror" min="1" max="30" inputmode="numeric" value="{{ old('filas', $p->columnas > 1 || $p->filas > 1 ? $p->filas : 5) }}" data-medida @unless ($esHoja) disabled @endunless>
                        </div>
                    </div>
                    @error('columnas')<p class="texto-error-campo">{{ $message }}</p>@enderror
                    @error('filas')<p class="texto-error-campo">{{ $message }}</p>@enderror
                    <div class="fila-medidas-qr">
                        <div>
                            <label class="campo-etiqueta" for="pl_margen_sup">Margen de arriba (mm)</label>
                            <input type="text" inputmode="decimal" id="pl_margen_sup" name="margen_superior_mm" class="campo" value="{{ $num('margen_superior_mm') }}" data-medida @unless ($esHoja) disabled @endunless>
                        </div>
                        <div>
                            <label class="campo-etiqueta" for="pl_margen_izq">Margen izquierdo (mm)</label>
                            <input type="text" inputmode="decimal" id="pl_margen_izq" name="margen_izquierdo_mm" class="campo" value="{{ $num('margen_izquierdo_mm') }}" data-medida @unless ($esHoja) disabled @endunless>
                        </div>
                    </div>
                    <div class="fila-medidas-qr">
                        <div>
                            <label class="campo-etiqueta" for="pl_sep_h">Separación entre columnas (mm)</label>
                            <input type="text" inputmode="decimal" id="pl_sep_h" name="separacion_horizontal_mm" class="campo" value="{{ $num('separacion_horizontal_mm') }}" data-medida @unless ($esHoja) disabled @endunless>
                        </div>
                    </div>
                </div>
                <div>
                    <label class="campo-etiqueta" for="pl_sep_v"><span data-texto-formato="hoja" @unless ($esHoja) hidden @endunless>Separación entre filas (mm)</span><span data-texto-formato="rollo" @if ($esHoja) hidden @endif>Avance entre etiquetas (mm)</span></label>
                    <input type="text" inputmode="decimal" id="pl_sep_v" name="separacion_vertical_mm" class="campo mb-1" value="{{ $num('separacion_vertical_mm') }}" data-medida>
                    <p class="campo-ayuda mb-3" data-texto-formato="rollo" @if ($esHoja) hidden @endif>Déjalo en 0 si tu rollo tiene etiquetas ya cortadas (la impresora detecta el espacio). En papel continuo, el espacio en blanco que quieres entre una y otra.</p>
                </div>
                <p class="aviso-medidas-qr" data-aviso-medidas role="status" aria-live="polite" hidden></p>
            </div>

            <div class="tarjeta">
                <h2 class="titulo-seccion-qr">3. Contenido</h2>
                <fieldset class="opciones-formato-qr">
                    <legend class="campo-etiqueta">Orientación</legend>
                    @foreach (\App\Models\EtiquetaPlantilla::ORIENTACIONES as $clave => $texto)
                        <label class="opcion-formato-qr">
                            <input type="radio" name="orientacion" value="{{ $clave }}" @checked(old('orientacion', $p->orientacion ?? 'horizontal') === $clave) data-medida>
                            <span><i class="bi {{ $clave === 'vertical' ? 'bi-distribute-vertical' : 'bi-distribute-horizontal' }}" aria-hidden="true"></i> {{ $texto }}</span>
                        </label>
                    @endforeach
                </fieldset>

                <label class="campo-etiqueta" for="pl_qr">Tamaño del QR (mm)</label>
                <input type="text" inputmode="decimal" id="pl_qr" name="qr_mm" class="campo mb-1 @error('qr_mm') es-invalido @enderror" required value="{{ $num('qr_mm') }}" data-medida>
                @error('qr_mm')<p class="texto-error-campo">{{ $message }}</p>@enderror
                <p class="campo-ayuda mb-3">Mínimo {{ \App\Services\Lector\PlantillasEtiquetas::QR_MINIMO }} mm. Más grande se lee más rápido y desde más lejos.</p>

                <fieldset class="mb-3">
                    <legend class="campo-etiqueta">Datos visibles</legend>
                    @foreach (\App\Models\EtiquetaPlantilla::DATOS as $campo => $texto)
                        <input type="hidden" name="{{ $campo }}" value="0">
                        <label class="fila-check dato-plantilla-qr">
                            <input type="checkbox" name="{{ $campo }}" value="1" @checked($hayDatosEnviados ? old($campo) === '1' : (bool) ($p->{$campo} ?? false)) data-medida>
                            <span>{{ $texto }}</span>
                        </label>
                    @endforeach
                    @error('mostrar_titulo')<p class="texto-error-campo">{{ $message }}</p>@enderror
                </fieldset>

                <h2 class="titulo-seccion-qr">Vista previa</h2>
                <div class="vista-plantilla-qr" data-vista-plantilla aria-hidden="true">
                    <div class="vista-etiqueta-qr" data-vista-etiqueta>
                        <span class="vista-qr" data-vista-qr><i class="bi bi-qr-code"></i></span>
                        <span class="vista-texto">
                            <span class="vista-linea logo" data-vista-dato="mostrar_logo">EMPRESA</span>
                            <span class="vista-linea titulo" data-vista-dato="mostrar_titulo">LL-CAT-01</span>
                            <span class="vista-linea" data-vista-dato="mostrar_tipo">Llaves</span>
                            <span class="vista-linea" data-vista-dato="mostrar_ubicacion">Sistemas · Sede</span>
                            <span class="vista-linea" data-vista-dato="mostrar_fecha">06/10/2026</span>
                            <span class="vista-linea codigo" data-vista-dato="mostrar_codigo">ABCD EFGH</span>
                        </span>
                    </div>
                </div>
                <p class="campo-ayuda mt-2 mb-0" data-vista-resumen></p>
            </div>
        </div>

        <div class="acciones-plantilla-qr">
            <a href="{{ route('etiquetas.plantillas') }}" class="btn-cancelar">Cancelar</a>
            <button type="submit" class="btn-gafetes"><i class="bi bi-check-lg me-1" aria-hidden="true"></i>{{ $p->exists ? 'Guardar cambios' : 'Crear plantilla' }}</button>
        </div>
    </form>
</div>
@endsection
