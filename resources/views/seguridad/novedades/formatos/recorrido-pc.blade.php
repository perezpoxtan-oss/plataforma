{{-- Recorrido PC histórico (SEGCAT: RECORRIDO_PC, frag_recorrido_pc.php) --}}
@php
    $puntos = array_values((array) $v['rpc_puntos']);
    $definiciones = collect(\App\Services\Novedades\Formatos\RecorridoPc::CATEGORIAS)->mapWithKeys(fn ($c, $clave) => [$clave => \App\Services\Novedades\Formatos\RecorridoPc::piezas($clave)]);
@endphp
<div class="accordion-item" id="moduloRecorridoPC">
    <h2 class="accordion-header"><a class="accordion-button" role="button" data-bs-toggle="collapse" href="#colRecorridoPC" aria-expanded="true"><i class="bi bi-clipboard-check-fill me-2 text-success" aria-hidden="true"></i>Checklist: Inspección Preventiva de Equipos PC</a></h2>
    <div id="colRecorridoPC" class="accordion-collapse collapse show">
        <div class="accordion-body" data-criterios-pc="{{ json_encode($definiciones) }}">
            <div data-filas="rpc_puntos" data-siguiente="{{ count($puntos) }}">
                @foreach ($puntos as $i => $p)
                    @include('seguridad.novedades.formatos._fila-punto-pc', ['i' => $i, 'p' => $p])
                @endforeach
            </div>
            <template data-plantilla="rpc_puntos">@include('seguridad.novedades.formatos._fila-punto-pc', ['i' => '__i__', 'p' => []])</template>
            <div class="text-center border-top pt-3 mt-2">
                <button type="button" class="btn-agregar-fila verde" data-agregar-fila="rpc_puntos"><i class="bi bi-plus-circle-fill me-1" aria-hidden="true"></i> Añadir Otro Equipo al Recorrido</button>
                <div class="text-muted small mt-2"><i class="bi bi-info-circle me-1" aria-hidden="true"></i>Posicione el cursor en el campo 'Lector ID / Escáner QR' y utilice su escáner (o acerque la etiqueta NFC al celular).</div>
            </div>
        </div>
    </div>
</div>
