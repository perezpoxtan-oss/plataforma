@php
    $tipo = $a['tipo'] ?? 'puerta';
    $texto = \App\Services\Novedades\Formatos\ValoresVista::TIPOS_APERTURA[$tipo] ?? $tipo;
    $especial = \App\Services\Novedades\Formatos\ValoresVista::ESPECIAL_APERTURA[$tipo] ?? '';
@endphp
<div class="row mb-2 align-items-end fila-dinamica" data-fila>
    <input type="hidden" name="hab_aperturas[{{ $i }}][tipo]" value="{{ $tipo }}">
    <div class="col-6 col-md-2"><span class="campo-etiqueta">{{ $texto }}</span><input type="text" class="campo bg-light" value="{{ $texto }}" readonly aria-label="Tipo"></div>
    <div class="col-6 col-md-3"><label class="campo-etiqueta" for="hab_apertura_estado_{{ $i }}">Estado</label><select id="hab_apertura_estado_{{ $i }}" name="hab_aperturas[{{ $i }}][estado]" class="campo"><option value="cerrada" @selected(($a['estado'] ?? 'cerrada') === 'cerrada')>Cerrada</option><option value="abierta" @selected(($a['estado'] ?? '') === 'abierta')>Abierta</option></select></div>
    <div class="col-6 col-md-3 text-md-center"><label class="campo-etiqueta d-block" for="hab_apertura_especial_{{ $i }}">{{ $especial }}</label><input type="checkbox" id="hab_apertura_especial_{{ $i }}" name="hab_aperturas[{{ $i }}][es_especial]" value="1" class="casilla-grande mb-3" @checked(! empty($a['es_especial']))></div>
    <div class="col-6 col-md-3"><label class="campo-etiqueta" for="hab_apertura_desc_{{ $i }}">Descripción <span class="text-lowercase fw-normal">(opcional)</span></label><input type="text" id="hab_apertura_desc_{{ $i }}" name="hab_aperturas[{{ $i }}][descripcion]" class="campo text-uppercase" maxlength="150" placeholder="Ej. Balcón principal" value="{{ $a['descripcion'] ?? '' }}"></div>
    <div class="col-12 col-md-1"><button type="button" class="btn-quitar-fila mb-3" data-quitar-fila aria-label="Quitar {{ mb_strtolower($texto) }}"><i class="bi bi-trash" aria-hidden="true"></i></button></div>
</div>
