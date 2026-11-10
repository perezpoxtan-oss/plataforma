{{-- Paquetes y complementos: suscripción por sede al mes o venta directa (pago único) --}}
@php use App\Services\Comercial\Calculadora; @endphp
@if ($paquetes->isNotEmpty())
<section class="lp-seccion lp-seccion-blanca" id="paquetes" aria-labelledby="titulo-paquetes">
    <div class="lp-envoltura">
        <div class="lp-encabezado">
            <h2 id="titulo-paquetes">Paquetes y complementos</h2>
            <p>Súmalos a tu plan. Los de suscripción se cobran cada mes; los de pago único, una sola vez.</p>
        </div>
        <ul class="lp-paquetes">
            @foreach ($paquetes as $paquete)
                <li class="lp-paquete">
                    <div>
                        <h3>{{ $paquete->nombre }}<span class="lp-modalidad lp-modalidad-{{ $paquete->modalidad }}">{{ $paquete->esUnico() ? 'Pago único' : 'Mensual' }}</span></h3>
                        @if ($paquete->descripcion)<p>{{ $paquete->descripcion }}</p>@endif
                        @if ($paquete->planes->isNotEmpty())
                            <p class="lp-paquete-se-suma">Se suma a {{ $paquete->planes->pluck('nombre')->join(', ', ' y ') }}.</p>
                        @endif
                    </div>
                    <div class="lp-paquete-precio">
                        <span class="lp-paquete-cifra">{{ Calculadora::precioCorto(Calculadora::centavos($paquete->precio)) }}</span>
                        <span class="lp-precio-unidad">{{ $paquete->por_sede ? 'por sede' : 'en total' }}{{ $paquete->esUnico() ? '' : ' al mes' }} {{ $ajustes['leyenda_iva'] }}</span>
                    </div>
                </li>
            @endforeach
        </ul>
    </div>
</section>
@endif
