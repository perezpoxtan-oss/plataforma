{{--
    Lugares que abre una llave (Zonas y edificios de la sede elegida): edificios,
    pisos y cuartos con su cascada de píldoras, y secciones. Para no mandar
    miles de habitaciones en cada visita, el diálogo los pide al abrirlo
    (GET llaves.lugares, LlaveController::lugares) y solo los arma en línea
    cuando el formulario regresa con errores. El diálogo de edición marca los
    de la llave después de cargarlos (plataforma.js, «Catálogo de llaves»).
    Parámetros: $p (prefijo de ids), $lugares (espacios, grupos), $marcados, $gruposMarcados.
--}}
@php
    $etiquetaLugar = function ($e) {
        return match ($e->nivel) {
            \App\Models\Espacio::AREA => $e->nombre.($e->padre ? ' ('.$e->padre->nombre.')' : ''),
            \App\Models\Espacio::AREA_ESPECIFICA => $e->nombre.($e->padre ? ' ('.trim(($e->padre->padre?->nombre ? $e->padre->padre->nombre.' / ' : '').$e->padre->nombre).')' : ''),
            default => $e->nombre,
        };
    };
    $cajas = [
        'zona' => ['Edificio(s) / Zona(s)', '(cada uno completo)', \App\Models\Espacio::EDIFICIO],
        'piso' => ['Piso(s)', '(pueden ser de distintos edificios)', \App\Models\Espacio::AREA],
        'area' => ['Área Específica / Cuarto(s)', '(cualquiera, sin importar edificio o piso)', \App\Models\Espacio::AREA_ESPECIFICA],
    ];
    // Ronda 5 (LL-02): selección en cascada Zona/Edificio → Piso → Área específica (como las píldoras de SEGCAT)
    $edificios = $lugares['espacios']->where('nivel', \App\Models\Espacio::EDIFICIO)->where('activo', true);
    $pisos = $lugares['espacios']->where('nivel', \App\Models\Espacio::AREA)->where('activo', true);
    $cascada = function ($e) {
        $padre = $e->padre;
        return match (true) {
            $padre === null => ['', ''],
            $padre->nivel === \App\Models\Espacio::AREA => [(string) ($padre->padre_id ?? ''), (string) $padre->id],
            default => [(string) $padre->id, ''],
        };
    };
@endphp
@foreach ($cajas as $alcance => [$titulo, $nota, $nivel])
    <div class="caja-lugares" data-llave-lugares="{{ $alcance }}" hidden>
        <label class="campo-etiqueta" for="{{ $p }}buscar_{{ $alcance }}">{{ $titulo }} <span class="text-lowercase fw-normal">{{ $nota }}</span></label>
        <p class="campo-ayuda mb-2" data-lugares-sin-sede><i class="bi bi-info-circle" aria-hidden="true"></i> Elige la Sede arriba primero.</p>
        @if ($alcance !== 'zona')
            <div class="cascada-lugares" data-cascada>
                <div class="cascada-paso" data-cascada-paso="edificio">
                    <span class="cascada-titulo">1. Zona / Edificio <span class="fw-normal">(toca uno o varios para ver solo sus {{ $alcance === 'piso' ? 'pisos' : 'pisos y cuartos' }})</span></span>
                    <div class="cascada-pildoras">
                        @foreach ($edificios as $ed)
                            <label class="pildora-cascada" data-pildora-edificio data-sede="{{ $ed->sede_id }}">
                                <input type="checkbox" value="{{ $ed->id }}"> {{ $ed->nombre }}
                            </label>
                        @endforeach
                    </div>
                </div>
                @if ($alcance === 'area')
                    <div class="cascada-paso" data-cascada-paso="piso">
                        <span class="cascada-titulo">2. Piso <span class="fw-normal">(opcional: acota todavía más)</span></span>
                        <div class="cascada-pildoras">
                            @foreach ($pisos as $pi)
                                <label class="pildora-cascada" data-pildora-piso data-sede="{{ $pi->sede_id }}" data-edificio="{{ $pi->padre_id }}">
                                    <input type="checkbox" value="{{ $pi->id }}"> {{ $pi->nombre }}@if ($pi->padre) <span class="text-muted fw-normal">({{ $pi->padre->nombre }})</span>@endif
                                </label>
                            @endforeach
                        </div>
                    </div>
                @endif
                <span class="cascada-titulo">{{ $alcance === 'area' ? '3. Cuartos / áreas' : '2. Pisos' }} <span class="fw-normal">(marca los que abre)</span></span>
            </div>
        @endif
        <input type="search" id="{{ $p }}buscar_{{ $alcance }}" class="campo campo-buscar-lugar mb-2" placeholder="Buscar por nombre..." data-buscar-lugar>
        <div class="caja-checks lista-lugares">
            @foreach ($lugares['espacios']->where('nivel', $nivel) as $e)
                @php [$deEdificio, $dePiso] = $cascada($e); @endphp
                <label class="fila-check" data-lugar data-sede="{{ $e->sede_id }}" data-nombre="{{ mb_strtolower($etiquetaLugar($e)) }}" @unless ($e->activo) data-inactivo @endunless
                       data-edificio="{{ $deEdificio }}" data-piso="{{ $dePiso }}">
                    <input type="checkbox" name="espacios[]" value="{{ $e->id }}" @checked(in_array($e->id, $marcados, true))>
                    {{ $etiquetaLugar($e) }}{{ $e->activo ? '' : ' (desactivado)' }}
                </label>
            @endforeach
            <p class="campo-ayuda m-0 p-1" data-lugares-vacio hidden><i class="bi bi-info-circle" aria-hidden="true"></i> Esta sede no tiene lugares de este tipo en <strong>Zonas y edificios</strong>. Agrégalos ahí o elige el alcance «Otra».</p>
        </div>
    </div>
@endforeach
<div class="caja-lugares" data-llave-lugares="seccion" hidden>
    <span class="campo-etiqueta">Sección(es) <span class="text-lowercase fw-normal">(grupos ya armados en Zonas y edificios)</span></span>
    <p class="campo-ayuda mb-2" data-lugares-sin-sede><i class="bi bi-info-circle" aria-hidden="true"></i> Elige la Sede arriba primero.</p>
    <div class="caja-checks lista-lugares">
        @foreach ($lugares['grupos'] as $g)
            <label class="fila-check" data-lugar data-sede="{{ $g->sede_id }}" data-nombre="{{ mb_strtolower($g->nombre) }}" @unless ($g->activo) data-inactivo @endunless>
                <input type="checkbox" name="grupos[]" value="{{ $g->id }}" @checked(in_array($g->id, $gruposMarcados, true))>
                {{ $g->nombre }} <span class="text-muted small">({{ $g->espacios_count }} hab.)</span>{{ $g->activo ? '' : ' (desactivada)' }}
            </label>
        @endforeach
        <p class="campo-ayuda m-0 p-1" data-lugares-vacio hidden><i class="bi bi-info-circle" aria-hidden="true"></i> Esta sede no tiene secciones. Créalas en <strong>Zonas y edificios</strong>, pestaña Secciones.</p>
    </div>
</div>
