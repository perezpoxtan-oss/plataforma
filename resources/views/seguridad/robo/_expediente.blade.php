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
    $opcionesEstatus = ['abierto' => 'Abierto / Seguimiento Pendiente', 'pendiente_turno' => 'Pendiente de turno', 'resuelto' => 'Resuelto y Cerrado'];
@endphp
<dialog id="dialogoRobo" class="dialogo ancho dialogo-expediente dialogo-robo" aria-labelledby="titulo-robo" data-abrir-al-cargar data-conservar-al-cerrar
        data-expediente="{{ $n->id }}" data-sede="{{ $n->sede_id }}">
    <div class="dialogo-cabecera">
        <h2 id="titulo-robo"><i class="bi bi-exclamation-octagon-fill me-2 text-danger" aria-hidden="true"></i>Robo {{ $n->folio() }}</h2>
        <div class="d-flex align-items-center gap-2">
            @if ($expediente['puedeImprimir'])
                <a href="{{ route('novedades.imprimir', $n->id) }}" target="_blank" rel="noopener" class="btn-icono" title="Imprimir expediente" aria-label="Imprimir expediente"><i class="bi bi-printer" aria-hidden="true"></i></a>
            @endif
            <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
        </div>
    </div>
    <div class="dialogo-cuerpo">
        <div class="reporte-inicial">
            <div><span class="dato-etiqueta">Ubicación</span>{{ $n->ubicacion }}</div>
            <div><span class="dato-etiqueta">Sede</span>{{ $n->sede?->nombre }} · @fecha($n->created_at)</div>
            <div><span class="dato-etiqueta">Reportó</span>{{ $n->reportado_por }}</div>
            <div><span class="dato-etiqueta">Habitación</span>{{ $n->areaEspecifica?->nombre ?? '—' }}</div>
            <div class="completo"><span class="dato-etiqueta">¿Qué sucedió?</span>{!! nl2br(e($n->descripcion)) !!}</div>
        </div>

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
                @if ($editable)
                    <label class="campo-etiqueta" for="robo_nueva_nota">Nueva nota de seguimiento <span class="text-lowercase fw-normal">(se agrega al historial, no lo reemplaza)</span></label>
                    <textarea id="robo_nueva_nota" name="nueva_nota" class="campo" rows="2" maxlength="2000" placeholder="Ej. Se revisaron cámaras del pasillo, sin hallazgos...">{{ $v['nueva_nota'] }}</textarea>
                @endif

                <label class="campo-etiqueta mt-2" for="robo_estatus">Estatus del Expediente</label>
                <select id="robo_estatus" name="estatus" class="campo campo-estatus">
                    @foreach ($opcionesEstatus as $clave => $texto)
                        @continue ($clave === 'pendiente_turno' && $v['estatus'] !== 'pendiente_turno')
                        <option value="{{ $clave }}" @selected($v['estatus'] === $clave)>{{ $texto }}</option>
                    @endforeach
                </select>
                <label class="campo-etiqueta mt-2" for="robo_resolucion">Estatus Final / Resolución <span class="text-lowercase fw-normal">(obligatorio para marcar Resuelto)</span></label>
                <textarea id="robo_resolucion" name="resolucion" class="campo" rows="2" maxlength="5000" data-requerido-si='{"estatus":["resuelto"]}'
                          placeholder="Ej. Se recuperó el objeto, se entregó al huésped el parte policial...">{{ $v['resolucion'] }}</textarea>

                <div class="accordion expediente-formatos mt-3">
                    @include('seguridad.novedades.formatos.robo', ['textoSinHabitacion' => 'Para la Ficha de Hechos, elige la habitación específica en el expediente de la Bitácora de Novedades.'])
                </div>

                <div class="minuto-a-minuto">
                    <span class="campo-etiqueta"><i class="bi bi-clock-history me-1" aria-hidden="true"></i>Historial de seguimiento (Minuto a Minuto)</span>
                    <div class="log-notas mb-0" tabindex="0" aria-label="Notas de seguimiento">
                        @forelse ($n->notas as $nota)
                            <div class="nota-log {{ $nota->tipo }}">[@fecha($nota->created_at)] {{ $nota->autor_nombre }}: {{ $nota->texto }}</div>
                        @empty
                            <div class="nota-log vacia">Sin notas de seguimiento todavía.</div>
                        @endforelse
                    </div>
                </div>
            </fieldset>

            <div class="dialogo-acciones">
                <button type="button" class="btn-cancelar" data-cerrar-dialogo>{{ $editable ? 'Cancelar' : 'Cerrar' }}</button>
                @if ($editable)
                    <button type="submit" class="btn-guardar-robo"><i class="bi bi-save-fill me-1" aria-hidden="true"></i>Guardar Cambios</button>
                @endif
            </div>
        </form>
    </div>
</dialog>
<datalist id="novColaboradores"></datalist>
<script type="application/json" id="novColaboradoresDatos">@json($expediente['colaboradores'])</script>
