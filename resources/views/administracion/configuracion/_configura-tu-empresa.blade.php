{{-- «Configura tu empresa»: mostrarla u ocultarla en Inicio (docs/tecnico/configura-tu-empresa.md) --}}
@php
    $asistenteArranque = app(\App\Services\Arranque\AsistenteArranque::class);
    $avanceArranque = $asistenteArranque->avance(auth()->user(), $empresa);
    $visibleArranque = $asistenteArranque->tarjeta(auth()->user(), $empresa->id) !== null;
@endphp
<section class="tarjeta p-4 mb-3" id="configura-tu-empresa" aria-labelledby="t-config-arranque">
    <div class="d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-3">
        <div>
            <h2 id="t-config-arranque" class="h6 fw-bold mb-1"><i class="bi bi-rocket-takeoff me-2 text-primary" aria-hidden="true"></i>Configura tu empresa</h2>
            <p class="small text-muted m-0">La guía de Inicio con los pasos para empezar a operar: llevas <strong>{{ $avanceArranque['hechos'] }} de {{ $avanceArranque['total'] }}</strong>. Ahora {{ $visibleArranque ? 'se ve' : 'no se ve' }} en Inicio.</p>
        </div>
        <form action="{{ route('asistente.visibilidad') }}" method="POST">
            @csrf
            @method('PUT')
            <input type="hidden" name="visible" value="{{ $visibleArranque ? 0 : 1 }}">
            <button type="submit" class="btn-importar-descarga secundario"><i class="bi {{ $visibleArranque ? 'bi-eye-slash' : 'bi-eye' }}" aria-hidden="true"></i> {{ $visibleArranque ? 'Ocultar de Inicio' : 'Mostrar en Inicio' }}</button>
        </form>
    </div>
</section>
