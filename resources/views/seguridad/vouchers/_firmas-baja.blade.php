{{--
    Ronda 5 (LL-04): cómo se firma el voucher de reposición, dentro del
    diálogo de baja (Llaves; Gafetes y Equipos pueden incluirlo igual: el
    servicio App\Services\Inventarios\Vouchers ya lo entiende).
      - Firma física (por omisión): se imprime y se firma a mano; después,
        en Vouchers de reposición, se marca "firmado en papel".
      - Firma digital: firma Seguridad y, si hay responsable, también él.
    Parámetro: id (prefijo único de la página).
--}}
@php $modoFirma = old('firma_modo', 'fisica'); @endphp
<fieldset class="firmas-voucher" data-firmas-voucher>
    <legend class="campo-etiqueta">Firmas del voucher</legend>
    <div class="opciones-firma-voucher">
        @foreach (\App\Services\Inventarios\Vouchers::FIRMA_MODOS as $clave => $texto)
            <label class="opcion-firma-voucher">
                <input type="radio" name="firma_modo" value="{{ $clave }}" @checked($modoFirma === $clave) @if ($clave === 'fisica') data-por-defecto @endif data-firma-modo>
                <span>{{ $texto }}</span>
            </label>
        @endforeach
    </div>
    <p class="campo-ayuda" data-firma-ayuda-fisica>Al generar el voucher se imprime la hoja: Seguridad y el responsable firman a mano. Las copias son para Seguridad, Recepción y Administración; el colaborador no recibe copia.</p>
    <div class="firmas-digitales" data-firmas-digitales hidden>
        @include('componentes.firma', ['id' => $id.'_firma_seguridad', 'nombre' => 'firma_seguridad', 'etiqueta' => 'Firma de Seguridad (quien registra)', 'requerido' => false, 'ayuda' => null])
        @if ($errors->has('firma_seguridad'))<p class="text-danger small">{{ $errors->first('firma_seguridad') }}</p>@endif
        <div data-firma-responsable-caja>
            @include('componentes.firma', ['id' => $id.'_firma_responsable', 'nombre' => 'firma_responsable', 'etiqueta' => 'Firma del responsable (colaborador)', 'requerido' => false, 'ayuda' => null])
            @if ($errors->has('firma_responsable'))<p class="text-danger small">{{ $errors->first('firma_responsable') }}</p>@endif
        </div>
    </div>
</fieldset>
