{{-- Desglose: lo pinta el servidor (Calculadora) y la calculadora del navegador lo actualiza con las mismas reglas.
     $calculo (de Calculadora::calcular), $boton (texto del botón o null), $prueba (bool, opcional) --}}
@php
    use App\Services\Comercial\Calculadora;
    $f = fn ($c) => Calculadora::formato((int) $c);
    $anual = $calculo['periodicidad'] === 'anual';
    $promo = $calculo['promocion'];
    $prueba = $prueba ?? false;
    $anualPct = rtrim(rtrim(number_format((float) ($ajustes['descuento_anual_porcentaje'] ?? 0), 2), '0'), '.');
@endphp
<aside class="lp-resumen" aria-labelledby="{{ $prefijo }}-titulo-resumen">
    <h3 id="{{ $prefijo }}-titulo-resumen">{{ $prueba ? 'Tu prueba gratis' : 'Tu costo estimado' }}</h3>
    <div aria-live="polite">
        @if ($prueba)
            <p class="lp-resumen-aviso">Durante los {{ $ajustes['dias_prueba'] }} días de prueba no pagas nada. Si después decides contratar, esto es lo que pagarías:</p>
        @endif
        <ul class="lp-renglones" data-resumen-renglones>
            @forelse ($calculo['renglones'] as $r)
                <li><span>{{ $r['nombre'] }} @if (! $r['cotizar'])<span class="lp-cantidad">{{ $r['cantidad'] > 1 ? '× '.$r['cantidad'].' ' : '' }}{{ $r['modalidad'] === 'unico' ? 'pago único' : 'al mes' }}</span>@endif</span>
                    <span class="lp-cifra">{{ $r['cotizar'] ? 'A cotizar' : $f($r['importe']) }}</span></li>
            @empty
                <li>Elige un plan para ver el costo.</li>
            @endforelse
        </ul>
        <p class="lp-ayuda" data-resumen-si="minimas" @if ($calculo['sedes_cobradas'] <= $calculo['sedes']) hidden @endif>Este plan se contrata desde <span data-resumen="sedes-cobradas">{{ $calculo['sedes_cobradas'] }}</span> sedes.</p>
        <dl class="lp-totales" data-resumen-si="mensual" @if ($calculo['requiere_cotizacion'] && $calculo['mensual']['subtotal'] === 0) hidden @endif>
            <dt><span data-resumen-texto="base">{{ $anual ? '12 meses' : 'Al mes' }}</span>, antes de IVA</dt><dd class="lp-cifra" data-resumen="base">{{ $f($calculo['mensual']['subtotal'] * $calculo['meses']) }}</dd>
            <dt data-resumen-si="anual" @if ($calculo['descuento_anual'] === 0) hidden @endif>Descuento por pago anual ({{ $anualPct }} %)</dt>
            <dd class="lp-cifra lp-descuento" data-resumen-si="anual" data-resumen="descuento-anual" @if ($calculo['descuento_anual'] === 0) hidden @endif>−{{ $f($calculo['descuento_anual']) }}</dd>
            <dt data-resumen-si="promocion" @if ($calculo['descuento_promocion'] === 0) hidden @endif>Promoción <span data-resumen="promocion-nombre">{{ $promo['nombre'] ?? '' }}</span><span data-resumen="promocion-meses">{{ ($promo['meses'] ?? null) !== null ? ' (primeros '.$promo['meses'].' '.($promo['meses'] === 1 ? 'mes' : 'meses').')' : '' }}</span></dt>
            <dd class="lp-cifra lp-descuento" data-resumen-si="promocion" data-resumen="descuento-promocion" @if ($calculo['descuento_promocion'] === 0) hidden @endif>−{{ $f($calculo['descuento_promocion']) }}</dd>
            <dt>IVA {{ rtrim(rtrim(number_format($ajustes['iva_porcentaje'], 2), '0'), '.') }} %</dt><dd class="lp-cifra" data-resumen="periodo-iva">{{ $f($calculo['periodo']['iva']) }}</dd>
            <dt class="lp-total-grande">Total <span data-resumen-texto="periodo">{{ $anual ? 'al año' : 'al mes' }}</span></dt><dd class="lp-cifra lp-total-grande" data-resumen="periodo-total">{{ $f($calculo['periodo']['total']) }}</dd>
        </dl>
        <p class="lp-ayuda" data-resumen-si="regular" @if ($calculo['periodo']['total'] === $calculo['periodo_regular']['total']) hidden @endif>Después de la promoción: <span data-resumen="regular-total">{{ $f($calculo['periodo_regular']['total']) }}</span> <span data-resumen-texto="periodo">{{ $anual ? 'al año' : 'al mes' }}</span> con IVA.</p>
        <dl class="lp-totales lp-resumen-unico" data-resumen-si="unico" @if ($calculo['unico']['subtotal'] === 0) hidden @endif>
            <dt>Pago único, antes de IVA</dt><dd class="lp-cifra" data-resumen="unico-subtotal">{{ $f($calculo['unico']['subtotal']) }}</dd>
            <dt>IVA</dt><dd class="lp-cifra" data-resumen="unico-iva">{{ $f($calculo['unico']['iva']) }}</dd>
            <dt>Total del pago único</dt><dd class="lp-cifra" data-resumen="unico-total">{{ $f($calculo['unico']['total']) }}</dd>
        </dl>
        <p class="lp-resumen-aviso" data-resumen-si="cotizar" @unless ($calculo['requiere_cotizacion']) hidden @endunless>Este plan se cotiza según tus sedes: envía tu solicitud y te mandamos la propuesta.</p>
    </div>
    @if ($boton)
        <button type="submit" class="lp-boton lp-boton-principal lp-boton-ancho">{{ $boton }}</button>
    @endif
    <p class="lp-ayuda">Montos en {{ $ajustes['moneda'] }}. Es un estimado: el precio final se confirma en tu cotización.</p>
</aside>
