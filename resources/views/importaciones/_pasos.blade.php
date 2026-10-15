{{-- Importar desde Excel: los tres pasos ($actual = 1, 2 o 3) --}}
<ol class="importar-pasos mb-4" aria-label="Pasos para importar">
    @foreach ([1 => 'Plantilla y archivo', 2 => 'Vista previa', 3 => 'Guardado'] as $numero => $texto)
        <li class="{{ $numero < $actual ? 'hecho' : ($numero === $actual ? 'activo' : '') }}" @if ($numero === $actual) aria-current="step" @endif>
            <span class="numero">@if ($numero < $actual)<i class="bi bi-check-lg" aria-hidden="true"></i>@else{{ $numero }}@endif</span>
            <span>{{ $texto }}</span>
        </li>
    @endforeach
</ol>
