{{--
    Diálogo "Sedes donde opera" de un proveedor.
    Parámetros: $proveedor (con sedes cargadas), $reabrir, $volver ('lista' | 'ficha'),
    $sedesAsignables, $puede['todasLasSedes'], $empresaNombre.
--}}
@php
    $todasSedes = $reabrir ? (bool) old('todas_las_sedes') : $proveedor->todas_las_sedes;
    $marcadasSedes = $reabrir ? array_map('intval', (array) old('sedes', [])) : $proveedor->sedes->pluck('id')->map(fn ($id) => (int) $id)->all();
@endphp
<dialog id="dialogoSedesProveedor{{ $proveedor->id }}" class="dialogo" aria-labelledby="titulo-sedes-proveedor-{{ $proveedor->id }}" @if ($reabrir) data-abrir-al-cargar @endif>
    <div class="dialogo-cabecera">
        <h2 id="titulo-sedes-proveedor-{{ $proveedor->id }}"><i class="bi bi-signpost-split me-2 text-success" aria-hidden="true"></i>Sedes donde opera «{{ $proveedor->nombre }}»</h2>
        <button type="button" class="btn-cerrar" data-cerrar-dialogo aria-label="Cerrar"><i class="bi bi-x-lg" aria-hidden="true"></i></button>
    </div>
    <div class="dialogo-cuerpo">
        <p class="text-muted small mb-3">Padrón de {{ $empresaNombre }} — elige en qué sedes puede entrar esta empresa externa.</p>
        <form action="{{ route('proveedores.sedes', $proveedor->id) }}" method="POST">
            @csrf
            @method('PUT')
            <input type="hidden" name="_dialogo" value="sedes-{{ $proveedor->id }}">
            <input type="hidden" name="_volver" value="{{ $volver }}">

            @if ($sedesAsignables->isEmpty())
                <p class="text-muted fst-italic">Esta empresa no tiene sedes activas registradas todavía.</p>
            @elseif ($puede['todasLasSedes'])
                <input type="hidden" name="todas_las_sedes" value="0">
                <label class="opcion-todas">
                    <input type="checkbox" name="todas_las_sedes" value="1" @checked($todasSedes) data-oculta-si-marcado="#sedes_proveedor_{{ $proveedor->id }}">
                    <span><strong>Todas las sedes</strong><br><span class="small text-muted">También las que se abran después. Desmárcalo para elegir solo algunas.</span></span>
                </label>
                <div class="lista-sedes-turno" id="sedes_proveedor_{{ $proveedor->id }}" @if ($todasSedes) hidden @endif>
                    @foreach ($sedesAsignables as $sede)
                        <label class="opcion-sede-turno"><input type="checkbox" name="sedes[]" value="{{ $sede->id }}" @checked(in_array($sede->id, $marcadasSedes, true))> <span>{{ $sede->nombre }}</span></label>
                    @endforeach
                </div>
            @else
                <div class="lista-sedes-turno">
                    @foreach ($sedesAsignables as $sede)
                        <label class="opcion-sede-turno"><input type="checkbox" name="sedes[]" value="{{ $sede->id }}" @checked($todasSedes || in_array($sede->id, $marcadasSedes, true))> <span>{{ $sede->nombre }}</span></label>
                    @endforeach
                </div>
                <p class="small text-muted"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Solo puedes agregar o quitar a la empresa externa en tu sede; las demás sedes se quedan como están.</p>
            @endif

            <div class="dialogo-acciones">
                <button type="button" class="btn-cancelar" data-cerrar-dialogo>Cancelar</button>
                @if ($sedesAsignables->isNotEmpty())
                    <button type="submit" class="btn-esmeralda">Guardar Sedes</button>
                @endif
            </div>
        </form>
    </div>
</dialog>
