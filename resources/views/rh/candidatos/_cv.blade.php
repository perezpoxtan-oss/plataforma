{{--
    Solicitud de empleo (formato general de México), en secciones plegables para
    capturar rápido en celular o PC. La usan: alta de RR. HH., edición de la
    ficha, el kiosco y la bolsa de trabajo (internet).

    $c: Candidato|null · $id: prefijo de ids · $kiosco: bool (lo llena el propio candidato: sin departamento ni notas)
    $privacidad: texto del aviso · $pidePrivacidad: bool
    $pideFirma: bool (declaración y firma; obligatoria si $kiosco) · $sinPuesto: bool (la bolsa de trabajo ya sabe la vacante)
    $departamentos, $puestos (RR. HH.: modelos; kiosco: nombres)
    No pide datos de salud, religión, política ni similares (decisión legal y ética).
--}}
@use('App\Models\Candidato')
@use('App\Models\Colaborador')
@php
    $kiosco = $kiosco ?? false;
    $conOld = $conOld ?? false;
    $pideFirma = $pideFirma ?? $kiosco;
    $sinPuesto = $sinPuesto ?? false;
    $valor = fn (string $campo, $defecto = null) => $conOld ? old($campo, $defecto) : $defecto;
    $filas = fn (string $lista) => array_values((array) ($conOld && is_array(old($lista)) ? old($lista) : ($c?->{$lista} ?? [])));
    $escolaridad = $filas('escolaridad') ?: [['nivel' => '']];
    $experiencia = $filas('experiencia') ?: [[]];
    $referencias = $filas('referencias') ?: ($kiosco ? [[], []] : [[]]);
    $laborales = $filas('referencias_laborales') ?: [[]];
    $partes = $c?->partesNombre() ?? ['nombre' => '', 'paterno' => '', 'materno' => ''];
    $maximo = \App\Services\Candidatos\AdministradorCandidatos::MAX_FILAS;
    // Sí / No (null = sin contestar)
    $siNo = function (string $campo) use ($valor, $c) {
        $actual = $valor($campo, $c?->{$campo} === null ? null : ($c->{$campo} ? '1' : '0'));

        return $actual === null || $actual === '' ? null : (string) $actual;
    };
    $tu = $kiosco ? 'tu' : 'su';
@endphp

