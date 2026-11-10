{{--
    Configuración → General → «Nombres en el menú» (Avanzado, criterio 7):
    cómo se llama cada módulo en el menú de la empresa
    (empresa_modulos.nombre_visible; vacío = el de siempre). La bitácora tiene
    su propio ajuste en su sección. $empresa, $puedeEditar
--}}
@php
    $nombresMenu = \App\Support\NombresMenu::de($empresa);
    $hayPropios = collect($nombresMenu)->flatten(1)->contains(fn ($m) => $m['propio'] !== null);
@endphp
<div class="tarjeta p-4" id="cfg-menu">
    <h3 class="h6 fw-bold"><i class="bi bi-list-ul me-2 text-primary" aria-hidden="true"></i>Nombres en el menú</h3>
    <p class="small text-muted mb-2">Si en tu empresa a un módulo le dicen distinto (por ejemplo «Bitácora de transporte» → «Taxis»), escribe aquí cómo quieres verlo en el menú. Solo cambia el nombre en el menú; la pantalla, los permisos y las direcciones no cambian. <span class="valor-original">Valor original: el nombre de siempre (campo vacío).</span></p>
    <details @if ($hayPropios || $errors->has('nombres.*')) open @endif>
        <summary class="small fw-bold">Avanzado: cambiar nombres del menú{{ $hayPropios ? ' (tu empresa cambió algunos)' : '' }}</summary>
        <form action="{{ route('configuracion.menu') }}" method="POST" class="mt-2">
            @csrf
            @method('PUT')
            @foreach ($nombresMenu as $menu => $modulos)
                <p class="campo-etiqueta mt-2 mb-1">{{ $menu }}</p>
                @foreach ($modulos as $m)
                    <div class="fila-nombre-menu">
                        <label class="small" for="menu_{{ $m['id'] }}">{{ $m['original'] }}</label>
                        <input type="text" id="menu_{{ $m['id'] }}" name="nombres[{{ $m['id'] }}]" class="campo mb-0" maxlength="{{ \App\Support\NombresMenu::MAXIMO }}"
                               value="{{ old('nombres.'.$m['id'], $m['propio']) }}" placeholder="{{ $m['original'] }}" @disabled(! $puedeEditar)>
                    </div>
                    @error('nombres.'.$m['id'])<p class="text-danger small mb-1">{{ $message }}</p>@enderror
                @endforeach
            @endforeach
            @if ($puedeEditar)
                <button type="submit" class="btn-azul mt-3" style="min-height:44px;border-radius:10px;padding:0 1.25rem;">Guardar</button>
            @endif
        </form>
    </details>
    @if ($puedeEditar && $hayPropios)
        @include('administracion.configuracion._restablecer', ['ajuste' => 'menu', 'que' => 'los nombres del menú'])
    @endif
</div>
