{{--
    Dónde va el rol en la jerarquía (ADR-0016): «Debajo de / Arriba de» otro
    rol; la plataforma calcula el número (a la mitad entre los vecinos). El
    número directo queda en «Avanzado». $prefijo ('nuevo' | 'editar'), $esNuevo.
    plataforma.js (bloque «Mejoras estructura») muestra en vivo en qué nivel quedará.
--}}
@php
    $reabrir = $esNuevo ? $dialogo === 'crear' : ($editando ?? null) !== null;
    $referencias = $roles->filter(fn ($r) => $esSuper || $r->nivel_jerarquia >= $nivelPropio)->values();
    $datosNiveles = $roles->map(fn ($r) => ['id' => $r->id, 'nombre' => $r->nombre, 'nivel' => (int) $r->nivel_jerarquia])->values();
@endphp
<fieldset class="posicion-rol" data-posicion-rol data-niveles="{{ $datosNiveles->toJson() }}" data-nivel-propio="{{ $esSuper ? 0 : $nivelPropio }}"
          data-excluir="{{ $esNuevo ? '' : ($editando->id ?? '') }}">
    <legend class="campo-etiqueta">¿Dónde va en la jerarquía?</legend>
    <div class="campo-grupo mb-1">
        <div style="flex: 1">
            <label class="visually-hidden" for="{{ $prefijo }}_posicion_relacion">Relación</label>
            <select id="{{ $prefijo }}_posicion_relacion" name="posicion_relacion" class="campo mb-0" data-posicion-relacion>
                <option value="debajo" @selected(! $reabrir || old('posicion_relacion') !== 'arriba')>Debajo de</option>
                <option value="arriba" @selected($reabrir && old('posicion_relacion') === 'arriba')>Arriba de</option>
            </select>
        </div>
        <div style="flex: 2">
            <label class="visually-hidden" for="{{ $prefijo }}_posicion_rol">Rol de referencia</label>
            <select id="{{ $prefijo }}_posicion_rol" name="posicion_rol" class="campo mb-0" data-posicion-referencia>
                <option value="">{{ $esNuevo ? '— Elige un rol —' : 'Dejarlo donde está' }}</option>
                @foreach ($referencias as $r)
                    <option value="{{ $r->id }}" @selected($reabrir && (string) old('posicion_rol') === (string) $r->id)>{{ $r->nombre }}</option>
                @endforeach
            </select>
        </div>
    </div>
    <p class="campo-ayuda mt-1" data-posicion-resultado aria-live="polite">
        @if ($esSuper)
            Arriba = más autoridad. Elige un rol y te diremos en qué lugar queda.
        @else
            Solo puedes crear roles debajo del tuyo («{{ auth()->user()->nombreRolPrincipal() }}»). Elige un rol y te diremos en qué lugar queda.
        @endif
    </p>

    <details class="avanzado-nivel" @if ($reabrir && old('nivel_jerarquia') && ! old('posicion_rol')) open @endif>
        <summary>Avanzado: escribir el número de nivel</summary>
        <label class="campo-etiqueta mt-2" for="{{ $prefijo }}_nivel">Nivel Jerárquico</label>
        <input type="number" id="{{ $prefijo }}_nivel" name="nivel_jerarquia" class="campo" min="{{ $nivelMinimo }}" max="999" step="1"
               value="{{ $reabrir ? old('nivel_jerarquia') : '' }}" @unless ($esNuevo) data-campo="nivel" @endunless
               data-mensaje-min="{{ $mensajeMinimo }}">
        <p class="campo-ayuda mt-0">
            Número menor = más autoridad. Niveles ya usados: {{ $nivelesUsados ? implode(', ', $nivelesUsados) : 'ninguno' }}.
            @unless ($esSuper) Tu nivel es {{ $nivelPropio }}: usa {{ $nivelMinimo }} o más. @endunless
            Si eliges una posición arriba, este número no se usa.
        </p>
    </details>
</fieldset>
