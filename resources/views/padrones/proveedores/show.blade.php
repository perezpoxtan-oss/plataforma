@extends('layouts.app')

@section('titulo', 'Ficha de '.$proveedor->nombre)

@section('contenido')
@php
    [$icono, $clase, $insignia] = \App\Http\Controllers\Padrones\ProveedorController::ESTILOS[$proveedor->categoria] ?? ['bi-building', 'bg-agencia', $proveedor->categoria];
    $dialogo = old('_dialogo');
    $sedesActivas = $proveedor->sedes->where('activo', true);
    $etiquetaSedes = $proveedor->todas_las_sedes || ($totalSedes > 0 && $sedesActivas->count() >= $totalSedes) ? 'Todas las sedes' : $sedesActivas->count().' de '.$totalSedes.' sedes';
    $pestanas = [
        'resumen' => ['bi-info-circle', 'Resumen'],
        'personal' => ['bi-people', 'Personal ('.$totalPersonas.')'],
        'flotilla' => ['bi-truck', 'Flotilla ('.$totalVehiculos.')'],
    ];
@endphp
<div class="tema-esmeralda pantalla-proveedores ficha-proveedor">
    @include('administracion.partes.avisos')

    <div class="d-flex align-items-center gap-2 mb-3">
        <a href="{{ route('proveedores.index') }}" class="btn-icono" aria-label="Regresar a Empresas Externas" title="Regresar a Empresas Externas"><i class="bi bi-arrow-left" aria-hidden="true"></i></a>
        <h1 class="h5 fw-bold m-0">Ficha de Proveedor</h1>
    </div>

    <section class="ficha-header-prov" aria-labelledby="nombre-proveedor">
        <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
            <div>
                <span class="ext-badge {{ $clase }}"><i class="bi {{ $icono }} me-1" aria-hidden="true"></i>{{ $insignia }}</span>
                <h2 id="nombre-proveedor" class="ext-title mt-2">{{ $proveedor->nombre }}</h2>
                <p class="text-muted small m-0">Padrón de {{ $empresaNombre }}</p>
            </div>
            <span class="etiqueta-estado {{ $proveedor->activo ? 'activo' : 'inactivo' }}">{{ $proveedor->activo ? 'ACTIVA' : 'BAJA / VETADA' }}</span>
        </div>
        <div class="datos-proveedor">
            @if ($proveedor->rfc)<span><i class="bi bi-card-text" aria-hidden="true"></i> RFC: <b>{{ $proveedor->rfc }}</b></span>@endif
            @if ($proveedor->telefono)<span><i class="bi bi-telephone" aria-hidden="true"></i> <a href="tel:{{ $proveedor->telefono }}">{{ $proveedor->telefono }}</a></span>@endif
            @if ($proveedor->direccion)<span><i class="bi bi-geo-alt" aria-hidden="true"></i> {{ $proveedor->direccion }}</span>@endif
            <span><i class="bi bi-signpost-split" aria-hidden="true"></i> {{ $etiquetaSedes }}</span>
        </div>
        @if ($actualizadoPor && $proveedor->updated_at?->ne($proveedor->created_at))
            <p class="texto-traza mt-2 mb-0"><i class="bi bi-clock-history" aria-hidden="true"></i> Editado por {{ $actualizadoPor }} · @fecha($proveedor->updated_at)</p>
        @elseif ($creadoPor)
            <p class="texto-traza mt-2 mb-0"><i class="bi bi-plus-circle" aria-hidden="true"></i> Creado por {{ $creadoPor }} · @fecha($proveedor->created_at)</p>
        @endif
        @if ($puede['editar'] || $puede['sedes'])
            <div class="d-flex flex-wrap gap-2 mt-3">
                @if ($puede['editar'])
                    <button type="button" class="btn-ficha" data-abrir-dialogo="dialogoEditarProveedor"><i class="bi bi-pencil-square me-1" aria-hidden="true"></i>Editar Datos Generales</button>
                @endif
                @if ($puede['sedes'])
                    <button type="button" class="btn-ficha" data-abrir-dialogo="dialogoSedesProveedor{{ $proveedor->id }}"><i class="bi bi-signpost-split me-1" aria-hidden="true"></i>Sedes donde opera</button>
                @endif
            </div>
        @endif
    </section>

    <nav class="tabs-ficha" aria-label="Secciones de la ficha">
        @foreach ($pestanas as $clave => [$iconoTab, $textoTab])
            <a href="{{ route('proveedores.show', ['proveedor' => $proveedor->id, 'tab' => $clave]) }}" class="tab-ficha {{ $pestana === $clave ? 'activo' : '' }}" @if ($pestana === $clave) aria-current="page" @endif>
                <i class="bi {{ $iconoTab }} me-1" aria-hidden="true"></i>{{ $textoTab }}
            </a>
        @endforeach
    </nav>

    @if ($pestana === 'resumen')
        <section class="ficha-header-prov" aria-labelledby="titulo-sedes-opera">
            <h3 id="titulo-sedes-opera" class="h6 fw-bold mb-2"><i class="bi bi-signpost-split me-1" aria-hidden="true"></i> Sedes donde opera</h3>
            @if ($proveedor->todas_las_sedes)
                <p class="small m-0"><span class="chip-sede"><i class="bi bi-check2-all me-1" aria-hidden="true"></i>Todas las sedes</span> <span class="text-muted">— incluidas las que se abran después.</span></p>
            @elseif ($proveedor->sedes->isEmpty())
                <p class="text-muted small m-0">Sin sedes asignadas todavía. @if ($puede['sedes']) Usa «Sedes donde opera» para asignarlas. @endif</p>
            @else
                <div class="d-flex flex-wrap gap-2">
                    @foreach ($proveedor->sedes as $sede)
                        <span class="chip-sede {{ $sede->activo ? '' : 'inactiva' }}">{{ $sede->nombre }}@unless ($sede->activo) (inactiva)@endunless</span>
                    @endforeach
                </div>
            @endif
        </section>
        <div class="resumen-padron">
            <a href="{{ route('proveedores.show', ['proveedor' => $proveedor->id, 'tab' => 'personal']) }}" class="ficha">
                <div class="icono"><i class="bi bi-people" aria-hidden="true"></i></div>
                <div class="valor">{{ $totalPersonas }}</div>
                <div class="etiqueta">Personal registrado</div>
            </a>
            <a href="{{ route('proveedores.show', ['proveedor' => $proveedor->id, 'tab' => 'flotilla']) }}" class="ficha">
                <div class="icono"><i class="bi bi-truck" aria-hidden="true"></i></div>
                <div class="valor">{{ $totalVehiculos }}</div>
                <div class="etiqueta">Vehículos en flotilla</div>
            </a>
        </div>
    @elseif ($pestana === 'personal')
        @if (! $puede['verPersonas'])
            <p class="text-muted small"><i class="bi bi-lock-fill me-1" aria-hidden="true"></i>Tu rol no puede ver el Padrón de Personas.</p>
        @else
            @if ($puede['agregarPersona'])
                <a href="{{ route('personas.index', ['nuevo' => 1, 'proveedor' => $proveedor->id]) }}" class="btn-agregar-padron mb-3"><i class="bi bi-person-plus me-1" aria-hidden="true"></i> Agregar persona</a>
            @endif
            @forelse ($personas as $persona)
                @php
                    $identificacion = $persona->folio_identificacion ? (\App\Models\Persona::IDENTIFICACIONES[$persona->tipo_identificacion] ?? 'Identificación').': '.$persona->folio_identificacion : null;
                @endphp
                <div class="fila-item {{ $persona->activo ? '' : 'inactivo' }}">
                    <div>
                        <h4 class="fila-titulo">
                            @if ($puede['enlacePersonas'])
                                <a href="{{ route('personas.index').'#persona-'.$persona->id }}">{{ $persona->nombre_completo }}</a>
                            @else
                                {{ $persona->nombre_completo }}
                            @endif
                            @unless ($persona->activo)<span class="etiqueta-inactiva ms-1">BAJA</span>@endunless
                        </h4>
                        <div class="meta">
                            {{ \App\Models\Persona::TIPOS[$persona->tipo] ?? $persona->tipo }}
                            @if ($identificacion) · {{ $identificacion }} @endif
                            @if ($persona->telefono) · <i class="bi bi-telephone" aria-hidden="true"></i> {{ $persona->telefono }} @endif
                        </div>
                    </div>
                </div>
            @empty
                <p class="text-muted small text-center py-3">Sin personal registrado para este proveedor todavía.</p>
            @endforelse
        @endif
    @else
        @if (! $puede['verVehiculos'])
            <p class="text-muted small"><i class="bi bi-lock-fill me-1" aria-hidden="true"></i>Tu rol no puede ver el Padrón Vehicular.</p>
        @else
            @if ($puede['agregarVehiculo'])
                <a href="{{ route('vehiculos.index', ['nuevo' => 1, 'proveedor' => $proveedor->id]) }}" class="btn-agregar-padron mb-3"><i class="bi bi-truck me-1" aria-hidden="true"></i> Agregar vehículo</a>
            @endif
            @forelse ($vehiculos as $vehiculo)
                <div class="fila-item {{ $vehiculo->activo ? '' : 'inactivo' }}">
                    <div>
                        <h4 class="fila-titulo">
                            @if ($puede['enlaceVehiculos'])
                                <a href="{{ route('vehiculos.index').'#vehiculo-'.$vehiculo->id }}">{{ $vehiculo->placas }}</a>
                            @else
                                {{ $vehiculo->placas }}
                            @endif
                            @unless ($vehiculo->activo)<span class="etiqueta-inactiva ms-1">BAJA</span>@endunless
                        </h4>
                        <div class="meta">
                            {{ \App\Models\Vehiculo::PROPIEDADES[$vehiculo->propiedad] ?? $vehiculo->propiedad }}
                            @if ($vehiculo->marca || $vehiculo->modelo) · {{ trim($vehiculo->marca.' '.$vehiculo->modelo) }} @endif
                            @if ($vehiculo->color) · {{ $vehiculo->color }} @endif
                            @if ($vehiculo->numero_economico) · Eco: {{ $vehiculo->numero_economico }} @endif
                        </div>
                    </div>
                </div>
            @empty
                <p class="text-muted small text-center py-3">Sin vehículos registrados para este proveedor todavía.</p>
            @endforelse
        @endif
    @endif

    {{-- ===== Edición de datos generales ===== --}}
    @if ($puede['editar'])
        @php $reabrir = $dialogo === 'editar-'.$proveedor->id; @endphp
        <dialog id="dialogoEditarProveedor" class="dialogo" aria-labelledby="titulo-proveedor-editar" @if ($reabrir) data-abrir-al-cargar @endif>
            <div class="dialogo-cabecera">
                <h2 id="titulo-proveedor-editar"><i class="bi bi-pencil-square me-2 text-success" aria-hidden="true"></i>Editar Empresa Externa</h2>
                <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
            </div>
            <div class="dialogo-cuerpo">
                <form action="{{ route('proveedores.update', $proveedor->id) }}" method="POST" autocomplete="off">
                    @csrf
                    @method('PUT')
                    <input type="hidden" name="_dialogo" value="editar-{{ $proveedor->id }}" data-campo-dialogo>
                    <input type="hidden" name="_volver" value="ficha">
                    @include('padrones.proveedores._campos', ['prefijo' => 'ficha_proveedor', 'reabrir' => $reabrir, 'duplicado' => true, 'valores' => $proveedor->only(['nombre', 'categoria', 'rfc', 'telefono', 'direccion'])])
                    <div class="dialogo-acciones">
                        <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                        <button type="submit" class="btn-esmeralda">Actualizar Datos</button>
                    </div>
                </form>
                @include('componentes.borrar', ['registro' => 'proveedores', 'id' => $proveedor->id])
            </div>
        </dialog>
    @endif

    @if ($puede['sedes'])
        @include('padrones.proveedores._sedes', ['reabrir' => $dialogo === 'sedes-'.$proveedor->id, 'volver' => 'ficha'])
    @endif
</div>
@endsection
