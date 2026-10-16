{{--
    «Agregar área reservable» (reservas.configurar): casillas con los espacios
    de Zonas y edificios en orden de árbol (sangría por nivel), buscador y, si
    hay varias sedes, filtro de sede. Para las zonas con espacios interiores se
    elige el modo: toda la zona como un solo espacio o por sus espacios
    interiores (los marca de una vez con la configuración de la zona).
    Parámetros: $opciones (CatalogosReservas::opcionesParaAgregar), $sedeId (opcional).
--}}
@php
    $sedeId = $sedeId ?? null;
    $porAgregar = $opciones->where('estado', '!=', 'reservable')->count();
    $variasSedesDialogo = $sedeId === null && $opciones->pluck('sede_id')->unique()->count() > 1;
@endphp
<dialog id="dialogoAgregarArea" class="dialogo ancho" aria-labelledby="t-agregar-area" @if ($errors->has('espacio_id')) data-abrir-al-cargar @endif>
    <div class="dialogo-cabecera">
        <h2 id="t-agregar-area"><i class="bi bi-plus-circle me-2 text-success" aria-hidden="true"></i>Agregar área reservable</h2>
        <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </div>
    <div class="dialogo-cuerpo">
        <form method="POST" action="{{ route('reservas.configuracion.marcar') }}" autocomplete="off">
            @csrf
            @if ($sedeId)<input type="hidden" name="sede" value="{{ $sedeId }}">@endif
            @if ($porAgregar === 0)
                <p class="small m-0">No quedan espacios por agregar. Crea el salón, la sala o la habitación en <strong>Estructura → Zonas y edificios</strong>.</p>
            @else
                <p class="campo-ayuda mt-0 mb-2">Marca los espacios de <strong>Zonas y edificios</strong> que se podrán reservar. Puedes marcar varios a la vez; después completas su aforo, horario y tarifas (todo es opcional).</p>
                <div class="d-flex flex-wrap gap-2 mb-2">
                    <div class="buscador flex-grow-1">
                        <i class="bi bi-search" aria-hidden="true"></i>
                        <input type="search" placeholder="Buscar espacio..." aria-label="Buscar espacio" data-filtro-texto="agregar-areas">
                    </div>
                    @if ($variasSedesDialogo)
                        <select class="filtro-select" aria-label="Sede" data-filtro-sede="agregar-areas">
                            <option value="">Todas las sedes</option>
                            @foreach ($opciones->unique('sede_id') as $o)<option value="{{ $o['sede_id'] }}">{{ $o['sede'] }}</option>@endforeach
                        </select>
                    @endif
                </div>
                <div class="lista-asignar" data-fichas="agregar-areas">
                    @foreach ($opciones as $o)
                        @php $ya = $o['estado'] === 'reservable'; @endphp
                        <label class="opcion-asignar" data-ficha data-sede="{{ $o['sede_id'] }}" data-estado="1"
                               data-texto="{{ mb_strtolower($o['nombre'].' '.$o['ruta'].' '.$o['sede']) }}" style="margin-left: {{ $o['sangria'] * 1.25 }}rem">
                            <input type="checkbox" class="form-check-input m-0" name="espacios[]" value="{{ $o['id'] }}"
                                   @checked($ya || in_array($o['id'], array_map('intval', (array) old('espacios', [])), true)) @disabled($ya)>
                            <span class="min-w-0">
                                <strong>{{ $o['nombre'] }}</strong>
                                <span class="d-block text-muted small">{{ $o['nivel'] }}{{ $o['ruta'] ? ' · '.$o['ruta'] : '' }}{{ $variasSedesDialogo ? ' · '.$o['sede'] : '' }}</span>
                            </span>
                            <span class="ms-auto d-flex flex-wrap gap-1 justify-content-end">
                                @if ($o['interiores'] > 0)<span class="chip-seccion">{{ $o['interiores'] }} {{ $o['interiores'] === 1 ? 'espacio interior' : 'espacios interiores' }}</span>@endif
                                @if ($ya)<span class="chip-seccion">Ya se reserva</span>@endif
                                @if ($o['estado'] === 'quitada')<span class="chip-seccion">Se quitó · vuelve con su configuración</span>@endif
                            </span>
                        </label>
                    @endforeach
                </div>
                <p class="text-muted text-center small" data-sin-resultados="agregar-areas" hidden>No hay espacios que coincidan con tu búsqueda.</p>

                <fieldset>
                    <legend class="campo-etiqueta">Si marcas una zona que tiene espacios interiores</legend>
                    <div class="d-flex flex-column gap-2">
                        <label class="opcion-asignar">
                            <input type="radio" class="form-check-input m-0" name="modo" value="completa" @checked(old('modo', 'completa') === 'completa')>
                            <span><strong>Toda la zona como un solo espacio</strong><span class="d-block text-muted small">Se reserva completa, por ejemplo un salón o una terraza.</span></span>
                        </label>
                        <label class="opcion-asignar">
                            <input type="radio" class="form-check-input m-0" name="modo" value="interiores" @checked(old('modo') === 'interiores')>
                            <span><strong>Por sus espacios interiores</strong><span class="d-block text-muted small">Se reserva cada habitación o sala que tiene dentro. Se agregan todas de una vez con la configuración de la zona y después puedes ajustar cada una.</span></span>
                        </label>
                    </div>
                </fieldset>
            @endif
            <div class="dialogo-acciones">
                <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                @if ($porAgregar > 0)<button type="submit" class="btn-verde">Guardar</button>@endif
            </div>
        </form>
    </div>
</dialog>
