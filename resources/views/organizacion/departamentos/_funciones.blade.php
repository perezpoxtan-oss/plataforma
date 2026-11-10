{{--
    Revisión Etapa 3a: cobertura por función de un departamento, por sede
    («Autoriza visitas: Sí · Firma pases: No (lo firma …)»), para que la
    pantalla no diga «cubierto» mientras firma otra persona.
    $funciones: Cobertura::porFuncion()[$departamento->id] ?? []; $varias: bool
--}}
@foreach ($funciones as $f)
    <div class="cobertura-funcion"><i class="bi {{ $f['visitas'] && ($f['pases']['si'] ?? true) ? 'bi-check2-circle' : 'bi-info-circle' }}" aria-hidden="true"></i>
        @if ($varias)<strong>{{ $f['sede'] }}:</strong>@endif {{ \App\Services\Autorizaciones\Cobertura::textoFuncion($f) }}</div>
@endforeach
