{{-- «Configura tu empresa» (docs/tecnico/configura-tu-empresa.md): cada paso se marca solo con los datos reales --}}
@php $listo = $asistente['hechos'] === $asistente['total']; @endphp
<section class="tarjeta p-4 mt-3 configura-empresa" id="configura-tu-empresa" aria-labelledby="t-configura">
    <div class="d-flex flex-column flex-md-row justify-content-between gap-2 mb-2">
        <div>
            <h2 id="t-configura" class="h5 fw-bold mb-1"><i class="bi {{ $listo ? 'bi-patch-check-fill text-success' : 'bi-rocket-takeoff text-primary' }} me-2" aria-hidden="true"></i>{{ $listo ? '¡Tu empresa está lista!' : 'Configura tu empresa' }}</h2>
            <p class="small text-muted m-0">{{ $listo ? 'Hiciste todos los pasos. Ya puedes ocultar esta tarjeta.' : 'Sigue estos pasos para empezar a operar. Cada uno se marca solo cuando ya está hecho.' }}</p>
        </div>
        <div class="text-md-end">
            <span class="small fw-bold">{{ $asistente['hechos'] }} de {{ $asistente['total'] }} pasos</span>
            <div class="barra-avance mt-1" role="progressbar" aria-label="Avance" aria-valuemin="0" aria-valuemax="{{ $asistente['total'] }}" aria-valuenow="{{ $asistente['hechos'] }}">
                <span style="width: {{ (int) round($asistente['hechos'] * 100 / max(1, $asistente['total'])) }}%"></span>
            </div>
        </div>
    </div>

    <ol class="pasos-configura">
        @foreach ($asistente['pasos'] as $paso)
            <li class="{{ $paso['hecho'] ? 'hecho' : '' }}">
                <span class="marca" aria-hidden="true"><i class="bi {{ $paso['hecho'] ? 'bi-check-lg' : $paso['icono'] }}"></i></span>
                <span class="flex-grow-1">
                    <strong class="d-block">{{ $paso['titulo'] }} <span class="visually-hidden">{{ $paso['hecho'] ? '(hecho)' : '(pendiente)' }}</span></strong>
                    <span class="small text-muted">{{ $paso['texto'] }}</span>
                </span>
                @if ($paso['enlaces'] !== [])
                    <span class="enlaces">
                        @foreach ($paso['enlaces'] as $texto => $url)
                            <a href="{{ $url }}" class="enlace-paso">{{ $texto }} <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
                        @endforeach
                    </span>
                @endif
            </li>
        @endforeach
    </ol>

    <form action="{{ route('asistente.visibilidad') }}" method="POST" class="mt-3 d-flex flex-wrap align-items-center gap-2">
        @csrf
        @method('PUT')
        <input type="hidden" name="visible" value="0">
        <button type="submit" class="btn-importar-descarga secundario"><i class="bi bi-check2-all" aria-hidden="true"></i> Ya terminé</button>
        <span class="small text-muted">La puedes volver a abrir en Configuración.</span>
    </form>
</section>
