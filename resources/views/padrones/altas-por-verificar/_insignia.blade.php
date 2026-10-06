{{--
    Estado de verificación de una ficha (ADR-0006): "Pendiente de verificar"
    (alta de la caseta desde Operación) o "Rechazado" / "Unido" con el motivo.
    Las verificadas no muestran nada. Lo ve todo el que consulta el padrón.
    $registro: Vehiculo | Proveedor | Persona; $femenino: bool
--}}
@if ($registro->estaPendiente())
    <div class="linea-verificacion">
        <span class="insignia-verificacion pendiente" data-insignia-verificacion="pendiente">
            <i class="bi bi-hourglass-split" aria-hidden="true"></i> Pendiente de verificar
        </span>
    </div>
@elseif ($registro->estaRechazado())
    <div class="linea-verificacion">
        <span class="insignia-verificacion rechazado" data-insignia-verificacion="rechazado">
            <i class="bi {{ $registro->fusionado_en_id ? 'bi-union' : 'bi-x-octagon' }}" aria-hidden="true"></i>
            {{ $registro->fusionado_en_id ? (($femenino ?? false) ? 'Unida con otro registro' : 'Unido con otro registro') : (($femenino ?? false) ? 'Rechazada' : 'Rechazado') }}
        </span>
        @if ($registro->motivo_rechazo && ! $registro->fusionado_en_id)
            <span class="motivo-verificacion">Motivo: {{ $registro->motivo_rechazo }}</span>
        @elseif ($registro->motivo_rechazo)
            <span class="motivo-verificacion">{{ $registro->motivo_rechazo }}</span>
        @endif
    </div>
@endif
