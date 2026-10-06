{{--
    Firma del usuario en sesión: "Usar mi firma guardada" (la misma de Pases de
    salida, privada) o firmar en el recuadro, con la opción de guardarla.
    $id: prefijo único; $firmaGuardada: bool; $etiqueta.
--}}
@php $modoAnterior = old('firma_modo', $firmaGuardada ? 'guardada' : 'nueva'); @endphp
<fieldset class="firma-propia" data-firma-propia>
    <legend class="campo-etiqueta">{{ $etiqueta ?? 'Tu firma' }} <span class="text-danger" aria-hidden="true">*</span></legend>
    @if ($firmaGuardada)
        <div class="opciones-firma-propia" role="radiogroup">
            <label class="opcion-firma-propia">
                <input type="radio" name="firma_modo" value="guardada" @checked($modoAnterior === 'guardada') data-firma-modo>
                <span><strong>Usar mi firma guardada</strong>
                    <img src="{{ route('procedimientos.mi-firma') }}" alt="Tu firma guardada" class="miniatura-firma" loading="lazy"></span>
            </label>
            <label class="opcion-firma-propia">
                <input type="radio" name="firma_modo" value="nueva" @checked($modoAnterior === 'nueva') data-firma-modo>
                <span><strong>Firmar ahora</strong> en el recuadro</span>
            </label>
        </div>
    @else
        <input type="hidden" name="firma_modo" value="nueva">
    @endif
    <div class="caja-firma-nueva" data-firma-nueva @if ($firmaGuardada && $modoAnterior === 'guardada') hidden @endif>
        @include('componentes.firma', ['id' => $id.'_firma', 'nombre' => 'firma', 'etiqueta' => 'Firma en el recuadro'])
        <label class="opcion-guardar-firma">
            <input type="checkbox" name="guardar_firma" value="1" @checked(old('guardar_firma'))>
            <span>{{ $firmaGuardada ? 'Reemplazar mi firma guardada con esta' : 'Guardar mi firma para usarla la próxima vez' }}
                <small class="d-block text-muted">Se guarda de forma privada: solo tú puedes usarla.</small></span>
        </label>
    </div>
</fieldset>