<x-seccion clave="cv-1" :abierta="true" class="bloque-cv">
    <x-slot:titulo><i class="bi bi-person-vcard me-2" aria-hidden="true"></i>Datos personales</x-slot:titulo>
    <div class="rejilla-cv">
        <div>
            <label class="campo-etiqueta" for="{{ $id }}_nombre">Nombre(s) *</label>
            <input type="text" id="{{ $id }}_nombre" name="nombre" class="campo" maxlength="60" required autocomplete="given-name"
                   value="{{ $valor('nombre', $partes['nombre']) }}" placeholder="Ej. Ana María">
        </div>
        <div>
            <label class="campo-etiqueta" for="{{ $id }}_paterno">Apellido paterno *</label>
            <input type="text" id="{{ $id }}_paterno" name="apellido_paterno" class="campo" maxlength="60" required autocomplete="family-name"
                   value="{{ $valor('apellido_paterno', $partes['paterno']) }}" placeholder="Ej. Pool">
        </div>
        <div>
            <label class="campo-etiqueta" for="{{ $id }}_materno">Apellido materno</label>
            <input type="text" id="{{ $id }}_materno" name="apellido_materno" class="campo" maxlength="60" autocomplete="additional-name"
                   value="{{ $valor('apellido_materno', $partes['materno']) }}" placeholder="Ej. Canché">
        </div>
        <div>
            <label class="campo-etiqueta" for="{{ $id }}_telefono">Teléfono celular{{ $kiosco ? ' *' : '' }}</label>
            <input type="tel" id="{{ $id }}_telefono" name="telefono" class="campo" maxlength="20" inputmode="numeric" autocomplete="tel" @if ($kiosco) required @endif
                   value="{{ $valor('telefono', $c?->telefono) }}" placeholder="10 dígitos">
        </div>
        <div>
            <label class="campo-etiqueta" for="{{ $id }}_correo">Correo</label>
            <input type="email" id="{{ $id }}_correo" name="correo" class="campo" maxlength="150" autocomplete="email" inputmode="email"
                   value="{{ $valor('correo', $c?->correo) }}" placeholder="nombre@correo.com">
        </div>
        <div>
            <label class="campo-etiqueta" for="{{ $id }}_nacimiento">Fecha de nacimiento</label>
            <input type="date" id="{{ $id }}_nacimiento" name="fecha_nacimiento" class="campo" autocomplete="bday" value="{{ $valor('fecha_nacimiento', $c?->fecha_nacimiento?->format('Y-m-d')) }}">
        </div>
        <div>
            <label class="campo-etiqueta" for="{{ $id }}_lugar">Lugar de nacimiento (estado)</label>
            <select id="{{ $id }}_lugar" name="lugar_nacimiento" class="campo">
                <option value="">-- Elegir --</option>
                @foreach (Colaborador::ESTADOS_NACIMIENTO as $estado)
                    <option value="{{ $estado }}" @selected($valor('lugar_nacimiento', $c?->lugar_nacimiento) === $estado)>{{ $estado }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="campo-etiqueta" for="{{ $id }}_nacionalidad">Nacionalidad</label>
            <input type="text" id="{{ $id }}_nacionalidad" name="nacionalidad" class="campo" maxlength="40" value="{{ $valor('nacionalidad', $c?->nacionalidad ?? ($c === null || $kiosco ? 'Mexicana' : null)) }}" placeholder="Mexicana">
        </div>
        <div>
            <label class="campo-etiqueta" for="{{ $id }}_sexo">Sexo</label>
            <select id="{{ $id }}_sexo" name="sexo" class="campo">
                <option value="">-- Elegir --</option>
                @foreach (Candidato::SEXOS as $clave => $texto)
                    <option value="{{ $clave }}" @selected($valor('sexo', $c?->sexo) === $clave)>{{ $texto }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="campo-etiqueta" for="{{ $id }}_civil">Estado civil</label>
            <select id="{{ $id }}_civil" name="estado_civil" class="campo">
                <option value="">-- Elegir --</option>
                @foreach (Candidato::ESTADOS_CIVILES as $clave => $texto)
                    <option value="{{ $clave }}" @selected($valor('estado_civil', $c?->estado_civil) === $clave)>{{ $texto }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="campo-etiqueta" for="{{ $id }}_dependientes">Dependientes económicos</label>
            <input type="number" id="{{ $id }}_dependientes" name="dependientes" class="campo" min="0" max="20" inputmode="numeric"
                   value="{{ $valor('dependientes', $c?->dependientes) }}" placeholder="¿Cuántas personas dependen de {{ $kiosco ? 'ti' : 'él/ella' }}?">
        </div>
    </div>
</x-seccion>

<x-seccion clave="cv-oficiales" :abierta="$kiosco" class="bloque-cv">
    <x-slot:titulo><i class="bi bi-card-text me-2" aria-hidden="true"></i>Documentos oficiales</x-slot:titulo>
    <p class="campo-ayuda mt-0"><i class="bi bi-lock" aria-hidden="true"></i> Solo los ve Recursos Humanos. {{ $kiosco ? 'Si no los tienes a la mano, déjalos en blanco.' : '' }}</p>
    <div class="rejilla-cv">
        <div>
            <label class="campo-etiqueta" for="{{ $id }}_curp">CURP</label>
            <input type="text" id="{{ $id }}_curp" name="curp" class="campo campo-mayusculas" maxlength="18" autocapitalize="characters" spellcheck="false" autocomplete="off"
                   value="{{ $valor('curp', $c?->curp) }}" placeholder="18 letras y números">
        </div>
        <div>
            <label class="campo-etiqueta" for="{{ $id }}_rfc">RFC</label>
            <input type="text" id="{{ $id }}_rfc" name="rfc" class="campo campo-mayusculas" maxlength="13" autocapitalize="characters" spellcheck="false" autocomplete="off"
                   value="{{ $valor('rfc', $c?->rfc) }}" placeholder="12 o 13 letras y números">
        </div>
        <div>
            <label class="campo-etiqueta" for="{{ $id }}_nss">Número de Seguro Social (NSS)</label>
            <input type="text" id="{{ $id }}_nss" name="nss" class="campo" maxlength="11" inputmode="numeric" autocomplete="off"
                   value="{{ $valor('nss', $c?->nss) }}" placeholder="11 números">
        </div>
        <div>
            <label class="campo-etiqueta" for="{{ $id }}_licencia">Licencia de manejo</label>
            <select id="{{ $id }}_licencia" name="licencia_tipo" class="campo">
                <option value="">No tengo / no aplica</option>
                @foreach (Candidato::LICENCIAS as $clave => $texto)
                    <option value="{{ $clave }}" @selected($valor('licencia_tipo', $c?->licencia_tipo) === $clave)>{{ $texto }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="campo-etiqueta" for="{{ $id }}_licencia_vig">Vigencia de la licencia</label>
            <input type="date" id="{{ $id }}_licencia_vig" name="licencia_vigencia" class="campo" value="{{ $valor('licencia_vigencia', $c?->licencia_vigencia?->format('Y-m-d')) }}">
        </div>
    </div>
</x-seccion>

<x-seccion clave="cv-domicilio" :abierta="$kiosco" class="bloque-cv">
    <x-slot:titulo><i class="bi bi-house-door me-2" aria-hidden="true"></i>Domicilio y contacto de emergencia</x-slot:titulo>
    <label class="campo-etiqueta" for="{{ $id }}_calle">Calle y número</label>
    <input type="text" id="{{ $id }}_calle" name="calle_numero" class="campo" maxlength="150" autocomplete="address-line1"
           value="{{ $valor('calle_numero', $c?->calle_numero) }}" placeholder="Ej. Calle 12 Mz 3 Lt 5">
    <div class="rejilla-cv">
        <div>
            <label class="campo-etiqueta" for="{{ $id }}_colonia">Colonia</label>
            <input type="text" id="{{ $id }}_colonia" name="colonia" class="campo" maxlength="100" value="{{ $valor('colonia', $c?->colonia) }}" placeholder="Ej. Región 100">
        </div>
        <div>
            <label class="campo-etiqueta" for="{{ $id }}_cp">Código postal</label>
            <input type="text" id="{{ $id }}_cp" name="codigo_postal" class="campo" maxlength="5" inputmode="numeric" autocomplete="postal-code"
                   value="{{ $valor('codigo_postal', $c?->codigo_postal) }}" placeholder="5 números">
        </div>
        <div>
            <label class="campo-etiqueta" for="{{ $id }}_municipio">Municipio o alcaldía</label>
            <input type="text" id="{{ $id }}_municipio" name="municipio" class="campo" maxlength="100" autocomplete="address-level2"
                   value="{{ $valor('municipio', $c?->municipio ?? $c?->ciudad) }}" placeholder="Ej. Benito Juárez">
        </div>
        <div>
            <label class="campo-etiqueta" for="{{ $id }}_estado">Estado</label>
            <select id="{{ $id }}_estado" name="estado_domicilio" class="campo" autocomplete="address-level1">
                <option value="">-- Elegir --</option>
                @foreach (array_diff(Colaborador::ESTADOS_NACIMIENTO, ['Extranjero']) as $estado)
                    <option value="{{ $estado }}" @selected($valor('estado_domicilio', $c?->estado_domicilio) === $estado)>{{ $estado }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="campo-etiqueta" for="{{ $id }}_residencia">Tiempo viviendo ahí</label>
            <input type="text" id="{{ $id }}_residencia" name="tiempo_residencia" class="campo" maxlength="40" value="{{ $valor('tiempo_residencia', $c?->tiempo_residencia) }}" placeholder="Ej. 3 años">
        </div>
        <div>
            <label class="campo-etiqueta" for="{{ $id }}_fijo">Teléfono fijo (opcional)</label>
            <input type="tel" id="{{ $id }}_fijo" name="telefono_fijo" class="campo" maxlength="15" inputmode="numeric" value="{{ $valor('telefono_fijo', $c?->telefono_fijo) }}" placeholder="10 dígitos">
        </div>
    </div>
    <h3 class="subtitulo-solicitud"><i class="bi bi-telephone-plus me-1" aria-hidden="true"></i>En caso de emergencia avisar a</h3>
    <div class="rejilla-cv">
        <div>
            <label class="campo-etiqueta" for="{{ $id }}_emer_nombre">Nombre</label>
            <input type="text" id="{{ $id }}_emer_nombre" name="emergencia_nombre" class="campo" maxlength="150" value="{{ $valor('emergencia_nombre', $c?->emergencia_nombre) }}" placeholder="Nombre de la persona">
        </div>
        <div>
            <label class="campo-etiqueta" for="{{ $id }}_emer_parentesco">Parentesco</label>
            <input type="text" id="{{ $id }}_emer_parentesco" name="emergencia_parentesco" class="campo" maxlength="40" value="{{ $valor('emergencia_parentesco', $c?->emergencia_parentesco) }}" placeholder="Ej. Mamá, esposo">
        </div>
        <div>
            <label class="campo-etiqueta" for="{{ $id }}_emer_tel">Teléfono</label>
            <input type="tel" id="{{ $id }}_emer_tel" name="emergencia_telefono" class="campo" maxlength="20" inputmode="numeric" value="{{ $valor('emergencia_telefono', $c?->emergencia_telefono) }}" placeholder="10 dígitos">
        </div>
    </div>
</x-seccion>

@unless ($sinPuesto)
<x-seccion clave="cv-2" :abierta="$kiosco" class="bloque-cv">
    <x-slot:titulo><i class="bi bi-briefcase me-2" aria-hidden="true"></i>Puesto al que aplica</x-slot:titulo>
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
        {{-- Vacantes (bolsa de trabajo) --}}
        @isset($vacantesElegibles)
            <label class="campo-etiqueta" for="{{ $id }}_vacante_id">Vacante publicada</label>
            <select id="{{ $id }}_vacante_id" name="vacante_id" class="campo">
                <option value="">-- Ninguna --</option>
                @foreach ($vacantesElegibles as $v)
                    <option value="{{ $v->id }}" @selected((string) $valor('vacante_id', $c?->vacante_id) === (string) $v->id)>{{ $v->titulo }}{{ $v->estado !== 'publicada' ? ' ('.\App\Models\Vacante::ESTADOS[$v->estado].')' : '' }}</option>
                @endforeach
            </select>
        @endisset
        {{-- Fin Vacantes --}}
    @endif
</x-seccion>
@endunless

<x-seccion clave="cv-3" :abierta="$kiosco" class="bloque-cv" data-filas-cv>
    <x-slot:titulo><i class="bi bi-mortarboard me-2" aria-hidden="true"></i>Escolaridad</x-slot:titulo>
    <div class="filas-cv" data-filas-cv-lista>
        @foreach ($escolaridad as $i => $f)
            @include('rh.candidatos._fila-escolaridad', ['i' => $i, 'f' => (array) $f])
        @endforeach
    </div>
    <template data-plantilla-fila>@include('rh.candidatos._fila-escolaridad', ['i' => '__i__', 'f' => []])</template>
    <button type="button" class="btn-agregar-fila-cv" data-agregar-fila-cv data-maximo="{{ $maximo }}"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Agregar estudios</button>
</x-seccion>

<x-seccion clave="cv-4" :abierta="$kiosco" class="bloque-cv" data-filas-cv>
    <x-slot:titulo><i class="bi bi-building me-2" aria-hidden="true"></i>Empleos anteriores</x-slot:titulo>
    <p class="campo-ayuda mt-0">Empieza por el más reciente. Si nunca has trabajado, déjalo en blanco.</p>
    <div class="filas-cv" data-filas-cv-lista>
        @foreach ($experiencia as $i => $f)
            @include('rh.candidatos._fila-experiencia', ['i' => $i, 'f' => (array) $f])
        @endforeach
    </div>
    <template data-plantilla-fila>@include('rh.candidatos._fila-experiencia', ['i' => '__i__', 'f' => []])</template>
    <button type="button" class="btn-agregar-fila-cv" data-agregar-fila-cv data-maximo="{{ $maximo }}"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Agregar otro empleo</button>
</x-seccion>

<x-seccion clave="cv-6" :abierta="$kiosco" class="bloque-cv">
    <x-slot:titulo><i class="bi bi-telephone me-2" aria-hidden="true"></i>Referencias</x-slot:titulo>
    <div data-filas-cv>
        <h3 class="subtitulo-solicitud"><i class="bi bi-people me-1" aria-hidden="true"></i>Personales{{ $kiosco ? ' (mínimo 2) *' : '' }}</h3>
        <p class="campo-ayuda mt-0">Personas que {{ $kiosco ? 'te' : 'lo' }} conozcan y no sean familiares.</p>
        <div class="filas-cv" data-filas-cv-lista>
            @foreach ($referencias as $i => $f)
                @include('rh.candidatos._fila-referencia', ['i' => $i, 'f' => (array) $f, 'lista' => 'referencias'])
            @endforeach
        </div>
        <template data-plantilla-fila>@include('rh.candidatos._fila-referencia', ['i' => '__i__', 'f' => [], 'lista' => 'referencias'])</template>
        <button type="button" class="btn-agregar-fila-cv" data-agregar-fila-cv data-maximo="{{ $maximo }}"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Agregar referencia personal</button>
    </div>
    <div data-filas-cv class="mt-3">
        <h3 class="subtitulo-solicitud"><i class="bi bi-briefcase me-1" aria-hidden="true"></i>Laborales</h3>
        <p class="campo-ayuda mt-0">Jefes o compañeros de trabajos anteriores.</p>
        <div class="filas-cv" data-filas-cv-lista>
            @foreach ($laborales as $i => $f)
                @include('rh.candidatos._fila-referencia', ['i' => $i, 'f' => (array) $f, 'lista' => 'referencias_laborales'])
            @endforeach
        </div>
        <template data-plantilla-fila>@include('rh.candidatos._fila-referencia', ['i' => '__i__', 'f' => [], 'lista' => 'referencias_laborales'])</template>
        <button type="button" class="btn-agregar-fila-cv" data-agregar-fila-cv data-maximo="{{ $maximo }}"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Agregar referencia laboral</button>
    </div>
</x-seccion>

<x-seccion clave="cv-5" :abierta="$kiosco" class="bloque-cv">
    <x-slot:titulo><i class="bi bi-stars me-2" aria-hidden="true"></i>Datos generales</x-slot:titulo>
    <label class="campo-etiqueta" for="{{ $id }}_medio">¿Cómo {{ $kiosco ? 'te enteraste' : 'se enteró' }} de la vacante?</label>
    <select id="{{ $id }}_medio" name="medio_vacante" class="campo">
        <option value="">-- Elegir --</option>
        @foreach (Candidato::MEDIOS_VACANTE as $clave => $texto)
            <option value="{{ $clave }}" @selected($valor('medio_vacante', $c?->medio_vacante ?? ($medioPorDefecto ?? null)) === $clave)>{{ $texto }}</option>
        @endforeach
    </select>

    @foreach ([
        'tiene_familiares' => '¿'.($kiosco ? 'Tienes' : 'Tiene').' familiares trabajando en la empresa?',
        'trabajo_antes_aqui' => '¿'.($kiosco ? 'Has' : 'Ha').' trabajado antes aquí?',
        'rolar_turnos' => '¿'.($kiosco ? 'Puedes' : 'Puede').' rolar turnos?',
        'puede_viajar' => '¿Disponibilidad para viajar?',
        'cambiar_residencia' => '¿Disponibilidad para cambiar de residencia?',
    ] as $campo => $pregunta)
        <fieldset class="pregunta-si-no">
            <legend class="campo-etiqueta">{{ $pregunta }}</legend>
            <div class="opciones-cv">
                <label class="opcion-cv"><input type="radio" name="{{ $campo }}" value="1" @checked($siNo($campo) === '1')><span>Sí</span></label>
                <label class="opcion-cv"><input type="radio" name="{{ $campo }}" value="0" @checked($siNo($campo) === '0')><span>No</span></label>
            </div>
        </fieldset>
        @if ($campo === 'tiene_familiares')
            <div data-mostrar-si='{"tiene_familiares":["1"]}'>
                <label class="campo-etiqueta" for="{{ $id }}_familiares">¿Quién? (nombre y área)</label>
                <input type="text" id="{{ $id }}_familiares" name="familiares_nombre" class="campo" maxlength="150" value="{{ $valor('familiares_nombre', $c?->familiares_nombre) }}" placeholder="Ej. Juan Pool, Mantenimiento">
            </div>
        @endif
    @endforeach

    <span class="campo-etiqueta">¿Cuándo {{ $kiosco ? 'puedes' : 'puede' }} empezar?</span>
    <div class="opciones-cv">
        @foreach (Candidato::DISPONIBILIDAD as $clave => $texto)
            <label class="opcion-cv"><input type="radio" name="disponibilidad" value="{{ $clave }}" @checked($valor('disponibilidad', $c?->disponibilidad) === $clave)><span>{{ $texto }}</span></label>
        @endforeach
    </div>
    <div class="rejilla-cv">
        <div>
            <label class="campo-etiqueta" for="{{ $id }}_inicio">Fecha en que {{ $kiosco ? 'puedes' : 'puede' }} empezar</label>
            <input type="date" id="{{ $id }}_inicio" name="fecha_inicio_posible" class="campo" value="{{ $valor('fecha_inicio_posible', $c?->fecha_inicio_posible?->format('Y-m-d')) }}">
        </div>
        <div>
            <label class="campo-etiqueta" for="{{ $id }}_pretension">Sueldo que {{ $kiosco ? 'esperas' : 'espera' }} al mes (opcional)</label>
            <input type="text" id="{{ $id }}_pretension" name="pretension" class="campo" maxlength="12" inputmode="decimal" value="{{ $valor('pretension', $c?->pretension !== null ? (string) (float) $c->pretension : null) }}" placeholder="Ej. 9000">
        </div>
        <div>
            <label class="campo-etiqueta" for="{{ $id }}_disp_notas">Horario o días disponibles</label>
            <input type="text" id="{{ $id }}_disp_notas" name="disponibilidad_notas" class="campo" maxlength="255" value="{{ $valor('disponibilidad_notas', $c?->disponibilidad_notas) }}" placeholder="Ej. Fines de semana, solo mañanas">
        </div>
        <div>
            <label class="campo-etiqueta" for="{{ $id }}_idiomas">Idiomas</label>
            <input type="text" id="{{ $id }}_idiomas" name="idiomas" class="campo" maxlength="255" value="{{ $valor('idiomas', $c?->idiomas) }}" placeholder="Ej. Español, inglés básico">
        </div>
    </div>
    <label class="campo-etiqueta" for="{{ $id }}_habilidades">Habilidades (lo que {{ $kiosco ? 'sabes' : 'sabe' }} hacer)</label>
    <textarea id="{{ $id }}_habilidades" name="habilidades" class="campo" rows="2" maxlength="1000" placeholder="Ej. Manejo de caja, atención a huéspedes">{{ $valor('habilidades', $c?->habilidades) }}</textarea>
</x-seccion>

@if ($pideFirma)
    <section class="bloque-firma-solicitud" aria-labelledby="{{ $id }}_t_firma">
        <h3 id="{{ $id }}_t_firma" class="subtitulo-solicitud"><i class="bi bi-pen me-1" aria-hidden="true"></i>Declaración y firma{{ $kiosco ? ' *' : '' }}</h3>
        @if (! $kiosco && $c?->firma_ruta)
            <p class="campo-ayuda mt-0"><i class="bi bi-check-circle-fill text-success" aria-hidden="true"></i> Ya firmó @fecha($c->firma_en). Si firma de nuevo, se reemplaza.</p>
        @elseif (! $kiosco)
            <p class="campo-ayuda mt-0">Opcional: si el candidato está aquí, puede firmar en esta pantalla (queda registrado que lo capturaste tú).</p>
        @endif
        <label class="casilla-privacidad">
            <input type="checkbox" name="declaracion" value="1" @if ($kiosco) required @endif @checked($conOld && old('declaracion'))>
            <span><strong>Declaro que la información es verdadera</strong> y autorizo que se verifiquen mis datos y referencias.</span>
        </label>
        @include('componentes.firma', ['id' => $id.'_firma', 'nombre' => 'firma', 'etiqueta' => $kiosco ? 'Tu firma' : 'Firma del candidato', 'requerido' => $kiosco,
            'ayuda' => 'Fecha de la firma: '.app(\App\Support\HoraLocal::class)->formatear(now(), 'd/m/Y').'.'])
    </section>
@endif

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
