{{--
    Reloj de lo que espera un Vo.Bo. (App\Support\RelojVoBo): cuánto lleva
    esperando y color por tiempo (por omisión: normal < 24 h, amarillo 24–72 h,
    rojo > 72 h; cada empresa los cambia en Configuración → General), siempre
    con texto además del color. $desde: fecha desde la que espera.
--}}
@if ($relojVoBo = \App\Support\RelojVoBo::de($desde, null, \App\Support\RelojVoBo::umbralesActuales()))
    <span class="reloj-vobo espera-{{ $relojVoBo['nivel'] }}" data-reloj-vobo="{{ $relojVoBo['nivel'] }}">
        <i class="bi {{ $relojVoBo['nivel'] === 'normal' ? 'bi-clock' : 'bi-exclamation-triangle-fill' }}" aria-hidden="true"></i>
        <span class="visually-hidden">Esperando Vo.Bo.</span> {{ \Illuminate\Support\Str::ucfirst($relojVoBo['hace']) }} · <strong>{{ $relojVoBo['etiqueta'] }}</strong>
    </span>
@endif
