{{-- Estado de un día: insignia de color y lo justificado --}}
@php
    $clase = match (true) {
        $dia['estado'] === 'falta' && isset($dia['justificaciones']['falta']) => 'asis-justificada',
        in_array($dia['estado'], ['falta', 'sin_llegar'], true) => 'asis-falta',
        $dia['estado'] === 'asistio' && ($dia['retardo'] > 0 || $dia['anticipada'] > 0) => 'asis-retardo',
        $dia['estado'] === 'asistio' => 'asis-asistio',
        default => 'asis-neutro',
    };
@endphp
<span class="asis-estado {{ $clase }}">{{ \App\Http\Controllers\RecursosHumanos\AsistenciaController::textoEstado($dia) }}</span>
@foreach ($dia['justificaciones'] as $tipoJ => $j)
    <div class="small text-muted"><i class="bi bi-check2-square me-1" aria-hidden="true"></i>{{ $tipos[$tipoJ] }} justificada: {{ $j['texto'] }}{{ $j['nota'] ? ' · '.$j['nota'] : '' }}</div>
@endforeach
