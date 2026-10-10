{{--
    Recepción (ADR-0007) en la tarjeta de un acceso: estado de la autorización (del
    departamento o de RR. HH.; la caseta la ve cambiar sola) y las fotos de evidencia. $a, $modo
    La caseta solo ve a qué viene («Candidato · Entrevista»): nunca etapas ni datos del CV.
--}}
@php
    $estadoAut = $a->autorizacion;
    $conRh = $a->motivo_visita === 'rh';
@endphp
@if ($conRh && $a->viene_a)
    <p class="linea-viene-a"><i class="bi bi-person-vcard me-1" aria-hidden="true"></i>
        {{ in_array($a->viene_a, ['busca_empleo', 'entrevista', 'documentos', 'firma'], true) ? 'Candidato · '.\App\Models\Acceso::VIENE_A[$a->viene_a] : \App\Models\Acceso::VIENE_A[$a->viene_a] ?? '' }}</p>
@endif
@if (in_array($estadoAut, ['esperando', 'espera'], true) && $a->estado === 'pendiente')
    <div class="aviso-esperando-autorizacion {{ $estadoAut === 'espera' ? 'pide-espera' : '' }}" data-acceso-esperando="{{ $a->id }}"
         data-estado-esperado="pendiente|{{ $estadoAut }}" data-url-estado="{{ route('accesos.autorizaciones-estado') }}">
        <i class="bi bi-hourglass-split" aria-hidden="true"></i>
        <div>
            @if ($conRh && $estadoAut === 'espera')
                <strong>RR. HH. PIDE QUE ESPERE</strong>
                <span class="d-block small">Que espere en caseta: Recursos Humanos avisará cuando pueda pasar · lleva {{ (int) $a->entrada_at?->diffInMinutes(now()) }} min. Esta tarjeta cambia sola.</span>
            @elseif ($conRh)
                <strong>ESPERANDO A RR. HH.</strong>
                <span class="d-block small">Ya se avisó a Recursos Humanos · esperando {{ (int) $a->entrada_at?->diffInMinutes(now()) }} min. Esta tarjeta cambia sola cuando contesten.</span>
            @else
                <strong>ESPERANDO AUTORIZACIÓN</strong> de {{ $a->departamento?->nombre ?? 'su departamento' }}
                <span class="d-block small">Ya se avisó al responsable · esperando {{ (int) $a->entrada_at?->diffInMinutes(now()) }} min. Esta tarjeta cambia sola cuando conteste.</span>
            @endif
        </div>
    </div>
@elseif ($estadoAut === 'autorizada' && $conRh)
    <p class="linea-autorizacion autorizada"><i class="bi bi-patch-check-fill me-1" aria-hidden="true"></i>RR. HH. dijo que pase</p>
@elseif ($estadoAut === 'autorizada' && $a->departamento)
    <p class="linea-autorizacion autorizada"><i class="bi bi-patch-check-fill me-1" aria-hidden="true"></i>Autorizado por {{ $a->departamento->nombre }}</p>
@elseif ($estadoAut === 'rechazada')
    <p class="linea-autorizacion rechazada"><i class="bi bi-x-octagon-fill me-1" aria-hidden="true"></i>NO AUTORIZADO por {{ $conRh ? 'Recursos Humanos' : ($a->departamento?->nombre ?? 'el departamento') }}: no ingresó. Devuélvele su identificación.</p>
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
