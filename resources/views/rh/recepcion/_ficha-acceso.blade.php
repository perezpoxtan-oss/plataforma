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
@php $esperaAut = ($esperas ?? [])[$a->id] ?? null; @endphp
@if (in_array($estadoAut, ['esperando', 'espera'], true) && $a->estado === 'pendiente')
    {{-- Quién debe responder, el reloj y el color van en el renglón de detalle de la tarjeta; aquí solo el aviso y a quién se escaló --}}
    <div class="aviso-esperando-autorizacion {{ $estadoAut === 'espera' ? 'pide-espera' : '' }} {{ $esperaAut !== null && $estadoAut !== 'espera' ? 'espera-'.$esperaAut['nivel'] : '' }}" data-acceso-esperando="{{ $a->id }}"
         data-estado-esperado="pendiente|{{ $estadoAut }}" data-url-estado="{{ route('accesos.autorizaciones-estado') }}">
        <i class="bi bi-hourglass-split" aria-hidden="true"></i>
        <div>
            <span class="d-block small">{{ $estadoAut === 'espera' ? ($conRh ? 'Que espere en caseta: Recursos Humanos avisará cuando pueda pasar.' : 'Que espere en caseta: le avisarán cuando pueda pasar.') : ($conRh ? 'Ya se avisó a Recursos Humanos.' : 'Ya se avisó al responsable.') }} Esta tarjeta cambia sola con la respuesta.</span>
            <span class="d-block small fw-bold texto-escalado" data-espera-escalado @if (! $esperaAut || ! $esperaAut['escalado']) hidden @endif>{{ $esperaAut['escalado'] ?? '' }}</span>
        </div>
    </div>
@elseif ($estadoAut === 'rechazada')
    <p class="linea-autorizacion rechazada"><i class="bi bi-x-octagon-fill me-1" aria-hidden="true"></i>Devuélvele su identificación.</p>
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
