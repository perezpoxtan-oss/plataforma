{{-- Avance de un paquete que se está preparando. public/js/traslado.js pide la siguiente parte sola; sin JavaScript queda el botón. --}}
<div class="tarjeta p-3 mb-3 border-primary" id="avance-paquete" data-continuar="{{ route('traslado.continuar', $trabajo['id']) }}" role="status" aria-live="polite">
    <div class="d-flex justify-content-between align-items-center gap-2 flex-wrap">
        <strong><span class="spinner-border spinner-border-sm text-primary me-2" aria-hidden="true"></span>{{ $titulo }}</strong>
        <span class="small text-muted">Iniciado @fecha(\Illuminate\Support\Carbon::parse($trabajo['inicio'])) por {{ $trabajo['creado_por_nombre'] }}</span>
    </div>
    <div class="progress mt-2" style="height: 14px;" role="progressbar" aria-label="Avance" aria-valuemin="0" aria-valuemax="100" aria-valuenow="{{ $trabajo['avance'] }}">
        <div class="progress-bar" data-avance-barra style="width: {{ $trabajo['avance'] }}%"></div>
    </div>
    <div class="d-flex justify-content-between small mt-1 gap-2 flex-wrap">
        <span data-avance-paso>{{ $trabajo['paso'] ?? 'En espera' }} · {{ $trabajo['avance'] }} %</span>
        @if ($trabajo['tipo'] !== 'verificacion')
            <span>Tamaño hasta ahora: <strong data-avance-tamano>{{ \App\Services\Traslado\Paquetes::legible($trabajo['bytes']) }}</strong></span>
        @endif
    </div>
    <p class="small text-muted mt-2 mb-2" data-avance-aviso>{{ $trabajo['aviso'] ?? 'Puedes dejar esta página abierta: avanza sola. Si la cierras, el servidor lo termina en los próximos minutos.' }}</p>
    <form action="{{ route('traslado.continuar', $trabajo['id']) }}" method="POST" class="m-0">
        @csrf
        <button type="submit" class="btn btn-outline-primary btn-sm" data-avance-boton><i class="bi bi-arrow-repeat me-1" aria-hidden="true"></i>Continuar</button>
    </form>
</div>
@pushOnce('scripts')
    <script src="{{ asset('js/traslado.js') }}?v={{ @filemtime(public_path('js/traslado.js')) ?: '1' }}"></script>
@endPushOnce
