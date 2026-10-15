{{-- Suscripciones: promociones y códigos de descuento --}}
@php use App\Models\Promocion; @endphp
<div class="d-flex justify-content-between align-items-center gap-2 mb-3 flex-wrap">
    <p class="small text-muted m-0">Descuentos en porcentaje o monto fijo al mes. Con código, el visitante lo escribe en la página de planes; sin código, solo se aplica a mano desde la ficha de un cliente. El descuento queda congelado en la solicitud y en la suscripción.</p>
    @if ($puedeEditar)<a href="{{ route('catalogo-comercial.promociones.crear') }}" class="btn btn-success fw-bold"><i class="bi bi-plus-circle me-1" aria-hidden="true"></i>Nueva promoción</a>@endif
</div>
<div class="tabla-scroll">
    <table class="table align-middle mb-0 bg-white">
        <thead><tr><th>Promoción</th><th>Código</th><th>Aplica a</th><th>Vigencia</th><th class="text-end">Usos</th><th>Estado</th><th><span class="visually-hidden">Acciones</span></th></tr></thead>
        <tbody>
            @forelse ($promociones as $p)
                @php
                    $hoy = \App\Models\Suscripcion::hoy();
                    $vencida = $p->vigente_hasta !== null && $hoy->gt($p->vigente_hasta);
                    $agotada = $p->usos_maximos !== null && $p->usos >= $p->usos_maximos;
                @endphp
                <tr @class(['text-muted' => ! $p->activo])>
                    <td class="fw-bold">{{ $p->nombre }}<div class="small text-muted fw-normal">{{ $p->resumen() }}</div></td>
                    <td>@if ($p->codigo)<code class="fw-bold">{{ $p->codigo }}</code>@else<span class="small text-muted">Solo a mano</span>@endif</td>
                    <td class="small">{{ $p->planes->isEmpty() ? 'Todos los planes' : $p->planes->pluck('nombre')->join(', ') }}<div class="text-muted">{{ Promocion::PERIODICIDADES[$p->periodicidad] ?? $p->periodicidad }}</div></td>
                    <td class="small text-nowrap">{{ $p->vigente_desde?->format('d/m/Y') ?? 'Desde hoy' }} – {{ $p->vigente_hasta?->format('d/m/Y') ?? 'sin fin' }}</td>
                    <td class="text-end">{{ $p->usos }}{{ $p->usos_maximos !== null ? ' de '.$p->usos_maximos : '' }}</td>
                    <td>
                        @if (! $p->activo)<span class="etiqueta-estado inactivo">DE BAJA</span>
                        @elseif ($vencida)<span class="etiqueta-estado inactivo">Vencida</span>
                        @elseif ($agotada)<span class="etiqueta-estado pendiente">Agotada</span>
                        @else<span class="etiqueta-estado activo">Vigente</span>@endif
                    </td>
                    <td>
                        @if ($puedeEditar)
                            <div class="d-flex gap-1 justify-content-end">
                                <a href="{{ route('catalogo-comercial.promociones.editar', $p->id) }}" class="btn-icono editar" title="Editar" aria-label="Editar «{{ $p->nombre }}»"><i class="bi bi-pencil-square" aria-hidden="true"></i></a>
                                <form action="{{ route('catalogo-comercial.promociones.estado', $p->id) }}" method="POST" class="m-0"
                                      data-confirmar="{{ $p->activo ? '¿Dar de baja «'.$p->nombre.'»? El código deja de aceptarse; los clientes que ya la tienen la conservan.' : '¿Reactivar «'.$p->nombre.'»?' }}">
                                    @csrf
                                    @method('PATCH')
                                    <input type="hidden" name="activo" value="{{ $p->activo ? 0 : 1 }}">
                                    @if ($p->activo)
                                        <button type="submit" class="btn-icono eliminar" title="Dar de baja" aria-label="Dar de baja «{{ $p->nombre }}»"><i class="bi bi-slash-circle" aria-hidden="true"></i></button>
                                    @else
                                        <button type="submit" class="btn-icono reactivar" title="Reactivar" aria-label="Reactivar «{{ $p->nombre }}»"><i class="bi bi-arrow-counterclockwise" aria-hidden="true"></i></button>
                                    @endif
                                </form>
                            </div>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-muted small">Todavía no hay promociones. Toca «Nueva promoción».</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
