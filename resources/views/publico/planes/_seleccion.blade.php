{{-- Lo que se elige (plan, sedes y paquetes). Lo usan la calculadora y el formulario de contratar.
     $eleccion = ['plan_id' => ?int, 'sedes' => int, 'paquetes' => list<int>], $prefijo (ids únicos) --}}
@php use App\Services\Comercial\Calculadora; @endphp
@if ($planes->isNotEmpty())
    <fieldset class="lp-bloque">
        <legend>Plan</legend>
        <div class="lp-opciones lp-opciones-planes">
            @foreach ($planes as $plan)
                <label class="lp-opcion">
                    <input type="radio" name="plan_id" value="{{ $plan->id }}" @checked((int) ($eleccion['plan_id'] ?? 0) === $plan->id)>
                    <span>
                        <span class="lp-opcion-nombre">{{ $plan->nombre }}</span>
                        <span class="lp-opcion-precio">{{ $plan->cotizar ? 'A cotizar' : Calculadora::formato(Calculadora::centavos($plan->precio_por_sede_mensual)).' por sede al mes' }}</span>
                    </span>
                </label>
            @endforeach
        </div>
    </fieldset>
@endif

<div class="lp-bloque">
    <label class="lp-etiqueta" for="{{ $prefijo }}-sedes">¿Cuántas sedes?</label>
    <div class="lp-sedes">
        <button type="button" data-sedes-paso="-1" aria-label="Una sede menos"><i class="bi bi-dash-lg" aria-hidden="true"></i></button>
        <input type="number" id="{{ $prefijo }}-sedes" name="sedes" min="1" max="999" step="1" inputmode="numeric" value="{{ (int) ($eleccion['sedes'] ?? 1) }}" data-sedes required>
        <button type="button" data-sedes-paso="1" aria-label="Una sede más"><i class="bi bi-plus-lg" aria-hidden="true"></i></button>
    </div>
    <p class="lp-ayuda">Cada hotel, planta o edificio con su propia caseta cuenta como una sede.</p>
</div>

@if ($paquetes->isNotEmpty())
    <fieldset class="lp-bloque">
        <legend>Paquetes (opcional)</legend>
        <div class="lp-opciones">
            @foreach ($paquetes as $paquete)
                <label class="lp-opcion">
                    <input type="checkbox" name="paquetes[]" value="{{ $paquete->id }}" @checked(in_array($paquete->id, $eleccion['paquetes'] ?? [], true))>
                    <span>
                        <span class="lp-opcion-nombre">{{ $paquete->nombre }}</span>
                        <span class="lp-opcion-precio">{{ Calculadora::formato(Calculadora::centavos($paquete->precio)) }} {{ $paquete->por_sede ? 'por sede' : 'en total' }}, {{ $paquete->esUnico() ? 'pago único' : 'al mes' }}</span>
                        @if ($paquete->planes->isNotEmpty())
                            <span class="lp-opcion-aviso" data-aviso-incompatible hidden>Solo se suma a {{ $paquete->planes->pluck('nombre')->join(', ', ' y ') }}.</span>
                        @endif
                    </span>
                </label>
            @endforeach
        </div>
    </fieldset>
@endif
