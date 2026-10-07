{{--
    Recepción (ADR-0007) en la tarjeta de un acceso: estado de la autorización del
    departamento (la caseta la ve cambiar sola) y las fotos de evidencia. $a, $modo
--}}
@php $estadoAut = $a->autorizacion; @endphp
@if ($estadoAut === 'esperando' && $a->estado === 'pendiente')
    <div class="aviso-esperando-autorizacion" data-acceso-esperando="{{ $a->id }}" data-url-estado="{{ route('accesos.autorizaciones-estado') }}">
        <i class="bi bi-hourglass-split" aria-hidden="true"></i>
        <div>
            <strong>ESPERANDO AUTORIZACIÓN</strong> de {{ $a->departamento?->nombre ?? 'su departamento' }}
            <span class="d-block small">Ya se avisó al responsable · esperando {{ (int) $a->entrada_at?->diffInMinutes(now()) }} min. Esta tarjeta cambia sola cuando conteste.</span>
        </div>
    </div>
@elseif ($estadoAut === 'autorizada' && $a->departamento)
    <p class="linea-autorizacion autorizada"><i class="bi bi-patch-check-fill me-1" aria-hidden="true"></i>Autorizado por {{ $a->departamento->nombre }}</p>
@elseif ($estadoAut === 'rechazada')
    <p class="linea-autorizacion rechazada"><i class="bi bi-x-octagon-fill me-1" aria-hidden="true"></i>NO AUTORIZADO por {{ $a->departamento?->nombre ?? 'el departamento' }}: no ingresó. Devuélvele su identificación.</p>
@endif
@if ($a->foto_persona || $a->foto_identificacion)
    <div class="fotos-acceso">
        @if ($a->foto_persona)
            <a href="{{ route('accesos.foto-persona', $a->id) }}" target="_blank" rel="noopener" class="btn-foto-acceso"><i class="bi bi-person-bounding-box me-1" aria-hidden="true"></i>Foto</a>
        @endif
        @if ($a->foto_identificacion)
            <a href="{{ route('accesos.foto-identificacion', $a->id) }}" target="_blank" rel="noopener" class="btn-foto-acceso"><i class="bi bi-person-vcard me-1" aria-hidden="true"></i>Identificación</a>
        @endif
    </div>
@endif
