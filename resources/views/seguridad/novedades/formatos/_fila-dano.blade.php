<div class="row mb-2 align-items-end fila-dinamica" data-fila>
    <div class="col-md-4"><label class="campo-etiqueta" for="siniestro_dano_{{ $i }}">Zona afectada</label><input type="text" id="siniestro_dano_{{ $i }}" name="siniestro_danos[{{ $i }}][zona]" class="campo text-uppercase" maxlength="100" placeholder="Ej: Cocina, Lobby, Habitación 204" value="{{ $d['zona'] ?? '' }}"></div>
    <div class="col-md-7"><label class="campo-etiqueta" for="siniestro_dano_desc_{{ $i }}">Descripción del daño</label><input type="text" id="siniestro_dano_desc_{{ $i }}" name="siniestro_danos[{{ $i }}][descripcion]" class="campo" maxlength="2000" value="{{ $d['descripcion'] ?? '' }}"></div>
    <div class="col-md-1"><button type="button" class="btn-quitar-fila mb-3" data-quitar-fila aria-label="Quitar zona con daño"><i class="bi bi-trash" aria-hidden="true"></i></button></div>
</div>
