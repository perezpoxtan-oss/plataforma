@extends('layouts.app')

@section('titulo', 'Pisos de '.$nodo->nombre)

@section('contenido')
<div class="tema-ambar">
    @include('administracion.partes.avisos')
    @include('organizacion.espacios.partes.migas')

    <div class="encabezado-pantalla mb-3">
        <div class="icono"><i class="bi bi-layers-fill text-warning" aria-hidden="true"></i></div>
        <div>
            <h1>Pisos de {{ $nodo->nombre }} @unless ($nodo->activo)<span class="etiqueta-inactiva">INACTIVA</span>@endunless</h1>
            <p>{{ $nodo->sede->nombre }} — entra a un piso para ver sus {{ $etiquetas['area_especifica']['plural'] }}.</p>
        </div>
    </div>

    @if ($puede['crear'] && $nodo->activo && $otrosEdificios->where('pisos_count', '>', 0)->isNotEmpty())
        <button type="button" class="btn btn-outline-dark btn-sm fw-bold mb-3" style="min-height:40px;border-radius:10px;" data-abrir-dialogo="dialogoCopiarPisos">
            <i class="bi bi-copy me-1" aria-hidden="true"></i> Copiar estructura de pisos de otra zona
        </button>
    @endif

    <div class="fichas-grid">
        @if ($puede['crear'] && $nodo->activo)
            <button type="button" class="ficha-card ficha-create" data-abrir-dialogo="dialogoNuevoPiso">
                <i class="bi bi-plus-circle-fill" aria-hidden="true"></i>
                <span class="h6 fw-bold m-0 mt-2 titulo-crear">Nuevo Piso</span>
            </button>
        @endif

        @forelse ($pisos as $p)
            <div class="ficha-card" @unless ($p->activo) style="opacity:.6" @endunless>
                <div>
                    <div class="d-flex justify-content-between align-items-start gap-2">
                        <h2 class="h5 fw-bold m-0"><i class="bi bi-layers text-warning me-2" aria-hidden="true"></i>{{ $p->nombre }}
                            @unless ($p->activo)<span class="etiqueta-inactiva ms-1">INACTIVO</span>@endunless
                        </h2>
                        @include('organizacion.espacios.partes.acciones', ['e' => $p, 'dialogo' => 'dialogoEditarPiso', 'valores' => $p->only(['nombre', 'tipo_espacio_id'])])
                    </div>
                    @if ($p->tipo)<div class="small text-muted mt-1">{{ $p->tipo->nombre }}</div>@endif
                    <div class="small text-muted mt-2"><i class="bi bi-door-closed" aria-hidden="true"></i> {{ $p->habitaciones_count }} {{ $etiquetas['area_especifica']['plural'] }} registrada(s)</div>
                </div>
                <a href="{{ route('espacios.show', $p->id) }}" class="btn-entrar"><i class="bi bi-box-arrow-in-right" aria-hidden="true"></i> Entrar a {{ $etiquetas['area_especifica']['plural'] }}</a>
            </div>
        @empty
            @unless ($puede['crear'])
                <div class="tarjeta estado-vacio"><p class="text-muted small m-0">Esta zona todavía no tiene pisos.</p></div>
            @endunless
        @endforelse
    </div>

    @if ($puede['crear'])
        @include('organizacion.espacios.partes.formulario', [
            'id' => 'dialogoNuevoPiso', 'titulo' => 'Nuevo Piso', 'icono' => 'bi-layers text-warning',
            'nivel' => 'area', 'padreId' => $nodo->id, 'tipos' => $tiposPiso, 'editar' => false,
            'boton' => 'Agregar Piso', 'claseBoton' => 'btn-ambar', 'etiquetaNombre' => 'Nombre del Piso (ej. Piso 1, Planta Baja)',
        ])

        <dialog id="dialogoCopiarPisos" class="dialogo" aria-labelledby="tituloCopiarPisos">
            <div class="dialogo-cabecera">
                <h2 id="tituloCopiarPisos"><i class="bi bi-copy me-2" aria-hidden="true"></i>Copiar Estructura de Pisos</h2>
                <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </div>
            <div class="dialogo-cuerpo">
                <form action="{{ route('espacios.copiar', $nodo->id) }}" method="POST">
                    @csrf
                    <label class="campo-etiqueta" for="copiar_origen">Copiar desde</label>
                    <select id="copiar_origen" name="origen_id" class="campo" required>
                        @foreach ($otrosEdificios->where('pisos_count', '>', 0) as $otro)
                            <option value="{{ $otro->id }}">{{ $otro->nombre }} ({{ $otro->pisos_count }} pisos)</option>
                        @endforeach
                    </select>
                    <p class="campo-ayuda mb-3">Se copian solo los nombres de los pisos activos que esta zona todavía no tenga; no se copian {{ mb_strtolower($etiquetas['area_especifica']['plural']) }}.</p>
                    <div class="dialogo-acciones">
                        <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                        <button type="submit" class="btn-ambar">Copiar Pisos</button>
                    </div>
                </form>
            </div>
        </dialog>
    @endif
    @if ($puede['editar'])
        @include('organizacion.espacios.partes.formulario', [
            'id' => 'dialogoEditarPiso', 'titulo' => 'Editar Piso', 'icono' => 'bi-pencil-square text-warning',
            'nivel' => 'area', 'tipos' => $tiposPiso, 'editar' => true,
            'boton' => 'Guardar Cambios', 'claseBoton' => 'btn-ambar', 'etiquetaNombre' => 'Nombre del Piso',
        ])
    @endif
</div>
@endsection
