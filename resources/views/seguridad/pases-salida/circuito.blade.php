@extends('layouts.app')

@section('titulo', 'Circuito de aprobación — Pases de Salida')

@section('contenido')
<div class="tema-azul pantalla-pases">
    @include('administracion.partes.avisos')
    @include('administracion.partes.selector-empresa')

    <a href="{{ route('pases-salida.index') }}" class="volver-pases"><i class="bi bi-arrow-left" aria-hidden="true"></i> Pases de salida</a>
    <div class="encabezado-pantalla mb-3">
        <div class="icono"><i class="bi bi-diagram-3 text-primary" aria-hidden="true"></i></div>
        <div>
            <h1>Circuito de aprobación</h1>
            <p>Quién aprueba cada pase de salida y en qué orden. Configuración → Pases de salida.</p>
        </div>
    </div>

    @if ($sinEmpresa)
        <div class="tarjeta estado-vacio"><p class="text-muted small m-0">Elige arriba la <strong>empresa de trabajo</strong>.</p></div>
    @else
        @php
            $filas = old('pasos') !== null ? array_values((array) old('pasos')) : $pasos->map(fn ($p) => [
                'nombre' => $p->nombre, 'tipo' => $p->tipo, 'rol_id' => $p->rol_id, 'departamento' => $p->departamento, 'departamento_id' => $p->departamento_id,
                'obligatorio' => $p->obligatorio, 'motivos' => $p->motivos ?? [], 'usuarios' => $p->usuarios->pluck('id')->all(),
            ])->all();
        @endphp

        <div class="tarjeta p-3 mb-3 explicacion-circuito">
            <p class="mb-2"><strong>Cómo funciona:</strong> los pasos se firman <strong>en orden</strong>; el siguiente se abre cuando el anterior aprueba. Si alguien rechaza, el pase regresa al solicitante para corregirlo.</p>
            <ul class="small mb-0">
                <li>Quien aprueba necesita el permiso <strong>«Aprobar»</strong> de Pases de salida en la sede de donde sale el equipo.</li>
                <li>El solicitante nunca aprueba su propio pase, y una persona no firma dos pasos del mismo pase (salvo que nadie más pueda).</li>
                <li>Si nadie cumple la regla de un paso, lo puede firmar cualquiera con «Aprobar» en la sede (así ningún pase se queda atorado).</li>
                <li>Los cambios aplican a los pases nuevos y a los que se reenvíen; los que ya están en curso conservan sus pasos.</li>
            </ul>
            @unless ($configurado)
                <p class="small text-primary mt-2 mb-0"><i class="bi bi-info-circle" aria-hidden="true"></i> Hoy se usa la cadena de SEGCAT: <strong>Jefe de Departamento → Contraloría → Gerencia</strong>.</p>
            @endunless
        </div>

        @unless ($puedeEditar)
            <div class="alert alert-warning small"><i class="bi bi-lock me-1" aria-hidden="true"></i>Solo consulta: el circuito es de toda la empresa y lo cambia quien tiene «Configurar» de Pases de salida con alcance de empresa.</div>
        @endunless

        <form action="{{ route('pases-salida.circuito.update') }}" method="POST" data-form-circuito>
            @csrf
            @method('PUT')
            <fieldset @disabled(! $puedeEditar)>
                <div class="pasos-circuito" data-pasos-circuito data-siguiente="{{ count($filas) }}">
                    @foreach ($filas as $i => $f)
                        @include('seguridad.pases-salida._paso-circuito', ['i' => $i, 'f' => $f])
                    @endforeach
                </div>
                <template data-plantilla-paso-circuito>
                    @include('seguridad.pases-salida._paso-circuito', ['i' => '__i__', 'f' => ['tipo' => 'permiso', 'departamento' => 'cualquiera', 'obligatorio' => true, 'motivos' => [], 'usuarios' => []]])
                </template>
                @if ($puedeEditar)
                    <div class="d-flex flex-wrap gap-2 mt-2">
                        <button type="button" class="btn-agregar-articulo" data-agregar-paso-circuito><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Agregar paso</button>
                        <button type="submit" class="btn-nuevo-pase"><i class="bi bi-save me-2" aria-hidden="true"></i>Guardar circuito</button>
                    </div>
                @endif
            </fieldset>
        </form>

        @if ($puedeEditar && $configurado)
            <form action="{{ route('pases-salida.circuito.destroy') }}" method="POST" class="mt-3" data-confirmar="¿Volver a la cadena de SEGCAT (Jefe de Departamento, Contraloría y Gerencia)?">
                @csrf
                @method('DELETE')
                <button type="submit" class="btn-enlace-pase"><i class="bi bi-arrow-counterclockwise me-1" aria-hidden="true"></i>Restablecer la cadena de SEGCAT</button>
            </form>
        @endif
    @endif
</div>
@endsection
