{{-- Tarjetas de planes: precio por sede al mes + IVA y lo que incluyen; el destacado resaltado --}}
@php use App\Services\Comercial\Calculadora; @endphp
<section class="lp-seccion" id="planes" aria-labelledby="titulo-planes">
    <div class="lp-envoltura">
        <div class="lp-encabezado">
            <h2 id="titulo-planes">Planes por sede al mes</h2>
            <p>Pagas por cada sede que usa la plataforma: un hotel, una planta, un edificio. Sin costo por instalar ni por actualizar.</p>
        </div>
        @if ($planes->isEmpty())
            <p class="lp-nota">Estamos preparando los planes. Escríbenos y te mandamos una propuesta.</p>
        @else
            <div class="lp-planes" style="--lp-columnas: {{ min(4, max(1, $planes->count())) }};">
                @foreach ($planes as $plan)
                    <article class="lp-plan {{ $plan->destacado ? 'lp-plan-destacado' : '' }}" aria-labelledby="plan-{{ $plan->id }}">
                        @if ($plan->destacado)<span class="lp-plan-recomendado">Recomendado</span>@endif
                        <h3 id="plan-{{ $plan->id }}">{{ $plan->nombre }}</h3>
                        <p class="lp-plan-frase">{{ $plan->frase }}</p>
                        <div class="lp-precio">
                            @if ($plan->cotizar)
                                <span class="lp-precio-medida">A tu medida</span>
                                <span class="lp-precio-unidad">Te enviamos una cotización</span>
                            @else
                                <span class="lp-precio-cifra">{{ Calculadora::precioCorto(Calculadora::centavos($plan->precio_por_sede_mensual)) }}</span>
                                <span class="lp-precio-unidad">por sede al mes {{ $ajustes['leyenda_iva'] }}</span>
                            @endif
                        </div>
                        <ul class="lp-plan-datos">
                            @if ($plan->sedes_minimas > 1)<li><i class="bi bi-buildings" aria-hidden="true"></i>Desde {{ $plan->sedes_minimas }} sedes</li>@endif
                            <li><i class="bi bi-people" aria-hidden="true"></i>{{ $plan->usuarios_por_sede === null ? 'Usuarios ilimitados' : 'Hasta '.$plan->usuarios_por_sede.' usuarios por sede' }}</li>
                        </ul>
                        <ul class="lp-incluye" aria-label="Incluye">
                            @foreach ($plan->productos as $producto)
                                <li><i class="bi bi-check-circle-fill" aria-hidden="true"></i>{{ $producto->nombre }}</li>
                            @endforeach
                        </ul>
                        <a href="{{ route('planes.contratar', ['plan' => $plan->slug]) }}" class="lp-boton lp-boton-ancho {{ $plan->destacado ? 'lp-boton-claro' : 'lp-boton-principal' }}">
                            {{ $plan->cotizar ? 'Pedir cotización' : 'Contratar '.$plan->nombre }}
                        </a>
                    </article>
                @endforeach
            </div>
            <p class="lp-nota">Precios en {{ $ajustes['moneda'] === 'MXN' ? 'pesos mexicanos' : $ajustes['moneda'] }} antes de IVA ({{ rtrim(rtrim(number_format($ajustes['iva_porcentaje'], 2), '0'), '.') }} %).</p>
        @endif
    </div>
</section>
