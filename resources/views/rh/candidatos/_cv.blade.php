{{--
    CV digital (captura rápida en celular o PC). Lo usan: alta de RR. HH., edición de la ficha y el kiosco.
    $c: Candidato|null · $id: prefijo de ids · $kiosco: bool (sin departamento ni notas)
    $privacidad: texto del aviso · $pidePrivacidad: bool
    $departamentos, $puestos (solo RR. HH.)
--}}
@use('App\Models\Candidato')
@php
    $kiosco = $kiosco ?? false;
    $conOld = $conOld ?? false;
    $valor = fn (string $campo, $defecto = null) => $conOld ? old($campo, $defecto) : $defecto;
    $filas = fn (string $lista) => array_values((array) ($conOld && is_array(old($lista)) ? old($lista) : ($c?->{$lista} ?? [])));
    $escolaridad = $filas('escolaridad') ?: [['nivel' => '']];
    $experiencia = $filas('experiencia') ?: [[]];
    $referencias = $filas('referencias') ?: [[]];
@endphp

<fieldset class="bloque-cv">
    <legend><i class="bi bi-person-vcard me-2" aria-hidden="true"></i>Datos de contacto</legend>
    <label class="campo-etiqueta" for="{{ $id }}_nombre">Nombre completo *</label>
    <input type="text" id="{{ $id }}_nombre" name="nombre_completo" class="campo" maxlength="150" required autocomplete="name"
           value="{{ $valor('nombre_completo', $c?->nombre_completo) }}" placeholder="Nombre y apellidos">
    <div class="rejilla-cv">
        <div>
            <label class="campo-etiqueta" for="{{ $id }}_telefono">Teléfono (celular)</label>
            <input type="tel" id="{{ $id }}_telefono" name="telefono" class="campo" maxlength="20" inputmode="numeric" autocomplete="tel"
                   value="{{ $valor('telefono', $c?->telefono) }}" placeholder="10 dígitos">
        </div>
        <div>
            <label class="campo-etiqueta" for="{{ $id }}_correo">Correo</label>
            <input type="email" id="{{ $id }}_correo" name="correo" class="campo" maxlength="150" autocomplete="email" inputmode="email"
                   value="{{ $valor('correo', $c?->correo) }}" placeholder="nombre@correo.com">
        </div>
        <div>
            <label class="campo-etiqueta" for="{{ $id }}_nacimiento">Fecha de nacimiento</label>
            <input type="date" id="{{ $id }}_nacimiento" name="fecha_nacimiento" class="campo" value="{{ $valor('fecha_nacimiento', $c?->fecha_nacimiento?->format('Y-m-d')) }}">
        </div>
        <div>
            <label class="campo-etiqueta" for="{{ $id }}_ciudad">Ciudad donde vives</label>
            <input type="text" id="{{ $id }}_ciudad" name="ciudad" class="campo" maxlength="120" autocomplete="address-level2"
                   value="{{ $valor('ciudad', $c?->ciudad) }}" placeholder="Ej. Cancún">
        </div>
    </div>
</fieldset>

