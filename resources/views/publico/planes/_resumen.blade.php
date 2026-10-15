{{-- Desglose: lo pinta el servidor (Calculadora) y la calculadora del navegador lo actualiza con las mismas reglas.
     $calculo (de Calculadora::calcular), $boton (texto del botón o null) --}}
@php use App\Services\Comercial\Calculadora; $f = fn ($c) => Calculadora::formato((int) $c); @endphp
<aside class="lp-resumen" aria-labelledby="{{ $prefijo }}-titulo-resumen">
    <h3 id="{{ $prefijo }}-titulo-resumen">Tu costo estimado</h3>
    <div aria-live="polite">
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
            <dt>Al mes, antes de IVA</dt><dd class="lp-cifra" data-resumen="mensual-subtotal">{{ $f($calculo['mensual']['subtotal']) }}</dd>
            <dt>IVA {{ rtrim(rtrim(number_format($ajustes['iva_porcentaje'], 2), '0'), '.') }} %</dt><dd class="lp-cifra" data-resumen="mensual-iva">{{ $f($calculo['mensual']['iva']) }}</dd>
            <dt class="lp-total-grande">Total al mes</dt><dd class="lp-cifra lp-total-grande" data-resumen="mensual-total">{{ $f($calculo['mensual']['total']) }}</dd>
        </dl>
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
