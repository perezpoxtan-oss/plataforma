{{--
    Datos generales de una empresa externa (alta, edición y alta rápida).
    Parámetros: $prefijo (ids únicos), $reabrir (tras un error, usar old()),
    $duplicado (bool, opcional: aviso en vivo «ya existe / se parece», Ronda 6), $valores (array opcional).
--}}
@php
    $valores ??= [];
    $valor = fn (string $campo, $porDefecto = '') => $reabrir ? old($campo, $porDefecto) : ($valores[$campo] ?? $porDefecto);
    $categoriaActual = $valor('categoria', 'proveedor');
@endphp
<label class="campo-etiqueta" for="{{ $prefijo }}_nombre">Razón Social o Nombre Comercial</label>
<input type="text" id="{{ $prefijo }}_nombre" name="nombre" class="campo" maxlength="150" placeholder="Ej: GRUPO BIMBO S.A. DE C.V."
       value="{{ $valor('nombre') }}" @if (! empty($duplicado)) data-duplicado="{{ route('proveedores.duplicado') }}" data-duplicado-min="3" @endif required>

<label class="campo-etiqueta" for="{{ $prefijo }}_categoria">Categoría de Proveedor</label>
<select id="{{ $prefijo }}_categoria" name="categoria" class="campo" required>
    @foreach (\App\Http\Controllers\Padrones\ProveedorController::ESTILOS as $clave => $estilo)
        <option value="{{ $clave }}" @selected($categoriaActual === $clave) @if ($clave === 'proveedor') data-por-defecto @endif>{{ $estilo[4] }}</option>
    @endforeach
</select>

<div class="campo-grupo">
    <div class="flex-fill">
        <label class="campo-etiqueta" for="{{ $prefijo }}_rfc">RFC <span class="text-lowercase fw-normal">(opcional)</span></label>
        <input type="text" id="{{ $prefijo }}_rfc" name="rfc" class="campo campo-mayusculas" maxlength="15" placeholder="AAA010101AAA"
               autocapitalize="characters" spellcheck="false" value="{{ $valor('rfc') }}">
    </div>
    <div class="flex-fill">
        <label class="campo-etiqueta" for="{{ $prefijo }}_telefono">Teléfono <span class="text-lowercase fw-normal">(opcional)</span></label>
        <input type="tel" inputmode="tel" id="{{ $prefijo }}_telefono" name="telefono" class="campo" maxlength="20" placeholder="+52 998 123 4567" value="{{ $valor('telefono') }}">
    </div>
</div>
<p class="campo-ayuda mb-3">RFC de 12 caracteres (persona moral) o 13 (persona física). Teléfono: solo números, con "+" y código de país si es extranjero.</p>

<label class="campo-etiqueta" for="{{ $prefijo }}_direccion">Dirección <span class="text-lowercase fw-normal">(opcional — se guarda para reutilizarla después)</span></label>
<input type="text" id="{{ $prefijo }}_direccion" name="direccion" class="campo" maxlength="255" value="{{ $valor('direccion') }}">
