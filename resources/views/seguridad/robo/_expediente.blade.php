{{--
    Expediente de Robo (SEGCAT: robo_modal_editar.php, "Robo #"): nota de
    seguimiento, estatus, resolución y el formato de Robo (1. Circunstancias,
    2. Sospechoso, 3. Testigos, 4. Canalización) — el mismo parcial que usa el
    expediente de la Bitácora de Novedades. Guarda con las mismas reglas.
--}}
@php
    $n = $expediente['novedad'];
    $v = $expediente['v'];
    $editable = $expediente['editable'];
    $trasError = old('_dialogo') === 'robo-'.$n->id && $errors->any();
    $opcionesEstatus = collect(\App\Models\Novedad::ESTATUS)->map(fn ($e) => $e[0])->all();
@endphp
<dialog id="dialogoRobo" class="dialogo ancho dialogo-expediente dialogo-robo" aria-labelledby="titulo-robo" data-abrir-al-cargar data-conservar-al-cerrar
        data-expediente="{{ $n->id }}" data-sede="{{ $n->sede_id }}">
    <div class="dialogo-cabecera">
        {{-- Mejoras Incidentes: mismo expediente que la Bitácora (mismo título, mismo orden), solo con lo que este rol puede tocar --}}
        <h2 id="titulo-robo"><i class="bi bi-folder-check me-2 text-primary" aria-hidden="true"></i>Expediente de Novedad <span class="folio-novedad ms-1">{{ $n->folio() }}</span> <span class="badge-categoria abierta ms-1">Robo</span></h2>
        <div class="d-flex align-items-center gap-2">
            @if ($expediente['puedeImprimir'])
                <a href="{{ route('novedades.imprimir', $n->id) }}" target="_blank" rel="noopener" class="btn-icono" title="Imprimir expediente" aria-label="Imprimir expediente"><i class="bi bi-printer" aria-hidden="true"></i></a>
            @endif
            <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
    </div>
    <div class="dialogo-cuerpo">
        <div class="reporte-inicial">
            <div><span class="dato-etiqueta">Sede</span>{{ $n->sede?->nombre }}</div>
            <div><span class="dato-etiqueta">Reportado</span>@fecha($n->created_at) · {{ $n->creador?->name ?? '—' }}</div>
            <div><span class="dato-etiqueta">¿Quién reporta?</span>{{ $n->reportado_por }}</div>
            <div><span class="dato-etiqueta">Ubicación específica</span>{{ $n->ubicacion }}@if ($n->areaEspecifica) <span class="text-muted">· {{ $n->areaEspecifica->nombre }}</span>@endif</div>
            <div class="completo"><span class="dato-etiqueta">¿Qué sucedió?</span>{!! nl2br(e($n->descripcion)) !!}</div>
        </div>
        @if ($expediente['enBitacora'])
            <p class="small mb-2"><a href="{{ route('novedades.index', ['abrir' => $n->id, 'volver' => 'robo']) }}"><i class="bi bi-box-arrow-up-right me-1" aria-hidden="true"></i>Ver el expediente completo en la Bitácora</a></p>
        @endif

        @if ($n->resuelto())
            <div class="aviso-solo-lectura">
                <i class="bi bi-lock-fill me-1" aria-hidden="true"></i>Este caso está <strong>Resuelto</strong>@if ($n->cerrado_en) (@fecha($n->cerrado_en) por {{ $n->cerrador?->name ?? '—' }})@endif. Se muestra en solo lectura.
                @if ($expediente['puedeReabrir'])
                    <a href="{{ route('novedades.index', ['abrir' => $n->id]) }}" class="fw-bold">Reabrir Caso para Editar</a> (desde la Bitácora de Novedades, con el motivo).
                @endif
            </div>
        @elseif (! $editable)
            <div class="aviso-solo-lectura"><i class="bi bi-lock-fill me-1" aria-hidden="true"></i>Tu rol solo puede consultar este caso, no editarlo.</div>
        @endif

        <form action="{{ route('robo.update', $n->id) }}" method="POST" autocomplete="off" data-form-novedad="robo" data-sede="{{ $n->sede_id }}">
            @csrf
            @method('PUT')
            <input type="hidden" name="_dialogo" value="robo-{{ $n->id }}">
            @if ($trasError)
                <div class="alert alert-danger small py-2" role="alert">
                    <i class="bi bi-exclamation-triangle-fill me-1" aria-hidden="true"></i>
                    @foreach ($errors->all() as $error)<div>{{ $error }}</div>@endforeach
                </div>
            @endif
            <fieldset @disabled(! $editable) class="expediente-campos">
                <div class="accordion expediente-formatos">
                    @include('seguridad.novedades.formatos.robo', ['textoSinHabitacion' => 'Para la Ficha de Hechos, elige la habitación específica en el expediente de la Bitácora de Novedades.'])
                </div>

                <div class="minuto-a-minuto">
                    <span class="campo-etiqueta"><i class="bi bi-clock-history me-1" aria-hidden="true"></i>Minuto a Minuto / Log de Seguimiento</span>
                    <div class="log-notas" tabindex="0" aria-label="Notas de seguimiento">
                        @forelse ($n->notas as $nota)
                            <div class="nota-log {{ $nota->tipo }}">[@fecha($nota->created_at)] {{ $nota->autor_nombre }}: {{ $nota->texto }}</div>
                        @empty
                            <div class="nota-log vacia">No hay registros previos.</div>
                        @endforelse
                    </div>
                    @if ($editable)
                        <label class="visually-hidden" for="robo_nueva_nota">Nueva actualización</label>
                        <input type="text" id="robo_nueva_nota" name="nueva_nota" class="campo campo-nota" maxlength="2000" placeholder="Escriba aquí la nueva actualización y presione Guardar Expediente..." value="{{ $v['nueva_nota'] }}">
                    @endif
                </div>

                <label class="campo-etiqueta" for="robo_estatus">Estatus Final del Expediente</label>
                <select id="robo_estatus" name="estatus" class="campo campo-estatus">
                    @foreach ($opcionesEstatus as $clave => $texto)
                        @continue ($clave === 'pendiente_turno' && $v['estatus'] !== 'pendiente_turno')
                        <option value="{{ $clave }}" @selected($v['estatus'] === $clave)>{{ $texto }}</option>
                    @endforeach
                </select>
                <label class="campo-etiqueta" for="robo_resolucion">Estatus Final / Resolución <span class="text-lowercase fw-normal">(cómo se concluyó el caso — obligatorio para marcar Resuelto)</span></label>
                <textarea id="robo_resolucion" name="resolucion" class="campo" rows="2" maxlength="5000" data-requerido-si='{"estatus":["resuelto"]}'
                          placeholder="Ej. Se recuperó el objeto, se entregó al huésped el parte policial...">{{ $v['resolucion'] }}</textarea>
            </fieldset>

            <div class="dialogo-acciones">
                <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cerrar</button>
                @if ($editable)
                    <button type="submit" class="btn-guardar-expediente"><i class="bi bi-save-fill me-1" aria-hidden="true"></i>Guardar Expediente</button>
                @endif
            </div>
        </form>
    </div>
</dialog>
<datalist id="novColaboradores"></datalist>
<script type="application/json" id="novColaboradoresDatos">@json($expediente['colaboradores'])</script>
