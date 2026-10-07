{{--
    Centro de notificaciones (campana del encabezado). El número de no leídas
    se pinta aquí; la lista se pide al abrir y el contador se refresca cada
    30 s mientras la pestaña está visible (bloque «Centro de notificaciones»
    de plataforma.js). Cada usuario ve solo las suyas.
--}}
@php
    $empresaCampana = app(\App\Support\Tenancy\EmpresaDeTrabajo::class)->id($usuario);
    $noLeidasCampana = $empresaCampana === null ? 0
        : app(\App\Support\Tenancy\Tenant::class)->conEmpresa($empresaCampana, fn () => app(\App\Services\Notificaciones\CentroNotificaciones::class)->noLeidas($usuario));
@endphp
<div class="campana" data-campana data-url="{{ route('notificaciones.resumen') }}">
    <button type="button" class="btn-campana" data-campana-boton aria-expanded="false" aria-haspopup="true"
            title="Notificaciones" aria-label="Notificaciones: {{ $noLeidasCampana }} sin leer">
        <i class="bi {{ $noLeidasCampana > 0 ? 'bi-bell-fill' : 'bi-bell' }}" aria-hidden="true"></i>
        <span class="campana-contador" data-campana-contador @if ($noLeidasCampana === 0) hidden @endif>{{ $noLeidasCampana > 99 ? '99+' : $noLeidasCampana }}</span>
    </button>
    <div class="campana-panel" data-campana-panel hidden>
        <div class="campana-cabeza">
            <strong>Notificaciones</strong>
            <form action="{{ route('notificaciones.leer-todas') }}" method="POST" class="m-0" data-campana-leer-todas>
                @csrf
                <button type="submit" class="campana-leer-todas"><i class="bi bi-check2-all me-1" aria-hidden="true"></i>Marcar todas como leídas</button>
            </form>
        </div>
        <div class="campana-lista" data-campana-lista aria-live="polite">
            <p class="campana-vacia" data-campana-cargando>Cargando…</p>
        </div>
        <a href="{{ route('notificaciones.index') }}" class="campana-ver-todas">Ver todas las notificaciones <i class="bi bi-arrow-right" aria-hidden="true"></i></a>
    </div>
</div>
