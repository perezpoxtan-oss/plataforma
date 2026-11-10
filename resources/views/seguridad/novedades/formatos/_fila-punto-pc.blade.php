@php
    $categoriaPunto = $p['categoria'] ?? '';
    $marcadas = (array) ($p['criterios'] ?? []);
    $numero = is_int($i) ? $i + 1 : '__n__';
@endphp
<div class="equipo-pc-card border rounded p-3 mb-3 bg-light position-relative fila-dinamica" data-fila data-punto-pc>
    <button type="button" class="btn-quitar-fila esquina" data-quitar-fila aria-label="Quitar punto de inspección"><i class="bi bi-trash" aria-hidden="true"></i></button>
    <h6 class="fw-bold text-success border-bottom pb-2"><i class="bi bi-shield-check me-2" aria-hidden="true"></i>Punto de Inspección #<span data-numero-fila>{{ $numero }}</span></h6>
    <div class="row mb-1">
        <div class="col-md-7">
            @include('componentes.lector', ['id' => 'rpc_id_'.$i, 'etiqueta' => 'Lector ID / Escáner QR', 'modo' => 'capturar',
                'nombre' => 'rpc_puntos['.$i.'][identificador]', 'valor' => $p['identificador'] ?? ''])
        </div>
        <div class="col-md-5">
            <label class="campo-etiqueta" for="rpc_cat_{{ $i }}">Categoría del Equipo</label>
            <select id="rpc_cat_{{ $i }}" name="rpc_puntos[{{ $i }}][categoria]" class="campo fw-bold border-success" data-categoria-pc>
                <option value="">-- Seleccione Categoría --</option>
                @foreach (\App\Services\Novedades\Formatos\RecorridoPc::CATEGORIAS as $clave => [$texto])
                    <option value="{{ $clave }}" @selected($categoriaPunto === $clave)>{{ $texto }}</option>
                @endforeach
            </select>
        </div>
    </div>
    <div class="row mb-2">
        <div class="col-md-4"><label class="campo-etiqueta" for="rpc_edi_{{ $i }}">Edificio / Torre</label><input type="text" id="rpc_edi_{{ $i }}" name="rpc_puntos[{{ $i }}][edificio]" class="campo bg-white text-uppercase" maxlength="100" value="{{ $p['edificio'] ?? '' }}"></div>
        <div class="col-md-4"><label class="campo-etiqueta" for="rpc_niv_{{ $i }}">Nivel / Piso</label><input type="text" id="rpc_niv_{{ $i }}" name="rpc_puntos[{{ $i }}][nivel]" class="campo bg-white text-uppercase" maxlength="50" value="{{ $p['nivel'] ?? '' }}"></div>
        <div class="col-md-4"><label class="campo-etiqueta" for="rpc_are_{{ $i }}">Área / Ubicación</label><input type="text" id="rpc_are_{{ $i }}" name="rpc_puntos[{{ $i }}][area]" class="campo bg-white text-uppercase" maxlength="150" value="{{ $p['area'] ?? '' }}"></div>
    </div>
    <h6 class="fw-bold text-dark border-bottom pb-2 mt-2"><i class="bi bi-list-check text-warning me-2" aria-hidden="true"></i>Componentes <span class="fw-normal text-muted small">(Marcar lo SANO/PRESENTE)</span></h6>
    <div class="row bg-white p-2 rounded border mx-0 mb-2 criterios-pc" data-criterios-de="{{ $i }}">
        @forelse (\App\Services\Novedades\Formatos\RecorridoPc::piezas($categoriaPunto) as $clave => $texto)
            <div class="col-md-4 col-sm-6 mb-2">
                <label class="d-flex align-items-center gap-2 small"><input type="checkbox" class="casilla-grande" name="rpc_puntos[{{ $i }}][criterios][{{ $clave }}]" value="1" @checked(in_array($clave, $marcadas, true))> {{ $texto }}</label>
            </div>
        @empty
            <div class="col-12 text-muted small fst-italic">Seleccione una categoría arriba para cargar las piezas a evaluar.</div>
        @endforelse
    </div>
    <label class="campo-etiqueta text-danger" for="rpc_obs_{{ $i }}">Observaciones / Desperfectos Encontrados</label>
    <textarea id="rpc_obs_{{ $i }}" name="rpc_puntos[{{ $i }}][observaciones]" class="campo border-danger mb-0" rows="1" maxlength="2000" placeholder="Describa daños físicos o anomalías...">{{ $p['observaciones'] ?? '' }}</textarea>
</div>
