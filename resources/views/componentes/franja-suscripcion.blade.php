{{-- Suscripciones: aviso de periodo de gracia o de solo consulta para los usuarios del cliente (ver docs/tecnico/suscripciones.md) --}}
@php $franjaSuscripcion = app(\App\Services\Suscripciones\EstadoCliente::class)->franja(auth()->user()); @endphp
@if ($franjaSuscripcion)
    <div class="alert alert-{{ $franjaSuscripcion['nivel'] }} small d-flex align-items-start gap-2 no-imprimir" role="{{ $franjaSuscripcion['nivel'] === 'danger' ? 'alert' : 'status' }}" data-franja-suscripcion>
        <i class="bi {{ $franjaSuscripcion['nivel'] === 'danger' ? 'bi-lock-fill' : 'bi-hourglass-split' }} mt-1" aria-hidden="true"></i>
        <span>{{ $franjaSuscripcion['texto'] }}
            @can('empresas.ver')<a href="{{ route('empresas.index') }}#tu-suscripcion" class="fw-bold ms-1">Ver mi suscripción</a>@endcan</span>
    </div>
@endif
