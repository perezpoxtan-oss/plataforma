{{--
    «Ver historial» (ADR-0016): botón para la ficha de cualquier registro que
    abre la Bitácora de auditoría con solo los movimientos de ese registro.
    Solo aparece si el usuario puede ver la bitácora y el tipo de registro es
    conocido por la bitácora. Uso:
    @include('componentes.ver-historial', ['registro' => \App\Models\Rol::class, 'id' => $rol->id, 'nombre' => $rol->nombre])
--}}
@php $aliasHistorial = \App\Services\Auditoria\LectorAuditoria::alias($registro ?? null); @endphp
@if ($aliasHistorial && ! empty($id) && auth()->user()?->can('auditoria.ver'))
    <a href="{{ route('auditoria.index', ['registro' => $aliasHistorial, 'id' => $id]) }}" class="btn-icono ver-historial" title="Ver historial"
       aria-label="Ver historial{{ ! empty($nombre) ? ' de '.$nombre : '' }}"><i class="bi bi-clock-history" aria-hidden="true"></i></a>
@endif
