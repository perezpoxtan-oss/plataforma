{{-- Horas libres de un área en un día: tocar una abre «Nueva reserva» con área, día y hora puestos. $a: área; $clave: Y-m-d; $huecos: ['10:00', …] --}}
<div class="res-huecos" role="group" aria-label="Horas libres de {{ $a->nombre() }} el {{ \Carbon\CarbonImmutable::parse($clave)->format('d/m') }}">
    @foreach ($huecos as $h)
        <a href="{{ route('reservas.create', ['area' => $a->id, 'fecha' => $clave, 'hora' => $h]) }}" class="res-hueco" aria-label="Reservar {{ $a->nombre() }} el {{ \Carbon\CarbonImmutable::parse($clave)->format('d/m') }} a las {{ $h }}"><i class="bi bi-plus" aria-hidden="true"></i>{{ $h }}</a>
    @endforeach
</div>