<fieldset class="bloque-cv">
    <legend><i class="bi bi-briefcase me-2" aria-hidden="true"></i>Puesto al que aplica</legend>
    @if ($kiosco)
        <label class="campo-etiqueta" for="{{ $id }}_vacante">¿A qué puesto aplicas?</label>
        <input type="text" id="{{ $id }}_vacante" name="vacante" class="campo" maxlength="150" list="{{ $id }}_puestos"
               value="{{ $valor('vacante', $c?->puestoVisible()) }}" placeholder="Ej. Camarista, Cocinero, Recepcionista">
        <datalist id="{{ $id }}_puestos">@foreach ($puestos as $p)<option value="{{ $p }}"></option>@endforeach</datalist>
    @else
        <div class="rejilla-cv">
            <div>
                <label class="campo-etiqueta" for="{{ $id }}_departamento">Departamento</label>
                <select id="{{ $id }}_departamento" name="departamento_id" class="campo">
                    <option value="">-- Sin definir --</option>
                    @foreach ($departamentos as $d)
                        <option value="{{ $d->id }}" @selected((string) $valor('departamento_id', $c?->departamento_id) === (string) $d->id)>{{ $d->nombre }}</option>
                    @endforeach
                </select>
            </div>
            <div>
                <label class="campo-etiqueta" for="{{ $id }}_puesto">Puesto (catálogo)</label>
                <select id="{{ $id }}_puesto" name="puesto_id" class="campo">
                    <option value="">-- Sin definir --</option>
                    @foreach ($puestos as $p)
                        <option value="{{ $p->id }}" @selected((string) $valor('puesto_id', $c?->puesto_id) === (string) $p->id)>{{ $p->nombre }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <label class="campo-etiqueta" for="{{ $id }}_vacante">Vacante (si no está en el catálogo)</label>
        <input type="text" id="{{ $id }}_vacante" name="vacante" class="campo" maxlength="150" value="{{ $valor('vacante', $c?->vacante) }}" placeholder="Ej. Ayudante de cocina (temporada)">
    @endif
</fieldset>

<fieldset class="bloque-cv" data-filas-cv>
    <legend><i class="bi bi-mortarboard me-2" aria-hidden="true"></i>Escolaridad</legend>
    <div class="filas-cv" data-filas-cv-lista>
        @foreach ($escolaridad as $i => $f)
            @include('rh.candidatos._fila-escolaridad', ['i' => $i, 'f' => (array) $f])
        @endforeach
    </div>
    <template data-plantilla-fila>@include('rh.candidatos._fila-escolaridad', ['i' => '__i__', 'f' => []])</template>
    <button type="button" class="btn-agregar-fila-cv" data-agregar-fila-cv data-maximo="{{ \App\Services\Candidatos\AdministradorCandidatos::MAX_FILAS }}"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Agregar estudios</button>
</fieldset>

<fieldset class="bloque-cv" data-filas-cv>
    <legend><i class="bi bi-building me-2" aria-hidden="true"></i>Experiencia laboral</legend>
    <div class="filas-cv" data-filas-cv-lista>
        @foreach ($experiencia as $i => $f)
            @include('rh.candidatos._fila-experiencia', ['i' => $i, 'f' => (array) $f])
        @endforeach
    </div>
    <template data-plantilla-fila>@include('rh.candidatos._fila-experiencia', ['i' => '__i__', 'f' => []])</template>
    <button type="button" class="btn-agregar-fila-cv" data-agregar-fila-cv data-maximo="{{ \App\Services\Candidatos\AdministradorCandidatos::MAX_FILAS }}"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Agregar otro trabajo</button>
</fieldset>

<fieldset class="bloque-cv">
    <legend><i class="bi bi-stars me-2" aria-hidden="true"></i>Habilidades y disponibilidad</legend>
    <label class="campo-etiqueta" for="{{ $id }}_habilidades">Habilidades (lo que sabes hacer)</label>
    <textarea id="{{ $id }}_habilidades" name="habilidades" class="campo" rows="2" maxlength="1000" placeholder="Ej. Manejo de caja, atención a huéspedes, licencia de manejo">{{ $valor('habilidades', $c?->habilidades) }}</textarea>
    <label class="campo-etiqueta" for="{{ $id }}_idiomas">Idiomas</label>
    <input type="text" id="{{ $id }}_idiomas" name="idiomas" class="campo" maxlength="255" value="{{ $valor('idiomas', $c?->idiomas) }}" placeholder="Ej. Español, inglés básico">
    <span class="campo-etiqueta">¿Cuándo puedes empezar?</span>
    <div class="opciones-cv">
        @foreach (Candidato::DISPONIBILIDAD as $clave => $texto)
            <label class="opcion-cv"><input type="radio" name="disponibilidad" value="{{ $clave }}" @checked($valor('disponibilidad', $c?->disponibilidad) === $clave)><span>{{ $texto }}</span></label>
        @endforeach
    </div>
    <div class="rejilla-cv">
        <div>
            <label class="campo-etiqueta" for="{{ $id }}_disp_notas">Horario o días disponibles</label>
            <input type="text" id="{{ $id }}_disp_notas" name="disponibilidad_notas" class="campo" maxlength="255" value="{{ $valor('disponibilidad_notas', $c?->disponibilidad_notas) }}" placeholder="Ej. Rolar turnos, fines de semana">
        </div>
        <div>
            <label class="campo-etiqueta" for="{{ $id }}_pretension">Sueldo que esperas al mes (opcional)</label>
            <input type="text" id="{{ $id }}_pretension" name="pretension" class="campo" maxlength="12" inputmode="decimal" value="{{ $valor('pretension', $c?->pretension !== null ? (string) (float) $c->pretension : null) }}" placeholder="Ej. 9000">
        </div>
    </div>
</fieldset>

<fieldset class="bloque-cv" data-filas-cv>
    <legend><i class="bi bi-telephone me-2" aria-hidden="true"></i>Referencias</legend>
    <div class="filas-cv" data-filas-cv-lista>
        @foreach ($referencias as $i => $f)
            @include('rh.candidatos._fila-referencia', ['i' => $i, 'f' => (array) $f])
        @endforeach
    </div>
    <template data-plantilla-fila>@include('rh.candidatos._fila-referencia', ['i' => '__i__', 'f' => []])</template>
    <button type="button" class="btn-agregar-fila-cv" data-agregar-fila-cv data-maximo="{{ \App\Services\Candidatos\AdministradorCandidatos::MAX_FILAS }}"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Agregar referencia</button>
</fieldset>

@if ($pidePrivacidad)
    <div class="aviso-privacidad-cv">
        <details>
            <summary><i class="bi bi-shield-lock me-1" aria-hidden="true"></i>Leer el aviso de privacidad</summary>
            <div class="texto-privacidad">{{ $privacidad }}</div>
        </details>
        <label class="casilla-privacidad">
            <input type="checkbox" name="acepta_privacidad" value="1" required @checked($conOld && old('acepta_privacidad'))>
            <span><strong>Acepto el aviso de privacidad</strong> y autorizo el uso de mis datos para este proceso de selección.</span>
        </label>
    </div>
@endif
