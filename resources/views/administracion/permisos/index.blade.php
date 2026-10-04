@extends('layouts.app')

@section('titulo', 'Permisos')

@section('contenido')
    @include('administracion.partes.avisos')

    @include('administracion.partes.selector-empresa')

    <div class="encabezado-pantalla mb-4">
        <div class="icono"><i class="bi bi-shield-lock-fill text-warning" aria-hidden="true"></i></div>
        <div>
            <h1>Permisos por Rol</h1>
            <p>Define qué puede ver, crear, editar o eliminar cada rol en cada módulo, y sobre qué registros.</p>
        </div>
    </div>

    @if ($roles->isEmpty())
        <div class="tarjeta estado-vacio">
            <div class="icono"><i class="bi bi-diagram-3" aria-hidden="true"></i></div>
            <p class="text-muted small m-0">No hay roles todavía. Créalos en <a href="{{ route('roles.index') }}">Roles y Jerarquía</a>.</p>
        </div>
    @else
        @if (! $puedeEditar)
            <div class="aviso-solo-lectura"><i class="bi bi-eye-fill me-2" aria-hidden="true"></i>Tu rol solo puede consultar esta pantalla, no guardar cambios.</div>
        @elseif (! $editable)
            <div class="aviso-solo-lectura"><i class="bi bi-eye-fill me-2" aria-hidden="true"></i>Este rol es de tu mismo nivel o superior: puedes consultarlo, pero no modificarlo.</div>
        @endif

        <nav class="rol-tabs" aria-label="Roles">
            @foreach ($roles as $r)
                <a href="{{ route('permisos.index', ['rol' => $r->id]) }}" class="rol-tab {{ $r->id === $rol->id ? 'activo' : '' }}" @if ($r->id === $rol->id) aria-current="page" @endif>
                    {{ $r->nombre }}@unless ($r->activo) <small>(inactivo)</small>@endunless
                </a>
            @endforeach
        </nav>

        @if ($ultimaModificacion)
            <p class="text-muted small mb-3"><i class="bi bi-clock-history" aria-hidden="true"></i> Última modificación de este rol: {{ $ultimaModificacion->name ?? 'alguien' }} · {{ \Illuminate\Support\Carbon::parse($ultimaModificacion->creado_en)->format('d/m/Y H:i') }}</p>
        @endif

        <p class="text-muted small mb-3">
            <i class="bi bi-info-circle" aria-hidden="true"></i>
            <strong>Alcance:</strong> "Solo los propios" = registros que esa persona capturó; "Su sede" = los de las sedes asignadas al usuario; "Toda la empresa" = todos.
            Marcar cualquier acción activa también "Ver".
        </p>

        <form action="{{ route('permisos.update', $rol->id) }}" method="POST" autocomplete="off"
              data-confirmar="¿Guardar estos permisos? Afecta de inmediato a todos los usuarios con el rol «{{ $rol->nombre }}».">
            @csrf
            @method('PUT')

            <div class="tabla-scroll">
                <table class="tabla-permisos">
                    <thead>
                        <tr>
                            <th scope="col">Módulo</th>
                            @foreach ($basicas as $accion)
                                <th scope="col">{{ $acciones[$accion] }}</th>
                            @endforeach
                            <th scope="col">Otras acciones</th>
                            <th scope="col">Alcance</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($areas as $area)
                            <tr class="fila-area"><td colspan="{{ count($basicas) + 3 }}"><i class="bi {{ $area->icono }} me-1" aria-hidden="true"></i>{{ $area->nombre }}</td></tr>

                            @foreach ($area->modulosVisibles as $modulo)
                                @php
                                    $tiene = ($actuales[$modulo->id] ?? collect())->keyBy('accion');
                                    $alcanceActual = $tiene->isEmpty()
                                        ? \App\Services\Permisos\Alcance::Sede
                                        : $tiene->map(fn ($p) => $p->alcance)->reduce(fn ($a, $b) => $a ? \App\Services\Permisos\Alcance::mayor($a, $b) : $b);
                                    $disponibles = $modulo->acciones->pluck('clave');
                                    $extras = $disponibles->diff($basicas);
                                    // Lo que el actor puede tocar: el Super Administrador todo; los demás, solo lo que tienen
                                    $puedeTocar = fn (string $accion) => $editable && ($propios === null || isset($propios[$modulo->clave.'.'.$accion]));
                                    $nombreCampo = 'permisos['.$modulo->clave.'][acciones][]';
                                @endphp
                                <tr class="{{ $modulo->padre_id ? 'submodulo' : '' }}" data-fila-permisos>
                                    <td class="modulo">
                                        @if ($modulo->padre_id)<i class="bi bi-arrow-return-right text-muted" aria-hidden="true"></i>@endif
                                        <i class="bi {{ $modulo->icono }} text-{{ $modulo->color_icono ?? 'primary' }}" aria-hidden="true"></i>{{ $modulo->nombre }}
                                    </td>
                                    @foreach ($basicas as $accion)
                                        <td class="celda-casilla">
                                            @if ($disponibles->contains($accion))
                                                <input type="checkbox" class="chk-permiso" name="{{ $nombreCampo }}" value="{{ $accion }}"
                                                       data-accion-permiso="{{ $accion }}"
                                                       aria-label="{{ $acciones[$accion] }} · {{ $modulo->nombre }}"
                                                       @checked($tiene->has($accion)) @disabled(! $puedeTocar($accion))>
                                                {{-- Una casilla bloqueada que ya estaba marcada se conserva --}}
                                                @if (! $puedeTocar($accion) && $tiene->has($accion))
                                                    <input type="hidden" name="{{ $nombreCampo }}" value="{{ $accion }}">
                                                @endif
                                            @else
                                                <span class="text-muted" aria-hidden="true">—</span>
                                            @endif
                                        </td>
                                    @endforeach
                                    <td>
                                        <div class="otras-acciones">
                                            @forelse ($extras as $accion)
                                                <label>
                                                    <input type="checkbox" class="chk-permiso" name="{{ $nombreCampo }}" value="{{ $accion }}"
                                                           data-accion-permiso="{{ $accion }}"
                                                           @checked($tiene->has($accion)) @disabled(! $puedeTocar($accion))>
                                                    {{ $acciones[$accion] ?? $accion }}
                                                </label>
                                                @if (! $puedeTocar($accion) && $tiene->has($accion))
                                                    <input type="hidden" name="{{ $nombreCampo }}" value="{{ $accion }}">
                                                @endif
                                            @empty
                                                <span class="text-muted" aria-hidden="true">—</span>
                                            @endforelse
                                        </div>
                                    </td>
                                    <td>
                                        <select name="permisos[{{ $modulo->clave }}][alcance]" class="select-alcance" aria-label="Alcance · {{ $modulo->nombre }}" @disabled(! $editable)>
                                            @foreach ($alcances as $alcance)
                                                <option value="{{ $alcance->value }}" @selected($alcance === $alcanceActual)>{{ $alcance->etiqueta() }}</option>
                                            @endforeach
                                        </select>
                                        @unless ($editable)
                                            <input type="hidden" name="permisos[{{ $modulo->clave }}][alcance]" value="{{ $alcanceActual->value }}">
                                        @endunless
                                    </td>
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($editable)
                <button type="submit" class="btn-guardar-permisos"><i class="bi bi-save2-fill me-2" aria-hidden="true"></i>Guardar Permisos de este Rol</button>
            @endif
        </form>
    @endif
@endsection
