{{--
    Formulario de la vacante (diálogos «Nueva vacante» y «Editar vacante»).
    $modo: nueva | editar · $reabrir: bool (volvió con errores) · $sedesForm: sedes que puede elegir
    $todasLasSedes: bool (alcance de empresa) · $sedeUsuario: ?int (su única sede, preseleccionada)
--}}
@use('App\Models\Vacante')
@use('App\Models\Candidato')
@php
    $o = fn (string $campo, $defecto = null) => $reabrir ? old($campo, $defecto) : $defecto;
    $marcadas = $reabrir ? array_map('intval', (array) old('sedes', [])) : ($sedeUsuario ? [$sedeUsuario] : []);
    $todas = $reabrir ? (bool) old('todas_las_sedes') : false;
    $p = $modo;
@endphp
@if ($reabrir && $errors->any())
    <div class="alert alert-danger small py-2 px-3" role="alert"><i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>Revisa los datos:
        <ul class="mb-0 ps-3">@foreach ($errors->all() as $m)<li>{{ $m }}</li>@endforeach</ul>
    </div>
@endif

<x-seccion :clave="'vacante-datos-'.$p" :abierta="true" class="bloque-cv">
    <x-slot:titulo><i class="bi bi-briefcase me-2" aria-hidden="true"></i>La vacante</x-slot:titulo>
    <label class="campo-etiqueta" for="{{ $p }}_titulo">Título de la vacante *</label>
    <input type="text" id="{{ $p }}_titulo" name="titulo" class="campo" maxlength="150" required value="{{ $o('titulo') }}" placeholder="Ej. Camarista, Cocinero de línea">
    <div class="rejilla-cv">
        <div>
            <label class="campo-etiqueta" for="{{ $p }}_puesto">Puesto (catálogo)</label>
            <select id="{{ $p }}_puesto" name="puesto_id" class="campo">
                <option value="">-- Sin definir --</option>
                @foreach ($puestos as $pu)
                    <option value="{{ $pu->id }}" @selected((string) $o('puesto_id') === (string) $pu->id)>{{ $pu->nombre }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="campo-etiqueta" for="{{ $p }}_depto">Departamento</label>
            <select id="{{ $p }}_depto" name="departamento_id" class="campo">
                <option value="">-- Sin definir --</option>
                @foreach ($departamentos as $d)
                    <option value="{{ $d->id }}" @selected((string) $o('departamento_id') === (string) $d->id)>{{ $d->nombre }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="campo-etiqueta" for="{{ $p }}_plazas">Número de plazas *</label>
            <input type="number" id="{{ $p }}_plazas" name="plazas" class="campo" min="1" max="999" required inputmode="numeric" value="{{ $o('plazas', 1) }}">
        </div>
    </div>
    <span class="campo-etiqueta d-block">Sede(s) *</span>
    @if ($todasLasSedes && $sedesForm->count() > 1)
        <input type="hidden" name="todas_las_sedes" value="0">
        <label class="opcion-todas">
            <input type="checkbox" name="todas_las_sedes" value="1" @checked($todas) data-oculta-si-marcado="#{{ $p }}_sedes">
            <span><strong>Todas las sedes</strong><br><span class="small text-muted">Desmárcalo para elegir solo algunas.</span></span>
        </label>
    @else
        <input type="hidden" name="todas_las_sedes" value="0">
    @endif
    <div class="lista-sedes-turno lista-sedes-vacante" id="{{ $p }}_sedes" @if ($todas) hidden @endif>
        @foreach ($sedesForm as $sede)
            <label class="opcion-sede-turno"><input type="checkbox" name="sedes[]" value="{{ $sede->id }}" @checked(in_array($sede->id, $marcadas, true))> <span>{{ $sede->nombre }}</span></label>
        @endforeach
    </div>
</x-seccion>

<x-seccion :clave="'vacante-condiciones-'.$p" :abierta="$modo === 'nueva'" class="bloque-cv">
    <x-slot:titulo><i class="bi bi-cash-coin me-2" aria-hidden="true"></i>Condiciones</x-slot:titulo>
    <div class="rejilla-cv">
        <div>
            <label class="campo-etiqueta" for="{{ $p }}_contrato">Tipo de contrato</label>
            <select id="{{ $p }}_contrato" name="tipo_contrato" class="campo">
                <option value="">-- Elegir --</option>
                @foreach (Vacante::CONTRATOS as $clave => $texto)
                    <option value="{{ $clave }}" @selected($o('tipo_contrato') === $clave)>{{ $texto }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="campo-etiqueta" for="{{ $p }}_jornada">Jornada</label>
            <select id="{{ $p }}_jornada" name="jornada" class="campo">
                <option value="">-- Elegir --</option>
                @foreach (Vacante::JORNADAS as $clave => $texto)
                    <option value="{{ $clave }}" @selected($o('jornada') === $clave)>{{ $texto }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="campo-etiqueta" for="{{ $p }}_turno">Turno</label>
            <select id="{{ $p }}_turno" name="turno_id" class="campo">
                <option value="">-- Sin turno fijo --</option>
                @foreach ($turnos as $t)
                    <option value="{{ $t->id }}" @selected((string) $o('turno_id') === (string) $t->id)>{{ $t->nombre }} ({{ $t->inicio() }} a {{ $t->fin() }})</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="campo-etiqueta" for="{{ $p }}_horario">Horario (texto)</label>
            <input type="text" id="{{ $p }}_horario" name="horario" class="campo" maxlength="150" value="{{ $o('horario') }}" placeholder="Ej. 6 días, descanso entre semana">
        </div>
        <div>
            <label class="campo-etiqueta" for="{{ $p }}_smin">Sueldo desde</label>
            <input type="text" id="{{ $p }}_smin" name="sueldo_min" class="campo" maxlength="12" inputmode="decimal" value="{{ $o('sueldo_min') }}" placeholder="Ej. 9000">
        </div>
        <div>
            <label class="campo-etiqueta" for="{{ $p }}_smax">Sueldo hasta</label>
            <input type="text" id="{{ $p }}_smax" name="sueldo_max" class="campo" maxlength="12" inputmode="decimal" value="{{ $o('sueldo_max') }}" placeholder="Ej. 11000">
        </div>
        <div>
            <label class="campo-etiqueta" for="{{ $p }}_periodo">El sueldo es</label>
            <select id="{{ $p }}_periodo" name="sueldo_periodo" class="campo">
                @foreach (Vacante::PERIODOS as $clave => $texto)
                    <option value="{{ $clave }}" @selected($o('sueldo_periodo', 'mensual') === $clave)>{{ ucfirst($texto) }}</option>
                @endforeach
            </select>
        </div>
        <div class="d-flex align-items-end">
            <label class="casilla-concluido"><input type="checkbox" name="sueldo_a_tratar" value="1" @checked($o('sueldo_a_tratar'))> Sueldo a tratar</label>
        </div>
    </div>
</x-seccion>

<x-seccion :clave="'vacante-descripcion-'.$p" :abierta="$modo === 'nueva'" class="bloque-cv">
    <x-slot:titulo><i class="bi bi-card-checklist me-2" aria-hidden="true"></i>Descripción y requisitos</x-slot:titulo>
    <label class="campo-etiqueta" for="{{ $p }}_descripcion">Descripción (qué hará)</label>
    <textarea id="{{ $p }}_descripcion" name="descripcion" class="campo" rows="3" maxlength="3000" placeholder="Ej. Limpieza y arreglo de habitaciones del hotel.">{{ $o('descripcion') }}</textarea>
    <label class="campo-etiqueta" for="{{ $p }}_requisitos">Requisitos (uno por renglón)</label>
    <textarea id="{{ $p }}_requisitos" name="requisitos" class="campo" rows="4" placeholder="Secundaria terminada&#10;Experiencia de 6 meses&#10;Disponibilidad de horario">{{ $o('requisitos') }}</textarea>
    <label class="campo-etiqueta" for="{{ $p }}_prestaciones">Ofrecemos (uno por renglón)</label>
    <textarea id="{{ $p }}_prestaciones" name="prestaciones" class="campo" rows="4" placeholder="Prestaciones de ley&#10;Comedor para empleados&#10;Transporte de personal">{{ $o('prestaciones') }}</textarea>
    <div class="rejilla-cv">
        <div>
            <label class="campo-etiqueta" for="{{ $p }}_escolaridad">Escolaridad mínima</label>
            <select id="{{ $p }}_escolaridad" name="escolaridad_minima" class="campo">
                <option value="">-- No se pide --</option>
                @foreach (Candidato::ESCOLARIDAD as $clave => $texto)
                    <option value="{{ $clave }}" @selected($o('escolaridad_minima') === $clave)>{{ $texto }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="campo-etiqueta" for="{{ $p }}_experiencia">Experiencia</label>
            <input type="text" id="{{ $p }}_experiencia" name="experiencia" class="campo" maxlength="150" value="{{ $o('experiencia') }}" placeholder="Ej. 1 año en hoteles (deseable)">
        </div>
    </div>
</x-seccion>

<x-seccion :clave="'vacante-publicacion-'.$p" :abierta="false" class="bloque-cv">
    <x-slot:titulo><i class="bi bi-calendar-event me-2" aria-hidden="true"></i>Fechas y contacto</x-slot:titulo>
    <div class="rejilla-cv">
        <div>
            <label class="campo-etiqueta" for="{{ $p }}_fpub">Publicar desde</label>
            <input type="date" id="{{ $p }}_fpub" name="fecha_publicacion" class="campo" value="{{ $o('fecha_publicacion') }}">
            <p class="campo-ayuda mt-0">Vacío = desde que la publiques.</p>
        </div>
        <div>
            <label class="campo-etiqueta" for="{{ $p }}_fcierre">Cerrar el</label>
            <input type="date" id="{{ $p }}_fcierre" name="fecha_cierre" class="campo" value="{{ $o('fecha_cierre') }}">
            <p class="campo-ayuda mt-0">Después de ese día deja de verse en internet.</p>
        </div>
        <div>
            <label class="campo-etiqueta" for="{{ $p }}_cnombre">Contacto (nombre)</label>
            <input type="text" id="{{ $p }}_cnombre" name="contacto_nombre" class="campo" maxlength="150" value="{{ $o('contacto_nombre') }}" placeholder="Ej. Recursos Humanos">
        </div>
        <div>
            <label class="campo-etiqueta" for="{{ $p }}_ctel">Teléfono de contacto</label>
            <input type="tel" id="{{ $p }}_ctel" name="contacto_telefono" class="campo" maxlength="20" inputmode="numeric" value="{{ $o('contacto_telefono') }}" placeholder="10 dígitos">
        </div>
        <div>
            <label class="campo-etiqueta" for="{{ $p }}_ccorreo">Correo de contacto</label>
            <input type="email" id="{{ $p }}_ccorreo" name="contacto_correo" class="campo" maxlength="150" value="{{ $o('contacto_correo') }}" placeholder="rh@empresa.com">
        </div>
    </div>
    <p class="campo-ayuda"><i class="bi bi-globe" aria-hidden="true"></i> El contacto se ve en la página pública de la vacante.</p>
</x-seccion>
